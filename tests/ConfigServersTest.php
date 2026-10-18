<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/servers.php';

final class ConfigServersTest extends TestCase
{
    public function testPickKeepsTheRequestedServerOrTheFirst(): void
    {
        $servers = [
            ['id' => 4, 'name' => '첫째'],
            ['id' => 9, 'name' => '둘째'],
        ];
        self::assertSame($servers[0], servers_pick($servers, null));
        self::assertSame($servers[0], servers_pick($servers, 3));
        self::assertSame($servers[1], servers_pick($servers, 9));
        self::assertNull(servers_pick([], 1));
    }

    public function testFaviconValueKeepsADataImageAndDropsAnythingElse(): void
    {
        self::assertNull(servers_favicon_value(null));
        self::assertNull(servers_favicon_value('https://example.test/icon.png'));
        self::assertSame('data:image/png;base64,abc', servers_favicon_value('data:image/png;base64,abc'));
        self::assertNull(servers_favicon_value('data:image/png;base64,' . str_repeat('a', 70000)));
    }

    public function testFindServerReturnsOnlyTheRequestedAddress(): void
    {
        $servers = [
            ['id' => 1, 'host' => '2001:db8::1', 'port' => 25565, 'name' => 'v6', 'enabled' => true],
            ['id' => 2, 'host' => 'play.example', 'port' => 25566, 'name' => 'v4', 'enabled' => true],
        ];

        self::assertSame($servers[1], servers_find($servers, 'play.example', 25566));
        self::assertSame($servers[0], servers_find($servers, null, null));
        self::assertNull(servers_find($servers, 'play.example', 25565));
        self::assertNull(servers_find($servers, 'other.example', 25566));
    }

    public function testDeployIniOmitsServerAddressesAndKeepsSecrets(): void
    {
        $result = config_deploy_ini($this->deployEnv());
        self::assertTrue($result['ok']);
        self::assertSame(
            "timeout = 3\n"
            . "db_host = \"db.internal\"\n"
            . "db_port = 3306\n"
            . "db_name = \"app\"\n"
            . "db_user = \"user\"\n"
            . "db_password = \"p\${X}ass\"\n"
            . "collect_token = \"tok\"\n",
            $result['ini']
        );
        self::assertStringNotContainsString('play.example', $result['ini']);
        self::assertStringNotContainsString('server_host', $result['ini']);
        $loaded = $this->loadIni($result['ini']);
        self::assertSame('p${X}ass', $loaded['db_password']);

        $missingHost = config_deploy_ini($this->deployEnv(['SERVER_HOST' => '']));
        self::assertTrue($missingHost['ok']);
        self::assertStringNotContainsString('server_host', $missingHost['ini']);

        $missingToken = config_deploy_ini($this->deployEnv(['COLLECT_TOKEN' => '']));
        self::assertFalse($missingToken['ok']);
        self::assertSame('COLLECT_TOKEN secret is empty', $missingToken['error']);

        $missingDb = config_deploy_ini($this->deployEnv(['DB_PASSWORD' => '']));
        self::assertFalse($missingDb['ok']);
        self::assertSame('DB_PASSWORD secret is empty', $missingDb['error']);
    }

    /** @param array<string, string> $override */
    private function deployEnv(array $override = []): array
    {
        return array_merge([
            'SERVER_HOST' => 'play.example',
            'SERVER_PORT' => '',
            'SERVER_NAME' => '',
            'SERVER_TIMEOUT' => '',
            'SERVERS' => '',
            'DB_HOST' => 'db.internal',
            'DB_PORT' => '',
            'DB_NAME' => 'app',
            'DB_USER' => 'user',
            'DB_PASSWORD' => 'p${X}ass',
            'COLLECT_TOKEN' => 'tok',
        ], $override);
    }

    /** @return array<string, string> */
    private function loadIni(string $ini): array
    {
        $dir = sys_get_temp_dir() . '/robotmc-config-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $path = $dir . '/config.ini';
        try {
            file_put_contents($path, $ini);
            $loaded = config_load($path);
            self::assertIsArray($loaded);
            return $loaded;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            rmdir($dir);
        }
    }
}
