<?php

declare(strict_types=1);

namespace SqlCanvas\Auth;

use SqlCanvas\Database\ConnectionProfile;
use SqlCanvas\Support\Config;

final class SessionAuth
{
    private const PROFILE_KEY = 'sql_canvas_connection';
    private const SERVER_KEY = 'sql_canvas_server';

    public function __construct(private readonly Config $config)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->enforceLifetime();
            return;
        }

        $session = (array) $this->config->get('security.session', []);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name((string) ($session['name'] ?? 'sql_canvas_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->isHttps(),
            'httponly' => true,
            'samesite' => (string) ($session['same_site'] ?? 'Strict'),
        ]);
        session_start();
        $this->enforceLifetime();
    }

    /** @param array<string, mixed> $server */
    public function establish(ConnectionProfile $profile, array $server): void
    {
        session_regenerate_id(true);
        $_SESSION[self::PROFILE_KEY] = $profile->toSession();
        $_SESSION[self::SERVER_KEY] = $server;
        $_SESSION['sql_canvas_connected_at'] = time();
        $_SESSION['sql_canvas_last_activity'] = time();
    }

    public function disconnect(): void
    {
        unset($_SESSION[self::PROFILE_KEY], $_SESSION[self::SERVER_KEY], $_SESSION['sql_canvas_connected_at']);
        session_regenerate_id(true);
    }

    public function isConnected(): bool
    {
        return isset($_SESSION[self::PROFILE_KEY]) && is_array($_SESSION[self::PROFILE_KEY]);
    }

    public function profile(): ?ConnectionProfile
    {
        $value = $_SESSION[self::PROFILE_KEY] ?? null;
        return is_array($value) ? ConnectionProfile::fromSession($value) : null;
    }

    /** @return array<string, mixed> */
    public function publicState(): array
    {
        if (!$this->isConnected()) {
            return ['connected' => false];
        }

        $profile = $this->profile();
        return [
            'connected' => true,
            'connection' => $profile?->publicSummary(),
            'server' => is_array($_SESSION[self::SERVER_KEY] ?? null) ? $_SESSION[self::SERVER_KEY] : [],
        ];
    }

    private function enforceLifetime(): void
    {
        $now = time();
        $idle = (int) $this->config->get('security.session.idle_timeout_seconds', 1800);
        $absolute = (int) $this->config->get('security.session.absolute_timeout_seconds', 28800);
        $last = (int) ($_SESSION['sql_canvas_last_activity'] ?? $now);
        $connectedAt = (int) ($_SESSION['sql_canvas_connected_at'] ?? $now);

        if ($this->isConnected() && (($now - $last) > $idle || ($now - $connectedAt) > $absolute)) {
            $this->disconnect();
            $_SESSION['sql_canvas_session_expired'] = true;
        }
        $_SESSION['sql_canvas_last_activity'] = $now;
    }

    private function isHttps(): bool
    {
        return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
    }
}

