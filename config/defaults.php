<?php

declare(strict_types=1);

return [
    'app' => [
        'environment' => 'development',
        'base_path' => '',
        'display_errors' => false,
    ],
    'security' => [
        'read_only' => true,
        'allowed_connection_hosts' => ['127.0.0.1', '::1', 'localhost'],
        'allowed_connection_ports' => [3306, 3307, 3308],
        'require_tls_for_remote' => true,
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
        'request_bytes' => 6291456,
        'page_size' => 100,
        'query_timeout_seconds' => 30,
    ],
];

