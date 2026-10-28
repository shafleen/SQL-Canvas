<?php

declare(strict_types=1);

namespace SqlCanvas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SqlCanvas\Application;
use SqlCanvas\Http\Request;
use SqlCanvas\Support\Config;

final class ApplicationTest extends TestCase
{
    private Application $application;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->application = new Application($root, Config::fromProjectRoot($root));
    }

    public function testWorkspaceBootsWithSecurityHeadersAndHonestEmptyState(): void
    {
        $response = $this->application->handle(new Request('GET', '/'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('No database connected', $response->body);
        self::assertStringContainsString('Connect a database server', $response->body);
        self::assertStringContainsString('Database workspace', $response->body);
        self::assertStringNotContainsString('milestone', strtolower($response->body));
        self::assertArrayHasKey('Content-Security-Policy', $response->headers);
    }

    public function testHealthUsesStableJsonContractWithoutDatabaseAccess(): void
    {
        $response = $this->application->handle(new Request('GET', '/api/health'));
        $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($payload['ok']);
        self::assertSame('disconnected', $payload['data']['databaseConnection']);
        self::assertFalse($payload['meta']['databaseAccessAttempted']);
    }

    public function testSessionStateNeverIncludesCredentials(): void
    {
        $response = $this->application->handle(new Request('GET', '/api/session'));
        $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($payload['ok']);
        self::assertFalse($payload['data']['connected']);
        self::assertArrayHasKey('csrfToken', $payload['data']);
        self::assertStringNotContainsString('password', strtolower($response->body));
    }

    public function testConnectRequiresCsrfBeforeDestinationOrCredentialsAreProcessed(): void
    {
        $response = $this->application->handle(new Request(
            'POST',
            '/api/session/connect',
            ['content-type' => 'application/json'],
            json_encode(['host' => '127.0.0.1'], JSON_THROW_ON_ERROR),
        ));
        $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->status);
        self::assertSame('csrf_failed', $payload['error']['code']);
    }

    public function testForbiddenDestinationIsRejectedWithoutEchoingCredentials(): void
    {
        $sessionResponse = $this->application->handle(new Request('GET', '/api/session'));
        $sessionPayload = json_decode($sessionResponse->body, true, flags: JSON_THROW_ON_ERROR);
        $secret = 'unit-test-secret-never-return';
        $response = $this->application->handle(new Request(
            'POST',
            '/api/session/connect',
            ['x-csrf-token' => $sessionPayload['data']['csrfToken']],
            json_encode([
                'host' => 'not-allowed.example.test',
                'port' => 3306,
                'username' => 'tester',
                'password' => $secret,
                'tls' => ['enabled' => false],
            ], JSON_THROW_ON_ERROR),
            '127.0.0.1',
        ));
        $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->status);
        self::assertSame('destination_denied', $payload['error']['code']);
        self::assertStringNotContainsString($secret, $response->body);
    }

    public function testUnknownRouteReturnsNormalizedError(): void
    {
        $response = $this->application->handle(new Request('GET', '/not-real'));
        $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(404, $response->status);
        self::assertFalse($payload['ok']);
        self::assertSame('not_found', $payload['error']['code']);
        self::assertArrayHasKey('requestId', $payload);
    }

    public function testOversizedRequestIsRejectedBeforeRouting(): void
    {
        $application = new Application(dirname(__DIR__, 2), new Config(['limits' => ['request_bytes' => 4], 'security' => []]));
        $response = $application->handle(new Request('POST', '/api/session/connect', [], '12345'));
        $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(413, $response->status);
        self::assertSame('request_too_large', $payload['error']['code']);
    }
}

