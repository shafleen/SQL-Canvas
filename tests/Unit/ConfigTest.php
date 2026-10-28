<?php

declare(strict_types=1);

namespace SqlCanvas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SqlCanvas\Support\Config;

final class ConfigTest extends TestCase
{
    public function testNestedValuesAndDefaultsAreReadSafely(): void
    {
        $config = new Config(['security' => ['read_only' => true]]);

        self::assertTrue($config->get('security.read_only'));
        self::assertSame('fallback', $config->get('security.missing', 'fallback'));
    }

    public function testCommittedDefaultsContainNoCredentials(): void
    {
        $config = Config::fromProjectRoot(dirname(__DIR__, 2));
        $encoded = strtolower((string) json_encode($config->all()));

        self::assertStringNotContainsString('password', $encoded);
        self::assertStringNotContainsString('username', $encoded);
        self::assertTrue($config->get('security.read_only'));
    }
}

