<?php

declare(strict_types=1);

namespace SqlCanvas\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SqlCanvas\Auth\SessionAuth;
use SqlCanvas\Database\ConnectionManager;
use SqlCanvas\Database\ConnectionProfile;
use SqlCanvas\Database\DatabaseSession;
use SqlCanvas\Database\DestinationPolicy;
use SqlCanvas\Database\MetadataReader;
use SqlCanvas\Services\AdministrationService;
use SqlCanvas\Services\CanvasService;
use SqlCanvas\Services\DataService;
use SqlCanvas\Services\DatabaseObjectService;
use SqlCanvas\Services\DatabaseService;
use SqlCanvas\Services\ImportExportService;
use SqlCanvas\Services\QueryService;
use SqlCanvas\Services\SchemaService;
use SqlCanvas\Support\Config;

#[Group('database')]
final class FullWorkflowTest extends TestCase
{
    private string $schema;
    private DatabaseSession $database;
    private SchemaService $schemaService;

    protected function setUp(): void
    {
        if (getenv('SQL_CANVAS_TEST_DATABASE') === false) self::markTestSkipped('Disposable database environment is not configured.');
        $this->schema = (string) getenv('SQL_CANVAS_TEST_DATABASE');
        $host = getenv('SQL_CANVAS_TEST_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('SQL_CANVAS_TEST_PORT') ?: 3306);
        $config = new Config(['security' => [
            'allowed_connection_hosts' => [$host], 'allowed_connection_ports' => [$port],
            'require_tls_for_remote' => false, 'allow_socket_connections' => false,
            'session' => ['name' => 'sql_canvas_integration', 'same_site' => 'Strict', 'idle_timeout_seconds' => 1800, 'absolute_timeout_seconds' => 3600],
        ]]);
        $profile = new ConnectionProfile('Fixture', $host, $port, getenv('SQL_CANVAS_TEST_USER') ?: '', getenv('SQL_CANVAS_TEST_PASSWORD') ?: '', $this->schema, null, false, null);
        $manager = new ConnectionManager(new DestinationPolicy($config));
        $auth = new SessionAuth($config);
        $auth->start();
        $auth->establish($profile, []);
        $this->database = new DatabaseSession($manager, $auth);
        $this->schemaService = new SchemaService($this->database, new MetadataReader(), false);
    }

    public function testStructuredSchemaDataQueryCanvasImportExportAndAdministration(): void
    {
        $table = 'workflow_records';
        $importTable = 'workflow_sql_import';
        $view = 'workflow_record_names';
        $event = 'workflow_fixture_event';
        $drop = ['operation' => 'DROP_TABLE', 'database' => $this->schema, 'table' => $table, 'confirmation' => $this->schema . '.' . $table];
        try {
            try { $this->schemaService->apply($drop); } catch (\Throwable) { }
            try { $this->database->pdo()->exec("DROP VIEW `{$this->schema}`.`{$view}`"); } catch (\Throwable) { }
            try { $this->database->pdo()->exec("DROP TABLE `{$this->schema}`.`{$importTable}`"); } catch (\Throwable) { }
            try { $this->database->pdo()->exec("DROP EVENT `{$this->schema}`.`{$event}`"); } catch (\Throwable) { }
            $created = $this->schemaService->apply([
                'operation' => 'CREATE_TABLE', 'database' => $this->schema, 'table' => $table,
                'engine' => 'InnoDB', 'charset' => 'utf8mb4', 'confirmation' => 'CREATE',
                'columns' => [
                    ['name' => 'id', 'type' => 'INT', 'unsigned' => true, 'nullable' => false, 'autoIncrement' => true, 'primary' => true],
                    ['name' => 'label', 'type' => 'VARCHAR', 'size' => '100', 'nullable' => false],
                    ['name' => 'amount', 'type' => 'DECIMAL', 'size' => '10,2', 'nullable' => true],
                    ['name' => 'payload', 'type' => 'BLOB', 'nullable' => true],
                ],
            ]);
            self::assertStringContainsString('CREATE TABLE', $created['sql']);

            $this->schemaService->apply([
                'operation' => 'ADD_COLUMN', 'database' => $this->schema, 'table' => $table,
                'columnDefinition' => ['name' => 'note', 'type' => 'VARCHAR', 'size' => '80', 'nullable' => true],
                'confirmation' => 'APPLY',
            ]);
            $this->schemaService->apply([
                'operation' => 'MODIFY_COLUMN', 'database' => $this->schema, 'table' => $table,
                'columnDefinition' => ['name' => 'note', 'type' => 'VARCHAR', 'size' => '120', 'nullable' => true],
                'confirmation' => 'APPLY',
            ]);
            $this->schemaService->apply([
                'operation' => 'ADD_COLUMN', 'database' => $this->schema, 'table' => $table,
                'columnDefinition' => ['name' => 'label_length', 'type' => 'INT', 'generatedExpression' => 'CHAR_LENGTH(`label`)', 'generatedStored' => false],
                'confirmation' => 'APPLY',
            ]);
            $this->schemaService->apply([
                'operation' => 'ADD_INDEX', 'database' => $this->schema, 'table' => $table,
                'name' => 'idx_workflow_label', 'columns' => ['label'], 'confirmation' => 'APPLY',
            ]);
            self::assertContains('note', array_column((new MetadataReader())->tableDetails($this->database->pdo(), $this->schema, $table)['columns'], 'name'));
            self::assertContains('label_length', array_column((new MetadataReader())->tableDetails($this->database->pdo(), $this->schema, $table)['columns'], 'name'));

            $data = new DataService($this->database, new MetadataReader(), false);
            $inserted = $data->insert($this->schema, $table, ['values' => ['label' => 'Alpha', 'amount' => '12.50', 'payload' => "\0\xFF"]]);
            self::assertSame(1, $inserted['affectedRows']);
            $page = $data->rows($this->schema, $table, ['pageSize' => '20']);
            self::assertTrue($page['writable']);
            self::assertSame('Alpha', $page['rows'][0]['label']);
            self::assertTrue($page['rows'][0]['payload']['__sqlCanvasBinary']);
            self::assertSame(2, $page['rows'][0]['payload']['bytes']);
            $row = $page['rows'][0];
            $data->update($this->schema, $table, ['identity' => ['id' => $row['id']], 'changes' => ['label' => 'Beta'], 'original' => $row]);

            $query = new QueryService($this->database, true);
            $result = $query->execute(['sql' => "SELECT label, amount FROM `{$this->schema}`.`{$table}`"]);
            self::assertSame('Beta', $result['rows'][0]['label']);
            $binaryResult = $query->execute(['sql' => "SELECT payload FROM `{$this->schema}`.`{$table}` WHERE label = 'Beta'"]);
            self::assertSame(2, $binaryResult['rows'][0]['payload']['bytes']);

            $imports = new ImportExportService($this->database, new MetadataReader(), false);
            $csv = "label,amount\nGamma,9.75\n";
            $preview = $imports->previewCsv($this->schema, $table, ['csv' => $csv, 'delimiter' => ',']);
            self::assertTrue($preview['canImport']);
            self::assertSame(1, $imports->importCsv($this->schema, $table, ['csv' => $csv, 'delimiter' => ',', 'confirmation' => $this->schema . '.' . $table])['insertedRows']);
            $firstCursorPage=$data->rows($this->schema,$table,['pageSize'=>'1']);
            $secondCursorPage=$data->rows($this->schema,$table,['pageSize'=>'1','page'=>'2','cursor'=>$firstCursorPage['nextCursor']]);
            self::assertSame('keyset',$firstCursorPage['paginationMode']);
            self::assertNotSame($firstCursorPage['rows'][0]['id'],$secondCursorPage['rows'][0]['id']);
            self::assertStringContainsString('Gamma', $imports->exportTable($this->schema, $table, 'csv')['content']);
            self::assertStringContainsString('base64:AP8=', $imports->exportTable($this->schema, $table, 'json')['content']);
            $databaseExport = $imports->exportDatabaseSql($this->schema, 'both', 100);
            self::assertStringContainsString("`{$this->schema}`.`{$table}`", $databaseExport['content']);
            $sqlImport = "CREATE TABLE `{$importTable}` (`id` INT NOT NULL PRIMARY KEY); INSERT INTO `{$importTable}` VALUES (7);";
            self::assertSame(2, $imports->previewSql($this->schema, ['sql' => $sqlImport])['statementCount']);
            self::assertSame(2, $imports->importSql($this->schema, ['sql' => $sqlImport, 'confirmation' => 'IMPORT ' . $this->schema])['executedStatements']);
            self::assertSame(7, (int) $this->database->pdo()->query("SELECT id FROM `{$this->schema}`.`{$importTable}`")->fetchColumn());

            $this->database->pdo()->exec("CREATE VIEW `{$this->schema}`.`{$view}` AS SELECT `id`, `label` FROM `{$this->schema}`.`{$table}`");
            $objects = new DatabaseObjectService($this->database, new MetadataReader(), false);
            self::assertStringContainsString('CREATE', strtoupper((string) $objects->definition($this->schema, 'view', $view)['definition']));
            $this->database->pdo()->exec(
                "CREATE EVENT `{$this->schema}`.`{$event}` ON SCHEDULE EVERY 1 DAY "
                . "STARTS CURRENT_TIMESTAMP + INTERVAL 1 DAY DISABLE "
                . "DO DELETE FROM `{$this->schema}`.`{$table}` WHERE `id` = 0"
            );
            self::assertSame('DISABLED', $objects->definition($this->schema, 'event', $event)['status']);
            self::assertSame(
                'ENABLED',
                $objects->setEventStatus($this->schema, $event, [
                    'status' => 'ENABLE',
                    'confirmation' => 'ENABLE ' . $this->schema . '.' . $event,
                ])['status'],
            );
            self::assertSame(
                'DISABLED',
                $objects->setEventStatus($this->schema, $event, [
                    'status' => 'DISABLE',
                    'confirmation' => 'DISABLE ' . $this->schema . '.' . $event,
                ])['status'],
            );

            $graph = (new CanvasService($this->database))->graph($this->schema);
            self::assertContains($table, array_column($graph['tables'], 'name'));
            $databases = new DatabaseService($this->database, new MetadataReader(), false);
            self::assertGreaterThanOrEqual(4, $databases->statistics($this->schema)['tableCount']);
            self::assertSame('COPY ' . $this->schema . ' TO sql_canvas_test_copy_preview', $databases->previewCopy(['source' => $this->schema, 'target' => 'sql_canvas_test_copy_preview', 'includeData' => false])['confirmation']);
            $overview = (new AdministrationService($this->database, true))->overview();
            self::assertArrayHasKey('Uptime', $overview['status']);
            $administration = new AdministrationService($this->database, true);
            $currentAccount = (string) $this->database->pdo()->query('SELECT CURRENT_USER()')->fetchColumn();
            [$currentUsername, $currentHost] = explode('@', $currentAccount, 2);
            self::assertNotEmpty($administration->grants(['username' => $currentUsername, 'host' => $currentHost])['grants']);
            $variables = $administration->variables('max_connections');
            self::assertContains('max_connections', array_map(
                static fn (array $row): string => strtolower((string) ($row['Variable_name'] ?? array_values($row)[0] ?? '')),
                $variables['variables'],
            ));
            self::assertContains('max_connections', $variables['editable']);

            $latest = $data->rows($this->schema, $table, ['pageSize' => '20']);
            $beta = array_values(array_filter($latest['rows'], static fn (array $item): bool => $item['label'] === 'Beta'))[0];
            $gamma = array_values(array_filter($latest['rows'], static fn (array $item): bool => $item['label'] === 'Gamma'))[0];
            self::assertSame(1, $data->bulkDelete($this->schema, $table, ['identities' => [['id' => $gamma['id']]], 'confirmation' => $this->schema . '.' . $table . ':1'])['affectedRows']);
            self::assertSame(1, $data->delete($this->schema, $table, ['identity' => ['id' => $beta['id']], 'confirmation' => $this->schema . '.' . $table])['affectedRows']);
        } finally {
            try { $this->database->pdo()->exec("DROP VIEW `{$this->schema}`.`{$view}`"); } catch (\Throwable) { }
            try { $this->database->pdo()->exec("DROP EVENT `{$this->schema}`.`{$event}`"); } catch (\Throwable) { }
            try { $this->database->pdo()->exec("DROP TABLE `{$this->schema}`.`{$importTable}`"); } catch (\Throwable) { }
            try { $this->schemaService->apply($drop); } catch (\Throwable) { }
        }
    }
}

