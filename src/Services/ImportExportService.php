<?php

declare(strict_types=1);

namespace SqlCanvas\Services;

use PDO;
use PDOException;
use SqlCanvas\Database\DatabaseSession;
use SqlCanvas\Database\MetadataReader;
use SqlCanvas\Database\SqlIdentifier;
use SqlCanvas\Http\ApiException;

final readonly class ImportExportService
{
    public function __construct(private DatabaseSession $database, private MetadataReader $metadata, private bool $readOnly)
    {
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function previewCsv(string $schema, string $table, array $input): array
    {
        $csv = is_string($input['csv'] ?? null) ? $input['csv'] : '';
        if ($csv === '' || strlen($csv) > 5_000_000) throw new ApiException('invalid_import', 'Provide a CSV file no larger than 5 MB for browser import.', 422);
        $delimiter = is_string($input['delimiter'] ?? null) && strlen($input['delimiter']) === 1 ? $input['delimiter'] : ',';
        $rows = $this->parseCsv($csv, $delimiter);
        if (count($rows) < 2) throw new ApiException('invalid_import', 'The CSV must contain a header and at least one data row.', 422);
        $headers = array_shift($rows);
        $columns = array_column($this->metadata->tableDetails($this->database->pdo(), $schema, $table)['columns'], 'name');
        $unknown = array_values(array_diff($headers, $columns));
        return ['headers' => $headers, 'rowCount' => count($rows), 'preview' => array_slice($rows, 0, 20), 'unknownHeaders' => $unknown, 'canImport' => $unknown === []];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function importCsv(string $schema, string $table, array $input): array
    {
        if ($this->readOnly) throw new ApiException('read_only_policy', 'This SQL Canvas deployment is configured for read-only database access.', 403);
        if (($input['confirmation'] ?? null) !== $schema . '.' . $table) throw new ApiException('confirmation_required', 'Type the qualified table name to confirm CSV import.', 422);
        $preview = $this->previewCsv($schema, $table, $input);
        if (!$preview['canImport']) throw new ApiException('invalid_mapping', 'Every CSV header must match a real table column.', 422, ['unknownHeaders' => $preview['unknownHeaders']]);
        $rows = $this->parseCsv((string) $input['csv'], is_string($input['delimiter'] ?? null) ? $input['delimiter'] : ',');
        $headers = array_shift($rows);
        $placeholders = array_map(static fn (int $index): string => ':v' . $index, array_keys($headers));
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', SqlIdentifier::qualified($schema, $table), implode(', ', array_map([SqlIdentifier::class, 'quote'], $headers)), implode(', ', $placeholders));
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare($sql);
        $inserted = 0;
        $pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                if (count($row) !== count($headers)) throw new ApiException('invalid_import_row', 'A CSV row does not match the header column count.', 422, ['row' => $inserted + 2]);
                $values = [];
                foreach ($row as $index => $value) $values[':v' . $index] = $value === (string) ($input['nullValue'] ?? '\\N') ? null : $value;
                $statement->execute($values);
                $inserted++;
            }
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
        return ['insertedRows' => $inserted];
    }

    /** @return array<string, mixed> */
    public function exportTable(string $schema, string $table, string $format, int $limit = 10000): array
    {
        $pdo = $this->database->pdo();
        $details = $this->metadata->tableDetails($pdo, $schema, $table);
        $limit = min(10000, max(1, $limit));
        $rows = $pdo->query(sprintf('SELECT * FROM %s LIMIT %d', SqlIdentifier::qualified($schema, $table), $limit))->fetchAll();
        $binaryColumns=array_column(array_filter($details['columns'],static fn(array $column):bool=>preg_match('/\b(?:BINARY|VARBINARY|BLOB)\b/i',(string)($column['type']??''))===1),'name');
        foreach($rows as &$row)foreach($binaryColumns as $column)if(array_key_exists($column,$row)&&$row[$column]!==null)$row[$column]='base64:'.base64_encode((string)$row[$column]);
        unset($row);
        if ($format === 'json') return ['format' => 'json', 'filename' => "{$schema}-{$table}.json", 'content' => json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'truncatedAt' => $limit];
        if ($format !== 'csv') throw new ApiException('unsupported_export', 'Choose CSV or JSON export.', 422);
        $handle = fopen('php://temp', 'w+');
        $headers = $rows === [] ? array_column($details['columns'], 'name') : array_keys($rows[0]);
        fputcsv($handle, $headers, escape: '');
        foreach ($rows as $row) {
            $safe = array_map(static function (mixed $value): mixed {
                if (!is_string($value)) return $value;
                return preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : $value;
            }, $row);
            fputcsv($handle, $safe, escape: '');
        }
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);
        return ['format' => 'csv', 'filename' => "{$schema}-{$table}.csv", 'content' => $content, 'truncatedAt' => $limit];
    }

    /** @return array<string,mixed> */
    public function exportDatabaseSql(string $schema, string $mode = 'both', int $rowLimit = 5000): array
    {
        if (!in_array($mode, ['structure', 'data', 'both'], true)) throw new ApiException('unsupported_export', 'Choose structure, data, or both for SQL export.', 422);
        $pdo = $this->database->pdo();
        $objects = $this->metadata->schemaObjects($pdo, $schema);
        $rowLimit = min(20000, max(1, $rowLimit));
        $lines = [
            '-- SQL Canvas database export',
            '-- Database: ' . str_replace(["\r", "\n"], '', $schema),
            'SET NAMES utf8mb4;',
            'SET FOREIGN_KEY_CHECKS=0;',
            '',
        ];
        $truncated = [];
        if ($mode !== 'data') {
            foreach ($objects['tables'] as $table) {
                $name = (string) $table['name'];
                $create = $pdo->query('SHOW CREATE TABLE ' . SqlIdentifier::qualified($schema, $name))->fetch();
                if (is_array($create)) $lines[] = (string) array_values($create)[1] . ';' . "\n";
            }
            foreach ($objects['views'] as $view) {
                $name = (string) $view['name'];
                $create = $pdo->query('SHOW CREATE VIEW ' . SqlIdentifier::qualified($schema, $name))->fetch();
                if (is_array($create)) $lines[] = (string) array_values($create)[1] . ';' . "\n";
            }
        }
        if ($mode !== 'structure') {
            foreach ($objects['tables'] as $table) {
                $name = (string) $table['name'];
                $qualified = SqlIdentifier::qualified($schema, $name);
                $rows = $pdo->query(sprintf('SELECT * FROM %s LIMIT %d', $qualified, $rowLimit + 1))->fetchAll();
                if (count($rows) > $rowLimit) { array_pop($rows); $truncated[] = $name; }
                foreach (array_chunk($rows, 100) as $batch) {
                    if ($batch === []) continue;
                    $columns = array_keys($batch[0]);
                    $values = array_map(fn (array $row): string => '(' . implode(', ', array_map(fn (string $column): string => $this->sqlLiteral($pdo, $row[$column]), $columns)) . ')', $batch);
                    $lines[] = sprintf('INSERT INTO %s (%s) VALUES\n%s;', $qualified, implode(', ', array_map([SqlIdentifier::class, 'quote'], $columns)), implode(",\n", $values));
                }
                if ($rows !== []) $lines[] = '';
            }
        }
        $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';
        return ['format' => 'sql', 'filename' => "{$schema}-{$mode}.sql", 'content' => implode("\n", $lines), 'mode' => $mode, 'rowLimitPerTable' => $rowLimit, 'truncatedTables' => $truncated];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function previewSql(string $schema, array $input): array
    {
        $sql = $this->sqlInput($input);
        $this->metadata->schemaObjects($this->database->pdo(), $schema);
        $statements = $this->splitSql($sql);
        $destructive = array_values(array_filter($statements, static fn (string $statement): bool => preg_match('/\A\s*(DROP|TRUNCATE|DELETE|UPDATE|ALTER|REPLACE)\b/i', $statement) === 1));
        return ['statementCount' => count($statements), 'destructiveStatementCount' => count($destructive), 'sample' => array_slice($statements, 0, 5), 'confirmation' => 'IMPORT ' . $schema];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function importSql(string $schema, array $input): array
    {
        if ($this->readOnly) throw new ApiException('read_only_policy', 'This SQL Canvas deployment is configured for read-only database access.', 403);
        if (($input['confirmation'] ?? null) !== 'IMPORT ' . $schema) throw new ApiException('confirmation_required', 'Type IMPORT followed by the database name to confirm SQL execution.', 422);
        $sql = $this->sqlInput($input);
        $statements = $this->splitSql($sql);
        $pdo = $this->database->pdo();
        $pdo->exec('USE ' . SqlIdentifier::quote($schema));
        $executed = 0;
        try {
            foreach ($statements as $statement) { $pdo->exec($statement); $executed++; }
        } catch (PDOException $exception) {
            $message = is_array($exception->errorInfo) && isset($exception->errorInfo[2]) ? (string) $exception->errorInfo[2] : 'The database rejected a statement.';
            $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message) ?? 'The database rejected a statement.';
            throw new ApiException('sql_import_failed', substr($message, 0, 500), 422, ['completedStatements' => $executed, 'failedStatement' => $executed + 1]);
        }
        return ['executedStatements' => $executed];
    }

    /** @return list<list<string>> */
    private function parseCsv(string $csv, string $delimiter): array
    {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, $csv);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) $rows[] = array_map(static fn (mixed $value): string => (string) $value, $row);
        fclose($handle);
        return $rows;
    }

    private function sqlLiteral(PDO $pdo, mixed $value): string
    {
        if ($value === null) return 'NULL';
        if (is_int($value) || is_float($value)) return (string) $value;
        $string = (string) $value;
        if (preg_match('//u', $string) !== 1) return '0x' . bin2hex($string);
        return $pdo->quote($string);
    }

    /** @param array<string,mixed> $input */
    private function sqlInput(array $input): string
    {
        $sql = is_string($input['sql'] ?? null) ? $input['sql'] : '';
        if ($sql === '' || strlen($sql) > 5_000_000) throw new ApiException('invalid_import', 'Provide a SQL file no larger than 5 MB.', 422);
        if (preg_match('/^\s*DELIMITER\b/im', $sql) === 1) throw new ApiException('unsupported_delimiter', 'DELIMITER scripts require the query workspace because browser SQL import uses standard semicolon separators.', 422);
        return $sql;
    }

    /** @return list<string> */
    private function splitSql(string $sql): array
    {
        $statements = []; $buffer = ''; $quote = null; $lineComment = false; $blockComment = false; $length = strlen($sql);
        for ($index = 0; $index < $length; $index++) {
            $char = $sql[$index]; $next = $index + 1 < $length ? $sql[$index + 1] : '';
            if ($lineComment) { $buffer .= $char; if ($char === "\n") $lineComment = false; continue; }
            if ($blockComment) { $buffer .= $char; if ($char === '*' && $next === '/') { $buffer .= '/'; $index++; $blockComment = false; } continue; }
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $next !== '') { $buffer .= $next; $index++; continue; }
                if ($char === $quote) { if ($next === $quote) { $buffer .= $next; $index++; } else $quote = null; }
                continue;
            }
            if (($char === '-' && $next === '-' && ($index + 2 >= $length || ctype_space($sql[$index + 2]))) || $char === '#') { $lineComment = true; $buffer .= $char; continue; }
            if ($char === '/' && $next === '*') { $blockComment = true; $buffer .= '/*'; $index++; continue; }
            if ($char === "'" || $char === '"' || $char === '`') { $quote = $char; $buffer .= $char; continue; }
            if ($char === ';') { if (trim($buffer) !== '') $statements[] = trim($buffer); $buffer = ''; if (count($statements) > 500) throw new ApiException('too_many_statements', 'SQL import is limited to 500 statements per request.', 422); continue; }
            $buffer .= $char;
        }
        if ($quote !== null || $blockComment) throw new ApiException('invalid_import', 'The SQL file contains an unterminated quoted value or comment.', 422);
        if (trim($buffer) !== '') $statements[] = trim($buffer);
        if ($statements === []) throw new ApiException('invalid_import', 'The SQL file does not contain an executable statement.', 422);
        return $statements;
    }
}

