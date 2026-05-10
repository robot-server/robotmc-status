<?php
// ==================== 설정 ====================
if (!file_exists(__DIR__ . '/config.ini')) {
    die('config.ini 파일이 없습니다. config.ini.sample을 config.ini로 복사하여 서버 IP 등을 수정하세요.');
}
$config = parse_ini_file(__DIR__ . '/config.ini');
$SERVER_HOST = $config['server_host'];
$SERVER_PORT = $config['server_port'];
$SERVER_NAME = $config['server_name'];
$TIMEOUT = $config['timeout'];
// ============================================

require __DIR__ . '/vendor/autoload.php';

use xPaw\MinecraftPing;
use xPaw\MinecraftPingException;

$info = null;
$error = null;
$pingTime = null;

$start = microtime(true);

try {
    $ping = new MinecraftPing($SERVER_HOST, $SERVER_PORT, $TIMEOUT);
    $info = $ping->Query();                    // Server List Ping
} catch (MinecraftPingException $e) {
    $error = $e->getMessage();
} finally {
    if (isset($ping)) $ping->Close();
}

$pingTime = round((microtime(true) - $start) * 1000);
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($SERVER_NAME) ?> - 서버 상태</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(to bottom, #18181b, #09090b);
        }

        .card {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .card:hover {
            transform: translateY(-8px);
            box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.4);
        }
    </style>
</head>
<body class="text-white min-h-screen py-12 px-4">
<div class="max-w-lg mx-auto">
    <div class="text-center mb-10">
        <h1 class="text-4xl font-bold tracking-tight"><?= htmlspecialchars($SERVER_NAME) ?></h1>
        <p class="text-zinc-400 mt-2">실시간 서버 상태</p>
    </div>

    <div id="status-card" class="card bg-zinc-900 rounded-3xl p-8 shadow-2xl border border-zinc-800">
        <?php if ($info !== null): ?>
            <!-- 온라인 상태 -->
            <div class="flex items-center gap-3 mb-6">
                <div class="w-5 h-5 bg-green-500 rounded-full animate-pulse"></div>
                <span class="text-3xl font-bold text-green-400">온라인</span>
            </div>

            <!-- MOTD -->
            <div class="bg-zinc-800/70 rounded-2xl p-6 mb-7 text-lg leading-relaxed whitespace-pre-wrap border border-zinc-700">
                <?= nl2br(htmlspecialchars(
                        is_array($info['description'] ?? '')
                                ? ($info['description']['text'] ?? json_encode($info['description'], JSON_UNESCAPED_UNICODE))
                                : ($info['description'] ?? 'MOTD 없음')
                )) ?>
            </div>

            <!-- 플레이어 수 + 버전 -->
            <div class="grid grid-cols-2 gap-6 mb-8">
                <div>
                    <p class="text-zinc-400 text-sm">접속자</p>
                    <p class="text-5xl font-bold text-white">
                        <?= $info['players']['online'] ?? 0 ?>
                        <span class="text-2xl text-zinc-500">/ <?= $info['players']['max'] ?? 0 ?></span>
                    </p>
                </div>
                <div>
                    <p class="text-zinc-400 text-sm">버전</p>
                    <p class="text-xl font-medium"><?= htmlspecialchars($info['version']['name'] ?? 'Unknown') ?></p>
                </div>
            </div>

            <!-- 접속 중인 플레이어 샘플 -->
            <?php if (!empty($info['players']['sample'])): ?>
                <div class="mb-8">
                    <p class="text-zinc-400 text-sm mb-3">현재 접속 중</p>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($info['players']['sample'] as $player): ?>
                            <span class="bg-emerald-900/60 text-emerald-300 px-4 py-2 rounded-2xl text-sm font-medium">
                                    <?= htmlspecialchars($player['name']) ?>
                                </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 서버 아이콘 -->
            <?php if (!empty($info['favicon'])): ?>
                <div class="flex justify-center mb-6">
                    <img src="<?= $info['favicon'] ?>"
                         class="w-28 h-28 rounded-2xl border-4 border-zinc-700 shadow-inner"
                         alt="서버 아이콘">
                </div>
            <?php endif; ?>

            <div class="text-center text-zinc-500 text-sm pt-4 border-t border-zinc-800">
                핑 <?= $pingTime ?>ms • <?= date('Y-m-d H:i:s') ?>
            </div>

        <?php else: ?>
            <!-- 오프라인 -->
            <div class="text-center py-16">
                <i class="fa-solid fa-server text-7xl text-red-500/80 mb-6"></i>
                <h2 class="text-3xl font-bold text-red-400 mb-3">서버가 오프라인입니다</h2>
                <p class="text-zinc-400"><?= htmlspecialchars($error ?? '연결할 수 없습니다.') ?></p>
            </div>
        <?php endif; ?>
    </div>

    <div class="text-center mt-10 text-zinc-500 text-xs">
        30초마다 자동 갱신 • Ping 기반
    </div>
</div>

<script>
    // 30초 후 자동 새로고침
    setTimeout(() => {
        location.reload();
    }, 30000);
</script>
</body>
</html>
