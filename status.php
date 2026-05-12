<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $httpStatus, array $payload): void
{
    http_response_code($httpStatus);
    $payload['timestamp'] = date('Y-m-d H:i:s');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function failApi(string $error): void
{
    respond(500, [
        'online' => null,
        'ping'   => null,
        'data'   => null,
        'error'  => $error,
    ]);
}

function failOffline(string $error): void
{
    respond(200, [
        'online' => false,
        'ping'   => null,
        'data'   => null,
        'error'  => $error,
    ]);
}

try {
    $configPath = __DIR__ . '/config.ini';
    if (!file_exists($configPath)) {
        failApi('config.ini not found');
    }
    $config = parse_ini_file($configPath);
    if ($config === false) {
        failApi('config.ini parse failed');
    }

    require __DIR__ . '/vendor/autoload.php';

    $host    = (string) ($config['server_host'] ?? '');
    $port    = (int)    ($config['server_port'] ?? 25565);
    $timeout = (float)  ($config['timeout']     ?? 3);

    if ($host === '') {
        failApi('server_host is empty in config.ini');
    }

    $ping = null;
    $info = null;
    $pingMs = null;

    $start = microtime(true);
    try {
        $ping = new \xPaw\MinecraftPing($host, $port, $timeout);
        $info = $ping->Query();
        $pingMs = (int) round((microtime(true) - $start) * 1000);
    } catch (\xPaw\MinecraftPingException $e) {
        failOffline($e->getMessage());
    } finally {
        if ($ping !== null) {
            $ping->Close();
        }
    }

    // xpaw 응답은 MC Server List Ping 표준 형태({description, players, version, favicon, ...})
    // 그대로 전달해 표준 호환을 유지한다. 클라이언트가 표준 형태를 직접 처리.
    respond(200, [
        'online' => true,
        'ping'   => $pingMs,
        'data'   => $info,
        'error'  => null,
    ]);

} catch (\Throwable $e) {
    // 예외 메시지에 서버 절대 경로 등 민감 정보가 포함될 수 있으므로 로그에만 남기고
    // 클라이언트에는 일반 메시지를 반환한다.
    error_log($e->getMessage());
    failApi('Internal Server Error');
}
