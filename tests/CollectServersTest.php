<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/collect.php';

final class CollectServersTest extends TestCase
{
    public function testWrongTokenDoesNotProbe(): void
    {
        $now = new DateTimeImmutable('2026-10-04 12:00:00', new DateTimeZone('UTC'));
        $servers = [['host' => 'a.example', 'port' => 25565, 'name' => 'A']];
        foreach ([['wrong', 'secret'], ['', 'secret'], ['secret', '']] as [$presented, $expected]) {
            $calls = 0;
            $ran = collect_perform($presented, $expected, static function () use (&$calls, $servers, $now): void {
                collect_servers(
                    $servers,
                    $now,
                    static function (): ?DateTimeImmutable {
                        return null;
                    },
                    static function () use (&$calls): array {
                        $calls++;
                        return ['online' => true, 'ping' => 1, 'data' => null, 'error' => null];
                    },
                    static function (array $payload): void {
                    },
                    static function (string $host, int $port, array $probe): void {
                    }
                );
            });
            self::assertFalse($ran);
            self::assertSame(0, $calls);
        }
    }

    public function testEmptyServerListDoesNotProbe(): void
    {
        $calls = 0;
        $failure = collect_servers(
            [],
            new DateTimeImmutable('2026-10-04 12:00:00', new DateTimeZone('UTC')),
            static function (): ?DateTimeImmutable {
                return null;
            },
            static function (string $host, int $port) use (&$calls): array {
                $calls++;
                return ['online' => true, 'ping' => 1, 'data' => null, 'error' => null];
            },
            static function (array $payload): void {
            },
            static function (string $host, int $port, array $probe): void {
            }
        );
        self::assertSame('no servers', $failure);
        self::assertSame(0, $calls);
    }

    public function testManyFreshServersSkipWithoutProbe(): void
    {
        $now = new DateTimeImmutable('2026-10-04 12:00:00', new DateTimeZone('UTC'));
        $events = [];
        $failure = collect_servers(
            [
                ['host' => 'a.example', 'port' => 25565, 'name' => 'A'],
                ['host' => 'b.example', 'port' => 25566, 'name' => 'B'],
            ],
            $now,
            static function (string $host, int $port) use ($now): DateTimeImmutable {
                return $now->modify('-1 minute');
            },
            static function (string $host, int $port) use (&$events): array {
                $events[] = 'probe';
                return ['online' => true, 'ping' => 1, 'data' => null, 'error' => null];
            },
            static function (array $payload) use (&$events): void {
                $events[] = $payload;
            },
            static function (string $host, int $port, array $probe) use (&$events): void {
                $events[] = 'insert';
            }
        );
        self::assertNull($failure);
        self::assertSame([['skipped' => true]], $events);
    }

    public function testOneServerFreshSkipsWithoutProbe(): void
    {
        $now = new DateTimeImmutable('2026-10-04 12:00:00', new DateTimeZone('UTC'));
        $events = [];
        $failure = $this->collect(
            [['host' => 'a.example', 'port' => 25565, 'name' => 'A']],
            $now,
            static function (string $host, int $port) use ($now): DateTimeImmutable {
                return $now->modify('-1 minute');
            },
            static function (string $host, int $port) use (&$events): array {
                $events[] = 'probe';
                return ['online' => true, 'ping' => 1, 'data' => null, 'error' => null];
            },
            $events
        );

        self::assertNull($failure);
        self::assertSame([['skipped' => true]], $events);
    }

    public function testOneServerOnlineReportsTheInsert(): void
    {
        $now = new DateTimeImmutable('2026-10-04 12:00:00', new DateTimeZone('UTC'));
        $events = [];
        $failure = $this->collect(
            [['host' => 'a.example', 'port' => 25565, 'name' => 'A']],
            $now,
            static function (): ?DateTimeImmutable {
                return null;
            },
            static function (string $host, int $port) use (&$events): array {
                $events[] = 'probe';
                return ['online' => true, 'ping' => 12, 'data' => null, 'error' => null];
            },
            $events
        );

        self::assertNull($failure);
        self::assertSame(['probe', 'insert', ['skipped' => false, 'online' => true]], $events);
    }

    public function testOneServerFailureClosesBeforeRemainingProbes(): void
    {
        $now = new DateTimeImmutable('2026-10-04 12:00:00', new DateTimeZone('UTC'));
        $events = [];
        $inserted = null;
        $failure = collect_servers(
            [['host' => 'a.example', 'port' => 25565, 'name' => 'A']],
            $now,
            static function (): ?DateTimeImmutable {
                return null;
            },
            static function (string $host, int $port) use (&$events): array {
                $events[] = 'probe';
                $attempt = count(array_filter($events, static function ($event): bool {
                    return $event === 'probe';
                }));
                if ($attempt < PROBE_CONFIRM_ATTEMPTS) {
                    return ['online' => false, 'ping' => null, 'data' => null, 'error' => 'fail-' . $attempt];
                }
                return ['online' => true, 'ping' => 7, 'data' => null, 'error' => null];
            },
            static function (array $payload) use (&$events): void {
                $events[] = $payload;
            },
            static function (string $host, int $port, array $probe) use (&$events, &$inserted): void {
                $events[] = 'insert';
                $inserted = $probe;
            }
        );

        self::assertNull($failure);
        self::assertSame(['probe', ['confirming' => true], 'probe', 'probe', 'insert'], $events);
        self::assertTrue($inserted['online']);
    }

    public function testManyServersCloseBeforeAnyProbeAndSkipFreshAddresses(): void
    {
        $now = new DateTimeImmutable('2026-10-04 12:00:00', new DateTimeZone('UTC'));
        $events = [];
        $probes = [];
        $inserts = [];
        $failure = collect_servers(
            [
                ['host' => 'fresh.example', 'port' => 25565, 'name' => 'Fresh'],
                ['host' => 'due.example', 'port' => 25566, 'name' => 'Due'],
            ],
            $now,
            static function (string $host, int $port) use ($now): ?DateTimeImmutable {
                if ($host === 'fresh.example' && $port === 25565) {
                    return $now->modify('-1 minute');
                }
                return null;
            },
            static function (string $host, int $port) use (&$events, &$probes): array {
                $events[] = 'probe';
                $probes[] = [$host, $port];
                $attempt = 0;
                foreach ($probes as $probe) {
                    if ($probe[0] === $host && $probe[1] === $port) {
                        $attempt++;
                    }
                }
                if ($attempt < PROBE_CONFIRM_ATTEMPTS) {
                    return ['online' => false, 'ping' => null, 'data' => null, 'error' => 'fail'];
                }
                return ['online' => false, 'ping' => null, 'data' => null, 'error' => 'fail-last'];
            },
            static function (array $payload) use (&$events): void {
                $events[] = $payload;
            },
            static function (string $host, int $port, array $probe) use (&$events, &$inserts): void {
                $events[] = 'insert';
                $inserts[] = [$host, $port, $probe['online']];
            }
        );

        self::assertNull($failure);
        self::assertSame(['confirming' => true], $events[0]);
        $seen = [];
        foreach ($probes as $probe) {
            $seen[$probe[0] . ':' . (string) $probe[1]] = $probe;
        }
        self::assertSame([['due.example', 25566]], array_values($seen));
        self::assertCount(PROBE_CONFIRM_ATTEMPTS, $probes);
        self::assertSame([['due.example', 25566, false]], $inserts);
        $confirmingAt = array_search(['confirming' => true], $events, true);
        $firstProbeAt = array_search('probe', $events, true);
        self::assertIsInt($confirmingAt);
        self::assertIsInt($firstProbeAt);
        self::assertLessThan($firstProbeAt, $confirmingAt);
    }

    /**
     * @param list<array{host: string, port: int, name: string}> $servers
     * @param callable(string, int): ?DateTimeImmutable $latestAt
     * @param callable(string, int): array{online: bool, ping: ?int, data: ?array, error: ?string} $probeOnce
     * @param list<mixed> $events
     */
    private function collect(array $servers, DateTimeImmutable $now, callable $latestAt, callable $probeOnce, array &$events): ?string
    {
        return collect_servers(
            $servers,
            $now,
            $latestAt,
            $probeOnce,
            static function (array $payload) use (&$events): void {
                $events[] = $payload;
            },
            static function (string $host, int $port, array $probe) use (&$events): void {
                $events[] = 'insert';
            }
        );
    }
}
