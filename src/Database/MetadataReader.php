<?php

declare(strict_types=1);

namespace SqlCanvas\Database;

use PDO;
use SqlCanvas\Http\ApiException;

final class MetadataReader
{
    /** @return list<array{name:string}> */
    public function databases(PDO $pdo): array
    {
        $rows = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
        return array_map(static fn (mixed $name): array => ['name' => (string) $name], $rows);
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function schemaObjects(PDO $pdo, string $schema): array
    {
        $this->assertSchemaVisible($pdo, $schema);

        $tables = $this->fetchAll($pdo,
            'SELECT TABLE_NAME AS name, TABLE_TYPE AS type, ENGINE AS engine, TABLE_ROWS AS estimatedRows '
            . 'FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema ORDER BY TABLE_NAME',
            $schema,
        );
        $routines = $this->fetchAll($pdo,
            'SELECT ROUTINE_NAME AS name, ROUTINE_TYPE AS type FROM information_schema.ROUTINES '
            . 'WHERE ROUTINE_SCHEMA = :schema ORDER BY ROUTINE_NAME',
            $schema,
        );
        $triggers = $this->fetchAll($pdo,
            'SELECT TRIGGER_NAME AS name, EVENT_MANIPULATION AS event FROM information_schema.TRIGGERS '
            . 'WHERE TRIGGER_SCHEMA = :schema ORDER BY TRIGGER_NAME',
            $schema,
        );
        $events = $this->fetchAll($pdo,
            'SELECT EVENT_NAME AS name, STATUS AS status FROM information_schema.EVENTS '
            . 'WHERE EVENT_SCHEMA = :schema ORDER BY EVENT_NAME',
            $schema,
        );

        return [
            'tables' => array_values(array_filter($tables, static fn (array $row): bool => $row['type'] === 'BASE TABLE')),
            'views' => array_values(array_filter($tables, static fn (array $row): bool => $row['type'] === 'VIEW')),
            'routines' => $routines,
            'triggers' => $triggers,
            'events' => $events,
        ];
    }

    /** @return array{columns:list<array<string,mixed>>,indexes:list<array<string,mixed>>,foreignKeys:list<array<string,mixed>>,information:array<string,mixed>} */
    public function tableDetails(PDO $pdo, string $schema, string $table): array
    {
        $this->assertTableVisible($pdo, $schema, $table);
        $columns = $this->fetch($pdo,
            'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_KEY AS columnKey, '
            . 'COLUMN_DEFAULT AS defaultValue, EXTRA AS extra, ORDINAL_POSITION AS position '
            . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table ORDER BY ORDINAL_POSITION',
            ['schema' => $schema, 'table' => $table],
        );
        $indexes = $this->fetch($pdo,
            'SELECT INDEX_NAME AS name, COLUMN_NAME AS columnName, NON_UNIQUE AS nonUnique, '
            . 'SEQ_IN_INDEX AS sequence, INDEX_TYPE AS type FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            ['schema' => $schema, 'table' => $table],
        );
        $foreignKeys = $this->fetch($pdo,
            'SELECT CONSTRAINT_NAME AS name, COLUMN_NAME AS columnName, REFERENCED_TABLE_SCHEMA AS referencedDatabase, REFERENCED_TABLE_NAME AS referencedTable, REFERENCED_COLUMN_NAME AS referencedColumn '
            . 'FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION',
            ['schema' => $schema, 'table' => $table],
        );
        $informationStatement = $pdo->prepare(
            'SELECT ENGINE AS engine, TABLE_ROWS AS estimatedRows, DATA_LENGTH AS dataBytes, INDEX_LENGTH AS indexBytes, TABLE_COLLATION AS collation, AUTO_INCREMENT AS autoIncrement, CREATE_TIME AS createdAt, UPDATE_TIME AS updatedAt, TABLE_COMMENT AS comment '
            . 'FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table'
        );
        $informationStatement->execute(['schema' => $schema, 'table' => $table]);
        $information = $informationStatement->fetch() ?: [];

        return ['columns' => $columns, 'indexes' => $indexes, 'foreignKeys' => $foreignKeys, 'information' => $information];
    }

    private function assertSchemaVisible(PDO $pdo, string $schema): void
    {
        $names = array_column($this->databases($pdo), 'name');
        if (!in_array($schema, $names, true)) {
            throw new ApiException('schema_not_found', 'The selected database is not visible to this account.', 404);
        }
    }

    private function assertTableVisible(PDO $pdo, string $schema, string $table): void
    {
        $this->assertSchemaVisible($pdo, $schema);
        $statement = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table'
        );
        $statement->execute(['schema' => $schema, 'table' => $table]);
        if ($statement->fetchColumn() === false) {
            throw new ApiException('table_not_found', 'The selected table is not visible to this account.', 404);
        }
    }

    /** @return list<array<string, mixed>> */
    private function fetchAll(PDO $pdo, string $sql, string $schema): array
    {
        return $this->fetch($pdo, $sql, ['schema' => $schema]);
    }

    /** @param array<string, string> $parameters @return list<array<string, mixed>> */
    private function fetch(PDO $pdo, string $sql, array $parameters): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    }
}

