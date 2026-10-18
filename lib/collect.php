<?php
declare(strict_types=1);

require_once __DIR__ . '/probe.php';
require_once __DIR__ . '/store.php';

function collect_token_matches(string $presented, string $expected): bool
{
    if ($expected === '' || $presented === '') {
        return false;
    }
    return hash_equals($expected, $presented);
}

/**
 * 토큰이 맞지 않으면 $work를 호출하지 않는다.
 */
function collect_perform(string $presented, string $expected, callable $work): bool
{
    if (!collect_token_matches($presented, $expected)) {
        return false;
    }
    $work();
    return true;
}

/**
 * @param list<array{host: string, port: int}> $servers
 * @param callable(string, int): ?DateTimeImmutable $latestAt
 * @return list<array{host: string, port: int}>
 */
function collect_due_servers(array $servers, DateTimeImmutable $now, callable $latestAt): array
{
    $due = [];
    foreach ($servers as $server) {
        $latest = $latestAt((string) $server['host'], (int) $server['port']);
        if (!checks_collect_is_fresh($latest, $now)) {
            $due[] = $server;
        }
    }
    return $due;
}

/**
 * 서버가 둘 이상이면 조회를 시작하기 전에 응답을 닫는다.
 * EventBridge API destination 대기가 약 5초라, 조회를 쌓으면 기록은 남아도 호출은 실패로 보인다.
 * 서버가 하나일 때는 첫 조회가 실패한 뒤에만 응답을 닫고 나머지를 다시 조회한다.
 *
 * @param list<array{host: string, port: int, name?: string}> $servers
 * @param callable(string, int): ?DateTimeImmutable $latestAt
 * @param callable(string, int): array{online: bool, ping: ?int, data: ?array, error: ?string} $probeOnce
 * @param callable(array<string, mixed>): void $respond
 * @param callable(string, int, array{online: bool, ping: ?int, data: ?array, error: ?string}): void $insert
 */
function collect_servers(
    array $servers,
    DateTimeImmutable $now,
    callable $latestAt,
    callable $probeOnce,
    callable $respond,
    callable $insert
): ?string {
    if ($servers === []) {
        return 'no servers';
    }

    $due = collect_due_servers($servers, $now, $latestAt);
    if ($due === []) {
        $respond(['skipped' => true]);
        return null;
    }

    if (count($servers) === 1) {
        $server = $due[0];
        $responded = false;
        $probe = probe_confirm_deferred(
            static function () use ($probeOnce, $server): array {
                return $probeOnce((string) $server['host'], (int) $server['port']);
            },
            PROBE_CONFIRM_ATTEMPTS,
            static function () use (&$responded, $respond): void {
                $responded = true;
                $respond(['confirming' => true]);
            }
        );
        $insert((string) $server['host'], (int) $server['port'], $probe);
        if (!$responded) {
            $respond([
                'skipped' => false,
                'online' => (bool) $probe['online'],
            ]);
        }
        return null;
    }

    $respond(['confirming' => true]);
    foreach ($due as $server) {
        $probe = probe_confirm(
            static function () use ($probeOnce, $server): array {
                return $probeOnce((string) $server['host'], (int) $server['port']);
            },
            PROBE_CONFIRM_ATTEMPTS
        );
        $insert((string) $server['host'], (int) $server['port'], $probe);
    }
    return null;
}

function collect_header_value(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$serverKey]) && is_string($_SERVER[$serverKey]) && $_SERVER[$serverKey] !== '') {
        return $_SERVER[$serverKey];
    }
    $redirectKey = 'REDIRECT_' . $serverKey;
    if (isset($_SERVER[$redirectKey]) && is_string($_SERVER[$redirectKey]) && $_SERVER[$redirectKey] !== '') {
        return $_SERVER[$redirectKey];
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $headerName => $value) {
            if (strcasecmp((string) $headerName, $name) === 0 && is_string($value) && $value !== '') {
                return $value;
            }
        }
    }
    return '';
}

function collect_presented_token(): string
{
    $authorization = collect_header_value('Authorization');
    if (preg_match('/^Bearer\s+(\S+)\s*$/i', $authorization, $matches) === 1) {
        return $matches[1];
    }

    $query = $_GET['token'] ?? '';
    if (is_string($query) && $query !== '') {
        return $query;
    }

    return '';
}
