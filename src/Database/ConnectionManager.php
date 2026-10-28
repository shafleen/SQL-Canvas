<?php

declare(strict_types=1);

namespace SqlCanvas\Database;

use PDO;

final readonly class ConnectionManager
{
    public function __construct(private DestinationPolicy $policy)
    {
    }

    public function connect(ConnectionProfile $profile): PDO
    {
        $this->policy->assertAllowed($profile);

        $dsnParts = [];
        if ($profile->socket !== null) {
            $dsnParts[] = 'unix_socket=' . $profile->socket;
        } else {
            $tcpHost = strtolower($profile->host) === 'localhost' ? '127.0.0.1' : $profile->host;
            $dsnParts[] = 'host=' . $tcpHost;
            $dsnParts[] = 'port=' . $profile->port;
        }
        if ($profile->database !== null) {
            $dsnParts[] = 'dbname=' . $profile->database;
        }
        $dsnParts[] = 'charset=utf8mb4';

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 8,
        ];

        if ($profile->tlsEnabled && defined('PDO::MYSQL_ATTR_SSL_CA')) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $profile->tlsCaPath;
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            }
        }

        return new PDO('mysql:' . implode(';', $dsnParts), $profile->username, $profile->password, $options);
    }
}

