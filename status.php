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

    // description은 string일 수도, { text: ... } 같은 array일 수도 있음 — string으로 정규화
    $description = $info['description'] ?? '';
    if (is_array($description)) {
        $description = $description['text']
            ?? json_encode($description, JSON_UNESCAPED_UNICODE);
    }

    // 플레이어 sample은 [{name, id}, ...] → 이름 문자열 배열로 단순화
    $sampleNames = [];
    if (!empty($info['players']['sample']) && is_array($info['players']['sample'])) {
        foreach ($info['players']['sample'] as $p) {
            if (isset($p['name']) && is_string($p['name'])) {
                $sampleNames[] = $p['name'];
            }
        }
    }

    respond(200, [
        'online' => true,
        'ping'   => $pingMs,
        'data'   => [
            'description' => $description,
            'players'     => [
                'online' => $info['players']['online'] ?? 0,
                'max'    => $info['players']['max']    ?? 0,
                'sample' => $sampleNames,
            ],
            'version' => [
                'name' => $info['version']['name'] ?? 'Unknown',
            ],
            'favicon' => $info['favicon'] ?? null,
        ],
        'error'  => null,
    ]);

} catch (\Throwable $e) {
    failApi($e->getMessage());
}
