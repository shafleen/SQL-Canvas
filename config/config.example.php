<?php

declare(strict_types=1);

/**
 * Copy this file to config/config.php. Never commit the copied file.
 * This template contains policy only: database credentials are intentionally
 * absent and are held only in the server-side PHP session.
 */
return [
    'app' => [
        'environment' => 'production',
        'base_path' => '', // Example: '/sql-canvas' when hosted in a subdirectory.
        'display_errors' => false,
    ],
    'security' => [
        // Enforced server-side for row, schema, import, query, and administration actions.
        'read_only' => true,
        // Use exact hosts/IPs. An empty list denies every database destination.
        'allowed_connection_hosts' => ['127.0.0.1'],
        'allowed_connection_ports' => [3306],
        // Remote destinations must use verified TLS. Localhost may be unencrypted.
        'require_tls_for_remote' => true,
        // Unix sockets are off unless explicitly enabled for a controlled host.
        'allow_socket_connections' => false,
        'session' => [
            'name' => 'sql_canvas_session',
            'idle_timeout_seconds' => 1800,
            'absolute_timeout_seconds' => 28800,
            'same_site' => 'Strict',
        ],
        'connection_attempts' => [
            'limit' => 5,
            'window_seconds' => 60,
        ],
    ],
    'limits' => [
        // Must be larger than the configured browser import limit plus JSON overhead.
        'request_bytes' => 6291456,
        'page_size' => 100,
        'query_timeout_seconds' => 30,
    ],
];

