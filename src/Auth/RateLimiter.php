<?php

declare(strict_types=1);

namespace SqlCanvas\Auth;

final class RateLimiter
{
    public function __construct(
        private readonly string $directory,
        private readonly int $limit,
        private readonly int $windowSeconds,
    ) {
    }

    public function assertAllowed(string $key): void
    {
        $attempts = $this->read($key);
        if (count($attempts) < $this->limit) {
            return;
        }

        $retryAfter = max(1, $this->windowSeconds - (time() - min($attempts)));
        throw new RateLimitException($retryAfter);
    }

    public function recordFailure(string $key): void
    {
        $attempts = $this->read($key);
        $attempts[] = time();
        $this->write($key, $attempts);
    }

    public function clear(string $key): void
    {
        $file = $this->file($key);
        if (is_file($file)) {
            unlink($file);
        }
    }

    /** @return list<int> */
    private function read(string $key): array
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return [];
        }

        $handle = fopen($file, 'c+');
        if ($handle === false) {
            return [];
        }

        try {
            flock($handle, LOCK_SH);
            $contents = stream_get_contents($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $values = json_decode($contents ?: '[]', true);
        if (!is_array($values)) {
            return [];
        }

        $threshold = time() - $this->windowSeconds;
        return array_values(array_filter(array_map('intval', $values), static fn (int $time): bool => $time >= $threshold));
    }

    /** @param list<int> $attempts */
    private function write(string $key, array $attempts): void
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0700, true);
        }

        $handle = fopen($this->file($key), 'c+');
        if ($handle === false) {
            return;
        }

        try {
            flock($handle, LOCK_EX);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($attempts));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function file(string $key): string
    {
        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }
}

