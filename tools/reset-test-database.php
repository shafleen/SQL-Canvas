<?php

declare(strict_types=1);

/**
 * Explicitly gated fixture reset. It refuses every database name that does not
 * start with sql_canvas_test_ and requires SQL_CANVAS_TEST_ALLOW_RESET=1.
 */

if (getenv('SQL_CANVAS_TEST_ALLOW_RESET') !== '1') {
    fwrite(STDERR, "Refusing reset: set SQL_CANVAS_TEST_ALLOW_RESET=1.\n");
    exit(2);
}

$host = getenv('SQL_CANVAS_TEST_HOST') ?: '127.0.0.1';
$port = (int) (getenv('SQL_CANVAS_TEST_PORT') ?: '3307');
$database = getenv('SQL_CANVAS_TEST_DATABASE') ?: 'sql_canvas_test_fixture';
$username = getenv('SQL_CANVAS_TEST_USER') ?: 'sql_canvas_test';
$password = getenv('SQL_CANVAS_TEST_PASSWORD') ?: 'sql_canvas_test_only';

if (!preg_match('/\Asql_canvas_test_[a-z0-9_]+\z/', $database)) {
    fwrite(STDERR, "Refusing reset: test database name must start with sql_canvas_test_.\n");
    exit(2);
}

if (!in_array($host, ['127.0.0.1', 'localhost', 'mysql', 'mariadb'], true)) {
    fwrite(STDERR, "Refusing reset: host is not an approved disposable-test host.\n");
    exit(2);
}

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
$pdo = new PDO($dsn, $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$fixture = (string) file_get_contents(dirname(__DIR__) . '/tests/Fixtures/demo.sql');
$statements = preg_split('/;\s*(?:\r?\n|\z)/', $fixture) ?: [];
foreach ($statements as $statement) {
    $statement = trim($statement);
    if ($statement !== '' && !str_starts_with($statement, '--')) {
        $pdo->exec($statement);
    } elseif (str_contains($statement, "\n")) {
        $withoutComments = preg_replace('/^--.*$/m', '', $statement);
        if (is_string($withoutComments) && trim($withoutComments) !== '') {
            $pdo->exec(trim($withoutComments));
        }
    }
}

fwrite(STDOUT, "Reset fictional fixture in {$database} on {$host}:{$port}.\n");

