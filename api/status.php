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
    $configPath = dirname(__DIR__) . '/config.ini';
    if (!file_exists($configPath)) {
        failApi('config.ini not found');
    }
    require_once dirname(__DIR__) . '/lib/config.php';
    $config = config_load($configPath);
    if ($config === false) {
        failApi('config.ini parse failed');
    }

    require_once dirname(__DIR__) . '/lib/probe.php';

    $host    = (string) ($config['server_host'] ?? '');
    $port    = (int)    ($config['server_port'] ?? 25565);
    $timeout = (float)  ($config['timeout']     ?? 3);

    if ($host === '') {
        failApi('server_host is empty in config.ini');
    }

    // 실시간 조회는 수집과 같은 probe를 쓰지만 행을 넣지 않는다.
    $result = probe_server($host, $port, $timeout);
    if (!$result['online']) {
        failOffline($result['error'] ?? '서버에 연결할 수 없습니다.');
    }

    respond(200, [
        'online' => true,
        'ping'   => $result['ping'],
        'data'   => $result['data'],
        'error'  => null,
    ]);

} catch (\Throwable $e) {
    // 예외 메시지에 서버 절대 경로 등 민감 정보가 포함될 수 있으므로 로그에만 남기고
    // 클라이언트에는 일반 메시지를 반환한다.
    error_log($e->getMessage());
    failApi('Internal Server Error');
}
