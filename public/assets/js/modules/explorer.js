const STORAGE_KEY = 'sql-canvas.explorer.v1';

export function sanitizeExplorerState(value = {}) {
    const expandedSchemas = Array.isArray(value.expandedSchemas)
        ? value.expandedSchemas.filter((name) => typeof name === 'string').slice(0, 20)
        : [];
    return { expandedSchemas };
}

export function initExplorer(client) {
    const tree = document.querySelector('[data-explorer-tree]');
    const empty = document.querySelector('[data-explorer-empty]');
    const filter = document.querySelector('[data-explorer-filter]');
    const feedback = document.querySelector('[data-explorer-feedback]');
    let state = loadState();

    function loadState() {
        try {
            return sanitizeExplorerState(JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}'));
        } catch {
            return sanitizeExplorerState();
        }
    }

    function saveState() {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(sanitizeExplorerState(state)));
    }

    function setFeedback(message = '') {
        feedback.textContent = message;
    }

    function row(label, kind, expandable = false) {
        const button = document.createElement(expandable ? 'button' : 'div');
        const chevron = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        const icon = document.createElement('span');
        const text = document.createElement('span');
        if (expandable) button.type = 'button';
        button.className = `tree-row ${kind}-row`;
        button.setAttribute('role', 'treeitem');
        button.tabIndex = -1;
        chevron.setAttribute('viewBox', '0 0 12 12');
        chevron.classList.add('tree-chevron');
        path.setAttribute('d', expandable ? 'm4 2 4 4-4 4' : '');
        chevron.append(path);
        icon.className = 'tree-icon';
        icon.textContent = kind === 'database' ? 'DB' : kind === 'table' ? '▦' : kind === 'view' ? '◫' : kind === 'column' ? '·' : '◇';
        text.className = 'tree-label';
        text.textContent = label;
        button.append(chevron, icon, text);
        if (expandable) button.setAttribute('aria-expanded', 'false');
        return button;
    }

    async function loadDatabases() {
        empty.hidden = true;
        tree.hidden = false;
        filter.disabled = false;
        tree.replaceChildren();
        setFeedback();
        const loading = document.createElement('div');
        loading.className = 'explorer-loading';
        loading.textContent = 'Loading permitted databases…';
        tree.append(loading);

        try {
            const data = await client.get('/databases');
            tree.replaceChildren();
            if (data.databases.length === 0) {
                loading.textContent = 'This account cannot see any databases.';
                tree.append(loading);
                return;
            }
            data.databases.forEach(({ name }, index) => tree.append(databaseNode(name, index === 0)));
            for (const schema of state.expandedSchemas) {
                const node = [...tree.querySelectorAll('[data-schema]')].find((item) => item.dataset.schema === schema);
                if (node) await toggleSchema(node);
            }
        } catch (error) {
            tree.replaceChildren();
            setFeedback(error instanceof Error ? error.message : 'Databases could not be loaded.');
        }
    }

    function databaseNode(name, first) {
        const node = document.createElement('div');
        const button = row(name, 'database', true);
        const children = document.createElement('div');
        node.className = 'tree-node';
        node.dataset.schema = name;
        node.dataset.search = name.toLowerCase();
        children.className = 'tree-children';
        children.setAttribute('role', 'group');
        children.hidden = true;
        button.tabIndex = first ? 0 : -1;
        button.addEventListener('click', () => toggleSchema(node));
        node.append(button, children);
        return node;
    }

    async function toggleSchema(node) {
        const button = node.querySelector(':scope > .tree-row');
        const children = node.querySelector(':scope > .tree-children');
        const opening = button.getAttribute('aria-expanded') !== 'true';
        button.setAttribute('aria-expanded', String(opening));
        children.hidden = !opening;
        const schema = node.dataset.schema;
        document.dispatchEvent(new CustomEvent('sqlcanvas:schema-selected', { detail: { schema } }));
        state.expandedSchemas = opening
            ? [...new Set([...state.expandedSchemas, schema])]
            : state.expandedSchemas.filter((name) => name !== schema);
        saveState();

        if (!opening || children.dataset.loaded === 'true') return;
        children.textContent = 'Loading objects…';
        try {
            const data = await client.get(`/databases/${encodeURIComponent(schema)}/objects`);
            children.replaceChildren();
            const groups = [
                ['tables', 'Tables'], ['views', 'Views'], ['routines', 'Routines'], ['triggers', 'Triggers'], ['events', 'Events'],
            ];
            for (const [key, label] of groups) {
                if (!data.objects[key]?.length) continue;
                const heading = document.createElement('div');
                heading.className = 'tree-group-label';
                heading.textContent = `${label} · ${data.objects[key].length}`;
                children.append(heading);
                data.objects[key].forEach((object) => children.append(objectNode(schema, object, key)));
            }
            if (children.childElementCount === 0) children.textContent = 'No visible objects.';
            children.dataset.loaded = 'true';
        } catch (error) {
            children.textContent = '';
            setFeedback(error instanceof Error ? error.message : 'Schema objects could not be loaded.');
        }
    }

    function objectNode(schema, object, group) {
        const node = document.createElement('div');
        const expandable = group === 'tables' || group === 'views';
        const kind = group === 'tables' ? 'table' : group === 'views' ? 'view' : 'object';
        const button = row(object.name, kind, expandable);
        node.className = 'tree-node';
        node.dataset.search = `${schema} ${group} ${object.name}`.toLowerCase();
        node.append(button);
        if (expandable) {
            const children = document.createElement('div');
            children.className = 'tree-children';
            children.setAttribute('role', 'group');
            children.hidden = true;
            node.append(children);
            button.addEventListener('click', () => {
                document.dispatchEvent(new CustomEvent('sqlcanvas:table-selected', { detail: { schema, table: object.name, kind } }));
                if (window.matchMedia('(max-width: 720px)').matches) document.querySelector('[data-app-shell]')?.classList.remove('explorer-overlay');
                toggleTable(node, schema, object.name);
            });
        } else {
            button.addEventListener('click', () => {
                document.dispatchEvent(new CustomEvent('sqlcanvas:object-selected', { detail: { schema, name: object.name, type: group.replace(/s$/, '') } }));
                if (window.matchMedia('(max-width: 720px)').matches) document.querySelector('[data-app-shell]')?.classList.remove('explorer-overlay');
            });
        }
        return node;
    }

    async function toggleTable(node, schema, table) {
        const button = node.querySelector(':scope > .tree-row');
        const children = node.querySelector(':scope > .tree-children');
        const opening = button.getAttribute('aria-expanded') !== 'true';
        button.setAttribute('aria-expanded', String(opening));
        children.hidden = !opening;
        if (!opening || children.dataset.loaded === 'true') return;
        children.textContent = 'Loading structure…';
        try {
            const data = await client.get(`/databases/${encodeURIComponent(schema)}/tables/${encodeURIComponent(table)}`);
            children.replaceChildren();
            appendDetails(children, 'Columns', data.columns, (item) => `${item.name} · ${item.type}`, 'column');
            appendDetails(children, 'Indexes', data.indexes, (item) => `${item.name} · ${item.columnName}`, 'index');
            appendDetails(children, 'Foreign keys', data.foreignKeys ?? [], (item) => `${item.columnName} → ${item.referencedTable}.${item.referencedColumn}`, 'index');
            children.dataset.loaded = 'true';
        } catch (error) {
            children.textContent = '';
            setFeedback(error instanceof Error ? error.message : 'Table structure could not be loaded.');
        }
    }

    function appendDetails(parent, label, items, formatter, kind) {
        if (!items.length) return;
        const heading = document.createElement('div');
        heading.className = 'tree-group-label';
        heading.textContent = `${label} · ${items.length}`;
        parent.append(heading);
        items.forEach((item) => {
            const detail = row(formatter(item), kind, false);
            detail.classList.add('detail-row');
            detail.tabIndex = -1;
            parent.append(detail);
        });
    }

    function reset() {
        tree.replaceChildren();
        tree.hidden = true;
        empty.hidden = false;
        filter.value = '';
        filter.disabled = true;
        setFeedback();
    }

    filter.addEventListener('input', () => {
        const query = filter.value.trim().toLowerCase();
        tree.querySelectorAll('.tree-node').forEach((node) => {
            node.hidden = query !== '' && !node.textContent.toLowerCase().includes(query) && !node.dataset.search?.includes(query);
        });
    });

    tree.addEventListener('keydown', (event) => {
        const items = [...tree.querySelectorAll('.tree-row:not([hidden])')].filter((item) => item.offsetParent !== null);
        const index = items.indexOf(document.activeElement);
        if (index < 0) return;
        if (event.key === 'ArrowDown') items[Math.min(index + 1, items.length - 1)]?.focus();
        else if (event.key === 'ArrowUp') items[Math.max(index - 1, 0)]?.focus();
        else if (event.key === 'Home') items[0]?.focus();
        else if (event.key === 'End') items.at(-1)?.focus();
        else if (event.key === 'ArrowRight' && items[index].getAttribute('aria-expanded') === 'false') items[index].click();
        else if (event.key === 'ArrowLeft' && items[index].getAttribute('aria-expanded') === 'true') items[index].click();
        else return;
        event.preventDefault();
    });

    document.addEventListener('sqlcanvas:connected', loadDatabases);
    document.addEventListener('sqlcanvas:disconnected', reset);
    return { loadDatabases, reset };
}

