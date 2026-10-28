export class ApiError extends Error {
    constructor(code, message, status, details = {}) {
        super(message);
        this.name = 'ApiError';
        this.code = code;
        this.status = status;
        this.details = details;
    }
}

export class ApiClient {
    constructor(documentRoot = document) {
        this.basePath = documentRoot.querySelector('meta[name="sql-canvas-base-path"]')?.content ?? '';
        this.csrfMeta = documentRoot.querySelector('meta[name="sql-canvas-csrf"]');
    }

    get(path, query = {}) {
        const parameters = new URLSearchParams();
        Object.entries(query).forEach(([key, value]) => {
            if (value !== undefined && value !== null && value !== '') parameters.set(key, String(value));
        });
        const suffix = parameters.size ? `?${parameters}` : '';
        return this.request(`${path}${suffix}`, { method: 'GET' });
    }

    post(path, body = {}) {
        return this.request(path, { method: 'POST', body: JSON.stringify(body) });
    }

    async request(path, options) {
        const headers = { Accept: 'application/json' };
        if (options.body !== undefined) headers['Content-Type'] = 'application/json';
        if (options.method !== 'GET' && this.csrfMeta?.content) {
            headers['X-CSRF-Token'] = this.csrfMeta.content;
        }

        const response = await fetch(`${this.basePath}/api${path}`, {
            ...options,
            headers,
            credentials: 'same-origin',
        });
        let payload;
        try {
            payload = await response.json();
        } catch {
            throw new ApiError('invalid_response', 'SQL Canvas received an invalid server response.', response.status);
        }

        if (!response.ok || payload.ok !== true) {
            const error = payload.error ?? {};
            throw new ApiError(error.code ?? 'request_failed', error.message ?? 'The request failed.', response.status, error.details ?? {});
        }

        if (payload.data?.csrfToken && this.csrfMeta) {
            this.csrfMeta.content = payload.data.csrfToken;
        }
        return payload.data;
    }
}

