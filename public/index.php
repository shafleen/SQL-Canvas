<?php

declare(strict_types=1);

use SqlCanvas\Http\Request;

$application = require dirname(__DIR__) . '/bootstrap/app.php';
$application->handle(Request::fromGlobals())->send();

