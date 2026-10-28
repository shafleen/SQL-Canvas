<?php

declare(strict_types=1);

namespace SqlCanvas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SqlCanvas\Support\AuditLogger;

final class AuditLoggerTest extends TestCase
{
    public function testAuditRecordContainsOutcomeButNoRequestBody(): void
    {
        $directory = sys_get_temp_dir() . '/sql-canvas-audit-' . bin2hex(random_bytes(5));
        (new AuditLogger($directory))->write('/api/schema/apply', 422, ['actor' => 'tester@localhost', 'client' => '127.0.0.1']);
        $files = glob($directory . '/*.log') ?: [];
        self::assertCount(1, $files);
        $record = json_decode((string) file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('rejected', $record['outcome']);
        self::assertSame('/api/schema/apply', $record['action']);
        self::assertArrayNotHasKey('body', $record);
        unlink($files[0]); rmdir($directory);
    }
}
