<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HistoryRulesTest extends TestCase
{
    public function testSeoulDayBoundarySplitsOneUtcEveningAcrossTwoSeoulDates(): void
    {
        $now = new DateTimeImmutable('2026-10-04 16:00:00', new DateTimeZone('UTC'));
        $beforeMidnight = new DateTimeImmutable('2026-10-04 14:59:59', new DateTimeZone('UTC'));
        $atMidnight = new DateTimeImmutable('2026-10-04 15:00:00', new DateTimeZone('UTC'));

        self::assertSame('2026-10-04', history_seoul_day($beforeMidnight));
        self::assertSame('2026-10-05', history_seoul_day($atMidnight));

        $days = history_bucket_days([
            ['checked_at' => '2026-10-04 14:59:59', 'online' => true, 'ping_ms' => 10],
            ['checked_at' => '2026-10-04 15:00:00', 'online' => false, 'ping_ms' => 30],
        ], $now);
        $byDate = $this->daysByDate($days);

        self::assertCount(90, $days);
        self::assertSame('2026-10-05', $days[89]['date']);
        self::assertSame(1, $byDate['2026-10-04']['samples']);
        self::assertSame('green', $byDate['2026-10-04']['color']);
        self::assertSame(1, $byDate['2026-10-05']['samples']);
        self::assertSame(0, $byDate['2026-10-05']['online']);
        self::assertSame('red', $byDate['2026-10-05']['color']);
    }

    public function testGapLongerThanTenMinutesIsTwoIncidentsAndIsNotDowntime(): void
    {
        $split = history_incidents([
            ['checked_at' => '2026-10-01 00:00:00', 'online' => '0'],
            ['checked_at' => '2026-10-01 00:20:00', 'online' => 0],
        ]);
        self::assertCount(2, $split);
        self::assertSame('2026-10-01 00:20:00', $split[0]['start']);
        self::assertSame('2026-10-01 00:20:00', $split[0]['end']);
        self::assertSame(0, $split[0]['duration_seconds']);
        self::assertSame('2026-10-01 00:00:00', $split[1]['start']);
        self::assertSame('2026-10-01 00:00:00', $split[1]['end']);
        self::assertSame(0, $split[1]['duration_seconds']);

        $joined = history_incidents([
            ['checked_at' => '2026-10-01 03:00:00', 'online' => false],
            ['checked_at' => '2026-10-01 03:10:00', 'online' => false],
        ]);
        self::assertCount(1, $joined);
        self::assertSame('2026-10-01 03:00:00', $joined[0]['start']);
        self::assertSame('2026-10-01 03:10:00', $joined[0]['end']);
        self::assertSame(600, $joined[0]['duration_seconds']);

        $brokenByOnline = history_incidents([
            ['checked_at' => '2026-10-01 05:00:00', 'online' => false],
            ['checked_at' => '2026-10-01 05:01:00', 'online' => true],
            ['checked_at' => '2026-10-01 05:02:00', 'online' => false],
        ]);
        self::assertCount(2, $brokenByOnline);
        self::assertSame(0, $brokenByOnline[0]['duration_seconds']);
        self::assertSame(0, $brokenByOnline[1]['duration_seconds']);

        $now = new DateTimeImmutable('2026-10-04 12:00:00', new DateTimeZone('UTC'));
        $summary = history_summarize([
            ['checked_at' => '2026-10-01 00:00:00', 'online' => false, 'ping_ms' => null],
        ], $now);
        $byDate = $this->daysByDate($summary['days']);
        self::assertSame('red', $byDate['2026-10-01']['color']);
        self::assertSame('gray', $byDate['2026-10-02']['color']);
        self::assertSame(0, $byDate['2026-10-02']['samples']);
        self::assertCount(1, $summary['incidents']);
        self::assertSame('2026-10-01 00:00:00', $summary['incidents'][0]['end']);
        self::assertSame(0, $summary['incidents'][0]['duration_seconds']);
    }

    public function testPartialDayAtNinetyPercentIsYellowAndUnderNinetyIsRed(): void
    {
        self::assertSame('yellow', history_color(10, 9));
        self::assertSame('red', history_color(10, 8));
        self::assertEqualsWithDelta(90.0, history_uptime_percent(9, 10), 0.000001);
        self::assertEqualsWithDelta(80.0, history_uptime_percent(8, 10), 0.000001);

        $now = new DateTimeImmutable('2026-10-04 12:00:00', new DateTimeZone('UTC'));
        $checks = [];
        for ($i = 0; $i < 9; $i++) {
            $checks[] = [
                'checked_at' => sprintf('2026-10-03 01:%02d:00', $i),
                'online' => true,
                'ping_ms' => 10,
            ];
        }
        $checks[] = ['checked_at' => '2026-10-03 01:09:00', 'online' => false, 'ping_ms' => 10];
        for ($i = 0; $i < 8; $i++) {
            $checks[] = [
                'checked_at' => sprintf('2026-10-04 01:%02d:00', $i),
                'online' => true,
                'ping_ms' => 10,
            ];
        }
        $checks[] = ['checked_at' => '2026-10-04 01:08:00', 'online' => false, 'ping_ms' => 10];
        $checks[] = ['checked_at' => '2026-10-04 01:09:00', 'online' => false, 'ping_ms' => 10];

        $byDate = $this->daysByDate(history_bucket_days($checks, $now));
        self::assertSame(10, $byDate['2026-10-03']['samples']);
        self::assertSame(9, $byDate['2026-10-03']['online']);
        self::assertGreaterThanOrEqual(90.0, $byDate['2026-10-03']['uptime']);
        self::assertSame('yellow', $byDate['2026-10-03']['color']);
        self::assertSame(10, $byDate['2026-10-04']['samples']);
        self::assertLessThan(90.0, $byDate['2026-10-04']['uptime']);
        self::assertSame('red', $byDate['2026-10-04']['color']);
    }

    public function testAllOnlineDayIsGreen(): void
    {
        self::assertSame('green', history_color(4, 4));
        $now = new DateTimeImmutable('2026-10-04 12:00:00', new DateTimeZone('UTC'));
        $days = history_bucket_days([
            ['checked_at' => '2026-10-04 00:00:00', 'online' => true, 'ping_ms' => 8],
            ['checked_at' => '2026-10-04 00:05:00', 'online' => 1, 'ping_ms' => 12],
        ], $now);
        $byDate = $this->daysByDate($days);
        self::assertSame('green', $byDate['2026-10-04']['color']);
        self::assertEqualsWithDelta(100.0, $byDate['2026-10-04']['uptime'], 0.000001);
    }

    public function testEmptyWindowIsNinetyGrayCellsWithoutUptimeOrIncidents(): void
    {
        $summary = history_summarize(
            [],
            new DateTimeImmutable('2026-10-04 00:00:00', new DateTimeZone('UTC'))
        );

        self::assertCount(90, $summary['days']);
        self::assertTrue($summary['empty']);
        self::assertNull($summary['uptime']);
        self::assertNull($summary['last_checked_at']);
        self::assertSame([], $summary['incidents']);
        foreach ($summary['days'] as $day) {
            self::assertSame(0, $day['samples']);
            self::assertNull($day['uptime']);
            self::assertNull($day['avg_ping']);
            self::assertSame('gray', $day['color']);
            self::assertSame('gray', history_color(0, 0));
        }
    }

    public function testUptimeDenominatorIgnoresDaysWithoutSamples(): void
    {
        $now = new DateTimeImmutable('2026-10-04 12:00:00', new DateTimeZone('UTC'));
        $summary = history_summarize([
            ['checked_at' => '2026-10-04 01:00:00', 'online' => true, 'ping_ms' => 12],
        ], $now);

        self::assertFalse($summary['empty']);
        self::assertEqualsWithDelta(100.0, $summary['uptime'], 0.000001);
        self::assertSame(1, array_sum(array_column($summary['days'], 'samples')));
        self::assertSame(89, count(array_filter(
            $summary['days'],
            static fn (array $day): bool => $day['samples'] === 0
        )));
    }

    public function testAveragePingIgnoresNulls(): void
    {
        self::assertNull(history_average_ping([null, null]));
        self::assertEqualsWithDelta(20.0, history_average_ping([10, null, 30]), 0.000001);

        $now = new DateTimeImmutable('2026-10-04 12:00:00', new DateTimeZone('UTC'));
        $days = history_bucket_days([
            ['checked_at' => '2026-10-04 01:00:00', 'online' => true, 'ping_ms' => 10],
            ['checked_at' => '2026-10-04 01:05:00', 'online' => true, 'ping_ms' => null],
            ['checked_at' => '2026-10-04 01:10:00', 'online' => true, 'ping_ms' => 30],
        ], $now);
        $byDate = $this->daysByDate($days);
        self::assertEqualsWithDelta(20.0, $byDate['2026-10-04']['avg_ping'], 0.000001);
        self::assertSame(3, $byDate['2026-10-04']['samples']);
    }

    /**
     * @param list<array<string, mixed>> $days
     * @return array<string, array<string, mixed>>
     */
    private function daysByDate(array $days): array
    {
        $byDate = [];
        foreach ($days as $day) {
            $byDate[$day['date']] = $day;
        }
        return $byDate;
    }
}
