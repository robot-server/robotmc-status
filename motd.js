// 서버 목록 MOTD를 자바 에디션 색·서식 런으로 바꾼다.
// 페이지와 테스트가 같은 함수를 쓴다.
(function (root) {
    var COLORS = {
        '0': '#000000',
        '1': '#0000AA',
        '2': '#00AA00',
        '3': '#00AAAA',
        '4': '#AA0000',
        '5': '#AA00AA',
        '6': '#FFAA00',
        '7': '#AAAAAA',
        '8': '#555555',
        '9': '#5555FF',
        'a': '#55FF55',
        'b': '#55FFFF',
        'c': '#FF5555',
        'd': '#FF55FF',
        'e': '#FFFF55',
        'f': '#FFFFFF'
    };

    var NAMED_COLORS = {
        black: '#000000',
        dark_blue: '#0000AA',
        dark_green: '#00AA00',
        dark_aqua: '#00AAAA',
        dark_red: '#AA0000',
        dark_purple: '#AA00AA',
        gold: '#FFAA00',
        gray: '#AAAAAA',
        grey: '#AAAAAA',
        dark_gray: '#555555',
        dark_grey: '#555555',
        blue: '#5555FF',
        green: '#55FF55',
        aqua: '#55FFFF',
        red: '#FF5555',
        light_purple: '#FF55FF',
        yellow: '#FFFF55',
        white: '#FFFFFF'
    };

    function emptyStyle() {
        return {
            color: null,
            bold: false,
            italic: false,
            underlined: false,
            strikethrough: false,
            obfuscated: false
        };
    }

    function copyStyle(style) {
        return {
            color: style.color,
            bold: style.bold,
            italic: style.italic,
            underlined: style.underlined,
            strikethrough: style.strikethrough,
            obfuscated: style.obfuscated
        };
    }

    function sameStyle(a, b) {
        return a.color === b.color &&
            a.bold === b.bold &&
            a.italic === b.italic &&
            a.underlined === b.underlined &&
            a.strikethrough === b.strikethrough &&
            a.obfuscated === b.obfuscated;
    }

    function cleanMotd(s) {
        return (s || '')
            .trim()
            .replace(/[ \t]+/g, ' ')
            .replace(/\n{3,}/g, '\n\n');
    }

    // 색 코드는 앞에 켜진 굵기·기울임·밑줄·취소선·난독화를 끈다. §r은 색과 서식을 모두 끈다.
    function applyCode(style, code) {
        if (Object.prototype.hasOwnProperty.call(COLORS, code)) {
            var next = emptyStyle();
            next.color = COLORS[code];
            return next;
        }
        if (code === 'k') return Object.assign(copyStyle(style), { obfuscated: true });
        if (code === 'l') return Object.assign(copyStyle(style), { bold: true });
        if (code === 'm') return Object.assign(copyStyle(style), { strikethrough: true });
        if (code === 'n') return Object.assign(copyStyle(style), { underlined: true });
        if (code === 'o') return Object.assign(copyStyle(style), { italic: true });
        if (code === 'r') return emptyStyle();
        return style;
    }

    function parseLegacy(text, base) {
        var runs = [];
        var style = copyStyle(base);
        var buf = '';

        function flush() {
            if (buf === '') return;
            var last = runs[runs.length - 1];
            if (last && sameStyle(last, style)) {
                last.text += buf;
            } else {
                var run = copyStyle(style);
                run.text = buf;
                runs.push(run);
            }
            buf = '';
        }

        for (var i = 0; i < text.length; i++) {
            if (text.charAt(i) !== '§') {
                buf += text.charAt(i);
                continue;
            }
            if (i + 1 >= text.length) break;
            var code = text.charAt(i + 1).toLowerCase();
            i++;
            flush();
            style = applyCode(style, code);
        }
        flush();
        return runs;
    }

    function componentStyle(node, parent) {
        var style = copyStyle(parent);
        if (!node || typeof node !== 'object') return style;
        if (typeof node.color === 'string') {
            if (/^#[0-9a-f]{6}$/i.test(node.color)) {
                style.color = node.color.toUpperCase();
            } else {
                var named = NAMED_COLORS[node.color.toLowerCase()];
                if (named) style.color = named;
            }
        }
        if (typeof node.bold === 'boolean') style.bold = node.bold;
        if (typeof node.italic === 'boolean') style.italic = node.italic;
        if (typeof node.underlined === 'boolean') style.underlined = node.underlined;
        if (typeof node.strikethrough === 'boolean') style.strikethrough = node.strikethrough;
        if (typeof node.obfuscated === 'boolean') style.obfuscated = node.obfuscated;
        return style;
    }

    function collectSegments(node, parent, segments) {
        if (node == null) return;
        if (typeof node === 'string') {
            segments.push({ text: node, style: parent });
            return;
        }
        if (typeof node !== 'object') return;
        var style = componentStyle(node, parent);
        if (typeof node.text === 'string') {
            segments.push({ text: node.text, style: style });
        }
        if (Array.isArray(node.extra)) {
            for (var i = 0; i < node.extra.length; i++) {
                collectSegments(node.extra[i], style, segments);
            }
        }
    }

    // §를 빼기 전에 원문을 정리한다. trim은 문자열 양 끝만 보므로 §f 뒤의 공백은 남는다.
    function cleanChars(chars) {
        var raw = '';
        for (var i = 0; i < chars.length; i++) raw += chars[i].ch;
        var start = raw.length - raw.trimStart().length;
        var end = raw.trimEnd().length;
        var trimmed = chars.slice(start, end);
        if (trimmed.length === 0) return [];

        var text = raw.slice(start, end);
        var spaced = [];
        var space = /[ \t]+/g;
        var last = 0;
        var match;
        while ((match = space.exec(text)) !== null) {
            for (var s = last; s < match.index; s++) spaced.push(trimmed[s]);
            spaced.push({
                ch: ' ',
                style: trimmed[match.index].style,
                id: trimmed[match.index].id
            });
            last = match.index + match[0].length;
        }
        for (var t = last; t < trimmed.length; t++) spaced.push(trimmed[t]);

        var mid = '';
        for (var m = 0; m < spaced.length; m++) mid += spaced[m].ch;
        var out = [];
        var breaks = /\n{3,}/g;
        last = 0;
        while ((match = breaks.exec(mid)) !== null) {
            for (var n = last; n < match.index; n++) out.push(spaced[n]);
            out.push(spaced[match.index]);
            out.push(spaced[match.index + 1]);
            last = match.index + match[0].length;
        }
        for (var k = last; k < spaced.length; k++) out.push(spaced[k]);
        return out;
    }

    function motdRuns(description) {
        var segments = [];
        collectSegments(description, emptyStyle(), segments);
        var chars = [];
        for (var id = 0; id < segments.length; id++) {
            var text = segments[id].text;
            for (var i = 0; i < text.length; i++) {
                chars.push({ ch: text.charAt(i), style: segments[id].style, id: id });
            }
        }
        var cleaned = cleanChars(chars);
        var runs = [];
        var index = 0;
        while (index < cleaned.length) {
            var segId = cleaned[index].id;
            var end = index + 1;
            while (end < cleaned.length && cleaned[end].id === segId) end++;
            var piece = '';
            for (var c = index; c < end; c++) piece += cleaned[c].ch;
            var parsed = parseLegacy(piece, cleaned[index].style);
            for (var p = 0; p < parsed.length; p++) {
                if (parsed[p].text !== '') runs.push(parsed[p]);
            }
            index = end;
        }
        return runs;
    }

    function applyRunStyle(span, run) {
        if (run.color) span.style.color = run.color;
        if (run.bold) span.style.fontWeight = 'bold';
        if (run.italic) span.style.fontStyle = 'italic';
        var lines = [];
        if (run.underlined) lines.push('underline');
        if (run.strikethrough) lines.push('line-through');
        if (lines.length > 0) span.style.textDecorationLine = lines.join(' ');
        if (run.obfuscated) {
            span.classList.add('motd-obfuscated');
        }
    }

    function fillOnlineMotd(element, description) {
        var runs = motdRuns(description);
        element.replaceChildren();
        for (var i = 0; i < runs.length; i++) {
            var span = document.createElement('span');
            span.textContent = runs[i].text;
            applyRunStyle(span, runs[i]);
            element.appendChild(span);
        }
    }

    root.cleanMotd = cleanMotd;
    root.motdRuns = motdRuns;
    root.fillOnlineMotd = fillOnlineMotd;
})(typeof globalThis !== 'undefined' ? globalThis : this);
