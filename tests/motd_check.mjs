import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const scratch = process.env.MOTD_SCRATCH || '';
const motdSource = fs.readFileSync(path.join(root, 'motd.js'), 'utf8');
const indexSource = fs.readFileSync(path.join(root, 'index.php'), 'utf8');

const SAMPLE = '§f §aHypixel Network §c[1.8/26.3]\n§f §2§lSB 0.27.2 §a§lMINISTERS §7§l| §d§lHALLOWEEN EVENT';
const VISIBLE = ' Hypixel Network [1.8/26.3]\n SB 0.27.2 MINISTERS | HALLOWEEN EVENT';

const SAMPLE_RUNS = [
    { text: ' ', color: '#FFFFFF', bold: false },
    { text: 'Hypixel Network ', color: '#55FF55', bold: false },
    { text: '[1.8/26.3]\n', color: '#FF5555', bold: false },
    { text: ' ', color: '#FFFFFF', bold: false },
    { text: 'SB 0.27.2 ', color: '#00AA00', bold: true },
    { text: 'MINISTERS ', color: '#55FF55', bold: true },
    { text: '| ', color: '#AAAAAA', bold: true },
    { text: 'HALLOWEEN EVENT', color: '#FF55FF', bold: true },
];

const JAVA_COLORS = [
    ['0', '#000000'],
    ['1', '#0000AA'],
    ['2', '#00AA00'],
    ['3', '#00AAAA'],
    ['4', '#AA0000'],
    ['5', '#AA00AA'],
    ['6', '#FFAA00'],
    ['7', '#AAAAAA'],
    ['8', '#555555'],
    ['9', '#5555FF'],
    ['a', '#55FF55'],
    ['b', '#55FFFF'],
    ['c', '#FF5555'],
    ['d', '#FF55FF'],
    ['e', '#FFFF55'],
    ['f', '#FFFFFF'],
];

function extractFunction(source, name) {
    const match = new RegExp('function\\s+' + name + '\\s*\\(').exec(source);
    if (!match) {
        throw new Error('index.php에 function ' + name + ' 이 없습니다');
    }
    let depth = 0;
    let started = false;
    let state = 'code';
    for (let i = match.index; i < source.length; i++) {
        const c = source[i];
        const next = source[i + 1];
        if (state === 'code') {
            if (c === "'" || c === '"' || c === '`') {
                state = c;
            } else if (c === '/' && next === '/') {
                state = 'line';
            } else if (c === '/' && next === '*') {
                state = 'block';
            } else if (c === '{') {
                depth++;
                started = true;
            } else if (c === '}') {
                depth--;
                if (started && depth === 0) {
                    return source.slice(match.index, i + 1);
                }
            }
        } else if (state === 'line') {
            if (c === '\n') state = 'code';
        } else if (state === 'block') {
            if (c === '*' && next === '/') {
                state = 'code';
                i++;
            }
        } else if (state === c && source[i - 1] !== '\\') {
            state = 'code';
        }
    }
    throw new Error('function ' + name + ' 이 닫히지 않았습니다');
}

function visibleText(runs) {
    return runs.map((run) => run.text).join('');
}

function assertStyle(run, expected) {
    assert.equal(run.text, expected.text);
    assert.equal(run.color, expected.color);
    assert.equal(run.bold, expected.bold);
    assert.equal(run.italic, expected.italic ?? false);
    assert.equal(run.underlined, expected.underlined ?? false);
    assert.equal(run.strikethrough, expected.strikethrough ?? false);
    assert.equal(run.obfuscated, expected.obfuscated ?? false);
}

function assertSample(runs) {
    assert.equal(runs.length, SAMPLE_RUNS.length);
    runs.forEach((run, index) => assertStyle(run, SAMPLE_RUNS[index]));
    assert.equal(visibleText(runs), VISIBLE);
    assert.equal(VISIBLE.split('\n').length, 2);
    assert.equal(VISIBLE.includes('§'), false);
}

function makeElement(tag) {
    const el = {
        tagName: String(tag).toUpperCase(),
        children: [],
        attrs: {},
        style: {},
        className: '',
        src: '',
        dataset: {},
        parentNode: null,
        _text: '',
    };
    el.classList = {
        _names() {
            return new Set(el.className.split(/\s+/).filter(Boolean));
        },
        _write(set) {
            el.className = [...set].join(' ');
        },
        add(name) {
            const set = this._names();
            set.add(name);
            this._write(set);
        },
        remove(name) {
            const set = this._names();
            set.delete(name);
            this._write(set);
        },
        contains(name) {
            return this._names().has(name);
        },
        toggle(name, force) {
            const next = force === undefined ? !this.contains(name) : Boolean(force);
            if (next) this.add(name);
            else this.remove(name);
            return next;
        },
    };
    el.setAttribute = (name, value) => {
        el.attrs[name] = String(value);
        if (name.startsWith('data-')) {
            const key = name.slice(5).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
            el.dataset[key] = String(value);
        }
    };
    el.getAttribute = (name) => (Object.prototype.hasOwnProperty.call(el.attrs, name) ? el.attrs[name] : null);
    el.removeAttribute = (name) => {
        delete el.attrs[name];
        if (name === 'src') el.src = '';
    };
    Object.defineProperty(el, 'textContent', {
        get() {
            if (el.children.length > 0) {
                return el.children.map((child) => child.textContent).join('');
            }
            return el._text;
        },
        set(value) {
            el.children.splice(0, el.children.length);
            el._text = value == null ? '' : String(value);
        },
    });
    Object.defineProperty(el, 'innerHTML', {
        get() {
            if (el.children.length === 0) {
                return escapeHtml(el._text);
            }
            return el.children.map((child) => '<' + child.tagName.toLowerCase() + '>' + escapeHtml(child.textContent) + '</' + child.tagName.toLowerCase() + '>').join('');
        },
        set() {
            throw new Error('MOTD는 innerHTML로 넣지 않습니다');
        },
    });
    el.appendChild = (child) => {
        child.parentNode = el;
        el.children.push(child);
        return child;
    };
    el.replaceChildren = (...nodes) => {
        el.children.splice(0, el.children.length);
        el._text = '';
        nodes.forEach((node) => el.appendChild(node));
    };
    el.querySelectorAll = (selector) => {
        const found = [];
        walk(el, selector, found);
        return found;
    };
    el.querySelector = (selector) => el.querySelectorAll(selector)[0] ?? null;
    return el;
}

function matches(node, selector) {
    if (selector.startsWith('.')) {
        return node.classList.contains(selector.slice(1));
    }
    return node.tagName === selector.toUpperCase();
}

function walk(node, selector, found) {
    for (const child of node.children) {
        if (matches(child, selector)) found.push(child);
        walk(child, selector, found);
    }
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function makeBlock() {
    const block = makeElement('section');
    block.className = 'server-block';
    for (const name of ['loading', 'online', 'offline']) {
        const section = makeElement('div');
        section.className = 'status-section is-hidden is-fading';
        section.setAttribute('data-section', name);
        block.appendChild(section);
        if (name !== 'online') continue;
        const motd = makeElement('div');
        motd.className = 'online-motd';
        section.appendChild(motd);
        for (const className of [
            'online-players-online',
            'online-players-max',
            'online-version',
            'online-ping',
            'online-timestamp',
        ]) {
            const node = makeElement('span');
            node.className = className;
            section.appendChild(node);
        }
        const sampleWrap = makeElement('div');
        sampleWrap.className = 'online-sample-wrap is-hidden';
        const sample = makeElement('div');
        sample.className = 'online-sample';
        sampleWrap.appendChild(sample);
        section.appendChild(sampleWrap);
        const faviconWrap = makeElement('div');
        faviconWrap.className = 'online-favicon-wrap is-hidden';
        const favicon = makeElement('img');
        favicon.className = 'online-favicon';
        faviconWrap.appendChild(favicon);
        section.appendChild(faviconWrap);
    }
    return block;
}

function loadPage() {
    const renderOnline = extractFunction(indexSource, 'renderOnline');
    const showSection = extractFunction(indexSource, 'showSection');
    assert.match(indexSource, /<script src="motd\.js"><\/script>/);
    assert.match(renderOnline, /fillOnlineMotd\(block\.querySelector\('\.online-motd'\), d\.description\)/);
    assert.doesNotMatch(renderOnline, /\.online-motd'\)\.textContent/);

    const sandbox = {
        console,
        requestAnimationFrame(fn) {
            fn();
        },
    };
    sandbox.window = sandbox;
    sandbox.globalThis = sandbox;
    sandbox.document = { createElement: makeElement };
    vm.runInNewContext(
        motdSource + '\n' + showSection + '\n' + renderOnline + '\n' +
        'this.__env = { window: typeof window, require: typeof require, module: typeof module, renderOnline: typeof renderOnline, motdRuns: typeof motdRuns, fillOnlineMotd: typeof fillOnlineMotd };',
        sandbox,
        { filename: 'motd.js' }
    );
    assert.equal(sandbox.__env.window, 'object');
    assert.equal(sandbox.__env.require, 'undefined');
    assert.equal(sandbox.__env.module, 'undefined');
    assert.equal(sandbox.__env.renderOnline, 'function');
    assert.equal(sandbox.__env.motdRuns, 'function');
    assert.equal(sandbox.__env.fillOnlineMotd, 'function');
    return sandbox;
}

function payload(description) {
    return {
        ping: 12,
        timestamp: '2026-10-18 00:00:00',
        data: {
            description,
            players: { online: 3, max: 20, sample: [{ name: '<b>not-html</b>' }] },
            version: { name: 'Paper 1.21' },
            favicon: 'not-an-image',
        },
    };
}

function assertRenderedMotd(motd) {
    assert.equal(motd.textContent, VISIBLE);
    assert.equal(motd.textContent.includes('§'), false);
    assert.equal(motd.querySelector('script'), null);
    const runs = motd.querySelectorAll('span');
    assert.equal(runs.length, SAMPLE_RUNS.length);
    runs.forEach((span, index) => {
        assert.equal(span.textContent, SAMPLE_RUNS[index].text);
        assert.equal(span.style.color, SAMPLE_RUNS[index].color);
        if (SAMPLE_RUNS[index].bold) {
            assert.equal(span.style.fontWeight, 'bold');
        } else {
            assert.equal(span.style.fontWeight, undefined);
        }
    });
    const green = runs.find((span) => span.textContent === 'Hypixel Network ');
    const ministers = runs.find((span) => span.textContent === 'MINISTERS ');
    assert.equal(green.style.color, '#55FF55');
    assert.equal(ministers.style.color, '#55FF55');
    assert.equal(ministers.style.fontWeight, 'bold');
}

function runUnit(page) {
    const { motdRuns, cleanMotd, fillOnlineMotd, renderOnline } = page;
    assertSample(motdRuns(SAMPLE));
    assertSample(motdRuns({ text: SAMPLE }));
    assertSample(motdRuns({ text: '', extra: [{ text: SAMPLE }] }));
    assertSample(motdRuns({ extra: [SAMPLE] }));

    const reset = motdRuns('§lX§cY');
    assert.equal(reset.length, 2);
    assertStyle(reset[0], { text: 'X', color: null, bold: true });
    assertStyle(reset[1], { text: 'Y', color: '#FF5555', bold: false });

    const colorAfterBold = motdRuns('§l§aY');
    assert.equal(colorAfterBold.length, 1);
    assertStyle(colorAfterBold[0], { text: 'Y', color: '#55FF55', bold: false });

    const cleared = motdRuns('§a§l§o§n§m§kZ§rQ');
    assert.equal(cleared.length, 2);
    assertStyle(cleared[0], {
        text: 'Z',
        color: '#55FF55',
        bold: true,
        italic: true,
        underlined: true,
        strikethrough: true,
        obfuscated: true,
    });
    assertStyle(cleared[1], { text: 'Q', color: null, bold: false });

    const upper = motdRuns('§A§LX');
    assertStyle(upper[0], { text: 'X', color: '#55FF55', bold: true });

    for (const [code, hex] of JAVA_COLORS) {
        const run = motdRuns('§' + code + 'Z');
        assert.equal(run.length, 1);
        assertStyle(run[0], { text: 'Z', color: hex, bold: false });
    }

    const formats = motdRuns('§o§n§mHi');
    assertStyle(formats[0], {
        text: 'Hi',
        color: null,
        bold: false,
        italic: true,
        underlined: true,
        strikethrough: true,
    });

    const obfuscated = motdRuns('§kSecret');
    assertStyle(obfuscated[0], { text: 'Secret', color: null, bold: false, obfuscated: true });
    assert.notEqual(obfuscated[0].obfuscated, false);

    const markup = '<script>alert(1)</script><b>x</b>';
    assert.equal(visibleText(motdRuns(markup)), markup);
    assert.equal(visibleText(motdRuns({ text: markup })), markup);
    const coloredMarkup = motdRuns('§c' + markup);
    assert.equal(coloredMarkup.length, 1);
    assert.equal(coloredMarkup[0].text, markup);
    assert.equal(coloredMarkup[0].color, '#FF5555');

    const inherited = motdRuns({
        text: 'A',
        color: 'green',
        bold: true,
        italic: true,
        extra: [
            { text: 'B' },
            { text: 'C', color: 'red' },
            'D',
            { text: 'E', bold: false, color: 'white' },
        ],
    });
    assertStyle(inherited[0], { text: 'A', color: '#55FF55', bold: true, italic: true });
    assertStyle(inherited[1], { text: 'B', color: '#55FF55', bold: true, italic: true });
    assertStyle(inherited[2], { text: 'C', color: '#FF5555', bold: true, italic: true });
    assertStyle(inherited[3], { text: 'D', color: '#55FF55', bold: true, italic: true });
    assertStyle(inherited[4], { text: 'E', color: '#FFFFFF', bold: false, italic: true });

    const isolated = motdRuns({
        color: 'green',
        bold: true,
        text: '',
        extra: [{ text: '§cX' }, { text: 'Y' }],
    });
    assertStyle(isolated[0], { text: 'X', color: '#FF5555', bold: false });
    assertStyle(isolated[1], { text: 'Y', color: '#55FF55', bold: true });

    const plain = '  hello \t world \n\n\n\nnext  ';
    assert.equal(visibleText(motdRuns(plain)), cleanMotd(plain));
    assert.equal(cleanMotd(plain), 'hello world \n\nnext');
    assert.equal(visibleText(motdRuns({ text: '  hello   world  ' })), 'hello world');
    assert.equal(motdRuns('   ').length, 0);
    assert.equal(visibleText(motdRuns('&aHello')), '&aHello');
    assert.equal(visibleText(motdRuns('§qZ')), 'Z');

    const block = makeBlock();
    renderOnline(block, payload(SAMPLE));
    const motd = block.querySelector('.online-motd');
    assertRenderedMotd(motd);
    const firstText = motd.textContent;
    assert.equal(block.querySelector('.online-players-online').textContent, '3');
    assert.equal(block.querySelector('.online-version').textContent, 'Paper 1.21');
    assert.equal(block.querySelector('.online-sample').textContent, '<b>not-html</b>');
    assert.equal(block.querySelector('.online-sample').querySelector('b'), null);
    const online = [...block.children].find((section) => section.dataset.section === 'online');
    assert.equal(online.classList.contains('is-hidden'), false);

    renderOnline(block, payload(markup));
    const dirty = block.querySelector('.online-motd');
    assert.equal(dirty.textContent, markup);
    assert.equal(dirty.querySelector('script'), null);
    assert.equal(dirty.querySelector('b'), null);
    assert.equal(dirty.innerHTML.includes('<script'), false);
    assert.match(dirty.innerHTML, /&lt;script&gt;/);
    assert.match(dirty.innerHTML, /&lt;b&gt;/);

    fillOnlineMotd(dirty, '§kAbc');
    const secret = dirty.querySelector('span');
    assert.equal(secret.textContent, 'Abc');
    assert.equal(secret.classList.contains('motd-obfuscated'), true);
    assert.equal(secret.style.filter, 'blur(1.5px)');
    assert.notEqual(secret.style.fontFamily, undefined);

    fillOnlineMotd(dirty, '§n§mLine');
    const line = dirty.querySelector('span');
    assert.equal(line.style.textDecorationLine, 'underline line-through');

    const again = makeBlock();
    renderOnline(again, payload(SAMPLE));
    assert.equal(again.querySelector('.online-motd').textContent, firstText);
    assertRenderedMotd(again.querySelector('.online-motd'));
}

function chromeBinary() {
    const candidates = [
        process.env.CHROME_PATH,
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
    ].filter(Boolean);
    return candidates.find((candidate) => fs.existsSync(candidate)) || '';
}

function normColor(value) {
    if (!value) return null;
    const text = String(value).trim().toLowerCase();
    const hex = text.match(/^#([0-9a-f]{6})$/);
    if (hex) return '#' + hex[1].toUpperCase();
    const rgb = text.match(/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/);
    if (!rgb) return text;
    const channel = (channel) => Number(channel).toString(16).padStart(2, '0').toUpperCase();
    return '#' + channel(rgb[1]) + channel(rgb[2]) + channel(rgb[3]);
}

function writeFixture(dir) {
    const renderOnline = extractFunction(indexSource, 'renderOnline');
    const showSection = extractFunction(indexSource, 'showSection');
    const inline = (source) => source.replace(/<\/script/gi, '<\\/script');
    const html = `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
body { margin: 0; background: #09090b; }
.online-motd {
  white-space: pre-wrap;
  background: #18181b;
  color: #a1a1aa;
  padding: 28px 32px;
  font: 22px/1.45 sans-serif;
  text-align: center;
}
#out { display: none; }
</style>
</head>
<body>
<section class="server-block">
  <div class="status-section is-hidden" data-section="loading"></div>
  <div class="status-section is-hidden is-fading" data-section="online">
    <div class="online-motd"></div>
    <span class="online-players-online"></span>
    <span class="online-players-max"></span>
    <span class="online-version"></span>
    <span class="online-ping"></span>
    <span class="online-timestamp"></span>
    <div class="online-sample-wrap is-hidden"><div class="online-sample"></div></div>
    <div class="online-favicon-wrap is-hidden"><img class="online-favicon" alt=""></div>
  </div>
  <div class="status-section is-hidden" data-section="offline"></div>
</section>
<pre id="out"></pre>
<script>
${inline(motdSource)}
</script>
<script>
${inline(showSection)}
${inline(renderOnline)}
const SAMPLE = ${JSON.stringify(SAMPLE)};
function readMotd(el) {
  return {
    text: el.textContent,
    html: el.innerHTML,
    scripts: el.querySelectorAll('script').length,
    bolds: el.querySelectorAll('b').length,
    runs: [...el.children].map((node) => ({
      tag: node.tagName,
      text: node.textContent,
      color: node.style.color,
      fontWeight: node.style.fontWeight,
      className: node.className,
      filter: node.style.filter
    }))
  };
}
const block = document.querySelector('.server-block');
const base = {
  ping: 12,
  timestamp: '2026-10-18 00:00:00',
  data: {
    description: SAMPLE,
    players: { online: 3, max: 20, sample: [] },
    version: { name: 'Paper 1.21' }
  }
};
renderOnline(block, base);
const first = readMotd(document.querySelector('.online-motd'));
renderOnline(block, base);
const second = readMotd(document.querySelector('.online-motd'));
renderOnline(block, Object.assign({}, base, {
  data: Object.assign({}, base.data, { description: '<' + 'script>alert(1)<' + '/script><b>x</b>' })
}));
const markup = readMotd(document.querySelector('.online-motd'));
renderOnline(block, Object.assign({}, base, {
  data: Object.assign({}, base.data, { description: '§kAbc' })
}));
const obfuscated = readMotd(document.querySelector('.online-motd'));
renderOnline(block, base);
document.getElementById('out').textContent = 'MOTD_RESULT:' + btoa(unescape(encodeURIComponent(JSON.stringify({ first, second, markup, obfuscated }))));
</script>
</body>
</html>
`;
    const htmlPath = path.join(dir, 'motd-fixture.html');
    fs.writeFileSync(htmlPath, html);
    return htmlPath;
}

function runChrome(chrome, args, cwd) {
    return spawnSync(chrome, args, {
        cwd,
        encoding: 'utf8',
        timeout: 20000,
        killSignal: 'SIGKILL',
    });
}

function assertChromeResult(parsed) {
    assert.deepEqual(parsed.first, parsed.second);
    assert.equal(parsed.first.text, VISIBLE);
    assert.equal(parsed.first.text.includes('§'), false);
    assert.equal(parsed.first.scripts, 0);
    const green = parsed.first.runs.find((run) => run.text === 'Hypixel Network ');
    const ministers = parsed.first.runs.find((run) => run.text === 'MINISTERS ');
    const halloween = parsed.first.runs.find((run) => run.text === 'HALLOWEEN EVENT');
    assert.ok(green);
    assert.ok(ministers);
    assert.ok(halloween);
    assert.equal(normColor(green.color), '#55FF55');
    assert.equal(normColor(ministers.color), '#55FF55');
    assert.equal(normColor(halloween.color), '#FF55FF');
    assert.equal(ministers.fontWeight === 'bold' || ministers.fontWeight === '700', true);
    assert.equal(halloween.fontWeight === 'bold' || halloween.fontWeight === '700', true);
    assert.equal(parsed.markup.text, '<script>alert(1)</script><b>x</b>');
    assert.equal(parsed.markup.scripts, 0);
    assert.equal(parsed.markup.bolds, 0);
    assert.equal(parsed.markup.html.includes('<script'), false);
    assert.match(parsed.markup.html, /&lt;script&gt;/);
    assert.match(parsed.markup.html, /&lt;b&gt;/);
    assert.equal(parsed.obfuscated.runs.length, 1);
    assert.equal(parsed.obfuscated.runs[0].text, 'Abc');
    assert.match(parsed.obfuscated.runs[0].className, /motd-obfuscated/);
    assert.match(parsed.obfuscated.runs[0].filter, /blur/);
}

function runDom() {
    const chrome = chromeBinary();
    if (!chrome) {
        noteDomFailure('Chrome 실행 파일을 찾지 못했습니다.');
        return;
    }
    const dir = scratch || fs.mkdtempSync(path.join(os.tmpdir(), 'motd-'));
    const profile = path.join(dir, 'chrome-profile');
    fs.mkdirSync(profile, { recursive: true });
    const htmlPath = writeFixture(dir);
    const fileUrl = 'file://' + htmlPath;
    const common = [
        '--headless=new',
        '--disable-gpu',
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-extensions',
        '--disable-background-networking',
        '--allow-file-access-from-files',
        '--user-data-dir=' + profile,
        '--window-size=1000,700',
        '--virtual-time-budget=4000',
        '--hide-scrollbars',
    ];
    const dumped = runChrome(chrome, [...common, '--dump-dom', fileUrl], dir);
    const stdout = dumped.stdout || '';
    const marker = stdout.match(/MOTD_RESULT:([A-Za-z0-9+/=]+)/);
    if (dumped.error || dumped.status === null || stdout.trim() === '') {
        const reason = dumped.error
            ? String(dumped.error.message || dumped.error)
            : 'Chrome이 DOM을 돌려주지 못했습니다\n' + (dumped.stderr || '').slice(0, 500);
        noteDomFailure(reason);
        return;
    }
    if (!marker) {
        throw new Error('브라우저가 페이지를 읽었지만 MOTD 결과가 비어 있습니다\n' + stdout.slice(0, 500));
    }
    const parsed = JSON.parse(Buffer.from(marker[1], 'base64').toString('utf8'));
    if (!parsed.first || parsed.first.text === '' || String(parsed.first.text).includes('§')) {
        throw new Error('MOTD readback이 비었거나 §가 남아 있습니다: ' + JSON.stringify(parsed.first));
    }
    assertChromeResult(parsed);
    if (scratch) {
        const shotPath = path.join(scratch, 'motd.png');
        const shot = runChrome(chrome, [...common, '--screenshot=' + shotPath, fileUrl], dir);
        if (shot.error || !fs.existsSync(shotPath)) {
            throw new Error(shot.error ? String(shot.error.message || shot.error) : '스크린샷을 쓰지 못했습니다\n' + (shot.stderr || '').slice(0, 500));
        }
    }
}

function noteDomFailure(reason) {
    if (scratch) {
        fs.writeFileSync(path.join(scratch, 'motd-dom.txt'), reason + '\n');
    }
}

const page = loadPage();
runUnit(page);
runUnit(page);
runDom();
process.stdout.write('motd ok\n');
