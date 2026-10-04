<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once dirname(__DIR__) . '/lib/history_rules.php';
require_once dirname(__DIR__) . '/lib/store.php';

$configPath = dirname(__DIR__) . '/config.ini';
$config = is_file($configPath) ? parse_ini_file($configPath) : false;
if ($config === false) {
    http_response_code(500);
    echo json_encode(['error' => 'history unavailable'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = checks_pdo($config);
    checks_ensure_schema($pdo);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $rows = checks_since($pdo, history_window_start_utc($now));
    echo json_encode(history_summarize($rows, $now), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'history unavailable'], JSON_UNESCAPED_UNICODE);
}
