<?php

declare(strict_types=1);

use SqlCanvas\Http\Request;

$application = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$request = Request::fromGlobals();
if ($request->path === '/api/index.php') {
    $request = new Request($request->method, '/api/health', $request->headers, $request->body);
}
$application->handle($request)->send();

