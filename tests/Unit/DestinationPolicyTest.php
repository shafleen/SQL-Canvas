<?php

declare(strict_types=1);

namespace SqlCanvas\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SqlCanvas\Database\ConnectionProfile;
use SqlCanvas\Database\DestinationPolicy;
use SqlCanvas\Http\ApiException;
use SqlCanvas\Support\Config;

final class DestinationPolicyTest extends TestCase
{
    public function testUnlistedHostIsDenied(): void
    {
        $policy = $this->policy(['127.0.0.1'], [3306]);
        $profile = $this->profile('database.example.test', 3306, true, __FILE__);

        try {
            $policy->assertAllowed($profile);
            self::fail('Expected destination policy denial.');
        } catch (ApiException $exception) {
            self::assertSame('destination_denied', $exception->errorCode);
            self::assertSame(403, $exception->status);
        }
    }

    public function testUnlistedPortIsDenied(): void
    {
        $policy = $this->policy(['127.0.0.1'], [3306]);

        $this->expectException(ApiException::class);
        $policy->assertAllowed($this->profile('127.0.0.1', 3307));
    }

    public function testAllowedLocalDestinationDoesNotRequireTls(): void
    {
        $policy = $this->policy(['127.0.0.1'], [3307]);
        $policy->assertAllowed($this->profile('127.0.0.1', 3307));
        self::addToAssertionCount(1);
    }

    /** @param list<string> $hosts @param list<int> $ports */
    private function policy(array $hosts, array $ports): DestinationPolicy
    {
        return new DestinationPolicy(new Config(['security' => [
            'allowed_connection_hosts' => $hosts,
            'allowed_connection_ports' => $ports,
            'require_tls_for_remote' => true,
            'allow_socket_connections' => false,
        ]]));
    }

    private function profile(string $host, int $port, bool $tls = false, ?string $ca = null): ConnectionProfile
    {
        return new ConnectionProfile('Test', $host, $port, 'user', 'secret', null, null, $tls, $ca);
    }
}

