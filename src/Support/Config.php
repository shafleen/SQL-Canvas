<?php

declare(strict_types=1);

namespace SqlCanvas\Support;

use InvalidArgumentException;

final readonly class Config
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }

    public static function fromProjectRoot(string $root): self
    {
        $defaultsFile = $root . '/config/defaults.php';
        if (!is_file($defaultsFile)) {
            throw new InvalidArgumentException('The default configuration is missing.');
        }

        /** @var array<string, mixed> $defaults */
        $defaults = require $defaultsFile;
        $localFile = $root . '/config/config.php';
        if (!is_file($localFile)) {
            return new self($defaults);
        }

        /** @var array<string, mixed> $local */
        $local = require $localFile;

        return new self(array_replace_recursive($defaults, $local));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}

