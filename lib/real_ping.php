<?php
declare(strict_types=1);

/**
 * Minecraft Real Ping (0x01) - 하이브리드 방식용
 *
 * xPaw 라이브러리는 Status 데이터 조회용으로 그대로 사용하고,
 * ping 값만 실제 Minecraft 프로토콜의 Ping Request / Pong Response(0x01)를 사용해 측정합니다.
 *
 * 이 파일은 완전히 독립적이며, xPaw에 의존하지 않습니다.
 */

/**
 * Minecraft 서버의 실제 네트워크 RTT를 0x01 Ping 패킷으로 측정합니다.
 *
 * @param string $host 서버 호스트 (도메인 또는 IP)
 * @param int $port 포트 (기본 25565)
 * @param float $timeout 초 단위 타임아웃
 * @return int|null 성공 시 밀리초 단위 ping, 실패/타임아웃 시 null
 */
function get_minecraft_real_ping(string $host, int $port, float $timeout = 3.0): ?int
{
    // xPaw와 동일한 SRV 해석 로직 (정확한 ping 측정을 위해 필요)
    [$resolvedHost, $resolvedPort] = resolve_minecraft_srv($host, $port);

    $socket = @fsockopen($resolvedHost, $resolvedPort, $errno, $errstr, $timeout);
    if ($socket === false) {
        return null;
    }

    $seconds = (int) $timeout;
    $microseconds = (int) (($timeout - $seconds) * 1000000);
    stream_set_timeout($socket, $seconds, $microseconds);

    try {
        // 1. Handshake (next state = 1: Status)
        $handshake = encode_varint(-1);                    // protocol version (ping용 -1)
        $handshake .= encode_string($host);
        $handshake .= pack('n', $port);                    // unsigned short, big endian
        $handshake .= encode_varint(1);                    // next state = Status

        write_minecraft_packet($socket, 0x00, $handshake);

        // 2. Status Request (Notchian 서버 quirk 대응을 위해 반드시 보냄)
        write_minecraft_packet($socket, 0x00, '');

        // 3. Status Response 읽기 (데이터는 버리고 연결만 유지)
        //    일부 서버는 이 응답을 받은 후에 Ping을 기다림
        read_minecraft_packet($socket); // Status Response (무시)

        // 4. Ping Request (0x01) - 실제 지연 측정 시작
        $sentAt = (int) round(microtime(true) * 1000);
        $payload = pack('J', $sentAt);                     // unsigned 64-bit big-endian
        write_minecraft_packet($socket, 0x01, $payload);

        // 5. Pong Response (0x01) 대기 및 RTT 계산
        $t0 = microtime(true);
        [$packetId, $response] = read_minecraft_packet($socket);
        $receivedAt = microtime(true);

        if ($packetId !== 0x01) {
            return null; // 예상치 못한 패킷
        }

        // Echo된 timestamp 확인 (correlation)
        $echoed = 0;
        if (strlen($response) >= 8) {
            $unpacked = unpack('J', substr($response, 0, 8));
            $echoed = is_array($unpacked) ? ($unpacked[1] ?? 0) : 0;
        }

        // RTT 계산 (로컬 시계 기준)
        $rtt = (int) round(($receivedAt - $t0) * 1000);

        // echo된 값으로 보정 (더 정확할 수 있음)
        if ($echoed > 0) {
            $byEcho = $sentAt - $echoed;
            if ($byEcho > 0 && $byEcho < 30000) {
                $rtt = $byEcho;
            }
        }

        return max(0, $rtt);

    } catch (Throwable $e) {
        // 모든 예외는 null로 처리 (ping 측정 실패는 치명적이지 않음)
        return null;
    } finally {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
}

// ============================================================================
// 내부용 저수준 Minecraft 프로토콜 헬퍼 (이 파일에서만 사용)
// ============================================================================

function encode_varint(int $value): string
{
    $out = '';
    $value &= 0xFFFFFFFF;
    do {
        $byte = $value & 0x7F;
        $value >>= 7;
        if ($value !== 0) {
            $byte |= 0x80;
        }
        $out .= chr($byte);
    } while ($value !== 0);
    return $out;
}

function encode_string(string $str): string
{
    return encode_varint(strlen($str)) . $str;
}

function write_minecraft_packet($socket, int $packetId, string $payload): void
{
    $data = encode_varint($packetId) . $payload;
    $length = encode_varint(strlen($data));
    fwrite($socket, $length . $data);
}

function read_varint($socket): int
{
    $result = 0;
    $shift = 0;
    for ($i = 0; $i < 5; $i++) {
        $char = fread($socket, 1);
        if ($char === false || $char === '') {
            throw new RuntimeException('Socket read failed or EOF');
        }
        $byte = ord($char);
        $result |= ($byte & 0x7F) << $shift;
        $shift += 7;
        if (($byte & 0x80) === 0) {
            break;
        }
    }
    return $result;
}

function read_minecraft_packet($socket): array
{
    $length = read_varint($socket);
    if ($length <= 0) {
        throw new RuntimeException('Invalid packet length');
    }

    $data = '';
    $remaining = $length;
    while ($remaining > 0) {
        $chunk = fread($socket, $remaining);
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('Socket read failed');
        }
        $data .= $chunk;
        $remaining -= strlen($chunk);
    }

    $offset = 0;
    $packetId = read_varint_from_buffer($data, $offset);
    $payload = substr($data, $offset);

    return [$packetId, $payload];
}

function read_varint_from_buffer(string $buffer, int &$offset): int
{
    $result = 0;
    $shift = 0;
    for ($i = 0; $i < 5; $i++) {
        if (!isset($buffer[$offset])) {
            throw new RuntimeException('Unexpected end of buffer');
        }
        $byte = ord($buffer[$offset++]);
        $result |= ($byte & 0x7F) << $shift;
        $shift += 7;
        if (($byte & 0x80) === 0) {
            break;
        }
    }
    return $result;
}

/**
 * Minecraft SRV 레코드 해석 (xPaw와 동일한 로직)
 */
function resolve_minecraft_srv(string $host, int $port): array
{
    // IP 주소면 SRV 조회 불필요
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return [$host, $port];
    }

    $records = @dns_get_record('_minecraft._tcp.' . $host, DNS_SRV);
    if (empty($records) || !isset($records[0]['target'], $records[0]['port'])) {
        return [$host, $port];
    }

    return [
        $records[0]['target'],
        (int) $records[0]['port'],
    ];
}
