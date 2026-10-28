<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$directories = ['bootstrap', 'config', 'public', 'src', 'templates', 'tests', 'tools'];
$failed = [];
$checked = 0;

foreach ($directories as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root . DIRECTORY_SEPARATOR . $directory,
        FilesystemIterator::SKIP_DOTS,
    ));
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $checked++;
        $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname());
        exec($command, $output, $code);
        if ($code !== 0) {
            $failed[] = $file->getPathname();
        }
        $output = [];
    }
}

if ($failed !== []) {
    fwrite(STDERR, "PHP lint failed:\n" . implode("\n", $failed) . "\n");
    exit(1);
}

fwrite(STDOUT, "PHP lint passed for {$checked} files.\n");

