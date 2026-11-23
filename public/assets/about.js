(function () {
    'use strict';

    const modal = document.getElementById('about');
    if (!modal) return;

    const versionEl = document.getElementById('aboutVersion');
    const accessEl = document.getElementById('aboutAccess');

    let loaded = false;
    let loading = false;

    async function fill() {
        if (loaded || loading) return;
        loading = true;

        try {
            const config = await API.config();
            const app = config.app || {};
            const access = config.access || {};

            versionEl.textContent = app.version || '—';

            const group = access.group === 'local' ? 'локальная сеть' : 'снаружи';
            const edit = access.can_edit ? 'редактирование' : 'только чтение';
            accessEl.textContent = `${group}, ${edit}`;

            loaded = true;
        } catch (error) {
            versionEl.textContent = '—';
            accessEl.textContent = 'нет данных';
        } finally {
            loading = false;
        }
    }

    function open() {
        modal.hidden = false;
        document.body.classList.add('is-locked');
        fill();
    }

    function close() {
        modal.hidden = true;
        document.body.classList.remove('is-locked');
    }

    document.addEventListener('click', (event) => {
        if (event.target.closest('#btnAbout')) {
            open();
            return;
        }

        if (event.target.closest('[data-close-about]')) {
            close();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            close();
        }
    });
})();
