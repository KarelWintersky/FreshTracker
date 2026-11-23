(function () {
    'use strict';

    const BASE = '/api';

    async function request(path, options = {}) {
        const headers = { Accept: 'application/json' };
        if (options.body !== undefined) {
            headers['Content-Type'] = 'application/json';
        }

        let response;
        try {
            response = await fetch(BASE + path, {
                credentials: 'same-origin',
                ...options,
                headers: { ...headers, ...(options.headers || {}) },
            });
        } catch (e) {
            const error = new Error('Нет связи с сервером');
            error.status = 0;
            throw error;
        }

        let payload = null;
        try {
            payload = await response.json();
        } catch (e) {
            /* non-JSON error page */
        }

        if (!response.ok || !payload || payload.status === 'error') {
            const error = new Error((payload && payload.message) || `Ошибка ${response.status}`);
            error.status = response.status;
            error.payload = payload;
            throw error;
        }

        return payload.data;
    }

    function query(params) {
        const search = new URLSearchParams();
        Object.entries(params || {}).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) {
                search.set(key, value);
            }
        });
        const string = search.toString();
        return string ? `?${string}` : '';
    }

    window.API = {
        config: () => request('/config/'),
        types: () => request('/types/'),
        createType: (data) => request('/types/', { method: 'POST', body: JSON.stringify(data) }),
        updateType: (id, data) => request(`/types/${id}/`, { method: 'PUT', body: JSON.stringify(data) }),
        deleteType: (id) => request(`/types/${id}/`, { method: 'DELETE' }),
        moveType: (id, direction) => request(`/types/${id}/move`, {
            method: 'POST',
            body: JSON.stringify({ direction }),
        }),
        products: (params) => request(`/products/${query(params)}`),
        create: (data) => request('/products/', { method: 'POST', body: JSON.stringify(data) }),
        update: (id, data) => request(`/products/${id}/`, { method: 'PUT', body: JSON.stringify(data) }),
        use: (id) => request(`/products/${id}/use`, { method: 'POST', body: '{}' }),
        dispose: (id) => request(`/products/${id}/dispose`, { method: 'POST', body: '{}' }),
        restore: (id) => request(`/products/${id}/restore`, { method: 'POST', body: '{}' }),
        remove: (id) => request(`/products/${id}/`, { method: 'DELETE' }),
        stats: () => request('/stats/'),
        login: (scope, password) => request('/auth/login', {
            method: 'POST',
            body: JSON.stringify({ scope, password }),
        }),
        logout: () => request('/auth/logout', { method: 'POST', body: '{}' }),
    };
})();
