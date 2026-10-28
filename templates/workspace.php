<?php

declare(strict_types=1);

/** @var string $basePath */
/** @var string $csrfToken */
/** @var array<string, mixed> $sessionState */
/** @var bool $readOnly */

$asset = static fn (string $path): string => htmlspecialchars($basePath . $path, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <meta name="description" content="SQL Canvas is a visual-first, self-hosted MySQL and MariaDB workspace for data, schema, query, and server administration.">
    <meta property="og:title" content="SQL Canvas — See your database differently">
    <meta property="og:description" content="A visual-first, self-hosted workspace for MySQL and MariaDB.">
    <meta property="og:type" content="website">
    <meta name="sql-canvas-csrf" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="sql-canvas-base-path" content="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>">
    <title>SQL Canvas — See your database differently</title>
    <link rel="stylesheet" href="<?= $asset('/assets/css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= $asset('/assets/css/base.css') ?>">
    <link rel="stylesheet" href="<?= $asset('/assets/css/layout.css') ?>">
    <link rel="stylesheet" href="<?= $asset('/assets/css/components.css') ?>">
    <script type="module" src="<?= $asset('/assets/js/app.js') ?>"></script>
</head>
<body>
<a class="skip-link" href="#workspace-main">Skip to workspace</a>
<div class="app-frame" data-app-shell data-connected="<?= ($sessionState['connected'] ?? false) === true ? 'true' : 'false' ?>">
    <header class="titlebar">
        <button class="icon-button mobile-nav-button" type="button" data-toggle-pane="explorer" aria-label="Open database explorer" title="Open explorer">
            <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 5h14M3 10h14M3 15h14"/></svg>
        </button>
        <a class="brand" href="<?= htmlspecialchars($basePath . '/', ENT_QUOTES, 'UTF-8') ?>" aria-label="SQL Canvas home">
            <svg class="brand-mark" viewBox="0 0 34 34" aria-hidden="true">
                <path d="M8 9h8v7H8zM18 18h8v7h-8zM16 12h5a5 5 0 0 1 5 5v1M18 22h-5a5 5 0 0 1-5-5v-1"/>
            </svg>
            <span>SQL <strong>Canvas</strong></span>
        </a>
        <button class="connection-context" type="button" data-connect-open aria-label="Open database connection dialog">
            <span class="status-dot" aria-hidden="true"></span>
            <span class="context-copy"><strong data-connection-label>Connect database</strong><small data-connection-detail>No database connected</small></span>
            <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg>
        </button>
        <button class="command-trigger" type="button" data-command-open aria-haspopup="dialog">
            <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m14.5 14.5 3 3M9 16A7 7 0 1 1 9 2a7 7 0 0 1 0 14Z"/></svg>
            <span>Workspace commands</span><kbd>Ctrl K</kbd>
        </button>
        <div class="title-actions">
            <button class="secondary-button top-query-button" type="button" data-new-query hidden>New query</button>
            <button class="icon-button" type="button" data-theme-toggle aria-label="Switch color theme" title="Switch color theme">
                <svg class="theme-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="M10 2v2m0 12v2M2 10h2m12 0h2M4.3 4.3l1.4 1.4m8.6 8.6 1.4 1.4m0-11.4-1.4 1.4m-8.6 8.6-1.4 1.4M14 10a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z"/></svg>
            </button>
        </div>
    </header>

    <aside class="explorer" data-pane="explorer" aria-label="Database explorer">
        <div class="pane-heading">
            <div><span class="eyebrow">Explorer</span><h2>Connections</h2></div>
            <div class="pane-actions">
                <?php if (!$readOnly): ?><button class="icon-button compact" type="button" data-schema-create-database aria-label="Create database" title="Create database"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 4v12M4 10h12"/></svg></button><button class="icon-button compact" type="button" data-schema-create-table aria-label="Create table in selected database" title="Create table in selected database"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 5h14v11H3zM3 9h14M8 5v11"/></svg></button><?php endif; ?>
                <button class="icon-button compact" type="button" data-toggle-pane="explorer" aria-label="Collapse database explorer" title="Collapse explorer"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m12.5 5-5 5 5 5"/></svg></button>
            </div>
        </div>
        <label class="filter-shell" for="explorer-filter">
            <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="9" cy="9" r="5"/><path d="m13 13 4 4"/></svg>
            <input id="explorer-filter" type="search" placeholder="Filter databases and objects" data-explorer-filter disabled>
        </label>
        <div class="explorer-empty" data-explorer-empty>
            <div class="empty-orbit" aria-hidden="true"><span></span><span></span><span></span></div>
            <h3>Connect to inspect your server</h3>
            <p>Credentials stay in the server-side PHP session and are never returned to this browser.</p>
            <button class="primary-button compact-action" type="button" data-connect-open>Connect database</button>
        </div>
        <div class="explorer-tree" role="tree" aria-label="Database objects" data-explorer-tree hidden></div>
        <div class="explorer-feedback" role="status" aria-live="polite" data-explorer-feedback></div>
        <div class="pane-note"><span class="lock-glyph" aria-hidden="true">⌁</span> Credentials never enter browser storage</div>
    </aside>

    <main class="workspace" id="workspace-main" tabindex="-1">
        <div class="tabbar" role="tablist" aria-label="Open workspace tabs">
            <button class="workspace-tab" role="tab" aria-selected="true" type="button" data-global-view="home">
                <span class="tab-symbol" aria-hidden="true">◇</span> Start
            </button>
            <button class="workspace-tab global-tab" role="tab" aria-selected="false" type="button" data-global-view="query" hidden>Query</button>
            <button class="workspace-tab global-tab" role="tab" aria-selected="false" type="button" data-global-view="canvas" hidden>Canvas</button>
            <button class="workspace-tab global-tab" role="tab" aria-selected="false" type="button" data-global-view="server" hidden>Server</button>
            <button class="workspace-tab global-tab" role="tab" aria-selected="false" type="button" data-global-view="users" hidden>Users</button>
            <span class="tabbar-space"></span>
            <button class="icon-button compact" type="button" data-toggle-pane="inspector" aria-label="Toggle object inspector" title="Toggle inspector">
                <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 4h14v12H3zM12 4v12"/></svg>
            </button>
        </div>

        <section class="welcome-canvas" aria-labelledby="welcome-title">
            <div class="canvas-grid" aria-hidden="true"></div>
            <div class="welcome-content">
                <span class="section-kicker"><i></i> <span data-welcome-kicker>Database workspace</span></span>
                <h1 id="welcome-title"><span data-welcome-title>Your database.</span><br><em data-welcome-subtitle>A whole new perspective.</em></h1>
                <p class="welcome-lede" data-welcome-lede>Connect to an allowed MySQL or MariaDB server. SQL Canvas will detect the server, load only visible metadata, and keep the password out of browser storage.</p>
                <button class="primary-button welcome-action" type="button" data-connect-open>Connect database</button>
                <div class="boundary-card">
                    <div class="boundary-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 4.6 2.8 8.1 7 10 4.2-1.9 7-5.4 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-5"/></svg>
                    </div>
                    <div><strong><?= $readOnly ? 'Read-only deployment policy' : 'Protected database actions' ?></strong><span><?= $readOnly ? 'Browsing and SQL reads are available; writes are blocked server-side.' : 'Changes require server permission, CSRF validation, safe row identity, and explicit confirmation.' ?></span></div>
                </div>
            </div>

            <div class="product-map" aria-label="Database workspace capabilities">
                <div class="map-caption"><span>Workspace readiness</span><small data-handshake-status>Waiting for connection</small></div>
                <div class="architecture-flow">
                    <div class="flow-node"><span class="node-index">A</span><strong>Explore</strong><small>schemas · objects · structure</small></div>
                    <div class="flow-link" aria-hidden="true"><i></i></div>
                    <div class="flow-node featured"><span class="node-index">B</span><strong>Work with data</strong><small>page · inspect · edit safely</small></div>
                    <div class="flow-link" aria-hidden="true"><i></i></div>
                    <div class="flow-node muted" data-server-node><span class="node-index">C</span><strong data-server-node-title>Query</strong><small data-server-node-detail>disconnected</small></div>
                </div>
                <div class="architecture-meta">
                    <span><i class="meta-dot teal"></i> Session-scoped credentials</span>
                    <span><i class="meta-dot blue"></i> Server-paged results</span>
                    <span><i class="meta-dot gold"></i> Explicit write confirmation</span>
                </div>
            </div>
        </section>

        <section class="object-workspace" data-object-workspace hidden aria-label="Table workspace">
            <header class="object-toolbar">
                <div class="object-title"><span data-object-schema>database</span><strong data-object-table>table</strong></div>
                <div class="view-switcher" role="tablist" aria-label="Table views">
                    <button type="button" role="tab" aria-selected="true" data-object-view="data">Data</button>
                    <button type="button" role="tab" aria-selected="false" data-object-view="structure">Structure</button>
                </div>
                <span class="toolbar-spacer"></span>
                <button class="secondary-button" type="button" data-grid-export>Export page</button>
                <button class="secondary-button" type="button" data-selection-copy-csv hidden>Copy selected CSV</button><button class="secondary-button" type="button" data-selection-copy-json hidden>Copy selected JSON</button><button class="secondary-button" type="button" data-selection-copy-sql hidden>Copy selected INSERT</button><button class="danger-button" type="button" data-selection-delete hidden>Delete selected</button>
                <button class="secondary-button" type="button" data-table-export>Export…</button>
                <button class="secondary-button" type="button" data-table-import <?= $readOnly ? 'hidden' : '' ?>>Import CSV</button>
                <button class="secondary-button" type="button" data-table-schema-actions <?= $readOnly ? 'hidden' : '' ?>>Table actions</button>
                <button class="secondary-button" type="button" data-grid-refresh>Refresh</button>
                <button class="primary-button" type="button" data-row-add hidden>Add row</button>
            </header>
            <div class="data-surface" data-data-surface>
                <div class="grid-filter"><label><span>Filter column</span><select data-grid-filter-column><option value="">No filter</option></select></label><label><span>Value</span><input data-grid-filter-value type="search" autocomplete="off" placeholder="Contains…"></label><button class="secondary-button" type="button" data-grid-filter-apply>Apply filter</button><button class="secondary-button" type="button" data-grid-filter-clear>Clear</button></div>
                <div class="grid-state" data-grid-state>Loading rows…</div>
                <div class="data-grid-wrap"><table class="data-grid" data-data-grid></table></div>
                <footer class="grid-footer">
                    <span data-grid-summary></span>
                    <span class="toolbar-spacer"></span>
                    <button class="secondary-button" type="button" data-page-previous>Previous</button>
                    <button class="secondary-button" type="button" data-page-next>Next</button>
                </footer>
            </div>
            <div class="structure-surface" data-structure-surface hidden></div>
        </section>

        <section class="query-workspace" data-query-workspace hidden aria-label="SQL query workspace">
            <header class="object-toolbar">
                <div class="object-title"><span>SQL workspace</span><strong>Query</strong></div>
                <div class="query-tabs" data-query-tabs role="tablist" aria-label="Query documents"></div><button class="secondary-button" type="button" data-query-add-tab>New tab</button>
                <span class="toolbar-spacer"></span>
                <select data-query-snippet aria-label="Insert SQL snippet"><option value="">Snippets…</option><option value="SELECT * FROM table_name LIMIT 100;">Select rows</option><option value="SELECT column_name, COUNT(*) AS total\nFROM table_name\nGROUP BY column_name\nORDER BY total DESC;">Group and count</option><option value="START TRANSACTION;\n\n-- statements\n\nCOMMIT;">Transaction script</option></select><button class="secondary-button" type="button" data-query-complete>Insert object</button><button class="secondary-button" type="button" data-query-format>Format</button><button class="secondary-button" type="button" data-query-find>Find</button>
                <label class="history-toggle"><input type="checkbox" data-query-history-optin> Save history on this device</label>
                <button class="secondary-button" type="button" data-query-export disabled>Export results</button><button class="secondary-button" type="button" data-query-clear-history>Clear history</button>
            </header>
            <div class="query-editor-shell">
                <div class="query-findbar" data-query-findbar hidden><label>Find <input type="search" data-query-find-text></label><label>Replace <input type="text" data-query-replace-text></label><button class="secondary-button" type="button" data-query-find-next>Next</button><button class="secondary-button" type="button" data-query-replace-one>Replace</button><button class="secondary-button" type="button" data-query-replace-all>Replace all</button></div>
                <label for="query-editor">SQL statement</label>
                <textarea id="query-editor" data-query-editor spellcheck="false" autocomplete="off" placeholder="SELECT * FROM database.table LIMIT 100;"></textarea>
                <div class="query-actions">
                    <span data-query-classification>Statement executes only when you choose Run.</span>
                    <button class="secondary-button" type="button" data-query-explain>Explain</button>
                    <button class="primary-button" type="button" data-query-run>Run query</button>
                </div>
            </div>
            <div class="query-result" data-query-result><div class="grid-state">Run a statement to see its result.</div></div>
        </section>

        <section class="definition-workspace" data-definition-workspace hidden aria-label="Database object definition">
            <header class="object-toolbar"><div class="object-title"><span data-definition-type>Object</span><strong data-definition-name>Definition</strong></div><span class="toolbar-spacer"></span><button class="secondary-button" type="button" data-definition-event-status hidden></button><button class="secondary-button" type="button" data-definition-copy>Copy SQL</button><button class="secondary-button" type="button" data-definition-query>Open in Query</button><button class="danger-button" type="button" data-definition-drop <?= $readOnly ? 'hidden' : '' ?>>Drop object</button></header>
            <div class="definition-content"><pre data-definition-sql>Choose a view, routine, trigger, or event in the explorer.</pre></div>
        </section>

        <section class="canvas-workspace" data-canvas-workspace hidden aria-label="Schema relationship canvas">
            <header class="object-toolbar"><div class="object-title"><span>Schema</span><strong data-canvas-title>Relationship canvas</strong></div><label class="canvas-search"><span class="visually-hidden">Find table on canvas</span><input type="search" placeholder="Find table…" data-canvas-search></label><span class="toolbar-spacer"></span><button class="secondary-button" type="button" data-canvas-zoom-out aria-label="Zoom out">−</button><button class="secondary-button" type="button" data-canvas-zoom-reset>100%</button><button class="secondary-button" type="button" data-canvas-zoom-in aria-label="Zoom in">+</button><button class="secondary-button" type="button" data-canvas-auto-layout>Auto layout</button><button class="secondary-button" type="button" data-canvas-compare>Compare snapshot</button><input type="file" accept="application/json,.json" data-canvas-snapshot-file hidden><button class="secondary-button" type="button" data-database-details>Database details</button><button class="secondary-button" type="button" data-database-copy <?= $readOnly ? 'hidden' : '' ?>>Copy database</button><button class="secondary-button" type="button" data-database-export-sql>Backup SQL</button><button class="secondary-button" type="button" data-database-import-sql <?= $readOnly ? 'hidden' : '' ?>>Import SQL</button><button class="danger-button" type="button" data-database-drop <?= $readOnly ? 'hidden' : '' ?>>Drop database</button><button class="secondary-button" type="button" data-canvas-export-json>Export JSON</button><button class="secondary-button" type="button" data-canvas-export-svg>Export SVG</button><button class="secondary-button" type="button" data-canvas-export-png>Export PNG</button><button class="secondary-button" type="button" data-canvas-refresh>Refresh</button></header>
            <div class="schema-canvas" data-schema-canvas><div class="grid-state">Select or expand a database, then open Canvas.</div></div>
        </section>

        <section class="admin-workspace" data-admin-workspace hidden aria-label="Server administration">
            <header class="object-toolbar"><div class="object-title"><span>Administration</span><strong data-admin-title>Server</strong></div><div class="view-switcher" role="tablist" aria-label="Administration views"><button type="button" role="tab" data-admin-view="status" aria-selected="true">Status</button><button type="button" role="tab" data-admin-view="variables" aria-selected="false">Variables</button><button type="button" role="tab" data-admin-view="processes" aria-selected="false">Processes</button><button type="button" role="tab" data-admin-view="users" aria-selected="false">Users</button></div><span class="toolbar-spacer"></span><?php if (!$readOnly): ?><button class="primary-button" type="button" data-admin-create-user hidden>Create user</button><?php endif; ?><button class="secondary-button" type="button" data-admin-refresh>Refresh</button></header>
            <div class="admin-content" data-admin-content><div class="grid-state">Loading server information…</div></div>
        </section>
    </main>

    <aside class="inspector" data-pane="inspector" aria-label="Connection and object inspector">
        <div class="pane-heading">
            <div><span class="eyebrow">Inspector</span><h2>Details</h2></div>
            <button class="icon-button compact" type="button" data-toggle-pane="inspector" aria-label="Collapse object inspector" title="Collapse inspector">
                <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m7.5 5 5 5-5 5"/></svg>
            </button>
        </div>
        <section class="inspector-section">
            <h3>Active connection</h3>
            <dl class="readiness-list">
                <div><dt>Status</dt><dd class="neutral" data-inspector-status>Disconnected</dd></div>
                <div><dt>Server</dt><dd data-inspector-server>—</dd></div>
                <div><dt>Identity</dt><dd data-inspector-user>—</dd></div>
                <div><dt>Effective grants</dt><dd data-inspector-grants>—</dd></div>
                <div><dt>Access policy</dt><dd class="<?= $readOnly ? 'neutral' : 'pass' ?>"><?= $readOnly ? 'Read only' : 'Writes enabled' ?></dd></div>
            </dl>
            <button class="secondary-button disconnect-button" type="button" data-disconnect hidden>Disconnect</button>
        </section>
        <section class="inspector-section">
            <h3>Selected object</h3>
            <dl class="readiness-list">
                <div><dt>Database</dt><dd data-selected-database>—</dd></div>
                <div><dt>Object</dt><dd data-selected-object>—</dd></div>
                <div><dt>Rows</dt><dd data-selected-rows>—</dd></div>
                <div><dt>Identity</dt><dd data-selected-identity>—</dd></div>
            </dl>
        </section>
        <p class="inspector-footnote">Database permissions remain authoritative. SQL Canvas revalidates every target when an operation runs.</p>
    </aside>

    <section class="results-panel" data-pane="results" aria-label="Session activity">
        <button class="results-handle" type="button" data-toggle-pane="results" aria-expanded="true">
            <span><i></i> Session activity</span>
            <span class="check-summary" data-activity-summary>Ready</span>
            <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 12 5-5 5 5"/></svg>
        </button>
        <div class="results-body activity-body" data-activity-log aria-live="polite">
            <div class="activity-entry"><time>Now</time><strong>Workspace ready</strong><span>Connect to load permitted server metadata.</span></div>
        </div>
    </section>

    <footer class="statusbar">
        <span><i class="status-dot"></i> <span data-status-connection>Disconnected</span></span>
        <span data-status-server>PHP <?= htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') ?></span>
        <span class="status-spacer"></span>
        <span><?= $readOnly ? 'Read-only mode' : 'Write access enabled' ?></span>
        <button type="button" data-toggle-pane="results">Activity</button>
    </footer>
</div>

<dialog class="command-dialog" data-command-dialog aria-labelledby="command-title">
    <div class="command-header">
        <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="9" cy="9" r="5"/><path d="m13 13 4 4"/></svg>
        <label class="visually-hidden" for="command-search" id="command-title">Workspace commands</label>
        <input id="command-search" type="search" placeholder="Type a workspace command…" autocomplete="off" data-command-search>
        <kbd>Esc</kbd>
    </div>
    <div class="command-list" role="listbox" aria-label="Available workspace commands" data-command-list></div>
    <p class="command-footnote">Commands reflect the active connection and deployment access policy.</p>
</dialog>

<dialog class="schema-dialog" data-query-suggestions-dialog aria-labelledby="query-suggestions-title">
    <div class="dialog-shell"><div class="dialog-heading"><div><span class="eyebrow">Permitted metadata</span><h2 id="query-suggestions-title">Insert database object</h2></div><button class="icon-button compact" type="button" data-query-suggestions-close aria-label="Close object suggestions"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button></div><div class="query-suggestions" data-query-suggestions></div><div class="dialog-actions"><span class="toolbar-spacer"></span><button class="secondary-button" type="button" data-query-suggestions-close>Cancel</button></div></div>
</dialog>

<dialog class="schema-dialog" data-canvas-diff-dialog aria-labelledby="canvas-diff-title">
    <div class="dialog-shell"><div class="dialog-heading"><div><span class="eyebrow">Schema comparison</span><h2 id="canvas-diff-title">Snapshot differences</h2></div><button class="icon-button compact" type="button" data-canvas-diff-close aria-label="Close schema comparison"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button></div><div class="schema-diff" data-canvas-diff></div><div class="dialog-actions"><span class="toolbar-spacer"></span><button class="primary-button" type="button" data-canvas-diff-close>Done</button></div></div>
</dialog>

<dialog class="schema-dialog" data-database-details-dialog aria-labelledby="database-details-title">
    <div class="dialog-shell"><div class="dialog-heading"><div><span class="eyebrow">Database properties</span><h2 id="database-details-title" data-database-details-title>Database details</h2></div><button class="icon-button compact" type="button" data-database-details-close aria-label="Close database details"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button></div><div class="metric-grid" data-database-metrics></div><div class="import-preview" data-database-tables></div><div class="dialog-actions"><span class="toolbar-spacer"></span><button class="primary-button" type="button" data-database-details-close>Done</button></div></div>
</dialog>

<dialog class="connection-dialog" data-connection-dialog aria-labelledby="connection-title">
    <form method="dialog" class="dialog-shell" data-connection-form>
        <div class="dialog-heading">
            <div><span class="eyebrow">New connection</span><h2 id="connection-title">Connect a database server</h2></div>
            <button class="icon-button compact" type="button" data-connect-close aria-label="Close connection dialog">
                <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg>
            </button>
        </div>
        <p class="dialog-intro">The deployment policy decides which destinations are allowed. Your password is sent only to this server and retained only in its PHP session.</p>
        <div class="connection-fields">
            <label class="field field-wide"><span>Connection name <small>optional</small></span><input name="label" autocomplete="off" placeholder="Local database"></label>
            <label class="field"><span>Host</span><input name="host" required value="127.0.0.1" autocomplete="off" spellcheck="false"></label>
            <label class="field field-port"><span>Port</span><input name="port" required type="number" min="1" max="65535" value="3306" inputmode="numeric"></label>
            <label class="field"><span>Username</span><input name="username" required autocomplete="username" spellcheck="false"></label>
            <label class="field"><span>Password</span><input name="password" type="password" autocomplete="current-password"></label>
            <label class="field field-wide"><span>Default database <small>optional</small></span><input name="database" autocomplete="off" spellcheck="false"></label>
            <label class="check-field field-wide"><input name="tlsEnabled" type="checkbox"><span><strong>Use verified TLS</strong><small>Required for remote hosts. Configure a CA path readable by the SQL Canvas server.</small></span></label>
            <label class="field field-wide tls-ca-field" data-tls-ca hidden><span>Server CA certificate path</span><input name="tlsCaPath" autocomplete="off" spellcheck="false" placeholder="/etc/ssl/certs/database-ca.pem"></label>
        </div>
        <div class="form-error" role="alert" data-connection-error hidden></div>
        <div class="dialog-actions">
            <span class="policy-note"><i></i> Connection attempts are rate limited</span>
            <button class="secondary-button" type="button" data-connect-close>Cancel</button>
            <button class="primary-button" type="submit" data-connect-submit>Connect</button>
        </div>
    </form>
</dialog>

<dialog class="record-dialog" data-record-dialog aria-labelledby="record-title">
    <form class="dialog-shell" data-record-form>
        <div class="dialog-heading">
            <div><span class="eyebrow" data-record-mode>Record</span><h2 id="record-title" data-record-title>Edit row</h2></div>
            <button class="icon-button compact" type="button" data-record-close aria-label="Close record editor"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button>
        </div>
        <div class="record-fields" data-record-fields></div>
        <div class="form-error" role="alert" data-record-error hidden></div>
        <div class="dialog-actions">
            <button class="danger-button" type="button" data-row-delete hidden>Delete row</button><button class="secondary-button" type="button" data-row-duplicate hidden>Duplicate row</button><button class="secondary-button" type="button" data-row-copy hidden>Copy JSON</button>
            <span class="toolbar-spacer"></span>
            <button class="secondary-button" type="button" data-record-close>Cancel</button>
            <button class="primary-button" type="submit" data-record-save>Save changes</button>
        </div>
    </form>
</dialog>

<dialog class="confirm-dialog" data-confirm-dialog aria-labelledby="confirm-title">
    <form class="dialog-shell" data-confirm-form>
        <div class="dialog-heading"><div><span class="eyebrow">Confirmation required</span><h2 id="confirm-title" data-confirm-title>Confirm action</h2></div></div>
        <p class="dialog-intro" data-confirm-message></p>
        <label class="field confirm-field"><span data-confirm-label>Type the target name</span><input data-confirm-input autocomplete="off" spellcheck="false"></label>
        <div class="form-error" role="alert" data-confirm-error hidden></div>
        <div class="dialog-actions"><span class="toolbar-spacer"></span><button class="secondary-button" type="button" data-confirm-cancel>Cancel</button><button class="danger-button" type="submit">Confirm</button></div>
    </form>
</dialog>

<dialog class="import-dialog" data-import-dialog aria-labelledby="import-title">
    <form class="dialog-shell" data-import-form>
        <div class="dialog-heading"><div><span class="eyebrow">CSV import</span><h2 id="import-title">Import rows</h2></div><button class="icon-button compact" type="button" data-import-close aria-label="Close import"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button></div>
        <div class="connection-fields"><label class="field field-wide"><span>CSV file</span><input type="file" accept=".csv,text/csv" data-import-file required></label><label class="field"><span>Delimiter</span><input value="," maxlength="1" data-import-delimiter></label><label class="field"><span>NULL marker</span><input value="\N" data-import-null></label></div>
        <div class="import-preview" data-import-preview><div class="grid-state">Choose a CSV file to preview its mapping.</div></div>
        <div class="form-error" role="alert" data-import-error hidden></div>
        <div class="dialog-actions"><span class="toolbar-spacer"></span><button class="secondary-button" type="button" data-import-close>Cancel</button><button class="primary-button" type="submit" data-import-start disabled>Import rows</button></div>
    </form>
</dialog>

<dialog class="schema-dialog" data-schema-dialog aria-labelledby="schema-title">
    <form class="dialog-shell" data-schema-form>
        <div class="dialog-heading"><div><span class="eyebrow">Schema operation</span><h2 id="schema-title" data-schema-title>Database action</h2></div><button class="icon-button compact" type="button" data-schema-close aria-label="Close schema operation"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button></div>
        <div class="connection-fields" data-schema-fields></div>
        <section class="sql-preview" data-schema-preview hidden><h3>SQL preview</h3><pre data-schema-sql></pre><ul data-schema-warnings></ul></section>
        <div class="form-error" role="alert" data-schema-error hidden></div>
        <div class="dialog-actions"><span class="toolbar-spacer"></span><button class="secondary-button" type="button" data-schema-close>Cancel</button><button class="secondary-button" type="button" data-schema-preview-button>Preview SQL</button><button class="danger-button" type="submit" data-schema-apply disabled>Apply</button></div>
    </form>
</dialog>

<dialog class="import-dialog" data-sql-import-dialog aria-labelledby="sql-import-title">
    <form class="dialog-shell" data-sql-import-form>
        <div class="dialog-heading"><div><span class="eyebrow">Database import</span><h2 id="sql-import-title">Run SQL file</h2></div><button class="icon-button compact" type="button" data-sql-import-close aria-label="Close SQL import"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button></div>
        <label class="field"><span>SQL file · maximum 5 MB</span><input type="file" accept=".sql,text/sql,text/plain" data-sql-import-file required></label>
        <div class="import-preview" data-sql-import-preview><div class="grid-state">Choose a SQL file to inspect its statements before execution.</div></div>
        <label class="field" data-sql-import-confirm-field hidden><span data-sql-import-confirm-label>Confirmation</span><input name="confirmation" autocomplete="off"></label>
        <p class="dialog-intro">Statements run using the selected database and the connected account's real privileges. DDL can implicitly commit and cannot be reliably rolled back.</p>
        <div class="form-error" role="alert" data-sql-import-error hidden></div>
        <div class="dialog-actions"><span class="toolbar-spacer"></span><button class="secondary-button" type="button" data-sql-import-close>Cancel</button><button class="danger-button" type="submit" data-sql-import-start disabled>Run import</button></div>
    </form>
</dialog>

<?php if (!$readOnly): ?>
<dialog class="schema-dialog" data-privilege-dialog aria-labelledby="privilege-title">
    <form class="dialog-shell" data-privilege-form>
        <div class="dialog-heading"><div><span class="eyebrow">Effective privileges</span><h2 id="privilege-title" data-privilege-title>User privileges</h2></div><button class="icon-button compact" type="button" data-privilege-close aria-label="Close privileges"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button></div>
        <div class="grant-list" data-current-grants></div>
        <div class="connection-fields"><label class="field"><span>Action</span><select name="action"><option value="grant">Grant</option><option value="revoke">Revoke</option></select></label><label class="field"><span>Scope</span><select name="scope"><option value="database">Database</option><option value="table">Table</option><option value="global">Global · high risk</option></select></label><label class="field"><span>Database</span><input name="database" autocomplete="off"></label><label class="field"><span>Table · table scope only</span><input name="table" autocomplete="off"></label><fieldset class="privilege-grid field-wide" data-privilege-options><legend>Privileges</legend></fieldset><label class="field field-wide"><span data-privilege-confirm-label>Confirmation</span><input name="confirmation" required autocomplete="off"></label></div>
        <p class="dialog-intro">Global grants affect every database on the server. Grant only the minimum scope and privileges required.</p><div class="form-error" role="alert" data-privilege-error hidden></div>
        <div class="dialog-actions"><span class="toolbar-spacer"></span><button class="secondary-button" type="button" data-privilege-close>Cancel</button><button class="danger-button" type="submit">Apply privilege change</button></div>
    </form>
</dialog>

<dialog class="schema-dialog" data-account-dialog aria-labelledby="account-title">
    <form class="dialog-shell" data-account-form>
        <div class="dialog-heading"><div><span class="eyebrow">Database account</span><h2 id="account-title" data-account-title>Create user</h2></div><button class="icon-button compact" type="button" data-account-close aria-label="Close account dialog"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button></div>
        <div class="connection-fields"><label class="field"><span>Username</span><input name="username" required autocomplete="off"></label><label class="field"><span>Allowed host</span><input name="host" required value="%" autocomplete="off"></label><label class="field field-wide" data-account-password-field><span>Password · minimum 12 characters</span><input name="password" type="password" minlength="12" autocomplete="new-password"></label><label class="field field-wide"><span data-account-confirm-label>Type username@host to confirm</span><input name="confirmation" required autocomplete="off"></label></div>
        <p class="dialog-intro" data-account-warning>Creates a login account without grants. Assign only the minimum privileges required using audited SQL.</p>
        <div class="form-error" role="alert" data-account-error hidden></div>
        <div class="dialog-actions"><span class="toolbar-spacer"></span><button class="secondary-button" type="button" data-account-close>Cancel</button><button class="danger-button" type="submit" data-account-submit>Create user</button></div>
    </form>
</dialog>
<?php endif; ?>
</body>
</html>

