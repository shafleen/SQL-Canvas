<?php

declare(strict_types=1);

namespace SqlCanvas\Http;

final readonly class Request
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $method,
        public string $path,
        public array $headers = [],
        public string $body = '',
        public string $clientIp = 'unknown',
        /** @var array<string, string> */
        public array $query = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }

        $query = [];
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $query = array_filter($query, 'is_string');

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path === '' ? '/' : $path,
            $headers,
            (string) file_get_contents('php://input'),
            (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            $query,
        );
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }

        $value = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new \JsonException('The request body must be a JSON object.');
        }

        return $value;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}

