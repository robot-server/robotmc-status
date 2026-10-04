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
