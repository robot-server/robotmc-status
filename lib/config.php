<?php
declare(strict_types=1);

function config_quote(string $value): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

function config_unescape(string $value): string
{
    $out = '';
    $length = strlen($value);
    for ($i = 0; $i < $length; $i++) {
        if ($value[$i] === '\\' && $i + 1 < $length) {
            $next = $value[$i + 1];
            if ($next === '\\' || $next === '"') {
                $out .= $next;
                $i++;
                continue;
            }
        }
        $out .= $value[$i];
    }
    return $out;
}

/** @return array<string, string>|false */
function config_load(string $path): array|false
{
    if (!is_file($path)) {
        return false;
    }
    $parsed = parse_ini_file($path, false, INI_SCANNER_RAW);
    if ($parsed === false) {
        return false;
    }
    $config = [];
    foreach ($parsed as $key => $value) {
        if (!is_string($key) || !is_string($value)) {
            return false;
        }
        $config[$key] = config_unescape($value);
    }
    return $config;
}



/**
 * 배포가 config.ini에 쓰는 내용. 서버 주소는 넣지 않는다.
 * DB와 collect_token은 필요하다.
 *
 * @param array<string, string> $env
 * @return array{ok: true, ini: string}|array{ok: false, error: string}
 */
function config_deploy_ini(array $env): array
{
    $get = static function (string $key) use ($env): string {
        return $env[$key] ?? '';
    };
    $timeout = $get('SERVER_TIMEOUT');
    if ($timeout === '') {
        $timeout = '3';
    }

    if (!preg_match('/^\d+(\.\d+)?$/', $timeout) || (float) $timeout <= 0) {
        return ['ok' => false, 'error' => 'SERVER_TIMEOUT must be a positive number'];
    }

    $dbHost = $get('DB_HOST');
    $dbPort = $get('DB_PORT');
    $dbName = $get('DB_NAME');
    $dbUser = $get('DB_USER');
    $dbPassword = $get('DB_PASSWORD');
    $collectToken = $get('COLLECT_TOKEN');
    if ($dbPort === '') {
        $dbPort = '3306';
    }
    foreach ([
        'DB_HOST' => $dbHost,
        'DB_NAME' => $dbName,
        'DB_USER' => $dbUser,
        'DB_PASSWORD' => $dbPassword,
        'COLLECT_TOKEN' => $collectToken,
    ] as $key => $value) {
        if ($value === '') {
            return ['ok' => false, 'error' => $key . ' secret is empty'];
        }
    }
    if (!preg_match('/^\d+$/', $dbPort) || (int) $dbPort < 1 || (int) $dbPort > 65535) {
        return ['ok' => false, 'error' => 'DB_PORT must be an integer from 1 to 65535'];
    }

    $singleLine = $timeout . $dbHost . $dbName . $dbUser . $dbPassword . $collectToken . $dbPort;
    if (preg_match("/[\r\n]/", $singleLine) === 1) {
        return ['ok' => false, 'error' => 'config values must be single line'];
    }

    $lines = [];
    $lines[] = 'timeout = ' . $timeout;
    $lines[] = 'db_host = ' . config_quote($dbHost);
    $lines[] = 'db_port = ' . $dbPort;
    $lines[] = 'db_name = ' . config_quote($dbName);
    $lines[] = 'db_user = ' . config_quote($dbUser);
    $lines[] = 'db_password = ' . config_quote($dbPassword);
    $lines[] = 'collect_token = ' . config_quote($collectToken);

    return ['ok' => true, 'ini' => implode("\n", $lines) . "\n"];
}
