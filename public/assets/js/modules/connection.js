import { ApiError } from '../api.js';

const selectors = {
    shell: '[data-app-shell]',
    dialog: '[data-connection-dialog]',
    form: '[data-connection-form]',
    error: '[data-connection-error]',
    submit: '[data-connect-submit]',
};

export function initConnection(client) {
    const shell = document.querySelector(selectors.shell);
    const dialog = document.querySelector(selectors.dialog);
    const form = document.querySelector(selectors.form);
    const errorBox = document.querySelector(selectors.error);
    const submit = document.querySelector(selectors.submit);
    const tlsToggle = form.elements.namedItem('tlsEnabled');
    const tlsCa = document.querySelector('[data-tls-ca]');

    function open() {
        errorBox.hidden = true;
        dialog.showModal();
        form.elements.namedItem('host').focus();
    }

    function close() {
        dialog.close();
    }

    function activity(title, detail) {
        const log = document.querySelector('[data-activity-log]');
        const entry = document.createElement('div');
        const time = document.createElement('time');
        const strong = document.createElement('strong');
        const copy = document.createElement('span');
        entry.className = 'activity-entry';
        time.textContent = new Intl.DateTimeFormat([], { hour: '2-digit', minute: '2-digit' }).format(new Date());
        strong.textContent = title;
        copy.textContent = detail;
        entry.append(time, strong, copy);
        log.prepend(entry);
        document.querySelector('[data-activity-summary]').textContent = title;
    }

    function render(state) {
        const connected = state.connected === true;
        const connection = state.connection ?? {};
        const server = state.server ?? {};
        shell.dataset.connected = String(connected);
        shell.dataset.canSetGlobalVariables=String(connected&&server.features?.setGlobalVariables===true);
        document.querySelector('[data-connection-label]').textContent = connected ? connection.label : 'Connect database';
        document.querySelector('[data-connection-detail]').textContent = connected
            ? `${server.vendor ?? 'Database'} ${server.version ?? ''}`.trim()
            : 'No database connected';
        document.querySelector('[data-inspector-status]').textContent = connected ? 'Connected' : 'Disconnected';
        document.querySelector('[data-inspector-status]').className = connected ? 'pass' : 'neutral';
        document.querySelector('[data-inspector-server]').textContent = connected ? `${connection.host}:${connection.port}` : '—';
        document.querySelector('[data-inspector-user]').textContent = connected ? server.currentUser || connection.username : '—';
        document.querySelector('[data-inspector-grants]').textContent = connected ? `${server.grantCount ?? 0} reported` : '—';
        document.querySelector('[data-disconnect]').hidden = !connected;
        document.querySelectorAll('[data-new-query]').forEach((button) => { button.hidden = !connected; });
        document.querySelectorAll('[data-global-view]').forEach((button) => {
            if (button.dataset.globalView !== 'home') button.hidden = !connected || (button.dataset.globalView === 'users' && server.features?.userAdministration !== true);
        });
        const createDatabase=document.querySelector('[data-schema-create-database]');if(createDatabase)createDatabase.hidden=!connected||server.features?.createDatabase!==true;
        const createUser=document.querySelector('[data-admin-create-user]');if(createUser)createUser.dataset.capabilityHidden=String(!connected||server.features?.createUser!==true);
        const usersView=document.querySelector('[data-admin-view="users"]');if(usersView)usersView.hidden=!connected||server.features?.userAdministration!==true;
        document.querySelector('[data-status-connection]').textContent = connected ? 'Connected' : 'Disconnected';
        document.querySelector('[data-status-server]').textContent = connected ? `${server.vendor} ${server.version}` : 'PHP workspace';
        document.querySelector('[data-handshake-status]').textContent = connected ? 'Verified and active' : 'Waiting for input';
        document.querySelector('[data-server-node]').classList.toggle('muted', !connected);
        document.querySelector('[data-server-node-title]').textContent = 'Query';
        document.querySelector('[data-server-node-detail]').textContent = connected ? `${server.vendor} ready` : 'connect first';

        if (connected) {
            document.querySelector('[data-welcome-kicker]').textContent = 'Database workspace';
            document.querySelector('[data-welcome-title]').textContent = 'Connected.';
            document.querySelector('[data-welcome-subtitle]').textContent = `${server.vendor} ${server.version}`;
            document.querySelector('[data-welcome-lede]').textContent = 'Expand a database in the explorer to inspect its permitted tables, views, routines, triggers, events, columns, and indexes.';
        } else {
            document.querySelector('[data-welcome-kicker]').textContent = 'Database workspace';
            document.querySelector('[data-welcome-title]').textContent = 'Your database.';
            document.querySelector('[data-welcome-subtitle]').textContent = 'A whole new perspective.';
            document.querySelector('[data-welcome-lede]').textContent = 'Connect to an allowed MySQL or MariaDB server. SQL Canvas will detect the server, load only visible metadata, and keep the password out of browser storage.';
        }

        document.dispatchEvent(new CustomEvent(connected ? 'sqlcanvas:connected' : 'sqlcanvas:disconnected', { detail: state }));
    }

    document.querySelectorAll('[data-connect-open]').forEach((button) => button.addEventListener('click', open));
    document.querySelectorAll('[data-connect-close]').forEach((button) => button.addEventListener('click', close));
    tlsToggle.addEventListener('change', () => { tlsCa.hidden = !tlsToggle.checked; });
    document.querySelector('[data-disconnect]').addEventListener('click', async () => {
        try {
            const state = await client.post('/session/disconnect');
            render(state);
            activity('Disconnected', 'Server-side connection credentials were removed from the session.');
        } catch (error) {
            activity('Disconnect failed', error instanceof Error ? error.message : 'The session could not be cleared.');
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        errorBox.hidden = true;
        submit.disabled = true;
        submit.textContent = 'Connecting…';
        const data = new FormData(form);
        const input = {
            label: data.get('label') ?? '',
            host: data.get('host') ?? '',
            port: Number(data.get('port') ?? 3306),
            username: data.get('username') ?? '',
            password: data.get('password') ?? '',
            database: data.get('database') ?? '',
            tls: { enabled: tlsToggle.checked, caPath: data.get('tlsCaPath') ?? '' },
        };

        try {
            const state = await client.post('/session/connect', input);
            form.elements.namedItem('password').value = '';
            render(state);
            close();
            activity('Connected', `${state.server.vendor} ${state.server.version} at ${state.connection.host}:${state.connection.port}`);
        } catch (error) {
            form.elements.namedItem('password').value = '';
            errorBox.textContent = error instanceof ApiError ? error.message : 'The connection could not be completed.';
            errorBox.hidden = false;
            activity('Connection rejected', errorBox.textContent);
        } finally {
            submit.disabled = false;
            submit.textContent = 'Connect';
        }
    });

    client.get('/session').then(render).catch((error) => {
        activity('Session unavailable', error instanceof Error ? error.message : 'SQL Canvas could not read the session.');
    });

    return { open, render, activity };
}

