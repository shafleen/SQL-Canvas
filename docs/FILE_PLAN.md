# Repository map

```text
public/                 supported document root, front controller, local assets
src/Http/               request, response, routing, CSRF, API errors
src/Auth/               secure session state and connection throttling
src/Database/           profiles, destination policy, PDO, capabilities, metadata
src/Services/           connection, explorer, data, schema, canvas, query,
                        import/export, database objects, administration
templates/              product workspace HTML
config/                 safe defaults and copy-only policy template
storage/                protected runtime cache/session-adjacent data
tests/Unit/             protocol, policy, parser, and application tests
tests/Integration/      disposable-engine metadata and full workflow tests
tests/Frontend/         native ES-module behavior tests
tests/Fixtures/         fictional MySQL/MariaDB schema and rows
tools/                  explicitly gated fixture reset
deploy/                 Apache and Nginx examples
docs/                   architecture, API, security, install, feature scope
.github/workflows/      PHP matrix and MySQL/MariaDB integration jobs
```

The production ZIP contains ready-to-run PHP and static assets. Composer and Node are development conveniences only.
