<?php

declare(strict_types=1);

namespace SqlCanvas\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SqlCanvas\Database\CapabilityDetector;
use SqlCanvas\Database\ConnectionManager;
use SqlCanvas\Database\ConnectionProfile;
use SqlCanvas\Database\DestinationPolicy;
use SqlCanvas\Database\MetadataReader;
use SqlCanvas\Support\Config;

#[Group('database')]
final class ConnectionMetadataTest extends TestCase
{
    public function testCapabilityAndLazyMetadataReadAgainstDisposableFixture(): void
    {
        if (getenv('SQL_CANVAS_TEST_DATABASE') === false) {
            self::markTestSkipped('Disposable database environment is not configured.');
        }

        $profile = $this->profile((string) getenv('SQL_CANVAS_TEST_PASSWORD'));
        $pdo = $this->manager()->connect($profile);
        $server = (new CapabilityDetector())->detect($pdo);
        $metadata = new MetadataReader();
        $database = (string) getenv('SQL_CANVAS_TEST_DATABASE');

        self::assertContains($server['vendor'], ['MySQL', 'MariaDB']);
        self::assertNotSame('', $server['version']);
        self::assertGreaterThan(0, $server['grantCount']);
        self::assertNotEmpty($server['grants']);
        self::assertFalse($server['features']['createDatabase']);
        self::assertFalse($server['features']['userAdministration']);
        self::assertContains($database, array_column($metadata->databases($pdo), 'name'));

        $objects = $metadata->schemaObjects($pdo, $database);
        self::assertContains('projects', array_column($objects['tables'], 'name'));
        self::assertContains('active_projects', array_column($objects['views'], 'name'));
        $details = $metadata->tableDetails($pdo, $database, 'projects');
        self::assertContains('project_name', array_column($details['columns'], 'name'));
        self::assertContains('PRIMARY', array_column($details['indexes'], 'name'));
        self::assertArrayHasKey('information', $details);
        self::assertArrayHasKey('foreignKeys', $details);
    }

    private function manager(): ConnectionManager
    {
        $host = getenv('SQL_CANVAS_TEST_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('SQL_CANVAS_TEST_PORT') ?: 3306);
        $config = new Config(['security' => [
            'allowed_connection_hosts' => [$host],
            'allowed_connection_ports' => [$port],
            'require_tls_for_remote' => false,
            'allow_socket_connections' => false,
        ]]);
        return new ConnectionManager(new DestinationPolicy($config));
    }

    private function profile(string $password): ConnectionProfile
    {
        return new ConnectionProfile(
            'Disposable fixture',
            getenv('SQL_CANVAS_TEST_HOST') ?: '127.0.0.1',
            (int) (getenv('SQL_CANVAS_TEST_PORT') ?: 3306),
            getenv('SQL_CANVAS_TEST_USER') ?: '',
            $password,
            getenv('SQL_CANVAS_TEST_DATABASE') ?: null,
            null,
            false,
            null,
        );
    }
}

