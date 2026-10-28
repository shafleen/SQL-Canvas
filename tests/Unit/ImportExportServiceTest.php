<?php

declare(strict_types=1);

namespace SqlCanvas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SqlCanvas\Http\ApiException;
use SqlCanvas\Services\ImportExportService;

final class ImportExportServiceTest extends TestCase
{
    public function testSqlSplitterPreservesSemicolonsInsideValuesAndComments(): void
    {
        $service = (new ReflectionClass(ImportExportService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ImportExportService::class, 'splitSql');
        $statements = $method->invoke($service, "-- heading\nINSERT INTO notes VALUES ('a;b'); /* x;y */ SELECT 1;");
        self::assertCount(2, $statements);
        self::assertStringContainsString("'a;b'", $statements[0]);
        self::assertStringEndsWith('SELECT 1', $statements[1]);
    }

    public function testSqlSplitterRejectsUnterminatedInput(): void
    {
        $service = (new ReflectionClass(ImportExportService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ImportExportService::class, 'splitSql');
        $this->expectException(ApiException::class);
        $method->invoke($service, "INSERT INTO notes VALUES ('unfinished);");
    }
}
