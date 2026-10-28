<?php

declare(strict_types=1);

namespace SqlCanvas\Services;

use InvalidArgumentException;
use PDOException;
use SqlCanvas\Auth\RateLimiter;
use SqlCanvas\Auth\SessionAuth;
use SqlCanvas\Database\CapabilityDetector;
use SqlCanvas\Database\ConnectionManager;
use SqlCanvas\Database\ConnectionProfile;
use SqlCanvas\Http\ApiException;

final readonly class ConnectionService
{
    public function __construct(
        private ConnectionManager $connections,
        private CapabilityDetector $capabilities,
        private SessionAuth $session,
        private RateLimiter $limiter,
    ) {
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function connect(array $input, string $clientIp): array
    {
        $key = 'connection:' . $clientIp;
        $this->limiter->assertAllowed($key);

        try {
            $profile = ConnectionProfile::fromInput($input);
            $pdo = $this->connections->connect($profile);
            $server = $this->capabilities->detect($pdo);
        } catch (InvalidArgumentException $exception) {
            throw new ApiException('validation_failed', $exception->getMessage(), 422);
        } catch (PDOException $exception) {
            $this->limiter->recordFailure($key);
            throw $this->safeConnectionError($exception);
        }

        $this->limiter->clear($key);
        $this->session->establish($profile, $server);

        return $this->session->publicState();
    }

    /** @return array<string, mixed> */
    public function disconnect(): array
    {
        $this->session->disconnect();
        return $this->session->publicState();
    }

    private function safeConnectionError(PDOException $exception): ApiException
    {
        $driverCode = is_array($exception->errorInfo ?? null) ? (int) ($exception->errorInfo[1] ?? 0) : 0;

        return match ($driverCode) {
            1045 => new ApiException('authentication_failed', 'The database rejected the username or password.', 401),
            1049 => new ApiException('database_not_found', 'The requested default database does not exist or is not visible.', 422),
            2002, 2003, 2005 => new ApiException('connection_unavailable', 'SQL Canvas could not reach the permitted database host and port.', 502),
            default => new ApiException('connection_failed', 'The database connection could not be established.', 502),
        };
    }
}

