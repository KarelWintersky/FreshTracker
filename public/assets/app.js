(function () {
    'use strict';

    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    const state = {
        config: null,
        types: [],
        products: [],
        history: [],
        stats: null,
        view: 'active',
        bucket: 'all',
        typeFilter: '',
        query: '',
        editing: null,
        selectedType: '',
    };

    const ui = {
        app: $('#app'),
        blocked: $('#blocked'),
        summary: $('#summary'),
        productsList: $('#productsList'),
        historyList: $('#historyList'),
        historyStats: $('#historyStats'),
        viewActive: $('#viewActive'),
        viewHistory: $('#viewHistory'),
        typeFilter: $('#typeFilter'),
        typeChips: $('#typeChips'),
        search: $('#search'),
        sheet: $('#sheet'),
        sheetTitle: $('#sheetTitle'),
        form: $('#productForm'),
        formError: $('#formError'),
        login: $('#login'),
        loginForm: $('#loginForm'),
        loginError: $('#loginError'),
        toasts: $('#toasts'),
    };

    /* ── Helpers ─────────────────────────────────────────── */

    function esc(value) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
        return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, (ch) => map[ch]);
    }

    function plural(number, one, few, many) {
        const value = Math.abs(number) % 100;
        const last = value % 10;
        if (value > 10 && value < 20) return many;
        if (last > 1 && last < 5) return few;
        if (last === 1) return one;
        return many;
    }

    function daysLabel(days) {
        if (days < 0) {
            const left = Math.abs(days);
            return `Просрочен на ${left} ${plural(left, 'день', 'дня', 'дней')}`;
        }
        if (days === 0) return 'Истекает сегодня';
        return `Осталось ${days} ${plural(days, 'день', 'дня', 'дней')}`;
    }

    function formatDate(value) {
        const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
        return match ? `${match[3]}.${match[2]}.${match[1]}` : '';
    }

    function formatGrams(grams) {
        const value = Number(grams) || 0;
        if (value >= 1000) {
            const kilograms = (value / 1000).toFixed(3).replace(/\.?0+$/, '').replace('.', ',');
            return `${kilograms} кг`;
        }
        return `${value} г`;
    }

    function todayPlus(days) {
        const date = new Date();
        date.setDate(date.getDate() + days);
        const pad = (number) => String(number).padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    }

    function typeBySlug(slug) {
        return state.types.find((type) => type.slug === slug) || null;
    }

    function canEdit() {
        return Boolean(state.config && state.config.access && state.config.access.can_edit);
    }

    function statusClass(product) {
        if (product.days_remaining < 0) return 'expired';
        if (product.days_remaining <= product.threshold_days) return 'soon';
        return 'fresh';
    }

    function toast(message, tone) {
        const node = document.createElement('div');
        node.className = 'toast' + (tone ? ` toast--${tone}` : '');
        node.textContent = message;
        ui.toasts.appendChild(node);
        setTimeout(() => {
            node.style.opacity = '0';
            node.style.transition = 'opacity .2s ease';
            setTimeout(() => node.remove(), 220);
        }, 2600);
    }

    function handleError(error) {
        if (error && error.status === 403) {
            needPassword();
            return;
        }
        toast((error && error.message) || 'Ошибка', 'error');
    }

    /* ── Rendering ───────────────────────────────────────── */

    function countBuckets() {
        const counts = { expired: 0, soon: 0, fresh: 0, active: 0 };
        state.products.forEach((product) => {
            counts.active += 1;
            counts[statusClass(product)] += 1;
        });
        return counts;
    }

    function renderSummary() {
        const counts = state.stats ? state.stats.counts : countBuckets();
        const grams = state.stats ? state.stats.weight.active_g : state.products.reduce((sum, p) => sum + p.weight_g, 0);

        const cards = [
            { bucket: 'expired', value: counts.expired, label: 'Просрочено', tone: 'danger' },
            { bucket: 'soon', value: counts.soon, label: 'Скоро истекают', tone: 'warning' },
            { bucket: 'fresh', value: counts.fresh, label: 'В запасе', tone: 'success' },
            { bucket: 'all', value: counts.active, label: 'Всего', tone: '' },
        ];

        ui.summary.innerHTML = cards.map((card) => `
            <button type="button" class="card card--${card.tone || 'neutral'} ${state.bucket === card.bucket ? 'is-active' : ''}"
                    data-bucket="${card.bucket}" aria-pressed="${state.bucket === card.bucket}">
                <span class="card__value">${card.value}</span>
                <span class="card__label">${card.label}</span>
            </button>
        `).join('') + `<div class="summary__foot">Всего в запасе: ${formatGrams(grams)}</div>`;
    }

    function emptyState(icon, title, text) {
        return `
            <div class="empty">
                <span class="empty__icon" aria-hidden="true">${icon}</span>
                <div class="empty__title">${esc(title)}</div>
                <div class="empty__text">${esc(text)}</div>
            </div>`;
    }

    function skeleton() {
        return '<div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div>';
    }

    function filtered(list, applyBucket) {
        const query = state.query;
        return list.filter((product) => {
            if (state.typeFilter && product.type !== state.typeFilter) return false;
            if (query && !product.name.toLowerCase().includes(query)) return false;
            if (applyBucket && state.bucket !== 'all' && statusClass(product) !== state.bucket) return false;
            return true;
        });
    }

    function productActions(product) {
        if (!canEdit()) return '';

        const primary = product.days_remaining < 0 ? 'dispose' : 'use';
        const useClass = primary === 'use' ? 'btn--success' : 'btn--ghost';
        const disposeClass = primary === 'dispose' ? 'btn--danger' : 'btn--ghost';

        return `
            <div class="item__actions">
                <button type="button" class="btn btn--sm ${useClass}" data-action="use" data-id="${product.id}">Использовал</button>
                <button type="button" class="btn btn--sm ${disposeClass}" data-action="dispose" data-id="${product.id}">Выкинул</button>
                <button type="button" class="btn btn--sm btn--ghost" data-action="edit" data-id="${product.id}">Изменить</button>
            </div>`;
    }

    function productHtml(product) {
        const tone = statusClass(product);

        return `
            <article class="item item--${tone}">
                <div class="item__main">
                    <div class="item__name">${esc(product.name)}</div>
                    <div class="item__meta">
                        <span class="tag">${esc(product.type_title)}</span>
                        <span>${formatGrams(product.weight_g)}</span>
                        <span class="dot">·</span>
                        <span>до ${formatDate(product.expiry_date)}</span>
                    </div>
                </div>
                <span class="item__status item__status--${tone}">${daysLabel(product.days_remaining)}</span>
                ${productActions(product)}
            </article>`;
    }

    function renderProducts() {
        const list = filtered(state.products, true);

        if (!list.length) {
            const text = state.query || state.typeFilter || state.bucket !== 'all'
                ? 'По выбранным условиям ничего нет.'
                : 'Добавьте первый продукт — и он появится здесь.';
            ui.productsList.innerHTML = emptyState('🍽️', 'Список пуст', text);
            return;
        }

        const groups = [
            { key: 'expired', title: 'Просрочено', items: list.filter((p) => statusClass(p) === 'expired') },
            { key: 'soon', title: 'Скоро истекают', items: list.filter((p) => statusClass(p) === 'soon') },
            { key: 'fresh', title: 'В запасе', items: list.filter((p) => statusClass(p) === 'fresh') },
        ].filter((group) => group.items.length);

        ui.productsList.innerHTML = groups.map((group) => `
            <section class="group">
                <h2 class="group__title">${group.title}<span class="group__count">${group.items.length}</span></h2>
                ${group.items.map(productHtml).join('')}
            </section>
        `).join('');
    }

    function historyStats() {
        const history = state.stats ? state.stats.history : { used: 0, disposed: 0, used_g: 0, disposed_g: 0, waste_percent: 0 };
        const months = (state.stats && state.stats.months) || [];

        const statsHtml = `
            <div class="stat">
                <div class="stat__label">Использовано</div>
                <div class="stat__value">${history.used}</div>
                <div class="stat__sub">${formatGrams(history.used_g)}</div>
            </div>
            <div class="stat">
                <div class="stat__label">Выброшено</div>
                <div class="stat__value">${history.disposed}</div>
                <div class="stat__sub">${formatGrams(history.disposed_g)}</div>
            </div>
            <div class="stat">
                <div class="stat__label">Доля отходов</div>
                <div class="stat__value">${history.waste_percent}%</div>
                <div class="stat__sub">от списанного</div>
            </div>`;

        const max = Math.max(1, ...months.map((month) => month.disposed_g));
        const monthsHtml = months.map((month) => {
            const label = monthLabel(month.month);
            const width = Math.max(2, Math.round((month.disposed_g / max) * 100));
            return `
                <div class="month">
                    <span>${esc(label)}</span>
                    <div class="month__bar"><div class="month__fill" style="width:${width}%"></div></div>
                    <span>${formatGrams(month.disposed_g)}</span>
                </div>`;
        }).join('');

        ui.historyStats.innerHTML = statsHtml + (monthsHtml ? `<div class="months">${monthsHtml}</div>` : '');
    }

    function monthLabel(value) {
        const match = /^(\d{4})-(\d{2})$/.exec(String(value || ''));
        if (!match) return '—';
        const date = new Date(Number(match[1]), Number(match[2]) - 1, 1);
        const month = date.toLocaleDateString('ru-RU', { month: 'short' });
        return `${month} ${date.getFullYear()}`;
    }

    function historyItemHtml(product) {
        const tone = product.status === 'used' ? 'used' : 'disposed';
        const atClose = product.expiry_days_at_close;
        let note = '';

        if (atClose !== null && atClose !== undefined) {
            note = atClose < 0
                ? `просрочен на ${Math.abs(atClose)} дн.`
                : `за ${atClose} дн. до срока`;
        }

        return `
            <article class="item item--${tone}">
                <div class="item__main">
                    <div class="item__name">${esc(product.name)}</div>
                    <div class="item__meta">
                        <span class="tag">${esc(product.type_title)}</span>
                        <span>${formatGrams(product.weight_g)}</span>
                        <span class="dot">·</span>
                        <span>${formatDate(product.closed_at || product.updated_at)}</span>
                        ${note ? `<span class="dot">·</span><span>${esc(note)}</span>` : ''}
                    </div>
                </div>
                <span class="item__status item__status--${tone}">${esc(product.status_label)}</span>
                ${canEdit() ? `<div class="item__actions">
                    <button type="button" class="btn btn--sm btn--ghost" data-action="restore" data-id="${product.id}">Вернуть</button>
                </div>` : ''}
            </article>`;
    }

    function renderHistory() {
        historyStats();

        const list = filtered(state.history, false);

        if (!list.length) {
            ui.historyList.innerHTML = emptyState('🗂️', 'История пуста', 'Использованные и выброшенные продукты появятся здесь.');
            return;
        }

        ui.historyList.innerHTML = `<section class="group">${list.map(historyItemHtml).join('')}</section>`;
    }

    function render() {
        renderSummary();

        if (state.view === 'history') {
            renderHistory();
        } else {
            renderProducts();
        }
    }

    function renderTypeFilter() {
        const chips = [{ slug: '', title: 'Все' }].concat(state.types);
        ui.typeFilter.innerHTML = chips.map((type) => `
            <button type="button" class="chip ${state.typeFilter === type.slug ? 'is-active' : ''}"
                    data-filter-type="${esc(type.slug)}">${esc(type.title)}</button>
        `).join('');
    }

    function renderTypeChips() {
        ui.typeChips.innerHTML = state.types.map((type) => `
            <button type="button" class="chip ${state.selectedType === type.slug ? 'is-active' : ''}"
                    data-form-type="${esc(type.slug)}">${esc(type.title)}</button>
        `).join('');
    }

    function renderAccess() {
        const access = (state.config && state.config.access) || {};
        const editable = Boolean(access.can_edit);

        $('#btnAdd').hidden = !editable;
        $('#btnUnlock').hidden = editable || !access.password_scope;
    }

    /* ── Data ────────────────────────────────────────────── */

    async function refresh() {
        ui.productsList.innerHTML = skeleton();
        ui.historyList.innerHTML = skeleton();

        try {
            const [products, history, stats] = await Promise.all([
                API.products({ status: 'active' }),
                API.products({ status: 'history' }),
                API.stats(),
            ]);

            state.products = products;
            state.history = history;
            state.stats = stats;
            render();
        } catch (error) {
            if (error.status === 403) {
                showBlocked();
                return;
            }
            handleError(error);
        }
    }

    /* ── Sheet ───────────────────────────────────────────── */

    function selectedTypeDefaults() {
        return typeBySlug(state.selectedType) || state.types[0] || null;
    }

    function openSheet(product) {
        if (!canEdit()) {
            needPassword();
            return;
        }

        state.editing = product || null;
        ui.form.reset();
        $('#productId').value = product ? product.id : '';
        $('#name').value = product ? product.name : '';

        const type = product && product.type ? product.type : (state.types[0] ? state.types[0].slug : '');
        selectFormType(type);

        const defaults = selectedTypeDefaults();
        const grams = product ? product.weight_g : (defaults && defaults.default_weight_g ? defaults.default_weight_g : 500);
        const weight = grams >= 1000 ? { value: grams / 1000, unit: 'kg' } : { value: grams, unit: 'g' };

        $('#weight').value = weight.value;
        $('#weightUnit').value = weight.unit;
        $('#expiryDate').value = product ? product.expiry_date : todayPlus(defaults ? defaults.default_expiry_days : 30);
        $('#threshold').value = product ? product.threshold_days : ((state.config.defaults && state.config.defaults.threshold_days) || 7);

        ui.sheetTitle.textContent = product ? 'Изменить продукт' : 'Новый продукт';
        $('#submitBtn').textContent = product ? 'Сохранить' : 'Добавить';
        ui.formError.hidden = true;
        ui.sheet.hidden = false;
        document.body.classList.add('is-locked');

        setTimeout(() => $('#name').focus(), 40);
    }

    function closeSheet() {
        ui.sheet.hidden = true;
        document.body.classList.remove('is-locked');
        state.editing = null;
    }

    function selectFormType(slug) {
        state.selectedType = slug;
        $$('#typeChips [data-form-type]').forEach((button) => {
            button.classList.toggle('is-active', button.dataset.formType === slug);
        });
    }

    function showFormError(message) {
        ui.formError.textContent = message;
        ui.formError.hidden = false;
    }

    function parsePayload() {
        return {
            name: $('#name').value.trim(),
            type: state.selectedType,
            weight: Number($('#weight').value),
            weight_unit: $('#weightUnit').value,
            expiry_date: $('#expiryDate').value,
            threshold_days: Number($('#threshold').value) || 7,
        };
    }

    function validate(payload) {
        if (!payload.name) return 'Введите название';
        if (!payload.weight || payload.weight <= 0) return 'Введите корректное количество';
        if (!payload.expiry_date) return 'Укажите срок годности';
        return null;
    }

    async function submitForm(event) {
        event.preventDefault();

        if (!canEdit()) {
            closeSheet();
            needPassword();
            return;
        }

        const payload = parsePayload();
        const error = validate(payload);
        if (error) {
            showFormError(error);
            return;
        }

        const isEdit = Boolean(state.editing);
        const id = state.editing ? state.editing.id : null;
        const submit = $('#submitBtn');
        submit.disabled = true;

        try {
            if (isEdit) {
                await API.update(id, payload);
            } else {
                await API.create(payload);
            }

            closeSheet();
            toast(isEdit ? 'Сохранено' : 'Продукт добавлен', 'success');
            await refresh();
        } catch (submitError) {
            if (submitError.status === 403) {
                closeSheet();
                needPassword();
            } else {
                showFormError(submitError.message);
            }
        } finally {
            submit.disabled = false;
        }
    }

    /* ── Login ───────────────────────────────────────────── */

    function needPassword() {
        const scope = state.config && state.config.access ? state.config.access.password_scope : '';
        if (!scope) {
            toast('Редактирование запрещено', 'error');
            return;
        }

        ui.login.hidden = false;
        $('#password').value = '';
        ui.loginError.hidden = true;
        setTimeout(() => $('#password').focus(), 40);
    }

    function closeLogin() {
        ui.login.hidden = true;
    }

    async function submitLogin(event) {
        event.preventDefault();

        const scope = state.config && state.config.access ? state.config.access.password_scope : '';
        const password = $('#password').value;

        if (!scope || !password) {
            ui.loginError.textContent = 'Введите пароль';
            ui.loginError.hidden = false;
            return;
        }

        try {
            await API.login(scope, password);
            closeLogin();
            state.config = await API.config();
            renderAccess();
            toast('Режим редактирования включён', 'success');
            await refresh();
        } catch (loginError) {
            ui.loginError.textContent = loginError.message;
            ui.loginError.hidden = false;
        }
    }

    /* ── Actions ─────────────────────────────────────────── */

    async function onAction(action, id) {
        if (action === 'edit') {
            const product = state.products.concat(state.history).find((item) => item.id === id);
            if (product) openSheet(product);
            return;
        }

        if (!canEdit()) {
            needPassword();
            return;
        }

        try {
            if (action === 'use') {
                await API.use(id);
                toast('Отмечено как использованное', 'success');
            } else if (action === 'dispose') {
                await API.dispose(id);
                toast('Отмечено как выброшенное', 'success');
            } else if (action === 'restore') {
                await API.restore(id);
                toast('Возвращено в запас', 'success');
            }

            await refresh();
        } catch (error) {
            handleError(error);
        }
    }

    function switchView(view) {
        state.view = view === 'history' ? 'history' : 'active';
        ui.viewActive.hidden = state.view !== 'active';
        ui.viewHistory.hidden = state.view !== 'history';

        $$('.tabs__btn').forEach((button) => {
            const active = button.dataset.view === state.view;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', String(active));
        });

        render();
    }

    /* ── Events ──────────────────────────────────────────── */

    function bindEvents() {
        document.addEventListener('click', (event) => {
            const closeSheetNode = event.target.closest('[data-close-sheet]');
            if (closeSheetNode) {
                closeSheet();
                return;
            }

            const closeLoginNode = event.target.closest('[data-close-login]');
            if (closeLoginNode) {
                closeLogin();
                return;
            }

            const viewNode = event.target.closest('[data-view]');
            if (viewNode) {
                switchView(viewNode.dataset.view);
                return;
            }

            const bucketNode = event.target.closest('[data-bucket]');
            if (bucketNode) {
                state.bucket = state.bucket === bucketNode.dataset.bucket && bucketNode.dataset.bucket !== 'all'
                    ? 'all'
                    : bucketNode.dataset.bucket;
                render();
                return;
            }

            const filterTypeNode = event.target.closest('[data-filter-type]');
            if (filterTypeNode) {
                state.typeFilter = filterTypeNode.dataset.filterType;
                renderTypeFilter();
                render();
                return;
            }

            const formTypeNode = event.target.closest('[data-form-type]');
            if (formTypeNode) {
                selectFormType(formTypeNode.dataset.formType);
                if (!state.editing) {
                    const defaults = selectedTypeDefaults();
                    if (defaults) {
                        const grams = defaults.default_weight_g || 500;
                        $('#weight').value = grams >= 1000 ? grams / 1000 : grams;
                        $('#weightUnit').value = grams >= 1000 ? 'kg' : 'g';
                        $('#expiryDate').value = todayPlus(defaults.default_expiry_days || 30);
                    }
                }
                return;
            }

            const actionNode = event.target.closest('[data-action]');
            if (actionNode) {
                onAction(actionNode.dataset.action, Number(actionNode.dataset.id));
                return;
            }

            const daysNode = event.target.closest('[data-days]');
            if (daysNode) {
                $('#expiryDate').value = todayPlus(Number(daysNode.dataset.days));
                return;
            }

            if (event.target.closest('[data-type-expiry]')) {
                const defaults = selectedTypeDefaults();
                $('#expiryDate').value = todayPlus(defaults ? defaults.default_expiry_days : 30);
            }
        });

        $('#btnAdd').addEventListener('click', () => openSheet(null));
        $('#btnTheme').addEventListener('click', () => window.Theme.toggle());
        $('#btnRefresh').addEventListener('click', () => refresh());
        $('#btnUnlock').addEventListener('click', () => needPassword());

        ui.form.addEventListener('submit', submitForm);
        ui.loginForm.addEventListener('submit', submitLogin);

        let timer = null;
        ui.search.addEventListener('input', (event) => {
            clearTimeout(timer);
            const value = event.target.value.trim().toLowerCase();
            timer = setTimeout(() => {
                state.query = value;
                render();
            }, 140);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            if (!ui.sheet.hidden) closeSheet();
            if (!ui.login.hidden) closeLogin();
        });
    }

    function showBlocked() {
        ui.app.hidden = true;
        ui.blocked.hidden = false;
    }

    /* ── Boot ────────────────────────────────────────────── */

    async function init() {
        bindEvents();

        try {
            state.config = await API.config();
        } catch (error) {
            showBlocked();
            return;
        }

        if (!window.Theme.hasStoredChoice() && state.config.app && state.config.app.theme) {
            window.Theme.apply(state.config.app.theme);
        }

        ui.app.hidden = false;
        renderAccess();

        try {
            state.types = await API.types();
        } catch (error) {
            handleError(error);
        }

        renderTypeFilter();
        renderTypeChips();
        await refresh();
    }

    document.addEventListener('DOMContentLoaded', init);
})();
