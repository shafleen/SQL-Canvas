# Architecture

SQL Canvas is a same-origin, framework-free PHP application with one supported HTTP boundary: `public/`. Source, configuration, templates, storage, fixtures, and documentation stay outside the document root. Production requires no Composer, Node, frontend build, CDN, telemetry service, or SQL Canvas-owned database.

```text
Browser: HTML + CSS + ES modules
  │ HTTPS, JSON, CSRF
  ▼
public/index.php → Application → Router → Services
                                  │
                                  ├─ Connection/session policy
                                  ├─ Metadata and row services
                                  ├─ Structured schema operations
                                  ├─ Query and import/export
                                  └─ Administration
                                            │
                                            ▼
                                  native PDO MySQL connection
```

Credentials are submitted only to the connect endpoint and retained in the server-side PHP session. Each request creates a non-persistent PDO connection from that protected profile. The connected database account remains authoritative for object visibility and privileges.

Services return arrays and throw stable `ApiException` errors; they do not render HTML. `Application` owns routing, CSRF checks, request-size policy, the response envelope, and security headers. Database values are parameters, identifiers are validated against metadata and quoted, and arbitrary SQL is isolated to explicit Query/SQL-import workflows.

The frontend is split into connection, explorer, workspace, and management ES modules. It uses `textContent` for server-derived labels and stores only theme, pane preferences, expanded database names, opt-in query history, and canvas positions. Credentials, row data, and tokens are not persisted by application code.

Disposable MySQL 8.4 and MariaDB 11.4 containers use fictional fixtures and temporary storage. Reset tooling rejects nonlocal hosts and database names outside the `sql_canvas_test_` prefix.
