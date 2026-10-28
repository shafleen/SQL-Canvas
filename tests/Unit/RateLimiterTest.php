<?php

declare(strict_types=1);

namespace SqlCanvas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SqlCanvas\Auth\RateLimiter;
use SqlCanvas\Auth\RateLimitException;

final class RateLimiterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sql-canvas-rate-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testFailuresAreLimitedAndSuccessCanClearThem(): void
    {
        $limiter = new RateLimiter($this->directory, 2, 60);
        $limiter->recordFailure('127.0.0.1');
        $limiter->assertAllowed('127.0.0.1');
        $limiter->recordFailure('127.0.0.1');

        try {
            $limiter->assertAllowed('127.0.0.1');
            self::fail('Expected the limiter to reject the third attempt.');
        } catch (RateLimitException $exception) {
            self::assertGreaterThan(0, $exception->retryAfter);
        }

        $limiter->clear('127.0.0.1');
        $limiter->assertAllowed('127.0.0.1');
        self::addToAssertionCount(1);
    }
}

