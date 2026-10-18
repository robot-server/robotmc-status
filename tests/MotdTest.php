<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MotdTest extends TestCase
{
    public function testShippedMotdMatchesJavaFormatting(): void
    {
        $first = $this->runChecker();
        $second = $this->runChecker();

        self::assertSame(0, $first['code'], $first['stdout'] . $first['stderr']);
        self::assertSame(0, $second['code'], $second['stdout'] . $second['stderr']);
        self::assertSame("motd ok\n", $first['stdout']);
        self::assertSame($first['stdout'], $second['stdout']);
    }

    /**
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runChecker(): array
    {
        $script = dirname(__DIR__) . '/tests/motd_check.mjs';
        $command = 'node ' . escapeshellarg($script);
        $process = proc_open(
            $command,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__)
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'code' => proc_close($process),
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }
}
