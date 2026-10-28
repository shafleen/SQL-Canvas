# Installation

## Requirements

- PHP 8.2 or newer with JSON, PDO, PDO MySQL, and sessions.
- Apache 2.4 or Nginx with PHP-FPM.
- A reachable MySQL or MariaDB server and an appropriately restricted SQL account are required for database operations.

## Local preview

```sh
php -S 127.0.0.1:8080 -t public
```

Open `http://127.0.0.1:8080`. The health response is at `/api/health`.

## Production-shaped deployment

1. Upload the repository outside the public web directory where practical.
2. Copy `config/config.example.php` to `config/config.php` and review every policy value.
3. Configure Apache or Nginx with the example in `deploy/`, pointing the document root to `public/`.
4. Add TLS and perimeter authentication; restrict access to a private network or VPN.
5. Confirm that `/src`, `/config`, `/tests`, and `/docs` are not HTTP-accessible.
6. Request `/api/health` and resolve every missing runtime requirement.
7. Add each permitted database hostname/IP and port explicitly in `config/config.php`. For remote hosts, configure verified TLS and a CA file readable by PHP.
8. Protect the PHP session directory and project-local `storage/` directory from other host users and all HTTP access.

The release runtime needs no Composer, Node.js, npm, application database, or CDN.

## Disposable fixture databases

Docker is optional and only used for tests:

```sh
docker compose -f docker-compose.test.yml --profile mysql up -d
SQL_CANVAS_TEST_ALLOW_RESET=1 SQL_CANVAS_TEST_PORT=3307 php tools/reset-test-database.php
```

Use profile `mariadb` and port `3308` for MariaDB. On PowerShell, set the same environment variables with `$env:NAME = 'value'` before running PHP. Remove the containers with `docker compose -f docker-compose.test.yml down`; database storage is a temporary filesystem.

