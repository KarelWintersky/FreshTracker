(function () {
    'use strict';

    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    const state = {
        config: null,
        types: [],
        editing: null,
    };

    const ui = {
        app: $('#app'),
        blocked: $('#blocked'),
        blockedText: $('#blockedText'),
        list: $('#typeList'),
        count: $('#typeCount'),
        sheet: $('#sheet'),
        sheetTitle: $('#sheetTitle'),
        form: $('#typeForm'),
        formError: $('#formError'),
        slugHint: $('#slugHint'),
        toasts: $('#toasts'),
    };

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

    function formatGrams(grams) {
        const value = Number(grams) || 0;
        if (value >= 1000) {
            const kilograms = (value / 1000).toFixed(3).replace(/\.?0+$/, '').replace('.', ',');
            return `${kilograms} кг`;
        }

        return `${value} г`;
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
        }, 2800);
    }

    function showBlocked() {
        ui.app.hidden = true;
        ui.blocked.hidden = false;
    }

    function defaultsText(type) {
        const parts = [
            `Срок ${type.default_expiry_days} дн.`,
            `порог ${type.default_threshold_days} дн.`,
            type.default_weight_g ? `вес ${formatGrams(type.default_weight_g)}` : 'вес не задан',
        ];

        return parts.join(' · ');
    }

    function usageText(type) {
        if (type.product_count === 0) {
            return 'не используется';
        }

        const history = type.product_count - type.active_count;
        const parts = [`${type.active_count} в запасе`];

        if (history > 0) {
            parts.push(`${history} в истории`);
        }

        return parts.join(' · ');
    }

    function typeRowHtml(type, index, total) {
        const actions = [];

        actions.push(`<button type="button" class="btn btn--sm btn--ghost" data-action="up" ${index === 0 ? 'disabled' : ''} title="Выше" aria-label="Выше">↑</button>`);
        actions.push(`<button type="button" class="btn btn--sm btn--ghost" data-action="down" ${index === total - 1 ? 'disabled' : ''} title="Ниже" aria-label="Ниже">↓</button>`);
        actions.push(`<button type="button" class="btn btn--sm btn--ghost" data-action="edit">Изменить</button>`);

        if (!type.is_system) {
            actions.push(`<button type="button" class="btn btn--sm btn--danger" data-action="delete">Удалить</button>`);
        }

        return `
            <article class="type-row ${type.is_system ? 'is-system' : ''}" data-id="${type.id}">
                <div class="type-row__main">
                    <div class="type-row__title">
                        ${esc(type.title)}
                        ${type.is_system ? '<span class="tag">системный</span>' : ''}
                    </div>
                    <div class="type-row__meta">
                        <code class="type-row__slug">${esc(type.slug)}</code>
                        <span>${esc(defaultsText(type))}</span>
                        <span>${esc(usageText(type))}</span>
                    </div>
                </div>
                <div class="type-row__actions">${actions.join('')}</div>
            </article>`;
    }

    function render() {
        ui.count.textContent = `${state.types.length} ${plural(state.types.length, 'тип', 'типа', 'типов')}`;

        if (!state.types.length) {
            ui.list.innerHTML = '<div class="empty"><span class="empty__icon">🗂️</span><div class="empty__title">Типов нет</div><div class="empty__text">Добавьте первый тип.</div></div>';
            return;
        }

        ui.list.innerHTML = state.types
            .map((type, index) => typeRowHtml(type, index, state.types.length))
            .join('');
    }

    async function load() {
        ui.list.innerHTML = '<div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div>';

        try {
            state.types = await API.types();
            render();
        } catch (error) {
            toast(error.message || 'Ошибка загрузки', 'error');
        }
    }

    function openSheet(type) {
        state.editing = type || null;
        ui.form.reset();

        $('#typeId').value = type ? type.id : '';
        $('#title').value = type ? type.title : '';
        $('#expiryDays').value = type ? type.default_expiry_days : 30;
        $('#thresholdDays').value = type ? type.default_threshold_days : 7;
        $('#weightG').value = type && type.default_weight_g ? type.default_weight_g : '';

        if (type) {
            ui.slugHint.textContent = `slug: ${type.slug} (не меняется при переименовании)`;
            ui.slugHint.hidden = false;
        } else {
            ui.slugHint.hidden = true;
        }

        ui.sheetTitle.textContent = type ? 'Изменить тип' : 'Новый тип';
        $('#submitBtn').textContent = type ? 'Сохранить' : 'Добавить';
        ui.formError.hidden = true;
        ui.sheet.hidden = false;
        document.body.classList.add('is-locked');

        setTimeout(() => $('#title').focus(), 40);
    }

    function closeSheet() {
        ui.sheet.hidden = true;
        document.body.classList.remove('is-locked');
        state.editing = null;
    }

    function showFormError(message) {
        ui.formError.textContent = message;
        ui.formError.hidden = false;
    }

    function parsePayload() {
        return {
            title: $('#title').value.trim(),
            default_expiry_days: Number($('#expiryDays').value),
            default_threshold_days: Number($('#thresholdDays').value),
            default_weight_g: $('#weightG').value === '' ? null : Number($('#weightG').value),
        };
    }

    function validate(payload) {
        if (!payload.title) return 'Введите название';
        if (!payload.default_expiry_days || payload.default_expiry_days < 1 || payload.default_expiry_days > 3650) {
            return 'Срок по умолчанию должен быть от 1 до 3650 дней';
        }
        if (!payload.default_threshold_days || payload.default_threshold_days < 1 || payload.default_threshold_days > 365) {
            return 'Порог должен быть от 1 до 365 дней';
        }
        if (payload.default_weight_g !== null && (!payload.default_weight_g || payload.default_weight_g < 1)) {
            return 'Вес должен быть положительным числом';
        }

        return null;
    }

    async function submitForm(event) {
        event.preventDefault();

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
                await API.updateType(id, payload);
            } else {
                await API.createType(payload);
            }

            closeSheet();
            toast(isEdit ? 'Тип сохранён' : 'Тип добавлен', 'success');
            await load();
        } catch (submitError) {
            showFormError(submitError.message);
        } finally {
            submit.disabled = false;
        }
    }

    async function deleteType(type) {
        const message = type.product_count > 0
            ? `Тип «${type.title}» используется в ${type.product_count} ${plural(type.product_count, 'записи', 'записях', 'записях')}. Все продукты будут перенесены в «Разное», а к названию добавится префикс «[${type.title}] ». Продолжить?`
            : `Удалить тип «${type.title}»?`;

        if (!window.confirm(message)) {
            return;
        }

        try {
            const result = await API.deleteType(type.id);
            const moved = result && result.moved_products ? ` Перенесено: ${result.moved_products}.` : '';
            toast(`Тип удалён.${moved}`, 'success');
            await load();
        } catch (error) {
            toast(error.message || 'Ошибка удаления', 'error');
        }
    }

    async function moveType(type, direction) {
        try {
            const result = await API.moveType(type.id, direction);
            if (!result.moved) {
                toast(direction === 'up' ? 'Тип уже первый' : 'Тип уже последний');
                return;
            }
            await load();
        } catch (error) {
            toast(error.message || 'Ошибка перемещения', 'error');
        }
    }

    function findType(id) {
        return state.types.find((type) => type.id === id) || null;
    }

    function bindEvents() {
        document.addEventListener('click', (event) => {
            if (event.target.closest('[data-close-sheet]')) {
                closeSheet();
                return;
            }

            const actionNode = event.target.closest('[data-action]');
            if (actionNode) {
                const row = actionNode.closest('.type-row');
                const type = row ? findType(Number(row.dataset.id)) : null;
                if (!type) return;

                if (actionNode.dataset.action === 'edit') openSheet(type);
                if (actionNode.dataset.action === 'delete') deleteType(type);
                if (actionNode.dataset.action === 'up') moveType(type, 'up');
                if (actionNode.dataset.action === 'down') moveType(type, 'down');
            }
        });

        $('#btnAddType').addEventListener('click', () => openSheet(null));
        $('#btnTheme').addEventListener('click', () => window.Theme.toggle());
        ui.form.addEventListener('submit', submitForm);

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !ui.sheet.hidden) {
                closeSheet();
            }
        });
    }

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

        const access = state.config.access || {};
        if (access.group !== 'local') {
            ui.blockedText.textContent = 'Управление типами доступно только в локальной сети.';
            showBlocked();
            return;
        }

        if (!access.can_edit) {
            ui.blockedText.textContent = 'Сначала разблокируйте редактирование на странице продуктов.';
            showBlocked();
            return;
        }

        ui.app.hidden = false;
        await load();
    }

    document.addEventListener('DOMContentLoaded', init);
})();
