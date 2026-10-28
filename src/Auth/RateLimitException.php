<?php

declare(strict_types=1);

namespace SqlCanvas\Auth;

use RuntimeException;

final class RateLimitException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Too many connection attempts.');
    }
}

