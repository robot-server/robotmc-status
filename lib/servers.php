<?php
declare(strict_types=1);

/**
 * @param list<array{id: int, host: string, port: int, name: string, enabled: bool}> $servers
 * @return array{id: int, host: string, port: int, name: string, enabled: bool}|null
 */
function servers_find(array $servers, ?string $host, ?int $port): ?array
{
    if ($host === null && $port === null) {
        return $servers[0] ?? null;
    }
    foreach ($servers as $server) {
        if ((string) $server['host'] === $host && (int) $server['port'] === $port) {
            return $server;
        }
    }
    return null;
}

/**
 * 요청한 id가 목록에 있으면 그 서버다. 없거나 비어 있으면 첫 서버다.
 *
 * @param list<array{id: int, host?: string, port?: int, name?: string, enabled?: bool, favicon?: ?string}> $servers
 * @return array{id: int, host?: string, port?: int, name?: string, enabled?: bool, favicon?: ?string}|null
 */
function servers_pick(array $servers, ?int $id): ?array
{
    if ($servers === []) {
        return null;
    }
    if ($id !== null && $id > 0) {
        foreach ($servers as $server) {
            if ((int) $server['id'] === $id) {
                return $server;
            }
        }
    }
    return $servers[0];
}

/**
 * 수집이 저장할 파비콘이다. data:image 가 아니거나 TEXT에 안 들어가면 null이다.
 * null이면 기존 아이콘을 그대로 둔다.
 */
function servers_favicon_value(mixed $value): ?string
{
    if (!is_string($value) || !str_starts_with($value, 'data:image/')) {
        return null;
    }
    if (strlen($value) > 65000) {
        return null;
    }
    return $value;
}

/**
 * 샘플의 server_id가 목록에 있으면 그 행이다.
 * 비어 있으면 첫 서버다. 목록에 없는 id는 버린다.
 *
 * @param list<array{id?: int, host?: string, port?: int, name?: string}> $servers
 */
function servers_resolve_id(?int $serverId, array $servers): ?int
{
    if ($serverId !== null && $serverId > 0) {
        foreach ($servers as $server) {
            if (isset($server['id']) && (int) $server['id'] === $serverId) {
                return $serverId;
            }
        }
        return null;
    }
    if ($servers === [] || !isset($servers[0]['id'])) {
        return null;
    }
    return (int) $servers[0]['id'];
}
