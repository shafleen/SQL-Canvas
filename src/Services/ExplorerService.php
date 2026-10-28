<?php

declare(strict_types=1);

namespace SqlCanvas\Services;

use PDO;
use PDOException;
use SqlCanvas\Auth\SessionAuth;
use SqlCanvas\Database\ConnectionManager;
use SqlCanvas\Database\MetadataReader;
use SqlCanvas\Http\ApiException;

final readonly class ExplorerService
{
    public function __construct(
        private ConnectionManager $connections,
        private MetadataReader $metadata,
        private SessionAuth $session,
    ) {
    }

    /** @return list<array{name:string}> */
    public function databases(): array
    {
        return $this->metadata->databases($this->pdo());
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function schemaObjects(string $schema): array
    {
        return $this->metadata->schemaObjects($this->pdo(), $schema);
    }

    /** @return array{columns:list<array<string,mixed>>,indexes:list<array<string,mixed>>,foreignKeys:list<array<string,mixed>>,information:array<string,mixed>} */
    public function tableDetails(string $schema, string $table): array
    {
        return $this->metadata->tableDetails($this->pdo(), $schema, $table);
    }

    private function pdo(): PDO
    {
        $profile = $this->session->profile();
        if ($profile === null) {
            throw new ApiException('not_connected', 'Connect to a database server before browsing metadata.', 401);
        }

        try {
            return $this->connections->connect($profile);
        } catch (PDOException) {
            $this->session->disconnect();
            throw new ApiException('connection_lost', 'The database session is no longer available. Connect again.', 401);
        }
    }
}

