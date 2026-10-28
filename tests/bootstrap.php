<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$composer = $root . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
} else {
    require $root . '/src/Support/Autoloader.php';
    SqlCanvas\Support\Autoloader::register($root . '/src');
}

