<?php

declare(strict_types=1);

namespace SqlCanvas\Database;

use InvalidArgumentException;

final readonly class ConnectionProfile
{
    public function __construct(
        public string $label,
        public string $host,
        public int $port,
        public string $username,
        public string $password,
        public ?string $database,
        public ?string $socket,
        public bool $tlsEnabled,
        public ?string $tlsCaPath,
    ) {
    }

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        $host = trim(self::string($input, 'host'));
        $username = trim(self::string($input, 'username'));
        $password = self::string($input, 'password', false);
        $label = trim((string) ($input['label'] ?? ''));
        $database = self::nullableString($input['database'] ?? null);
        $socket = self::nullableString($input['socket'] ?? null);
        $port = filter_var($input['port'] ?? 3306, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);

        if ($host === '' || strlen($host) > 253) {
            throw new InvalidArgumentException('Enter a valid database host.');
        }
        if ($username === '' || strlen($username) > 128) {
            throw new InvalidArgumentException('Enter a valid database username.');
        }
        if ($port === false) {
            throw new InvalidArgumentException('Enter a database port between 1 and 65535.');
        }

        $tls = is_array($input['tls'] ?? null) ? $input['tls'] : [];
        $tlsEnabled = ($tls['enabled'] ?? false) === true;
        $tlsCaPath = self::nullableString($tls['caPath'] ?? null);

        return new self(
            $label !== '' ? substr($label, 0, 80) : $host . ':' . $port,
            $host,
            (int) $port,
            $username,
            $password,
            $database,
            $socket,
            $tlsEnabled,
            $tlsCaPath,
        );
    }

    /** @param array<string, mixed> $value */
    public static function fromSession(array $value): self
    {
        return new self(
            (string) ($value['label'] ?? ''),
            (string) ($value['host'] ?? ''),
            (int) ($value['port'] ?? 3306),
            (string) ($value['username'] ?? ''),
            (string) ($value['password'] ?? ''),
            self::nullableString($value['database'] ?? null),
            self::nullableString($value['socket'] ?? null),
            ($value['tlsEnabled'] ?? false) === true,
            self::nullableString($value['tlsCaPath'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public function toSession(): array
    {
        return [
            'label' => $this->label,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
            'database' => $this->database,
            'socket' => $this->socket,
            'tlsEnabled' => $this->tlsEnabled,
            'tlsCaPath' => $this->tlsCaPath,
        ];
    }

    /** @return array<string, mixed> */
    public function publicSummary(): array
    {
        return [
            'label' => $this->label,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'database' => $this->database,
            'transport' => $this->socket !== null ? 'socket' : ($this->tlsEnabled ? 'tls' : 'tcp'),
        ];
    }

    /** @param array<string, mixed> $input */
    private static function string(array $input, string $key, bool $trimRequired = true): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException($trimRequired ? "The {$key} field is required." : "The {$key} field must be text.");
        }
        return $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}

