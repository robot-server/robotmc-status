<?php
declare(strict_types=1);

/**
 * 실시간 상태와 수집이 같은 마인크래프트 조회를 쓴다.
 * 이 함수는 기록을 저장하지 않는다.
 *
 * @return array{online: bool, ping: ?int, data: ?array, error: ?string}
 */
function probe_server(string $host, int $port, float $timeout): array
{
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    require_once __DIR__ . '/real_ping.php';

    $ping = null;
    $info = null;
    try {
        $ping = new \xPaw\MinecraftPing($host, $port, $timeout);
        $info = $ping->Query();
    } catch (\xPaw\MinecraftPingException $e) {
        return [
            'online' => false,
            'ping' => null,
            'data' => null,
            'error' => $e->getMessage(),
        ];
    } finally {
        if ($ping !== null) {
            $ping->Close();
        }
    }

    $pingMs = null;
    if ($info !== null) {
        $pingMs = get_minecraft_real_ping($host, $port, $timeout);
    }

    return [
        'online' => true,
        'ping' => $pingMs,
        'data' => is_array($info) ? $info : null,
        'error' => null,
    ];
}
