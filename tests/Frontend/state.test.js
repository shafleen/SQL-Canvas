import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeTheme, sanitizePreferences, savePreferences, loadPreferences, STORAGE_KEY } from '../../public/assets/js/state.js';
import { sanitizeExplorerState } from '../../public/assets/js/modules/explorer.js';
import { csvCell, formatSqlText } from '../../public/assets/js/modules/workspace.js';

test('theme normalization accepts only supported themes', () => {
    assert.equal(normalizeTheme('light'), 'light');
    assert.equal(normalizeTheme('unknown', true), 'dark');
});

test('workspace preferences contain booleans and no sensitive connection fields', () => {
    const preferences = sanitizePreferences({ theme: 'light', explorerCollapsed: 1, password: 'never-store-me' });
    assert.deepEqual(preferences, {
        theme: 'light',
        explorerCollapsed: false,
        inspectorCollapsed: false,
        resultsCollapsed: false,
    });
    assert.equal('password' in preferences, false);
});

test('preferences round-trip through a storage-compatible adapter', () => {
    const values = new Map();
    const storage = { getItem: (key) => values.get(key) ?? null, setItem: (key, value) => values.set(key, value) };
    savePreferences({ theme: 'light', resultsCollapsed: true }, storage);
    assert.ok(values.has(STORAGE_KEY));
    assert.equal(loadPreferences(storage).resultsCollapsed, true);
});

test('explorer persistence accepts only bounded schema-name strings', () => {
    const names = [...Array(25)].map((_, index) => `schema_${index}`);
    const state = sanitizeExplorerState({ expandedSchemas: [...names, 3, null], password: 'not-allowed' });
    assert.equal(state.expandedSchemas.length, 20);
    assert.equal('password' in state, false);
});

test('CSV export escapes quotes and spreadsheet formulas', () => {
    assert.equal(csvCell('A "quoted" value'), '"A ""quoted"" value"');
    assert.equal(csvCell('=2+2'), '"\'=2+2"');
    assert.equal(csvCell(null), '');
});

test('SQL formatter normalizes common clauses without executing or parsing SQL', () => {
    assert.equal(formatSqlText('select code from projects where status = \'active\' order by code'), "SELECT code\nFROM projects\nWHERE status = 'active'\nORDER BY code");
});


