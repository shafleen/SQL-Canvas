# Implementation status

Last updated: 28 October 2026

SQL Canvas now runs as a real, PHP-native MySQL/MariaDB management workspace. The shipped interface contains product controls and live database state only; development checkpoints are not rendered in the application.

## Working capabilities

- Secure session-only connections with exact destination/port policy, verified remote TLS, CSRF, throttling, capability detection, and sanitized errors.
- Lazy explorer for databases, tables, views, columns, indexes, foreign keys, routines, triggers, and events.
- Server-paged, stably sorted and filtered data grid with keyset pagination where safe; PK-gated inserts, inline edits, record editor, optimistic concurrency, generated-key duplication, transactional bulk deletion, and page export.
- Structured database/table operations with SQL preview, identifier quoting, metadata validation, typed destructive confirmations, indexes, foreign keys, columns, rename/copy, and maintenance actions.
- Relationship canvas sourced from `information_schema`, draggable and keyboard-positionable cards, FK edges/proposals, search, minimap, zoom, auto-layout, local layout persistence, snapshot comparison, and JSON/SVG/PNG export.
- Deliberate SQL execution with in-memory tabs, selection execution, snippets, metadata insertion, find/replace, write confirmation, server timeout, read-only transactions, EXPLAIN, bounded results, CSV result export, and opt-in local history.
- CSV preview/import with transactional commit and NULL handling; bounded CSV/JSON table export; database SQL structure/data export and confirmed multi-statement SQL import.
- Live server status/process list, filterable server variables with capability-gated global changes, confirmed process termination, account create/remove, effective grants, and scoped grant/revoke controls.
- Protected JSON-lines audit records for every mutation outcome without request bodies or database values.
- Definition viewer and confirmed drop workflow for views, triggers, routines, and events; event enable/disable controls; definitions can be copied or opened in Query for server-specific editing.
- Responsive dark/light application shell with keyboard navigation, command palette, reduced-motion support, product empty states, and no frontend framework or build step.

## Verification in this workspace

- PHP 8.5.12: 51 PHP files lint clean.
- PHPUnit: 21 tests, 52 assertions passed (non-database suite).
- Native Node test runner: 6 tests passed; all application JavaScript modules syntax-check.
- MySQL 8.4.13: 3 integration tests, 50 assertions passed against an isolated fixture.
- MariaDB 11.4.5: 3 integration tests, 50 assertions passed against an isolated fixture.
- Live browser QA at `http://127.0.0.1:8765/`: real MariaDB explorer, rows, canvas, query, status, variables, permissions, event behavior, desktop layout, and 390px mobile layout reviewed.

## Honest limitations still tracked

- One active connection is supported per browser session; multiple simultaneous connection tabs are not yet provided.
- Browser imports and exports are intentionally bounded (5 MB import, 500 SQL statements, configurable row caps). They are not a replacement for native hot-backup or bulk-loader tools.
- Non-generated primary keys cannot be duplicated automatically; Add row is required so a new identity can be supplied.
- Arbitrary routine/trigger/event scripts that depend on client-side `DELIMITER` directives should be edited in Query or a native client. SQL import accepts standard semicolon-separated statements.
- Query cancellation is best-effort through timeout/connection handling; stopping a browser request is not represented as guaranteed server-query cancellation.
- Routine/trigger/event creation and complex alteration use the Query workspace so the database server remains the syntax authority; events additionally have direct enable/disable controls.

See [docs/FEATURE_MATRIX.md](docs/FEATURE_MATRIX.md) for capability-level scope and [docs/SECURITY.md](docs/SECURITY.md) for deployment boundaries.
