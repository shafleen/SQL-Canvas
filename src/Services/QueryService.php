<?php

declare(strict_types=1);

namespace SqlCanvas\Services;

use PDOException;
use SqlCanvas\Database\DatabaseSession;
use SqlCanvas\Http\ApiException;

final readonly class QueryService
{
    public function __construct(
        private DatabaseSession $database,
        private bool $readOnly,
        private int $maximumRows = 500,
        private int $timeoutSeconds = 30,
    ) {
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function execute(array $input, bool $explain = false): array
    {
        $sql = trim(is_string($input['sql'] ?? null) ? $input['sql'] : '');
        if ($sql === '') {
            throw new ApiException('validation_failed', 'Enter a SQL statement to execute.', 422);
        }
        if (strlen($sql) > 1000000) {
            throw new ApiException('query_too_large', 'The SQL statement exceeds the configured request limit.', 413);
        }

        $classification = $this->classify($sql);
        if ($explain && !in_array($classification, ['SELECT', 'WITH'], true)) {
            throw new ApiException('explain_unsupported', 'EXPLAIN is available for SELECT statements in this workspace.', 422);
        }
        if ($this->readOnly && !in_array($classification, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'WITH'], true)) {
            throw new ApiException('read_only_policy', 'This SQL Canvas deployment is configured for read-only database access.', 403);
        }
        if (!$this->readOnly && !in_array($classification, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'WITH'], true)
            && ($input['confirmation'] ?? null) !== 'EXECUTE') {
            throw new ApiException('confirmation_required', 'Type EXECUTE to run a write, DDL, or privilege statement.', 422);
        }

        $pdo = $this->database->pdo();
        try {
            $version = (string) $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
            if (stripos($version, 'MariaDB') !== false) $pdo->exec('SET SESSION max_statement_time = ' . max(1, $this->timeoutSeconds));
            else $pdo->exec('SET SESSION MAX_EXECUTION_TIME = ' . (max(1, $this->timeoutSeconds) * 1000));
            if ($this->readOnly) {
                $pdo->exec('SET TRANSACTION READ ONLY');
                $pdo->beginTransaction();
            }
            $started = hrtime(true);
            $statement = $pdo->prepare($explain ? 'EXPLAIN ' . $sql : $sql);
            $statement->execute();
            $durationMs = round((hrtime(true) - $started) / 1_000_000, 2);

            if ($statement->columnCount() === 0) {
                return ['classification' => $classification, 'affectedRows' => $statement->rowCount(), 'durationMs' => $durationMs, 'columns' => [], 'rows' => []];
            }

            $columns = [];
            for ($index = 0; $index < $statement->columnCount(); $index++) {
                $meta = $statement->getColumnMeta($index) ?: [];
                $flags=is_array($meta['flags']??null)?$meta['flags']:[];$native=(string)($meta['native_type']??'');
                $columns[] = ['name' => (string) ($meta['name'] ?? $index), 'nativeType' => $native ?: null, 'binary' => preg_match('/BLOB|BINARY/i',$native)===1||in_array('blob',$flags,true)||in_array('binary',$flags,true)];
            }
            $rows = [];
            while (count($rows) <= $this->maximumRows && ($row = $statement->fetch()) !== false) $rows[] = $row;
            $truncated = count($rows) > $this->maximumRows;
            if ($truncated) array_pop($rows);
            foreach($rows as &$row)foreach($columns as $column)if($column['binary']===true&&array_key_exists($column['name'],$row)&&$row[$column['name']]!==null){$bytes=(string)$row[$column['name']];$row[$column['name']]=['__sqlCanvasBinary'=>true,'base64'=>base64_encode($bytes),'bytes'=>strlen($bytes)];}
            unset($row);

            return [
                'classification' => $explain ? 'EXPLAIN' : $classification,
                'affectedRows' => null,
                'durationMs' => $durationMs,
                'columns' => $columns,
                'rows' => $rows,
                'truncated' => $truncated,
            ];
        } catch (PDOException $exception) {
            $driverMessage = is_array($exception->errorInfo) && isset($exception->errorInfo[2]) ? (string) $exception->errorInfo[2] : 'The server rejected the statement.';
            $safe = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $driverMessage) ?? 'The server rejected the statement.';
            $safe = preg_replace('/(?i)(password|passwd|pwd)\s*[=:]\s*\S+/', '$1=[redacted]', $safe) ?? $safe;
            throw new ApiException('database_statement_error', substr($safe, 0, 500), 422);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }

    private function classify(string $sql): string
    {
        $withoutComments = preg_replace('/\A(?:\s|--[^\n]*(?:\n|\z)|#[^\n]*(?:\n|\z)|\/\*.*?\*\/)+/s', '', $sql) ?? $sql;
        return preg_match('/\A([A-Za-z]+)/', ltrim($withoutComments), $match) === 1 ? strtoupper($match[1]) : 'UNKNOWN';
    }
}

