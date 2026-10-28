<?php

declare(strict_types=1);

namespace SqlCanvas\Database;

use PDO;
use PDOException;
use SqlCanvas\Auth\SessionAuth;
use SqlCanvas\Http\ApiException;

final readonly class DatabaseSession
{
    public function __construct(
        private ConnectionManager $connections,
        private SessionAuth $session,
    ) {
    }

    public function pdo(): PDO
    {
        $profile = $this->session->profile();
        if ($profile === null) {
            throw new ApiException('not_connected', 'Connect to a database server first.', 401);
        }

        try {
            return $this->connections->connect($profile);
        } catch (PDOException) {
            $this->session->disconnect();
            throw new ApiException('connection_lost', 'The database session is no longer available. Connect again.', 401);
        }
    }
}

