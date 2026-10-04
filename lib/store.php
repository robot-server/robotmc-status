<?php
declare(strict_types=1);

require_once __DIR__ . '/history_rules.php';

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

function checks_collect_lock(PDO $pdo): void
{
    $statement = $pdo->query("SELECT GET_LOCK('robotmc_status_collect', 15)");
    $got = $statement === false ? false : $statement->fetchColumn();
    if ((string) $got !== '1') {
        throw new RuntimeException('collect lock unavailable');
    }
}

function checks_collect_unlock(PDO $pdo): void
{
    $pdo->query("SELECT RELEASE_LOCK('robotmc_status_collect')");
}

function checks_ensure_schema(PDO $pdo): void
{
    try {
        $pdo->query('SELECT 1 FROM robotmc_status LIMIT 1');
        return;
    } catch (PDOException $e) {
        if (!checks_table_missing($e)) {
            throw $e;
        }
    }

    $sqlPath = __DIR__ . '/robotmc_status.sql';
    $sql = is_file($sqlPath) ? file_get_contents($sqlPath) : false;
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException('schema file is missing');
    }
    $pdo->exec($sql);
}

function checks_latest_at(PDO $pdo): ?DateTimeImmutable
{
    $value = $pdo->query('SELECT MAX(checked_at) FROM robotmc_status')->fetchColumn();
    if ($value === false || $value === null || $value === '') {
        return null;
    }
    return history_parse_utc((string) $value);
}

function checks_insert(
    PDO $pdo,
    DateTimeImmutable $checkedAt,
    bool $online,
    ?int $pingMs,
    ?int $playersOnline,
    ?int $playersMax
): void {
    $statement = $pdo->prepare(
        'INSERT INTO robotmc_status (checked_at, online, ping_ms, players_online, players_max)
         VALUES (:checked_at, :online, :ping_ms, :players_online, :players_max)'
    );
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

/** @return list<array{checked_at: string, online: bool, ping_ms: ?int, players_online: ?int, players_max: ?int}> */
function checks_since(PDO $pdo, DateTimeImmutable $fromUtc): array
{
    $statement = $pdo->prepare(
        'SELECT checked_at, online, ping_ms, players_online, players_max
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
        ];
    }
    return $rows;
}
