<?php
// config.ini 누락은 운영자 설정 문제이므로 페이지 자체가 안 뜨게 즉시 중단.
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/store.php';
if (!file_exists(__DIR__ . '/config.ini')) {
    die('config.ini 파일이 없습니다. config.ini.sample을 config.ini로 복사하여 데이터베이스와 collect_token을 설정하세요.');
}
$config = config_load(__DIR__ . '/config.ini');
if ($config === false) {
    die('config.ini 파일을 읽지 못했습니다.');
}
try {
    $pdo = checks_pdo($config);
    checks_ensure_schema($pdo);
    $servers = checks_servers($pdo);
} catch (Throwable $e) {
    error_log($e->getMessage());
    die('서버 목록을 불러오지 못했습니다.');
}
$requestedId = null;
if (isset($_GET['id']) && is_string($_GET['id']) && preg_match('/^\d+$/', $_GET['id']) === 1) {
    $requestedId = (int) $_GET['id'];
}
$selected = servers_pick($servers, $requestedId);
$multiple = count($servers) > 1;
$heading = $selected === null ? '서버 상태' : $selected['name'];

function index_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function index_refresh_bar(string $marginClass): void
{
    echo '<div id="progress-container" class="' . index_h($marginClass) . ' hidden">' . "\n";
    echo '        <div class="h-[2px] bg-zinc-200 dark:bg-zinc-800">' . "\n";
    echo '            <div id="refresh-progress" class="h-full w-0 bg-zinc-400 dark:bg-zinc-500" style="width: 0%"></div>' . "\n";
    echo '        </div>' . "\n";
    echo '    </div>' . "\n";
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $selected === null ? '서버 상태' : index_h($heading) . ' - 서버 상태' ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <script>
        tailwind.config = {
            darkMode: 'class'
        };
        // FOUC 방지: 가능한 한 빨리 테마 클래스 적용
        (function() {
            try {
                const saved = localStorage.getItem('theme') || 'system';
                const isDark = saved === 'dark' ||
                    (saved === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                if (isDark) {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {
                // localStorage 접근 차단 등의 환경 무시
            }
        })();
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

        .history-cell {
            position: relative;
            flex: 1 1 0;
            min-width: 0;
            height: 2.5rem;
            border-radius: 2px;
            background: #d4d4d8;
        }
        .dark .history-cell[data-color="gray"] {
            background: #3f3f46;
        }
        .history-cell[data-color="green"] {
            background: #22c55e;
        }
        .history-cell[data-color="yellow"] {
            background: #facc15;
        }
        .history-cell[data-color="red"] {
            background: #ef4444;
        }
        .history-cell .history-tip {
            display: none;
            position: absolute;
            z-index: 30;
            left: 50%;
            bottom: calc(100% + 8px);
            transform: translateX(-50%);
            width: max-content;
            padding: 0.5rem 0.625rem;
            border-radius: 0.75rem;
            background: #18181b;
            color: #fafafa;
            font-size: 0.75rem;
            line-height: 1.45;
            white-space: pre;
            pointer-events: none;
        }
        .dark .history-cell .history-tip {
            background: #fafafa;
            color: #18181b;
        }
        .history-cell:hover .history-tip,
        .history-cell.is-open .history-tip {
            display: block;
        }
        .history-cell.has-incident {
            cursor: pointer;
        }
        .history-cell.is-selected {
            outline: 2px solid #18181b;
            outline-offset: 1px;
            z-index: 1;
        }
        .dark .history-cell.is-selected {
            outline-color: #fafafa;
        }
        .history-cell:focus-visible {
            outline: 2px solid #2563eb;
            outline-offset: 1px;
            z-index: 2;
        }
        .dark .history-cell:focus-visible {
            outline-color: #93c5fd;
        }
        .server-drawer[hidden] {
            display: none;
        }
        .server-drawer {
            position: fixed;
            inset: 0;
            z-index: 60;
        }
        .server-drawer-backdrop {
            position: absolute;
            inset: 0;
            background: rgb(0 0 0 / 0.45);
        }
        .server-drawer-panel {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            max-height: 70vh;
            overflow: auto;
            padding: 0.75rem 1rem calc(1.25rem + env(safe-area-inset-bottom));
            border-radius: 1.5rem 1.5rem 0 0;
            background: #fff;
        }
        .dark .server-drawer-panel {
            background: #18181b;
        }
    </style>
</head>
<body class="text-zinc-900 dark:text-white min-h-screen py-12 px-4">
<div class="max-w-3xl mx-auto">
    <div class="text-center mb-10">
        <?php if ($multiple && $selected !== null): ?>
        <button type="button" id="server-drawer-open" class="inline-flex items-center justify-center gap-2" aria-expanded="false" aria-controls="server-drawer">
            <h1 class="text-4xl font-bold tracking-tight"><?= index_h($heading) ?></h1>
            <i class="fa-solid fa-chevron-down text-xl text-zinc-400" aria-hidden="true"></i>
        </button>
        <?php else: ?>
        <h1 class="text-4xl font-bold tracking-tight"><?= index_h($heading) ?></h1>
        <?php endif; ?>
        <p class="text-zinc-500 dark:text-zinc-400 mt-2">실시간 서버 상태</p>
    </div>

    <?php if ($multiple && $selected !== null): ?>
    <div id="server-drawer" class="server-drawer" hidden>
        <button type="button" class="server-drawer-backdrop" aria-label="닫기"></button>
        <div class="server-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="server-drawer-title">
            <h2 id="server-drawer-title" class="sr-only">서버 선택</h2>
            <ul class="max-w-3xl mx-auto">
                <?php foreach ($servers as $server): ?>
                <?php $serverFavicon = $server['favicon'] ?? null; ?>
                <li>
                    <a class="flex items-center gap-3 rounded-2xl px-3 py-3 hover:bg-zinc-100 dark:hover:bg-zinc-800" href="?id=<?= (int) $server['id'] ?>">
                        <?php if (is_string($serverFavicon) && $serverFavicon !== ''): ?>
                        <img class="h-12 w-12 rounded-xl border border-zinc-200 dark:border-zinc-700" src="<?= index_h($serverFavicon) ?>" alt="">
                        <?php else: ?>
                        <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-zinc-100 text-zinc-400 dark:bg-zinc-800" aria-hidden="true">
                            <i class="fa-solid fa-server"></i>
                        </span>
                        <?php endif; ?>
                        <span class="text-lg font-medium"><?= index_h($server['name']) ?></span>
                        <?php if ((int) $server['id'] === (int) $selected['id']): ?>
                        <i class="fa-solid fa-check ml-auto text-zinc-500" aria-label="선택됨"></i>
                        <?php endif; ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <script>
        (function () {
            const openButton = document.getElementById('server-drawer-open');
            const drawer = document.getElementById('server-drawer');
            if (!openButton || !drawer) {
                return;
            }
            const closeDrawer = () => {
                drawer.hidden = true;
                openButton.setAttribute('aria-expanded', 'false');
            };
            openButton.addEventListener('click', () => {
                const willOpen = drawer.hidden;
                drawer.hidden = !willOpen;
                openButton.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });
            drawer.querySelector('.server-drawer-backdrop').addEventListener('click', closeDrawer);
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    closeDrawer();
                }
            });
        })();
    </script>
    <?php endif; ?>

    <?php if ($servers === []): ?>
    <p class="empty-servers text-center text-zinc-500 dark:text-zinc-400">등록된 서버가 없습니다</p>
    <?php endif; ?>

    <?php if ($selected !== null): ?>
    <?php $server = $selected; ?>
    <section class="server-block"
             data-id="<?= (int) $server['id'] ?>"
             data-host="<?= index_h($server['host']) ?>"
             data-port="<?= (int) $server['port'] ?>">
        <div class="status-card card bg-white dark:bg-zinc-900 rounded-3xl p-8 shadow-2xl border border-zinc-200 dark:border-zinc-800">
            <div class="status-section text-center py-16" data-section="loading">
                <i class="fa-solid fa-circle-notch fa-spin text-6xl text-zinc-500 dark:text-zinc-400 mb-6"></i>
                <p class="text-zinc-500 dark:text-zinc-400">서버 상태 확인 중…</p>
            </div>

            <div class="status-section is-hidden is-fading" data-section="online">
                <div class="mb-6 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-5 h-5 bg-green-500 rounded-full animate-pulse"></div>
                        <span class="text-3xl font-bold text-green-600 dark:text-green-400">온라인</span>
                    </div>
                    <div class="refresh-indicator hidden text-zinc-500 dark:text-zinc-400">
                        <i class="fa-solid fa-rotate fa-spin text-3xl"></i>
                    </div>
                </div>

                <div class="online-motd bg-zinc-100 dark:bg-zinc-800/70 rounded-2xl p-6 mb-7 text-lg leading-relaxed whitespace-pre-wrap text-center border border-zinc-200 dark:border-zinc-700"></div>

                <div class="grid grid-cols-2 gap-6 mb-8">
                    <div>
                        <p class="text-zinc-500 dark:text-zinc-400 text-sm">접속자</p>
                        <p class="text-5xl font-bold text-zinc-900 dark:text-white">
                            <span class="online-players-online">0</span><span class="text-2xl text-zinc-500 dark:text-zinc-500"> / <span class="online-players-max">0</span></span>
                        </p>
                    </div>
                    <div>
                        <p class="text-zinc-500 dark:text-zinc-400 text-sm">버전</p>
                        <p class="online-version text-xl font-medium text-zinc-900 dark:text-white">Unknown</p>
                    </div>
                </div>

                <div class="online-sample-wrap mb-8 is-hidden">
                    <p class="text-zinc-500 dark:text-zinc-400 text-sm mb-3">현재 접속 중</p>
                    <div class="online-sample flex flex-wrap gap-2"></div>
                </div>

                <div class="online-favicon-wrap flex justify-center mb-6 is-hidden">
                    <img class="online-favicon w-28 h-28 rounded-2xl border-4 border-zinc-200 dark:border-zinc-700 shadow-inner" alt="서버 아이콘">
                </div>

                <div class="text-center text-zinc-500 dark:text-zinc-500 text-sm pt-4 border-t border-zinc-200 dark:border-zinc-800">
                    핑 <span class="online-ping">-</span>ms • <span class="online-timestamp">-</span>
                </div>
            </div>

            <div class="status-section is-hidden is-fading" data-section="offline">
                <div class="py-16 text-center">
                    <i class="fa-solid fa-server text-7xl text-red-500/80 mb-6"></i>
                    <div class="mb-3 flex items-center justify-center gap-3">
                        <h2 class="text-3xl font-bold text-red-500 dark:text-red-400">서버가 오프라인입니다</h2>
                        <div class="refresh-indicator-offline hidden text-zinc-500 dark:text-zinc-400">
                            <i class="fa-solid fa-rotate fa-spin text-3xl"></i>
                        </div>
                    </div>
                    <p class="offline-message text-zinc-500 dark:text-zinc-400">연결할 수 없습니다.</p>
                </div>
            </div>
        </div>

        <?php index_refresh_bar('mt-3'); ?>

        <section class="history mt-8" aria-labelledby="history-heading">
            <div class="flex items-baseline justify-between gap-3 mb-3">
                <h2 id="history-heading" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">지난 90일</h2>
                <p class="history-uptime text-sm text-zinc-500 dark:text-zinc-400">—</p>
            </div>
            <div class="history-cells flex gap-px" role="list" aria-label="지난 90일">
                <?php for ($historyCell = 0; $historyCell < 90; $historyCell++): ?>
                    <div class="history-cell" data-color="gray" role="listitem"></div>
                <?php endfor; ?>
            </div>
            <p class="history-empty mt-3 text-sm text-zinc-500 dark:text-zinc-400">아직 기록이 없습니다</p>
            <p class="history-last mt-2 text-xs text-zinc-500 dark:text-zinc-400">마지막 수집 없음</p>
            <div class="history-incidents-wrap mt-4 is-hidden">
                <p class="history-incidents-label text-xs text-zinc-500 dark:text-zinc-400 mb-2">끊긴 구간</p>
                <ul class="history-incidents text-sm text-zinc-700 dark:text-zinc-300 space-y-1"></ul>
            </div>
        </section>
    </section>
    <?php endif; ?>

    <div class="text-center mt-8 text-zinc-500 dark:text-zinc-500 text-xs">
        30초마다 자동 갱신 • Ping 기반
    </div>
</div>

<button id="theme-toggle"
        class="fixed top-4 right-4 z-50 w-10 h-10 flex items-center justify-center rounded-full bg-white/90 dark:bg-zinc-900/90 text-zinc-500 dark:text-zinc-400 hover:bg-white dark:hover:bg-zinc-800 hover:text-zinc-700 dark:hover:text-zinc-200 shadow-sm border border-zinc-200 dark:border-zinc-700 transition-all active:scale-95"
        aria-label="테마 전환">
    <i id="theme-icon" class="fa-solid fa-moon text-lg"></i>
</button>

<script>
    const blockStates = new WeakMap();

    function blockState(block) {
        let state = blockStates.get(block);
        if (!state) {
            state = { initial: true, pinned: null, incidents: new Map(), view: null };
            blockStates.set(block, state);
        }
        return state;
    }

    const refreshIndicatorSelector = '.refresh-indicator, .refresh-indicator-offline';
    const progressBar = document.getElementById('refresh-progress');
    const progressContainer = document.getElementById('progress-container');
    const CYCLE_DURATION = 30000;
    let cycleStartTime = null;
    let rafId = null;

    function initTheme() {
        const toggleBtn = document.getElementById('theme-toggle');
        const icon = document.getElementById('theme-icon');
        const THEME_ORDER = ['system', 'light', 'dark'];

        function updateIcon(mode) {
            if (!icon || !toggleBtn) return;
            icon.classList.remove('fa-sun', 'fa-moon', 'fa-circle-half-stroke');
            if (mode === 'light') {
                icon.classList.add('fa-sun');
                toggleBtn.title = '라이트 모드 (클릭하여 변경)';
            } else if (mode === 'dark') {
                icon.classList.add('fa-moon');
                toggleBtn.title = '다크 모드 (클릭하여 변경)';
            } else {
                icon.classList.add('fa-circle-half-stroke');
                toggleBtn.title = '시스템 설정 따르기 (클릭하여 변경)';
            }
        }

        function applyTheme(mode) {
            const isDark = mode === 'dark' ||
                (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', isDark);
            localStorage.setItem('theme', mode);
            updateIcon(mode);
        }

        const saved = localStorage.getItem('theme') || 'system';
        applyTheme(saved);

        toggleBtn?.addEventListener('click', () => {
            const current = localStorage.getItem('theme') || 'system';
            const currentIndex = THEME_ORDER.indexOf(current);
            const next = THEME_ORDER[(currentIndex + 1) % THEME_ORDER.length];
            applyTheme(next);
        });

        const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        mediaQuery.addEventListener('change', () => {
            if ((localStorage.getItem('theme') || 'system') === 'system') {
                applyTheme('system');
            }
        });
    }

    try {
        initTheme();
    } catch (e) {
        console.error('테마 초기화 실패:', e);
    }

    function showRefreshIndicator(block, show) {
        block.querySelectorAll(refreshIndicatorSelector).forEach((node) => {
            node.classList.toggle('hidden', !show);
        });
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

    function showSection(block, name) {
        block.querySelectorAll('.status-section').forEach((el) => {
            if (el.dataset.section === name) {
                el.classList.remove('is-hidden');
                requestAnimationFrame(() => el.classList.remove('is-fading'));
            } else {
                el.classList.add('is-fading');
                el.classList.add('is-hidden');
            }
        });
    }

    function cleanMotd(s) {
        return (s || '')
            .trim()
            .replace(/[ \t]+/g, ' ')
            .replace(/\n{3,}/g, '\n\n');
    }

    function renderOnline(block, payload) {
        const d = payload.data || {};
        const players = d.players || {};
        const version = d.version || {};
        const flatten = (c) => {
            if (c == null) return '';
            if (typeof c === 'string') return c;
            if (typeof c !== 'object') return '';
            return (c.text || '') + (Array.isArray(c.extra) ? c.extra.map(flatten).join('') : '');
        };
        block.querySelector('.online-motd').textContent = cleanMotd(flatten(d.description));
        block.querySelector('.online-players-online').textContent = players.online ?? 0;
        block.querySelector('.online-players-max').textContent = players.max ?? 0;
        block.querySelector('.online-version').textContent = version.name || 'Unknown';
        block.querySelector('.online-ping').textContent = payload.ping ?? '-';
        block.querySelector('.online-timestamp').textContent = payload.timestamp || '-';

        const sampleWrap = block.querySelector('.online-sample-wrap');
        const sample = block.querySelector('.online-sample');
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
        sampleWrap.classList.toggle('is-hidden', sample.children.length === 0);

        const faviconWrap = block.querySelector('.online-favicon-wrap');
        const favicon = block.querySelector('.online-favicon');
        if (typeof d.favicon === 'string' && d.favicon.startsWith('data:image/')) {
            favicon.src = d.favicon;
            faviconWrap.classList.remove('is-hidden');
        } else {
            favicon.removeAttribute('src');
            faviconWrap.classList.add('is-hidden');
        }

        showSection(block, 'online');
    }

    function renderOffline(block, msg) {
        block.querySelector('.offline-message').textContent = msg || '연결할 수 없습니다.';
        showSection(block, 'offline');
    }

    async function loadBlockStatus(block) {
        const state = blockState(block);
        const initial = state.initial;
        if (initial) {
            showSection(block, 'loading');
        } else {
            showRefreshIndicator(block, true);
        }

        try {
            const params = new URLSearchParams({
                host: block.dataset.host || '',
                port: block.dataset.port || ''
            });
            const res = await fetch('api/status.php?' + params.toString(), { cache: 'no-store' });
            const data = await res.json().catch(() => null);

            if (res.ok && data?.online) {
                renderOnline(block, data);
            } else {
                const msg = res.ok
                    ? (data?.error || '서버에 연결할 수 없습니다.')
                    : '상태를 가져오지 못했습니다. 잠시 후 다시 시도해주세요.';
                renderOffline(block, msg);
            }

            if (res.ok && data && initial) {
                progressContainer?.classList.remove('hidden');
            }
        } catch (e) {
            renderOffline(block, '상태를 가져오지 못했습니다.');
        } finally {
            state.initial = false;
            showRefreshIndicator(block, false);
        }
    }

    function loadStatus() {
        startRefreshCycle();
        document.querySelectorAll('.server-block').forEach((block) => {
            loadBlockStatus(block);
        });
    }

    function formatUptime(value) {
        if (value === null || value === undefined || Number.isNaN(Number(value))) {
            return '없음';
        }
        const number = Number(value);
        const shown = number >= 100 ? 100 : Math.floor(number * 10 + 1e-8) / 10;
        return Number.isInteger(shown) ? String(shown) : shown.toFixed(1);
    }

    function placeHistoryTip(cell) {
        const tip = cell.querySelector('.history-tip');
        if (!tip) {
            return;
        }
        tip.style.left = '50%';
        tip.style.transform = 'translateX(-50%)';
        const wasHidden = window.getComputedStyle(tip).display === 'none';
        if (wasHidden) {
            tip.style.display = 'block';
        }
        const margin = 8;
        const rect = tip.getBoundingClientRect();
        let shift = 0;
        if (rect.left < margin) {
            shift = margin - rect.left;
        } else if (rect.right > window.innerWidth - margin) {
            shift = window.innerWidth - margin - rect.right;
        }
        tip.style.transform = 'translateX(calc(-50% + ' + shift + 'px))';
        if (wasHidden) {
            tip.style.display = '';
        }
    }

    const kstFormatter = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Seoul',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23'
    });

    function formatKst(utcSql) {
        if (!utcSql) {
            return '없음';
        }
        const date = new Date(String(utcSql).replace(' ', 'T') + 'Z');
        if (Number.isNaN(date.getTime())) {
            return String(utcSql);
        }
        const parts = kstFormatter.formatToParts(date);
        const part = (type) => parts.find((item) => item.type === type)?.value ?? '';
        return part('year') + '-' + part('month') + '-' + part('day') + ' ' + part('hour') + ':' + part('minute');
    }

    function formatDuration(seconds) {
        const total = Math.max(0, Math.trunc(Number(seconds) || 0));
        if (total < 60) {
            return total + '초';
        }
        const minutes = Math.floor(total / 60);
        if (minutes < 60) {
            const remain = total % 60;
            return remain === 0 ? minutes + '분' : minutes + '분 ' + remain + '초';
        }
        const hours = Math.floor(minutes / 60);
        const remainMinutes = minutes % 60;
        return remainMinutes === 0 ? hours + '시간' : hours + '시간 ' + remainMinutes + '분';
    }

    function kstDate(utcSql) {
        return formatKst(utcSql).slice(0, 10);
    }

    function nextSeoulDate(isoDate) {
        const [year, month, day] = isoDate.split('-').map(Number);
        const date = new Date(Date.UTC(year, month - 1, day));
        date.setUTCDate(date.getUTCDate() + 1);
        return date.toISOString().slice(0, 10);
    }

    function indexIncidentsByDate(incidents) {
        const byDate = new Map();
        incidents.forEach((incident) => {
            const start = kstDate(incident.start);
            const end = kstDate(incident.end);
            if (!/^\d{4}-\d{2}-\d{2}$/.test(start) || !/^\d{4}-\d{2}-\d{2}$/.test(end) || start > end) {
                return;
            }
            let cursor = start;
            for (let guard = 0; guard < 120 && cursor <= end; guard++) {
                const list = byDate.get(cursor);
                if (list) {
                    list.push(incident);
                } else {
                    byDate.set(cursor, [incident]);
                }
                if (cursor === end) {
                    return;
                }
                const next = nextSeoulDate(cursor);
                if (next <= cursor) {
                    return;
                }
                cursor = next;
            }
        });
        return byDate;
    }

    function incidentsOnDate(state, date) {
        if (!date) {
            return [];
        }
        return state.incidents.get(date) ?? [];
    }

    function chosenIncidentDate(state, days) {
        if (state.pinned) {
            return incidentsOnDate(state, state.pinned).length > 0 ? state.pinned : null;
        }
        const today = days.length > 0 ? String(days[days.length - 1].date ?? '') : '';
        return today && incidentsOnDate(state, today).length > 0 ? today : null;
    }

    function incidentCells(block) {
        return [...block.querySelectorAll('.history-cells .history-cell.has-incident')];
    }

    function syncIncidentFocus(block) {
        const cells = incidentCells(block);
        block.querySelectorAll('.history-cells .history-cell').forEach((cell) => {
            if (!cell.classList.contains('has-incident')) {
                cell.removeAttribute('tabindex');
            }
        });
        if (cells.length === 0) {
            return;
        }
        const current = cells.find((cell) => cell.classList.contains('is-selected'))
            || cells.find((cell) => cell.getAttribute('tabindex') === '0')
            || cells[cells.length - 1];
        cells.forEach((cell) => {
            const tabIndex = cell === current ? '0' : '-1';
            if (cell.getAttribute('tabindex') !== tabIndex) {
                cell.tabIndex = Number(tabIndex);
            }
        });
    }

    function selectIncidentDate(block, date) {
        const state = blockState(block);
        if (!date || incidentsOnDate(state, date).length === 0) {
            return;
        }
        state.pinned = date;
        renderIncidentSelection(block);
    }

    function renderIncidentSelection(block) {
        const state = blockState(block);
        const wrap = block.querySelector('.history-incidents-wrap');
        const list = block.querySelector('.history-incidents');
        const label = block.querySelector('.history-incidents-label');
        if (!wrap || !list) {
            return;
        }
        const days = Array.isArray(state.view?.days) ? state.view.days : [];
        const date = chosenIncidentDate(state, days);
        block.querySelectorAll('.history-cells .history-cell').forEach((cell) => {
            const selected = date !== null && cell.dataset.date === date;
            cell.classList.toggle('is-selected', selected);
            if (selected) {
                cell.setAttribute('aria-current', 'date');
            } else {
                cell.removeAttribute('aria-current');
            }
        });
        list.replaceChildren();
        const shown = incidentsOnDate(state, date);
        wrap.classList.toggle('is-hidden', shown.length === 0);
        if (label && date) {
            label.textContent = date + ' 끊긴 구간';
        }
        shown.forEach((incident) => {
            const item = document.createElement('li');
            item.textContent = formatKst(incident.start)
                + ' – '
                + formatKst(incident.end)
                + ' · '
                + formatDuration(incident.duration_seconds);
            list.appendChild(item);
        });
        syncIncidentFocus(block);
    }

    function renderHistory(block, data) {
        const state = blockState(block);
        state.view = data;
        const cells = block.querySelectorAll('.history-cells .history-cell');
        const days = Array.isArray(data?.days) ? data.days : [];
        const incidents = Array.isArray(data?.incidents) ? data.incidents : [];
        state.incidents = indexIncidentsByDate(incidents);
        const allowed = { gray: true, green: true, yellow: true, red: true };
        days.forEach((day, index) => {
            const cell = cells[index];
            if (!cell) {
                return;
            }
            const color = allowed[day.color] ? day.color : 'gray';
            cell.dataset.color = color;
            cell.dataset.date = String(day.date ?? '');
            cell.classList.toggle('has-incident', incidentsOnDate(state, cell.dataset.date).length > 0);
            const uptime = formatUptime(day.uptime);
            const ping = day.avg_ping === null || day.avg_ping === undefined
                ? '평균 핑 없음'
                : '평균 핑 ' + Math.round(Number(day.avg_ping)) + 'ms';
            const detail = [
                String(day.date ?? ''),
                '가동률 ' + (uptime === '없음' ? '없음' : uptime + '%'),
                '샘플 ' + String(day.samples ?? 0),
                ping
            ].join('\n');
            cell.removeAttribute('title');
            cell.setAttribute('aria-label', detail);
            let tip = cell.querySelector('.history-tip');
            if (!tip) {
                tip = document.createElement('div');
                tip.className = 'history-tip';
                cell.appendChild(tip);
            }
            tip.textContent = detail;
        });
        for (let index = days.length; index < cells.length; index++) {
            cells[index].dataset.date = '';
            cells[index].classList.remove('has-incident');
        }

        const uptimeNode = block.querySelector('.history-uptime');
        if (uptimeNode) {
            uptimeNode.textContent = data?.uptime === null || data?.uptime === undefined
                ? '—'
                : formatUptime(data.uptime) + '%';
        }

        const emptyNode = block.querySelector('.history-empty');
        if (emptyNode) {
            emptyNode.classList.toggle('is-hidden', data?.empty !== true);
        }

        const lastNode = block.querySelector('.history-last');
        if (lastNode) {
            lastNode.textContent = data?.last_checked_at
                ? '마지막 수집 ' + formatKst(data.last_checked_at)
                : '마지막 수집 없음';
        }

        renderIncidentSelection(block);
    }

    function showHistoryError(block) {
        const emptyNode = block.querySelector('.history-empty');
        if (emptyNode) {
            emptyNode.textContent = '기록을 불러오지 못했습니다';
            emptyNode.classList.remove('is-hidden');
        }
    }

    async function loadHistory() {
        const blocks = [...document.querySelectorAll('.server-block')];
        try {
            const res = await fetch('api/history.php', { cache: 'no-store' });
            if (!res.ok) {
                blocks.forEach((block) => showHistoryError(block));
                return;
            }
            const data = await res.json().catch(() => null);
            const servers = Array.isArray(data?.servers) ? data.servers : null;
            if (!servers) {
                blocks.forEach((block) => showHistoryError(block));
                return;
            }
            blocks.forEach((block) => {
                const summary = servers.find((item) => Number(item.id) === Number(block.dataset.id));
                if (!summary) {
                    showHistoryError(block);
                    return;
                }
                const emptyNode = block.querySelector('.history-empty');
                if (emptyNode && summary.empty === true) {
                    emptyNode.textContent = '아직 기록이 없습니다';
                }
                renderHistory(block, summary);
            });
        } catch (e) {
            blocks.forEach((block) => showHistoryError(block));
        }
    }

    loadStatus();
    setInterval(loadStatus, 30000);

    try {
        document.querySelectorAll('.server-block').forEach((block) => {
            const cells = block.querySelector('.history-cells');
            cells?.addEventListener('click', (event) => {
                const cell = event.target.closest('.history-cell');
                if (!cell || !block.contains(cell)) {
                    return;
                }
                const wasOpen = cell.classList.contains('is-open');
                block.querySelectorAll('.history-cell.is-open').forEach((node) => node.classList.remove('is-open'));
                if (!wasOpen) {
                    cell.classList.add('is-open');
                    placeHistoryTip(cell);
                }
                if (cell.classList.contains('has-incident') && cell.dataset.date) {
                    selectIncidentDate(block, cell.dataset.date);
                }
            });
            cells?.addEventListener('keydown', (event) => {
                const cell = event.target.closest('.history-cell');
                if (!cell || !block.contains(cell) || !cell.classList.contains('has-incident')) {
                    return;
                }
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    selectIncidentDate(block, cell.dataset.date);
                    return;
                }
                if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
                    return;
                }
                const incidentList = incidentCells(block);
                const index = incidentList.indexOf(cell);
                if (index < 0) {
                    return;
                }
                const next = incidentList[index + (event.key === 'ArrowRight' ? 1 : -1)];
                if (!next) {
                    return;
                }
                event.preventDefault();
                selectIncidentDate(block, next.dataset.date);
                next.focus();
            });
            cells?.addEventListener('mouseover', (event) => {
                const cell = event.target.closest('.history-cell');
                if (cell && block.contains(cell)) {
                    placeHistoryTip(cell);
                }
            });
        });
        loadHistory();
        setInterval(loadHistory, 300000);
    } catch (e) {
        // 기록 UI 초기화 실패는 실시간 갱신을 멈추지 않는다.
    }
</script>
</body>
</html>
