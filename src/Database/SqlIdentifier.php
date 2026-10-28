<?php

declare(strict_types=1);

namespace SqlCanvas\Database;

final class SqlIdentifier
{
    public static function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public static function qualified(string $schema, string $object): string
    {
        return self::quote($schema) . '.' . self::quote($object);
    }
}

