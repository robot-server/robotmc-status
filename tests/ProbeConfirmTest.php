<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/probe.php';

final class ProbeConfirmTest extends TestCase
{
    public function testOnlineOnFirstAttemptDoesNotRetry(): void
    {
        $calls = 0;
        $result = probe_confirm(static function () use (&$calls): array {
            $calls++;
            return ['online' => true, 'ping' => 12, 'data' => ['ok' => true], 'error' => null];
        }, PROBE_CONFIRM_ATTEMPTS);

        self::assertSame(1, $calls);
        self::assertTrue($result['online']);
        self::assertSame(12, $result['ping']);
    }

    public function testLaterOnlineAttemptIsKept(): void
    {
        $calls = 0;
        $result = probe_confirm(static function () use (&$calls): array {
            $calls++;
            if ($calls < 3) {
                return ['online' => false, 'ping' => null, 'data' => null, 'error' => 'timeout'];
            }
            return ['online' => true, 'ping' => 40, 'data' => null, 'error' => null];
        }, PROBE_CONFIRM_ATTEMPTS);

        self::assertSame(3, $calls);
        self::assertTrue($result['online']);
        self::assertSame(40, $result['ping']);
    }

    public function testEveryAttemptOfflineReturnsTheLastFailure(): void
    {
        $calls = 0;
        $result = probe_confirm(static function () use (&$calls): array {
            $calls++;
            return ['online' => false, 'ping' => null, 'data' => null, 'error' => 'fail-' . $calls];
        }, PROBE_CONFIRM_ATTEMPTS);

        self::assertSame(PROBE_CONFIRM_ATTEMPTS, $calls);
        self::assertFalse($result['online']);
        self::assertSame('fail-' . PROBE_CONFIRM_ATTEMPTS, $result['error']);
    }
}
