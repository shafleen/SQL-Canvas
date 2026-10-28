<?php

declare(strict_types=1);

namespace SqlCanvas\Services;

use PDOException;
use SqlCanvas\Database\DatabaseSession;
use SqlCanvas\Http\ApiException;
use SqlCanvas\Database\SqlIdentifier;

final readonly class AdministrationService
{
    private const PRIVILEGES = ['SELECT','INSERT','UPDATE','DELETE','CREATE','ALTER','DROP','INDEX','REFERENCES','EXECUTE','EVENT','TRIGGER','SHOW VIEW','CREATE VIEW','CREATE ROUTINE','ALTER ROUTINE'];
    private const EDITABLE_GLOBALS = [
        'max_connections',
        'event_scheduler',
        'slow_query_log',
        'long_query_time',
    ];
    public function __construct(private DatabaseSession $database, private bool $readOnly)
    {
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $pdo = $this->database->pdo();
        $status = $this->keyValue($pdo->query('SHOW GLOBAL STATUS')->fetchAll());
        $variables = $this->keyValue($pdo->query('SHOW VARIABLES')->fetchAll());
        $wantedStatus = ['Uptime', 'Threads_connected', 'Threads_running', 'Questions', 'Slow_queries', 'Bytes_received', 'Bytes_sent', 'Connections', 'Aborted_connects'];
        $wantedVariables = ['version', 'version_comment', 'hostname', 'port', 'max_connections', 'character_set_server', 'collation_server', 'sql_mode', 'event_scheduler', 'read_only'];
        return [
            'status' => array_intersect_key($status, array_flip($wantedStatus)),
            'variables' => array_intersect_key($variables, array_flip($wantedVariables)),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function processes(): array
    {
        return $this->database->pdo()->query('SHOW FULL PROCESSLIST')->fetchAll();
    }

    /** @return array<string,mixed> */
    public function variables(string $filter = ''): array
    {
        $rows = $this->database->pdo()->query('SHOW GLOBAL VARIABLES')->fetchAll();
        $filter = strtolower(trim($filter));
        if ($filter !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => str_contains(
                    strtolower((string) ($row['Variable_name'] ?? array_values($row)[0] ?? '')),
                    $filter,
                ),
            ));
        }

        return [
            'variables' => array_slice($rows, 0, 1000),
            'editable' => self::EDITABLE_GLOBALS,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function setGlobalVariable(array $input): array
    {
        $this->assertWritable();
        $name = strtolower(trim((string) ($input['name'] ?? '')));
        if (!in_array($name, self::EDITABLE_GLOBALS, true)) {
            throw new ApiException('variable_not_editable', 'This global variable is not editable through SQL Canvas.', 422);
        }
        if (($input['confirmation'] ?? null) !== 'SET GLOBAL ' . $name) {
            throw new ApiException('confirmation_required', "Type SET GLOBAL {$name} to confirm a server-wide change.", 422);
        }

        $raw = trim((string) ($input['value'] ?? ''));
        $value = match ($name) {
            'max_connections' => (string) $this->boundedNumber($raw, 1, 100000, true),
            'long_query_time' => (string) $this->boundedNumber($raw, 0, 86400, false),
            'event_scheduler', 'slow_query_log' => in_array(strtoupper($raw), ['ON', 'OFF'], true)
                ? strtoupper($raw)
                : throw new ApiException('invalid_variable_value', 'Use ON or OFF for this variable.', 422),
        };
        $sql = 'SET GLOBAL ' . $name . ' = ' . $value;
        $this->database->pdo()->exec($sql);

        return ['name' => $name, 'value' => $value, 'scope' => 'GLOBAL', 'sql' => $sql];
    }

    /** @return list<array<string, mixed>> */
    public function users(): array
    {
        try {
            // Keep this projection to columns shared by supported MySQL and MariaDB
            // releases. Vendor-specific account state is exposed through SHOW CREATE
            // USER in a later, explicitly requested detail view.
            return $this->database->pdo()->query('SELECT User AS user, Host AS host, plugin FROM mysql.user ORDER BY User, Host')->fetchAll();
        } catch (PDOException) {
            throw new ApiException('permission_denied', 'The connected account cannot read database user metadata.', 403);
        }
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function kill(array $input): array
    {
        $this->assertWritable();
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || ($input['confirmation'] ?? null) !== (string) $id) throw new ApiException('confirmation_required', 'Type the process ID to confirm termination.', 422);
        $this->database->pdo()->exec('KILL ' . (int) $id);
        return ['terminatedProcess' => (int) $id];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function createUser(array $input): array
    {
        $this->assertWritable();
        $user = $this->accountPart($input['username'] ?? null, 'username');
        $host = $this->accountPart($input['host'] ?? '%', 'host');
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        if (strlen($password) < 12) throw new ApiException('weak_password', 'Use a password containing at least 12 characters.', 422);
        $target = $user . '@' . $host;
        if (($input['confirmation'] ?? null) !== $target) throw new ApiException('confirmation_required', "Type {$target} to confirm account creation.", 422);
        $pdo = $this->database->pdo();
        $sql = sprintf('CREATE USER %s@%s IDENTIFIED BY %s', $pdo->quote($user), $pdo->quote($host), $pdo->quote($password));
        $pdo->exec($sql);
        return ['account' => $target];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function dropUser(array $input): array
    {
        $this->assertWritable();
        $user = $this->accountPart($input['username'] ?? null, 'username');
        $host = $this->accountPart($input['host'] ?? null, 'host');
        $target = $user . '@' . $host;
        if (($input['confirmation'] ?? null) !== $target) throw new ApiException('confirmation_required', "Type {$target} to confirm account removal.", 422);
        $pdo = $this->database->pdo();
        $pdo->exec(sprintf('DROP USER %s@%s', $pdo->quote($user), $pdo->quote($host)));
        return ['removedAccount' => $target];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function grants(array $input): array
    {
        $user = $this->accountPart($input['username'] ?? null, 'username');
        $host = $this->accountPart($input['host'] ?? null, 'host');
        $pdo = $this->database->pdo();
        try {
            $rows = $pdo->query(sprintf('SHOW GRANTS FOR %s@%s', $pdo->quote($user), $pdo->quote($host)))->fetchAll(\PDO::FETCH_COLUMN);
        } catch (PDOException) {
            throw new ApiException('permission_denied', 'The connected account cannot inspect privileges for this user.', 403);
        }
        return ['account' => $user . '@' . $host, 'grants' => array_map('strval', $rows), 'availablePrivileges' => self::PRIVILEGES];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function changePrivileges(array $input, bool $revoke): array
    {
        $this->assertWritable();
        $user = $this->accountPart($input['username'] ?? null, 'username');
        $host = $this->accountPart($input['host'] ?? null, 'host');
        $privileges = is_array($input['privileges'] ?? null) ? array_values(array_unique(array_map(static fn (mixed $value): string => strtoupper(trim((string) $value)), $input['privileges']))) : [];
        if ($privileges === [] || array_diff($privileges, self::PRIVILEGES) !== []) throw new ApiException('invalid_privileges', 'Choose one or more supported privileges.', 422);
        $scope = strtolower((string) ($input['scope'] ?? 'database'));
        $database = trim((string) ($input['database'] ?? ''));
        $table = trim((string) ($input['table'] ?? ''));
        $targetScope = match ($scope) {
            'global' => '*.*',
            'database' => SqlIdentifier::quote($this->identifierPart($database, 'database')) . '.*',
            'table' => SqlIdentifier::qualified($this->identifierPart($database, 'database'), $this->identifierPart($table, 'table')),
            default => throw new ApiException('invalid_scope', 'Choose global, database, or table privilege scope.', 422),
        };
        $account = $user . '@' . $host;
        $verb = $revoke ? 'REVOKE' : 'GRANT';
        if (($input['confirmation'] ?? null) !== $verb . ' ' . $account) throw new ApiException('confirmation_required', "Type {$verb} {$account} to confirm the privilege change.", 422);
        $pdo = $this->database->pdo();
        $sql = sprintf('%s %s ON %s %s %s@%s', $verb, implode(', ', $privileges), $targetScope, $revoke ? 'FROM' : 'TO', $pdo->quote($user), $pdo->quote($host));
        $pdo->exec($sql);
        return ['account' => $account, 'scope' => $targetScope, 'privileges' => $privileges, 'action' => strtolower($verb)];
    }

    /** @param list<array<string,mixed>> $rows @return array<string,string> */
    private function keyValue(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $values = array_values($row);
            if (isset($values[0], $values[1])) $result[(string) $values[0]] = (string) $values[1];
        }
        return $result;
    }

    private function accountPart(mixed $value, string $label): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > 255 || str_contains($value, "\0")) throw new ApiException('validation_failed', "Enter a valid account {$label}.", 422);
        return trim($value);
    }

    private function identifierPart(string $value, string $label): string
    {
        if ($value === '' || strlen($value) > 64 || str_contains($value, "\0")) throw new ApiException('invalid_identifier', "Enter a valid {$label} identifier.", 422);
        return $value;
    }

    private function boundedNumber(string $value, float $minimum, float $maximum, bool $integer): int|float
    {
        if (!is_numeric($value)) {
            throw new ApiException('invalid_variable_value', 'Enter a numeric variable value.', 422);
        }
        $number = $integer ? (int) $value : (float) $value;
        if ($number < $minimum || $number > $maximum) {
            throw new ApiException('invalid_variable_value', "Enter a value from {$minimum} to {$maximum}.", 422);
        }
        return $number;
    }

    private function assertWritable(): void
    {
        if ($this->readOnly) throw new ApiException('read_only_policy', 'This SQL Canvas deployment is configured for read-only database access.', 403);
    }
}

