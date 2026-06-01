<?php
// config.ini 누락은 운영자 설정 문제이므로 페이지 자체가 안 뜨게 즉시 중단.
if (!file_exists(__DIR__ . '/config.ini')) {
    die('config.ini 파일이 없습니다. config.ini.sample을 config.ini로 복사하여 서버 IP 등을 수정하세요.');
}
$config = parse_ini_file(__DIR__ . '/config.ini');
$SERVER_NAME = $config['server_name'] ?? '마인크래프트 서버';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($SERVER_NAME) ?> - 서버 상태</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <script>
        tailwind.config = {
            darkMode: 'class'
        };
    </script>
    <style>
        body {
            background: linear-gradient(to bottom, #f4f4f5, #fafafa);
        }
        .dark body {
            background: linear-gradient(to bottom, #18181b, #09090b);
        }

        .card {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .card:hover {
            transform: translateY(-8px);
            box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.4);
        }

        .status-section {
            transition: opacity 0.2s ease;
        }

        .is-hidden {
            display: none;
        }

        .is-fading {
            opacity: 0;
        }
    </style>
</head>
<body class="text-zinc-900 dark:text-white min-h-screen py-12 px-4">
<div class="max-w-lg mx-auto">
    <div class="text-center mb-10">
        <h1 class="text-4xl font-bold tracking-tight"><?= htmlspecialchars($SERVER_NAME) ?></h1>
        <p class="text-zinc-500 dark:text-zinc-400 mt-2">실시간 서버 상태</p>
    </div>

    <div id="status-card" class="card bg-white dark:bg-zinc-900 rounded-3xl p-8 shadow-2xl border border-zinc-200 dark:border-zinc-800">

        <!-- 로딩 -->
        <div id="status-loading" class="status-section text-center py-16">
            <i class="fa-solid fa-circle-notch fa-spin text-6xl text-zinc-500 dark:text-zinc-400 mb-6"></i>
            <p class="text-zinc-500 dark:text-zinc-400">서버 상태 확인 중…</p>
        </div>

        <!-- 온라인 -->
        <div id="status-online" class="status-section is-hidden is-fading">
            <div class="mb-6 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-5 h-5 bg-green-500 rounded-full animate-pulse"></div>
                    <span class="text-3xl font-bold text-green-600 dark:text-green-400">온라인</span>
                </div>
                <!-- 백그라운드 갱신 인디케이터 (온라인 상태일 때만) -->
                <div id="refresh-indicator" class="hidden text-zinc-500 dark:text-zinc-400">
                    <i class="fa-solid fa-rotate fa-spin text-3xl"></i>
                </div>
            </div>

            <div id="online-motd"
                 class="bg-zinc-100 dark:bg-zinc-800/70 rounded-2xl p-6 mb-7 text-lg leading-relaxed whitespace-pre-wrap text-center border border-zinc-200 dark:border-zinc-700"></div>

            <div class="grid grid-cols-2 gap-6 mb-8">
                <div>
                    <p class="text-zinc-500 dark:text-zinc-400 text-sm">접속자</p>
                    <p class="text-5xl font-bold text-zinc-900 dark:text-white">
                        <span id="online-players-online">0</span><span class="text-2xl text-zinc-500 dark:text-zinc-500"> / <span id="online-players-max">0</span></span>
                    </p>
                </div>
                <div>
                    <p class="text-zinc-500 dark:text-zinc-400 text-sm">버전</p>
                    <p id="online-version" class="text-xl font-medium text-zinc-900 dark:text-white">Unknown</p>
                </div>
            </div>

            <div id="online-sample-wrap" class="mb-8 is-hidden">
                <p class="text-zinc-500 dark:text-zinc-400 text-sm mb-3">현재 접속 중</p>
                <div id="online-sample" class="flex flex-wrap gap-2"></div>
            </div>

            <div id="online-favicon-wrap" class="flex justify-center mb-6 is-hidden">
                <img id="online-favicon" class="w-28 h-28 rounded-2xl border-4 border-zinc-200 dark:border-zinc-700 shadow-inner" alt="서버 아이콘">
            </div>

            <div class="text-center text-zinc-500 dark:text-zinc-500 text-sm pt-4 border-t border-zinc-200 dark:border-zinc-800">
                핑 <span id="online-ping">-</span>ms • <span id="online-timestamp">-</span>
            </div>
        </div>

        <!-- 오프라인 -->
        <div id="status-offline" class="status-section is-hidden is-fading">
            <div class="py-16 text-center">
                <i class="fa-solid fa-server text-7xl text-red-500/80 mb-6"></i>
                <div class="mb-3 flex items-center justify-center gap-3">
                    <h2 class="text-3xl font-bold text-red-500 dark:text-red-400">서버가 오프라인입니다</h2>
                    <!-- 백그라운드 갱신 인디케이터 (오프라인 상태일 때) -->
                    <div id="refresh-indicator-offline" class="hidden text-zinc-500 dark:text-zinc-400">
                        <i class="fa-solid fa-rotate fa-spin text-3xl"></i>
                    </div>
                </div>
                <p id="offline-message" class="text-zinc-500 dark:text-zinc-400">연결할 수 없습니다.</p>
            </div>
        </div>
    </div>

    <!-- Progress Bar (카드 바깥 + 카드와 동일 너비) -->
    <div id="progress-container" class="mt-3 hidden">
        <div class="h-[2px] bg-zinc-200 dark:bg-zinc-800">
            <div id="refresh-progress"
                 class="h-full w-0 bg-zinc-400 dark:bg-zinc-500"
                 style="width: 0%"></div>
        </div>
    </div>

    <div class="text-center mt-8 text-zinc-500 dark:text-zinc-500 text-xs">
        30초마다 자동 갱신 • Ping 기반
    </div>
</div>

<!-- 테마 토글 (화면 오른쪽 위 고정) -->
<button id="theme-toggle"
        class="fixed top-4 right-4 z-50 w-10 h-10 flex items-center justify-center rounded-full bg-white/90 dark:bg-zinc-900/90 text-zinc-500 dark:text-zinc-400 hover:bg-white dark:hover:bg-zinc-800 hover:text-zinc-700 dark:hover:text-zinc-200 shadow-sm border border-zinc-200 dark:border-zinc-700 transition-all active:scale-95"
        aria-label="테마 전환">
    <i id="theme-icon" class="fa-solid fa-moon text-lg"></i>
</button>

<script>
    const sections = {
        loading: document.getElementById('status-loading'),
        online:  document.getElementById('status-online'),
        offline: document.getElementById('status-offline'),
    };

    let isInitialLoad = true;
    let cycleStartTime = null;
    let rafId = null;

    const refreshIndicatorOnline = document.getElementById('refresh-indicator');
    const refreshIndicatorOffline = document.getElementById('refresh-indicator-offline');
    const progressBar = document.getElementById('refresh-progress');
    const progressContainer = document.getElementById('progress-container');

    const CYCLE_DURATION = 30000; // 30초

    // ===== 테마 관리 (라이트/다크 모드 지원) =====
    function initTheme() {
        const toggleBtn = document.getElementById('theme-toggle');
        const icon = document.getElementById('theme-icon');

        function applyTheme(mode) {
            let isDark;
            if (mode === 'system') {
                isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            } else {
                isDark = mode === 'dark';
            }
            document.documentElement.classList.toggle('dark', isDark);

            if (icon) {
                icon.classList.toggle('fa-moon', !isDark);
                icon.classList.toggle('fa-sun', isDark);
            }
        }

        // 초기 테마 적용 (저장된 값 또는 시스템 설정)
        const saved = localStorage.getItem('theme') || 'system';
        applyTheme(saved);

        // 토글 버튼: 클릭 시 명시적 light/dark 전환 + 저장
        toggleBtn?.addEventListener('click', () => {
            const currentlyDark = document.documentElement.classList.contains('dark');
            const next = currentlyDark ? 'light' : 'dark';
            localStorage.setItem('theme', next);
            applyTheme(next);
        });

        // 시스템 설정 변경 시 (사용자가 명시적 선택을 하지 않은 경우에만 반영)
        const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        mediaQuery.addEventListener('change', () => {
            const savedPref = localStorage.getItem('theme');
            if (!savedPref || savedPref === 'system') {
                applyTheme('system');
            }
        });
    }

    initTheme();

    function showRefreshIndicator(show) {
        const action = show ? 'remove' : 'add';
        refreshIndicatorOnline?.classList[action]('hidden');
        refreshIndicatorOffline?.classList[action]('hidden');
    }

    function updateProgress() {
        if (!cycleStartTime || !progressBar) return;

        const elapsed = Date.now() - cycleStartTime;
        const progress = Math.min((elapsed / CYCLE_DURATION) * 100, 100);
        progressBar.style.width = `${progress}%`;

        if (progress < 100) {
            rafId = requestAnimationFrame(updateProgress);
        }
    }

    function startRefreshCycle() {
        if (rafId) cancelAnimationFrame(rafId);
        cycleStartTime = Date.now();
        if (progressBar) {
            progressBar.style.width = '0%';
        }
        rafId = requestAnimationFrame(updateProgress);
    }

    function showSection(name) {
        for (const [key, el] of Object.entries(sections)) {
            if (key === name) {
                el.classList.remove('is-hidden');
                // 다음 프레임에 fade-in 트리거
                requestAnimationFrame(() => el.classList.remove('is-fading'));
            } else {
                el.classList.add('is-fading');
                el.classList.add('is-hidden');
            }
        }
    }

    function showLoading() {
        showSection('loading');
    }

    // MOTD 정리: 앞뒤 공백, 연속 공백, 과도한 빈 줄
    function cleanMotd(s) {
        return (s || '')
            .trim()
            .replace(/[ \t]+/g, ' ')
            .replace(/\n{3,}/g, '\n\n');
    }

    function renderOnline(payload) {
        const d = payload.data || {};
        const players = d.players || {};
        const version = d.version || {};

        // description은 표준 SLP Chat Component: string 또는 {text, extra:[...]} 트리.
        // 일부 서버는 최상위 text를 비우고 extra에만 내용을 담으므로 재귀로 평탄화.
        const flatten = (c) => {
            if (c == null) return '';
            if (typeof c === 'string') return c;
            if (typeof c !== 'object') return '';
            return (c.text || '') + (Array.isArray(c.extra) ? c.extra.map(flatten).join('') : '');
        };
        document.getElementById('online-motd').textContent = cleanMotd(flatten(d.description));
        document.getElementById('online-players-online').textContent = players.online ?? 0;
        document.getElementById('online-players-max').textContent    = players.max ?? 0;
        document.getElementById('online-version').textContent        = version.name || 'Unknown';
        document.getElementById('online-ping').textContent           = payload.ping ?? '-';
        document.getElementById('online-timestamp').textContent      = payload.timestamp || '-';

        // sample은 표준 SLP로 [{name, id, ...}, ...] 형태
        const sampleWrap = document.getElementById('online-sample-wrap');
        const sample = document.getElementById('online-sample');
        sample.replaceChildren();
        if (Array.isArray(players.sample)) {
            for (const p of players.sample) {
                const name = p?.name;
                if (typeof name !== 'string') continue;
                const chip = document.createElement('span');
                chip.className = 'bg-emerald-100 dark:bg-emerald-900/60 text-emerald-700 dark:text-emerald-300 px-4 py-2 rounded-2xl text-sm font-medium';
                chip.textContent = name;
                sample.appendChild(chip);
            }
        }
        if (sample.children.length > 0) {
            sampleWrap.classList.remove('is-hidden');
        } else {
            sampleWrap.classList.add('is-hidden');
        }

        // favicon은 data: URI만 신뢰 (XSS 방지)
        const faviconWrap = document.getElementById('online-favicon-wrap');
        const favicon = document.getElementById('online-favicon');
        if (typeof d.favicon === 'string' && d.favicon.startsWith('data:image/')) {
            favicon.src = d.favicon;
            faviconWrap.classList.remove('is-hidden');
        } else {
            favicon.removeAttribute('src');
            faviconWrap.classList.add('is-hidden');
        }

        showSection('online');
    }

    function renderOffline(msg) {
        document.getElementById('offline-message').textContent = msg || '연결할 수 없습니다.';
        showSection('offline');
    }

    async function loadStatus() {
        const wasInitialLoad = isInitialLoad;

        // Progress Bar 사이클을 loadStatus 시작 시점에 맞춰 시작
        // (실제 30초 폴링 주기와 정확히 동기화되도록)
        startRefreshCycle();

        if (wasInitialLoad) {
            showLoading();
        } else {
            // 백그라운드 갱신일 때만 미묘한 인디케이터 표시
            showRefreshIndicator(true);
        }

        try {
            const res = await fetch('status.php', { cache: 'no-store' });
            const data = await res.json().catch(() => null);

            if (res.ok && data?.online) {
                renderOnline(data);
            } else {
                // res.ok=false → API 인프라 예외 (HTTP 500)
                // data.online=false → MC 서버 오프라인/timeout
                const msg = res.ok
                    ? (data?.error || '서버에 연결할 수 없습니다.')
                    : '상태를 가져오지 못했습니다. 잠시 후 다시 시도해주세요.';
                renderOffline(msg);
            }

            if (res.ok && data && wasInitialLoad) {
                // 최초 성공 시 Progress Bar 표시
                progressContainer?.classList.remove('hidden');
            }
        } catch (e) {
            // 네트워크 단절·DNS 실패 등
            renderOffline('상태를 가져오지 못했습니다.');
        } finally {
            isInitialLoad = false;
            showRefreshIndicator(false);
        }
    }

    loadStatus();
    setInterval(loadStatus, 30000);
</script>
</body>
</html>
