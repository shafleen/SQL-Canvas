<?php

declare(strict_types=1);

use SqlCanvas\Application;
use SqlCanvas\Support\Autoloader;
use SqlCanvas\Support\Config;

$root = dirname(__DIR__);
require $root . '/src/Support/Autoloader.php';
Autoloader::register($root . '/src');

return new Application($root, Config::fromProjectRoot($root));

