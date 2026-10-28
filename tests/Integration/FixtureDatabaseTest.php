<?php

declare(strict_types=1);

namespace SqlCanvas\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('database')]
final class FixtureDatabaseTest extends TestCase
{
    public function testDisposableFixtureHasExpectedFictionalData(): void
    {
        if (getenv('SQL_CANVAS_TEST_DATABASE') === false) {
            self::markTestSkipped('Disposable database environment is not configured.');
        }

        $pdo = new PDO(sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('SQL_CANVAS_TEST_HOST') ?: '127.0.0.1',
            getenv('SQL_CANVAS_TEST_PORT') ?: '3306',
            getenv('SQL_CANVAS_TEST_DATABASE'),
        ), getenv('SQL_CANVAS_TEST_USER') ?: '', getenv('SQL_CANVAS_TEST_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM contributors')->fetchColumn());
        self::assertSame('Aurora Archive', $pdo->query("SELECT project_name FROM projects WHERE code = 'AURORA'")->fetchColumn());
        self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM project_assignments')->fetchColumn());
    }
}

