# Same-origin API

All endpoints live under `/api` and return `{"ok":true,"data":{},"meta":{}}` or a stable error envelope. POST requests require the current `X-CSRF-Token`. Credentials are accepted only by session connect and never appear in URLs or responses.

## Session and exploration

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/health`, `/session` | Runtime checks and redacted session state |
| POST | `/session/connect`, `/session/disconnect` | Establish or remove the server-side connection profile |
| GET | `/server/info`, `/server/capabilities`, `/databases` | Connected server and visible databases |
| GET | `/databases/{db}/objects` | Tables, views, routines, triggers, events |
| GET | `/databases/{db}/tables/{table}` | Columns, indexes, FKs, table statistics |
| GET | `/databases/{db}/objects/{type}/{name}` | Server-provided object definition |

## Data, schema, canvas, and query

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/databases/{db}/tables/{table}/rows` | Bounded sort/filter/page |
| POST | `.../rows/insert`, `.../update`, `.../delete`, `.../duplicate`, `.../bulk-delete` | PK-safe row changes |
| GET | `/schema/options` | Server engines, charsets, collations, supported types |
| POST | `/schema/preview`, `/schema/apply` | Structured DDL preview and confirmed apply |
| GET | `/databases/{db}/schema-graph` | Tables, columns, and FK relationships |
| GET | `/databases/{db}/statistics` | Database and table statistics |
| POST | `/databases/copy/preview`, `/databases/copy/start` | Guarded database structure/data copy |
| POST | `/query/execute`, `/query/explain` | Deliberate SQL and execution plans |
| POST | `/databases/{db}/objects/{type}/{name}/drop` | Confirmed object removal |
| POST | `/databases/{db}/objects/event/{name}/status` | Confirmed event enable/disable |

## Transfer and administration

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `.../import/preview`, `.../import/start` | CSV preview and transactional import |
| GET | `.../tables/{table}/export` | Bounded CSV/JSON table export |
| GET | `/databases/{db}/export-sql` | Structure/data SQL backup |
| POST | `/databases/{db}/import-sql/preview`, `.../start` | Inspect and run bounded SQL import |
| GET | `/admin/server-status`, `/admin/variables`, `/admin/processes`, `/admin/users` | Live administration data |
| POST | `/admin/variables/set` | Allowlisted, capability-gated global variable change |
| POST | `/admin/processes/kill` | Confirmed process termination |
| POST | `/admin/users/create`, `/admin/users/drop` | Account lifecycle |
| GET | `/admin/users/grants` | Effective grants for one account |
| POST | `/admin/users/grant`, `/admin/users/revoke` | Confirmed scoped privilege changes |

Path segments are encoded with `encodeURIComponent`. Structured row identities are server-verified primary-key maps; clients cannot supply raw WHERE clauses.
