<?php
declare(strict_types=1);

ini_set('display_errors', '0');

require_once dirname(__DIR__) . '/lib/history_rules.php';
require_once dirname(__DIR__) . '/lib/store.php';
require_once dirname(__DIR__) . '/lib/probe.php';

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
$config = is_file($configPath) ? parse_ini_file($configPath) : false;
if ($config === false) {
    collect_deny();
}

$expected = $config['collect_token'] ?? '';
if (!is_string($expected) || $expected === '' || $presented === '' || !hash_equals($expected, $presented)) {
    collect_deny();
}

try {
    $pdo = checks_pdo($config);
    checks_ensure_schema($pdo);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    checks_prune($pdo, $now);
    $latest = checks_latest_at($pdo);
    if ($latest !== null && ($now->getTimestamp() - $latest->getTimestamp()) < 4 * 60) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['skipped' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $host = trim((string) ($config['server_host'] ?? ''));
    if ($host === '') {
        collect_fail('server_host is empty');
    }
    $port = (int) ($config['server_port'] ?? 25565);
    $timeout = (float) ($config['timeout'] ?? 3);
    $probe = probe_server($host, $port, $timeout);
    $data = is_array($probe['data']) ? $probe['data'] : [];
    $ping = $probe['ping'];
    checks_insert(
        $pdo,
        $now,
        (bool) $probe['online'],
        is_int($ping) ? $ping : null,
        collect_player_count($data, 'online'),
        collect_player_count($data, 'max')
    );
} catch (Throwable $e) {
    error_log($e->getMessage());
    collect_fail('database unavailable');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'skipped' => false,
    'online' => (bool) $probe['online'],
], JSON_UNESCAPED_UNICODE);
