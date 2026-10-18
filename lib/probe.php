<?php
declare(strict_types=1);

/**
 * 수집이 오프라인을 기록하기 전에 조회하는 횟수.
 * 한 번이라도 온라인이면 그 결과를 쓰고, 전부 실패할 때만 마지막 실패를 남긴다.
 * 실시간 페이지는 이 횟수를 쓰지 않고 한 번만 조회한다.
 */
const PROBE_CONFIRM_ATTEMPTS = 3;

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

/**
 * 실패하면 같은 조회를 다시 한다. 한 번이라도 온라인이면 즉시 그 결과를 반환한다.
 *
 * @param callable(): array{online: bool, ping: ?int, data: ?array, error: ?string} $probe
 * @return array{online: bool, ping: ?int, data: ?array, error: ?string}
 */
function probe_confirm(callable $probe, int $attempts): array
{
    $attempts = max(1, $attempts);
    $result = [
        'online' => false,
        'ping' => null,
        'data' => null,
        'error' => null,
    ];
    for ($try = 0; $try < $attempts; $try++) {
        $result = $probe();
        if ($result['online']) {
            return $result;
        }
    }
    return $result;
}

/**
 * 첫 조회가 실패하고 시도가 남아 있으면 $onDefer를 한 번 호출한 뒤 나머지를 조회한다.
 * 수집은 그 자리에서 HTTP 응답을 끝내고, 호출처가 남은 조회를 기다리지 않게 한다.
 *
 * @param callable(): array{online: bool, ping: ?int, data: ?array, error: ?string} $probe
 * @param callable(): void $onDefer
 * @return array{online: bool, ping: ?int, data: ?array, error: ?string}
 */
function probe_confirm_deferred(callable $probe, int $attempts, callable $onDefer): array
{
    $attempts = max(1, $attempts);
    $result = $probe();
    if ($result['online'] || $attempts === 1) {
        return $result;
    }
    $onDefer();
    return probe_confirm($probe, $attempts - 1);
}
