const API_BASE_URL = '/api';
const API_PRODUCTS_URL = '/api/products';

// Конфигурация типов продуктов
const PRODUCT_TYPES = {
    'разное': {
        weight: '1.0',
        defaultExpiryDays: 30,
        expiryDescription: '30 дней',
    },
    'крупы': {
        weight: '0.9',
        defaultExpiryDays: 365,
        expiryDescription: '1 год',
    },
    'макароны': {
        weight: '0.5',
        defaultExpiryDays: 180,
        expiryDescription: '6 месяцев',
    },
    'консервы': {
        weight: '0.4',
        defaultExpiryDays: 365,
        expiryDescription: '1 год',
    },
    'масло': {
        weight: '0.9',
        defaultExpiryDays: 30,
        expiryDescription: '1 месяц',
    },
    'мука': {
        weight: '1.0',
        defaultExpiryDays: 365,
        expiryDescription: '1 год',
    },
    'специи': {
        weight: '0.1',
        defaultExpiryDays: 180,
        expiryDescription: '6 месяцев',
    },
    'чай_кофе': {
        weight: '0.25',
        defaultExpiryDays: 180,
        expiryDescription: '6 месяцев',
    }
};

// Глобальные переменные
let datePicker;

class ProductAPI {
    /**
     * Выполнить HTTP-запрос
     * @param {string} endpoint
     * @param {object} options
     * @param target
     *
     * @returns {Promise<any>}
     */
    static async _request(endpoint, options = {}, target = API_PRODUCTS_URL) {
        const response = await fetch(`${target}${endpoint}`, {
            headers: {
                'Content-Type': 'application/json',
            },
            ...options
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({
                message: `HTTP error! status: ${response.status}`
            }));
            throw new Error(errorData.message || 'Unknown error');
        }

        return response.json();
    }

    static async getAll() {
        return this._request('/');
    }

    static async get(id) {
        return this._request(`/${id}/`);
    }

    static async create(productData) {
        return this._request('/', {
            method: 'POST',
            body: JSON.stringify(productData)
        });
    }

    static async update(id, productData) {
        return this._request(`/${id}/`, {
            method: 'PUT',
            body: JSON.stringify(productData)
        });
    }

    static async delete(id) {
        return this._request(`/${id}/`, {
            method: 'DELETE'
        });
    }
}

// Вспомогательные функции
const Utils = {
    escapeHtml(unsafe) {
        if (unsafe == null) return '';

        const text = typeof unsafe === 'string'
            ? unsafe
            : String(unsafe);

        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },

    formatDate(dateString) {
        try {
            const date = new Date(dateString);
            if (isNaN(date.getTime())) return 'Неверная дата';

            return date.toLocaleDateString('ru-RU', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        } catch {
            return 'Неверная дата';
        }
    },

    showNotification(message, type = 'error') {
        const notification = document.createElement('div');
        const backgroundColor = type === 'success' ? '#28a745' : '#dc3545';

        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px; /* В старой версии 15px 20px */ 
            border-radius: 8px;
            color: white;
            font-weight: 600;
            z-index: 10000;
            transform: translateX(400px);
            transition: transform 0.3s ease;
            background: ${backgroundColor};
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        `;
        notification.textContent = message;

        document.body.appendChild(notification);

        requestAnimationFrame(() => {
            notification.style.transform = 'translateX(0)';
        });

        setTimeout(() => {
            notification.style.transform = 'translateX(400px)';
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    },

    safeParseProducts(data) {
        if (!Array.isArray(data)) return [];

        return data
            .map(item => ({
                id: Number(item.id) || 0,
                name: String(item.name || ''),
                weight: Number(item.weight) || 0,
                expiry_date: String(item.expiry_date || ''),
                type: String(item.type || 'разное'),
                threshold_days: Number(item.threshold_days) || 7,
                days_remaining: Number(item.days_remaining) || 0,
                created_at: item.created_at || null,
                updated_at: item.updated_at || null,
                is_deleted: Boolean(item.is_deleted || false),
                deleted_at: item.deleted_at || null
            }))
            .filter(product => product.id > 0 && !product.is_deleted);
    },

    getProductStatus(daysRemaining, thresholdDays) {
        if (daysRemaining < 0) {
            return {
                class: 'expired',
                text: 'Просрочен',
                icon: 'status-expired'
            };
        }

        if (daysRemaining <= thresholdDays) {
            return {
                class: 'warning',
                text: `Скоро истекает <br>&nbsp;&nbsp;&nbsp;&nbsp; (осталось ${Math.ceil(daysRemaining)} дн.)`,
                icon: 'status-warning'
            };
        }

        return {
            class: '',
            text: `ОК (${Math.ceil(daysRemaining)} дн.)`,
            icon: 'status-ok'
        };
    }
};

// Основные функции приложения
class ProductManager {
    static async loadProducts() {
        try {
            const productsData = await ProductAPI.getAll();
            const products = Utils.safeParseProducts(productsData);
            this.displayProducts(products);
        } catch (error) {
            console.error('Ошибка загрузки:', error);
            Utils.showNotification(`Ошибка загрузки: ${error.message}`, 'error');
            this.showEmptyState('⚠️', 'Ошибка загрузки данных', true);
        }
    }

    static displayProducts(products) {
        const container = document.getElementById('productsList');

        if (!products?.length) {
            return this.showEmptyState('📦', 'Нет добавленных продуктов');
        }

        container.innerHTML = products.map(product => this.createProductElement(product)).join('');
    }

    static createProductElement(product) {
        const status = Utils.getProductStatus(product.days_remaining, product.threshold_days);

        return `
            <div class="product-item ${status.class}">
                <div data-label="Наименование"><strong>${Utils.escapeHtml(product.name)}</strong></div>
                <div data-label="Вес">${product.weight} кг</div>
                <div data-label="Тип">${Utils.escapeHtml(product.type)}</div>
                <div data-label="Срок годности">${Utils.formatDate(product.expiry_date)}</div>
                <div data-label="Статус">
                    <span class="status-indicator ${status.icon}"></span>${status.text}
                </div>
                <div data-label="Действие">
                    <button class="delete-btn" onclick="ProductManager.deleteProduct(${product.id})">
                        🗑️ Удалить
                    </button>
                </div>
            </div>
        `;
    }

    static showEmptyState(icon, message, showRetry = false) {
        const container = document.getElementById('productsList');
        const retryButton = showRetry
            ? '<button class="btn" onclick="ProductManager.loadProducts()" style="width: auto; margin-top: 20px;">Повторить попытку</button>'
            : '';

        container.innerHTML = `
            <div class="empty-state">
                <i>${icon}</i>
                <p>${message}</p>
                ${!showRetry ? '<button class="btn" onclick="FormManager.openFormPanel()" style="width: auto; margin-top: 20px;">Добавить первый продукт</button>' : ''}
                ${retryButton}
            </div>
        `;
    }

    static async addProduct(event) {
        event.preventDefault();

        const form = event.target;
        const formData = new FormData(form);

        const productData = {
            name: (formData.get('name') || '').trim(),
            weight: Number(formData.get('weight')),
            expiry_date: formData.get('expiry_date'),
            type: formData.get('type'),
            threshold_days: Number(formData.get('threshold_days')) || 7
        };

        // Валидация
        const errors = this.validateProduct(productData);
        if (errors.length) {
            errors.forEach(error => Utils.showNotification(error, 'error'));
            return;
        }

        try {
            await ProductAPI.create(productData);
            FormManager.resetForm(form);
            FormManager.closeFormPanel();
            await this.loadProducts();
            Utils.showNotification('Продукт добавлен', 'success');
        } catch (error) {
            console.error('Ошибка добавления:', error);
            Utils.showNotification(`Ошибка: ${error.message}`, 'error');
        }
    }

    static validateProduct(data) {
        const errors = [];

        if (!data.name) errors.push('Введите название продукта');
        if (!data.weight || data.weight <= 0) errors.push('Введите корректный вес');
        if (!data.expiry_date) errors.push('Введите срок годности');

        return errors;
    }

    static async deleteProduct(id) {
        if (!id || id <= 0) {
            Utils.showNotification('Неверный ID продукта', 'error');
            return;
        }

        if (!confirm('Вы уверены, что хотите удалить этот продукт?')) {
            return;
        }

        try {
            await ProductAPI.delete(id);
            await this.loadProducts();
            Utils.showNotification('Продукт удален', 'success');
        } catch (error) {
            console.error('Ошибка удаления:', error);
            Utils.showNotification(`Ошибка: ${error.message}`, 'error');
        }
    }
}

// Функции для работы с формой
class FormManager {
    static init() {
        this.initDatePicker();
        this.bindEvents();
    }

    static initDatePicker() {
        datePicker = flatpickr("#expiry_date", {
            locale: "ru",
            dateFormat: "d.m.Y",
            altInput: true,
            altFormat: "d.m.Y",
            minDate: "today",
            allowInput: true,
            clickOpens: true
        });
    }

    static bindEvents() {
        const form = document.getElementById('productForm');
        if (form) {
            form.addEventListener('submit', ProductManager.addProduct.bind(ProductManager));
        }

        const overlay = document.getElementById('overlay');
        if (overlay) {
            overlay.addEventListener('click', this.closeFormPanel.bind(this));
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') this.closeFormPanel();
        });

        // Привязка события изменения типа продукта
        const typeSelect = document.getElementById('type');
        if (typeSelect) {
            typeSelect.addEventListener('change', () => {
                this.updateDefaultsByType();
            });
        }
    }

    static openFormPanel() {
        document.getElementById('formPanel').classList.add('active');
        document.getElementById('overlay').classList.add('active');
        document.body.style.overflow = 'hidden';
        document.getElementById('name').focus();
    }

    static closeFormPanel() {
        document.getElementById('formPanel').classList.remove('active');
        document.getElementById('overlay').classList.remove('active');
        document.body.style.overflow = 'auto';
    }

    static updateDefaultsByType() {
        this.updateWeightByType();
        this.updateExpiryHint();
    }

    static updateWeightByType() {
        const type = document.getElementById('type').value;
        const weightInput = document.getElementById('weight');

        const config = PRODUCT_TYPES[type];
        if (config?.weight) {
            weightInput.value = config.weight;
        }
    }

    static updateExpiryHint() {
        const type = document.getElementById('type').value;
        const hintElement = document.getElementById('expiryHint');

        const config = PRODUCT_TYPES[type];
        hintElement.textContent = config?.expiryDescription
            ? `Срок годности по умолчанию: ${config.expiryDescription}`
            : '';
    }

    static setDefaultExpiry() {
        const type = document.getElementById('type').value;
        const config = PRODUCT_TYPES[type];
        const days = config?.defaultExpiryDays || 30;

        this.setExpiryDays(days, 'defaultExpiryBtn');
    }

    static setExpiryDays(days, buttonId = null) {
        const today = new Date();
        today.setDate(today.getDate() + days);
        if (datePicker) {
            datePicker.setDate(today);
        }

        if (buttonId) {
            this.highlightButton('.quick-days-btn', buttonId);
        }
    }

    static setThreshold(days, event) {
        document.getElementById('threshold_days').value = days;
        this.highlightButton('.threshold-btn', event.target.id);
    }

    static highlightButton(selector, buttonId) {
        document.querySelectorAll(selector).forEach(btn => {
            btn.classList.remove('active');
        });

        const targetBtn = document.getElementById(buttonId);
        if (targetBtn) targetBtn.classList.add('active');
    }

    static resetForm(form) {
        form.reset();
        document.getElementById('type').value = 'разное';
        document.getElementById('weight').value = '1.0';
        document.getElementById('threshold_days').value = '7';

        if (datePicker) datePicker.clear();
        this.updateExpiryHint();

        // Сброс подсветки кнопок
        document.querySelectorAll('.quick-days-btn, .threshold-btn').forEach(btn => {
            btn.classList.remove('active');
        });
    }
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', () => {
    ProductManager.loadProducts();
    FormManager.init();
    FormManager.updateExpiryHint();
});

// Экспорт функций для глобального использования
window.ProductManager = ProductManager;
window.FormManager = FormManager;
window.openFormPanel = FormManager.openFormPanel.bind(FormManager);
window.closeFormPanel = FormManager.closeFormPanel.bind(FormManager);
window.updateDefaultsByType = FormManager.updateDefaultsByType.bind(FormManager);
window.setDefaultExpiry = FormManager.setDefaultExpiry.bind(FormManager);
window.setDays = (days) => {
    FormManager.setExpiryDays(days);

    // Подсветка кнопки
    document.querySelectorAll('.quick-days-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.classList.add('active');
};
window.setThreshold = (days) => {
    FormManager.setThreshold(days, event);
};