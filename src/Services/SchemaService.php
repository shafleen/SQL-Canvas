<?php

declare(strict_types=1);

namespace SqlCanvas\Services;

use PDO;
use SqlCanvas\Database\DatabaseSession;
use SqlCanvas\Database\MetadataReader;
use SqlCanvas\Database\SqlIdentifier;
use SqlCanvas\Http\ApiException;

final readonly class SchemaService
{
    private const TYPES = [
        'TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT', 'DECIMAL', 'FLOAT', 'DOUBLE',
        'CHAR', 'VARCHAR', 'TEXT', 'MEDIUMTEXT', 'LONGTEXT', 'BINARY', 'VARBINARY', 'BLOB',
        'DATE', 'DATETIME', 'TIMESTAMP', 'TIME', 'YEAR', 'JSON', 'BOOLEAN',
    ];

    public function __construct(
        private DatabaseSession $database,
        private MetadataReader $metadata,
        private bool $readOnly,
    ) {
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        $pdo = $this->database->pdo();
        $charsets = $pdo->query('SELECT CHARACTER_SET_NAME AS name, DEFAULT_COLLATE_NAME AS defaultCollation FROM information_schema.CHARACTER_SETS ORDER BY CHARACTER_SET_NAME')->fetchAll();
        $collations = $pdo->query('SELECT COLLATION_NAME AS name, CHARACTER_SET_NAME AS charset FROM information_schema.COLLATIONS ORDER BY COLLATION_NAME')->fetchAll();
        $engines = array_values(array_filter($pdo->query('SHOW ENGINES')->fetchAll(), static fn (array $row): bool => ($row['Support'] ?? '') !== 'NO'));
        return ['types' => self::TYPES, 'charsets' => $charsets, 'collations' => $collations, 'engines' => $engines];
    }

    /** @param array<string, mixed> $input @return array{sql:string,confirmation:string,warnings:list<string>} */
    public function preview(array $input): array
    {
        $operation = strtoupper((string) ($input['operation'] ?? ''));
        return match ($operation) {
            'CREATE_DATABASE' => $this->previewCreateDatabase($input),
            'DROP_DATABASE' => $this->previewDropDatabase($input),
            'CREATE_TABLE' => $this->previewCreateTable($input),
            'DROP_TABLE' => $this->previewDropTable($input),
            'TRUNCATE_TABLE' => $this->previewTruncate($input),
            'ADD_INDEX' => $this->previewAddIndex($input),
            'DROP_INDEX' => $this->previewDropIndex($input),
            'ADD_FOREIGN_KEY' => $this->previewForeignKey($input),
            'DROP_FOREIGN_KEY' => $this->previewDropForeignKey($input),
            'ADD_COLUMN' => $this->previewAddColumn($input),
            'MODIFY_COLUMN' => $this->previewModifyColumn($input),
            'DROP_COLUMN' => $this->previewDropColumn($input),
            'RENAME_COLUMN' => $this->previewRenameColumn($input),
            'RENAME_TABLE' => $this->previewRenameTable($input),
            'COPY_TABLE' => $this->previewCopyTable($input),
            'MAINTAIN_TABLE' => $this->previewMaintenance($input),
            default => throw new ApiException('unsupported_schema_operation', 'Choose a supported structured schema operation.', 422),
        };
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function apply(array $input): array
    {
        $this->assertWritable();
        $preview = $this->preview($input);
        if (($input['confirmation'] ?? null) !== $preview['confirmation']) {
            throw new ApiException('confirmation_required', 'The confirmation text does not match the operation target.', 422, ['expected' => $preview['confirmation']]);
        }
        $pdo = $this->database->pdo();
        $started = hrtime(true);
        $affected = $pdo->exec($preview['sql']);
        return ['sql' => $preview['sql'], 'affectedRows' => $affected, 'durationMs' => round((hrtime(true) - $started) / 1_000_000, 2)];
    }

    /** @param array<string, mixed> $input */
    private function previewCreateDatabase(array $input): array
    {
        $name = $this->identifier($input, 'database');
        $charset = $this->word($input, 'charset', 'utf8mb4');
        $collation = $this->word($input, 'collation', 'utf8mb4_unicode_ci');
        $this->assertCharsetAndCollation($charset, $collation);
        return ['sql' => sprintf('CREATE DATABASE %s CHARACTER SET %s COLLATE %s', SqlIdentifier::quote($name), $charset, $collation), 'confirmation' => 'CREATE', 'warnings' => []];
    }

    /** @param array<string, mixed> $input */
    private function previewDropDatabase(array $input): array
    {
        $name = $this->identifier($input, 'database');
        return ['sql' => 'DROP DATABASE ' . SqlIdentifier::quote($name), 'confirmation' => $name, 'warnings' => ['This permanently removes every object and row in the database.', 'DDL may implicitly commit and cannot be reliably rolled back.']];
    }

    /** @param array<string, mixed> $input */
    private function previewCreateTable(array $input): array
    {
        $schema = $this->identifier($input, 'database');
        $table = $this->identifier($input, 'table');
        $columns = is_array($input['columns'] ?? null) ? $input['columns'] : [];
        if ($columns === []) throw new ApiException('validation_failed', 'Add at least one table column.', 422);
        $definitions = [];
        $primary = [];
        $unique = [];
        foreach ($columns as $column) {
            if (!is_array($column)) continue;
            $name = $this->identifier($column, 'name');
            $type = strtoupper($this->word($column, 'type'));
            if (!in_array($type, self::TYPES, true)) throw new ApiException('invalid_column_type', "The type {$type} is not allowed.", 422);
            $size = trim((string) ($column['size'] ?? ''));
            if ($size !== '' && preg_match('/\A\d+(?:,\d+)?\z/', $size) !== 1) throw new ApiException('invalid_column_size', 'Column size must contain numbers and an optional precision.', 422);
            $definition = SqlIdentifier::quote($name) . ' ' . $type . ($size !== '' ? '(' . $size . ')' : '');
            if (($column['unsigned'] ?? false) === true && preg_match('/INT|DECIMAL|FLOAT|DOUBLE/', $type)) $definition .= ' UNSIGNED';
            $generated = trim((string)($column['generatedExpression']??''));
            if($generated!=='')$definition.=' GENERATED ALWAYS AS ('.$this->generatedExpression($generated).') '.(($column['generatedStored']??false)===true?'STORED':'VIRTUAL');
            else {$definition .= ($column['nullable'] ?? false) === true ? ' NULL' : ' NOT NULL';
            if (($column['autoIncrement'] ?? false) === true) $definition .= ' AUTO_INCREMENT';
            if (array_key_exists('default', $column) && $column['default'] !== '') {
                $default = $column['default'];
                $definition .= $default === null ? ' DEFAULT NULL' : ' DEFAULT ' . $this->database->pdo()->quote((string) $default);
            }}
            $definitions[] = $definition;
            if (($column['primary'] ?? false) === true) $primary[] = SqlIdentifier::quote($name);
            if (($column['unique'] ?? false) === true) $unique[] = $name;
        }
        if ($definitions === []) throw new ApiException('validation_failed', 'No valid table columns were provided.', 422);
        if ($primary !== []) $definitions[] = 'PRIMARY KEY (' . implode(', ', $primary) . ')';
        foreach ($unique as $name) {
            $indexName = 'uq_' . $table . '_' . $name;
            if (strlen($indexName) > 64) $indexName = substr($indexName, 0, 55) . '_' . substr(hash('sha256', $indexName), 0, 8);
            $definitions[] = 'UNIQUE KEY ' . SqlIdentifier::quote($indexName) . ' (' . SqlIdentifier::quote($name) . ')';
        }
        $engine = $this->word($input, 'engine', 'InnoDB');
        $charset = $this->word($input, 'charset', 'utf8mb4');
        $this->assertEngine($engine);
        $this->assertCharsetAndCollation($charset, null);
        $sql = sprintf('CREATE TABLE %s (%s) ENGINE=%s DEFAULT CHARSET=%s', SqlIdentifier::qualified($schema, $table), implode(', ', $definitions), $engine, $charset);
        return ['sql' => $sql, 'confirmation' => 'CREATE', 'warnings' => []];
    }

    /** @param array<string, mixed> $input */
    private function previewDropTable(array $input): array
    {
        [$schema, $table] = $this->target($input);
        return ['sql' => 'DROP TABLE ' . SqlIdentifier::qualified($schema, $table), 'confirmation' => $schema . '.' . $table, 'warnings' => ['The table structure and every row will be permanently removed.', 'DDL may implicitly commit.']];
    }

    /** @param array<string, mixed> $input */
    private function previewTruncate(array $input): array
    {
        [$schema, $table] = $this->target($input);
        return ['sql' => 'TRUNCATE TABLE ' . SqlIdentifier::qualified($schema, $table), 'confirmation' => $schema . '.' . $table, 'warnings' => ['TRUNCATE is not DELETE and may implicitly commit.', 'Foreign keys may prevent this operation.']];
    }

    /** @param array<string, mixed> $input */
    private function previewAddIndex(array $input): array
    {
        [$schema, $table] = $this->target($input);
        $details = $this->metadata->tableDetails($this->database->pdo(), $schema, $table);
        $available = array_column($details['columns'], 'name');
        $columns = is_array($input['columns'] ?? null) ? array_values($input['columns']) : [];
        if ($columns === [] || array_diff($columns, $available) !== []) throw new ApiException('invalid_index_columns', 'Choose valid table columns for the index.', 422);
        $name = $this->identifier($input, 'name');
        $unique = ($input['unique'] ?? false) === true ? 'UNIQUE ' : '';
        return ['sql' => sprintf('ALTER TABLE %s ADD %sINDEX %s (%s)', SqlIdentifier::qualified($schema, $table), $unique, SqlIdentifier::quote($name), implode(', ', array_map([SqlIdentifier::class, 'quote'], $columns))), 'confirmation' => 'APPLY', 'warnings' => ['Creating an index can lock or rebuild a large table.']];
    }

    /** @param array<string, mixed> $input */
    private function previewDropIndex(array $input): array
    {
        [$schema, $table] = $this->target($input);
        $name = $this->identifier($input, 'name');
        return ['sql' => sprintf('ALTER TABLE %s DROP INDEX %s', SqlIdentifier::qualified($schema, $table), SqlIdentifier::quote($name)), 'confirmation' => $schema . '.' . $table . '.' . $name, 'warnings' => ['Removing an index can degrade query performance and may rebuild the table.']];
    }

    /** @param array<string, mixed> $input */
    private function previewForeignKey(array $input): array
    {
        [$schema, $table] = $this->target($input);
        $column = $this->identifier($input, 'column');
        $targetSchema = $this->identifier($input, 'targetDatabase');
        $targetTable = $this->identifier($input, 'targetTable');
        $targetColumn = $this->identifier($input, 'targetColumn');
        $name = $this->identifier($input, 'name');
        $sourceDetails = $this->metadata->tableDetails($this->database->pdo(), $schema, $table);
        $targetDetails = $this->metadata->tableDetails($this->database->pdo(), $targetSchema, $targetTable);
        $sourceDefinition = $this->findColumn($sourceDetails['columns'], $column);
        $targetDefinition = $this->findColumn($targetDetails['columns'], $targetColumn);
        if ($sourceDefinition === null || $targetDefinition === null) {
            throw new ApiException('invalid_foreign_key_columns', 'Choose columns that exist on both relationship tables.', 422);
        }
        if (strtolower((string) $sourceDefinition['type']) !== strtolower((string) $targetDefinition['type'])) {
            throw new ApiException('incompatible_foreign_key_columns', 'Foreign-key columns must use compatible data types.', 422);
        }
        $targetIndexed = array_filter($targetDetails['indexes'], static fn (array $index): bool => ($index['columnName'] ?? null) === $targetColumn && (int) ($index['sequence'] ?? 0) === 1);
        if ($targetIndexed === []) throw new ApiException('foreign_key_target_not_indexed', 'The referenced column must be the first column of an index.', 422);
        if (strcasecmp((string) ($sourceDetails['information']['engine'] ?? ''), 'InnoDB') !== 0 || strcasecmp((string) ($targetDetails['information']['engine'] ?? ''), 'InnoDB') !== 0) {
            throw new ApiException('foreign_key_engine_unsupported', 'Both tables must use InnoDB for this relationship workflow.', 422);
        }
        $actions = ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION'];
        $onDelete = strtoupper((string) ($input['onDelete'] ?? 'RESTRICT'));
        $onUpdate = strtoupper((string) ($input['onUpdate'] ?? 'RESTRICT'));
        if (!in_array($onDelete, $actions, true) || !in_array($onUpdate, $actions, true)) throw new ApiException('invalid_referential_action', 'Choose a supported referential action.', 422);
        $sql = sprintf('ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s ON UPDATE %s', SqlIdentifier::qualified($schema, $table), SqlIdentifier::quote($name), SqlIdentifier::quote($column), SqlIdentifier::qualified($targetSchema, $targetTable), SqlIdentifier::quote($targetColumn), $onDelete, $onUpdate);
        return ['sql' => $sql, 'confirmation' => 'APPLY', 'warnings' => ['Existing rows must satisfy the new relationship.', 'The operation can lock or rebuild the table.']];
    }

    /** @param array<string,mixed> $input */
    private function previewDropForeignKey(array $input): array
    {
        [$schema,$table]=$this->target($input);$name=$this->identifier($input,'name');
        return ['sql'=>sprintf('ALTER TABLE %s DROP FOREIGN KEY %s',SqlIdentifier::qualified($schema,$table),SqlIdentifier::quote($name)),'confirmation'=>$schema.'.'.$table.'.'.$name,'warnings'=>['Removing this relationship may allow orphaned rows.','The operation can rebuild or lock the table.']];
    }

    /** @param array<string,mixed> $input */
    private function previewAddColumn(array $input): array
    {
        [$schema,$table]=$this->target($input);$column=is_array($input['columnDefinition']??null)?$input['columnDefinition']:[];$name=$this->identifier($column,'name');$type=strtoupper($this->word($column,'type'));
        if(!in_array($type,self::TYPES,true))throw new ApiException('invalid_column_type','Choose a supported column type.',422);$size=trim((string)($column['size']??''));if($size!==''&&preg_match('/\A\d+(?:,\d+)?\z/',$size)!==1)throw new ApiException('invalid_column_size','Column size must contain numbers and an optional precision.',422);
        $definition=SqlIdentifier::quote($name).' '.$type.($size!==''?'('.$size.')':'');if(($column['unsigned']??false)===true&&preg_match('/INT|DECIMAL|FLOAT|DOUBLE/',$type))$definition.=' UNSIGNED';$generated=trim((string)($column['generatedExpression']??''));if($generated!=='')$definition.=' GENERATED ALWAYS AS ('.$this->generatedExpression($generated).') '.(($column['generatedStored']??false)===true?'STORED':'VIRTUAL');else{$definition.=($column['nullable']??false)===true?' NULL':' NOT NULL';if(array_key_exists('default',$column)&&$column['default']!=='')$definition.=$column['default']===null?' DEFAULT NULL':' DEFAULT '.$this->database->pdo()->quote((string)$column['default']);}
        return ['sql'=>sprintf('ALTER TABLE %s ADD COLUMN %s',SqlIdentifier::qualified($schema,$table),$definition),'confirmation'=>'APPLY','warnings'=>['Adding a column can rebuild and lock a large table.']];
    }

    /** @param array<string,mixed> $input */
    private function previewModifyColumn(array $input): array
    {
        [$schema,$table]=$this->target($input);$column=is_array($input['columnDefinition']??null)?$input['columnDefinition']:[];$name=$this->identifier($column,'name');$details=$this->metadata->tableDetails($this->database->pdo(),$schema,$table);if(!in_array($name,array_column($details['columns'],'name'),true))throw new ApiException('column_not_found','The selected column is not visible.',404);$type=strtoupper($this->word($column,'type'));if(!in_array($type,self::TYPES,true))throw new ApiException('invalid_column_type','Choose a supported column type.',422);$size=trim((string)($column['size']??''));if($size!==''&&preg_match('/\A\d+(?:,\d+)?\z/',$size)!==1)throw new ApiException('invalid_column_size','Column size must contain numbers and an optional precision.',422);$definition=SqlIdentifier::quote($name).' '.$type.($size!==''?'('.$size.')':'');if(($column['unsigned']??false)===true&&preg_match('/INT|DECIMAL|FLOAT|DOUBLE/',$type))$definition.=' UNSIGNED';$generated=trim((string)($column['generatedExpression']??''));if($generated!=='')$definition.=' GENERATED ALWAYS AS ('.$this->generatedExpression($generated).') '.(($column['generatedStored']??false)===true?'STORED':'VIRTUAL');else{$definition.=($column['nullable']??false)===true?' NULL':' NOT NULL';if(array_key_exists('default',$column)&&$column['default']!=='')$definition.=$column['default']===null?' DEFAULT NULL':' DEFAULT '.$this->database->pdo()->quote((string)$column['default']);}
        return ['sql'=>sprintf('ALTER TABLE %s MODIFY COLUMN %s',SqlIdentifier::qualified($schema,$table),$definition),'confirmation'=>'APPLY','warnings'=>['Changing a column can rebuild and lock the table.','Narrowing or converting a type can truncate data or fail; review a backup first.']];
    }

    /** @param array<string,mixed> $input */
    private function previewDropColumn(array $input): array
    {
        [$schema,$table]=$this->target($input);$name=$this->identifier($input,'column');return ['sql'=>sprintf('ALTER TABLE %s DROP COLUMN %s',SqlIdentifier::qualified($schema,$table),SqlIdentifier::quote($name)),'confirmation'=>$schema.'.'.$table.'.'.$name,'warnings'=>['All values in this column will be permanently removed.','Dependent indexes, views, routines, or application queries may break.']];
    }

    /** @param array<string,mixed> $input */
    private function previewRenameColumn(array $input): array
    {
        [$schema,$table]=$this->target($input);$from=$this->identifier($input,'column');$to=$this->identifier($input,'targetColumn');return ['sql'=>sprintf('ALTER TABLE %s RENAME COLUMN %s TO %s',SqlIdentifier::qualified($schema,$table),SqlIdentifier::quote($from),SqlIdentifier::quote($to)),'confirmation'=>'APPLY','warnings'=>['Renaming a column can break application queries, views, triggers, and stored programs.','Support depends on the connected server version.']];
    }

    /** @param array<string,mixed> $input */
    private function previewRenameTable(array $input): array
    {
        [$schema,$table]=$this->target($input);$target=$this->identifier($input,'targetTable');return ['sql'=>sprintf('RENAME TABLE %s TO %s',SqlIdentifier::qualified($schema,$table),SqlIdentifier::qualified($schema,$target)),'confirmation'=>$schema.'.'.$table,'warnings'=>['Renaming can break application queries and stored definitions that reference the old name.']];
    }

    /** @param array<string,mixed> $input */
    private function previewCopyTable(array $input): array
    {
        [$schema,$table]=$this->target($input);$target=$this->identifier($input,'targetTable');return ['sql'=>sprintf('CREATE TABLE %s LIKE %s',SqlIdentifier::qualified($schema,$target),SqlIdentifier::qualified($schema,$table)),'confirmation'=>'CREATE','warnings'=>['This copies table structure and indexes only; it does not copy rows, triggers, or foreign keys.']];
    }

    /** @param array<string,mixed> $input */
    private function previewMaintenance(array $input): array
    {
        [$schema,$table]=$this->target($input);$action=strtoupper((string)($input['maintenance']??''));if(!in_array($action,['ANALYZE','CHECK','OPTIMIZE','REPAIR'],true))throw new ApiException('invalid_maintenance','Choose Analyze, Check, Optimize, or Repair.',422);return ['sql'=>$action.' TABLE '.SqlIdentifier::qualified($schema,$table),'confirmation'=>'APPLY','warnings'=>['This operation can run for a long time or lock the table.','Engine support and required privileges vary.']];
    }

    /** @param array<string, mixed> $input @return array{string,string} */
    private function target(array $input): array
    {
        return [$this->identifier($input, 'database'), $this->identifier($input, 'table')];
    }

    /** @param array<string, mixed> $input */
    private function identifier(array $input, string $key): string
    {
        $value = trim(is_string($input[$key] ?? null) ? $input[$key] : '');
        if ($value === '' || strlen($value) > 64 || str_contains($value, "\0")) throw new ApiException('invalid_identifier', "Enter a valid {$key} identifier.", 422);
        return $value;
    }

    /** @param array<string, mixed> $input */
    private function word(array $input, string $key, string $default = ''): string
    {
        $value = trim(is_string($input[$key] ?? null) ? $input[$key] : $default);
        if ($value === '' || preg_match('/\A[A-Za-z0-9_]+\z/', $value) !== 1) throw new ApiException('validation_failed', "Enter a valid {$key} value.", 422);
        return $value;
    }

    private function assertEngine(string $engine): void
    {
        $statement = $this->database->pdo()->prepare('SELECT COUNT(*) FROM information_schema.ENGINES WHERE ENGINE = :engine AND SUPPORT <> :unsupported');
        $statement->execute(['engine' => $engine, 'unsupported' => 'NO']);
        if ((int) $statement->fetchColumn() !== 1) throw new ApiException('invalid_engine', 'Choose an engine supported by the connected server.', 422);
    }

    private function assertCharsetAndCollation(string $charset, ?string $collation): void
    {
        $statement = $this->database->pdo()->prepare('SELECT COUNT(*) FROM information_schema.CHARACTER_SETS WHERE CHARACTER_SET_NAME = :charset');
        $statement->execute(['charset' => $charset]);
        if ((int) $statement->fetchColumn() !== 1) throw new ApiException('invalid_charset', 'Choose a character set supported by the connected server.', 422);
        if ($collation === null) return;
        $statement = $this->database->pdo()->prepare('SELECT COUNT(*) FROM information_schema.COLLATIONS WHERE COLLATION_NAME = :collation AND CHARACTER_SET_NAME = :charset');
        $statement->execute(['collation' => $collation, 'charset' => $charset]);
        if ((int) $statement->fetchColumn() !== 1) throw new ApiException('invalid_collation', 'Choose a collation that belongs to the selected character set.', 422);
    }

    /** @param list<array<string,mixed>> $columns @return array<string,mixed>|null */
    private function findColumn(array $columns, string $name): ?array
    {
        foreach ($columns as $column) if (($column['name'] ?? null) === $name) return $column;
        return null;
    }

    private function generatedExpression(string $expression): string
    {
        if(strlen($expression)>1000||preg_match('/\A[A-Za-z0-9_`+\-*\/%().,\s\'\"]+\z/',$expression)!==1||substr_count($expression,'(')!==substr_count($expression,')'))throw new ApiException('invalid_generated_expression','Use a bounded generated-column expression containing identifiers, literals, functions, and arithmetic operators only.',422);return $expression;
    }

    private function assertWritable(): void
    {
        if ($this->readOnly) throw new ApiException('read_only_policy', 'This SQL Canvas deployment is configured for read-only database access.', 403);
    }
}

