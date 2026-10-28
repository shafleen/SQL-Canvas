<?php

declare(strict_types=1);

namespace SqlCanvas\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SqlCanvas\Database\ConnectionProfile;

final class ConnectionProfileTest extends TestCase
{
    public function testPublicSummaryNeverContainsPasswordOrCaPath(): void
    {
        $profile = ConnectionProfile::fromInput([
            'label' => 'Local fixture',
            'host' => '127.0.0.1',
            'port' => 3307,
            'username' => 'fixture_user',
            'password' => 'highly-secret',
            'database' => 'sql_canvas_test_fixture',
            'tls' => ['enabled' => false],
        ]);

        $summary = $profile->publicSummary();
        self::assertSame('Local fixture', $summary['label']);
        self::assertArrayNotHasKey('password', $summary);
        self::assertStringNotContainsString('highly-secret', json_encode($summary, JSON_THROW_ON_ERROR));
    }

    public function testInvalidPortIsRejectedBeforePdo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ConnectionProfile::fromInput([
            'host' => '127.0.0.1',
            'port' => 70000,
            'username' => 'user',
            'password' => '',
        ]);
    }
}

