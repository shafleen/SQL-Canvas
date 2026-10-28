<?php

declare(strict_types=1);

namespace SqlCanvas\Support;

final class Autoloader
{
    public static function register(string $sourceDirectory): void
    {
        $prefix = 'SqlCanvas\\';

        spl_autoload_register(static function (string $class) use ($prefix, $sourceDirectory): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $file = rtrim($sourceDirectory, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . str_replace('\\', DIRECTORY_SEPARATOR, $relative)
                . '.php';

            if (is_file($file)) {
                require $file;
            }
        });
    }
}

