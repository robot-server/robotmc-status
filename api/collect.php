<?php
declare(strict_types=1);

ini_set('display_errors', '0');

require_once dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/history_rules.php';
require_once dirname(__DIR__) . '/lib/store.php';
require_once dirname(__DIR__) . '/lib/probe.php';
require_once dirname(__DIR__) . '/lib/collect.php';

function collect_deny(): void
{
    http_response_code(404);
    exit;
}

function collect_fail(string $error): void
{
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

/** @param array<string, mixed> $payload */
function collect_finish_request(array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        $json = '{}';
    }

    ignore_user_abort(true);
    @ini_set('zlib.output_compression', '0');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Connection: close');
    header('Content-Length: ' . strlen($json));
    echo $json;
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

/** @param array<string, mixed> $data */
function collect_player_count(array $data, string $key): ?int
{
    if (!isset($data['players']) || !is_array($data['players'])) {
        return null;
    }
    if (!isset($data['players'][$key]) || !is_numeric($data['players'][$key])) {
        return null;
    }
    return max(0, (int) $data['players'][$key]);
}

$presented = collect_presented_token();
$configPath = dirname(__DIR__) . '/config.ini';
$config = config_load($configPath);
if ($config === false) {
    collect_deny();
}

$expected = $config['collect_token'] ?? '';
if (!is_string($expected)) {
    $expected = '';
}

$body = null;
$failure = null;
$locked = false;
$responded = false;
$pdo = null;
$performed = collect_perform($presented, $expected, static function () use (
    $config,
    &$body,
    &$failure,
    &$locked,
    &$responded,
    &$pdo
): void {
    try {
        $pdo = checks_pdo($config);
        checks_ensure_schema($pdo);
        if (!checks_collect_lock($pdo)) {
            $failure = 'collect busy';
            return;
        }
        $locked = true;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        checks_prune($pdo, $now);
        $servers = checks_servers($pdo);
        $timeout = (float) ($config['timeout'] ?? 3);
        $failure = collect_servers(
            $servers,
            $now,
            static function (string $host, int $port) use ($pdo, $servers): ?DateTimeImmutable {
                foreach ($servers as $server) {
                    if ((string) $server['host'] === $host && (int) $server['port'] === $port) {
                        return checks_latest_at($pdo, (int) $server['id']);
                    }
                }
                return null;
            },
            static function (string $host, int $port) use ($timeout): array {
                return probe_server($host, $port, $timeout);
            },
            static function (array $payload) use (&$responded, &$body): void {
                if (($payload['confirming'] ?? false) === true) {
                    $responded = true;
                    collect_finish_request($payload);
                    return;
                }
                $body = $payload;
            },
            static function (string $host, int $port, array $probe) use ($pdo, $now, $servers): void {
                $serverId = 0;
                foreach ($servers as $server) {
                    if ((string) $server['host'] === $host && (int) $server['port'] === $port) {
                        $serverId = (int) $server['id'];
                        break;
                    }
                }
                $data = is_array($probe['data'] ?? null) ? $probe['data'] : [];
                $ping = $probe['ping'] ?? null;
                checks_insert(
                    $pdo,
                    $serverId,
                    $now,
                    (bool) ($probe['online'] ?? false),
                    is_int($ping) ? $ping : null,
                    collect_player_count($data, 'online'),
                    collect_player_count($data, 'max')
                );
                $favicon = servers_favicon_value($data['favicon'] ?? null);
                if ($favicon !== null && $serverId > 0) {
                    checks_save_favicon($pdo, $serverId, $favicon);
                }
            }
        );
    } catch (Throwable $e) {
        error_log($e->getMessage());
        if (!$responded) {
            $failure = 'database unavailable';
        }
    } finally {
        if ($locked && $pdo instanceof PDO) {
            try {
                checks_collect_unlock($pdo);
            } catch (Throwable $e) {
                error_log($e->getMessage());
            }
        }
    }
});

if (!$performed) {
    collect_deny();
}

if ($responded) {
    return;
}

if ($failure !== null) {
    collect_fail($failure);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($body, JSON_UNESCAPED_UNICODE);
