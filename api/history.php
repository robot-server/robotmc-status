<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/history_rules.php';
require_once dirname(__DIR__) . '/lib/store.php';

$configPath = dirname(__DIR__) . '/config.ini';
$config = config_load($configPath);
if ($config === false) {
    http_response_code(500);
    echo json_encode(['error' => 'history unavailable'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = checks_pdo($config);
    checks_ensure_schema($pdo);
    $servers = checks_servers($pdo);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $rows = checks_since($pdo, history_window_start_utc($now));
    echo json_encode([
        'servers' => history_summarize_servers($rows, $servers, $now),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'history unavailable'], JSON_UNESCAPED_UNICODE);
}
