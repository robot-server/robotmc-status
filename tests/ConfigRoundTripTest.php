<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/config.php';

final class ConfigRoundTripTest extends TestCase
{
    public function testDeployQuotingSurvivesRawRead(): void
    {
        $cases = [
            'plain',
            'p${REVIEW_UNSET_VAR}ass',
            'p\\ass',
            'p"ass',
            'p\\a"ss',
            'a#b;c',
            'true',
        ];
        $dir = sys_get_temp_dir() . '/robotmc-config-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            foreach ($cases as $value) {
                $path = $dir . '/config.ini';
                file_put_contents($path, 'collect_token = ' . config_quote($value) . "\n");
                $loaded = config_load($path);
                self::assertIsArray($loaded);
                self::assertSame($value, $loaded['collect_token']);
            }
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
