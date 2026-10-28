<?php

declare(strict_types=1);

namespace SqlCanvas\Database;

use PDO;

final class CapabilityDetector
{
    /** @return array<string, mixed> */
    public function detect(PDO $pdo): array
    {
        $row = $pdo->query(
            'SELECT VERSION() AS version, @@version_comment AS version_comment, '
            . 'DATABASE() AS current_database, CURRENT_USER() AS authenticated_user, @@sql_mode AS sql_mode'
        )->fetch();
        $row = is_array($row) ? $row : [];
        $version = (string) ($row['version'] ?? 'unknown');
        $comment = (string) ($row['version_comment'] ?? '');
        $vendor = stripos($version . ' ' . $comment, 'mariadb') !== false ? 'MariaDB' : 'MySQL';
        $numericVersion = $this->numericVersion($version);
        $grants = array_map(
            static fn (mixed $grant): string => (string) $grant,
            $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN),
        );
        $grantText = strtoupper(implode("\n", $grants));
        $globalAll = preg_match('/GRANT\s+ALL PRIVILEGES\s+ON\s+\*\.\*/', $grantText) === 1;
        $globalCreate = $globalAll || preg_match('/GRANT\s+[^\n]*\bCREATE\b[^\n]*\s+ON\s+\*\.\*/', $grantText) === 1;
        $createUser = $globalAll || preg_match('/GRANT\s+[^\n]*\bCREATE USER\b[^\n]*\s+ON\s+\*\.\*/', $grantText) === 1;
        $readUsers = $globalAll
            || preg_match('/GRANT\s+[^\n]*\bSELECT\b[^\n]*\s+ON\s+\*\.\*/', $grantText) === 1
            || preg_match('/GRANT\s+[^\n]*\bSELECT\b[^\n]*\s+ON\s+`?MYSQL`?\.(?:\*|`?USER`?)/', $grantText) === 1;
        $process = $globalAll || preg_match('/GRANT\s+[^\n]*\bPROCESS\b[^\n]*\s+ON\s+\*\.\*/', $grantText) === 1;
        $globalVariables=$globalAll||preg_match('/GRANT\s+[^\n]*\b(?:SUPER|SYSTEM_VARIABLES_ADMIN)\b[^\n]*\s+ON\s+\*\.\*/',$grantText)===1;

        return [
            'vendor' => $vendor,
            'version' => $version,
            'versionComment' => $comment,
            'currentDatabase' => $row['current_database'] ?? null,
            'currentUser' => (string) ($row['authenticated_user'] ?? ''),
            'sqlMode' => (string) ($row['sql_mode'] ?? ''),
            'grants' => $grants,
            'grantCount' => count($grants),
            'features' => [
                'informationSchema' => true,
                'routines' => true,
                'triggers' => true,
                'events' => true,
                'checkConstraints' => $vendor === 'MariaDB'
                    ? version_compare($numericVersion, '10.2.1', '>=')
                    : version_compare($numericVersion, '8.0.16', '>='),
                'createDatabase' => $globalCreate,
                'userAdministration' => $readUsers,
                'createUser' => $createUser,
                'globalProcessList' => $process,
                'setGlobalVariables' => $globalVariables,
            ],
        ];
    }

    private function numericVersion(string $version): string
    {
        return preg_match('/\d+\.\d+\.\d+/', $version, $matches) === 1 ? $matches[0] : '0.0.0';
    }
}

