# Product capability matrix

Status meanings: **Complete** is implemented with automated coverage; **Implemented** is end-to-end but still awaits the disposable-engine matrix; **Partial** is deliberately bounded and documented; **Unsupported** is outside the initial product scope.

| Capability | Status | Current boundary |
|---|---|---|
| PHP 8.2+ modular runtime, secure deployment, configuration | Complete | No application database or production build step |
| Session connection, TLS/destination policy, CSRF, throttling | Complete | One active connection per browser session |
| Metadata explorer and object details | Complete | Visibility follows the connected SQL account |
| Paged/filterable data grid and safe row CRUD | Complete | Writes require a real primary key; page size and bulk operations are bounded |
| Database/table/column/index/FK structured operations | Complete | SQL preview and typed confirmation precede mutations |
| Relationship canvas, FK proposals, snapshots, image/JSON export | Complete | Layout is local browser state; no row data enters exports |
| SQL workspace, EXPLAIN, timeout, confirmation, result export/history | Complete | Results are capped; arbitrary SQL cannot be declared safe by a parser |
| CSV/JSON table import/export | Complete | CSV import is transactional and limited to 5 MB |
| Whole-database SQL backup/import | Partial | Browser-safe caps; no compression, hot-backup, or `DELIMITER` scripts |
| Users and scoped grants/revokes | Complete | SQL server syntax/privileges remain authoritative |
| Views, routines, triggers, and events | Partial | Browse/definition/drop and event enable/disable; Query handles server-specific authoring |
| Server status, variables, processes and termination | Complete | Global changes are allowlisted, capability-gated and strongly confirmed |
| Table maintenance and statistics | Complete | Engine and privilege support is decided by the server |
| Multiple simultaneous connection tabs | Partial | Single session connection is documented and enforced |
| Streaming multi-gigabyte transfer/native hot backup | Unsupported | Use `mysqldump`, `mariadb-dump`, or server-native tooling |
| PostgreSQL, SQLite, SQL Server, Oracle | Unsupported | MySQL/MariaDB only |
| AI SQL, telemetry, mandatory SaaS/CDN | Unsupported | Explicit product non-goal |

No UI control is included unless it calls a real backend operation or shows real connected-server data.
