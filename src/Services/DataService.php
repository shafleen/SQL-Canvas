<?php

declare(strict_types=1);

namespace SqlCanvas\Services;

use PDO;
use SqlCanvas\Database\DatabaseSession;
use SqlCanvas\Database\MetadataReader;
use SqlCanvas\Database\SqlIdentifier;
use SqlCanvas\Http\ApiException;

final readonly class DataService
{
    public function __construct(
        private DatabaseSession $database,
        private MetadataReader $metadata,
        private bool $readOnly,
        private int $maximumPageSize = 200,
    ) {
    }

    /** @param array<string, string> $query @return array<string, mixed> */
    public function rows(string $schema, string $table, array $query): array
    {
        $pdo = $this->database->pdo();
        $details = $this->metadata->tableDetails($pdo, $schema, $table);
        $columns = array_column($details['columns'], 'name');
        $primaryKey = $this->primaryKey($details['indexes']);
        $pageSize = min($this->maximumPageSize, max(1, (int) ($query['pageSize'] ?? 50)));
        $page = max(1, (int) ($query['page'] ?? 1));
        $sort = (string) ($query['sort'] ?? ($primaryKey[0] ?? $columns[0] ?? ''));
        if (!in_array($sort, $columns, true)) {
            throw new ApiException('invalid_sort', 'The selected sort column is not available.', 422);
        }
        $direction = strtoupper((string) ($query['direction'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $offset = ($page - 1) * $pageSize;
        $qualified = SqlIdentifier::qualified($schema, $table);
        $filterColumn = (string) ($query['filterColumn'] ?? '');
        $filterValue = (string) ($query['filterValue'] ?? '');
        $conditions = [];
        $parameters = [];
        if ($filterColumn !== '') {
            if (!in_array($filterColumn, $columns, true)) throw new ApiException('invalid_filter', 'The selected filter column is not available.', 422);
            $conditions[] = 'CAST(' . SqlIdentifier::quote($filterColumn) . ' AS CHAR) LIKE :filter';
            $parameters['filter'] = '%' . $filterValue . '%';
        }
        $cursor=$query['cursor']??null;$cursorMode=count($primaryKey)===1&&$sort===$primaryKey[0]&&($page===1||(is_string($cursor)&&$cursor!==''));
        if($cursorMode&&is_string($cursor)&&$cursor!==''){$decoded=base64_decode($cursor,true);if($decoded===false)throw new ApiException('invalid_cursor','The pagination cursor is invalid.',422);$conditions[]=SqlIdentifier::quote($sort).($direction==='DESC'?' < ':' > ').':cursor';$parameters['cursor']=$decoded;$offset=0;}
        $where=$conditions===[]?'':' WHERE '.implode(' AND ',$conditions);
        $sql = sprintf(
            'SELECT * FROM %s%s ORDER BY %s %s LIMIT %d OFFSET %d',
            $qualified,
            $where,
            SqlIdentifier::quote($sort),
            $direction,
            $pageSize + 1,
            $cursorMode ? 0 : $offset,
        );
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll();
        $hasMore = count($rows) > $pageSize;
        if ($hasMore) array_pop($rows);
        $nextCursor=$cursorMode&&$rows!==[]?base64_encode((string)$rows[array_key_last($rows)][$sort]):null;
        $rows = $this->normalizeBinaryRows($rows, $details['columns']);

        return [
            'schema' => $schema,
            'table' => $table,
            'columns' => $details['columns'],
            'primaryKey' => $primaryKey,
            'editable' => $primaryKey !== [],
            'writable' => !$this->readOnly && $primaryKey !== [],
            'rows' => $rows,
            'page' => $page,
            'pageSize' => $pageSize,
            'sort' => $sort,
            'direction' => $direction,
            'hasMore' => $hasMore,
            'paginationMode' => $cursorMode ? 'keyset' : 'offset',
            'nextCursor' => $nextCursor,
            'filterColumn' => $filterColumn,
            'filterValue' => $filterValue,
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function insert(string $schema, string $table, array $input): array
    {
        $this->assertWritable();
        $pdo = $this->database->pdo();
        $details = $this->metadata->tableDetails($pdo, $schema, $table);
        $allowed = array_column(array_filter($details['columns'], static fn (array $column): bool => stripos((string) ($column['extra'] ?? ''), 'generated') === false), 'name');
        $values = is_array($input['values'] ?? null) ? $input['values'] : [];
        $values = array_intersect_key($values, array_flip($allowed));
        if ($values === []) {
            throw new ApiException('validation_failed', 'Enter at least one column value.', 422);
        }

        $columns = array_keys($values);
        $placeholders = array_map(static fn (string $name): string => ':v_' . hash('crc32b', $name), $columns);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            SqlIdentifier::qualified($schema, $table),
            implode(', ', array_map([SqlIdentifier::class, 'quote'], $columns)),
            implode(', ', $placeholders),
        );
        $statement = $pdo->prepare($sql);
        foreach ($columns as $index => $column) {
            $statement->bindValue($placeholders[$index], $values[$column], $values[$column] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }
        $statement->execute();

        return ['affectedRows' => $statement->rowCount(), 'insertId' => $pdo->lastInsertId() ?: null];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function update(string $schema, string $table, array $input): array
    {
        $this->assertWritable();
        $pdo = $this->database->pdo();
        $details = $this->metadata->tableDetails($pdo, $schema, $table);
        $columns = array_column(array_filter($details['columns'], static fn (array $column): bool => stripos((string) ($column['extra'] ?? ''), 'generated') === false), 'name');
        $primaryKey = $this->primaryKey($details['indexes']);
        if ($primaryKey === []) {
            throw new ApiException('unsafe_row_identity', 'Rows in this table are read-only because no primary key is available.', 409);
        }

        $identity = is_array($input['identity'] ?? null) ? $input['identity'] : [];
        $changes = is_array($input['changes'] ?? null) ? $input['changes'] : [];
        $original = is_array($input['original'] ?? null) ? $input['original'] : [];
        $changes = array_intersect_key($changes, array_flip($columns));
        if ($changes === [] || array_diff($primaryKey, array_keys($identity)) !== []) {
            throw new ApiException('validation_failed', 'The row identity or changes are incomplete.', 422);
        }

        $set = [];
        $where = [];
        $parameters = [];
        foreach ($changes as $column => $value) {
            $name = ':set_' . hash('crc32b', $column);
            $set[] = SqlIdentifier::quote($column) . ' = ' . $name;
            $parameters[$name] = $value;
        }
        foreach ($primaryKey as $column) {
            $name = ':pk_' . hash('crc32b', $column);
            $where[] = SqlIdentifier::quote($column) . ' <=> ' . $name;
            $parameters[$name] = $identity[$column];
        }
        foreach (array_intersect_key($original, array_flip($columns)) as $column => $value) {
            if (is_array($value) || is_object($value)) continue;
            $name = ':old_' . hash('crc32b', $column);
            $where[] = SqlIdentifier::quote($column) . ' <=> ' . $name;
            $parameters[$name] = $value;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s LIMIT 1', SqlIdentifier::qualified($schema, $table), implode(', ', $set), implode(' AND ', $where));
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        if ($statement->rowCount() !== 1) {
            throw new ApiException('write_conflict', 'The row changed after it was loaded. Reload it before saving.', 409);
        }
        return ['affectedRows' => 1];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function delete(string $schema, string $table, array $input): array
    {
        $this->assertWritable();
        if (($input['confirmation'] ?? null) !== $schema . '.' . $table) {
            throw new ApiException('confirmation_required', 'Type the qualified table name to confirm row deletion.', 422);
        }
        $pdo = $this->database->pdo();
        $details = $this->metadata->tableDetails($pdo, $schema, $table);
        $primaryKey = $this->primaryKey($details['indexes']);
        $identity = is_array($input['identity'] ?? null) ? $input['identity'] : [];
        if ($primaryKey === [] || array_diff($primaryKey, array_keys($identity)) !== []) {
            throw new ApiException('unsafe_row_identity', 'A complete primary-key identity is required for deletion.', 409);
        }
        $where = [];
        $parameters = [];
        foreach ($primaryKey as $column) {
            $name = ':pk_' . hash('crc32b', $column);
            $where[] = SqlIdentifier::quote($column) . ' <=> ' . $name;
            $parameters[$name] = $identity[$column];
        }
        $statement = $pdo->prepare(sprintf('DELETE FROM %s WHERE %s LIMIT 1', SqlIdentifier::qualified($schema, $table), implode(' AND ', $where)));
        $statement->execute($parameters);
        if ($statement->rowCount() !== 1) {
            throw new ApiException('write_conflict', 'The row no longer exists or changed before deletion.', 409);
        }
        return ['affectedRows' => 1];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function duplicate(string $schema, string $table, array $input): array
    {
        $this->assertWritable();$pdo=$this->database->pdo();$details=$this->metadata->tableDetails($pdo,$schema,$table);$primaryKey=$this->primaryKey($details['indexes']);$identity=is_array($input['identity']??null)?$input['identity']:[];
        if($primaryKey===[]||array_diff($primaryKey,array_keys($identity))!==[])throw new ApiException('unsafe_row_identity','A complete primary-key identity is required to duplicate a row.',409);
        $where=[];$parameters=[];foreach($primaryKey as $column){$name=':pk_'.hash('crc32b',$column);$where[]=SqlIdentifier::quote($column).' <=> '.$name;$parameters[$name]=$identity[$column];}
        $statement=$pdo->prepare(sprintf('SELECT * FROM %s WHERE %s LIMIT 2',SqlIdentifier::qualified($schema,$table),implode(' AND ',$where)));$statement->execute($parameters);$rows=$statement->fetchAll();if(count($rows)!==1)throw new ApiException('write_conflict','The source row is no longer uniquely available.',409);$values=$rows[0];
        $generatedPrimary=[];foreach($details['columns'] as $column){if(str_contains((string)($column['extra']??''),'auto_increment')){$generatedPrimary[]=(string)$column['name'];unset($values[$column['name']]);}}
        if(array_diff($primaryKey,$generatedPrimary)!==[])throw new ApiException('duplicate_requires_new_key','This row uses a non-generated primary key. Use Add row so you can provide a new identity.',409);
        return $this->insert($schema,$table,['values'=>$values]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function bulkDelete(string $schema,string $table,array $input):array
    {
        $this->assertWritable();$identities=is_array($input['identities']??null)?array_values($input['identities']):[];if($identities===[]||count($identities)>100)throw new ApiException('invalid_bulk_selection','Select between 1 and 100 rows for one bulk delete.',422);$expected=$schema.'.'.$table.':'.count($identities);if(($input['confirmation']??null)!==$expected)throw new ApiException('confirmation_required',"Type {$expected} to confirm bulk deletion.",422);
        $pdo=$this->database->pdo();$details=$this->metadata->tableDetails($pdo,$schema,$table);$primaryKey=$this->primaryKey($details['indexes']);if($primaryKey===[])throw new ApiException('unsafe_row_identity','A primary key is required for bulk deletion.',409);$where=[];foreach($primaryKey as $column)$where[]=SqlIdentifier::quote($column).' <=> :pk_'.hash('crc32b',$column);$statement=$pdo->prepare(sprintf('DELETE FROM %s WHERE %s LIMIT 1',SqlIdentifier::qualified($schema,$table),implode(' AND ',$where)));$deleted=0;$pdo->beginTransaction();try{foreach($identities as $identity){if(!is_array($identity)||array_diff($primaryKey,array_keys($identity))!==[])throw new ApiException('unsafe_row_identity','Every selected row must include its complete primary key.',409);$parameters=[];foreach($primaryKey as $column)$parameters[':pk_'.hash('crc32b',$column)]=$identity[$column];$statement->execute($parameters);if($statement->rowCount()!==1)throw new ApiException('write_conflict','A selected row no longer exists. Nothing was deleted.',409);$deleted++;}$pdo->commit();}catch(\Throwable $exception){if($pdo->inTransaction())$pdo->rollBack();throw $exception;}return ['affectedRows'=>$deleted];
    }

    /** @param list<array<string,mixed>> $indexes @return list<string> */
    private function primaryKey(array $indexes): array
    {
        $primary = array_values(array_filter($indexes, static fn (array $index): bool => $index['name'] === 'PRIMARY'));
        usort($primary, static fn (array $a, array $b): int => (int) $a['sequence'] <=> (int) $b['sequence']);
        return array_map(static fn (array $index): string => (string) $index['columnName'], $primary);
    }

    private function assertWritable(): void
    {
        if ($this->readOnly) {
            throw new ApiException('read_only_policy', 'This SQL Canvas deployment is configured for read-only database access.', 403);
        }
    }

    /** @param list<array<string,mixed>> $rows @param list<array<string,mixed>> $columns @return list<array<string,mixed>> */
    private function normalizeBinaryRows(array $rows, array $columns): array
    {
        $binary=array_column(array_filter($columns,static fn(array $column):bool=>preg_match('/\b(?:BINARY|VARBINARY|BLOB)\b/i',(string)($column['type']??''))===1),'name');
        foreach($rows as &$row)foreach($binary as $column)if(array_key_exists($column,$row)&&$row[$column]!==null){$bytes=(string)$row[$column];$row[$column]=['__sqlCanvasBinary'=>true,'base64'=>base64_encode($bytes),'bytes'=>strlen($bytes)];}
        unset($row);
        return $rows;
    }
}

