<?php

declare(strict_types=1);

namespace SqlCanvas\Services;

use SqlCanvas\Database\DatabaseSession;
use SqlCanvas\Database\MetadataReader;
use SqlCanvas\Database\SqlIdentifier;
use SqlCanvas\Http\ApiException;

final readonly class DatabaseObjectService
{
    public function __construct(private DatabaseSession $database, private MetadataReader $metadata, private bool $readOnly)
    {
    }

    /** @return array<string,mixed> */
    public function definition(string $schema, string $type, string $name): array
    {
        $pdo = $this->database->pdo();
        $this->metadata->schemaObjects($pdo, $schema);
        $type = strtolower($type);
        $map = [
            'view' => ['VIEWS', 'TABLE_SCHEMA', 'TABLE_NAME', 'VIEW'],
            'trigger' => ['TRIGGERS', 'TRIGGER_SCHEMA', 'TRIGGER_NAME', 'TRIGGER'],
            'event' => ['EVENTS', 'EVENT_SCHEMA', 'EVENT_NAME', 'EVENT'],
        ];
        if ($type === 'routine') {
            $statement = $pdo->prepare('SELECT ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = :schema AND ROUTINE_NAME = :name');
            $statement->execute(['schema' => $schema, 'name' => $name]);
            $routineType = strtoupper((string) $statement->fetchColumn());
            if (!in_array($routineType, ['PROCEDURE', 'FUNCTION'], true)) throw new ApiException('object_not_found', 'The selected routine is not visible to this account.', 404);
            $keyword = $routineType;
        } else {
            if (!isset($map[$type])) throw new ApiException('unsupported_object_type', 'Choose a view, routine, trigger, or event.', 422);
            [$table, $schemaColumn, $nameColumn, $keyword] = $map[$type];
            $statement = $pdo->prepare("SELECT 1 FROM information_schema.{$table} WHERE {$schemaColumn} = :schema AND {$nameColumn} = :name");
            $statement->execute(['schema' => $schema, 'name' => $name]);
            if ($statement->fetchColumn() === false) throw new ApiException('object_not_found', 'The selected database object is not visible to this account.', 404);
        }
        $row = $pdo->query('SHOW CREATE ' . $keyword . ' ' . SqlIdentifier::qualified($schema, $name))->fetch();
        if (!is_array($row)) throw new ApiException('definition_unavailable', 'The connected account cannot read this object definition.', 403);
        $create = '';
        foreach ($row as $key => $value) if (is_string($key) && str_starts_with(strtolower($key), 'create ')) { $create = (string) $value; break; }
        if ($create === '') $create = (string) (array_values($row)[2] ?? array_values($row)[1] ?? '');
        $result = ['database' => $schema, 'type' => strtolower($keyword), 'name' => $name, 'definition' => $create];
        if ($type === 'event') {
            $status = $pdo->prepare(
                'SELECT STATUS FROM information_schema.EVENTS WHERE EVENT_SCHEMA = :schema AND EVENT_NAME = :name'
            );
            $status->execute(['schema' => $schema, 'name' => $name]);
            $result['status'] = strtoupper((string) $status->fetchColumn());
        }
        return $result;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function drop(string $schema, string $type, string $name, array $input): array
    {
        if ($this->readOnly) throw new ApiException('read_only_policy', 'This SQL Canvas deployment is configured for read-only database access.', 403);
        $definition = $this->definition($schema, $type, $name);
        $target = $schema . '.' . $name;
        if (($input['confirmation'] ?? null) !== $target) throw new ApiException('confirmation_required', 'Type the qualified object name to confirm removal.', 422);
        $keyword = strtoupper((string) $definition['type']);
        $sql = 'DROP ' . $keyword . ' ' . SqlIdentifier::qualified($schema, $name);
        $this->database->pdo()->exec($sql);
        return ['removed' => $target, 'type' => strtolower($keyword), 'sql' => $sql];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function setEventStatus(string $schema, string $name, array $input): array
    {
        if ($this->readOnly) {
            throw new ApiException('read_only_policy', 'This SQL Canvas deployment is configured for read-only database access.', 403);
        }
        $definition = $this->definition($schema, 'event', $name);
        $status = strtoupper(trim((string) ($input['status'] ?? '')));
        if (!in_array($status, ['ENABLE', 'DISABLE'], true)) {
            throw new ApiException('invalid_event_status', 'Choose ENABLE or DISABLE for the event.', 422);
        }
        $target = $schema . '.' . $name;
        $expected = $status . ' ' . $target;
        if (($input['confirmation'] ?? null) !== $expected) {
            throw new ApiException('confirmation_required', "Type {$expected} to confirm the event change.", 422);
        }
        $sql = 'ALTER EVENT ' . SqlIdentifier::qualified($schema, $name) . ' ' . $status;
        $this->database->pdo()->exec($sql);

        return [
            'database' => $schema,
            'name' => $name,
            'status' => $status === 'ENABLE' ? 'ENABLED' : 'DISABLED',
            'previousStatus' => $definition['status'] ?? null,
            'sql' => $sql,
        ];
    }
}
