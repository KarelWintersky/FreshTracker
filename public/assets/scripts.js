const API_BASE_URL = 'api.php';

// Веса по типам продуктов
const typeWeights = {
    'разное': '1.0',
    'крупы': '0.9',
    'макароны': '0.5',
    'консервы': '0.4',
    'масло': '0.9',
    'мука': '1.0',
    'специи': '0.1',
    'чай_кофе': '0.25'
};

// Сроки годности по умолчанию для типов продуктов (в днях)
const defaultExpiryDays = {
    'разное': 30,
    'крупы': 365,
    'макароны': 180,
    'консервы': 365,
    'масло': 30,
    'мука': 365,
    'специи': 180,
    'чай_кофе': 180
};

// Описания сроков годности для подсказок
const expiryDescriptions = {
    'разное': '30 дней',
    'крупы': '1 год',
    'макароны': '6 месяцев',
    'консервы': '1 год',
    'масло': '1 месяц',
    'мука': '1 год',
    'специи': '6 месяцев',
    'чай_кофе': '6 месяцев'
};

// Глобальные переменные
let datePicker;

class ProductAPI {
    static async getAll() {
        const response = await fetch(API_BASE_URL, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
            }
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({ message: 'Unknown error' }));
            throw new Error(errorData.message || `HTTP error! status: ${response.status}`);
        }

        return await response.json();
    }

    static async get(id) {
        const response = await fetch(`${API_BASE_URL}?id=${id}`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
            }
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({ message: 'Unknown error' }));
            throw new Error(errorData.message || `HTTP error! status: ${response.status}`);
        }

        return await response.json();
    }

    static async create(productData) {
        const response = await fetch(API_BASE_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(productData)
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({ message: 'Unknown error' }));
            throw new Error(errorData.message || `HTTP error! status: ${response.status}`);
        }

        return await response.json();
    }

    static async update(id, productData) {
        const response = await fetch(`${API_BASE_URL}?id=${id}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(productData)
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({ message: 'Unknown error' }));
            throw new Error(errorData.message || `HTTP error! status: ${response.status}`);
        }

        return await response.json();
    }

    static async delete(id) {
        const response = await fetch(`${API_BASE_URL}?id=${id}`, {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
            }
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({ message: 'Unknown error' }));
            throw new Error(errorData.message || `HTTP error! status: ${response.status}`);
        }

        return await response.json();
    }
}

// Вспомогательные функции
function escapeHtml(unsafe) {
    if (unsafe == null) {
        return '';
    }

    switch (typeof unsafe) {
        case 'string':
            break;
        case 'number':
        case 'boolean':
            unsafe = String(unsafe);
            break;
        default:
            return '';
    }

    const div = document.createElement('div');
    div.textContent = unsafe;
    return div.innerHTML;
}

function formatDate(dateString) {
    try {
        const date = new Date(dateString);
        if (isNaN(date.getTime())) {
            return 'Неверная дата';
        }
        return date.toLocaleDateString('ru-RU', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    } catch (error) {
        console.error('Ошибка форматирования даты:', error);
        return 'Неверная дата';
    }
}

function showNotification(message, type) {
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 15px 20px;
        border-radius: 8px;
        color: white;
        font-weight: 600;
        z-index: 1000;
        transform: translateX(400px);
        transition: transform 0.3s ease;
        ${type === 'success' ? 'background: #28a745;' : 'background: #dc3545;'}
    `;
    notification.textContent = message;

    document.body.appendChild(notification);

    setTimeout(() => {
        notification.style.transform = 'translateX(0)';
    }, 100);

    setTimeout(() => {
        notification.style.transform = 'translateX(400px)';
        setTimeout(() => {
            if (document.body.contains(notification)) {
                document.body.removeChild(notification);
            }
        }, 300);
    }, 3000);
}

function safeParseProducts(data) {
    if (!Array.isArray(data)) {
        return [];
    }

    return data.map(item => ({
        id: parseInt(item.id) || 0,
        name: String(item.name || ''),
        weight: parseFloat(item.weight) || 0,
        expiry_date: String(item.expiry_date || ''),
        type: String(item.type || 'разное'),
        threshold_days: parseInt(item.threshold_days) || 7,
        days_remaining: parseFloat(item.days_remaining) || 0,
        created_at: item.created_at || null,
        updated_at: item.updated_at || null,
        is_deleted: Boolean(item.is_deleted || false),
        deleted_at: item.deleted_at || null
    })).filter(product => product.id > 0 && !product.is_deleted); // Фильтруем удаленные продукты
}

// Основные функции приложения
async function loadProducts() {
    try {
        const productsData = await ProductAPI.getAll();
        const products = safeParseProducts(productsData);
        displayProducts(products);
    } catch (error) {
        console.error('Error:', error);
        showNotification('Ошибка загрузки данных: ' + error.message, 'error');

        const container = document.getElementById('productsList');
        container.innerHTML = `
            <div class="empty-state">
                <i>⚠️</i>
                <p>Ошибка загрузки данных</p>
                <button class="btn" onclick="loadProducts()" style="width: auto; margin-top: 20px;">Повторить попытку</button>
            </div>
        `;
    }
}

function displayProducts(products) {
    const container = document.getElementById('productsList');

    if (!products || !Array.isArray(products) || products.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i>📦</i>
                <p>Нет добавленных продуктов</p>
                <button class="btn" onclick="openFormPanel()" style="width: auto; margin-top: 20px;">Добавить первый продукт</button>
            </div>
        `;
        return;
    }

    container.innerHTML = '';

    products.forEach(product => {
        const productId = parseInt(product.id) || 0;
        const productName = escapeHtml(product.name || 'Неизвестно');
        const productWeight = parseFloat(product.weight) || 0;
        const productType = escapeHtml(product.type || 'разное');
        const expiryDate = product.expiry_date || '';
        const thresholdDays = parseInt(product.threshold_days) || 7;
        const daysRemaining = parseFloat(product.days_remaining) || 0;

        let statusClass = '';
        let statusText = '';
        let statusIcon = '';

        if (daysRemaining < 0) {
            statusClass = 'expired';
            statusText = 'Просрочен';
            statusIcon = 'status-expired';
        } else if (daysRemaining <= thresholdDays) {
            statusClass = 'warning';
            statusText = `Скоро истекает <br>&nbsp;&nbsp;&nbsp;&nbsp; (осталось ${Math.ceil(daysRemaining)} дн.)`;
            statusIcon = 'status-warning';
        } else {
            statusText = `ОК (${Math.ceil(daysRemaining)} дн.)`;
            statusIcon = 'status-ok';
        }

        const productElement = document.createElement('div');
        productElement.className = `product-item ${statusClass}`;

        productElement.innerHTML = `
            <div data-label="Наименование"><strong>${productName}</strong></div>
            <div data-label="Вес">${productWeight} кг</div>
            <div data-label="Тип">${productType}</div>
            <div data-label="Срок годности">${formatDate(expiryDate)}</div>
            <div data-label="Статус"><span class="status-indicator ${statusIcon}"></span>${statusText}</div>
            <div data-label="Действие">
                <button class="delete-btn" onclick="deleteProduct(${productId})">🗑️ Удалить</button>
            </div>
        `;

        container.appendChild(productElement);
    });
}

async function addProduct() {
    const form = document.getElementById('productForm');
    const formData = new FormData(form);

    const productData = {
        name: formData.get('name'),
        weight: parseFloat(formData.get('weight')),
        expiry_date: formData.get('expiry_date'),
        type: formData.get('type'),
        threshold_days: parseInt(formData.get('threshold_days'))
    };

    // Валидация на клиенте
    if (!productData.name || !productData.name.trim()) {
        showNotification('Введите название продукта', 'error');
        return;
    }

    if (!productData.weight || productData.weight <= 0) {
        showNotification('Введите корректный вес', 'error');
        return;
    }

    if (!productData.expiry_date) {
        showNotification('Введите срок годности', 'error');
        return;
    }

    try {
        await ProductAPI.create(productData);

        // Сброс формы
        form.reset();
        document.getElementById('type').value = 'разное';
        document.getElementById('weight').value = '1.0';
        document.getElementById('threshold_days').value = '7';
        if (datePicker) {
            datePicker.clear();
        }
        updateExpiryHint();

        // Сброс подсветки кнопок
        document.querySelectorAll('.quick-days-btn, .threshold-btn').forEach(btn => {
            btn.classList.remove('active');
        });

        closeFormPanel();
        loadProducts();
        showNotification('Продукт добавлен', 'success');
    } catch (error) {
        console.error('Error:', error);
        showNotification('Ошибка при добавлении: ' + error.message, 'error');
    }
}

async function deleteProduct(id) {
    if (!id || id <= 0) {
        showNotification('Неверный ID продукта', 'error');
        return;
    }

    if (!confirm('Вы уверены, что хотите удалить этот продукт?')) {
        return;
    }

    try {
        await ProductAPI.delete(id);
        loadProducts();
        showNotification('Продукт удален', 'success');
    } catch (error) {
        console.error('Error:', error);
        showNotification('Ошибка при удалении: ' + error.message, 'error');
    }
}

// Функции для работы с формой
function openFormPanel() {
    document.getElementById('formPanel').classList.add('active');
    document.getElementById('overlay').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeFormPanel() {
    document.getElementById('formPanel').classList.remove('active');
    document.getElementById('overlay').classList.remove('active');
    document.body.style.overflow = 'auto';
}

function initDatePicker() {
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

function updateDefaultsByType() {
    updateWeightByType();
    updateExpiryHint();
}

function updateWeightByType() {
    const type = document.getElementById('type').value;
    const weightInput = document.getElementById('weight');

    if (typeWeights[type]) {
        weightInput.value = typeWeights[type];
    }
}

function updateExpiryHint() {
    const type = document.getElementById('type').value;
    const hintElement = document.getElementById('expiryHint');

    if (expiryDescriptions[type]) {
        hintElement.textContent = `Срок годности по умолчанию: ${expiryDescriptions[type]}`;
    } else {
        hintElement.textContent = '';
    }
}

function setDefaultExpiry() {
    const type = document.getElementById('type').value;
    const days = defaultExpiryDays[type] || 30;

    const today = new Date();
    today.setDate(today.getDate() + days);
    datePicker.setDate(today);

    // Подсветка кнопки "По умолчанию"
    document.querySelectorAll('.quick-days-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    document.getElementById('defaultExpiryBtn').classList.add('active');
}

function setDays(days) {
    const today = new Date();
    today.setDate(today.getDate() + days);
    datePicker.setDate(today);

    // Подсветка активной кнопки
    document.querySelectorAll('.quick-days-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.classList.add('active');
}

function setThreshold(days) {
    document.getElementById('threshold_days').value = days;

    // Подсветка активной кнопки
    document.querySelectorAll('.threshold-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.classList.add('active');
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    loadProducts();
    initDatePicker();
    updateExpiryHint();

    // Обработка формы
    document.getElementById('productForm').addEventListener('submit', function(e) {
        e.preventDefault();
        addProduct();
    });

    // Закрытие панели по клику на оверлей
    document.getElementById('overlay').addEventListener('click', closeFormPanel);

    // Закрытие по ESC
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeFormPanel();
        }
    });
});

// Экспорт функций для глобального использования
window.loadProducts = loadProducts;
window.displayProducts = displayProducts;
window.addProduct = addProduct;
window.deleteProduct = deleteProduct;
window.openFormPanel = openFormPanel;
window.closeFormPanel = closeFormPanel;
window.updateDefaultsByType = updateDefaultsByType;
window.setDefaultExpiry = setDefaultExpiry;
window.setDays = setDays;
window.setThreshold = setThreshold;
