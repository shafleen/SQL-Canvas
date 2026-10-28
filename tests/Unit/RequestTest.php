<?php

declare(strict_types=1);

namespace SqlCanvas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SqlCanvas\Http\Request;

final class RequestTest extends TestCase
{
    public function testRequestContainsMethodAndDecodedPath(): void
    {
        $originalServer = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'get';
        $_SERVER['REQUEST_URI'] = '/api/health?details=0';

        try {
            $request = Request::fromGlobals();
            self::assertSame('GET', $request->method);
            self::assertSame('/api/health', $request->path);
        } finally {
            $_SERVER = $originalServer;
        }
    }
}

