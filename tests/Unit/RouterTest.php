<?php

declare(strict_types=1);

namespace SqlCanvas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SqlCanvas\Http\Request;
use SqlCanvas\Http\Response;
use SqlCanvas\Http\Router;

final class RouterTest extends TestCase
{
    public function testEncodedIdentifierSegmentsAreDecodedAfterRouting(): void
    {
        $router = new Router();
        $router->get('/api/databases/{database}/objects', static fn (Request $request, array $params): Response => Response::json($params));

        $response = $router->dispatch(new Request('GET', '/api/databases/name%2Fwith%20space/objects'));
        $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('name/with space', $payload['database']);
    }
}

