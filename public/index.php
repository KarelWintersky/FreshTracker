<?php
// Создание базы данных при первом запуске
$db = new PDO('sqlite:freshtracker.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Создание таблицы если не существует
$db->exec("
    CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        weight REAL NOT NULL,
        expiry_date TEXT NOT NULL,
        type TEXT NOT NULL,
        threshold_days INTEGER DEFAULT 7
    )
");

// Обработка AJAX запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                addProduct($db);
                break;
            case 'delete':
                deleteProduct($db);
                break;
            case 'get_list':
                getProductList($db);
                break;
        }
    }
    exit;
}

function addProduct($db) {
    $name = trim($_POST['name']);
    $weight = floatval($_POST['weight']);
    $expiry_input = $_POST['expiry_date'];
    $type = $_POST['type'];
    $threshold_days = intval($_POST['threshold_days']);

    if (empty($name) || $weight <= 0) {
        echo json_encode(['success' => false, 'message' => 'Неверные данные']);
        return;
    }

    // Обработка ввода даты
    $expiry_date = processDateInput($expiry_input);
    if (!$expiry_date) {
        echo json_encode(['success' => false, 'message' => 'Неверный формат даты']);
        return;
    }

    $stmt = $db->prepare("
        INSERT INTO products (name, weight, expiry_date, type, threshold_days) 
        VALUES (?, ?, ?, ?, ?)
    ");

    $success = $stmt->execute([$name, $weight, $expiry_date, $type, $threshold_days]);

    echo json_encode([
        'success' => $success,
        'message' => $success ? 'Продукт добавлен' : 'Ошибка при добавлении'
    ]);
}

function processDateInput($input) {
    $input = trim($input);

    // Если введено число - добавляем указанное количество дней к текущей дате
    if (is_numeric($input)) {
        $days = intval($input);
        $date = new DateTime();
        $date->modify("+{$days} days");
        return $date->format('Y-m-d');
    }

    // Пробуем различные форматы дат
    $formats = [
        'Y-m-d',
        'd.m.Y',
        'd/m/Y',
        'd-m-Y',
        'Y/m/d'
    ];

    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $input);
        if ($date) {
            return $date->format('Y-m-d');
        }
    }

    return false;
}

function deleteProduct($db) {
    $id = intval($_POST['id']);
    $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
    $success = $stmt->execute([$id]);

    echo json_encode([
        'success' => $success,
        'message' => $success ? 'Продукт удален' : 'Ошибка при удалении'
    ]);
}

function getProductList($db) {
    $stmt = $db->query("
        SELECT *, 
               julianday(expiry_date) - julianday('now') as days_remaining
        FROM products 
        ORDER BY expiry_date ASC
    ");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($products);
}

// Функция для получения срока годности по умолчанию для типа продукта
function getDefaultExpiryDays($type) {
    $defaultExpiry = [
        'разное' => 30,      // 30 дней по умолчанию
        'крупы' => 365,      // 1 год
        'макароны' => 180,   // 6 месяцев
        'консервы' => 365,   // 1 год
        'масло' => 30,       // 1 месяц
        'мука' => 365,       // 1 год
        'специи' => 180,     // 6 месяцев
        'чай_кофе' => 180    // 6 месяцев
    ];

    return $defaultExpiry[$type] ?? 30;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="version" content="VERSION_PLACEHOLDER">
    <title>FreshTracker - Учет продуктов</title>
    <link rel="stylesheet" href="assets/flatpickr.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            position: relative;
        }

        .header {
            background: linear-gradient(135deg, #2c3e50, #34495e);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }

        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            font-weight: 300;
        }

        .main-content {
            padding: 30px;
            min-height: 600px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .section-title {
            color: #2c3e50;
            font-size: 1.5em;
            margin: 0;
        }

        .add-product-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: transform 0.2s;
            white-space: nowrap;
        }

        .add-product-btn:hover {
            transform: translateY(-2px);
            background: #218838;
        }

        .form-panel {
            position: fixed;
            top: -100%;
            left: 50%;
            transform: translateX(-50%);
            width: 90%;
            max-width: 500px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.3);
            padding: 30px;
            transition: top 0.8s ease;
            z-index: 1000;
            max-height: 90vh;
            overflow-y: auto;
        }

        .form-panel.active {
            top: 45%;
            transform: translate(-50%, -50%);
        }

        .close-panel {
            position: absolute;
            top: 15px;
            right: 15px;
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #6c757d;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .close-panel:hover {
            background: #f8f9fa;
            color: #dc3545;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }

        input, select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        input:focus, select:focus {
            outline: none;
            border-color: #667eea;
        }

        .btn {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 14px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            width: 100%;
            transition: transform 0.2s;
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .products-list {
            max-height: 600px;
            overflow-y: auto;
        }

        .product-item {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr auto;
            gap: 15px;
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            align-items: center;
            transition: background-color 0.3s;
        }

        .product-item:hover {
            background-color: #f8f9fa;
        }

        .product-item.header {
            background: #2c3e50;
            color: white;
            font-weight: 600;
            position: sticky;
            top: 0;
        }

        .expired {
            background-color: #ffe6e6;
            border-left: 4px solid #dc3545;
        }

        .warning {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
        }

        .delete-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
        }

        .delete-btn:hover {
            background: #c82333;
        }

        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }

        .status-ok { background-color: #28a745; }
        .status-warning { background-color: #ffc107; }
        .status-expired { background-color: #dc3545; }

        .empty-state {
            text-align: center;
            padding: 60px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .input-with-prefix {
            position: relative;
        }

        .input-prefix {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-weight: 600;
        }

        .input-with-prefix input {
            padding-left: 40px;
        }

        .quick-days-buttons {
            display: flex;
            gap: 8px;
            margin-top: 8px;
            flex-wrap: wrap;
        }

        .quick-days-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: background-color 0.3s;
            flex: 1;
            min-width: 50px;
        }

        .quick-days-btn:hover {
            background: #5a6268;
        }

        .quick-days-btn.active {
            background: #667eea;
        }

        .threshold-buttons {
            display: flex;
            gap: 8px;
            margin-top: 8px;
            flex-wrap: wrap;
        }

        .threshold-btn {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: background-color 0.3s;
            flex: 1;
            min-width: 50px;
        }

        .threshold-btn:hover {
            background: #138496;
        }

        .threshold-btn.active {
            background: #667eea;
        }

        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease;
        }

        .overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .panel-title {
            text-align: center;
            margin-bottom: 25px;
            color: #2c3e50;
            font-size: 1.5em;
        }

        .default-expiry-hint {
            font-size: 12px;
            color: #28a745;
            margin-top: 5px;
            font-style: italic;
        }
    </style>
</head>
<body>
<div class="overlay" id="overlay"></div>

<div class="container">
    <div class="header">
        <h1>🍎 FreshTracker</h1>
        <p>Учет продуктов и контроль сроков годности</p>
    </div>

    <div class="main-content">
        <div class="section-header">
            <h2 class="section-title">Список продуктов</h2>
            <button class="add-product-btn" onclick="openFormPanel()">➕ Добавить продукт</button>
        </div>

        <div class="product-item header">
            <div>Наименование</div>
            <div>Вес</div>
            <div>Тип</div>
            <div>Срок годности</div>
            <div>Статус</div>
            <div>Действие</div>
        </div>

        <div id="productsList" class="products-list">
            <div class="empty-state">
                <i>📦</i>
                <p>Нет добавленных продуктов</p>
                <button class="btn" onclick="openFormPanel()" style="width: auto; margin-top: 20px;">Добавить первый продукт</button>
            </div>
        </div>
    </div>
</div>

<!-- Панель добавления продукта -->
<div class="form-panel" id="formPanel">
    <button class="close-panel" onclick="closeFormPanel()">×</button>
    <h3 class="panel-title">Добавить продукт</h3>

    <form id="productForm">
        <div class="form-group">
            <label for="name">Наименование продукта</label>
            <input type="text" id="name" name="name" required placeholder="Например: Гречневая крупа">
        </div>

        <div class="form-group">
            <label for="type">Тип продукта</label>
            <select id="type" name="type" required onchange="updateDefaultsByType()">
                <option value="разное" selected>Разное</option>
                <option value="крупы">Крупы</option>
                <option value="макароны">Макароны</option>
                <option value="консервы">Консервы</option>
                <option value="масло">Масло</option>
                <option value="мука">Мука</option>
                <option value="специи">Специи</option>
                <option value="чай_кофе">Чай/Кофе</option>
            </select>
            <div class="default-expiry-hint" id="expiryHint"></div>
        </div>

        <div class="form-group">
            <label for="weight">Вес</label>
            <div class="input-with-prefix">
                <span class="input-prefix">⚖️</span>
                <input type="number" id="weight" name="weight" step="0.001" required placeholder="0.5">
            </div>
        </div>

        <div class="form-group">
            <label for="expiry_date">Срок годности</label>
            <input type="text" id="expiry_date" name="expiry_date" required
                   placeholder="Выберите дату или используйте кнопки ниже">

            <div class="quick-days-buttons">
                <button type="button" class="quick-days-btn" onclick="setDays(3)">+3 дн</button>
                <button type="button" class="quick-days-btn" onclick="setDays(7)">+7 дн</button>
                <button type="button" class="quick-days-btn" onclick="setDays(14)">+14 дн</button>
                <button type="button" class="quick-days-btn" onclick="setDays(30)">+30 дн</button>
                <button type="button" class="quick-days-btn" onclick="setDays(60)">+60 дн</button>
                <button type="button" class="quick-days-btn" id="defaultExpiryBtn" onclick="setDefaultExpiry()" style="background: #28a745;">По умолчанию</button>
            </div>
        </div>

        <div class="form-group">
            <label for="threshold_days">Порог предупреждения (дни)</label>
            <div class="input-with-prefix">
                <span class="input-prefix">⏰</span>
                <input type="number" id="threshold_days" name="threshold_days" value="7" min="1" max="365">
            </div>

            <div class="threshold-buttons">
                <button type="button" class="threshold-btn" onclick="setThreshold(3)">3 дн</button>
                <button type="button" class="threshold-btn" onclick="setThreshold(7)">7 дн</button>
                <button type="button" class="threshold-btn" onclick="setThreshold(14)">14 дн</button>
                <button type="button" class="threshold-btn" onclick="setThreshold(30)">30 дн</button>
            </div>
        </div>

        <button type="submit" class="btn">➕ Добавить продукт</button>
    </form>
</div>

<script src="assets/flatpickr.min.js"></script>
<script src="assets/flatpickr-ru.min.js"></script>
<script>
    let datePicker;

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

    // Загрузка списка при загрузке страницы
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

    function loadProducts() {
        const formData = new FormData();
        formData.append('action', 'get_list');

        fetch('', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(products => {
                displayProducts(products);
            })
            .catch(error => {
                console.error('Error:', error);
            });
    }

    function displayProducts(products) {
        const container = document.getElementById('productsList');

        if (products.length === 0) {
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
            const expiryDate = new Date(product.expiry_date);
            const now = new Date();
            const daysRemaining = Math.floor((expiryDate - now) / (1000 * 60 * 60 * 24));

            let statusClass = '';
            let statusText = '';
            let statusIcon = '';

            if (daysRemaining < 0) {
                statusClass = 'expired';
                statusText = 'Просрочен';
                statusIcon = 'status-expired';
            } else if (daysRemaining <= product.threshold_days) {
                statusClass = 'warning';
                statusText = `Скоро истекает <br>&nbsp;&nbsp;&nbsp;&nbsp; (осталось ${daysRemaining} дн.)`;
                statusIcon = 'status-warning';
            } else {
                statusText = `ОК (${daysRemaining} дн.)`;
                statusIcon = 'status-ok';
            }

            const productElement = document.createElement('div');
            productElement.className = `product-item ${statusClass}`;
            productElement.innerHTML = `
                    <div><strong>${escapeHtml(product.name)}</strong></div>
                    <div>${product.weight} кг</div>
                    <div>${escapeHtml(product.type)}</div>
                    <div>${formatDate(product.expiry_date)}</div>
                    <div><span class="status-indicator ${statusIcon}"></span>${statusText}</div>
                    <div>
                        <button class="delete-btn" onclick="deleteProduct(${product.id})">🗑️ Удалить</button>
                    </div>
                `;

            container.appendChild(productElement);
        });
    }

    function addProduct() {
        const formData = new FormData(document.getElementById('productForm'));
        formData.append('action', 'add');

        fetch('', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    document.getElementById('productForm').reset();
                    // Восстанавливаем выбранный тип по умолчанию
                    document.getElementById('type').value = 'разное';
                    // Восстанавливаем вес по умолчанию для типа "разное"
                    document.getElementById('weight').value = '1.0';
                    // Восстанавливаем порог по умолчанию
                    document.getElementById('threshold_days').value = '7';
                    datePicker.clear();
                    updateExpiryHint();

                    // Сбрасываем подсветку кнопок
                    document.querySelectorAll('.quick-days-btn, .threshold-btn').forEach(btn => {
                        btn.classList.remove('active');
                    });

                    closeFormPanel();
                    loadProducts();
                    showNotification(result.message, 'success');
                } else {
                    showNotification(result.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Ошибка при добавлении', 'error');
            });
    }

    function deleteProduct(id) {
        if (!confirm('Вы уверены, что хотите удалить этот продукт?')) {
            return;
        }

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);

        fetch('', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    loadProducts();
                    showNotification(result.message, 'success');
                } else {
                    showNotification(result.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Ошибка при удалении', 'error');
            });
    }

    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('ru-RU', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    }

    function escapeHtml(unsafe) {
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function showNotification(message, type) {
        // Создаем уведомление
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

        // Анимация появления
        setTimeout(() => {
            notification.style.transform = 'translateX(0)';
        }, 100);

        // Автоматическое скрытие
        setTimeout(() => {
            notification.style.transform = 'translateX(400px)';
            setTimeout(() => {
                document.body.removeChild(notification);
            }, 300);
        }, 3000);
    }
</script>
</body>
</html>