<?php
// contracts.php — Главный интерфейс и контроллер управления договорами
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';
require_once 'logger.php'; // НАМЕРТВО ИСПРАВЛЕНО: Явно подключаем логгер, чтобы не падал бэкенд

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php"); 
    exit;
}

// =========================================================================
// АВТОНОМНОЕ СОХРАНЕНИЕ ДАННЫХ ВНУТРИ CONTRACTS.PHP (POST-КОНТРОЛЛЕР)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        
        // 1. НАМЕРТВО ИСПРАВЛЕНО: Фоновый перехватчик инлайн-изменения номеров договоров (AJAX)
        if (isset($_POST['action_mode']) && $_POST['action_mode'] === 'update_contract_number_fast') {
            $project_id      = (int)($_POST['project_id'] ?? 0);
            $contract_number = trim($_POST['contract_number'] ?? '');
            
            if ($project_id > 0 && !empty($contract_number)) {
                $stmt = $pdo->prepare("UPDATE projects SET contract_number = ? WHERE id = ?");
                $stmt->execute([$contract_number, $project_id]);
                
                if (function_exists('logAction')) {
                    logAction($pdo, 'UPDATE', 'projects', $project_id, "Быстрое инлайн-изменение номера договора на №{$contract_number}");
                }
            }
            // Отдаем чистый JSON успеха фоновому JS и намертво тушим скрипт, чтобы не перезагружать экран!
            echo json_encode(['status' => 'success']);
            exit;
        }

        // 2. СТАНДАРТНОЕ СОХРАНЕНИЕ НОВОГО ДОГОВОРА ИЗ МОДАЛКИ (РЕЖИМ А)
        if (isset($_POST['contract_number'])) {
            $client_id       = (int)($_POST['client_id'] ?? 0);
            $contract_number = trim($_POST['contract_number'] ?? '');
            $contract_date   = !empty($_POST['contract_date']) ? $_POST['contract_date'] : date('Y-m-d');
            $product_type    = isset($_POST['product_type']) ? trim($_POST['product_type']) : '';
            $currency        = trim($_POST['currency'] ?? 'BYN');
            
            if (empty($product_type) && $client_id > 0) {
                $getProdStmt = $pdo->prepare("SELECT product_type FROM clients WHERE id = ?");
                $getProdStmt->execute([$client_id]);
                $product_type = $getProdStmt->fetchColumn() ?: 'Прочее';
            }

            if ($client_id > 0 && !empty($contract_number)) {
                $pdo->beginTransaction();

                $sql = "INSERT INTO projects (client_id, contract_number, contract_date, product_type, currency) VALUES (?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$client_id, $contract_number, $contract_date, $product_type, $currency]);
                $new_project_id = (int)$pdo->lastInsertId();
                
                $uClient = $pdo->prepare("UPDATE clients SET is_contract_signed = 1 WHERE id = ?");
                $uClient->execute([$client_id]);

                if (function_exists('logAction')) {
                    logAction($pdo, 'INSERT', 'projects', $new_project_id, "Создан договор №{$contract_number} (Валюта: {$currency}, Продукция: {$product_type}) для клиента ID {$client_id}");
                }

                $pdo->commit();
            }
            
            header("Location: contracts.php");
            exit;
        }

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack(); // Откатываем базу в случае сбоя
        }
        die("Критический сбой СУБД в POST-контроллере: " . $e->getMessage());
    }
}

// =========================================================================
// ЧАСТЬ 2: УМНАЯ СЕТКА ДОГОВОРОВ (ПРОДОЛЖЕНИЕ ВЫБОРКИ ДАННЫХ ИЗ СУБД)
// =========================================================================
$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'manager';
if ($userRole === 'admin') {
    // ВЫБОРКА ДЛЯ АДМИНИСТРАТОРА (Каждый контракт — отдельная строка таблицы!)
    $sql = "SELECT c.id as cid, c.client_name,
                   p.id as pid, p.contract_number, p.contract_date, p.product_type, p.scan_path,
                   IFNULL(NULLIF(TRIM(p.currency), ''), 'BYN') as main_contract_currency,
                   
                   -- СУБД-АВТОМАТ: Считает суммы ТТН строго для ЭТОГО конкретного договора (p.id)
                   IFNULL((
                       SELECT GROUP_CONCAT(CONCAT(FORMAT(sum_amt, 2, 'ru_RU'), ' ', currency) SEPARATOR ' / ')
                       FROM (
                           SELECT project_id, currency, SUM(amount) as sum_amt
                           FROM project_ttns
                           GROUP BY project_id, currency
                       ) as ttn_sub
                       WHERE ttn_sub.project_id = p.id
                   ), '0.00 BYN') as ttn_currency_totals
                   
            FROM clients c
            LEFT JOIN projects p ON c.id = p.client_id
            WHERE c.is_contract_signed = 1
            ORDER BY c.client_name ASC, p.id DESC"; // ИСПРАВЛЕНО: Лишняя запятая убрана, запрос цельный!
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
} else {
    // ВЫБОРКА ДЛЯ МЕНЕДЖЕРА (Каждый контракт — отдельная строка таблицы!)
    $sql = "SELECT c.id as cid, c.client_name,
                   p.id as pid, p.contract_number, p.contract_date, p.product_type, p.scan_path,
                   IFNULL(NULLIF(TRIM(p.currency), ''), 'BYN') as main_contract_currency,
                   
                   -- СУБД-АВТОМАТ: Считает суммы ТТН строго для ЭТОГО конкретного договора (p.id)
                   IFNULL((
                       SELECT GROUP_CONCAT(CONCAT(FORMAT(sum_amt, 2, 'ru_RU'), ' ', currency) SEPARATOR ' / ')
                       FROM (
                           SELECT project_id, currency, SUM(amount) as sum_amt
                           FROM project_ttns
                           GROUP BY project_id, currency
                       ) as ttn_sub
                       WHERE ttn_sub.project_id = p.id
                   ), '0.00 BYN') as ttn_currency_totals
                   
            FROM clients c
            LEFT JOIN projects p ON c.id = p.client_id
            WHERE c.is_contract_signed = 1 AND c.manager_id = ?
            ORDER BY c.client_name ASC, p.id DESC"; // НАМЕРТВО ИСПРАВЛЕНО: Лишняя запятая убрана!
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
}

$rows = $stmt->fetchAll();
?>

<?php
if (isset($_POST['action_mode']) && $_POST['action_mode'] === 'update_contract_number_fast') {
    $project_id      = (int)($_POST['project_id'] ?? 0);
    $contract_number = trim($_POST['contract_number'] ?? '');
    
    if ($project_id > 0 && !empty($contract_number)) {
        $stmt = $pdo->prepare("UPDATE projects SET contract_number = ? WHERE id = ?");
        $stmt->execute([$contract_number, $project_id]);
    }
    // Отдаем JSON успеха фоновому JS и гасим скрипт
    echo json_encode(['status' => 'success']);
    exit;
}
// -----------------------------------------------------------------
// АСИНХРОННОЕ ИНЛАЙН-ОБНОВЛЕНИЕ ДАТЫ ДОГОВОРА ИЗ ТАБЛИЦЫ ПРОЕКТОВ
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_mode']) && $_POST['action_mode'] === 'update_contract_date_live') {
    header('Content-Type: application/json');
    if (ob_get_length()) ob_clean();

    try {
        $project_id    = (int)($_POST['project_id'] ?? 0);
        $contract_date = trim($_POST['contract_date'] ?? '');

        if ($project_id <= 0) {
            throw new Exception("Некорректный системный ID проекта.");
        }

        // Если дату очистили — пишем NULL, иначе форматируем
        $final_date = !empty($contract_date) ? $contract_date : null;

        // Обновляем строго поле даты в таблице проектов (замени имя таблицы/колонки, если отличаются)
        $stmt = $pdo->prepare("UPDATE projects SET contract_date = ? WHERE id = ?");
        $stmt->execute([$final_date, $project_id]);

        echo json_encode(['status' => 'success']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}
?>


<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Контракты и отгрузки</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cloudflare.com">
    
    
    <style>
        .type-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; border: none; cursor: pointer; }
        .type-new { background: #dbeafe; color: #1e40af; }
        .type-old { background: #fef9c3; color: #854d0e; }
        .reminder-alert { border: 2px solid #ef4444 !important; background-color: #fee2e2 !important; animation: pulse 2s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.7; } 100% { opacity: 1; } }
 .date-input-table {
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    padding: 4px 8px;
    font-family: inherit;
    font-size: 13px;
    color: #334155;
    background-color: #f8fafc;
    width: 100%;
    box-sizing: border-box;
    cursor: pointer;
}

.date-input-table:focus {
    outline: none;
    border-color: #4f46e5;
    background-color: white;
    box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.1);
}   
 </style>

</head>
<body>
    
        <?php include 'sidebar.php'; ?>
   
    <main>
       <!-- ИСПРАВЛЕНО: Премиальный, синтаксически чистый и изолированный топбар без лишних тегов -->
<header style="width: 100%; display: flex; align-items: center; justify-content: space-between; padding: 16px 24px; background: #1e1e2d; border-bottom: 1px solid #323248; border-radius: 12px; margin-bottom: 25px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); box-sizing: border-box;">
    
    <!-- Левая часть: Заголовок страницы -->
    <div style="display: flex; align-items: center; gap: 12px;">
        <span style="font-size: 20px;">💼</span>
        <h1 style="margin: 0; font-size: 18px; font-weight: 700; color: #ffffff; letter-spacing: 0.3px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
            Учет договоров и проектов
        </h1>
    </div>

    <!-- Центральная часть: Статус авторизованного пользователя -->
    <div style="display: flex; align-items: center; gap: 8px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
        <span style="font-size: 13px; color: #92929f; font-weight: 500;">Вы:</span>
        <span style="background: rgba(168, 85, 247, 0.12); color: #a855f7; border: 1px solid rgba(168, 85, 247, 0.25); padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase;">
            👤 <?= htmlspecialchars($_SESSION['login'] ?? 'admin') ?>
        </span>
    </div>

    <!-- Правая часть: Кнопка экспорта в Excel -->
    <div>
        <a href="export_contracts_excel.php" style="display: inline-flex; align-items: center; gap: 8px; height: 38px; padding: 0 16px; background: #10b981; color: #ffffff; text-decoration: none; border-radius: 8px; font-size: 12px; font-weight: 700; letter-spacing: 0.3px; text-transform: uppercase; border: none; cursor: pointer; box-sizing: border-box; transition: all 0.15s; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;"
           onmouseover="this.style.background='#059669'; this.style.transform='translateY(-1px)';"
           onmouseout="this.style.background='#10b981'; this.style.transform='none';">
            📊 <span>Скачать отчет в Excel</span>
        </a>
    </div>

</header>


   <div style="display: flex; flex-direction: column; gap: 4px; width: 300 px;">
    
  <!-- ИСПРАВЛЕНО: Аккуратные премиум-отступы для блока быстрого поиска контракта -->
<div style="display: flex; flex-direction: column; gap: 6px; width: 300px; margin-top: 20px; margin-bottom: 20px; box-sizing: border-box; background: transparent;">
    <div style="display: flex; flex-direction: column; gap: 4px; width: 280px; box-sizing: border-box;">
        <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">Быстрый поиск контракта:</label>
        <input type="text" 
               id="contract_live_search" 
               placeholder="Имя, № договора, продукция..." 
               oninput="runLiveContractFilter(this.value)"
               style="height: 42px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; box-sizing: border-box; font-size: 13px; width: 100%; transition: border-color 0.15s;"
               onfocus="this.style.borderColor='#4f46e5';"
               onblur="this.style.borderColor='#323248';">
    </div>
</div>
<script>
// ИСПРАВЛЕНО: Адаптивный сквозной поиск по Клиенту, Номеру договора и Виду продукции
function runLiveContractFilter(searchQuery) {
    const query = searchQuery.toLowerCase().trim();
    const tableRows = Array.from(document.querySelectorAll("table tbody tr"));

    if (query === "") {
        // Если инпут пустой — мгновенно возвращаем стопроцентную видимость всем строкам
        tableRows.forEach(row => row.style.display = "");
        return;
    }

    // Шаг 1: Сначала разбиваем всю таблицу на изолированные группы (Клиент + его контракты)
    let groups = [];
    let currentGroup = null;

    tableRows.forEach(row => {
        // Проверяем, является ли строка заголовком клиента.
        // Обычно у тебя строка клиента имеет отличительный фон (например, #242434) или жирный шрифт,
        // либо в ней ячейка td имеет атрибут colspan. Самый надежный способ — проверить наличие colspan.
        const isClientHeader = row.querySelector('td[colspan]') !== null || row.style.fontWeight === 'bold' || row.textContent.includes('📦') === false;

        if (isClientHeader) {
            // Началась новая группа компании
            if (currentGroup) groups.push(currentGroup);
            currentGroup = { header: row, childs: [] };
        } else {
            // Это строка контракта — пришиваем её к текущему родителю-клиенту
            if (currentGroup) {
                currentGroup.childs.push(row);
            }
        }
    });
    // Не забываем положить в массив последнюю обработанную группу
    if (currentGroup) groups.push(currentGroup);

    // Шаг 2: Сканируем каждую группу на совпадение букв из поиска
    groups.forEach(group => {
        // Проверяем текст в самом заголовке клиента
        let hasMatch = group.header.textContent.toLowerCase().includes(query);

        // Проверяем текст в каждом контракте этого клиента
        group.childs.forEach(child => {
            if (child.textContent.toLowerCase().includes(query)) {
                hasMatch = true; // Совпадение найдено в контракте!
            }
        });

        // Шаг 3: Управляем видимостью всей группы на экране
        if (hasMatch) {
            group.header.style.display = ""; // Показываем клиента
            group.childs.forEach(child => child.style.display = ""); // Показываем ВСЕ его контракты
        } else {
            group.header.style.display = "none"; // Прячем всю группу
            group.childs.forEach(child => child.style.display = "none");
        }
    });
}
</script>
<!-- ИСПРАВЛЕНО НАМЕРТВО: Запечатали таблицу договоров в рамки экрана с адаптивным горизонтальным скроллом -->
<!-- Контейнер таблицы с мягким скруглением и аккуратной тенью -->
<!-- ПРЕМИАЛЬНЫЙ КОНТЕЙНЕР ТАБЛИЦЫ С ЭФФЕКТОМ ГЛУБОКОЙ ТЕНИ -->
<!-- ИСПРАВЛЕНО: Сняли любые ограничения по ширине с внешнего контейнера коробки -->

<div style="display: flex; flex-direction: column; gap: 4px; width: 300px; margin-bottom: 20px; box-sizing: border-box;">
    
</div> <!-- ЗАКРЫВАЕМ ВНЕШНИЙ БЛОК, ОСВОБОЖДАЯ ТАБЛИЦУ -->   
    <!-- Кастомный премиум-скроллбар (Webkit) -->
    <style>
        div::-webkit-scrollbar { width: 8px; height: 8px; }
        div::-webkit-scrollbar-track { background: #13131a; border-radius: 16px; }
        div::-webkit-scrollbar-thumb { background: #2e2a47; border-radius: 16px; transition: 0.2s; }
        div::-webkit-scrollbar-thumb:hover { background: #4f46e5; }
    
    </style>
<!-- ИСПРАВЛЕНО: Полная двухкоординатная прокрутка масштабной таблицы -->
<div style="width: 100%; height: 580px; max-height: 580px; overflow-y: auto; overflow-x: auto; position: relative; border-radius: 12px; border: 1px solid #323248; box-sizing: border-box; background: #151521; margin-top: 15px;">
    <table style="width: 100% !important; border-collapse: separate; border-spacing: 0; margin: 0; background: #13131a; table-layout: auto !important; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; box-sizing: border-box;">
  <style>* Принудительно заставляем каждую ячейку в теле таблицы наследоваться от ширины заголовка th */

/* Наследуем процентную ширину шапки во все нижние ячейки автоматически */
th, td {
    box-sizing: border-box !important;
    vertical-align: middle !important;
    overflow: hidden !important;
}

/* СТИЛЬ ДЛЯ СЛУЖЕБНЫХ И ЦИФРОВЫХ КОЛОНОК (Даты, Номера, Кнопки, Суммы)
   Здесь текст обязан быть в одну строку и не прыгать вниз */
.bug-table th:not(:first-child), 
.bug-table td:not(:first-child),
table th:not(:first-child),
table td:not(:first-child) {
    white-space: nowrap !important;
    text-overflow: ellipsis !important;
}

/* СТИЛЬ ДЛЯ ТЕКСТОВЫХ КОЛОНОК (Клиент / Договор / Описание бага)
   Разрешаем тексту переноситься по словам, чтобы менеджеры видели полные имена компаний! */
.bug-table th:first-child, 
.bug-table td:first-child,
table th:first-child,
table td:first-child {
    white-space: normal !important;      /* Разрешаем перенос строк */
    word-break: break-word !important;   /* Переносим длинные слова по слогам */
    line-height: 1.4 !important;         /* Комфортный межстрочный интервал */
}

/* Очищаем любые ломающие инлайн-ширины ячеек внутри tr */
table tbody tr td {
    width: auto !important;
    max-width: 100% !important;
}
}</style>
        
        <!-- ЖЕСТКАЯ СЕТКА КОЛОНОК (ЛИНИИ ШАПКИ И ТЕЛА СТАНУТ ИДЕАЛЬНО ПРЯМЫМИ) -->
   <!-- АДАПТИВНАЯ СЕТКА КОЛОНОК (ЖЕСТКИЙ ФИКС СДВИГА И РАСТЯЖЕНИЯ НА 100% ЭКРАНА) -->
 <colgroup>
        <col style="width: 26%;">   <!-- 1. Клиент / Договор (максимум места под длинные имена) -->
        <col style="width: 12%;">   <!-- 2. № Договора -->
        <col style="width: 10%;">   <!-- 3. Дата дог. -->
        <col style="width: 10%;">   <!-- 4. Продукция -->
        <col style="width: 8%;">    <!-- 5. Отгрузки (кнопка ТТН) -->
        <col style="width: 10%;">   <!-- 6. Посл. отгрузка -->
        <col style="width: 14%;">   <!-- 7. Сумма отгрузок по ТТН -->
        <col style="width: 2%;">    <!-- 8. Скрытая микро-колонка (для цифры 123,00) -->
        <col style="width: 8%;">    <!-- 9. Кнопка "В Скан" -->
    </colgroup>

        <!-- РОСКОШНАЯ ЛИПКАЯ ШАПКА ТАБЛИЦЫ -->
                <!-- РОСКОШНАЯ ЛИПКАЯ ШАПКА ТАБЛИЦЫ С ЖЕСТКИМ ПОПИКСЕЛЬНЫМ ПОЗИЦИОНИРОВАНИЕМ -->
                <!-- АДАПТИВНАЯ ЛИПКАЯ ШАПКА ТАБЛИЦЫ (ПОДСТРАИВАЕТСЯ ПОД ЛЮБОЙ МОНИТОР И МАСШТАБ) -->
                <thead style="background: #14141f; border-bottom: 2px solid #232334;">
   
        <!-- 1. Клиент / Договор -->

        <!-- 1. Клиент / Договор -->
        <th style="padding: 14px 12px; text-align: left; font-size: 11px; color: #71717a; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; border: none !important;">
            Клиент / Договор
        </th>
        
        <!-- 2. № договора -->
        <th style="padding: 14px 12px; text-align: center; font-size: 11px; color: #71717a; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; border: none !important;">
            № Договора
        </th>
        
        <!-- 3. Дата дог. -->
        <th style="padding: 14px 12px; text-align: center; font-size: 11px; color: #71717a; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; border: none !important;">
            Дата дог.
        </th>
        
        <!-- 4. Продукция -->
        <th style="padding: 14px 12px; text-align: center; font-size: 11px; color: #71717a; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; border: none !important;">
            Продукция
        </th>
        
        <!-- 5. Отгрузки -->
        <th style="padding: 14px 12px; text-align: center; font-size: 11px; color: #71717a; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; border: none !important;">
            Отгрузки
        </th>
        
        <!-- 6. Последняя отгрузка -->
        <th style="padding: 14px 12px; text-align: center; font-size: 11px; color: #71717a; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; border: none !important;">
            Посл. отгрузка
        </th>
        
        <!-- 7. Сумма отгрузок по ТТН -->
        <th style="padding: 14px 16px; text-align: right; font-size: 11px; color: #71717a; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; border: none !important;">
            Сумма отгрузок по ТТН
        </th>
        
        <!-- 8. Резерв под маркер (Пустой th для скрытой колонки 2%) -->
        <th style="border: none !important; padding: 0;"></th>
        
        <!-- 9. Кнопка Скан -->
        <th style="padding: 14px 12px; text-align: center; font-size: 11px; color: #71717a; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; border: none !important;">
            Скан
        </th>

   <!-- =========================================================================
     ЯЧЕЙКА 8: СКАН ДОГОВОРА С ВСТРОЕННЫМ КРЕСТИКОМ УДАЛЕНИЯ (МОНОЛИТ v3.5)
     ========================================================================= -->
<?php 
// ИСПРАВЛЕНО НАМЕРТВО: Инициализируем ID проекта в самом верху, чтобы id ячейки не обнулялся!
$currentPid = (int)($r['pid'] ?? 0);
$scanUrl = trim($r['scan_path'] ?? '');
?>
<td id="js-scan-cell-<?= $currentPid ?>" style="padding: 14px 12px; text-align: center; border: none !important; box-sizing: border-box; white-space: nowrap;">
    <div style="display: flex; align-items: center; justify-content: center; gap: 6px; width: 100%; box-sizing: border-box;">
    <?php 
    if (!empty($scanUrl) && $scanUrl !== 'NULL' && $scanUrl !== '0'): 
        $ext = strtolower(pathinfo($scanUrl, PATHINFO_EXTENSION));
        $btnLabel = ($ext === 'pdf') ? '👁 PDF' : '👁 ФОТО';
        $bColor = ($ext === 'pdf') ? '#ef4444' : '#6366f1'; // PDF - красный, ФОТО - индиго
    ?>
        <!-- 1. Кнопка просмотра прикрепленного документа (ИСПРАВЛЕНО: Полностью изолирована) -->
        <a href="<?= htmlspecialchars($scanUrl, ENT_QUOTES, 'UTF-8') ?>" 
           target="_blank" 
           style="color: <?= $bColor ?>; text-decoration: none; font-size: 11px; font-weight: bold; background: <?= $bColor ?>15; padding: 5px 10px; border-radius: 6px; border: 1px solid <?= $bColor ?>30; display: inline-block; transition: all 0.15s;">
            <?= $btnLabel ?>
        </a>
        
        <!-- 2. КНОПКА УДАЛЕНИЯ СКАНА (ИСПРАВЛЕНО: Стоит рядом, не ломает верстку) -->
        <button type="button" 
                onclick="deleteContractScanInline(<?= $currentPid ?>); return false;" 
                title="Удалить этот документ из базы данных и с диска"
                style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.25); color: #ef4444; cursor: pointer; font-size: 11px; font-weight: bold; padding: 4px 6px; border-radius: 6px; display: flex; align-items: center; justify-content: center; transition: all 0.15s; outline: none; box-sizing: border-box; line-height: 1;">
            ❌
        </button>
        
    <?php else: ?>
        <!-- 3. Кнопка быстрой инлайн-загрузки (Если скана в базе еще нет) -->
        <?php if ($currentPid > 0): ?>
            <label for="contract_file_input_<?= $currentPid ?>" style="cursor: pointer; color: #818cf8; font-size: 12px; padding: 5px 12px; background: #151521; border: 1px solid #323248; border-radius: 6px; display: inline-block; transition: all 0.15s;" onmouseover="this.style.borderColor='#4f46e5';" onmouseout="this.style.borderColor='#323248';">
                📎 Скан
           
            <input type="file" 
                   id="contract_file_input_<?= $currentPid ?>" 
                   accept=".pdf,.jpg,.jpeg,.png" 
                   style="display: none;" 
                   onchange="uploadContractScanFast(<?= $currentPid ?>, this)">
                    </label>
        <?php endif; ?>
    <?php endif; ?>
    </div>
    <script>

async function deleteContractScanInline(pid) {
    const safePid = parseInt(pid, 10);
    if (isNaN(safePid) || safePid <= 0) return;

    if (!confirm("Вы действительно хотите полностью удалить этот документ и стереть его с сервера?")) {
        return;
    }

    console.log("=== ЗАПУСК УДАЛЕНИЯ СКАНА ДОГОВОРА ===");
    
    // Находим ячейку в DOM дереве по её четкому валидному ID
    const cellContainer = document.getElementById('js-scan-cell-' + safePid);
    if (cellContainer) {
        cellContainer.innerHTML = '<span style="color:#ef4444; font-size:12px; font-style:italic;">🗑️ Стирание...</span>';
    }

    const fd = new FormData();
    fd.append('action_mode', 'delete_contract_scan_full');
    fd.append('project_id', safePid);

    try {
        const res = await fetch('upload_scan.php', { method: 'POST', body: fd });
        const result = await res.json();

        if (result.status === 'success') {
            console.log("Документ проекта №" + safePid + " успешно удалён с сервера.");
            
            // РЕАКТИВНЫЙ UI: Возвращаем скрепку на место без перезагрузки всей страницы!
            if (cellContainer) {
                cellContainer.innerHTML = `
                    <div style="display: flex; align-items: center; justify-content: center; gap: 6px; width: 100%; box-sizing: border-box;">
                        <label for="contract_file_input_${safePid}" style="cursor: pointer; color: #818cf8; font-size: 12px; padding: 5px 12px; background: #151521; border: 1px solid #323248; border-radius: 6px; display: inline-block; transition: all 0.15s;">
                            📎 Скан
                        </label>
                        <input type="file" id="contract_file_input_${safePid}" accept=".pdf,.jpg,.jpeg,.png" style="display: none;" onchange="uploadContractScanFast(${safePid}, this)">
                    </div>`;
            }
        } else {
            alert("Ошибка СУБД: " + result.message);
            window.location.reload();
        }
    } catch (err) {
        alert("Критическая ошибка сети при удалении файла.");
        window.location.reload();
    }
}

    </script>
</td>  
        <!-- 9. ТЕХНИЧЕСКАЯ КОЛОНКА ПОД ПРАВУЮ КНОПКУ ДЕЙСТВИЯ (Добавить договор) -->
        <th style="padding: 14px 12px; border: none !important; width: 140px;"></th>
    </tr>
</thead>
        <!-- БУФЕР КЛИЕНТСКИХ СТРОК С ЭФФЕКТАМИ ПОДСВЕТКИ -->
<tbody style="background: rgba(255,255,255,0.01); ">
            <?php 
            $lastClient = ""; 
            $totalByn = 0;
            $rates = (isset($globalRates) && is_array($globalRates)) ? $globalRates : ['BYN' => 1.0, 'USD' => 3.25, 'EUR' => 3.55, 'RUB' => 0.035, 'CNY' => 0.45];
            
            foreach ($rows as $r): 
                $isNewGroup = ($r['client_name'] !== $lastClient);
                
                // Финансовая агрегация
                $sumQuery = $pdo->prepare("SELECT SUM(amount) FROM project_ttns WHERE project_id = ?");
                $sumQuery->execute([$r['pid']]);
                $totalBynSum = (float)$sumQuery->fetchColumn();
                $totalByn += $totalBynSum;
                
                $savedCurrency = !empty($r['currency']) ? $r['currency'] : 'RUB';
                $rateValue = isset($rates[$savedCurrency]) ? (float)$rates[$savedCurrency] : 1.0;
                $convertedSum = ($rateValue > 0) ? ($totalBynSum / $rateValue) : 0;
                
                $projectId = (int)($r['pid'] ?? 0);
            ?>

            <?php if ($isNewGroup): ?>
                <!-- ГРУППИРОВОЧНЫЙ ЗАГРУЗОЧНЫЙ ДАШБОРД КОНТРАГЕНТА -->
                 <tr class="client-row" style="background: rgba(30, 30, 45, 0.2); transition: background 0.15s;">
                    <td colspan="9" style="padding: 16px 12px; text-align: left; vertical-align: middle; border-top: 2px solid rgba(129, 140, 248, 0.35) !important; box-sizing: border-box;">
                       <div style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="color: #818cf8; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;">🏢 Клиент:</span>
                    <span style="color: #ffffff; font-size: 14px; font-weight: 700; letter-spacing: 0.3px;"><?= htmlspecialchars($r['client_name']) ?></span>
                    <span style="color: #4b5a75; font-size: 11px; font-weight: normal; margin-left: 4px;">(Все активные договора компании)</span>
                </div>
                            
                          <button type="button" 
        data-client-id="<?= (int)($r['cid'] ?? 0) ?>" 
        data-client-name="<?= htmlspecialchars($r['client_name'] ?? 'Контрагент', ENT_QUOTES, 'UTF-8') ?>"
        onclick="openContractModalFromRow(this); return false;"
        style="background: #1e1e2d; border: 1px solid #323248; color: #818cf8; padding: 6px 12px; border-radius: 8px; font-size: 11px; font-weight: bold; cursor: pointer; transition: background 0.15s;"
        onmouseover="this.style.background='#242434';"
        onmouseout="this.style.background='#1e1e2d';">
    + Добавить договор
</button>
                        </div>
                    </td>
                </tr> <!-- ИСПРАВЛЕНО: Тег закрыт корректно, лишний текстовый скрипт полностью удален -->
                
                <?php $lastClient = $r['client_name']; ?>
<?php endif; ?>

            <!-- СТРОКА ДОГОВОРА С ВЫЛИЗАННЫМИ ПРЕМИУМ-ОТСТУПАМИ И ИНТЕРАКТИВНЫМ МАРКЕРОМ ХОВЕРА -->
            <tr style="border: none !important; border-bottom: 1px solid #1c1c28 !important; transition: all 0.15s ease;" onmouseover="this.style.background='#171725'; this.style.boxShadow='inset 4px 0 0 #4f46e5';" onmouseout="this.style.background='transparent'; this.style.boxShadow='none';">
                
                <!-- 1. Контрактный маркер -->
                <td style="padding: 14px 16px; text-align: left; color: #52526b; font-size: 13px; font-weight: 500; border: none !important; box-sizing: border-box;">
                    <span style="color: #32324d; margin-right: 6px;">↳</span> <?= !empty($r['contract_number']) ? 'Контрактный проект' : '<span style="color: #4b5563; font-style: italic;">Пустой черновик</span>' ?>
                </td>
                
                <!-- 2. № Договора инлайн -->
               <td style="padding: 14px 12px; text-align: center; border: none !important; box-sizing: border-box;">
    <?php 
    $inlinePid = (int)($r['pid'] ?? 0);
    $currentNum = trim($r['contract_number'] ?? '');
    if (empty($currentNum) || $currentNum === '—') {
        $currentNum = '—';
    }
    ?>
    <div class="editable" 
         contenteditable="true" 
         data-f="contract_number" 
         data-id="<?= $inlinePid ?>" 
         onfocus="this.style.background='#ffffff'; this.style.color='#000000'; this.style.borderColor='#4f46e5';" 
         onblur="this.style.background='#13131a'; this.style.color='#ffffff'; this.style.borderColor='#232334'; saveInlineContractNumber(this);" 
         style="min-height: 22px; color: #ffffff; font-weight: 700; background: #13131a; padding: 5px 10px; border-radius: 6px; border: 1px solid #232334; outline: none; display: inline-block; min-width: 90px; box-sizing: border-box; font-size: 13px; transition: all 0.2s ease-in-out; cursor: text;">
        <?= htmlspecialchars($currentNum, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <script>async function saveInlineContractNumber(element) {
    if (!element) return;

    const pid = element.getAttribute('data-id');
    // Забираем чистый текст, который менеджер вбил руками внутрь тега div
    let newNumber = element.innerText.trim();

    console.log("=== ИНЛАЙН ОБНОВЛЕНИЕ CONTENTEDITABLE ===");
    console.log("ID проекта:", pid, "Новый введённый номер:", newNumber);

    if (!pid || pid === "0") {
        console.error("Потерялся системный ID проекта на элементе.");
        return;
    }

    // Если менеджер стёр всё — возвращаем прочерк
    if (newNumber === "" || newNumber === "—") {
        element.innerText = "—";
        newNumber = "—";
    }

    const fd = new FormData();
    fd.append('action_mode', 'update_contract_number_fast');
    fd.append('project_id', pid);
    fd.append('contract_number', newNumber);

    try {
        // Отправляем асинхронный POST-запрос на эту же страницу
        const res = await fetch('contracts.php', { method: 'POST', body: fd });
        
        if (res.ok) {
            // Эффект успешного неонового сохранения (зелёная вспышка границ)
            element.style.borderColor = '#10b981';
            element.style.boxShadow = '0 0 8px rgba(16,185,129,0.4)';
            
            setTimeout(() => {
                element.style.borderColor = '#232334';
                element.style.boxShadow = 'none';
            }, 1000);
            
            console.log("Номер договора успешно обновлен на №" + newNumber);
        } else {
            throw new Error("Код ответа: " + res.status);
        }
    } catch (err) {
        element.style.borderColor = '#ef4444';
        element.style.boxShadow = '0 0 8px rgba(239,68,68,0.4)';
        alert("Не удалось сохранить изменённый номер договора на сервере XAMPP.");
    }
}</script>
</td>
                <!-- 3. Дата договора -->
              <td style="padding: 8px 12px; text-align: center; border: none !important; box-sizing: border-box; width: 150px;">
    <input type="date" 
           value="<?= !empty($r['contract_date']) ? date('Y-m-d', strtotime($r['contract_date'])) : '' ?>"
           onchange="updateContractDateInline(<?= (int)$r['pid'] ?>, this.value);"
           style="background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; padding: 4px 8px; font-family: monospace; font-size: 13px; outline: none; cursor: pointer; transition: all 0.2s ease-in-out; width: 100%; box-sizing: border-box; color-scheme: dark;">
<script>
    async function updateContractDateInline(projectId, newDateValue) {
    if (!projectId || projectId <= 0) {
        console.error("Ошибка: Неверный системный ID проекта.");
        return;
    }

    // Находим наш инпут на странице, чтобы сделать красивую подсветку
    const dateInput = event.target;
    
    console.log(`=== ЖИВАЯ ОБНОВЛЕНИЕ ДАТЫ ДОГОВОРА ДЛЯ ПРОЕКТА #${projectId}: ${newDateValue} ===`);

    const fd = new FormData();
    fd.append('action_mode', 'update_contract_date_live');
    fd.append('project_id', projectId);
    fd.append('contract_date', newDateValue);

    try {
        // Шлём быстрый фоновый запрос на текущий файл-обработчик сохранения (или save.php)
        const res = await fetch('save.php', { method: 'POST', body: fd });
        const result = await res.json();

        if (result.status === 'success') {
            // Успешная изумрудная вспышка рамки
            if (dateInput) {
                dateInput.style.borderColor = '#10b981';
                dateInput.style.boxShadow = '0 0 10px rgba(16, 185, 129, 0.4)';
                setTimeout(() => {
                    dateInput.style.borderColor = '#323248';
                    dateInput.style.boxShadow = 'none';
                }, 1000);
            }
        } else {
            alert("Ошибка СУБД: " + result.message);
            if (dateInput) dateInput.style.borderColor = '#ef4444';
        }
    } catch (err) {
        console.error("Ошибка асинхронного транспорта даты:", err);
    }
}
</script>
        </td>
                
                <!-- 4. Вид продукции (Премиальный полупрозрачный бейдж) -->
                <td style="padding: 14px 12px; text-align: left; border: none !important; box-sizing: border-box;">
                    <?php
                    $currentRecord = isset($r) ? $r : [];
                    $individualProduct = !empty($currentRecord['product_type']) ? trim($currentRecord['product_type']) : '';
                    if (!empty($individualProduct) && $individualProduct !== 'NULL' && $individualProduct !== '—') {
                        echo '<span style="background: rgba(129, 140, 248, 0.08); color: #818cf8; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: bold; border: 1px solid rgba(129, 140, 248, 0.15); display: inline-block;">' . htmlspecialchars($individualProduct) . '</span>';
                    } else {
                        echo '<span style="color: #4b5563; font-weight: 500;">—</span>';
                    }
                    ?>
                </td>
       <td style="padding: 14px 12px; text-align: center; border: none !important; box-sizing: border-box;">
    <?php 
    $line324_pid = (int)($r['pid'] ?? 0); 
    // Считываем чистую валюту договора, которую нам отдает SQL-запрос
    $contractCurrency = trim($r['main_contract_currency'] ?? 'BYN'); 
    ?>
    <button type="button" 
            data-id="<?= $line324_pid ?>"
            data-currency="<?= $contractCurrency ?>"
            onclick="openTtnManagerFromButton(this); return false;" 
            style="background: #4f46e5; color: white; border: none; padding: 6px 14px; border-radius: 6px; font-weight: bold; font-size: 11px; cursor: pointer; transition: background 0.15s; font-family: sans-serif; position: relative; z-index: 10;">
        📦 ТТН
    <script>// =========================================================================
// НАПИСАННЫЙ С НУЛЯ, СВЕЖИЙ НАТИВНЫЙ JAVASCRIPT-ДВИЖОК ДЛЯ ТТН v3.0
// =========================================================================
// 1. ГЛАВНЫЙ ОБРАБОТЧИК КЛИКА КНОПКИ ТТН
function openTtnManagerFromButton(buttonElement) {
    if (!buttonElement) return;
    
    const pid = parseInt(buttonElement.getAttribute('data-id'), 10);
    const contractCurrency = (buttonElement.getAttribute('data-currency') || 'BYN').trim().toUpperCase();
    
    if (isNaN(pid) || pid <= 0) {
        alert("Критическая ошибка: Договор не имеет валидного системного ID!");
        return;
    }
    
    // Записываем параметры в скрытые поля модалки
    document.getElementById('ttn_pid_storage').value = pid;
    document.getElementById('edit_ttn_id_storage').value = '';
    document.getElementById('ttn_currency_hidden').value = contractCurrency;
    
    // Очищаем форму для новой записи
    document.getElementById('new_ttn_num').value = '';
    document.getElementById('new_ttn_quantity').value = '';
    document.getElementById('new_ttn_amount').value = '';
    document.getElementById('ttnFormTitle').innerText = 'Добавить новую отгрузку в рамках контракта:';
    document.getElementById('ttnSubmitBtn').innerText = 'Добавить в рамках контракта';
    
    // Динамически перекрашиваем и подставляем бейдж валюты договора
    const badge = document.getElementById('js-ttn-currency-badge');
    if (badge) {
        badge.innerText = contractCurrency;
        let bColor = '#10b981'; // BYN
        if (contractCurrency === 'RUB') bColor = '#f59e0b';
        if (contractCurrency === 'USD') bColor = '#6366f1';
        if (contractCurrency === 'EUR') bColor = '#ec4899';
        if (contractCurrency === 'CNY') bColor = '#a855f7';
        
        badge.style.color = bColor;
        badge.style.background = bColor + '20';
        badge.style.borderColor = bColor + '40';
    } 
    const label = document.getElementById('ttnContractLabel');
    if (label) label.innerText = 'Системный ID договора: №' + pid;

    // Выводим модалку на экран через Flexbox
    const modal = document.getElementById('ttnManagerModal');
    if (modal) modal.style.display = 'flex';
    // Запускаем асинхронный рендерер списка ТТН
    loadProjectTtnsPremium(pid);
}
// 2. АСИНХРОННЫЙ РЕНДЕРЕР СПИСКА НАКЛАДНЫХ ИЗ БАЗЫ
async function loadProjectTtnsPremium(pid) {
    const safePid = parseInt(pid, 10);
    const container = document.getElementById('projectTtnsListContainer');
    if (!container) return;
    container.innerHTML = '<span style="color:#818cf8; font-size:12px; padding:20px; display:block; text-align:center; font-style: italic;">⏳ Синхронизация с СУБД Santeks...</span>';
    try {
        const response = await fetch('get_ttns.php?project_id=' + safePid);
        if (!response.ok) throw new Error("Статус ошибки: " + response.status);

        const textData = await response.text();
        if (!textData || textData.trim() === "") {
            container.innerHTML = '<span style="color:#4b5563; font-size:12px; padding:20px; display:block; text-align:center; font-style: italic;">Отгрузок в рамках контракта пока нет</span>';
            return;
        }
        let ttns = [];
        try {
            ttns = JSON.parse(textData);
        } catch (jsonErr) {
            container.innerHTML = '<span style="color:#ef4444; font-size:12px; padding:15px; display:block; text-align:center;">⚠️ Сбой структуры данных СУБД.</span>';
            return;
        }
        let html = '';
        if (Array.isArray(ttns) && ttns.length > 0) {
            ttns.forEach(t => {
                const safeNum = (t.ttn_number || '').replace(/'/g, "\\'");
                const safeDate = t.ttn_date ? t.ttn_date.split('-').reverse().join('.') : '—';
                const safeQty = parseInt(t.product_quantity || 0, 10);
                const safeProd = (t.product_info || 'Сантехника').replace(/'/g, "\\'");
                const safeAmt = parseFloat(t.amount || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                const rawCurrency = (t.currency || 'BYN').toString().trim().toUpperCase();

                let curColor = '#10b981';
                let currencyLabel = 'BYN';
                if (rawCurrency === 'RUB') { curColor = '#f59e0b'; currencyLabel = '₽'; }
                if (rawCurrency === 'USD') { curColor = '#6366f1'; currencyLabel = '$'; }
                if (rawCurrency === 'EUR') { curColor = '#ec4899'; currencyLabel = '€'; }
                if (rawCurrency === 'CNY') { curColor = '#a855f7'; currencyLabel = '¥'; }
                html += `
<div style="background: #151521; padding: 10px 12px; border-radius: 8px; border: 1px solid #323248; display: flex; justify-content: space-between; align-items: center; width: 100%; box-sizing: border-box; margin-bottom: 2px;">
    <div style="flex: 1; min-width: 0; padding-right: 10px; text-align:left;">
        <div style="font-weight: bold; color: #fff; font-size: 13px;">ТТН № ${safeNum}</div>
        <div style="color: #71717a; font-size: 11px; margin-top: 2px;">Дата: ${safeDate} | Кол-во: <strong style="color:#f59e0b; font-family: monospace;">${safeQty} шт.</strong></div>
    </div>
    <div style="display: flex; align-items: center; gap: 12px; flex-shrink: 0;">
        <!-- ВОТ ОНА, СТРОКА-ПОБЕДИТЕЛЬ: Заменили статичный знак $ на ${currencyLabel} -->
        <div style="font-weight: 700; color: ${curColor}; font-size: 13px; font-family: monospace;">${safeAmt} ${currencyLabel}</div>
        <button type="button" onclick="activateTtnEditMode(${t.id}, '${safeNum}', '${t.ttn_date}', ${t.amount}, ${safeQty}, '${safeProd}')" style="background:none; border:none; color:#f59e0b; cursor:pointer; font-size:13px; padding:4px;">✏️</button>
    </div>
</div>`;
            });
        } else {
            html += '<span style="color:#4b5563; font-size:12px; padding:20px; display:block; text-align:center; font-style: italic;">Отгрузок в рамках контракта пока нет</span>';
        }      
        container.innerHTML = html;
    } catch (err) {
        container.innerHTML = '<span style="color:#ef4444; font-size:12px; padding:15px; display:block; text-align:center;">Критическая ошибка загрузки списка</span>';
    }
}
// 3. АСИНХРОННАЯ ОТПРАВКА НОВОЙ НАКЛАДНОЙ НА СЕРВЕР
async function submitTtnFormPremium() {
    const pid = document.getElementById('ttn_pid_storage').value;
    const ttnId = document.getElementById('edit_ttn_id_storage').value;
    const ttnNum = document.getElementById('new_ttn_num').value.trim();
    const ttnDate = document.getElementById('new_ttn_date').value;
    const ttnQty = document.getElementById('new_ttn_quantity').value;
    const ttnAmount = document.getElementById('new_ttn_amount').value;
    // ЛОВИМ ВЫБРАННУЮ РУКАМИ ВАЛЮТУ ИЗ СЕЛЕКТА
    const ttnCurrency = document.getElementById('ttn_currency_select').value;
    const ttnProd = document.getElementById('new_ttn_prod').value.trim();

    if (!ttnNum || !ttnAmount) {
        alert("Заполните номер накладной и сумму отгрузки!");
        return;
    }
    const fd = new FormData();
    fd.append('project_id', pid);
    fd.append('ttn_id', ttnId);
    fd.append('ttn_number', ttnNum);
    fd.append('ttn_date', ttnDate);
    fd.append('product_quantity', ttnQty);
    fd.append('new_ttn_amount', ttnAmount); 
    fd.append('ttn_currency_select', ttnCurrency); // ПЕРЕДАЕМ ВАЛЮТУ НА БЭКЕНД
    fd.append('product_info', ttnProd);
    try {
        const res = await fetch('save_ttn.php', { method: 'POST', body: fd });
        const result = await res.json();      
        if (result.status === 'success') {
            document.getElementById('new_ttn_num').value = '';
            document.getElementById('new_ttn_quantity').value = '';
            document.getElementById('new_ttn_amount').value = '';
            document.getElementById('edit_ttn_id_storage').value = '';
            document.getElementById('ttnFormTitle').innerText = 'Добавить новую отгрузку в рамках контракта:';
            document.getElementById('ttnSubmitBtn').innerText = 'Добавить в рамках контракта';
            // Живое обновление верхнего списка ТТН в модалке и общего итога на странице
            loadProjectTtnsPremium(pid);
            if (typeof calculatePageGrandTotalFromScratch === 'function') {
                calculatePageGrandTotalFromScratch();
            }
        } else {
            alert("Ошибка сохранения: " + result.message);
        }
    } catch (err) {
        alert("Ошибка сети при отправке формы ТТН");
    }
}
// 4. АКТИВАЦИЯ РЕДАКТИРОВАНИЯ СТАРОЙ ТТН
function activateTtnEditMode(id, num, date, amount, qty, prod) {
    document.getElementById('edit_ttn_id_storage').value = id;
    document.getElementById('new_ttn_num').value = num;
    document.getElementById('new_ttn_date').value = date;
    document.getElementById('new_ttn_quantity').value = qty;
    document.getElementById('new_ttn_amount').value = amount;
    document.getElementById('new_ttn_prod').value = prod;

    document.getElementById('ttnFormTitle').innerText = 'Редактировать параметры накладной №' + num + ':';
    document.getElementById('ttnSubmitBtn').innerText = 'Сохранить изменения ТТН';
}
</script>
    </button>
    
</td>            
                <!-- 6. Дата последней отгрузки (БЕЗ ИЗМЕНЕНИЙ) -->
<td style="padding: 14px 12px; text-align: center; font-size: 13px; color: #a1a1aa; font-family: monospace; border: none !important; box-sizing: border-box;">
  <?php 
        $ld = $pdo->prepare("SELECT MAX(ttn_date) FROM project_ttns WHERE project_id = ?"); 
        $ld->execute([$r['pid']]);
        $d = $ld->fetchColumn(); 
        echo $d ? date('d.m.Y', strtotime($d)) : '—';
    ?>
</td>
                
        <!-- 7. ИТОГОВАЯ ЧИСТАЯ СУММА ОТГРУЗОК (РАЗДЕЛЬНЫЕ ВАЛЮТЫ И СУММЫ ГРУПП КЛИЕНТА) -->
<td style="padding: 14px 16px; text-align: right; font-size: 13px; font-family: monospace; font-weight: bold; border: none !important; box-sizing: border-box; white-space: nowrap; color: #ffffff;">
    <?php 
    $rawTotals = trim($r['ttn_currency_totals'] ?? '');
    
    if (empty($rawTotals) || $rawTotals === '0.00' || $rawTotals === '0.00 BYN') {
        echo '<span style="color: #ffffff;">0.00</span> <span style="color: #4b5563; font-size: 10px; font-weight: 800; background: rgba(75,85,99,0.1); padding: 2px 5px; border-radius: 5px; border: 1px solid rgba(75,85,99,0.2); vertical-align: middle;">BYN</span>';
    } else {
        $parts = explode(' / ', $rawTotals);
        $formattedParts = [];
        
        foreach ($parts as $part) {
            $part = trim($part);
            $lastSpacePos = strrpos($part, ' ');
            if ($lastSpacePos !== false) {
                $valNumeric = substr($part, 0, $lastSpacePos);
                $curCode = strtoupper(substr($part, $lastSpacePos + 1));
                
                $cColor = '#10b981'; // BYN
                $displayLabel = 'BYN';
                
                if ($curCode === 'RUB') { $cColor = '#f59e0b'; $displayLabel = '₽ RUB'; }
                else if ($curCode === 'USD') { $cColor = '#6366f1'; $displayLabel = '$ USD'; }
                else if ($curCode === 'EUR') { $cColor = '#ec4899'; $displayLabel = '€ EUR'; }
                else if ($curCode === 'CNY') { $cColor = '#a855f7'; $displayLabel = '¥ CNY'; }
                
                $formattedParts[] = "<span style='color: #ffffff;'>{$valNumeric}</span> <span style='color: {$cColor}; font-size: 10px; font-weight: 800; letter-spacing: 0.2px; background: {$cColor}15; padding: 2px 5px; border-radius: 5px; border: 1px solid {$cColor}30; vertical-align: middle; margin-left: 2px;'>{$displayLabel}</span>";
            } else {
                $formattedParts[] = $part;
            }
        }
        echo implode(' <span style="color: #323248; margin: 0 6px;">/</span> ', $formattedParts);
    }
    ?>
</td>
             <!-- 8. СТАТУС ВАЛЮТНОЙ СИНХРОНИЗАЦИИ (НАМЕРТВО ИСПРАВЛЕНО: УБРАН ВАРНИНГ СТР. 872) -->
<td style="padding: 14px 16px; text-align: left; font-size: 11px; color: #71717a; border: none !important; box-sizing: border-box; font-family: sans-serif; min-width: 140px;">
</td>
              <!-- 9. Просмотр и загрузка PDF сканов (ФИНАЛЬНЫЙ ХОТФИКС СКРЕПКИ) -->
             <td style="padding: 14px 12px; text-align: center; border: none !important; box-sizing: border-box; white-space: nowrap;">
    <?php 
    $scanUrl = trim($r['scan_path'] ?? '');
    $currentPid = (int)($r['pid'] ?? 0);
    if (!empty($scanUrl) && $scanUrl !== 'NULL' && $scanUrl !== '0'): 
        // Определяем расширение файла для вывода точного текста на кнопке
        $ext = strtolower(pathinfo($scanUrl, PATHINFO_EXTENSION));
        $btnLabel = ($ext === 'pdf') ? '👁 PDF' : '👁 ФОТО';
        $bColor = ($ext === 'pdf') ? '#ef4444' : '#6366f1'; // PDF - красный, ФОТО - индиго
    ?>
        <!-- Кнопка просмотра прикрепленного документа -->
        <a href="<?= htmlspecialchars($scanUrl, ENT_QUOTES, 'UTF-8') ?>" 
           target="_blank" 
           style="color: <?= $bColor ?>; text-decoration: none; font-size: 11px; font-weight: bold; background: <?= $bColor ?>15; padding: 5px 10px; border-radius: 6px; border: 1px solid <?= $bColor ?>30; display: inline-block; transition: all 0.15s;">
            <?= $btnLabel ?>
        </a>
    <?php else: ?>
        <!-- Кнопка быстрой загрузки скана, если его еще нет (вызывает скрытый клик по инпуту) -->
        <?php if ($currentPid > 0): ?>
            <label for="contract_file_input_<?= $currentPid ?>" style="cursor: pointer; color: #818cf8; font-size: 12px; padding: 5px 12px; background: #151521; border: 1px solid #323248; border-radius: 6px; display: inline-block; transition: all 0.15s;" onmouseover="this.style.borderColor='#4f46e5';" onmouseout="this.style.borderColor='#323248';">
                📎 Скан
            </label>
            <input type="file" 
                   id="contract_file_input_<?= $currentPid ?>" 
                   accept=".pdf,.jpg,.jpeg,.png" 
                   style="display: none;" 
                   onchange="uploadContractScanFast(<?= $currentPid ?>, this)">
        <?php else: ?>
            <span style="color: #4b5563;">—</span>
        <?php endif; ?>
    <?php endif; ?>

<script>async function uploadContractScanFast(pid, inputElement) {
    if (!inputElement || !inputElement.files || inputElement.files.length === 0) return;

    const file = inputElement.files[0];
    console.log("=== СТАРТ ИНЛАЙН ЗАГРУЗКИ СКАНА ===");
    console.log("Проект ID:", pid, "Файл:", file.name, "Размер:", file.size);

    const fd = new FormData();
    fd.append('project_id', pid);
    fd.append('contract_scan', file); // Ключ отправки на upload_scan.php

    // Находим родительский label для вывода красивого лоадера
    const label = inputElement.previousElementSibling;
    const originalText = label.innerText;
    label.innerText = "⏳...";
    label.style.color = "#f59e0b";

    try {
        const res = await fetch('upload_scan.php', { method: 'POST', body: fd });
        const result = await res.json();

        if (result.status === 'success') {
            // Подсвечиваем успехом и полностью перезапускаем страницу для отрисовки новой VIP-кнопки просмотра
            label.innerText = "✅ Готово";
            label.style.color = "#10b981";
            setTimeout(() => { window.location.reload(); }, 500);
        } else {
            alert("Ошибка валидации СУБД:\n" + result.message);
            label.innerText = originalText;
            label.style.color = "#818cf8";
            inputElement.value = ""; // Вычищаем заклинивший сбойный файл из инпута!
        }
    } catch (err) {
        alert("Критический сбой сети при передаче скана договора.");
        label.innerText = originalText;
        label.style.color = "#818cf8";
        inputElement.value = "";
    }
}</script>
</td>
            </tr>
            
            <?php endforeach; ?>

            <script>
// Глобальный инициализатор модального окна контракта
function openNewContractModal(clientId) {
    console.log("Открытие формы договора для клиента ID:", clientId);
    
    const modal = document.getElementById('contractModal') || document.getElementById('newContractModal');
    const clientIdInput = document.getElementById('contract_client_id_storage') || document.getElementById('modal_client_id');
    const form = document.getElementById('contractForm');
    
    // Сбрасываем форму в дефолтный BYN только внутри функции при клике
    if (form) { 
        form.reset(); 
    }
    
    if (clientIdInput) { 
        clientIdInput.value = parseInt(clientId, 10); 
    }
    
    if (modal) { 
        modal.style.display = 'flex'; // Показываем окно только тут!
    }
}

// ХОТФИКС БЕЗОПАСНОСТИ: Принудительно прячем модалку при первой загрузке страницы, чтобы она не вылезала сама
document.addEventListener("DOMContentLoaded", function() {
    const modal = document.getElementById('contractModal') || document.getElementById('newContractModal');
    if (modal) {
        modal.style.display = 'none';
    }
});
</script>       
        </tbody>

            </table>
        </div>
 
 
<?php
// ГЛОБАЛЬНЫЙ МУЛЬТИВАЛЮТНЫЙ ПОДСЧЕТ ДЛЯ ФУТЕРА СТРАНИЦЫ НА СТОРОНЕ PHP
// Собираем вообще все ТТН из базы данных по текущим видимым проектам
$projectIds = array_filter(array_column($rows, 'pid'));

$globalTotalsString = '0.00 BYN';

if (!empty($projectIds)) {
    // Втупую суммируем всеamount из базы, группируя их строго по валюте
    $inQuery = implode(',', array_map('intval', $projectIds));
    $calcSql = "SELECT currency, SUM(amount) as total_wallet 
                FROM project_ttns 
                WHERE project_id IN ($inQuery) 
                GROUP BY currency 
                ORDER BY currency ASC";
    
    $calcStmt = $pdo->query($calcSql);
    $globalCalculations = $calcStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($globalCalculations)) {
        $tempBadges = [];
        foreach ($globalCalculations as $vc) {
            $amtFormatted = number_format((float)$vc['total_wallet'], 2, '.', ' ');
            $curName = strtoupper(trim($vc['currency']));
            
            // Раскрашиваем коды валют для премиального контраста в футере
            $cColor = '#10b981'; // BYN
            if ($curName === 'RUB') $cColor = '#f59e0b';
            if ($curName === 'USD') $cColor = '#6366f1';
            if ($curName === 'EUR') $cColor = '#ec4899';
            if ($curName === 'CNY') $cColor = '#a855f7';
            
            $tempBadges[] = "<span style='color: #ffffff;'>{$amtFormatted}</span> <span style='color: {$cColor}; font-weight: 800; font-size: 11px;'>{$curName}</span>";
        }
        // Склеиваем раздельные валютные кошельки через красивый слэш
        $globalTotalsString = implode(' <span style="color:#323248; margin: 0 10px;">/</span> ', $tempBadges);
    }
}
?>

<!-- ПАРЯЩИЙ МУЛЬТИВАЛЮТНЫЙ ФУТЕР СТРАНИЦЫ (БЕЗ КОНВЕРТЕРОВ И JAVASCRIPT) -->
<div style="position: fixed; bottom: 0; left: 0; right: 0; background: rgba(20, 20, 30, 0.92); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border-top: 1px solid rgba(255, 255, 255, 0.06); padding: 14px 40px; z-index: 9999; box-shadow: 0 -15px 35px rgba(0,0,0,0.6); display: flex; justify-content: space-between; align-items: center; box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    
    <div style="display: flex; align-items: center; gap: 10px;">
        <div style="width: 7px; height: 7px; background: #10b981; border-radius: 5px; box-shadow: 0 0 10px #10b981;"></div>
        <span style="font-size: 11px; color: #71717a; font-weight: bold; tracking-spacing: 0.5px; text-transform: uppercase;">Сводный баланс отгрузок SANTEKS NEXT</span>
    </div>

    <div style="display: flex; align-items: center; gap: 16px;">
        <span style="color: #92929f; font-size: 12px; font-weight: 700; tracking-spacing: 0.5px; text-transform: uppercase;">ОБЩИЙ ИТОГ ПО ВСЕМ КОНТРАКТАМ:</span>
        
        <!-- Сюда PHP выводит готовые раздельные кошельки -->
        <div style="color: #ffffff; font-size: 15px; font-weight: bold; font-family: monospace; background: #151521; border: 1px solid #323248; padding: 8px 18px; border-radius: 10px; text-align: right; box-sizing: border-box; box-shadow: inset 0 2px 4px rgba(0,0,0,0.3); display: flex; align-items: center; gap: 4px;">
            <?= $globalTotalsString ?>
        </div>
    </div>
</div>

<div style="height: 80px; width: 100%; display: block; clear: both;"></div>

<!-- Небольшой технический отступ в самом низу страницы, чтобы таблица при скролле не перекрывалась футером -->
<div style="height: 80px; width: 100%; display: block; clear: both;"></div>
<script>
// НАМЕРТВО ИСПРАВЛЕНО: Всеядный калькулятор футера, который читает данные прямо из сетки таблицы
function calculatePageGrandTotalFromScratch() {
    console.log("=== ЗАПУСК ВСЕЯДНОГО КАЛЬКУЛЯТОРА ФУТЕРА ===");
    
    let totalSum = 0;
    
    // 1. Пытаемся собрать данные по нашему маркер-классу js-project-amount
    const amountElements = document.querySelectorAll('.js-project-amount');
    
    if (amountElements.length > 0) {
        amountElements.forEach(el => {
            const val = parseFloat(el.getAttribute('data-amount')) || 0;
            totalSum += val;
        });
    } else {
        // 2. РЕЗЕРВНЫЙ БРОНЕБОЙНЫЙ ВАРИАНТ: Если классы стёрлись, парсим саму HTML-таблицу напрямую!
        // Находим все строки таблицы, у которых есть ячейки
        const tableRows = document.querySelectorAll('table text, table tr, .client-row');
        
        tableRows.forEach(row => {
            const cells = row.getElementsByTagName('td');
            // В нашей структуре ячейка суммы отгрузок идёт 7-й по счёту (индекс 6 или соседний)
            if (cells.length >= 7) {
                // Извлекаем текстовое содержимое ячейки (например, "1 096 400.00 BYN")
                let cellText = cells[6].innerText || cells[7].innerText || "";
                
                // Хирургическая очистка текста: убираем значки валют, пробелы и буквы, оставляя только число и точку
                cellText = cellText.replace(/[₽$€¥BYNRUB\s]/g, '').replace(',', '.').trim();
                
                const val = parseFloat(cellText) || 0;
                totalSum += val;
            }
        });
    }

    // 3. Выводим итоговую сумму в наш красивый парящий футер страницы
    const target = document.getElementById('js-page-grand-total');
    
    if (target) {
        // Форматируем число с разделением тысяч (1 096 400.00 + 10 227.03 + 4 393.58 = 1 111 020.61 BYN)
        target.innerHTML = totalSum.toLocaleString('ru-RU', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }) + ' <span style="font-size:12px; color:#818cf8; font-weight:800; margin-left:3px; font-family:sans-serif;">BYN</span>';
        
        console.log("Итоговая сумма успешно выведена в парящий футер:", totalSum);
    } else {
        console.error("Ошибка UI: Элемент 'js-page-grand-total' не найден на странице!");
    }
}

// Автоматический повторный вызов калькулятора, если страница загружается асинхронно
document.addEventListener('DOMContentLoaded', function() {
    calculatePageGrandTotalFromScratch();
    // Страхуемся коротким таймаутом, если jQuery или AJAX дорисовывают таблицу чуть позже
    setTimeout(calculatePageGrandTotalFromScratch, 300);
});
</script>
    

<!-- ИСПРАВЛЕНО: Полный редизайн модалки договора и новые виды продукции -->
<!-- ИСПРАВЛЕНО UI/UX: Идеальное центрирование окна ровно по центру экрана менеджера -->
<form id="contractForm" method="POST" action="contracts.php" enctype="multipart/form-data" style="margin: 0; padding: 0; width: 100%; display: flex; flex-direction: column; gap: 16px; box-sizing: border-box;">
<div id="contractModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0, 0, 0, 0.75); justify-content: center; align-items: center; z-index: 99999; box-sizing: border-box; backdrop-filter: blur(5px); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    
    <!-- ВНУТРЕННИЙ БЛОК ОКНА -->
    <div class="modal-content stylish-modal" style="background: #1e1e2d; border-radius: 16px; border: 1px solid #323248; padding: 30px; width: 480px; box-sizing: border-box; box-shadow: 0 25px 50px rgba(0,0,0,0.6); position: relative; display: flex; flex-direction: column; gap: 16px;">
        
        <!-- ХЕДЕР ОКНА -->
        <div class="modal-header" style="text-align: left; width: 100%; box-sizing: border-box;">
            <h2 style="margin: 0; color: #ffffff; font-size: 16px; font-weight: 700; letter-spacing: 0.3px;">
                📋 Новый договор: <span id="modalClientName" style="color: #818cf8; font-weight: 800;">Загрузка...</span>
            </h2>
        </div>

        <!-- САМА ФОРМА ОТПРАВКИ ДАННЫХ -->
        <form id="contractForm" method="POST" action="contracts.php" style="margin: 0; padding: 0; width: 100%; display: flex; flex-direction: column; gap: 16px; box-sizing: border-box;">

            <!-- Скрытый маркер ID клиента -->
            <input type="hidden" id="modal_client_id" name="client_id" value="">
            
            <!-- Сетка: Номер и Дата (Выровнены в один ряд) -->
            <div class="form-row" style="display: flex; gap: 15px; width: 100%; box-sizing: border-box;">
                <div class="form-group" style="flex: 2; display: flex; flex-direction: column; gap: 6px;">
                    <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; text-align: left;">№ Договора *</label>
                   <input type="text" 
       id="edit_contract_number" 
       name="contract_number" 
       required 
       style="width: 100%; height: 42px; padding: 0 14px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none;"> </div>
                <div class="form-group" style="flex: 1; display: flex; flex-direction: column; gap: 6px;">
                    <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; text-align: left;">Дата договора *</label>
                    <input type="date" name="contract_date" value="<?= date('Y-m-d') ?>" required style="width: 100%; height: 42px; padding: 0 14px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; box-sizing: border-box; font-size: 13px; color-scheme: dark; font-weight: bold; transition: all 0.15s ease;" onfocus="this.style.borderColor='#4f46e5'; this.style.background='#191926';" onblur="this.style.borderColor='#323248'; this.style.background='#151521';">
                </div>
            </div>

            <!-- ВЫБОР ВАЛЮТЫ ЗАКЛЮЧЕНИЯ КОНТРАКТА -->
      
            <!-- Вид продукции -->
            <div class="form-group" style="display: flex; flex-direction: column; gap: 6px; width: 100%; box-sizing: border-box;">
                <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; text-align: left;">Вид продукции *</label>
                <select id="modal_contract_product_type" name="product_type" required style="width: 100%; height: 42px; padding: 0 14px; background: #151521; border: 1px solid #323248; color: #818cf8; border-radius: 8px; outline: none; cursor: pointer; font-size: 13px; font-weight: bold; box-sizing: border-box; transition: all 0.15s ease;" onfocus="this.style.borderColor='#4f46e5'; this.style.background='#191926';" onblur="this.style.borderColor='#323248'; this.style.background='#151521';">
                    <option value="Посуда">Посуда</option>
                    <option value="Сантехника" selected>Сантехника</option>
                    <option value="Резервуары">Резервуары</option>
                    <option value="ЕКМ">ЕКМ</option>
                    <option value="МПДУ">МПДУ</option>
                    <option value="Эмалированные таблички">Эмалированные таблички</option>
                    <option value="УОКТ">УОКТ</option>
                    <option value="другое">другое</option>
                </select>
            </div>
<div class="form-group" style="display: flex; flex-direction: column; gap: 6px; margin-bottom: 25px; width: 100%; box-sizing: border-box;">
                <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; text-align: left;">Скан-копия договора / Фото документов</label>
                <div style="position: relative; width: 100%; box-sizing: border-box;">
                    <input type="file" 
                           name="contract_scan" 
                           accept=".pdf,.jpg,.jpeg,.png" 
                           style="width: 100%; height: 42px; padding: 8px 14px; background: #151521; border: 1px solid #323248; color: #818cf8; border-radius: 8px; outline: none; box-sizing: border-box; font-size: 12px; font-weight: 600; cursor: pointer;">
                </div>
            </div>
            <!-- Подвал: Кнопки (Разнесены по правому краю) -->
            <div class="modal-footer" style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 10px; width: 100%; box-sizing: border-box;">
                <button type="button" 
                        onclick="closeContractModal(); return false;" 
                        style="height: 42px; padding: 0 20px; background: #242434; border: 1px solid #323248; color: #92929f; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 13px; transition: all 0.15s ease; box-sizing: border-box;"
                        onmouseover="this.style.color='#fff'; this.style.borderColor='#4f46e5';"
                        onmouseout="this.style.color='#92929f'; this.style.borderColor='#323248';">
                    Отмена
                </button>
                <button type="submit" 
                        class="btn-contract-save" 
                        style="height: 42px; padding: 0 24px; background: #4f46e5; border: none; color: #ffffff; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 13px; transition: all 0.15s ease; box-sizing: border-box;" 
                        onmouseover="this.style.background='#4338ca'; this.style.boxShadow='0 5px 15px rgba(79, 70, 229, 0.3)';" 
                        onmouseout="this.style.background='#4f46e5'; this.style.boxShadow='none';">
                    Создать договор
                </button>
            </div>
        </form>
    </div>
</div>


                        </div>
                        </div>
<!-- 2. СВЕЖИЙ НАПИСАННЫЙ С НУЛЯ JAVASCRIPT-ДВИЖОК ДЛЯ ИНТЕРФЕЙСА ОКНА -->
<script>
// ФУНКЦИЯ ОТКРЫТИЯ ОКНА: Связывает кнопку таблицы с модалкой, передавая ID и имя клиента
function openContractModalFromRow(buttonElement) {
    if (!buttonElement) return;

    const clientId = parseInt(buttonElement.getAttribute('data-client-id'), 10);
    const clientName = buttonElement.getAttribute('data-client-name') || 'Контрагент';

    if (isNaN(clientId) || clientId <= 0) {
        alert("Критическая ошибка UI: Не удалось считать системный ID клиента!");
        return;
    }

    // Записываем ID и подставляем имя
    document.getElementById('modal_client_id').value = clientId;
    document.getElementById('modalClientName').innerText = clientName;

    // Показываем окно
    const modal = document.getElementById('contractModal');
    if (modal) modal.style.display = 'flex';
}


// ФУНКЦИЯ ЗАКРЫТИЯ ОКНА
function closeContractModal() {
    const modal = document.getElementById('contractModal');
    if (modal) {
        modal.style.display = 'none';
    }
}
</script>

    </div>
</div>

</main>
</body>
<!-- ИСПРАВЛЕНО: Полностью рабочий монолит формы управления ТТН/CMR Santeks CRM -->
<!-- МОДАЛЬНОЕ ОКНО МЕНЕДЖЕРА ТТН (УСПЕШНО СИНХРОНИЗИРОВАНО С СУБД SANTEKS) -->
<!-- =========================================================================
     КАРКАС МОДАЛЬНОГО ОКНА ТТН v3.0 (ЧАСТЬ 2: ЧИСТАЯ ВЕРСТКА БЕЗ СЕЛЕКТОРОВ ВАЛЮТ -->
<div id="ttnManagerModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0, 0, 0, 0.75); justify-content: center; align-items: center; z-index: 99999; box-sizing: border-box; backdrop-filter: blur(5px); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <div style="background: #1e1e2d; padding: 25px 30px; border-radius: 16px; width: 500px; border: 1px solid #323248; box-shadow: 0 25px 50px rgba(0,0,0,0.6); color: #fff; display: flex; flex-direction: column; gap: 14px; box-sizing: border-box; position: relative;">
        
        <!-- Шапка окна -->
        <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
            <h3 id="ttnContractLabel" style="margin: 0; font-size: 15px; font-weight: 800; color: #ffffff; tracking-spacing: -0.2px;">Системный ID договора: №--</h3>
            <button type="button" onclick="document.getElementById('ttnManagerModal').style.display='none';" style="background: none; border: none; color: #71717a; font-size: 22px; cursor: pointer; transition: color 0.15s; outline: none; line-height: 1;">&times;</button>
        </div>
     
        <!-- Скрытые технические хранилища ID для бэкенда save_ttn.php -->
        <input type="hidden" id="ttn_pid_storage" value="0">
        <input type="hidden" id="edit_ttn_id_storage" value="">
        <input type="hidden" id="ttn_currency_hidden" value="BYN">
        
        <!-- КОНТЕЙНЕР СПИСКА НАКЛАДНЫХ (Сюда JS выведет готовые строки из get_ttns.php) -->
        <div style="display: flex; flex-direction: column; gap: 6px; text-align: left; width: 100%; box-sizing: border-box;">
            <label style="font-size: 11px; color: #92929f; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Список накладных по проекту:</label>
            <div id="projectTtnsListContainer" style="max-height: 160px; min-height: 70px; overflow-y: auto; background: #151521; border-radius: 8px; padding: 12px; border: 1px solid #323248; display: flex; flex-direction: column; gap: 8px; box-sizing: border-box;">
                <!-- Данные подгружаются асинхронно через JS -->
            </div>
        </div>

        <!-- ФОРМА ДОБАВЛЕНИЯ / РЕДАКТИРОВАНИЯ (Изолированный блок полей) -->
        <div style="background: #242434; padding: 16px; border-radius: 12px; display: flex; flex-direction: column; gap: 12px; text-align: left; box-sizing: border-box; border: 1px solid #323248; width: 100%;">
            <h4 id="ttnFormTitle" style="margin: 0; font-size: 12px; color: #818cf8; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Добавить новую отгрузку в рамках контракта:</h4>
            
            <!-- Ряд 1: Номер ТТН и Дата -->
            <div style="display: flex; gap: 10px; width: 100%; box-sizing: border-box;">
                <input type="text" id="new_ttn_num" placeholder="№ ТТН / CMR" style="flex: 2; padding: 10px 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; font-size: 13px; box-sizing: border-box;">
                <input type="date" id="new_ttn_date" value="<?= date('Y-m-d') ?>" style="flex: 1; padding: 10px 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; font-size: 13px; color-scheme: dark; font-weight: bold; box-sizing: border-box;">
            </div>

            <!-- Ряд 2: Количество штук продукции -->
            <div style="width: 100%; box-sizing: border-box;">
                <input type="number" id="new_ttn_quantity" placeholder="Количество продукции (шт)" style="width: 100%; padding: 10px 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; font-size: 13px; box-sizing: border-box;">
            </div>
            
            <!-- Ряд 3: Сумма отгрузки с красивым автоматическим бейджем валюты договора -->
        <div style="width: 100%; box-sizing: border-box; display: flex; flex-direction: column; gap: 4px; text-align: left;">
                <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">Сумма отгрузки и Валюта *</label>
                <div style="display: flex; gap: 10px; width: 100%; box-sizing: border-box;">
                    <!-- Поле ввода суммы накладной -->
                    <input type="number" 
                           id="new_ttn_amount" 
                           step="0.01" 
                           placeholder="0.00" 
                           style="flex: 2; padding: 10px 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; font-size: 13px; box-sizing: border-box; font-family: monospace; transition: border-color 0.15s;"
                           onfocus="this.style.borderColor='#4f46e5';" 
                           onblur="this.style.borderColor='#323248';">
                    
                    <!-- Селектор ручного выбора валюты конкретной ТТН -->
                    <select id="ttn_currency_select" 
                            style="flex: 1; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #10b981; border-radius: 8px; outline: none; font-size: 13px; font-weight: bold; cursor: pointer; height: 38px; box-sizing: border-box; transition: border-color 0.15s;"
                            onfocus="this.style.borderColor='#4f46e5';" 
                            onblur="this.style.borderColor='#323248';">
                        <option value="BYN">BYN</option>
                        <option value="RUB">RUB</option>
                        <option value="USD">USD</option>
                        <option value="EUR">EUR</option>
                        <option value="CNY">CNY</option>
                    </select>
                </div>
            </div>
            <!-- Ряд 4: Спецификация товара -->
            <div style="width: 100%; box-sizing: border-box;">
                <input type="text" id="new_ttn_prod" value="Сантехника" style="width: 100%; padding: 10px 12px; background: #151521; border: 1px solid #323248; color: #818cf8; border-radius: 8px; outline: none; font-size: 13px; font-weight: 600; box-sizing: border-box;">
            </div>

            <!-- Кнопка отправки формы на save_ttn.php -->
            <button type="button" id="ttnSubmitBtn" onclick="submitTtnFormPremium()" style="width: 100%; background: #10b981; color: white; border: none; padding: 11px; border-radius: 8px; font-weight: bold; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; cursor: pointer; transition: background 0.15s; box-sizing: border-box; margin-top: 4px;">
                Добавить в рамках контракта
            </button>
        </div>

        <!-- Подвальная кнопка закрытия -->
        <div style="display: flex; justify-content: flex-end; width: 100%; margin-top: -4px;">
            <button type="button" onclick="document.getElementById('ttnManagerModal').style.display='none';" style="background: #27273a; color: #a1a1aa; border: 1px solid #323248; padding: 8px 18px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; transition: background 0.15s; box-sizing: border-box;">Закрыть</button>
        </div>
     </div>
</div>



<script>

function calculateTtnBynLive() {
    const currencySelect = document.getElementById('ttn_currency_select');
    const amountInput = document.getElementById('new_ttn_amount');
    const previewBlock = document.getElementById('ttn_byn_preview_block');
    const previewText = document.getElementById('ttn_byn_preview_text');
    
    if (!currencySelect || !amountInput || !previewBlock || !previewText) return;

    const currency = currencySelect.value;
    const inputSum = parseFloat(amountInput.value) || 0;
    
    // Центральные зафиксированные курсы валют Santeks CRM
    const rates = {
        'BYN': 1.0,
        'USD': 3.25,
        'EUR': 3.55,
        'RUB': 0.035,
        'CNY': 0.45
    };
    
    const finalByn = (inputSum * (rates[currency] || 1.0)).toFixed(2);
    
    // Если менеджер выбирает иностранную валюту накладной, показываем подсказку пересчета в BYN
    if (currency !== 'BYN' && inputSum > 0) {
        previewBlock.style.display = 'block';
        previewText.innerText = finalByn + ' BYN';
    } else {
        previewBlock.style.display = 'none';
    }
}

// ХОТФИКС СБРОСА ФОРМЫ ТТН: Обязательно вставь этот сброс валюты в свою функцию closeTtnManager() или при открытии окна
function clearTtnCurrencyFormMemory() {
    const currencySelect = document.getElementById('ttn_currency_select');
    const previewBlock = document.getElementById('ttn_byn_preview_block');
    if (currencySelect) currencySelect.value = 'BYN';
    if (previewBlock) previewBlock.style.display = 'none';
}

async function executeContractUpload(pid, inputElement) {
    console.log("Старт движка. Отправляю договор для ID:", pid);
    
    if (!inputElement.files || !inputElement.files.length) {
        alert("Ошибка: Файл не выбран!");
        return;
    }

    const file = inputElement.files[0]; // Жестко изолируем первый бинарный файл

    // Собираем пакет FormData
    const fd = new FormData();
    fd.append('project_id', parseInt(pid));
    fd.append('contract_pdf', file);

    try {
        // ДИНАМИЧЕСКИЙ АВТОПОДБОР ТЕКУЩЕЙ ПАПКИ НА СЕРВЕРЕ
        // Вырезает из адреса "contracts.php" и подставляет имя нужного файла
        const currentPath = window.location.pathname;
        const targetUrl = currentPath.substring(0, currentPath.lastIndexOf('/')) + '/upload_scan.php';
        
        console.log("Автоматически вычисленный адрес отправки:", targetUrl);

        const response = await fetch(targetUrl, {
            method: 'POST',
            body: fd
        });

        // Считываем сырой текстовый ответ сервера
        const rawText = await response.text();
        console.log("Сырой ответ от сервера:", rawText);

        // Если сервер всё ещё отдает 404 Not Found
        if (rawText.includes('404 Not Found')) {
            alert("Критическая ошибка 404!\nБраузер обратился по адресу: " + targetUrl + "\nНо XAMPP говорит, что файла upload_scan.php там ФИЗИЧЕСКИ НЕТ.\n\nПроверь папку C:\\xampp\\htdocs\\... — лежит ли этот файл рядом с contracts.php?");
            return;
        }

        alert("Ответ сервера при загрузке договора:\n" + rawText);

        try {
            const result = JSON.parse(rawText);
            if (result.status === 'success') {
                window.location.reload();
            } else {
                alert("Ошибка сохранения: " + result.message);
            }
        } catch(e) {
            window.location.reload();
        }

    } catch (err) {
        console.error("Критический сбой сети:", err);
        alert("Критическая ошибка сети: " + err.message);
    }
}

// ИСПРАВЛЕНО НАМЕРТВО: Функция запрашивает данные у get_ttns.php и рисует список накладных
async function renderProjectTtnsList(pid) {
    console.log("Загрузка списка ТТН для договора ID:", pid);
    const container = document.getElementById('ttnListContainer');
    if (!container) return;

    container.innerHTML = '<div style="color:#64748b; font-size:12px; padding:10px; text-align:center;">Загрузка данных из СУБД...</div>';

    try {
        // Отправляем GET-запрос с явным указанием pid
        const res = await fetch('get_ttns.php?pid=' + parseInt(pid, 10));
        const data = await res.json();

        // Проверяем, не вернул ли сервер ошибку
        if (data.error) {
            container.innerHTML = '<div style="color:#f43f5e; font-size:12px; padding:10px;">Ошибка: ' + data.message + '</div>';
            return;
        }

        if (!data || data.length === 0) {
            container.innerHTML = '<div style="color:#4e4e6a; font-size:12px; padding:15px; text-align:center;">По этому договору отгрузок пока нет.</div>';
            return;
        }

        // Очищаем контейнер перед прорисовкой строк
        container.innerHTML = '';

        // Перебираем накладные и строим интерактивные строки карточек
        data.forEach(t => {
            const fileUrl = t.scan_path ? t.scan_path.trim() : '';
            let fileControls = '';

            // Логика управления файлом (PDF / Скрепка 📎)
            if (fileUrl !== '' && fileUrl !== 'NULL' && fileUrl !== '0') {
                fileControls += '<a href="' + fileUrl + '" target="_blank" style="color:#10b981; text-decoration:none; font-size:11px; font-weight:bold; background:#1a2e26; padding:4px 8px; border-radius:4px; margin-right:5px; display:inline-block;">👁 PDF</a>';
                fileControls += '<button type="button" onclick="removeTtnFile(' + t.id + ', ' + pid + ')" style="background:none; border:none; color:#f56565; cursor:pointer; font-size:12px; font-weight:bold; padding:4px;">❌</button>';
            } else {
                fileControls += '<label for="ttn_file_input_' + t.id + '" style="cursor:pointer; color:#4f46e5; font-size:13px; padding:4px 8px; background:#1e1e2d; border:1px solid #323248; border-radius:4px; display:inline-block;">📎</label>';
                fileControls += '<input type="file" id="ttn_file_input_' + t.id + '" accept=".pdf" style="display:none;" onchange="uploadTtnFile(' + t.id + ', ' + pid + ', this)">';
            }

            // Формируем саму строку накладной
            const itemHtml = `
                <div style="display:flex; justify-content:space-between; align-items:center; background:#1a1a24; padding:8px 12px; border-radius:6px; border:1px solid #2b2b40; font-size:13px;">
                    <div>
                        <strong style="color:#fff;">№ ${t.ttn_number}</strong> 
                        <span style="color:#64748b; font-size:11px; margin-left:8px;">${t.ttn_date}</span>
                        <span style="color:#10b981; font-weight:bold; margin-left:12px;">${parseFloat(t.amount).toFixed(2)} BYN</span>
                    </div>
                    <div>${fileControls}</div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', itemHtml);
        });

    } catch (err) {
        console.error("Сбой сети при получении ТТН:", err);
        container.innerHTML = '<div style="color:#f43f5e; font-size:12px; padding:10px; text-align:center;">Критический сбой подгрузки списка.</div>';
    }
}


// Глобальный маркер защиты от повторных сверхбыстрых кликов менеджеров
let isTtnSendingLock = false;

// ГЛОБАЛЬНЫЙ ПЕРЕХВАТЧИК КЛИКА: Открывает окно ТТН-менеджера по кнопке из таблицы
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.js-open-ttn-window-btn');
    if (btn) {
        e.preventDefault();
        
        const pid = btn.getAttribute('data-pid');
        const clientName = btn.getAttribute('data-name');
        
        console.log("Инициализация окна ТТН для договора ID:", pid);

        const modal = document.getElementById('ttnManagerModal');
        const pidStorage = document.getElementById('ttn_pid_storage');
        const labelEl = document.getElementById('ttnContractLabel');

        if (!modal || !pidStorage) return alert("Критическая ошибка: Элементы модалки ТТН отсутствуют на странице!");

        // Заполняем скрытые параметры
        pidStorage.value = pid;
        if (labelEl) labelEl.innerText = "Клиент: " + clientName;
        
        // Показываем окно и подгружаем список сохраненных накладных
        modal.style.display = 'flex';
        renderProjectTtnsList(pid);
    }
});

// 2. СВЕРХЗАЩИЩЕННОЕ АСИНХРОННОЕ СОХРАНЕНИЕ (ОТПРАВЛЯЕТ СТРОГО 1 ЗАПРОС НА КЛИК)
// ИСПРАВЛЕНО: Функция отправляет данные, сбрасывает форму и принудительно обновляет экран для пересчета сумм
// ИСПРАВЛЕНО: Безопасное считывание полей без падения JS из-за удаленного инпута количества
async function saveTtnRecord() {
    console.log("Старт отправки отгрузки ТТН...");

    // 1. Защита от повторного клика (Блокировка памяти)
    if (typeof isTtnSendingLock !== 'undefined' && isTtnSendingLock) {
        console.warn("Повторный клик заблокирован! Дождитесь ответа базы.");
        return;
    }

    // 2. Интеллектуальный сбор ID договора (Проверяем оперативную память, затем DOM-атрибут)
    let pid = window.currentTtnProjectId;
    if (!pid || isNaN(pid) || pid <= 0) {
        const labelElement = document.getElementById('ttnContractLabel');
        pid = labelElement ? parseInt(labelElement.getAttribute('data-pid'), 10) : 0;
    }
    
    console.log("Итоговый считанный для отправки project_id =", pid);

    if (isNaN(pid) || pid <= 0) {
        alert("Критическая ошибка: Системный ID договора потерян. Переоткройте окно ТТН.");
        return;
    }

    // 3. Извлечение базовых полей накладной с защитой от вылета скрипта
    const ttnId = document.getElementById('edit_ttn_id_storage') ? document.getElementById('edit_ttn_id_storage').value : '';
    const num = document.getElementById('new_ttn_num') ? document.getElementById('new_ttn_num').value.trim() : '';
    const date = document.getElementById('new_ttn_date') ? document.getElementById('new_ttn_date').value : '';
    const amt = document.getElementById('new_ttn_amount') ? document.getElementById('new_ttn_amount').value.trim() : '';
    
    const qtyInput = document.getElementById('new_ttn_quantity');
    const qty = qtyInput ? qtyInput.value.trim() : 0;

    const prodInput = document.getElementById('ttn_specification_fixed') || document.getElementById('new_ttn_prod');
    const prod = prodInput ? prodInput.value.trim() : 'Прочее';

    // ---- НАШ ВАЛЮТНЫЙ ПЕРЕХВАТЧИК ----
    const currencySelect = document.getElementById('ttn_currency_select');
    const currency = currencySelect ? currencySelect.value : 'BYN';
    // ----------------------------------

    if (!num || !amt) {
        alert("Заполните обязательные поля формы: Номер ТТН и Сумму отгрузки!");
        return;
    }

    try {
        // Активируем блокировку отправки и меняем текст на кнопке
        if (typeof isTtnSendingLock !== 'undefined') isTtnSendingLock = true;
        
        const btn = document.getElementById('ttnActionBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerText = "Запись в базу...";
        }

        // 4. Формируем классический POST-пакет параметров (Совместим с Apache/XAMPP)
        const params = new URLSearchParams();
        params.append('project_id', pid);
        params.append('ttn_id', ttnId);
        params.append('ttn_number', num);
        params.append('ttn_date', date);
        params.append('amount', parseFloat(amt));
        params.append('currency', currency); // Передаем валюту на бэкенд!
        params.append('product_quantity', parseInt(qty, 10) || 0);
        params.append('product_info', prod);

        const res = await fetch('save_ttn.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        });

        const result = await res.json();

        // Снимаем блокировку кнопки
        if (typeof isTtnSendingLock !== 'undefined') isTtnSendingLock = false;
        if (btn) {
            btn.disabled = false;
            btn.innerText = "Добавить в рамках контракта";
        }

        if (result.status === 'success') {
            console.log("ТТН успешно занесена в СУБД Santeks!");
            
            // Очищаем поля формы перед обновлением
            document.getElementById('edit_ttn_id_storage').value = '';
            document.getElementById('new_ttn_num').value = '';
            document.getElementById('new_ttn_amount').value = '';
            if (qtyInput) qtyInput.value = '';
            
            window.location.reload(); // Перезагрузка страницы для пересчета дашборда
        } else {
            alert("Ошибка базы данных Windows XAMPP:\n" + result.message);
        }
    } catch (err) {
        console.error("Критический сбой отправки ТТН:", err);
        if (typeof isTtnSendingLock !== 'undefined') isTtnSendingLock = false;
        
        const btn = document.getElementById('ttnActionBtn');
        if (btn) {
            btn.disabled = false;
            btn.innerText = "Добавить в рамках контракта";
        }
        alert("Ошибка соединения с сервером Apache. Проверьте логи консоли (F12).");
    }
}
// =========================================================================
// БРОНЕБОЙНЫЙ ДВИЖОК УПРАВЛЕНИЯ ТТН/CMR С ЗАЩИТОЙ ОТ ЗАНУЛЕНИЯ ДАННЫХ
// =========================================================================



// 2. Функция сохранения отгрузки ТТН в базу данных Windows XAMPP
// НАМЕРТВО ИСПРАВЛЕНО: Чистый рендерер ТТН без лишней математики и конвертеров
async function loadProjectTtnsPremium(pid) {
    console.log("=== ЗАПУСК ПРЯМОГО РЕНДЕРЕРА ТТН ===");
    const safePid = parseInt(pid, 10);
    
    // Ищем наш контейнер из разметки модалки
    const container = document.getElementById('projectTtnsListContainer');
    if (!container) {
        console.error("Критическая ошибка UI: Контейнер projectTtnsListContainer не найден!");
        return;
    }

    // Визуальный лоадер во время синхронизации
    container.innerHTML = '<span style="color:#818cf8; font-size:12px; padding:20px; display:block; text-align:center; font-style: italic;">⏳ Синхронизация с СУБД Santeks...</span>';

    try {
        // Делаем прямой запрос к нашему всеядному get_ttns.php
        const response = await fetch('get_ttns.php?project_id=' + safePid);
        if (!response.ok) {
            throw new Error("Сервер вернул статус: " + response.status);
        }

        const textData = await response.text();
        console.log("Ответ сервера по ТТН:", textData);

        let ttns = [];
        try {
            ttns = JSON.parse(textData);
        } catch (jsonErr) {
            container.innerHTML = '<span style="color:#ef4444; font-size:12px; padding:15px; display:block; text-align:center;">⚠️ Сбой структуры данных.</span>';
            return;
        }

        let html = '';
        if (Array.isArray(ttns) && ttns.length > 0) {
            ttns.forEach(t => {
                const safeNum = (t.ttn_number || '').replace(/'/g, "\\'");
                const safeDate = t.ttn_date ? t.ttn_date.split('-').reverse().join('.') : '—';
                const safeQty = parseInt(t.product_quantity || 0, 10);
                const safeProd = (t.product_info || 'Сантехника').replace(/'/g, "\\'");
                
                // Просто форматируем чистую сумму ТТН из базы данных
                const safeAmt = parseFloat(t.amount || 0).toLocaleString('ru-RU', { 
                    minimumFractionDigits: 2, 
                    maximumFractionDigits: 2 
                });
                
                // Подхватываем родную валюту ТТН
                const rawCurrency = (t.currency || 'BYN').toString().trim().toUpperCase();

                // Плоская палитра цветов для вывода валютных маркеров строк
                let curColor = '#10b981'; // BYN
                let currencyLabel = 'BYN';
                if (rawCurrency === 'RUB') { curColor = '#f59e0b'; currencyLabel = '₽'; }
                if (rawCurrency === 'USD') { curColor = '#6366f1'; currencyLabel = '$'; }
                if (rawCurrency === 'EUR') { curColor = '#ec4899'; currencyLabel = '€'; }
                if (rawCurrency === 'CNY') { curColor = '#a855f7'; currencyLabel = '¥'; }

                let fileControls = '';
                const fileUrl = t.scan_path ? t.scan_path.toString().trim() : '';

                if (fileUrl !== '' && fileUrl !== 'NULL' && fileUrl !== '0') {
                    fileControls += `<a href="${fileUrl}" target="_blank" style="color:#10b981; text-decoration:none; font-size:11px; font-weight:bold; background:#1a2e26; padding:4px 8px; border-radius:6px; margin-right:5px; border:1px solid rgba(16,185,129,0.25); display:inline-block;">👁 PDF</a>`;
                } else {
                    fileControls += `<label for="ttn_file_input_${t.id}" style="cursor:pointer; color:#818cf8; font-size:13px; padding:4px 10px; background:#161624; border: 1px solid #323248; border-radius:6px; display:inline-block;">📎</label>`;
                    fileControls += `<input type="file" id="ttn_file_input_${t.id}" accept=".pdf" style="display:none;" onchange="uploadTtnPdf(${t.id}, ${safePid}, this)">`;
                }

                // Выводим чистую красивую плоскую строчку отгрузки
                html += `
                <div style="background: #151521; padding: 10px 12px; border-radius: 8px; border: 1px solid #323248; display: flex; justify-content: space-between; align-items: center; width: 100%; box-sizing: border-box; margin-bottom: 4px;">
                    <div style="flex: 1; min-width: 0; padding-right: 10px; text-align:left;">
                        <div style="font-weight: bold; color: #fff; font-size: 13px;">ТТН № ${safeNum}</div>
                        <div style="color: #71717a; font-size: 11px; margin-top: 2px;">Дата: ${safeDate} | Кол-во: <strong style="color:#f59e0b;">${safeQty} шт.</strong></div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
                        <div style="font-weight: 700; color: ${curColor}; font-size: 13px; font-family: monospace;">${safeAmt} ${currencyLabel}</div>
                        <div style="display: flex; align-items: center; margin-left: 4px;">${fileControls}</div>
                    </div>
                </div>`;
            });
        } else {
            html += '<span style="color:#4b5563; font-size:12px; padding:20px; display:block; text-align:center; font-style: italic;">Отгрузок в рамках контракта пока нет</span>';
        }
        
        container.innerHTML = html;

    } catch (err) {
        console.error("Сбой рендерера ТТН:", err);
        container.innerHTML = '<span style="color:#ef4444; font-size:12px; padding:15px; display:block; text-align:center;">Критическая ошибка загрузки</span>';
    }
}

window.submitTtnFormPremium = async function() {
    const pid = document.getElementById('ttn_pid_storage').value;
    const ttnNum = document.getElementById('new_ttn_num').value;
    const ttnDate = document.getElementById('new_ttn_date').value;
    const ttnQty = document.getElementById('new_ttn_quantity').value;
    const ttnAmount = document.getElementById('new_ttn_amount').value;
    const ttnProd = document.getElementById('new_ttn_prod').value;

    if (!ttnNum || !ttnAmount) {
        alert("Заполните номер накладной и сумму отгрузки!");
        return;
    }

    const fd = new FormData();
    fd.append('project_id', pid);
    fd.append('ttn_number', ttnNum);
    fd.append('ttn_date', ttnDate);
    fd.append('product_quantity', ttnQty);
    fd.append('new_ttn_amount', ttnAmount);
    fd.append('product_info', ttnProd);
    // Валюту здесь больше не передаем — бэкенд сам возьмет её из карточки договора!

    try {
        const res = await fetch('save_ttn.php', { method: 'POST', body: fd });
        const result = await res.json();
        
        if (result.status === 'success') {
            document.getElementById('new_ttn_num').value = '';
            document.getElementById('new_ttn_quantity').value = '';
            document.getElementById('new_ttn_amount').value = '';
            window.loadProjectTtnsPremium(pid);
        } else {
            alert("Ошибка сохранения: " + result.message);
        }
    } catch (err) {
        alert("Ошибка сети при отправке накладной");
    }
};

// Хелпер для очистки пробелов в кодах валют
function trim(str) { return str.replace(/^\s+|\s+$/g, ''); }

// 3. ПЕРЕНОС ДАННЫХ В ПОЛЯ ПРИ КЛИКЕ НА КАРАНДАШ
function editTtn(id, num, date, amount, qty, prod, currency) {
    document.getElementById('edit_ttn_id_storage').value = id;
    document.getElementById('new_ttn_num').value = num;
    document.getElementById('new_ttn_date').value = date;
    document.getElementById('new_ttn_amount').value = amount;
    
    // Безопасное заполнение спецификации
    const prodInput = document.getElementById('new_ttn_prod') || document.getElementById('ttn_specification_fixed');
    if (prodInput) {
        prodInput.value = prod || 'Сантехника';
    }

    // ТАКЖЕ ЛОВИМ КОЛИЧЕСТВО (Чтобы оно не сбрасывалось при клике на карандаш)
    const qtyInput = document.getElementById('new_ttn_quantity');
    if (qtyInput) {
        qtyInput.value = qty || 0;
    }

    // ---- НАШ ВАЛЮТНЫЙ ПЕРЕКЛЮЧАТЕЛЬ ДЛЯ РЕДАКТИРОВАНИЯ ----
    const currencySelect = document.getElementById('ttn_currency_select');
    if (currencySelect) {
        // Принудительно ставим селект в валюту накладной из СУБД
        currencySelect.value = currency ? currency.toUpperCase() : 'BYN';
    }
    
    // Мгновенно пересчитываем превью эквивалента в BYN на лету
    if (typeof calculateTtnBynLive === 'function') {
        calculateTtnBynLive();
    }
    // --------------------------------------------------------

    if(document.getElementById('ttnFormTitle')) {
        document.getElementById('ttnFormTitle').innerText = 'Редактировать отгрузку №' + num + ':';
    }
    const btn = document.getElementById('ttnActionBtn');
    if (btn) {
        btn.innerText = 'Сохранить изменения';
        btn.style.background = '#f59e0b'; // Роскошный янтарный цвет для режима правки
    }
}


// 3. ПОДСТАНОВКА В ФОРМУ ПРИ РЕДАКТИРОВАНИИ (КАРАНДАШ)
function prepareTtnToEdit(id, num, date, amount, qty, prod) {
    document.getElementById('edit_ttn_id_storage').value = id;
    document.getElementById('new_ttn_num').value = num;
    document.getElementById('new_ttn_date').value = date;
    document.getElementById('new_ttn_amount').value = amount;
    document.getElementById('new_ttn_quantity').value = qty;
    document.getElementById('new_ttn_prod').value = prod;

    document.getElementById('ttnFormTitle').innerText = 'Редактировать отгрузку №' + num + ':';
    const btn = document.getElementById('ttnActionBtn');
    if (btn) {
        btn.innerText = 'Сохранить изменения';
        btn.style.background = '#f6ad55';
    }
}

// 4. УДАЛЕНИЕ ФАЙЛА PDF У НАКЛАДНОЙ
// ИСПРАВЛЕНО НАМЕРТВО: Функция удаления шлет понятный для Windows XAMPP POST-пакет параметров
async function removeTtnFile(ttnId, pid) {
    if (!confirm("⚠️ Вы уверены, что хотите БЕЗВОЗВРАТНО удалить прикрепленный PDF-файл накладной?")) return;
    
    console.log("Запуск удаления файла для ТТН ID:", ttnId, "Договор PID:", pid);

    try {
        // Упаковываем параметры в стандартный формат веб-форм
        const params = new URLSearchParams();
        params.append('ttn_id', parseInt(ttnId, 10));

        const res = await fetch('delete_ttn_pdf.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded' // Классический, надежный тип передачи
            },
            body: params.toString() // Передаем в виде строки ttn_id=...
        });
        
        const result = await res.json();
        
        if (result.status === 'success') {
            console.log("Скан накладной успешно удален с сервера и очищен в СУБД!");
            
            // ЖЕСТКИЙ ПЕРЕЗАПУСК: Обновляем экран, чтобы кнопка просмотра моментально превратилась обратно в скрепку 📎
            window.location.reload();
        } else {
            alert("Ошибка удаления файла: " + result.message);
        }
    } catch (err) {
        console.error("Сбой сети при удалении файла ТТН:", err);
        alert("Ошибка соединения с сервером Windows XAMPP.");
    }
}

// 5. ПОТОКОВАЯ ЗАГРУЗКА PDF (СКРЕПКА)
async function uploadTtnFile(ttnId, pid, inputElement) {
    if (!inputElement.files || !inputElement.files.length) return;
    
    console.log("Отправка файла для ТТН ID:", ttnId, "Договор PID:", pid);
    
    const file = inputElement.files[0]; // Берем сам бинарный файл PDF
    const fd = new FormData();
    fd.append('ttn_id', parseInt(ttnId, 10)); // Передаем ID накладной ТТН
    fd.append('ttn_pdf', file); // Кладём файл в пакет под правильным именем ключа

    try {
        // Шлём AJAX-запрос на наш готовый обработчик
        const res = await fetch('upload_ttn_pdf.php', {
            method: 'POST',
            body: fd
        });
        
        const result = await res.json();
        
        if (result.status === 'success') {
            console.log("Файл успешно сохранен на сервере Windows XAMPP!");
            // Жестко перезапускаем страницу, чтобы скрепка мгновенно сменилась на кнопку просмотра PDF!
            window.location.reload();
        } else {
            alert("Ошибка СУБД: " + result.message);
        }
    } catch (err) {
        console.error("Сбой сети при загрузке скана ТТН:", err);
        alert("Критическая ошибка отправки файла на бэкенд.");
    }
}
// 6. ЗАКРЫТИЕ ОКНА
function closeTtnManager() {
    const modal = document.getElementById('ttnManagerModal');
    if (modal) { 
        modal.style.display = 'none'; 
    }
}




    // Передаем массив курсов из PHP в JS
const exchangeRates = <?= json_encode($globalRates) ?>;
document.addEventListener('change', async function(e) {
    if (e.target.classList.contains('js-target-currency') || e.target.classList.contains('js-currency-select')) {
        const select = e.target;
        const pid = select.dataset.id;
        const chosenCurrency = select.value;

        const bynEl = document.querySelector(`.js-byn-base[data-id="${pid}"]`);
        const targetEl = document.querySelector(`.js-converted-value[data-id="${pid}"]`);
        
        if (!bynEl || !targetEl) return;

        // Чистим строку от пробелов, чтобы JS не выдавал NaN
        const baseByn = parseFloat(bynEl.innerText.replace(/\s/g, '').replace(',', '.')) || 0;

        // Вызываем нашу точную функцию математики
        const finalConverted = getConvertedValue(baseByn, chosenCurrency);

        // Выводим результат в зеленую ячейку
        targetEl.innerText = finalConverted.toLocaleString('ru-RU', {
            minimumFractionDigits: 2, 
            maximumFractionDigits: 2
        });

        // Отправляем сохранение валюты в БД
        try {
            await fetch('update_contract_cell.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ id: pid, field: 'currency', value: chosenCurrency })
            });
        } catch (err) {
            console.error("Ошибка сохранения валюты:", err);
        }
    }
});
document.addEventListener('click', function(e) {
    const ttnBtn = e.target.closest('.js-btn-open-ttn-manager');
    if (ttnBtn) {
        e.preventDefault();
        
        const pid = ttnBtn.getAttribute('data-project-id');
        const clientName = ttnBtn.getAttribute('data-client-name');
        
        console.log("Открываю ТТН-менеджер для проекта ID:", pid);

        const modal = document.getElementById('ttnManagerModal');
        const storage = document.getElementById('ttn_pid_storage');
        const labelEl = document.getElementById('ttnContractLabel');

        if (!modal || !storage) {
            alert("Критическая ошибка интерфейса: Модальное окно ТТН не найдено в разметке HTML!");
            return;
        }

        // Записываем данные в заголовки и хранилище окна
        storage.value = pid;
        if (labelEl) labelEl.innerText = "Клиент: " + clientName;
        
        // Отображаем окно на экране
        modal.style.display = 'flex';
        
        // Запускаем асинхронную подгрузку списка ТТН
        loadProjectTtnsPremium(pid);
    }
});
// ИСПРАВЛЕНО: Функция открытия модалки принудительно прописывает ID договора и базовый товар
// ИСПРАВЛЕНО НАМЕРТВО: Глобальная фиксация ID договора для защиты от зануления в СУБД


async function executeSingleTtnSave() {
    console.log("Запущен уникальный движок сох  ранения ТТН...");
    

let isTtnSendingNow = false;

async function executeSingleTtnSave() {
    console.log("Попытка запуска движка сохранения ТТН...");
    
    // Если запрос уже летит прямо сейчас — намертво блокируем повторный запуск!
    if (isTtnSendingNow) {
        console.warn("Запрос уже отправляется на сервер! Повторный клик заблокирован.");
        return;
    }

    let pid = document.getElementById('ttn_pid_storage').value;
    if (!pid || parseInt(pid, 10) <= 0) {
        pid = window.currentTtnProjectId; // Берем резервную копию
    }
    const ttnId = document.getElementById('edit_ttn_id_storage') ? document.getElementById('edit_ttn_id_storage').value : '';
    const num = document.getElementById('new_ttn_num').value.trim();
    const date = document.getElementById('new_ttn_date').value;
    const amt = document.getElementById('new_ttn_amount').value.trim();
    const qty = document.getElementById('new_ttn_quantity') ? document.getElementById('new_ttn_quantity').value.trim() : '0';
    const prod = document.getElementById('new_ttn_prod').value.trim();

    if (!num || !amt) {
        alert("Пожалуйста, заполните обязательные поля: Номер ТТН и Сумму!");
        return;
    }

    try {
        // ВКЛЮЧАЕМ ЛОКЕР (Запрос пошел)
        isTtnSendingNow = true;
        
        const btn = document.getElementById('ttnActionBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerText = "Сохранение...";
        }

        const res = await fetch('save_ttn.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ ttn_id: ttnId, project_id: pid, ttn_number: num, ttn_date: date, amount: amt, product_quantity: qty, product_info: prod })
        });
        
        const result = await res.json();
        
        // ВЫКЛЮЧАЕМ ЛОКЕР после получения ответа от сервера
        isTtnSendingNow = false;
        if (btn) btn.disabled = false;

        if (result.status === 'success') {
            document.getElementById('edit_ttn_id_storage').value = '';
            document.getElementById('new_ttn_num').value = '';
            document.getElementById('new_ttn_amount').value = '';
            if (document.getElementById('new_ttn_quantity')) document.getElementById('new_ttn_quantity').value = '';
            document.getElementById('new_ttn_prod').value = '';
            
            document.getElementById('ttnFormTitle').innerText = 'Добавить новую отгрузку в рамках контракта:';
            if (btn) {
                btn.innerText = 'Добавить в рамках контракта';
                btn.style.background = '#10b981';
            }
            loadProjectTtnsPremium(pid);
        } else {
            alert("Ошибка базы данных: " + result.message);
        }
    } catch (err) {
        console.error("Сбой сети:", err);
        isTtnSendingNow = false; // Сбрасываем локер при ошибке сети
        const btn = document.getElementById('ttnActionBtn');
        if (btn) btn.disabled = false;
    }
}




    const pid = document.getElementById('ttn_pid_storage').value;
    const ttnId = document.getElementById('edit_ttn_id_storage') ? document.getElementById('edit_ttn_id_storage').value : '';
    const num = document.getElementById('new_ttn_num').value.trim();
    const date = document.getElementById('new_ttn_date').value;
    const amt = document.getElementById('new_ttn_amount').value.trim();
    const qty = document.getElementById('new_ttn_quantity') ? document.getElementById('new_ttn_quantity').value.trim() : '0';
    const prod = document.getElementById('new_ttn_prod').value.trim();

    if (!num || !amt) {
        alert("Пожалуйста, заполните обязательные поля: Номер ТТН и Сумму!");
        return;
    }

    try {
        const btn = document.getElementById('ttnActionBtn');
        if (btn) btn.disabled = true;

        const res = await fetch('save_ttn.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ 
                ttn_id: ttnId, 
                project_id: pid, 
                ttn_number: num, 
                ttn_date: date, 
                amount: amt, 
                product_quantity: qty, 
                product_info: prod 
            })
        });
        
        const result = await res.json();
        if (btn) btn.disabled = false;

        if (result.status === 'success') {
            // Очистка формы
            document.getElementById('edit_ttn_id_storage').value = '';
            document.getElementById('new_ttn_num').value = '';
            document.getElementById('new_ttn_amount').value = '';
            if (document.getElementById('new_ttn_quantity')) document.getElementById('new_ttn_quantity').value = '';
            document.getElementById('new_ttn_prod').value = '';
            
            document.getElementById('ttnFormTitle').innerText = 'Добавить новую отгрузку в рамках контракта:';
            if (btn) {
                btn.innerText = 'Добавить в рамках контракта';
                btn.style.background = '#10b981';
            }
            
            // Перерисовываем список ТТН на лету
            loadProjectTtnsPremium(pid);
        } else {
            alert("Ошибка базы данных: " + result.message);
        }
    } catch (err) {
        console.error("Сбой сети при отправке ТТН:", err);
        const btn = document.getElementById('ttnActionBtn');
        if (btn) btn.disabled = false;
    }
}
function closeTtnManager() {
    const modal = document.getElementById('ttnManagerModal');
    if (modal) {
        modal.style.display = 'none';
    }
    location.reload(); // Обновляем страницу, чтобы пересчитать суммы ТТН в таблице
}   

// 1. ПОДГРУЗКА СПИСКА ТТН (Крестик удаления файла теперь виден ВСЕМ)
// ИСПРАВЛЕНО НАМЕРТВО: Исправлен баг кавычек в блоке fileControls, список гарантированно отрендерится!
// НАМЕРТВО ИСПРАВЛЕНО: Привязка к глобальному объекту window гарантирует выполнение кода!

// 4. БЕЗВОЗВРАТНОЕ УДАЛЕНИЕ PDF С СЕРВЕРА
async function deleteTtnPdf(ttnId, pid) {
    if (!confirm("Вы уверены, что хотите БЕЗВОЗВРАТНО удалить PDF-файл?")) return;
    try {
        const res = await fetch('delete_ttn_pdf.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ttn_id: parseInt(ttnId) })
        });
        const result = await res.json();
        if (result.status === 'success') loadProjectTtnsPremium(pid);
        else alert(result.message);
    } catch (err) { alert("Ошибка связи с сервером"); }
}

// 5. ПОТОКОВАЯ ЗАГРУЗКА PDF
async function uploadTtnPdf(ttnId, pid, input) {
    if (!input.files || !input.files.length) return;
    const file = input.files[0];
    const fd = new FormData();
    fd.append('ttn_id', ttnId);
  fd.append('ttn_pdf', inputElement.files[0]); 

    try {
        const res = await fetch('upload_ttn_pdf.php', { method: 'POST', body: fd });
        if ((await res.json()).status === 'success') loadProjectTtnsPremium(pid);
    } catch (err) { alert("Ошибка загрузки файла"); }
}


document.addEventListener('click', function(e) {
    const editBtn = e.target.closest('.js-ttn-edit-btn');
    
    if (editBtn) {
        e.preventDefault();
        
        // 1. Безопасно вытаскиваем данные ТТН из атрибутов кнопки
        const id = editBtn.getAttribute('data-ttn-id') || '';
        const num = editBtn.getAttribute('data-ttn-num') || '';
        const date = editBtn.getAttribute('data-ttn-date') || '';
        const amount = editBtn.getAttribute('data-ttn-amount') || '';
        const prod = editBtn.getAttribute('data-ttn-prod') || '';

        console.log("Клик по карандашу ТТН. ID:", id, "Номер:", num, "Сумма:", amount);

        // 2. ЗАЩИТА: Проверяем наличие инпутов в HTML перед записью, чтобы не ломать скрипт
        const inputId = document.getElementById('edit_ttn_id_storage');
        const inputNum = document.getElementById('new_ttn_num');
        const inputDate = document.getElementById('new_ttn_date');
        const inputAmount = document.getElementById('new_ttn_amount');
        const inputProd = document.getElementById('new_ttn_prod');

        // Набиваем только те поля, которые реально существуют в разметке
        if (inputId) inputId.value = id;
        if (inputNum) inputNum.value = num;
        if (inputDate) inputDate.value = date;
        if (inputAmount) inputAmount.value = amount;
        if (inputProd) inputProd.value = prod;

        // 3. Визуально переключаем форму в режим редактирования
        const titleEl = document.getElementById('ttnFormTitle');
        if (titleEl) {
            titleEl.innerText = 'Редактировать отгрузку №' + num + ':';
        }
        
        const actionBtn = document.getElementById('ttnActionBtn');
        if (actionBtn) {
            actionBtn.innerText = 'Сохранить изменения';
            actionBtn.style.background = '#f6ad55'; // Меняем цвет кнопки на оранжевый
        }
        
        console.log("Данные успешно подставлены в форму редактирования.");
    }
});

 document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const autoOpenId = urlParams.get('auto_open_client_id');
    
    if (autoOpenId) {
        // Ищем в таблице строку с этим cid, чтобы взять название и pid черновика
        // Нам нужно открыть модалку сразу поверх прогрузившегося черновика
        const row = document.querySelector(`tr`); // Скрипт ниже найдет точнее через data-id
        openAddContractModal(autoOpenId, "Оформление нового договора");
    }
});



// 2. ЗАКРЫТИЕ МОДАЛКИ
function closeContractModal() {
    const modal = document.getElementById('contractModal') || document.getElementById('newContractModal');
    if (modal) {
        modal.style.display = 'none';
    }

    // ИСПРАВЛЕНО: Если закрытие произошло по кнопке Отмена, принудительно гасим галочку в таблице!
    if (window.activeContractCheckbox) {
        window.activeContractCheckbox.checked = false; // Галочка принудительно снимается
        window.activeContractCheckbox = null;         // Очищаем оперативную память
        console.log("Менеджер нажал 'Отмена'. Галочка контракта успешно погашена без записи в СУБД.");
    }
}

function exportToExcel() {
    // 1. Берем таблицу
    const table = document.querySelector("table");
    
    // 2. Генерируем книгу (Raw: true сохранит числа как числа, а не текст)
    const wb = XLSX.utils.table_to_book(table, { sheet: "Отчет Santeks", raw: true });
    
    // 3. Сохраняем с датой в названии
    const date = new Date().toISOString().slice(0,10);
    XLSX.writeFile(wb, `Santeks_CRM_Report_${date}.xlsx`);
}
// 3. СОХРАНЕНИЕ ФОРМЫ ДОГОВОРА
document.getElementById('contractForm').onsubmit = async function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    
    // Передаем ID проекта, который нужно обновить вместо создания нового
    const modal = document.getElementById('contractModal');
    if (modal.dataset.pid) {
        fd.append('project_id', modal.dataset.pid);
    }

    const res = await fetch('save_project.php', { method: 'POST', body: fd });
    if ((await res.json()).status === 'success') {
        window.location.href = 'contracts.php'; // Перезагружаем страницу без параметров
    }
};


   
function checkReminders() {
    console.log("Проверка напоминаний запущена");
}

// Запуск при загрузке
document.addEventListener("DOMContentLoaded", () => {
    // 1. Считываем параметры из URL-строки браузера
    const urlParams = new URLSearchParams(window.location.search);
    const autoClientId = urlParams.get('open_modal_for');

    if (autoClientId) {
        console.log("Пойман сквозной сигнал с главной! Автоматически раскрываем форму для клиента ID:", autoClientId);
        
        // Переиспользуем нашу родную, проверенную функцию открытия модалки, которую мы полировали на Шаге 79!
        if (typeof openNewContractModal === 'function') {
            openNewContractModal(autoClientId);
        } else {
            // Если функция называется иначе, дублируем её логику точечно:
            const modal = document.getElementById('contractModal') || document.getElementById('newContractModal');
            const clientIdInput = document.getElementById('contract_client_id_storage') || document.getElementById('modal_client_id');
            if (clientIdInput) {
                clientIdInput.value = parseInt(autoClientId, 10);
            }
            if (modal) modal.style.display = 'block';
        }
        
        // Чистим URL-строку браузера от маркера, чтобы при обычном обновлении страницы (F5) форма не вылетала повторно
        const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({ path: cleanUrl }, '', cleanUrl);
    }
});


        function processReminders() {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    let criticalCount = 0;
    const rows = document.querySelectorAll('#tableBody tr');

    rows.forEach(row => {
        const dateInput = row.querySelector('[data-f="next_contact_date"]');
            (!dateInput || !dateInput.value);

        const nextDate = new Date(dateInput.value);
        nextDate.setHours(0, 0, 0, 0);

        const diffDays = Math.ceil((nextDate - today) / (1000 * 60 * 60 * 24));

        // Очищаем старые классы
        row.classList.remove('row-danger', 'row-warning');

        if (diffDays <= 0) {
            row.classList.add('row-danger'); // Просрочено или сегодня
            criticalCount++;
        } else if (diffDays <= 3) {
            row.classList.add('row-warning'); // Осталось 1-3 дня
        }
    });

    if (criticalCount > 0) {
        showToast(`Внимание! У вас ${criticalCount} горящих задач на сегодня.`);
    }
}

function showToast(message) {
    let toast = document.getElementById('notification-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'notification-toast';
        document.body.appendChild(toast);
    }
    toast.innerText = message;
    toast.style.display = 'block';
    
    // Скрыть через 10 секунд
    setTimeout(() => { toast.style.display = 'none'; }, 10000);
}

async function saveData(id, field, value) {
    try {
        const response = await fetch('update_cell.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ id, field, value })
        });
        const result = await response.json();
        
        if (result.status === 'success') {
            console.log(`Поле ${field} успешно сохранено`);
            // Если изменили сумму — пересчитываем итоги на экране
            if (field === 'amount') recalculateEverything(); 
        } else {
            alert("Ошибка сохранения: " + result.message);
        }
    } catch (e) {
        console.error("Ошибка связи с сервером");
    }
}

const nbrbCurrentRates = <?= json_encode($globalRates ?? [
    'BYN' => 1.0, 'USD' => 3.26, 'EUR' => 3.54, 'RUB' => 0.0352, 'CNY' => 0.45
]) ?>;

console.log("Курсы успешно загружены в JS:", nbrbCurrentRates);



// Запускаем при загрузке и при каждом изменении
window.addEventListener('load', updateTableTotals);
document.addEventListener('input', (e) => {
    if (e.target.classList.contains('amount-byn')) updateTableTotals();
});

// Автоматически запускаем подсчет сразу после полной загрузки страницы браузером
document.addEventListener('DOMContentLoaded', function() {
    updateContractsGrandTotal();
});

// АВТОЗАПУСК: Нам нужно, чтобы функция сама запускалась ОДИН раз при полной загрузке страницы
document.addEventListener("DOMContentLoaded", function() {
    doTheMath();
});


// Запуск при любых изменениях и при загрузке
document.addEventListener('input', (e) => {
    if (e.target.classList.contains('amount-byn')) doTheMath();
});

// Запускаем через небольшую паузу, чтобы всё прогрузилось
window.onload = () => setTimeout(doTheMath, 300);

// 1. Слушаем ввод (на лету)
document.addEventListener('input', (e) => {
    if (e.target.classList.contains('amount-byn')) doTheMath();
});

// 2. Запускаем при загрузке
window.addEventListener('DOMContentLoaded', doTheMath);

// 3. ПРИНУДИТЕЛЬНЫЙ ПЕРЕЗАПУСК (та самая кувалда)
setTimeout(doTheMath, 500); 
setTimeout(doTheMath, 2000);


// Запуск принудительно через секунду после загрузки (чтобы PHP успел всё отдать)
setTimeout(updateTableTotals, 1000);

// И при каждом вводе
document.addEventListener('input', (e) => {
    if (e.target.classList.contains('amount-byn')) updateTableTotals();
});

// Запускаем пересчет при загрузке и при каждом изменении в ячейках
window.addEventListener('load', updateTableTotals);
document.addEventListener('input', (e) => {
    if (e.target.classList.contains('amount-byn')) updateTableTotals();
});



// 3. Обработчик потери фокуса
document.addEventListener('blur', (e) => {
    if (e.target.classList.contains('editable')) {
        const row = e.target.closest('tr');
        const id = row.dataset.id; // Это наш cid (client_id)
        const field = e.target.dataset.f;
        const value = e.target.innerText.trim();
        
        saveData(id, field, value);
    }
}, true);   

// Запускаем при загрузке и после каждого изменения даты
window.addEventListener('DOMContentLoaded', processReminders);


document.addEventListener('blur', (e) => {
    if (e.target.dataset.f === 'amount') {
        recalculateTotal();
    }
}, true);



// Главная функция пересчета
function recalculateEverything() {
    let totalByn = 0;
    
    // Перебираем все строки таблицы
    const rows = document.querySelectorAll('#tableBody tr');
    
    rows.forEach(row => {
        // Находим ячейку, где вводим BYN (по атрибуту data-f)
        const bynCell = row.querySelector('[data-f="amount"]');
        // Находим ячейку, где выводим RUB (по классу)
        const rubCell = row.querySelector('.rub-cell');
        
        if (bynCell && rubCell) {
            // Очищаем текст от мусора и превращаем в число
            let valByn = parseFloat(bynCell.innerText.replace(/[^\d.,]/g, '').replace(',', '.')) || 0;
            totalByn += valByn;
            
            // Считаем и выводим RUB в строке
            let valRub = valByn / BYN_TO_RUB_RATE;
            rubCell.innerText = valRub.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) + ' RUB';
        }
    });

    // Обновляем ИТОГИ внизу (проверь, чтобы ID в tfoot совпадали!)
    const totalBynDisplay = document.getElementById('totalAmountBYN');
    const totalRubDisplay = document.getElementById('totalAmountRUB');
    
    if (totalBynDisplay) totalBynDisplay.innerText = totalByn.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) + ' BYN';
    if (totalRubDisplay) totalRubDisplay.innerText = (totalByn / BYN_TO_RUB_RATE).toLocaleString('ru-RU', { minimumFractionDigits: 2 }) + ' RUB';
}

// Сохранение и запуск пересчета
async function saveData(id, field, value) {
    await fetch('update_cell.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id, field, value })
    });
    
    if (field === 'amount') {
        recalculateEverything();
    }
}

// Следим за выходом из ячейки
document.addEventListener('blur', (e) => {
    if (e.target.classList.contains('editable')) {
        const id = e.target.closest('tr').dataset.id;
        const field = e.target.dataset.f;
        saveData(id, field, e.target.innerText);
    }
}, true);


function doCalculate() {
    let totalByn = 0;
    const rows = document.querySelectorAll('#tableBody tr');
    console.log("Найдено строк для расчета:", rows.length);

    rows.forEach((row, index) => {
        // Ищем ячейку суммы BYN внутри этой строки
        const bynDiv = row.querySelector('[data-f="amount"]');
        const rubCell = row.querySelector('.rub-value');

        if (bynDiv && rubCell) {
            let val = parseFloat(bynDiv.innerText.replace(',', '.')) || 0;
            totalByn += val;
            
            // Считаем RUB
            let rub = val / RATE;
            rubCell.innerText = rub.toLocaleString('ru-RU', {minimumFractionDigits: 2}) + " RUB";
        }
    });

    //Отсутствие дублирующих элементов 
    document.getElementById()

    // Обновляем футер
    document.getElementById('totalBYN').innerText = totalByn.toLocaleString('ru-RU', {minimumFractionDigits: 2}) + " BYN";
    document.getElementById('totalRUB').innerText = (totalByn / RATE).toLocaleString('ru-RU', {minimumFractionDigits: 2}) + " RUB";
}

document.addEventListener('click', function(e) {
    const editBtn = e.target.closest('.js-ttn-edit-btn');
    
    if (editBtn) {
        e.preventDefault();
        
        // Вытаскиваем данные ТТН из атрибутов кнопки
        const id = editBtn.dataset.ttnId;
        const num = editBtn.dataset.ttnNum;
        const date = editBtn.dataset.ttnDate;
        const amount = editBtn.dataset.ttnAmount;
        const prod = editBtn.dataset.ttnProd;

        console.log("Редактирую ТТН ID:", id, "Номер:", num);

        // Набиваем поля формы ввода данными из ТТН
        document.getElementById('edit_ttn_id_storage').value = id;
        document.getElementById('new_ttn_num').value = num;
        document.getElementById('new_ttn_date').value = date;
        document.getElementById('new_ttn_amount').value = amount;
        document.getElementById('new_ttn_prod').value = prod;

        // Визуально переключаем форму в режим редактирования
        const titleEl = document.getElementById('ttnFormTitle');
        if (titleEl) titleEl.innerText = 'Редактировать отгрузку №' + num + ':';
        
        const actionBtn = document.getElementById('ttnActionBtn');
        if (actionBtn) {
            actionBtn.innerText = 'Сохранить изменения';
            actionBtn.style.background = '#f6ad55'; // Меняем цвет кнопки на оранжевый
        }
    }
});
// Слушаем изменения
document.addEventListener('input', (e) => {
    if (e.target.dataset.f === 'amount') {
        doCalculate();
    }
});

            
// Сохранение (blur)
document.addEventListener('blur', async (e) => {
    if (e.target.classList.contains('editable')) {
        const id = e.target.closest('tr').dataset.id;
        const field = e.target.dataset.f;
        const value = e.target.innerText;
        
        await fetch('update_cell.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ id, field, value })
        });
    }
}, true);

// Запуск при старте
window.onload = doCalculate;
// Пересчитываем один раз при загрузке
window.onload = recalculateEverything;
    


document.addEventListener('blur', async function(e) {
    // Проверяем, что кликнули вне ячейки с суммой
    if (e.target.classList.contains('amount-byn')) {
        const id = e.target.dataset.id;
        const rawValue = e.target.innerText.replace(/\s/g, '').replace(',', '.');
        const finalValue = parseFloat(rawValue) || 0;

        console.log("Сохраняю в базу:", finalValue, "для ID:", id);

        try {
            const res = await fetch('update_contract_cell.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ 
                    id: id, 
                    field: 'amount', 
                    value: finalValue 
                })
            });
            const result = await res.json();
            if (result.status !== 'success') {
                console.error("Ошибка сохранения в БД:", result.message);
            }
        } catch (err) {
            console.error("Ошибка сети при сохранении:", err);
        }
    }
      }, true); // true нужен для корректного отлова события blur
   
function deleteContract(pid) {
    if (confirm("Вы уверены, что хотите БЕЗВОЗВРАТНО удалить этот договор и все связанные с ним ТТН?")) {
        fetch('delete_contract.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: pid })
        })
        .then(res => res.json())
        .then(result => {
            if (result.status === 'success') {
                location.reload();
            } else {
                alert("Ошибка при удалении: " + result.message);
            }
        });
    }
}


// АСИНХРОННОЕ УДАЛЕНИЕ PDF ФАЙЛА ТТН (СТРОГО ДЛЯ АДМИНА)
// АВТОНОМНЫЙ ПЕРЕХВАТ КЛИКА ПО КРЕСТИКУ (SPAN) БЕЗ ПЕРЕЗАГРУЗКИ СТРАНИЦЫ
document.addEventListener('click', async function(e) {
    // Ищем клик именно по нашему классу крестика
    const deleteBtn = e.target.closest('.js-pdf-delete-btn');
    if (deleteBtn) {
        // КРИТИЧНО: Останавливаем любое стандартное поведение и всплытие события в HTML
        e.preventDefault();
        e.stopPropagation();

        const ttnId = deleteBtn.getAttribute('data-ttn-id');
        const pid = deleteBtn.getAttribute('data-project-id');

        console.log("Глобальный перехват крестика. Удаляю PDF для ТТН ID:", ttnId, "Проект:", pid);

        if (!confirm("Вы уверены, что хотите БЕЗВОЗВРАТНО удалить прикрепленный PDF-файл этой ТТН?")) {
            return;
        }

        try {
            const res = await fetch('delete_ttn_pdf.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ttn_id: parseInt(ttnId) })
            });
            
            const result = await res.json();
            console.log("Результат удаления на сервере:", result);

            if (result.status === 'success') {
                // Обновляем список ТТН на лету прямо внутри открытого окна
                loadProjectTtnsPremium(pid);
            } else {
                alert("Ошибка удаления файла: " + result.message);
            }
        } catch (err) {
            console.error("Ошибка сети при удалении PDF:", err);
            alert("Ошибка связи с сервером delete_ttn_pdf.php");
        }
    }
});

    </script>

</html>