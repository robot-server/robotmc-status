<?php
declare(strict_types=1);

require_once __DIR__ . '/history_rules.php';
require_once __DIR__ . '/servers.php';

function checks_pdo(array $config): PDO
{
    $host = trim((string) ($config['db_host'] ?? ''));
    $name = trim((string) ($config['db_name'] ?? ''));
    $user = trim((string) ($config['db_user'] ?? ''));
    $password = (string) ($config['db_password'] ?? '');
    $port = (int) ($config['db_port'] ?? 3306);
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('database is not configured');
    }
    if ($host === '' || $name === '' || $user === '' || preg_match('/[;\r\n]/', $host . $name) === 1) {
        throw new RuntimeException('database is not configured');
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
        $options[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = 3;
    }
    $pdo = new PDO($dsn, $user, $password, $options);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function checks_table_missing(PDOException $e): bool
{
    $info = $e->errorInfo;
    return (isset($info[1]) && (int) $info[1] === 1146)
        || (string) $e->getCode() === '42S02';
}

function checks_collect_lock(PDO $pdo): bool
{
    $statement = $pdo->query("SELECT GET_LOCK('robotmc_status_collect', 15)");
    $got = $statement === false ? false : $statement->fetchColumn();
    return (string) $got === '1';
}

function checks_collect_unlock(PDO $pdo): void
{
    $pdo->query("SELECT RELEASE_LOCK('robotmc_status_collect')");
}

function checks_collect_is_fresh(?DateTimeImmutable $latest, DateTimeImmutable $now): bool
{
    if ($latest === null) {
        return false;
    }
    return ($now->getTimestamp() - $latest->getTimestamp()) < 4 * 60;
}

function checks_read_schema(string $path): string
{
    $sql = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException('schema file is missing');
    }
    return $sql;
}

function checks_table_ready(PDO $pdo, string $table): bool
{
    if ($table !== 'robotmc_status' && $table !== 'robotmc_server') {
        throw new InvalidArgumentException('unknown table');
    }
    try {
        $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
        return true;
    } catch (PDOException $e) {
        if (!checks_table_missing($e)) {
            throw $e;
        }
    }
    return false;
}

function checks_ensure_schema(PDO $pdo): void
{
    if (!checks_table_ready($pdo, 'robotmc_server')) {
        $pdo->exec(checks_read_schema(__DIR__ . '/robotmc_server.sql'));
    }
    if (!checks_table_ready($pdo, 'robotmc_status')) {
        $pdo->exec(checks_read_schema(__DIR__ . '/robotmc_status.sql'));
    }
}

/** @return list<array{id: int, host: string, port: int, name: string, enabled: bool, favicon: ?string}> */
function checks_servers(PDO $pdo): array
{
    $sql = 'SELECT id, host, port, name, enabled, favicon FROM robotmc_server WHERE enabled = 1 ORDER BY id ASC';
    $statement = $pdo->query($sql);
    $servers = [];
    if ($statement === false) {
        return [];
    }
    while ($row = $statement->fetch()) {
        $servers[] = [
            'id' => (int) $row['id'],
            'host' => (string) $row['host'],
            'port' => (int) $row['port'],
            'name' => (string) $row['name'],
            'enabled' => (int) $row['enabled'] === 1,
            'favicon' => servers_favicon_value(is_string($row['favicon'] ?? null) ? $row['favicon'] : null),
        ];
    }
    return $servers;
}

function checks_save_favicon(PDO $pdo, int $serverId, string $favicon): void
{
    $statement = $pdo->prepare('UPDATE robotmc_server SET favicon = :favicon WHERE id = :id');
    $statement->bindValue(':favicon', $favicon);
    $statement->bindValue(':id', $serverId, PDO::PARAM_INT);
    $statement->execute();
}

function checks_latest_at(PDO $pdo, int $serverId): ?DateTimeImmutable
{
    $statement = $pdo->prepare('SELECT MAX(checked_at) FROM robotmc_status WHERE server_id = :server_id');
    $statement->bindValue(':server_id', $serverId, PDO::PARAM_INT);
    $statement->execute();
    $value = $statement->fetchColumn();
    if ($value === false || $value === null || $value === '') {
        return null;
    }
    return history_parse_utc((string) $value);
}

function checks_insert(
    PDO $pdo,
    int $serverId,
    DateTimeImmutable $checkedAt,
    bool $online,
    ?int $pingMs,
    ?int $playersOnline,
    ?int $playersMax
): void {
    $statement = $pdo->prepare(
        'INSERT INTO robotmc_status
            (server_id, checked_at, online, ping_ms, players_online, players_max)
         VALUES
            (:server_id, :checked_at, :online, :ping_ms, :players_online, :players_max)'
    );
    $statement->bindValue(':server_id', $serverId, PDO::PARAM_INT);
    $statement->bindValue(
        ':checked_at',
        $checkedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
    );
    $statement->bindValue(':online', $online ? 1 : 0, PDO::PARAM_INT);
    $statement->bindValue(':ping_ms', $pingMs, $pingMs === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $statement->bindValue(
        ':players_online',
        $playersOnline,
        $playersOnline === null ? PDO::PARAM_NULL : PDO::PARAM_INT
    );
    $statement->bindValue(
        ':players_max',
        $playersMax,
        $playersMax === null ? PDO::PARAM_NULL : PDO::PARAM_INT
    );
    $statement->execute();
}

function checks_prune(PDO $pdo, DateTimeImmutable $now): void
{
    $cutoff = $now->setTimezone(new DateTimeZone('UTC'))->modify('-90 days')->format('Y-m-d H:i:s');
    $statement = $pdo->prepare('DELETE FROM robotmc_status WHERE checked_at < :cutoff');
    $statement->execute(['cutoff' => $cutoff]);
}

/** @return list<array{checked_at: string, online: bool, ping_ms: ?int, players_online: ?int, players_max: ?int, server_id: int}> */
function checks_since(PDO $pdo, DateTimeImmutable $fromUtc): array
{
    $statement = $pdo->prepare(
        'SELECT checked_at, online, ping_ms, players_online, players_max, server_id
         FROM robotmc_status
         WHERE checked_at >= :from
         ORDER BY checked_at ASC'
    );
    $statement->execute([
        'from' => $fromUtc->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
    ]);

    $rows = [];
    while ($row = $statement->fetch()) {
        $rows[] = [
            'checked_at' => (string) $row['checked_at'],
            'online' => history_is_online($row['online']),
            'ping_ms' => $row['ping_ms'] === null ? null : (int) $row['ping_ms'],
            'players_online' => $row['players_online'] === null ? null : (int) $row['players_online'],
            'players_max' => $row['players_max'] === null ? null : (int) $row['players_max'],
            'server_id' => (int) $row['server_id'],
        ];
    }
    return $rows;
}
