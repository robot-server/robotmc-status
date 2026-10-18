<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/collect.php';

final class HistoryServersTest extends TestCase
{
    public function testOneAddressDoesNotChangeAnotherAddressSummaryOrSkip(): void
    {
        $now = new DateTimeImmutable('2026-10-04 12:00:00', new DateTimeZone('UTC'));
        $servers = [
            ['id' => 1, 'host' => '2001:db8::1', 'port' => 25565, 'name' => 'A'],
            ['id' => 2, 'host' => 'beta.example', 'port' => 25566, 'name' => 'B'],
        ];
        $sampleA = [
            'checked_at' => '2026-10-04 01:00:00',
            'online' => false,
            'ping_ms' => null,
            'server_id' => 1,
        ];
        $sampleB = [
            'checked_at' => '2026-10-04 02:00:00',
            'online' => true,
            'ping_ms' => 15,
            'server_id' => 2,
        ];

        $onlyB = history_summarize_servers([$sampleB], $servers, $now);
        $both = history_summarize_servers([$sampleA, $sampleB], $servers, $now);

        self::assertSame('2001:db8::1', $both[0]['host']);
        self::assertSame(25565, $both[0]['port']);
        self::assertSame('beta.example', $both[1]['host']);
        self::assertSame(25566, $both[1]['port']);
        self::assertNotSame($onlyB[0]['uptime'], $both[0]['uptime']);
        self::assertSame($onlyB[1]['uptime'], $both[1]['uptime']);
        self::assertSame($onlyB[1]['incidents'], $both[1]['incidents']);
        self::assertCount(1, $both[0]['incidents']);
        self::assertSame([], $both[1]['incidents']);

        $due = collect_due_servers($servers, $now, static function (string $host, int $port) use ($now): ?DateTimeImmutable {
            if ($host === '2001:db8::1' && $port === 25565) {
                return $now->modify('-1 minute');
            }
            return null;
        });
        self::assertCount(1, $due);
        self::assertSame('beta.example', $due[0]['host']);
        self::assertSame(25566, $due[0]['port']);
    }

    public function testAddressLessRowBelongsToTheFirstConfiguredServer(): void
    {
        $now = new DateTimeImmutable('2026-10-04 12:00:00', new DateTimeZone('UTC'));
        $servers = [
            ['id' => 1, 'host' => 'first.example', 'port' => 25565, 'name' => '첫째'],
            ['id' => 2, 'host' => 'second.example', 'port' => 25566, 'name' => '둘째'],
        ];
        self::assertSame(1, servers_resolve_id(null, $servers));

        $summaries = history_summarize_servers([
            [
                'checked_at' => '2026-10-04 03:00:00',
                'online' => true,
                'ping_ms' => 8,
                'server_id' => null,
            ],
        ], $servers, $now);

        self::assertFalse($summaries[0]['empty']);
        self::assertTrue($summaries[1]['empty']);
        self::assertSame([], $summaries[1]['incidents']);
        self::assertSame('first.example', $summaries[0]['host']);
        self::assertSame(25565, $summaries[0]['port']);
    }

    public function testServerIdKeepsHistoryWhenTheAddressChanges(): void
    {
        $now = new DateTimeImmutable('2026-10-04 12:00:00', new DateTimeZone('UTC'));
        $servers = [
            ['id' => 1, 'host' => 'moved.example', 'port' => 25565, 'name' => 'A'],
            ['id' => 2, 'host' => 'beta.example', 'port' => 25566, 'name' => 'B'],
        ];
        $sampleA = [
            'checked_at' => '2026-10-04 01:00:00',
            'online' => false,
            'ping_ms' => null,
            'server_id' => 1,
        ];
        $sampleB = [
            'checked_at' => '2026-10-04 02:00:00',
            'online' => true,
            'ping_ms' => 15,
            'server_id' => 2,
        ];

        $onlyB = history_summarize_servers([$sampleB], $servers, $now);
        $both = history_summarize_servers([$sampleA, $sampleB], $servers, $now);
        self::assertSame('moved.example', $both[0]['host']);
        self::assertSame(1, $both[0]['id']);
        self::assertNotSame($onlyB[0]['uptime'], $both[0]['uptime']);
        self::assertSame($onlyB[1]['uptime'], $both[1]['uptime']);
        self::assertSame($onlyB[1]['incidents'], $both[1]['incidents']);
        self::assertCount(1, $both[0]['incidents']);
        self::assertSame([], $both[1]['incidents']);

        $addressless = history_summarize_servers([
            [
                'checked_at' => '2026-10-04 03:00:00',
                'online' => true,
                'ping_ms' => 4,
                'server_id' => null,
            ],
        ], $servers, $now);
        self::assertFalse($addressless[0]['empty']);
        self::assertTrue($addressless[1]['empty']);
        self::assertSame(1, servers_resolve_id(null, $servers));
    }
}
