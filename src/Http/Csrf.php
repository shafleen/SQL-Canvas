<?php

declare(strict_types=1);

namespace SqlCanvas\Http;

final class Csrf
{
    public static function token(): string
    {
        if (!isset($_SESSION['sql_canvas_csrf']) || !is_string($_SESSION['sql_canvas_csrf'])) {
            $_SESSION['sql_canvas_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['sql_canvas_csrf'];
    }

    public static function assertValid(?string $token): void
    {
        $expected = self::token();
        if ($token === null || !hash_equals($expected, $token)) {
            throw new ApiException('csrf_failed', 'The security token is missing or expired. Refresh SQL Canvas and try again.', 403);
        }
    }

    public static function rotate(): string
    {
        $_SESSION['sql_canvas_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['sql_canvas_csrf'];
    }
}

