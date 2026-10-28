export const STORAGE_KEY = 'sql-canvas.workspace.v1';

export const defaultPreferences = Object.freeze({
    theme: 'dark',
    explorerCollapsed: false,
    inspectorCollapsed: false,
    resultsCollapsed: false,
});

export function normalizeTheme(theme, systemDark = true) {
    if (theme === 'dark' || theme === 'light') return theme;
    return systemDark ? 'dark' : 'light';
}

export function sanitizePreferences(value = {}) {
    return {
        theme: normalizeTheme(value.theme),
        explorerCollapsed: value.explorerCollapsed === true,
        inspectorCollapsed: value.inspectorCollapsed === true,
        resultsCollapsed: value.resultsCollapsed === true,
    };
}

export function loadPreferences(storage = window.localStorage) {
    try {
        const stored = JSON.parse(storage.getItem(STORAGE_KEY) ?? '{}');
        return sanitizePreferences(stored);
    } catch {
        return { ...defaultPreferences };
    }
}

export function savePreferences(preferences, storage = window.localStorage) {
    storage.setItem(STORAGE_KEY, JSON.stringify(sanitizePreferences(preferences)));
}

