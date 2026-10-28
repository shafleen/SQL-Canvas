<?php

declare(strict_types=1);

namespace SqlCanvas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SqlCanvas\Database\SqlIdentifier;

final class SqlIdentifierTest extends TestCase
{
    public function testIdentifiersAreQuotedWithEscapedBackticks(): void
    {
        self::assertSame('`odd``name`', SqlIdentifier::quote('odd`name'));
        self::assertSame('`schema`.`table`', SqlIdentifier::qualified('schema', 'table'));
    }
}

