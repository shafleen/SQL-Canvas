<?php

declare(strict_types=1);

namespace SqlCanvas\Support;

final readonly class AuditLogger
{
    public function __construct(private string $directory)
    {
    }

    /** @param array<string,mixed> $context */
    public function write(string $action, int $status, array $context = []): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) return;
        $entry = [
            'time' => gmdate('c'),
            'action' => substr($action, 0, 240),
            'status' => $status,
            'outcome' => $status < 400 ? 'accepted' : 'rejected',
            'actor' => isset($context['actor']) ? substr((string) $context['actor'], 0, 255) : 'disconnected',
            'client' => isset($context['client']) ? hash('sha256', (string) $context['client']) : null,
        ];
        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) return;
        @file_put_contents($this->directory . '/audit-' . gmdate('Y-m-d') . '.log', $json . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
