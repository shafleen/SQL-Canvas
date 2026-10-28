<?php

declare(strict_types=1);

namespace SqlCanvas\Services;

use SqlCanvas\Database\DatabaseSession;
use SqlCanvas\Http\ApiException;

final readonly class CanvasService
{
    public function __construct(private DatabaseSession $database)
    {
    }

    /** @return array<string, mixed> */
    public function graph(string $schema): array
    {
        $pdo = $this->database->pdo();
        $visible = $pdo->query('SHOW DATABASES')->fetchAll(\PDO::FETCH_COLUMN);
        if (!in_array($schema, $visible, true)) throw new ApiException('schema_not_found', 'The selected database is not visible to this account.', 404);
        $tableStatement = $pdo->prepare('SELECT TABLE_NAME AS name, TABLE_TYPE AS type, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema ORDER BY TABLE_NAME');
        $tableStatement->execute(['schema' => $schema]);
        $tables = $tableStatement->fetchAll();
        $columnStatement = $pdo->prepare('SELECT TABLE_NAME AS tableName, COLUMN_NAME AS name, COLUMN_TYPE AS type, COLUMN_KEY AS columnKey, ORDINAL_POSITION AS position FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :schema ORDER BY TABLE_NAME, ORDINAL_POSITION');
        $columnStatement->execute(['schema' => $schema]);
        $columns = $columnStatement->fetchAll();
        $relationStatement = $pdo->prepare('SELECT k.CONSTRAINT_NAME AS name, k.TABLE_NAME AS sourceTable, k.COLUMN_NAME AS sourceColumn, k.REFERENCED_TABLE_NAME AS targetTable, k.REFERENCED_COLUMN_NAME AS targetColumn, r.UPDATE_RULE AS onUpdate, r.DELETE_RULE AS onDelete FROM information_schema.KEY_COLUMN_USAGE k LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.CONSTRAINT_SCHEMA = :schema AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME, k.ORDINAL_POSITION');
        $relationStatement->execute(['schema' => $schema]);
        $grouped = [];
        foreach ($columns as $column) $grouped[$column['tableName']][] = $column;
        foreach ($tables as &$table) $table['columns'] = $grouped[$table['name']] ?? [];
        return ['database' => $schema, 'tables' => $tables, 'relationships' => $relationStatement->fetchAll()];
    }
}

