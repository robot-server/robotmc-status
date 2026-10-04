<?php
declare(strict_types=1);

/**
 * 수집 행을 한국 시간 90일 막대와 장애 구간으로 집계한다.
 * 데이터베이스와 소켓을 열지 않는다.
 */

const HISTORY_DAYS = 90;
const HISTORY_INCIDENT_GAP_SECONDS = 600;

function history_parse_utc(string $value): DateTimeImmutable
{
    return new DateTimeImmutable($value, new DateTimeZone('UTC'));
}

function history_is_online(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value === 1;
    }
    if (is_string($value)) {
        return $value === '1';
    }
    return false;
}

function history_seoul_day(DateTimeImmutable $instant): string
{
    return $instant->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d');
}

/** @return list<string> 오늘을 포함한 한국 시간 날짜. 오래된 날이 앞이다. */
function history_window_dates(DateTimeImmutable $now): array
{
    $end = $now->setTimezone(new DateTimeZone('Asia/Seoul'))->setTime(0, 0, 0);
    $day = $end->modify('-' . (HISTORY_DAYS - 1) . ' days');
    $dates = [];
    for ($i = 0; $i < HISTORY_DAYS; $i++) {
        $dates[] = $day->format('Y-m-d');
        $day = $day->modify('+1 day');
    }
    return $dates;
}

function history_window_start_utc(DateTimeImmutable $now): DateTimeImmutable
{
    $dates = history_window_dates($now);
    return (new DateTimeImmutable($dates[0] . ' 00:00:00', new DateTimeZone('Asia/Seoul')))
        ->setTimezone(new DateTimeZone('UTC'));
}

function history_uptime_percent(int $online, int $samples): ?float
{
    if ($samples <= 0) {
        return null;
    }
    return ($online / $samples) * 100;
}

function history_color(int $samples, int $online): string
{
    if ($samples <= 0) {
        return 'gray';
    }
    if ($online === $samples) {
        return 'green';
    }
    $uptime = history_uptime_percent($online, $samples);
    if ($uptime !== null && $uptime >= 90.0) {
        return 'yellow';
    }
    return 'red';
}

/** @param list<mixed> $values */
function history_average_ping(array $values): ?float
{
    $total = 0.0;
    $count = 0;
    foreach ($values as $value) {
        if ($value === null) {
            continue;
        }
        $total += (float) $value;
        $count++;
    }
    if ($count === 0) {
        return null;
    }
    return $total / $count;
}

/**
 * @param list<array<string, mixed>> $checks
 * @return list<array{date: string, samples: int, online: int, uptime: ?float, avg_ping: ?float, color: string}>
 */
function history_bucket_days(array $checks, DateTimeImmutable $now): array
{
    $groups = [];
    foreach (history_window_dates($now) as $date) {
        $groups[$date] = ['online' => 0, 'samples' => 0, 'pings' => []];
    }

    foreach ($checks as $check) {
        $at = history_parse_utc((string) $check['checked_at']);
        $day = history_seoul_day($at);
        if (!isset($groups[$day])) {
            continue;
        }
        $groups[$day]['samples']++;
        if (history_is_online($check['online'] ?? false)) {
            $groups[$day]['online']++;
        }
        $groups[$day]['pings'][] = $check['ping_ms'] ?? null;
    }

    $days = [];
    foreach ($groups as $date => $group) {
        $days[] = [
            'date' => $date,
            'samples' => $group['samples'],
            'online' => $group['online'],
            'uptime' => history_uptime_percent($group['online'], $group['samples']),
            'avg_ping' => history_average_ping($group['pings']),
            'color' => history_color($group['samples'], $group['online']),
        ];
    }
    return $days;
}

/**
 * 오프라인이 이어지고 맞닿은 샘플 간격이 10분 이하인 구간만 하나의 장애다.
 * 길이는 첫 샘플부터 마지막 샘플까지이며, 그 뒤의 빈 시간이나 현재 시각까지 늘리지 않는다.
 *
 * @param list<array<string, mixed>> $checks
 * @return list<array{start: string, end: string, duration_seconds: int}>
 */
function history_incidents(array $checks): array
{
    $items = [];
    foreach ($checks as $check) {
        $items[] = [
            'at' => history_parse_utc((string) $check['checked_at']),
            'online' => history_is_online($check['online'] ?? false),
        ];
    }
    usort($items, static function (array $a, array $b): int {
        return $a['at'] <=> $b['at'];
    });

    $runs = [];
    $current = null;
    $previousAt = null;

    foreach ($items as $item) {
        if ($item['online']) {
            if ($current !== null) {
                $runs[] = $current;
                $current = null;
            }
            $previousAt = $item['at'];
            continue;
        }

        $gap = $previousAt === null ? null : $item['at']->getTimestamp() - $previousAt->getTimestamp();
        $continues = $current !== null && $gap !== null && $gap <= HISTORY_INCIDENT_GAP_SECONDS;
        if ($continues) {
            $current['end'] = $item['at'];
        } else {
            if ($current !== null) {
                $runs[] = $current;
            }
            $current = ['start' => $item['at'], 'end' => $item['at']];
        }
        $previousAt = $item['at'];
    }
    if ($current !== null) {
        $runs[] = $current;
    }

    $incidents = [];
    foreach ($runs as $run) {
        $incidents[] = [
            'start' => $run['start']->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'end' => $run['end']->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'duration_seconds' => $run['end']->getTimestamp() - $run['start']->getTimestamp(),
        ];
    }
    usort($incidents, static function (array $a, array $b): int {
        return strcmp($b['start'], $a['start']);
    });
    return $incidents;
}

/**
 * @param list<array<string, mixed>> $checks
 * @return array{
 *   days: list<array{date: string, samples: int, online: int, uptime: ?float, avg_ping: ?float, color: string}>,
 *   uptime: ?float,
 *   last_checked_at: ?string,
 *   incidents: list<array{start: string, end: string, duration_seconds: int}>,
 *   empty: bool
 * }
 */
function history_summarize(array $checks, DateTimeImmutable $now): array
{
    $days = history_bucket_days($checks, $now);
    $window = [];
    foreach ($days as $day) {
        $window[$day['date']] = true;
    }

    $inWindow = [];
    $samples = 0;
    $online = 0;
    $last = null;
    foreach ($checks as $check) {
        $at = history_parse_utc((string) $check['checked_at']);
        if (!isset($window[history_seoul_day($at)])) {
            continue;
        }
        $inWindow[] = $check;
        $samples++;
        if (history_is_online($check['online'] ?? false)) {
            $online++;
        }
        $stamp = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        if ($last === null || $stamp > $last) {
            $last = $stamp;
        }
    }

    return [
        'days' => $days,
        'uptime' => history_uptime_percent($online, $samples),
        'last_checked_at' => $last,
        'incidents' => history_incidents($inWindow),
        'empty' => $samples === 0,
    ];
}
