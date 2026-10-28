import { loadPreferences, savePreferences } from './state.js';
import { ApiClient } from './api.js';
import { initConnection } from './modules/connection.js';
import { initExplorer } from './modules/explorer.js';
import { initWorkspace } from './modules/workspace.js';
import { initManagement } from './modules/management.js';

const root = document.documentElement;
const shell = document.querySelector('[data-app-shell]');
const dialog = document.querySelector('[data-command-dialog]');
const commandSearch = document.querySelector('[data-command-search]');
const commandList = document.querySelector('[data-command-list]');
let preferences = loadPreferences();

const commands = [
    { id: 'connect', label: 'Connect database', detail: 'Open the secure server connection form' },
    { id: 'query', label: 'New SQL query', detail: 'Open a deliberate query workspace', connected: true },
    { id: 'canvas', label: 'Open schema canvas', detail: 'Visualize tables and foreign-key relationships', connected: true },
    { id: 'server', label: 'Open server status', detail: 'Inspect live server variables and activity', connected: true },
    { id: 'users', label: 'Open database users', detail: 'Inspect accounts and effective privileges', connected: true },
    { id: 'create-database', label: 'Create database', detail: 'Preview and apply a structured database operation', connected: true, selector: '[data-schema-create-database]' },
    { id: 'create-table', label: 'Create table', detail: 'Build a table in the selected database', connected: true, selector: '[data-schema-create-table]' },
    { id: 'refresh', label: 'Refresh database explorer', detail: 'Reload permitted database metadata', connected: true },
    { id: 'theme', label: 'Switch color theme', detail: 'Toggle between dark and light workspace' },
    { id: 'explorer', label: 'Toggle explorer', detail: 'Show or hide the connection explorer' },
    { id: 'inspector', label: 'Toggle object inspector', detail: 'Show or hide connection and object details' },
    { id: 'results', label: 'Toggle session activity', detail: 'Expand or collapse the bottom activity panel' },
];

function applyPreferences() {
    root.dataset.theme = preferences.theme;
    shell.classList.toggle('explorer-collapsed', preferences.explorerCollapsed);
    shell.classList.toggle('inspector-collapsed', preferences.inspectorCollapsed);
    shell.classList.toggle('results-collapsed', preferences.resultsCollapsed);
    document.querySelectorAll('[data-toggle-pane="results"]').forEach((button) => {
        button.setAttribute('aria-expanded', String(!preferences.resultsCollapsed));
    });
}

function commit() {
    applyPreferences();
    savePreferences(preferences);
}

function togglePane(name) {
    const narrow = window.matchMedia('(max-width: 720px)').matches;
    if (name === 'explorer' && narrow) {
        shell.classList.toggle('explorer-overlay');
        return;
    }
    if (name === 'inspector' && window.matchMedia('(max-width: 1020px)').matches) {
        shell.classList.toggle('inspector-overlay');
        return;
    }
    const key = `${name}Collapsed`;
    preferences = { ...preferences, [key]: !preferences[key] };
    commit();
}

function runCommand(id) {
    if (id === 'connect') {
        dialog.close();
        document.querySelector('[data-connect-open]')?.click();
        return;
    } else if (id === 'query') {
        dialog.close();
        document.querySelector('[data-new-query]:not([hidden])')?.click();
        return;
    } else if (['canvas','server','users'].includes(id)) {
        dialog.close(); document.querySelector(`[data-global-view="${id}"]`)?.click(); return;
    } else if (id === 'create-database' || id === 'create-table') {
        dialog.close(); document.querySelector(id === 'create-database'?'[data-schema-create-database]':'[data-schema-create-table]')?.click(); return;
    } else if (id === 'refresh') {
        dialog.close(); document.dispatchEvent(new CustomEvent('sqlcanvas:connected',{detail:{connected:true}})); return;
    } else if (id === 'theme') {
        preferences = { ...preferences, theme: preferences.theme === 'dark' ? 'light' : 'dark' };
        commit();
    } else {
        togglePane(id);
    }
    dialog.close();
}

function renderCommands(query = '') {
    const needle = query.trim().toLowerCase();
    commandList.replaceChildren();
    commands.filter((command) => {
        const target=command.selector?document.querySelector(command.selector):null;
        const globalTarget=['canvas','server','users'].includes(command.id)?document.querySelector(`[data-global-view="${command.id}"]`):null;
        return (!command.connected || shell.dataset.connected === 'true') && (!command.selector || (target && !target.hidden)) && (!globalTarget || !globalTarget.hidden) && `${command.label} ${command.detail}`.toLowerCase().includes(needle);
    }).forEach((command) => {
        const button = document.createElement('button');
        const copy = document.createElement('span');
        const label = document.createElement('strong');
        const detail = document.createElement('small');
        button.type = 'button';
        button.className = 'command-item';
        button.setAttribute('role', 'option');
        label.textContent = command.label;
        detail.textContent = command.detail;
        copy.append(label, detail);
        button.append(copy);
        button.addEventListener('click', () => runCommand(command.id));
        commandList.append(button);
    });
}

document.querySelector('[data-theme-toggle]')?.addEventListener('click', () => runCommand('theme'));
document.querySelectorAll('[data-toggle-pane]').forEach((button) => {
    button.addEventListener('click', () => togglePane(button.dataset.togglePane));
});
document.querySelector('[data-command-open]')?.addEventListener('click', () => {
    renderCommands();
    dialog.showModal();
    commandSearch.focus();
});
commandSearch?.addEventListener('input', () => renderCommands(commandSearch.value));
document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        renderCommands();
        dialog.showModal();
        commandSearch.focus();
    }
});

applyPreferences();
const api = new ApiClient();
initExplorer(api);
const connection = initConnection(api);
initWorkspace(api, connection);
initManagement(api, connection);

