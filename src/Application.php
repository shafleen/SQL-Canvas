<?php

declare(strict_types=1);

namespace SqlCanvas;

use InvalidArgumentException;
use JsonException;
use SqlCanvas\Auth\RateLimiter;
use SqlCanvas\Auth\RateLimitException;
use SqlCanvas\Auth\SessionAuth;
use SqlCanvas\Database\CapabilityDetector;
use SqlCanvas\Database\ConnectionManager;
use SqlCanvas\Database\DestinationPolicy;
use SqlCanvas\Database\MetadataReader;
use SqlCanvas\Http\ApiException;
use SqlCanvas\Http\Csrf;
use SqlCanvas\Http\Request;
use SqlCanvas\Http\Response;
use SqlCanvas\Http\Router;
use SqlCanvas\Services\ConnectionService;
use SqlCanvas\Services\AdministrationService;
use SqlCanvas\Services\CanvasService;
use SqlCanvas\Services\DataService;
use SqlCanvas\Services\DatabaseObjectService;
use SqlCanvas\Services\DatabaseService;
use SqlCanvas\Services\ExplorerService;
use SqlCanvas\Services\ImportExportService;
use SqlCanvas\Services\QueryService;
use SqlCanvas\Services\SchemaService;
use SqlCanvas\Support\Config;
use SqlCanvas\Support\AuditLogger;
use Throwable;

final class Application
{
    private Router $router;
    private SessionAuth $session;
    private ConnectionService $connectionService;
    private ExplorerService $explorerService;
    private DataService $dataService;
    private QueryService $queryService;
    private SchemaService $schemaService;
    private CanvasService $canvasService;
    private AdministrationService $administrationService;
    private ImportExportService $importExportService;
    private DatabaseObjectService $databaseObjectService;
    private AuditLogger $audit;
    private DatabaseService $databaseService;

    public function __construct(
        private readonly string $root,
        private readonly Config $config,
    ) {
        $this->session = new SessionAuth($config);
        $manager = new ConnectionManager(new DestinationPolicy($config));
        $rateConfig = (array) $config->get('security.connection_attempts', []);
        $limiter = new RateLimiter(
            $root . '/storage/cache/connection-attempts',
            (int) ($rateConfig['limit'] ?? 5),
            (int) ($rateConfig['window_seconds'] ?? 60),
        );
        $this->connectionService = new ConnectionService(
            $manager,
            new CapabilityDetector(),
            $this->session,
            $limiter,
        );
        $this->explorerService = new ExplorerService($manager, new MetadataReader(), $this->session);
        $databaseSession = new \SqlCanvas\Database\DatabaseSession($manager, $this->session);
        $readOnly = $config->get('security.read_only', true) === true;
        $this->dataService = new DataService($databaseSession, new MetadataReader(), $readOnly);
        $this->queryService = new QueryService($databaseSession, $readOnly, 500, (int) $config->get('limits.query_timeout_seconds', 30));
        $this->schemaService = new SchemaService($databaseSession, new MetadataReader(), $readOnly);
        $this->canvasService = new CanvasService($databaseSession);
        $this->administrationService = new AdministrationService($databaseSession, $readOnly);
        $this->importExportService = new ImportExportService($databaseSession, new MetadataReader(), $readOnly);
        $this->databaseObjectService = new DatabaseObjectService($databaseSession, new MetadataReader(), $readOnly);
        $this->audit = new AuditLogger($root . '/storage/logs');
        $this->databaseService = new DatabaseService($databaseSession, new MetadataReader(), $readOnly);
        $this->router = new Router();
        $this->registerRoutes();
    }

    public function handle(Request $request): Response
    {
        $auditActorBefore = 'disconnected';
        try {
            $this->session->start();
            $beforeState = $this->session->publicState();
            $beforeConnection = is_array($beforeState['connection'] ?? null) ? $beforeState['connection'] : [];
            if (isset($beforeConnection['username'], $beforeConnection['host'])) $auditActorBefore = $beforeConnection['username'] . '@' . $beforeConnection['host'];
            if (strlen($request->body) > (int) $this->config->get('limits.request_bytes', 6_291_456)) {
                throw new ApiException('request_too_large', 'The request exceeds the configured upload limit.', 413);
            }
            $response = $this->router->dispatch($request);
        } catch (ApiException $exception) {
            $error = [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ];
            if ($exception->details !== []) {
                $error['details'] = $exception->details;
            }
            $response = Response::json([
                'ok' => false,
                'error' => $error,
                'requestId' => bin2hex(random_bytes(8)),
            ], $exception->status);
        } catch (RateLimitException $exception) {
            $response = Response::json([
                'ok' => false,
                'error' => [
                    'code' => 'rate_limited',
                    'message' => 'Too many connection attempts. Wait before trying again.',
                    'details' => ['retryAfter' => $exception->retryAfter],
                ],
                'requestId' => bin2hex(random_bytes(8)),
            ], 429);
        } catch (JsonException|InvalidArgumentException) {
            $response = Response::json([
                'ok' => false,
                'error' => ['code' => 'invalid_request', 'message' => 'The request body is not valid.'],
                'requestId' => bin2hex(random_bytes(8)),
            ], 400);
        } catch (Throwable) {
            $response = Response::json([
                'ok' => false,
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'SQL Canvas could not complete the request.',
                ],
                'requestId' => bin2hex(random_bytes(8)),
            ], 500);
        }

        if ($request->method === 'POST') {
            $state = $this->session->publicState();
            $connection = is_array($state['connection'] ?? null) ? $state['connection'] : [];
            $actor = isset($connection['username'], $connection['host']) ? $connection['username'] . '@' . $connection['host'] : $auditActorBefore;
            $this->audit->write($request->path, $response->status, ['actor' => $actor, 'client' => $request->clientIp]);
        }

        return new Response($response->body, $response->status, array_merge(
            $this->securityHeaders(),
            $response->headers,
        ));
    }

    private function registerRoutes(): void
    {
        $this->router->get('/', fn (): Response => $this->workspace());
        $this->router->get('/api', fn (): Response => $this->health());
        $this->router->get('/api/', fn (): Response => $this->health());
        $this->router->get('/api/health', fn (): Response => $this->health());
        $this->router->get('/api/session', fn (): Response => $this->success([
            ...$this->session->publicState(),
            'csrfToken' => Csrf::token(),
        ]));
        $this->router->post('/api/session/connect', function (Request $request): Response {
            Csrf::assertValid($request->header('x-csrf-token'));
            $state = $this->connectionService->connect($request->json(), $request->clientIp);
            return $this->success([...$state, 'csrfToken' => Csrf::rotate()]);
        });
        $this->router->post('/api/session/disconnect', function (Request $request): Response {
            Csrf::assertValid($request->header('x-csrf-token'));
            $state = $this->connectionService->disconnect();
            return $this->success([...$state, 'csrfToken' => Csrf::rotate()]);
        });
        $this->router->get('/api/server/info', fn (): Response => $this->connectedStateResponse(false));
        $this->router->get('/api/server/capabilities', fn (): Response => $this->connectedStateResponse(true));
        $this->router->get('/api/databases', fn (): Response => $this->success([
            'databases' => $this->explorerService->databases(),
        ]));
        $this->router->get('/api/databases/{database}/objects', fn (Request $request, array $params): Response => $this->success([
            'database' => $params['database'],
            'objects' => $this->explorerService->schemaObjects($params['database']),
        ]));
        $this->router->get('/api/databases/{database}/tables/{table}', fn (Request $request, array $params): Response => $this->success([
            'database' => $params['database'],
            'table' => $params['table'],
            ...$this->explorerService->tableDetails($params['database'], $params['table']),
        ]));
        $this->router->get('/api/databases/{database}/objects/{type}/{name}', fn (Request $request, array $params): Response => $this->success(
            $this->databaseObjectService->definition($params['database'], $params['type'], $params['name']),
        ));
        $this->router->post('/api/databases/{database}/objects/{type}/{name}/drop', function (Request $request, array $params): Response {
            Csrf::assertValid($request->header('x-csrf-token'));
            return $this->success($this->databaseObjectService->drop($params['database'], $params['type'], $params['name'], $request->json()));
        });
        $this->router->post('/api/databases/{database}/objects/event/{name}/status', function (Request $request, array $params): Response {
            Csrf::assertValid($request->header('x-csrf-token'));
            return $this->success($this->databaseObjectService->setEventStatus($params['database'], $params['name'], $request->json()));
        });
        $this->router->get('/api/databases/{database}/tables/{table}/rows', fn (Request $request, array $params): Response => $this->success(
            $this->dataService->rows($params['database'], $params['table'], $request->query),
        ));
        $this->router->post('/api/databases/{database}/tables/{table}/rows/insert', function (Request $request, array $params): Response {
            Csrf::assertValid($request->header('x-csrf-token'));
            return $this->success($this->dataService->insert($params['database'], $params['table'], $request->json()));
        });
        $this->router->post('/api/databases/{database}/tables/{table}/rows/update', function (Request $request, array $params): Response {
            Csrf::assertValid($request->header('x-csrf-token'));
            return $this->success($this->dataService->update($params['database'], $params['table'], $request->json()));
        });
        $this->router->post('/api/databases/{database}/tables/{table}/rows/delete', function (Request $request, array $params): Response {
            Csrf::assertValid($request->header('x-csrf-token'));
            return $this->success($this->dataService->delete($params['database'], $params['table'], $request->json()));
        });
        $this->router->post('/api/databases/{database}/tables/{table}/rows/duplicate', function (Request $request, array $params): Response {
            Csrf::assertValid($request->header('x-csrf-token'));
            return $this->success($this->dataService->duplicate($params['database'], $params['table'], $request->json()));
        });
        $this->router->post('/api/databases/{database}/tables/{table}/rows/bulk-delete', function (Request $request, array $params): Response {
            Csrf::assertValid($request->header('x-csrf-token'));
            return $this->success($this->dataService->bulkDelete($params['database'], $params['table'], $request->json()));
        });
        $this->router->post('/api/query/execute', function (Request $request): Response {
            Csrf::assertValid($request->header('x-csrf-token'));
            return $this->success($this->queryService->execute($request->json()));
        });
        $this->router->post('/api/query/explain', function (Request $request): Response {
            Csrf::assertValid($request->header('x-csrf-token'));
            return $this->success($this->queryService->execute($request->json(), true));
        });
        $this->router->get('/api/schema/options', fn (): Response => $this->success($this->schemaService->options()));
        $this->router->post('/api/schema/preview', function (Request $request): Response {
            Csrf::assertValid($request->header('x-csrf-token'));
            return $this->success($this->schemaService->preview($request->json()));
        });
        $this->router->post('/api/schema/apply', function (Request $request): Response {
            Csrf::assertValid($request->header('x-csrf-token'));
            return $this->success($this->schemaService->apply($request->json()));
        });
        $this->router->get('/api/databases/{database}/schema-graph', fn (Request $request, array $params): Response => $this->success($this->canvasService->graph($params['database'])));
        $this->router->get('/api/databases/{database}/statistics', fn (Request $request, array $params): Response => $this->success($this->databaseService->statistics($params['database'])));
        $this->router->post('/api/databases/copy/preview', function (Request $request): Response { Csrf::assertValid($request->header('x-csrf-token')); return $this->success($this->databaseService->previewCopy($request->json())); });
        $this->router->post('/api/databases/copy/start', function (Request $request): Response { Csrf::assertValid($request->header('x-csrf-token')); return $this->success($this->databaseService->copy($request->json())); });
        $this->router->get('/api/admin/server-status', fn (): Response => $this->success($this->administrationService->overview()));
        $this->router->get('/api/admin/processes', fn (): Response => $this->success(['processes' => $this->administrationService->processes()]));
        $this->router->get('/api/admin/variables', fn (Request $request): Response => $this->success($this->administrationService->variables($request->query['filter']??'')));
        $this->router->post('/api/admin/variables/set', function (Request $request): Response { Csrf::assertValid($request->header('x-csrf-token')); return $this->success($this->administrationService->setGlobalVariable($request->json())); });
        $this->router->get('/api/admin/users', fn (): Response => $this->success(['users' => $this->administrationService->users()]));
        $this->router->post('/api/admin/processes/kill', function (Request $request): Response {
            Csrf::assertValid($request->header('x-csrf-token'));
            return $this->success($this->administrationService->kill($request->json()));
        });
        $this->router->post('/api/admin/users/create', function (Request $request): Response {
            Csrf::assertValid($request->header('x-csrf-token'));
            return $this->success($this->administrationService->createUser($request->json()));
        });
        $this->router->post('/api/admin/users/drop', function (Request $request): Response {
            Csrf::assertValid($request->header('x-csrf-token'));
            return $this->success($this->administrationService->dropUser($request->json()));
        });
        $this->router->get('/api/admin/users/grants', fn (Request $request): Response => $this->success(
            $this->administrationService->grants($request->query),
        ));
        $this->router->post('/api/admin/users/grant', function (Request $request): Response {
            Csrf::assertValid($request->header('x-csrf-token'));
            return $this->success($this->administrationService->changePrivileges($request->json(), false));
        });
        $this->router->post('/api/admin/users/revoke', function (Request $request): Response {
            Csrf::assertValid($request->header('x-csrf-token'));
            return $this->success($this->administrationService->changePrivileges($request->json(), true));
        });
        $this->router->post('/api/databases/{database}/tables/{table}/import/preview', function (Request $request, array $params): Response {
            Csrf::assertValid($request->header('x-csrf-token'));
            return $this->success($this->importExportService->previewCsv($params['database'], $params['table'], $request->json()));
        });
        $this->router->post('/api/databases/{database}/tables/{table}/import/start', function (Request $request, array $params): Response {
            Csrf::assertValid($request->header('x-csrf-token'));
            return $this->success($this->importExportService->importCsv($params['database'], $params['table'], $request->json()));
        });
        $this->router->get('/api/databases/{database}/tables/{table}/export', fn (Request $request, array $params): Response => $this->success(
            $this->importExportService->exportTable($params['database'], $params['table'], $request->query['format'] ?? 'csv', (int) ($request->query['limit'] ?? 10000)),
        ));
        $this->router->get('/api/databases/{database}/export-sql', fn (Request $request, array $params): Response => $this->success(
            $this->importExportService->exportDatabaseSql($params['database'], $request->query['mode'] ?? 'both', (int) ($request->query['rowLimit'] ?? 5000)),
        ));
        $this->router->post('/api/databases/{database}/import-sql/preview', function (Request $request, array $params): Response {
            Csrf::assertValid($request->header('x-csrf-token'));
            return $this->success($this->importExportService->previewSql($params['database'], $request->json()));
        });
        $this->router->post('/api/databases/{database}/import-sql/start', function (Request $request, array $params): Response {
            Csrf::assertValid($request->header('x-csrf-token'));
            return $this->success($this->importExportService->importSql($params['database'], $request->json()));
        });
    }

    private function workspace(): Response
    {
        $basePath = rtrim((string) $this->config->get('app.base_path', ''), '/');
        $csrfToken = Csrf::token();
        $sessionState = $this->session->publicState();
        $readOnly = $this->config->get('security.read_only', true) === true;
        ob_start();
        require $this->root . '/templates/workspace.php';
        $html = (string) ob_get_clean();

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function health(): Response
    {
        $checks = $this->runtimeChecks();
        $ready = !in_array(false, $checks, true);

        return Response::json([
            'ok' => true,
            'data' => [
                'service' => 'SQL Canvas',
                'status' => $ready ? 'ready' : 'requirements_missing',
                'runtime' => [
                    'php' => PHP_VERSION,
                    'checks' => $checks,
                ],
                'databaseConnection' => $this->session->isConnected() ? 'session_active' : 'disconnected',
            ],
            'meta' => ['databaseAccessAttempted' => false],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function success(array $data): Response
    {
        return Response::json(['ok' => true, 'data' => $data, 'meta' => []]);
    }

    private function connectedStateResponse(bool $featuresOnly): Response
    {
        $state = $this->session->publicState();
        if (($state['connected'] ?? false) !== true) {
            throw new ApiException('not_connected', 'Connect to a database server first.', 401);
        }

        $server = is_array($state['server'] ?? null) ? $state['server'] : [];
        return $this->success($featuresOnly ? ['features' => $server['features'] ?? []] : $server);
    }

    /** @return array<string, bool> */
    private function runtimeChecks(): array
    {
        return [
            'php_8_2_or_newer' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'json' => extension_loaded('json'),
            'pdo' => extension_loaded('pdo'),
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'sessions' => extension_loaded('session'),
        ];
    }

    /** @return array<string, string> */
    private function securityHeaders(): array
    {
        return [
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'",
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cache-Control' => 'no-store',
        ];
    }
}

