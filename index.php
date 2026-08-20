<?php
// =========================================================================
// ЧИСТЫЙ МОНОЛИТНЫЙ WINDOWS-БЛОК CRM SANTEKS (БЕЗ ДУБЛИРОВАНИЯ И ВАРНИНГОВ)
// =========================================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// 1. ПРОВЕРКА АВТОРИЗАЦИИ И СИНХРОНИЗАЦИЯ ИМЕН ПЕРЕМЕННЫХ
if (!isset($_SESSION['user_id'])) {
    header("Location: auth.html");
    exit;
}
// Задаем единые сквозные переменные, которые используются и в логике, и в верстке
$userId    = (int)$_SESSION['user_id'];
$userRole  = $_SESSION['role'] ?? 'manager';
$u_role    = $userRole; // Дублируем для совместимости с любыми плашками
$u_id      = $userId;   // Дублируем для старой верстки
// 2. ФИЛЬТРАЦИЯ ПО МЕНЕДЖЕРУ ДЛЯ АДМИНИСТРАТОРА
$filterManagerId = isset($_GET['manager_id']) ? (int)$_GET['manager_id'] : 0;
$filterSource = isset($_GET['source']) ? trim($_GET['source']) : '';
// 3. ЖЕСТКИЙ ПЕРЕХВАТ ТЕКУЩЕЙ ВКЛАДКИ
$current_tab = isset($_GET['tab']) ? strtolower(trim($_GET['tab'])) : 'active';
$tab = $current_tab; // Синхронизируем, чтобы HTML-ссылки понимали активный статус
// Единая логика сортировки: просроченные контакты летят наверх, отказники всегда вниз
$orderByLogic = "ORDER BY first_contact_date DESC";
// 4. СБОР СТАТИСТИКИ ДЛЯ ПЛАШЕК ДАШБОРДА (БЕЗ ВАРНИНГОВ)
$stats = ['total' => 0, 'in_work' => 0, 'refusals' => 0, 'signed' => 0];
try {
    if ($userRole === 'admin') {
        $sql_stats = "SELECT 
            COUNT(*) as total,
           
            SUM(CASE WHEN status = 'Отказ' THEN 1 ELSE 0 END) as refusals,
            SUM(CASE WHEN is_contract_signed = 1 THEN 1 ELSE 0 END) as signed
        FROM clients";
        $stats = $pdo->query($sql_stats)->fetch() ?: $stats;
    } else {
        $sql_stats = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'Текущий' THEN 1 ELSE 0 END) as in_work,
            SUM(CASE WHEN status = 'Отказ' THEN 1 ELSE 0 END) as refusals,
            SUM(CASE WHEN is_contract_signed = 1 THEN 1 ELSE 0 END) as signed
        FROM clients WHERE manager_id = ?";
        $stmt_stats = $pdo->prepare($sql_stats);
        $stmt_stats->execute([$userId]);
        $stats = $stmt_stats->fetch() ?: $stats;
    }
} catch (Exception $e) { }
// Подстраховка массива, чтобы верстка на строке 291 никогда не падала
if (!$stats) {
    $stats = ['total' => 0, 'in_work' => 0, 'refusals' => 0, 'signed' => 0];
}
// 5. ПОДСЧЕТ ВЫРУЧКИ ИЗ НАКЛАДНЫХ ТТН
$managerTotalSales = 0.00;
try {
    if ($userRole === 'admin') {
        $managerTotalSales = (float)($pdo->query("SELECT SUM(amount) FROM project_ttns")->fetchColumn() ?: 0.00);
    } else {
        $sumStmt = $pdo->prepare("SELECT SUM(t.amount) 
                                  FROM project_ttns t
                                  INNER JOIN projects p ON t.project_id = p.id
                                  INNER JOIN clients c ON p.client_id = c.id
                                  WHERE c.manager_id = ?");
        $sumStmt->execute([$userId]);
        $managerTotalSales = (float)($sumStmt->fetchColumn() ?: 0.00);
    }
} catch (Exception $e) { }
$clients = [];
try {
    // Базовые условия для Админа и Менеджера
    if ($userRole === 'admin') {
        if ($filterManagerId > 0) {
            if ($current_tab === 'refused') {
                $sql = "SELECT * FROM clients WHERE manager_id = ? AND status = 'Отказ'";
            } else {
                $sql = "SELECT * FROM clients WHERE manager_id = ? AND status != 'Отказ' ";
            }
            $params = [$filterManagerId];
        } else {
            if ($current_tab === 'refused') {
                $sql = "SELECT * FROM clients WHERE status = 'Отказ'";
            } else {
                $sql = "SELECT * FROM clients WHERE status != 'Отказ'";
            }
            $params = [];
        }
    } else {
        if ($current_tab === 'refused') {
            $sql = "SELECT * FROM clients WHERE manager_id = ? AND status = 'Отказ'";
        } else {
            $sql = "SELECT * FROM clients WHERE manager_id = ? AND status != 'Отказ' ";
        }
        $params = [$userId];
    }
    // ТОЧЕЧНАЯ НАДСТРОЙКА: Если источник выбран, динамически дописываем фильтр в SQL
    if (!empty($filterSource)) {
        $sql .= " AND source = ?";
        $params[] = $filterSource;
    }
    // Приклеиваем нашу эталонную сортировку просрочек
    $sql .= " " . $orderByLogic;
    // Готовим и выполняем безопасный запрос
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $clients = $stmt->fetchAll() ?: [];
} catch (Exception $e) {
    $clients = [];
}
// =========================================================================
// ЕДИНЫЙ МОДУЛЬ МНОГОФАКТОРНОЙ ФИЛЬТРАЦИИ С НУЛЯ
// =========================================================================
// 1. Принимаем параметры фильтров из адресной строки браузера
$sourceFilter  = isset($_GET['source']) ? trim($_GET['source']) : '';
$statusFilter  = isset($_GET['status']) ? trim($_GET['status']) : '';
$productFilter = isset($_GET['ct_type']) ? trim($_GET['ct_type']) : '';
$dateFilter    = isset($_GET['next_contact_date_filter']) ? trim($_GET['next_contact_date_filter']) : '';
// 2. Очищаем дефолтные текстовые заглушки, чтобы они не летели в базу данных
if ($sourceFilter === 'Все источники') $sourceFilter = '';
if ($statusFilter === 'Все статусы')   $statusFilter = '';
if ($productFilter === 'Все виды')     $productFilter = '';
if ($userRole === 'admin') {
     if ($current_tab === 'refused') {
        $sql = "SELECT c.*, 
                       c.next_contact_date AS client_next_contact_date, 
                       -- Если в проекте пусто, берем множественную строку из клиента, защищая от сброса!
                       COALESCE(NULLIF(p.ct_type, ''), NULLIF(c.product_type, ''), 'Сантехника') AS project_ct_type, 
                       p.contract_number, 
                       p.id as pid 
                FROM clients c 
                LEFT JOIN projects p ON c.id = p.client_id 
                WHERE c.status = 'Отказ'";
    } else {
        $sql = "SELECT c.*, 
                       c.next_contact_date AS client_next_contact_date, 
                       COALESCE(NULLIF(p.ct_type, ''), NULLIF(c.product_type, ''), 'Сантехника') AS project_ct_type, 
                       p.contract_number, 
                       p.id as pid 
                FROM clients c 
                LEFT JOIN projects p ON c.id = p.client_id 
                WHERE c.status != 'Отказ'";
    }
    $params = [];
} else {
    if ($current_tab === 'refused') {
        $sql = "SELECT c.*, 
                       c.next_contact_date AS client_next_contact_date, 
                       COALESCE(NULLIF(p.ct_type, ''), NULLIF(c.product_type, ''), 'Сантехника') AS project_ct_type, 
                       p.contract_number, 
                       p.id as pid 
                FROM clients c 
                LEFT JOIN projects p ON c.id = p.client_id 
                WHERE c.manager_id = ? AND c.status = 'Отказ'";
    } else {
        $sql = "SELECT c.*, 
                       c.next_contact_date AS client_next_contact_date, 
                       COALESCE(NULLIF(p.ct_type, ''), NULLIF(c.product_type, ''), 'Сантехника') AS project_ct_type, 
                       p.contract_number, 
                       p.id as pid 
                FROM clients c 
                LEFT JOIN projects p ON c.id = p.client_id 
                WHERE c.manager_id = ? AND c.status != 'Отказ'";
    }
    $params = [$userId];
}
try {
    // Формируем каркас базового запроса с учетом ролей и вкладок (Рабочая/Архив)
    if ($userRole === 'admin') {
        if ($filterManagerId > 0) {
            $sql = ($current_tab === 'refused') 
                ? "SELECT * FROM clients WHERE manager_id = ? AND status = 'Отказ'" 
                : "SELECT * FROM clients WHERE manager_id = ? AND status != 'Отказ'";
            $params = [$filterManagerId];
        } else {
            $sql = ($current_tab === 'refused') 
                ? "SELECT * FROM clients WHERE status = 'Отказ'" 
                : "SELECT * FROM clients WHERE status != 'Отказ'";
            $params = [];
        }
    } else {
        $sql = ($current_tab === 'refused') 
            ? "SELECT * FROM clients WHERE manager_id = ? AND status = 'Отказ'" 
            : "SELECT * FROM clients WHERE manager_id = ? AND status != 'Отказ'";
        $params = [$userId];
    }

    // 3. Динамически приклеиваем условия фильтрации, если они выбраны менеджером
    if (!empty($sourceFilter)) {
        $sql .= " AND source = ?";
        $params[] = $sourceFilter;
    }
    if (!empty($statusFilter) && $current_tab !== 'refused') {
        $sql .= " AND status = ?";
        $params[] = $statusFilter;
    }
   if (!empty($productFilter)) {
    // Используем оператор LIKE вместо жесткого равенства =, чтобы находить вхождение
    $sql .= " AND (ct_type LIKE ? OR c.product_type LIKE ?)";
    $params[] = "%" . trim($productFilter) . "%";
    $params[] = "%" . trim($productFilter) . "%"; // Страхуем поиск и в projects, и в clients
}
    if (!empty($dateFilter)) {
    $sql .= " AND next_contact_date = ?";
    $params[] = $dateFilter;
}
    // Пришиваем логику сортировки и запрашиваем данные из СУБД Windows
    $sql .= " " . $orderByLogic;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $clients = $stmt->fetchAll() ?: [];

} catch (Exception $e) {
    $clients = [];
}
$totalClients = count($clients);
?>

<?php 
$u_id = $_SESSION['user_id'];
$u_role = $_SESSION['role'];
// =========================================================================
// WINDOWS-ФИКС №3: СБОР ОБЩЕЙ СТАТИСТИКИ ДЛЯ ПЛАШЕК ДАШБОРДА (БЕЗ ОШИБОК)
// =========================================================================
$stats = ['total' => 0, 'in_work' => 0, 'refusals' => 0, 'signed' => 0];

try {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        // АДМИН: Считает 'Текущий' по всей компании
        $sql_stats = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'Текущий' THEN 1 ELSE 0 END) as in_work,
            SUM(CASE WHEN status = 'Отказ' THEN 1 ELSE 0 END) as refusals,
            SUM(CASE WHEN is_contract_signed = 1 THEN 1 ELSE 0 END) as signed
        FROM clients";
        $stats = $pdo->query($sql_stats)->fetch() ?: $stats;
    } else {
        // МЕНЕДЖЕР: Считает 'Текущий' только по своим клиентам
        $sql_stats = "SELECT 
            COUNT(*) as total,  
            SUM(CASE WHEN status = 'Новый' THEN 1 ELSE 0 END) as in_work,
            SUM(CASE WHEN status = 'Отказ' THEN 1 ELSE 0 END) as refusals,
            SUM(CASE WHEN is_contract_signed = 1 THEN 1 ELSE 0 END) as signed
        FROM clients WHERE manager_id = ?";
        $stmt_stats = $pdo->prepare($sql_stats);
        $stmt_stats->execute([$userId]);
        $stats = $stmt_stats->fetch() ?: $stats;
    }
} catch (Exception $e) {
    // Гасим ошибки структуры СУБД
}

// Перепроверка массива, чтобы на строке 291 никогда не вылетал Undefined array key
if (!isset($stats['in_work'])) {
    $stats['in_work'] = 0;
}
// Переменные для вывода в HTML-карточки показателей
$totalClients = isset($clients) ? count($clients) : 0;
?>

<?php
// =========================================================================
// ИСПРАВЛЕНО НАМЕРТВО: Именованный безопасный INSERT лида с жестким контролем ct_type
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_new_client') {
    $client_name    = trim($_POST['client_name'] ?? '');
    $unp            = trim($_POST['unp'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $phone          = trim($_POST['phone'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $status         = trim($_POST['status'] ?? 'Новый');
    $source         = trim($_POST['source'] ?? 'Запрос');
    
    $next_contact_date = trim($_POST['next-contact-date'] ?? date('Y-m-d', strtotime('+7 days')));
    if ($next_contact_date === '0000-00-00' || empty($next_contact_date)) {
        $next_contact_date = null;
    }
    
    $manager_id = (int)($_POST['manager_id'] ?? $userId);
    
    // =========================================================================
    // НАМЕРТВО ИСПРАВЛЕНО: Сборка массива чекбоксов в единую строку через запятую
    // =========================================================================
    $posted_ct_types = $_POST['ct_type'] ?? []; 
    $final_products_string = 'Сантехника'; // Жесткий страховочный дефолт

    if (is_array($posted_ct_types) && !empty($posted_ct_types)) {
        $final_products_string = implode(', ', array_map('trim', $posted_ct_types));
    } elseif (is_string($posted_ct_types) && !empty(trim($posted_ct_types))) {
        $final_products_string = trim($posted_ct_types);
    }
    // =========================================================================

    if (empty($client_name)) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Наименование организации не может быть пустым!']);
        exit;
    }

    try {
        // НАМЕРТВО ИСПРАВЛЕНО: Запись идет строго в колонку product_type таблицы clients!
        $sql = "INSERT INTO clients (client_name, unp, contact_person, phone, email, status, source, manager_id, product_type, first_contact_date, next_contact_date, comment) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $client_name, 
            $unp, 
            $contact_person, 
            $phone, 
            $email, 
            $status, 
            $source, 
            $manager_id, 
            $final_products_string, // <-- НАШ ФИКС: Передаем множественную строку!
            date('Y-m-d'),          // first_contact_date (сегодня)
            $next_contact_date, 
            trim($_POST['comment'] ?? '')
        ]);
        
        $newId = $pdo->lastInsertId();
        
        if (function_exists('logAction')) {
            logAction($pdo, 'INSERT', 'clients', $newId, "Создан лид: '{$client_name}' (ID: {$newId}). Продукция: {$final_products_string}");
        }
        
        header('Content-Type: application/json');
     //    echo json_encode(['status' => 'success', 'client_id' => $newId]);
        exit;

    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Ошибка СУБД: ' . $e->getMessage()]);
        exit;
    }
}
if (!empty($client_name)) {
    try {
        // =========================================================================
        //  Безошибочная проверка дубликатов по УНП
        // =========================================================================
        if (!empty($unp) && $unp !== '—' && strlen($unp) === 9) {
            $check_sql = "SELECT client_name FROM clients WHERE trim(unp) = :unp LIMIT 1";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([':unp' => $unp]);
            $duplicate = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($duplicate) {
                die("<div style='background: #f8d7da; color: #721c24; padding: 20px; border: 1px solid #f5c6cb; border-radius: 6px; margin: 20px; font-family: sans-serif;'>
                        <strong>⚠️ Ошибка дублирования данных:</strong> Контрагент с УНП «" . htmlspecialchars($unp) . "» уже существует в системе! 
                        <br>Название в базе: «" . htmlspecialchars($duplicate['client_name']) . "».
                        <br><br><a href='index.php' style='color: #721c24; font-weight: bold;'>Вернуться назад</a>
                     </div>");
            }
        }

        // =========================================================================
        // Добавлена запись и в product_type, и в ct_type!
        // =========================================================================
        $sql = "INSERT INTO clients (
                    client_name, 
                    unp, 
                    contact_person, 
                    phone, 
                    email, 
                    status, 
                    source, 
                    next_contact_date,
                    manager_id, 
                    product_type, -- Центральная колонка СУБД
                    website,
                    ct_type       -- Самая правая колонка из структуры БД (Фикс падения!)
                ) VALUES (
                    :client_name, 
                    :unp, 
                    :contact_person, 
                    :phone, 
                    :email, 
                    :status, 
                    :source, 
                    :next_contact_date,
                    :manager_id, 
                    :product_type,
                    :website,
                    :ct_type      -- Привязываем именованный параметр
                )";
        
        $stmt = $pdo->prepare($sql);
        
        // 3. Жестко привязываем переменные к параметрам (Передаем строку продукции $ct_type дважды!)
        $stmt->execute([
            ':client_name'       => $client_name,
            ':unp'               => $unp,
            ':contact_person'    => $contact_person,
            ':phone'             => $phone,
            ':email'             => $email,
            ':status'            => $status,
            ':source'            => $source,
            ':next_contact_date' => (!empty($next_contact_date) && $next_contact_date !== '0000-00-00') ? $next_contact_date : null,
            ':manager_id'        => $manager_id,
            ':product_type'      => $ct_type, // 1-й раз (для product_type)
            ':website'           => isset($website) ? trim($website) : '',
            ':ct_type'           => $ct_type  // 2-й раз (для ct_type, спасает от Fatal Error!)
        ]);
        
        header("Location: index.php");
        exit;

    } catch (Exception $e) {
        die("<div style='color:#ef4444; padding:20px; font-family:sans-serif;'>🚨 Критический сбой СУБД при добавлении клиента: " . htmlspecialchars($e->getMessage()) . "</div>");
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_mode']) && $_POST['action_mode'] === 'check_unp_duplicate_live') {
    header('Content-Type: application/json');
    if (ob_get_length()) ob_clean();

    try {
        $unp = trim($_POST['unp'] ?? '');

        if (empty($unp)) {
          echo json_encode(['status' => 'clean']); 
            exit;
        }
        // Ищем компанию с таким же УНП в таблице clients
        $stmt = $pdo->prepare("SELECT client_name FROM clients WHERE trim(unp) = ? LIMIT 1");
        $stmt->execute([$unp]);
        $existingClient = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingClient) {
            // Если нашли — отдаем JSON с флагом дубликата и именем компании
           echo json_encode([
           'status' => 'duplicate',
          'client_name' => htmlspecialchars($existingClient['client_name'], ENT_QUOTES, 'UTF-8')
         ]);
        exit;
        } else {
         // echo json_encode(['status' => 'clean']); 
           exit;
       }
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
    <title>WebCRM | Таблица</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cloudflare.com"></script> <!-- Тот самый локальный файл -->
    
<style> .form-group input:invalid, .form-group select:invalid { border: 2px solid #ef4444 !important; }
        .form-group input:valid, .form-group select:valid { border: 2px solid #10b981 !important; }
        .reminder-row { background: rgba(239, 68, 68, 0.15) !important; animation: pulse 2s infinite; }
        .manager-report-block { background: #1b1b28; padding: 15px; border-radius: 8px; margin-bottom: 20px; }</style>
    
</head>
<body>
    <!-- ИДЕАЛЬНОЕ ВЕРТИКАЛЬНОЕ МЕНЮ СИСТЕМЫ -->

    <?php include 'sidebar.php'; ?>
    <main>

            <header style =" background-color: #151521 !important;
        background: #151521 !important;
        border-bottom: 1px solid #323248 !important; margin-left: 15px;">

        <button onclick="openAddModal()" class="btn-primary">+ Добавить клиента</button>
                <!-- ИСПРАВЛЕНО НАМЕРТВО: Кнопка сохраняет все PHP-фильтры вкладок и на лету подхватывает живой поиск из инпута -->
    <a href="export_excel.php?tab=<?= htmlspecialchars($current_tab) ?>&manager_id=<?= $filterManagerId ?>&source=<?= urlencode($sourceFilter) ?>&status=<?= urlencode($statusFilter) ?>&ct_type=<?= urlencode($productFilter) ?>" 
    id="excelExportButton"
    onclick="
            // На лету перехватываем строку быстрого поиска со страницы
            const searchInput = document.getElementById('client_live_search') || document.getElementById('archive_live_search');
            const q = searchInput ? searchInput.value.trim() : '';
            // Дописываем параметр query в ссылку перед самим скачиванием
            this.href = this.getAttribute('href') + '&query=' + encodeURIComponent(q);
    "
    style="background: #10b981; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; font-size: 13px; display: inline-block; transition: 0.2s; border: none; cursor: pointer;"
    onmouseover="this.style.background='#059669';" 
    onmouseout="this.style.background='#10b981';">
        📊 СКАЧАТЬ ОТЧЕТ В EXCEL
    </a>

    <button type="button" onclick="openComplexModal();" style="background: #818cf8; color: #fff; border: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; font-size: 13px; cursor: pointer; transition: 0.2s;" onmouseover="this.style.background='#6366f1';" onmouseout="this.style.background='#818cf8';">
    💎 Добавить клиента и договор
</header>        
<!-- БЛОК ФИЛЬТРАЦИИ ПО ИСТОЧНИКУ -->
<div class="toolbar" style="background: #1e1e2d; padding: 15px; border-radius: 8px; border: 1px solid #323248; margin-bottom: 20px; margin-left:25px;">
    <form method="GET" action="index.php" style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap; margin: 0; padding: 0;">
        <!-- Сохраняем текущую вкладку (Рабочая база / Архив), чтобы при фильтрации она не сбрасывалась -->
        <input type="hidden" name="tab" value="<?= htmlspecialchars($current_tab) ?>">
        <?php if ($userRole === 'admin' && $filterManagerId > 0): ?>
            <input type="hidden" name="manager_id" value="<?= $filterManagerId ?>">
        <?php endif; ?>
        <!-- Фильтр 1: По типу продукции -->
        <div style="display: flex; flex-direction: column; gap: 4px;">
            <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Вид продукции:</label>
            <select name="ct_type" style="padding: 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; cursor: pointer; font-size: 13px;">
                <option value="Все виды" <?= $productFilter === '' ? 'selected' : '' ?>>Все виды</option>
                <option value="Посуда" <?= $productFilter === 'Посуда' ? 'selected' : '' ?>>Посуда</option>
                <option value="Сантехника" <?= $productFilter === 'Сантехника' ? 'selected' : '' ?>>Сантехника</option>
                <option value="ЕКМ" <?= $productFilter === 'ЕКМ' ? 'selected' : '' ?>>ЕКМ</option>
                <option value="МПДУ" <?= $productFilter === 'МПДУ'? 'selected' : '' ?>>МПДУ</option> 
                <option value="Резервуары"<?= $productFilter === 'Резервуары'? 'selected' : '' ?>>Резервуары</option>
                <option value = "Эмалированные таблички" <?= $productFilter === 'Эмалированные таблички' ? 'selected' : '' ?>>Эмалированные таблички</option>
                <option value = "УОКТ" <?= $productFilter === "УОКТ" ? 'selected' : ''?>>УОКТ</option>
                <option value = "Другое" <?= $productFilter === "Другое" ? 'selected' : '' ?>>Другое</option>                
            </select>
        </div>
        <!-- Фильтр 2: По дате следующего контакта -->
        <div style="display: flex; flex-direction: column; gap: 4px; width: 160px;">
            <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Дата контакта:</label>
            <input type="date" 
                   name="next_contact_date_filter" 
                   value="<?= htmlspecialchars($dateFilter) ?>" 
                   style="height: 42px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; box-sizing: border-box; font-size: 13px; color-scheme: dark; cursor: pointer; width: 100%;">
        </div>
        <!-- Фильтр 3: По источнику привлечения -->
        <div style="display: flex; flex-direction: column; gap: 4px;">
            <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Источник привлечения:</label>
            <select name="source" style="padding: 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; cursor: pointer; font-size: 13px;">
                <option value="Все источники" <?= $sourceFilter === '' ? 'selected' : '' ?>>Все источники</option>
                <option value="Запрос" <?= $sourceFilter === 'Запрос' ? 'selected' : '' ?>>Запрос</option>
                <option value="Холодный поиск" <?= $sourceFilter === 'Холодный поиск' ? 'selected' : '' ?>>Холодный поиск</option>
                <option value="Закупки" <?= $sourceFilter === 'Закупки' ? 'selected' : '' ?>>Закупки</option>
                <option value="Связка" <?= $sourceFilter === 'Связка' ? 'selected' : '' ?>>Связка</option>
            </select>
            <style>
                select[name="lead_source"] {
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://w3.org' width='12' height='12' fill='%2392929f' viewBox='0 0 16 16'><path d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/></svg>") !important;
    background-repeat: no-repeat !important;
    background-position: calc(100% - 12px) center !important;
}

select[name="lead_source"]:focus {
    border-color: #4f46e5 !important;
    box-shadow: 0 0 8px rgba(79, 70, 229, 0.3) !important;
}
            </style>
        </div>
        <div style="display: flex; flex-direction: column; gap: 4px; width: 300 px;">
    <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Быстрый поиск компании:</label>
    <input type="text" 
           id="client_live_search" 
           placeholder="Введите любые сведения, которые помните" 
           oninput="runLiveClientFilter(this.value)"
           style="height: 42px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; box-sizing: border-box; font-size: 13px; width: 100%;">
           <script>
            function runLiveClientFilter(searchQuery) {
    const query = searchQuery.toLowerCase().trim();
    const rows = document.querySelectorAll("table tbody tr");
    rows.forEach(row => {
        if (query === "") {
            row.style.display = "";
        } else if (row.innerText.toLowerCase().includes(query)) {
            row.style.display = "";
        } else {
            row.style.display = "none";
        }
    });
}
           </script>
</div>


 <!-- Фильтр: Статус лида (Возвращен в систему) -->
        <div style="display: flex; flex-direction: column; gap: 4px; width: 160px;">
            <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Статус клиента:</label>
            <select name="status" style="height: 42px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; cursor: pointer; font-size: 13px; box-sizing: border-box; width: 100%;">
                <option value="" <?= empty($statusFilter) ? 'selected' : '' ?>>Все статусы</option>
                <option value="Новый" <?= $statusFilter === 'Новый' ? 'selected' : '' ?>>🔴 Новый</option>
                <option value="Текущий" <?= $statusFilter === 'Текущий' ? 'selected' : '' ?>>🟡 Текущий</option>
                <option value="Отказ" <?= $statusFilter === 'Отказ' ? 'selected' : ''?>>Отказ</option>
            </select>
        </div>


         <!-- ФИЛЬТР ПО МЕНЕДЖЕРАМ: Отображается СТРОГО только для Администраторов -->
<?php if ($userRole === 'admin'): 
    // Запрашиваем всех активных менеджеров из базы данных для выпадающего списка
    try {
        $stmt_m = $pdo->query("SELECT id, login FROM users WHERE role = 'manager' ORDER BY login ASC");
        $all_managers = $stmt_m->fetchAll() ?: [];
    } catch (Exception $e) { $all_managers = []; }
?>
    <div style="display: flex; flex-direction: column; gap: 4px; width: 180px;">
        <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Менеджер-фильтр:</label>
        <select name="manager_id" style="padding: 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; cursor: pointer; font-size: 13px; box-sizing: border-box; width: 100%;">
            <option value="0" <?= $filterManagerId === 0 ? 'selected' : '' ?>>Все менеджеры</option>
            <?php foreach ($all_managers as $m): ?>
                <option value="<?= (int)$m['id'] ?>" <?= $filterManagerId === (int)$m['id'] ? 'selected' : '' ?>>
                    👤 <?= htmlspecialchars($m['login']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    
    </div>
<?php

$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
    // Фильтр №2: По коммерческому статусу лида (Новый, Текущий)
    if (!empty($statusFilter) && $current_tab !== 'refused') {
        $sql .= " AND status = ?";
        $params[] = $statusFilter;
    }


?>
       
<?php endif; ?>

        <!-- Кнопки управления фильтрацией -->
        <div style="display: flex; gap: 10px; margin-top: 18px;">
            <button type="submit" style="background: #4f46e5; color: #fff; border: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 13px; transition: 0.2s;">🔍 Применить</button>
            <a href="index.php?tab=<?= htmlspecialchars($current_tab) ?><?= $filterManagerId > 0 ? '&manager_id='.$filterManagerId : '' ?>" style="background: #323248; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; font-size: 13px; display: inline-block; transition: 0.2s;">❌ Сбросить</a>
        </div>

    </form>
    
</div>


<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin: 5px 0 5px 0; width: 100%;">
    
    <!-- Карточка 1 -->
    <div style="background: #1e1e2d; padding: 15px; border-radius: 12px; border-left: 5px solid #4f46e5; box-shadow: 0 4px 6px rgba(0,0,0,0.2); margin-left:25px; margin-right: 25px;">
        <div style="color: #92929f; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px;">Всего клиентов</div>
        <div style="color: #fff; font-size: 28px; font-weight: bold; line-height: 1;"><?= (int)$stats['total'] ?></div>
    </div>

    <!-- Карточка 2 -->
    <div style="background: #1e1e2d; padding: 20px; border-radius: 12px; border-left: 4px solid #f6ad55; box-shadow: 0 4px 6px rgba(0,0,0,0.2); margin-left:25px; margin-right:25px;">
        <div style="color: #92929f; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px;">в работе</div>
        <div style="color: #fff; font-size: 28px; font-weight: bold; line-height: 1;"><?= (int)$stats['in_work'] ?></div>
    </div>

    <!-- Карточка 3 -->
    <div style="background: #1e1e2d; padding: 20px; border-radius: 12px; border-left: 4px solid #f56565; box-shadow: 0 4px 6px rgba(0,0,0,0.2); margin-left:25px; margin-right: 25px;">
        <div style="color: #92929f; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px;">Отказы</div>
        <div style="color: #fff; font-size: 28px; font-weight: bold; line-height: 1;"><?= (int)$stats['refusals'] ?></div>
    </div>

    <!-- Карточка 4 -->
    <div style="background: #1e1e2d; padding: 20px; border-radius: 12px; border-left: 4px solid #10b981; box-shadow: 0 4px 6px rgba(0,0,0,0.2); margin-left:25px; margin-right:25px;">
        <div style="color: #92929f; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px;">Заключено сделок</div>
        <div style="color: #fff; font-size: 28px; font-weight: bold; line-height: 1;"><?= (int)$stats['signed'] ?></div>
    </div>

</div>


        <div class="table-container">
            <div style="display: flex; gap: 10px; margin-bottom: 15px; text-align: left; align-items: center; flex-wrap: wrap;">
    <!-- Сохраняем manager_filter в ссылках, чтобы при переключении вкладок админский фильтр не сбрасывался -->
    <?php $mQuery = $filterManagerId > 0 ? '&manager_filter=' . $filterManagerId : ''; ?>
    
    <a href="index.php?tab=active<?= $mQuery ?>" style="text-decoration: none; padding: 8px 16px; border-radius: 6px; font-size: 13px;  font-weight: bold; background: <?= $tab === 'active' ? '#4f46e5' : '#1e1e2d' ?>; color: #fff; border: 1px solid <?= $tab === 'active' ? '#4f46e5' : '#323248' ?>; transition: 0.15s;">
        💼 Рабочая база клиентов
    </a>
    
    <a href="index.php?tab=refused<?= $mQuery ?>" style="text-decoration: none; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: bold; background: <?= $tab === 'refused' ? '#ef4444' : '#1e1e2d' ?>; color: #fff; border: 1px solid <?= $tab === 'refused' ? '#ef4444' : '#323248' ?>; transition: 0.15s;">
        ❌ Архив отказов
    </a>
</div>
 <div style="max-height: 820px; width: 100%; border: 1px solid #323248; border-radius: 8px; background: #1e1e2d; box-shadow: 0 4px 20px rgba(0,0,0,0.3); box-sizing: border-box;">
    
   <!-- ИСПРАВЛЕНО НАМЕРТВО: Колонки освобождены от жестких процентов экрана и двигаются динамически по длине текста! -->
<table style="width: 100% !important; min-width: 1400px; border-collapse: separate; border-spacing: 0; margin: 0; background: #13131a; table-layout: fixed !important; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">

    <!-- СТИЛЬНАЯ ЛИПКАЯ ШАПКА С ИСПРАВЛЕННЫМИ СВОЙСТВАМИ ДЛЯ ДИНАМИЧЕСКОГО РЕСАЙЗА -->
    <thead style="position: sticky; top: 0; z-index: 10; background: #161624;">
        <tr style="background: #161624; border-bottom: 2px solid #323248;">
            <th style="padding: 16px 10px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-align: center; white-space: nowrap; position: relative; overflow: hidden; text-overflow: ellipsis; border: none; background: #161624;">П/П<div class="resizer"></div></th>
            <th style="padding: 16px 10px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-align: center; white-space: nowrap; position: relative; overflow: hidden; text-overflow: ellipsis; border: none; background: #161624;">Дата контакта<div class="resizer"></div></th>
            <th style="padding: 16px 10px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-align: left; white-space: nowrap; position: relative; overflow: hidden; text-overflow: ellipsis; border: none; background: #161624;">Клиент<div class="resizer"></div></th>
            <th style="padding: 16px 10px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-align: center; white-space: nowrap; position: relative; overflow: hidden; text-overflow: ellipsis; border: none; background: #161624;">УНП<div class="resizer"></div></th>
            <th style="text-align: left; padding: 12px 10px; font-size: 11px; color: #92929f; text-transform: uppercase; letter-spacing: 0.5px;">Контактное лицо</th>
            <th style="padding: 16px 10px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-align: center; white-space: nowrap; position: relative; overflow: hidden; text-overflow: ellipsis; border: none; background: #161624;">Статус<div class="resizer"></div></th>
            <th style="padding: 16px 10px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-align: center; white-space: nowrap; position: relative; overflow: hidden; text-overflow: ellipsis; border: none; background: #161624;">Источник<div class="resizer"></div></th>
            <th style="padding: 16px 10px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-align: center; white-space: nowrap; position: relative; overflow: hidden; text-overflow: ellipsis; border: none; background: #161624;">След. контакт<div class="resizer"></div></th>
            <th style="padding: 16px 10px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-align: center; white-space: nowrap; position: relative; overflow: hidden; text-overflow: ellipsis; border: none; background: #161624;">Вид продукции<div class="resizer"></div></th>
            <th style="padding: 16px 10px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-align: center; white-space: nowrap; position: relative; overflow: hidden; text-overflow: ellipsis; border: none; background: #161624;">Контракт<div class="resizer"></div></th>
            <th style="padding: 16px 10px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-align: center; white-space: nowrap; position: relative; overflow: hidden; text-overflow: ellipsis; border: none; background: #161624;">Действие<div class="resizer"></div></th>
            <th style="padding: 16px 10px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-align: center; white-space: nowrap; position: relative; overflow: hidden; text-overflow: ellipsis; border: none; background: #161624;">Скан КП</th>

        </tr>
      
        
        <script>document.addEventListener('DOMContentLoaded', function() {
    const createResizableTable = function(table) {
        if (!table) return;
        const cols = table.querySelectorAll('th');
        
        cols.forEach(function(col) {
            const resizer = col.querySelector('.resizer');
            if (!resizer) return;
            
            let x = 0;
            let w = 0;
            
            const mouseMoveHandler = function(e) {
                const dx = e.clientX - x;
                col.style.width = (w + dx) + 'px';
                col.style.minWidth = (w + dx) + 'px'; // Фиксируем min-width для удержания структуры
            };
            
            const mouseUpHandler = function() {
                resizer.classList.remove('resizing');
                document.removeEventListener('mousemove', mouseMoveHandler);
                document.removeEventListener('mouseup', mouseUpHandler);
            };
            
            resizer.addEventListener('mousedown', function(e) {
                x = e.clientX;
                const styles = window.getComputedStyle(col);
                w = parseInt(styles.width, 10);
                
                resizer.classList.add('resizing');
                document.addEventListener('mousemove', mouseMoveHandler);
                document.addEventListener('mouseup', mouseUpHandler);
            });
        });
    };  
document.addEventListener("DOMContentLoaded", () => {
    // Находим форму внутри твоего окна openComplexForm строго по тегу или классу, чтобы исключить конфликты ID
    const complexForm = document.querySelector('#jointClientContractForm') 
                      || document.querySelector('#jointForm') 
                      || document.querySelector('#complexForm')
                      || document.querySelector('#clientContractForm')
                      || document.querySelector('form[id*="Complex"]');

    if (complexForm) {
        console.log("Пакетный движок успешно изолировал комплексную форму связки!");
        
          complexForm.onsubmit = async function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            console.log("Старт пакетной транзакции создания лида и контракта на save.php...");

            try {
                const formData = new FormData(this);

                // Жестко заставляем PHP включить режим связки клиент+договор
                formData.set('action', 'complex'); 
                
                // Собираем данные по ID, если в HTML у полей кривые name
                const contractNumInput = document.getElementById('contract_number') 
                                      || document.getElementById('contract_num') 
                                      || document.getElementById('add_contract_number')
                                      || document.querySelector('input[id*="contract"]')
                                      || document.querySelector('input[name*="contract"]');
                                      
                const contractDateInput = document.getElementById('contract_date') 
                                       || document.getElementById('date')
                                       || document.querySelector('input[type="date"]');

                if (contractNumInput) {
                    formData.set('contract_number', contractNumInput.value);
                    console.log("Найден номер договора для отправки:", contractNumInput.value);
                } else {
                    console.error("🚨 КРИТИЧЕСКИЙ СБОЙ ФРОНТЕНДА: Поле номера договора вообще не найдено в DOM!");
                }

                if (contractDateInput) {
                    formData.set('contract_date', contractDateInput.value);
                    console.log("Найдена дата договора для отправки:", contractDateInput.value);
                }

                const res = await fetch('save.php', {
                    method: 'POST',
                    body: formData
                });

                const rawText = await res.text();
                console.log("Сырой ответ сервера комплексной связки:", rawText);
                
                if (!rawText.trim().startsWith('{')) {
                    alert("🚨 КРИТИЧЕСКИЙ СБОЙ ТРАНЗАКЦИИ СУБД!\nСервер вернул ошибку PHP вместо JSON:\n\n" + rawText);
                    return;
                }

                const result = JSON.parse(rawText);
                if (result.status === 'success') {
                    console.log("Пакетная запись успешно зафиксирована во всех таблицах!");
                    
                    const cModal = document.getElementById('complexModal') || document.getElementById('jointModal') || document.getElementById('clientModal');
                    if (cModal) cModal.style.display = 'none';
                    
                    // ЧИСТЫЙ ПЕРЕХОД: полностью сбрасывает POST-данные и убирает окно браузера
                    window.location.replace(window.location.pathname);
                } else {
                    alert("⚠️ Отказ СУБД при создании связки:\n" + result.message);
                }
            } catch (err) {
                console.error("Сбой транспорта комплексной формы:", err);
                alert("Критическая ошибка сети или синтаксиса JavaScript. Проверьте консоль F12.");
            }
            return false;
        };
    }
});
    // Находим нашу главную таблицу и инициализируем на ней ручной сплиттер колонок
    const mainTable = document.querySelector('table');
    createResizableTable(mainTable);
});

document.addEventListener('keydown', function(event) {
    // Проверяем, что нажата именно клавиша Escape
    if (event.key === 'Escape' || event.keyCode === 27) {
        console.log("⌨️ СИСТЕМА: Перехвачено нажатие клавиши Esc. Закрытие окон без сохранения...");

        // 1. Главная карточка редактирования клиента
        const editModal = document.getElementById('clientModal');
        if (editModal && (editModal.style.display === 'flex' || editModal.style.display === 'block')) {
            editModal.style.display = 'none';
            console.log("➡ Закрыто окно редактирования clientModal");
        }

        // 2. Изолированная карточка просмотра (Только чтение), которую мы сделали
        const viewModal = document.getElementById('viewClientModal');
        if (viewModal && (viewModal.style.display === 'flex' || viewModal.style.display === 'block')) {
            viewModal.style.display = 'none';
            console.log("➡ Закрыто окно просмотра viewClientModal");
        }

        // 3. Модалка создания/редактирования договоров из прошлого шага
        const contractModal = document.getElementById('contractModal') || document.getElementById('newContractModal') || document.getElementById('complexModal');
        if (contractModal && (contractModal.style.display === 'flex' || contractModal.style.display === 'block')) {
            contractModal.style.display = 'none';
            
            // Если у тебя в коде осталась функция сброса галочек при отмене, вызываем её
            if (typeof closeContractModal === 'function') {
                closeContractModal();
            }
            console.log("➡ Закрыто окно договора contractModal");
        }
    }
});


</script>
        <style>
            /* Стили для интерактивных ползунков ручного изменения ширины колонок */
            th {
                position: sticky; /* Оставляем шапку зафиксированной сверху */
                top: 0;
            }
            .resizer {
                position: absolute;
                top: 0;
                right: 0;
                width: 6px;
                cursor: col-resize;
                user-select: none;
                height: 100%;
                z-index: 10;
                transition: background 0.15s;
            }
            .resizer:hover {
                background: rgba(79, 70, 229, 0.2); /* Тонкая неоновая подсветка зоны захвата */
            }
            .resizing, .resizer:active {
                border-right: 2px solid #4f46e5; /* Жесткая фиксация границы при перетаскивании */
                background: rgba(79, 70, 229, 0.3);
            }
        </style>
    </thead>

    <tbody>
    <?php $i = 1; foreach ($clients as $c): 
        $isOverdue = false;
        if ($c['status'] !== 'Отказ' && !empty($c['next_contact_date'])) {
            $currentDate = strtotime(date('Y-m-d'));
            $contactDate = strtotime($c['next_contact_date']);
            
            $daysDiff = ($contactDate - $currentDate) / 86400;
            
            // Сработает на: сегодня, завтра, +6 дней вперед и любую прошлую просрочку
            if ($daysDiff <= 6) {
                $isOverdue = true;
            }
        }
    ?>

        <!-- ИСПРАВЛЕНО: Премиальные отступы, ховер и рамки строки данных -->
      <tr data-id="<?= $c['id'] ?>" class="<?= $isOverdue ? 'reminder-row' : '' ?>" style="border-bottom: 1px solid #1c1c28; transition: all 0.15s ease;" onmouseover="this.style.background='#171725'; this.style.boxShadow='inset 4px 0 0 #4f46e5';" onmouseout="this.style.background='transparent'; this.style.boxShadow='none';">
            
            <!-- 1. П/П (Приглушенный фиолетовый) -->
            <td style="padding: 14px 10px; text-align: center; color: #52526b; font-family: monospace; font-weight: bold; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= $i++ ?></td>
            
            <!-- 2. Дата первого контакта (Пепельный) -->
            <td class="cell-date" style="padding: 14px 10px; text-align: center; color: #71717a; font-family: monospace; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= date('d.m.Y', strtotime($c['first_contact_date'])) ?></td>
            
            <!-- 3. Название компании (Яркий белый нео-нуар) -->
<td style="padding: 12px 10px; text-align: left; vertical-align: middle; min-width: 220px; box-sizing: border-box;">
    <a href="#" 
       onclick="openClientCard(<?= (int)$c['id'] ?>); return false;" 
       style="color: #ffffff; font-weight: 700; text-decoration: none; font-size: 13px; transition: color 0.15s ease; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;"
       onmouseover="this.style.color='#818cf8'; this.style.textDecoration='underline';" 
       onmouseout="this.style.color='#ffffff'; this.style.textDecoration='none';">
        🏢 <?= htmlspecialchars($c['client_name'] ?? 'Без названия') ?>
    </a>
</td>
<!-- АБСОЛЮТНО ИЗОЛИРОВАННАЯ КАРТОЧКА КЛИЕНТА (СПУЩЕНО В ПОДВАЛ index.php) -->
<!-- АБСОЛЮТНО ИЗОЛИРОВАННАЯ КАРТОЧКА КЛИЕНТА (РЕЖИМ СТРОГОГО ПРОСМОТРА — ИНПУТЫ ЗАБЛОКИРОВАНЫ) -->
<div id="viewClientModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.75); justify-content: center; align-items: center; z-index: 999999; box-sizing: border-box; padding: 15px;">
    <div style="background: #1e1e2d; border-radius: 12px; border: 1px solid #323248; padding: 30px; width: 600px; box-sizing: border-box; box-shadow: 0 15px 40px rgba(0,0,0,0.6); color: #fff; font-family: sans-serif; position: relative;"> 
        
        <!-- Шапка карточки -->
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #323248; padding-bottom: 12px; margin-bottom: 20px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <h3 id="viewModalTitle" style="margin: 0; font-size: 16px; text-transform: uppercase; letter-spacing: 0.5px; color: #818cf8; font-weight: bold;">
                    🔒 Просмотр контрагента
                </h3>
                <!-- НЕОНОВЫЙ ИНДИКАТОР: Показывает менеджерам, что это режим Только чтение -->
                <span style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); color: #3b82f6; font-size: 9px; font-weight: 800; padding: 2px 8px; border-radius: 4px; text-transform: uppercase; letter-spacing: 0.5px;">
                    Только чтение
                </span>
            </div>
            <button type="button" onclick="document.getElementById('viewClientModal').style.display='none';" style="background: none; border: none; color: #71717a; cursor: pointer; font-size: 24px; line-height: 1; padding: 0; transition: color 0.1s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#71717a'">&times;</button>
        </div>

        <!-- Тело со сводной информацией (СТРОГИЙ ТЕКСТОВЫЙ РЕЖИМ БЕЗ ФОРМ И ИНПУТОВ) -->
        <div id="viewModalBody" style="margin-bottom: 20px; max-height: 350px; overflow-y: auto; padding-right: 4px;">
            <!-- Сюда JS-функция закидывает чистый текст реквизитов организации -->
        </div>
    
                <!-- ========================================== -->
            <!-- БЛОК 8: МУЛЬТИКОНТАКТЫ -->
            <!-- ========================================== -->
            <div style="grid-column: 1 / -1; margin-top: 15px; margin-bottom: 15px; border-top: 1px dashed #323248; padding-top: 15px; text-align: left; width: 100%; box-sizing: border-box;">
                
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; width: 100%;">
                    <h3 style="margin: 0; font-size: 13px; color: #fff; text-transform: uppercase; letter-spacing: 0.5px;">👥 Контактные лица компании</h3>
                    <button type="button" id="toggleContactViewBtn" onclick="toggleContactView();" style="background: #4f46e5; border: none; color: #fff; padding: 0 16px; border-radius: 6px; font-size: 12px; font-weight: bold; cursor: pointer; height: 36px;">
                        ➕ Добавить контакт
                    </button>
                </div>

                <!-- ГРИД КОНТАКТОВ -->
                <div id="contactsGrid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px; max-height: 250px; overflow-y: auto; padding-right: 5px; margin-bottom: 10px; width: 100%; box-sizing: border-box; min-height: 50px;">
                    <!-- Сюда JS будет рендерить карточки контактов -->
                </div>

                <!-- ФОРМА ДОБАВЛЕНИЯ КОНТАКТА -->
                <div id="contactsFormArea" style="display: none; background: rgba(255,255,255,0.02); border: 1px solid #323248; border-radius: 8px; padding: 15px; box-sizing: border-box; width: 100%;">
                    <!-- Сюда JS подставит форму -->
                </div>
            </div>
        <!-- Кнопка закрытия -->
        <div style="display: flex; justify-content: flex-end; width: 100%;">
            <button type="button" onclick="document.getElementById('viewClientModal').style.display='none';" style="height: 38px; padding: 0 25px; background: #242434; border: 1px solid #323248; color: #fff; border-radius: 6px; font-size: 13px; font-weight: bold; cursor: pointer; transition: all 0.15s; outline: none;" onmouseover="this.style.background='#323248'; this.style.borderColor='#4b5563';" onmouseout="this.style.background='#242434'; this.style.borderColor='#323248';">
                Закрыть карточку
            </button>
        </div>

<script>
// НАМЕРТВО ИСПРАВЛЕНО: Прямая асинхронная выгрузка данных в изолированную карточку просмотра
window.openPureViewModal = async function(id) {
    console.log("=== ЗАПУСК ПРЯМОЙ ВЫГРУЗКИ КАРТОЧКИ ПРОСМОТРА ДЛЯ ID #" + id + " ===");
    
    const modal = document.getElementById('viewClientModal');
    const body = document.getElementById('viewModalBody');
    const contactsGrid = document.getElementById('viewModalContactsGrid');
    
    if (!modal || !body || !contactsGrid) {
        console.error("🚨 КРИТ: Элементы модалки просмотра не найдены в DOM!");
        return;
    }

    // Вспомогательная локальная функция экранирования кавычек от вылетов верстки
    const localEscapeHtml = function(text) {
        if (!text) return '—';
        return text.toString().replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    };

    try {
        // Очищаем старый текст на время загрузки, чтобы менеджер видел реактивность
        body.innerHTML = '<div style="color: #64748b; font-size: 13px; text-align: center; padding: 20px;">Секунду, подгружаю реквизиты из СУБД...</div>';
        contactsGrid.innerHTML = '';

        // 1. Делаем прямой фоновый запрос к бэкенду за данными контрагента
        const res = await fetch('get_client.php?id=' + parseInt(id, 10));
        const responseData = await res.json();
        
        if (responseData.status !== 'success' || !responseData.data) {
            body.innerHTML = `<div style="color: #ef4444; font-size: 13px; text-align: center; padding: 20px;">Ошибка СУБД: ${localEscapeHtml(responseData.message)}</div>`;
            return;
        }

        const data = responseData.data;
    
        // 2. Обновляем динамический заголовок
        if (document.getElementById('viewModalTitle')) {
            document.getElementById('viewModalTitle').innerText = '🔒 Просмотр контрагента #' + data.id;
        }

        // 3. Рендерим все реквизиты компании чистым, красивым, нередактируемым текстом
        body.innerHTML = `
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; text-align: left;">
                <div>
                    <div style="font-size: 10px; color: #64748b; font-weight: bold; text-transform: uppercase; margin-bottom: 4px;">Название организации</div>
                    <div style="font-size: 14px; color: #fff; font-weight: 500;">${localEscapeHtml(data.client_name)}</div>
                </div>
                <div>
                    <div style="font-size: 10px; color: #64748b; font-weight:bold; text-transform: uppercase; margin-bottom: 4px;">УНП контрагента</div>
                    <div style="font-size: 14px; color: #fff; font-family: monospace;">${localEscapeHtml(data.UNP || data.unp)}</div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; text-align: left;">
                <div>
                    <div style="font-size: 10px; color: #64748b; font-weight: bold; text-transform: uppercase; margin-bottom: 4px;">Сайт компании</div>
                    <div style="font-size: 14px; color: #818cf8;">${localEscapeHtml(data.website)}</div>
                </div>
                <div>
                    <div style="font-size: 10px; color: #64748b; font-weight: bold; text-transform: uppercase; margin-bottom: 4px;">E-mail компании</div>
                    <div style="font-size: 14px; color: #fff;">${localEscapeHtml(data.email)}</div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; text-align: left;">
                <div>
                    <div style="font-size: 10px; color: #64748b; font-weight: bold; text-transform: uppercase; margin-bottom: 4px;">Телефон прямой</div>
                    <div style="font-size: 14px; color: #fff; font-family: monospace;">${localEscapeHtml(data.phone)}</div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; text-align: left;">
                <div> 
                <div>
                    <div style="font-size: 10px; color: #64748b; font-weight: bold; text-transform: uppercase; margin-bottom: 4px;">Статус клиента</div>
                    <div style="font-size: 14px; color: #10b981; font-weight: bold;">${localEscapeHtml(data.status || 'Новый')}</div>
                </div>
            </div>
            </div>
            <div style="border-top: 1px solid #232334; padding-top: 12px; text-align: left;">
                <div style="font-size: 10px; color: #64748b; font-weight: bold; text-transform: uppercase; margin-bottom: 4px;">Комментарий менеджера</div>
                <div style="font-size: 13px; color: #92929f; line-height: 1.4; white-space: pre-wrap;">${localEscapeHtml(data.comment)}</div>
            </div>
        `;

        // 4. Выводим связанных ЛПР и дополнительные контакты
        if (data.contacts && Array.isArray(data.contacts) && data.contacts.length > 0) {
            data.contacts.forEach(contact => {
                let name = contact.name || (contact.contact_name || 'Без имени');
                let role = contact.position || (contact.contact_role || '');
                let phone = contact.phone || '';

                const card = document.createElement('div');
                card.style.cssText = "background: #151521; border: 1px solid #323248; border-radius: 6px; padding: 10px; text-align: left;";
                card.innerHTML = `
                    <div style="font-weight: bold; color: #fff; font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">👤 ${localEscapeHtml(name)}</div>
                    ${role ? `<div style="color: #64748b; font-size: 11px;">💼 ${localEscapeHtml(role)}</div>` : ''}
                    ${phone ? `<div style="color: #818cf8; font-family: monospace; font-size: 11px;">📞 ${localEscapeHtml(phone)}</div>` : ''}
                `;
                contactsGrid.appendChild(card);
            });
        } else {
            contactsGrid.innerHTML = `<div style="color: #64748b; font-size: 12px; grid-column: 1/-1; text-align: center; padding: 10px; border: 1px dashed #232334; border-radius: 6px;">Дополнительных лиц связи не добавлено</div>`;
        }

        // 5. Проявляем готовую заполненную модалку на экране
        modal.style.setProperty('display', 'flex', 'important');

    } catch (err) {
        console.error("🚨 ОШИБКА РЕНДЕРИНГА КАРТОЧКИ ПРОСМОТРА:", err);
        body.innerHTML = `<div style="color: #ef4444; font-size: 13px; text-align: center; padding: 20px;">Критическая ошибка сети при связи с хостингом.</div>`;
    }
};
// ============================================================
// МУЛЬТИКОНТАКТЫ - УПРАВЛЕНИЕ
// ============================================================
let contactIndex = 0;
window.currentModalContacts = [];
window.editingContactIndex = null;

// Переключение между гридом и формой
function toggleContactView(showForm = null) {
    const grid = document.getElementById('contactsGrid');
    const formArea = document.getElementById('contactsFormArea');
    const btn = document.getElementById('toggleContactViewBtn');
    
    if (!grid || !formArea || !btn) return;

    const isFormVisible = (showForm !== null) ? showForm : (grid.style.display === 'none');

    if (isFormVisible) {
        grid.style.display = 'grid';
        formArea.style.display = 'none';
        btn.innerText = "➕ Добавить контакт";
        btn.style.background = "#4f46e5";
        window.editingContactIndex = null;
    } else {
        grid.style.display = 'none';
        formArea.style.display = 'block';
        btn.innerText = "⬅️ К списку лиц";
        btn.style.background = "#323248";
    }
}

// Открытие формы для создания/редактирования контакта
function openContactFormWithData(c = null) {
    const formArea = document.getElementById('contactsFormArea');
    if (!formArea) return;

    const name = c ? (c.name || '') : '';
    const position = c ? (c.position || '') : '';
    const phone = c ? (c.phone || '') : '';
    const email = c ? (c.email || '') : '';
    const postal_address = c ? (c.postal_address || '') : '';
    const function_notes = c ? (c.function_notes || '') : '';

    formArea.innerHTML = `
        <h4 style="margin: 0 0 12px 0; font-size: 12px; color: #818cf8; text-transform: uppercase;">${c ? 'Редактировать' : 'Новое'} контактное лицо</h4>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 10px;">
            <div>
                <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; display: block; margin-bottom: 4px;">ФИО *</label>
                <input type="text" id="tmp_contact_name" value="${escapeHtmlQuotes(name)}" placeholder="Иванов Иван Иванович" style="width: 100%; height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; box-sizing: border-box;">
            </div>
            <div>
                <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; display: block; margin-bottom: 4px;">Должность</label>
                <input type="text" id="tmp_contact_position" value="${escapeHtmlQuotes(position)}" placeholder="Главный снабженец" style="width: 100%; height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; box-sizing: border-box;">
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 10px;">
            <div>
                <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; display: block; margin-bottom: 4px;">Телефон</label>
                <input type="text" id="tmp_contact_phone" value="${escapeHtmlQuotes(phone)}" placeholder="+375..." style="width: 100%; height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; box-sizing: border-box;">
            </div>
            <div>
                <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; display: block; margin-bottom: 4px;">Email</label>
                <input type="email" id="tmp_contact_email" value="${escapeHtmlQuotes(email)}" placeholder="ivanov@mail.com" style="width: 100%; height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; box-sizing: border-box;">
            </div>
        </div>
        
        <div style="margin-bottom: 10px;">
            <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; display: block; margin-bottom: 4px;">Почтовый адрес</label>
            <input type="text" id="tmp_contact_postal" value="${escapeHtmlQuotes(postal_address)}" placeholder="246000" style="width: 100%; height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; box-sizing: border-box;">
        </div>
        
        <div style="margin-bottom: 15px;">
            <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; display: block; margin-bottom: 4px;">Примечания</label>
          <textarea id="comment" name="comment" rows="3" placeholder="Ваш комментарий..." style="width: 100%; padding: 10px 14px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; resize: vertical; box-sizing: border-box; font-family: inherit;"></textarea>
        </div>

        <div style="display: flex; gap: 10px; justify-content: flex-end;">
            <button type="button" onclick="saveContactFormToGrid();" style="background: #10b981; border: none; color:#fff; padding: 8px 20px; border-radius: 6px; font-weight: bold; cursor: pointer;">💾 Сохранить</button>
            <button type="button" onclick="toggleContactView(false);" style="background: rgba(255,255,255,0.05); border: 1px solid #323248; color:#fff; padding: 8px 20px; border-radius: 6px; cursor: pointer;">Отмена</button>
        </div>
    `;
}

// Сохранение контакта из формы в грид
function saveContactFormToGrid() {
    const nameInput = document.getElementById('tmp_contact_name');
    if (!nameInput || !nameInput.value.trim()) {
        alert("ФИО обязательно для заполнения!");
        nameInput.focus();
        return;
    }

    const contactData = {
        name: nameInput.value.trim(),
        position: document.getElementById('tmp_contact_position')?.value.trim() || '',
        phone: document.getElementById('tmp_contact_phone')?.value.trim() || '',
        email: document.getElementById('tmp_contact_email')?.value.trim() || '',
        postal_address: document.getElementById('tmp_contact_postal')?.value.trim() || '',
        function_notes: document.getElementById('tmp_contact_notes')?.value.trim() || ''
    };

    if (typeof window.editingContactIndex === 'number' && window.editingContactIndex >= 0) {
        window.currentModalContacts[window.editingContactIndex] = contactData;
    } else {
        if (!Array.isArray(window.currentModalContacts)) {
            window.currentModalContacts = [];
        }
        window.currentModalContacts.push(contactData);
    }

    window.editingContactIndex = null;
    renderContactsGrid(window.currentModalContacts);
    toggleContactView(false);
}

// Удаление контакта
function removeContactFromDataArray(index) {
    if (confirm("Удалить это контактное лицо?")) {
        window.currentModalContacts.splice(index, 1);
        renderContactsGrid(window.currentModalContacts);
    }
}

// Рендеринг грида контактов
function renderContactsGrid(contactsArray) {
    const gridContainer = document.getElementById('contactsGrid');
    if (!gridContainer) return;

    gridContainer.innerHTML = '';
    window.currentModalContacts = Array.isArray(contactsArray) ? contactsArray : [];

    if (window.currentModalContacts.length === 0) {
        gridContainer.innerHTML = `<div style="grid-column: 1/-1; color: #64748b; font-size: 13px; padding: 15px; text-align: center; border: 1px dashed #323248; border-radius: 6px;">Контактные лица не добавлены</div>`;
        return;
    }

    window.currentModalContacts.forEach((contact, idx) => {
        const card = document.createElement('div');
        card.style.cssText = "background: #151521; border: 1px solid #323248; border-radius: 8px; padding: 12px; position: relative; text-align: left; box-sizing: border-box;";
        
        card.innerHTML = `
            <div style="font-weight: bold; color: #fff; font-size: 13px; padding-right: 30px;">👤 ${escapeHtmlQuotes(contact.name || 'Без имени')}</div>
            ${contact.position ? `<div style="color: #92929f; font-size: 11px;">💼 ${escapeHtmlQuotes(contact.position)}</div>` : ''}
            ${contact.phone ? `<div style="color: #818cf8; font-family: monospace; font-size: 11px;">📞 ${escapeHtmlQuotes(contact.phone)}</div>` : ''}
            ${contact.email ? `<div style="color: #cbd5e1; font-size: 11px;">✉️ ${escapeHtmlQuotes(contact.email)}</div>` : ''}
            
            <div style="position: absolute; right: 8px; top: 8px; display: flex; gap: 4px;">
                <button type="button" onclick="editContact(${idx});" style="background: rgba(255,255,255,0.05); border: 1px solid #323248; color: #f59e0b; border-radius: 4px; padding: 2px 6px; font-size: 11px; cursor: pointer;">✏️</button>
                <button type="button" onclick="removeContactFromDataArray(${idx});" style="background: rgba(255,255,255,0.05); border: 1px solid #323248; color: #ef4444; border-radius: 4px; padding: 2px 6px; font-size: 11px; cursor: pointer;">✖</button>
            </div>
            
            <input type="hidden" name="contacts[${idx}][name]" value="${escapeHtmlQuotes(contact.name || '')}">
            <input type="hidden" name="contacts[${idx}][position]" value="${escapeHtmlQuotes(contact.position || '')}">
            <input type="hidden" name="contacts[${idx}][phone]" value="${escapeHtmlQuotes(contact.phone || '')}">
            <input type="hidden" name="contacts[${idx}][email]" value="${escapeHtmlQuotes(contact.email || '')}">
            <input type="hidden" name="contacts[${idx}][postal_address]" value="${escapeHtmlQuotes(contact.postal_address || '')}">
            <input type="hidden" name="contacts[${idx}][function_notes]" value="${escapeHtmlQuotes(contact.function_notes || '')}">
        `;
        gridContainer.appendChild(card);
    });
}

// Редактирование контакта
function editContact(index) {
    const contact = window.currentModalContacts[index];
    if (!contact) return;
    
    window.editingContactIndex = index;
    openContactFormWithData(contact);
    toggleContactView(true);
}

// Экранирование кавычек
function escapeHtmlQuotes(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// Загрузка контактов при открытии редактирования
function loadContactsToGrid(contacts) {
    if (Array.isArray(contacts) && contacts.length > 0) {
        // Убираем главный контакт (is_main) если он есть
        const filtered = contacts.filter(c => !c.is_main);
        renderContactsGrid(filtered);
    } else {
        renderContactsGrid([]);
    }
}

</script>
    </div>
</div>


            <!-- 4. УНП (Пепельный) -->
            <td class="cell-unp" style="padding: 14px 10px; text-align: center; color: #71717a; font-family: monospace; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($c['unp'] ?: '—') ?></td>
            
         
            <!-- 7. Email -->
 <!-- НАМЕРТВО ИСПРАВЛЕНО: Всеядный сбор ID внутри ячейки для вывода подсказок -->
<!-- НАМЕРТВО ИСПРАВЛЕНО: Колонка Контактное лицо с каскадной страховкой через Email -->
<td style="padding: 12px; text-align: left; vertical-align: middle; border: none !important; box-sizing: border-box; min-width: 180px;">
    <?php
    // 1. Вытаскиваем данные главного контакта из таблицы clients
    $mainPersonName  = trim($c['contact_person'] ?? '');
    $mainPersonPhone = trim($c['phone'] ?? '');
    $mainPersonEmail = trim($c['email'] ?? ($c['e_mail'] ?? ''));

    // 2. Если в clients пусто, пробуем подтянуть первое лицо из связанной таблицы client_contacts
    if (empty($mainPersonName) && empty($mainPersonPhone) && isset($c['id'])) {
        $getFallbackContact = $pdo->prepare("SELECT name, phone, email FROM client_contacts WHERE client_id = ? ORDER BY id ASC LIMIT 1");
        $getFallbackContact->execute([(int)$c['id']]);
        $fallback = $getFallbackContact->fetch(PDO::FETCH_ASSOC);
        if ($fallback) {
            $mainPersonName  = trim($fallback['name'] ?? '');
            $mainPersonPhone = trim($fallback['phone'] ?? '');
            $mainPersonEmail = trim($fallback['email'] ?? '');
        }
    }

    // Проверяем, есть ли у нас хоть какие-то живые данные по ЛПР (Имя или Телефон)
    $hasLprData = (!empty($mainPersonName) || !empty($mainPersonPhone));
    ?>
    <?php if ($hasLprData): 
        // ВАРИАНТ А: Лицо указано -> Выводим Имя / Телефон, а Email прячем в ховер подсказку
        $tooltipText = !empty($mainPersonEmail) ? "Прямой E-mail ЛПР: " . htmlspecialchars($mainPersonEmail) : "E-mail для этого контакта не указан";
    ?>
        <div title="<?= $tooltipText ?>" style="display: flex; flex-direction: column; gap: 2px; cursor: help; width: fit-content;">
            <!-- Строка 1: Имя контактного лица -->
            <span style="color: #ffffff; font-weight: 600; font-size: 13px; line-height: 1.2;">
                👤 <?= !empty($mainPersonName) ? htmlspecialchars($mainPersonName) : 'Имя не указано' ?>
            </span>
            
            <!-- Строка 2: Номер телефона в скобках под именем -->
            <?php if (!empty($mainPersonPhone)): ?>
                <span style="color: #818cf8; font-family: monospace; font-size: 11px; font-weight: normal; line-height: 1.2;">
                    (📞 <?= htmlspecialchars($mainPersonPhone) ?>)
                </span>
            <?php else: ?>
                <span style="color: #4b5563; font-style: italic; font-size: 11px; line-height: 1.2;">
                    (телефон не указан)
                </span>
            <?php endif; ?>
        </div>

    <?php elseif (!empty($mainPersonEmail)): 
        // ВАРИАНТ Б: ЛПР не указан, но есть Email -> Выводим чистый Email на экран, чтобы ячейка работала
    ?>
        <div title="Контактное лицо не указано, выведен общий Email" style="display: flex; flex-direction: column; width: fit-content;">
            <span style="color: #cbd5e1; font-size: 13px; font-weight: 500; letter-spacing: 0.2px;">
                ✉️ <?= htmlspecialchars($mainPersonEmail) ?>
            </span>
            <span style="color: #4b5563; font-style: italic; font-size: 10px; margin-top: 1px;">
                (контакт не указан)
            </span>
        </div>

    <?php else: 
        // ВАРИАНТ В: Вообще полная пустота по всем полям контактов -> выводим аккуратный прочерк
    ?>
        <span style="color: #32324d;">— — — — — — — — —</span>
    <?php endif; ?>
</td>
            <!-- 8. Статус (ИНТЕЛЛЕКТУАЛЬНЫЕ НЕОНОВЫЕ БЕЙДЖИ) -->
            <td class="cell-status" style="padding: 14px 10px; text-align: center; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                <?php
                $statusText = trim($c['status'] ?? 'Новый');
                // Подбираем премиальный полупрозрачный бейдж под статус
                $stStyle = "background: rgba(59, 130, 246, 0.1); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.2);"; // Синий дефолт
                if ($statusText === 'Новый') {
                    $stStyle = "background: rgba(129, 140, 248, 0.1); color: #818cf8; border: 1px solid rgba(129, 140, 248, 0.2);";
                } elseif ($statusText === 'В работе' || $statusText === 'Потенциальный') {
                    $stStyle = "background: rgba(245, 158, 11, 0.1); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.2);";
                } elseif ($statusText === 'Договор' || $statusText === 'Контракт' || $statusText === 'Завершен') {
                    $stStyle = "background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.2);";
                } elseif ($statusText === 'Отказ' || $statusText === 'Архив') {
                    $stStyle = "background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2);";
                }
                ?>
                <span style="<?= $stStyle ?> padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 800; display: inline-block; letter-spacing: 0.5px; text-transform: uppercase;">
                    <?= htmlspecialchars($statusText) ?>
                </span>
            </td>
            
            <!-- 9. Источник привлечения -->
            <td class="source" style="padding: 14px 10px; text-align: center; color: #a1a1aa; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($c['source'] ?: '—') ?></td>
    </td>  
<!-- 8. СЛЕДУЮЩИЙ КОНТАКТ (НАМЕРТВО ИСПРАВЛЕНО: ВСТАЛО НА СВОЁ МЕСТО И ЧИТАЕТ МАССИВ $c) -->
      <td class="cell-next-contact" style="padding: 14px 10px; text-align: center; font-size: 13px; color: #fff; vertical-align: middle; border: none !important; box-sizing: border-box; width: 160px;">
    <?php 
    $directClientId = (int)($c['id'] ?? 0);
    $currentNextDate = '';

    if ($directClientId > 0) {
        // Вытаскиваем актуальную дату созвона из СУБД для отображения в календаре
        $dbQuery = $pdo->prepare("SELECT next_contact_date FROM clients WHERE id = ? LIMIT 1");
        $dbQuery->execute([$directClientId]);
        $rawNextDate = trim($dbQuery->fetchColumn() ?: '');
        
        if (!empty($rawNextDate) && $rawNextDate !== '0000-00-00' && strtolower($rawNextDate) !== 'null') {
            $currentNextDate = date('Y-m-d', strtotime($rawNextDate));
        }
    }
    ?>
        <input type="date" 
           id="next_contact_date_input_<?= $directClientId ?>"
           value="<?= $currentNextDate ?>"
           onchange="
               console.log('📅 ФРОНТЕНД: Отправка даты...', this.value);
               
               fetch('update_inline_date.php', {
                   method: 'POST',
                   headers: { 'Content-Type': 'application/json' },
                   body: JSON.stringify({ id: <?= $directClientId ?>, value: this.value })
               })
               .then(res => {
                   if (!res.ok) throw new Error('HTTP status ' + res.status);
                   return res.json();
               })
               .then(result => {
                   if(result.status === 'success') {
                       console.log('🎯 СУБД: Дата созвона НАМЕРТВО зафиксирована в базе:', result.saved_date);
                   } else {
                       alert('Ошибка СУБД: ' + result.message);
                   }
               })
               .catch(err => {
                   console.error('🚨 КРИТ СЕТИ:', err);
                   alert('Критическая ошибка связи с новым обработчиком дат!');
               });
           "
           style="background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; padding: 6px 8px; font-family: monospace; font-size: 12px; outline: none; cursor: pointer; transition: all 0.2s ease-in-out; width: 100%; box-sizing: border-box; color-scheme: dark;">
</td>
        </td>
     


            <!-- ИСПРАВЛЕНО: Выводим тип продукции привязанного договора вместо дефолтного значения -->
<!-- ИСПРАВЛЕНО НАМЕРТВО: Проверяем все возможные имена колонок продукции из СУБД (ct_type, product_info, prod), убирая жесткий дефолт -->
<td style="padding: 12px; text-align: left; vertical-align: middle; border: none !important; font-size: 13px;">
    <?php
    $displayProduct = trim($c['product_type'] ?? 'Сантехника');
    if (empty($displayProduct)) { $displayProduct = 'Сантехника'; }
    
    // Если выбрано несколько видов, красиво подсветим их фиолетовым неоном
    $hasMultiple = (strpos($displayProduct, ',') !== false);
    $badgeStyle = $hasMultiple ? 'color: #818cf8; font-weight: bold;' : 'color: #fff;';
    ?>
    <span style="<?= $badgeStyle ?>"><?= htmlspecialchars($displayProduct) ?></span>
</td>
  <td style="padding: 14px 10px; text-align: center; vertical-align: middle; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
    <?php 
    $currentClientId = (int)($c['id'] ?? 0);
    
    // Способ 1: Прямая и строгая проверка наличия реального договора в таблице проектов projects
    $checkContractStmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE client_id = ? AND contract_number != 'Б/Н' AND contract_number IS NOT NULL AND contract_number != ''");
    $checkContractStmt->execute([$currentClientId]);
    $hasRealContract = ((int)$checkContractStmt->fetchColumn() > 0);
    
    // Способ 2: Проверка флага из самой таблицы клиентов clients
    $isSignedFlag = (isset($c['is_contract_signed']) && (int)$c['is_contract_signed'] === 1);
    
    // Галочка будет визуально чекнута, если взведен флаг ИЛИ есть физический договор
    $checkboxChecked = ($hasRealContract || $isSignedFlag);
    
    // НАМЕРТВО ИСПРАВЛЕНО: JS-маркер равен 1 строго при наличии РЕАЛЬНОГО физического договора в projects!
    $jsContractFlag = $hasRealContract ? 1 : 0;
    ?>
    <input type="checkbox" 
           id="contract_signed_<?= $currentClientId ?>"
           data-has-contract="<?= $jsContractFlag ?>"
           <?= $checkboxChecked ? 'checked' : '' ?> 
           onchange="toggleContractStatus(<?= $currentClientId ?>, event, <?= $jsContractFlag ?>)"
           style="cursor: pointer; width: 16px; height: 16px; position: relative; z-index: 10;">
           <script>
            window.toggleContractStatus = async function(clientId, event, dbHasContract) {
    // Если clientId не передан, пытаемся взять из data-id строки
    let checkbox = event?.target || document.getElementById('contract_signed_' + clientId);
    if (!checkbox) {
        console.error('❌ Чекбокс не найден');
        return;
    }
    if (!clientId || clientId === 0) {
        const tr = checkbox.closest('tr');
        if (tr && tr.dataset.id) {
            clientId = parseInt(tr.dataset.id, 10);
        }
    }
    if (!clientId || clientId === 0) {
        alert('❌ Не удалось определить ID клиента');
        return;
    }

    const isChecked = checkbox.checked;
    const hasRealContract = dbHasContract === 1;

    // Если пытаемся снять галочку, а есть реальный договор – предупреждаем
    if (!isChecked && hasRealContract) {
        if (!confirm("⚠️ У клиента есть активный договор! Снять галочку?")) {
            checkbox.checked = true;
            return;
        }
    }

    // Блокируем чекбокс на время запроса
    checkbox.disabled = true;
    checkbox.style.accentColor = '#f59e0b';

    try {
        const res = await fetch('update_cell.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: clientId, field: 'is_contract_signed', value: isChecked ? 1 : 0 })
        });
        const data = await res.json();

        if (data.status === 'error') {
            alert('❌ ' + data.message);
            checkbox.checked = !isChecked;
        } else {
            if (isChecked) {
                showToast('✅ Галочка установлена, черновик создан', 'success');
                setTimeout(() => {
                    window.location.href = 'contracts.php?auto_open_client_id=' + clientId;
                }, 500);
            } else {
                // После снятия галочки обновляем страницу, чтобы обновить список
                window.location.reload();
            }
        }
    } catch (err) {
        console.error('Ошибка:', err);
        alert('❌ Ошибка связи с сервером');
        checkbox.checked = !isChecked;
    } finally {
        checkbox.disabled = false;
        checkbox.style.accentColor = '';
    }
};

           </script>
</td>
        <script>
// НАМЕРТВО ИСПРАВЛЕНО: Чистое фоновое переключение статуса контракта в СУБД Santeks
// ИСПРАВЛЕНО: Безопасная работа с глобальной переменной
// =========================================================================
// НАМЕРТВО ИСПРАВЛЕНО: Чистое фоновое переключение статуса контракта
// =========================================================================

// =========================================================================
// ФУНКЦИЯ ПОКАЗА УВЕДОМЛЕНИЙ (TOAST)
// =========================================================================
function showToast(message, type = 'info') {
    // Удаляем старый тост
    const oldToast = document.getElementById('crm-toast');
    if (oldToast) oldToast.remove();
    
    // Создаем новый
    const toast = document.createElement('div');
    toast.id = 'crm-toast';
    toast.style.cssText = `
        position: fixed;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        padding: 15px 25px;
        border-radius: 8px;
        font-family: sans-serif;
        font-size: 14px;
        font-weight: 600;
        z-index: 999999;
        box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        animation: slideUpToast 0.3s ease-out;
        max-width: 90%;
        text-align: center;
    `;
    
    // Цвета в зависимости от типа
    const colors = {
        success: { bg: '#10b981', text: '#fff' },
        warning: { bg: '#f59e0b', text: '#1e293b' },
        error: { bg: '#ef4444', text: '#fff' },
        info: { bg: '#3b82f6', text: '#fff' }
    };
    
    const color = colors[type] || colors.info;
    toast.style.background = color.bg;
    toast.style.color = color.text;
    toast.textContent = message;
    
    document.body.appendChild(toast);
    
    // Автоматическое скрытие через 5 секунд
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 5000);
}

// Добавляем CSS-анимацию для тоста
const style = document.createElement('style');
style.textContent = `
    @keyframes slideUpToast {
        from { opacity: 0; transform: translateX(-50%) translateY(20px); }
        to { opacity: 1; transform: translateX(-50%) translateY(0); }
    }
`;
document.head.appendChild(style);

// Функция closeContractModal остается для обратной совместимости, если где-то вызывается темой
function closeContractModal() {
    if (window.activeContractCheckbox) {
        window.activeContractCheckbox.checked = false;
        window.activeContractCheckbox = null;
    }
}
</script>

<td>
<!-- МАКСИМАЛЬНО ПРОСТАЯ И НАДЕЖНАЯ КНОПКА РЕДАКТИРОВАНИЯ -->
<?php 
// Жесткое условие блокировки кнопки "Ред.": контракт подписан и роль равна менеджеру
$isComplexLock = ((int)($c['is_contract_signed'] ?? 0) === 1 && $userRole === 'manager'); 
?>
<button type="button" 
        class="btn-edit"
        onclick="<?= $isComplexLock ? "alert('⚠️ Доступ ограничен: Карточка заблокирована для редактирования, так как по ней уже заключен договор! Обратитесь к Администратору.'); return false;" : "openProtectedEditModal(" . (int)$c['id'] . "); return false;" ?>"
        style="background: <?= $isComplexLock ? '#3f3f46' : '#4f46e5' ?>; color: <?= $isComplexLock ? '#92929f' : 'white' ?>; border: none; padding: 4px 10px; border-radius: 4px; cursor: <?= $isComplexLock ? 'not-allowed' : 'pointer' ?>; font-size: 12px; font-weight: bold;"
        title="<?= $isComplexLock ? 'Редактирование запрещено! Карточка создана в связке с договором.' : 'Редактировать личные данные клиента' ?>">
    <?= $isComplexLock ? '🔒 Ред.' : '✏️ Ред.' ?>


</button>

</td>

            <td class="cell-source" style="display:none;"><?= htmlspecialchars($c['source']) ?></td>
      
      <td style="padding: 12px 10px; text-align: center; vertical-align: middle; border: none !important; box-sizing: border-box; width: 110px;">
    <?php 
    $fileName = trim($c['kp_file'] ?? '');
    
    if (!empty($fileName) && $fileName !== 'NULL' && $fileName !== 'null'): 
    ?>
        <!-- Если файл есть в СУБД — выводим зеленую неоновую кнопку прямой загрузки -->
        <a href="uploads/kp/<?= htmlspecialchars($fileName) ?>" 
           target="_blank" 
           title="Открыть коммерческое предложение в новой вкладке"
           style="background: rgba(16, 185, 129, 0.1); border: 1px solid #10b981; color: #10b981; padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: bold; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; transition: all 0.15s;"
           onmouseover="this.style.background='rgba(16, 185, 129, 0.2)';"
           onmouseout="this.style.background='rgba(16, 185, 129, 0.1)';"
           onclick="event.stopPropagation();">
            📎 Скан
        </a>
    <?php else: ?>
        <!-- Если файла нет — аккуратный серый прочерк -->
        <span style="color: #32324d; font-size: 13px;">—</span>
    <?php endif; ?>
</td>
  </tr>
        <!-- НАМЕРТВО ИСПРАВЛЕНО: Интерактивный вывод и скачивание скана КП прямо из сетки таблицы -->


        <?php endforeach; ?>
    </tbody>
    
</table>
</div>
</div>
</div>

<!-- ============================================================ -->
<!-- МОДАЛЬНОЕ ОКНО РЕДАКТИРОВАНИЯ КЛИЕНТА (ЧИСТАЯ ВЕРСИЯ) -->
<!-- ============================================================ -->
<div id="clientModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.75); justify-content: center; align-items: center; z-index: 99999; box-sizing: border-box; padding: 20px;">
    
    <div style="background: #1e1e2d; border-radius: 16px; border: 1px solid #323248; padding: 35px 40px; width: 95%; max-width: 1300px; max-height: 92vh; overflow-y: auto; box-sizing: border-box; box-shadow: 0 10px 40px rgba(0,0,0,0.5);">
        
        <!-- ЗАГОЛОВОК -->
        <h2 id="modalTitle" style="margin: 0 0 20px 0; font-size: 18px; color: #fff; text-align: left;">Редактирование клиента</h2>
        
        <!-- ФОРМА -->
        <form id="clientForm" method="POST" enctype="multipart/form-data" style="margin: 0; padding: 0;">
            
            <!-- Скрытые поля -->
            <input type="hidden" id="client_id" name="client_id" value="0">
            
            <!-- ========================================== -->
            <!-- БЛОК 1: НАЗВАНИЕ, УНП, САЙТ -->
            <!-- ========================================== -->
            <div style="display: grid; grid-template-columns: 2fr 1fr 1.5fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Название *</label>
                    <input type="text" id="client_name" name="client_name" required placeholder="ООО СантехМонтаж" style="width: 100%; height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; box-sizing: border-box;">
                </div>
               <div style="flex: 2; display: flex; flex-direction: column; gap: 6px; min-width: 120px; position: relative;">
    <label style="font-size: 10px; color: #92929f; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3px;">УНП Контрагента *</label>
    <div style="display: flex; gap: 8px; align-items: center;">
          <input type="text" name="unp" id="unp" placeholder="9 знаков" maxlength="9" style="height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; width: 100%; box-sizing: border-box;"> 

        <button type="button" id="skipUnpBtn" onclick="bypassUnpCheck()" 
                style="display: none; height: 38px; padding: 0 12px; background: #f59e0b; border: none; color: #1e293b; border-radius: 6px; font-size: 11px; font-weight: bold; cursor: pointer; white-space: nowrap;">
            🔓 Пропустить
        </button>
    </div>
    <div id="js-unp-error-block" style="display:none; font-size:10px; color:#ef4444; font-weight:600;">
        <span>⚠️ УНП уже зарегистрирован (<strong id="js-duplicate-name-span">Имя</strong>)!</span>
        <button type="button" onclick="bypassUnpCheck()" style="background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: #10b981; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 800; cursor: pointer;">
            🔓 Пропустить как филиал
        </button>
    </div>
</div>
                <div>
                    <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Сайт</label>
                    <input type="text" id="client_website" name="website" placeholder="example.com" style="width: 100%; height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; box-sizing: border-box;">
                </div>
            </div>
            
            <!-- ========================================== -->
            <!-- БЛОК 2: КОНТАКТНОЕ ЛИЦО, ТЕЛЕФОН, EMAIL -->
            <!-- ========================================== -->
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Контактное лицо</label>
                    <input type="text" id="contact_person" name="contact_person" placeholder="Иванов И.И." style="width: 100%; height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; box-sizing: border-box;">
                </div>
                <div>
                    <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Телефон</label>
                    <input type="text" id="phone" name="phone" placeholder="+375..." style="width: 100%; height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; box-sizing: border-box;">
                </div>
                <div>
                    <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Email</label>
                    <input type="email" id="email" name="email" placeholder="info@mail.com" style="width: 100%; height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; box-sizing: border-box;">
                </div>
            </div>
            
            <!-- ========================================== -->
            <!-- БЛОК 3: ДАТЫ -->
            <!-- ========================================== -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Первый контакт</label>
                    <input type="date" id="first_contact_date" name="first_contact_date" readonly style="width: 100%; height: 38px; padding: 0 12px; background: #1a1a26; border: 1px solid #323248; color: #707084; border-radius: 6px; outline: none; font-size: 13px; box-sizing: border-box; cursor: not-allowed;">
                </div>
                <div>
                    <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Следующий контакт *</label>
                    <input type="date" id="next_contact_date" name="next_contact_date" required style="width: 100%; height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; box-sizing: border-box; color-scheme: dark;">
                </div>
            </div>
            
            <!-- ========================================== -->
            <!-- БЛОК 4: СТАТУС И ИСТОЧНИК -->
            <!-- ========================================== -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Статус</label>
                    <select id="status" name="status" style="width: 100%; height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; box-sizing: border-box;">
                        <option value="Новый">Новый</option>
                        <option value="Текущий">Текущий</option>
                        <option value="Отказ">Отказ</option>
                        <option value="Потенциальный">Потенциальный</option>
                    </select>
                </div>
                <div>
                    <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Источник</label>
                    <select id="source" name="source" style="width: 100%; height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; box-sizing: border-box;">
                        <option value="Холодный поиск">Холодный поиск</option>
                        <option value="Запрос">Запрос</option>
                        <option value="Закупки">Закупки</option>
                        <option value="Связка">Связка</option>
                    </select>
                </div>
            </div>
            
            <!-- ========================================== -->
            <!-- БЛОК 5: ПРОДУКЦИЯ (ЧЕКБОКСЫ) -->
            <!-- ========================================== -->
            <div style="margin-bottom: 15px;">
                <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; display: block; margin-bottom: 8px;">Виды продукции</label>
                <div style="background: #151521; border: 1px solid #323248; border-radius: 6px; padding: 10px; max-height: 120px; overflow-y: auto; display: grid; grid-template-columns: 1fr 1fr; gap: 6px;">
                    <?php foreach (['Посуда', 'Сантехника', 'ЕКМ', 'МПДУ', 'Резервуары', 'УОКТ', 'Прочее'] as $p): ?>
                        <label style="display: flex; align-items: center; gap: 8px; color: #fff; font-size: 13px; cursor: pointer;">
                            <input type="checkbox" name="product_type[]" value="<?= $p ?>" class="js-product-cb" style="accent-color: #818cf8; cursor: pointer;">
                            <?= $p ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- ========================================== -->
            <!-- БЛОК 6: КОММЕНТАРИЙ -->
            <!-- ========================================== -->
            <div style="margin-bottom: 15px;">
                <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; display: block; margin-bottom: 8px;">Комментарий</label>
           <textarea id="comment" name="comment" rows="3" placeholder="Ваш комментарий..." style="width: 100%; padding: 10px 14px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; resize: vertical; box-sizing: border-box; font-family: inherit;"></textarea>
            </div>
            
            <!-- ========================================== -->
            <!-- БЛОК 7: ЗАГРУЗКА ФАЙЛА -->
            <!-- ========================================== -->
            <div style="margin-bottom: 20px;">
                <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; display: block; margin-bottom: 8px;">Скан КП (PDF, JPG, PNG)</label>
                <input type="file" name="kp_file" accept=".pdf,.jpg,.jpeg,.png" style="width: 100%; height: 38px; padding: 6px 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; box-sizing: border-box; cursor: pointer;">
            </div>
            
            <!-- ========================================== -->
            <!-- КНОПКИ -->
            <!-- ========================================== -->
            <div style="display: flex; justify-content: flex-end; gap: 12px; border-top: 1px solid #323248; padding-top: 15px;">
                <button type="button" onclick="closeModal()" style="height: 38px; padding: 0 20px; background: rgba(255,255,255,0.05); border: 1px solid #323248; color: #92929f; border-radius: 6px; font-size: 13px; font-weight: bold; cursor: pointer;">Отмена</button>
                <button type="submit" style="height: 38px; padding: 0 24px; background: #4f46e5; border: none; color: #fff; border-radius: 6px; font-size: 13px; font-weight: bold; cursor: pointer;">Сохранить изменения</button>
            </div>
            
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- МОДАЛЬНОЕ ОКНО СВЯЗКИ (КЛИЕНТ + ДОГОВОР) -->
<!-- ============================================================ -->
<div id="complexModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); justify-content: center; align-items: center; z-index: 999999; box-sizing: border-box; padding: 15px;">
    <div class="modal-content" style="background: #1e1e2d; border-radius: 8px; border: 1px solid #323248; padding: 25px; width: 100%; max-width: 600px; box-sizing: border-box; box-shadow: 0 10px 30px rgba(0,0,0,0.5); color: #fff; font-family: sans-serif;"> 
        
        <h3 style="margin-top: 0; color: #fff; font-size: 16px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #323248; padding-bottom: 10px; margin-bottom: 20px;">
            🗂 Создание контрагента и договора в одной связке
        </h3>

        <form id="jointClientContractForm" style="margin: 0; padding: 0; display: flex; flex-direction: column; gap: 15px;">
            <input type="hidden" name="action" value="complex">

            <!-- РЯД 1: ДАННЫЕ ОРГАНИЗАЦИИ -->
            <div class="form-row">
                <div class="form-group" style="flex: 2;">
                    <label>Название компании *</label>
                    <input type="text" name="client_name" id="complex_client_name" required placeholder="ООО СантехМонтаж">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label>УНП / ИНН</label>
                    <input type="text" name="unp" id="complex_unp" placeholder="123456789" maxlength="9">
                    <span id="complex_unp_error_msg" style="display: none; font-size: 11px; color: #ef4444; font-weight: bold;"></span>
                </div>
            </div>

            <!-- РЯД 2: КОНТАКТЫ -->
            <div class="form-row">
                <div class="form-group">
                    <label>Телефон связи</label>
                    <input type="text" name="phone" id="complex_phone" placeholder="+375 (...)">
                </div>
                <div class="form-group">
                    <label>Контактное лицо</label>
                    <input type="text" name="contact_person" id="complex_contact_person" placeholder="Иванов И.И.">
                </div>
            </div>

            <!-- РЯД 3: ДОГОВОР -->
            <div class="form-row">
                <div class="form-group" style="flex: 2;">
                    <label>№ Договора *</label>
                    <input type="text" name="contract_number" id="complex_contract_number" required placeholder="Напр: 240/Т">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label>Дата заключения</label>
                    <input type="date" name="contract_date" id="complex_contract_date" required>
                </div>
            </div>
<!-- РЯД 4: ПРОДУКЦИЯ -->
<div class="form-group" style="width: 100%;">
    <label>Вид продукции</label>
    <div id="ct_type_multiselect_container" style="background: #151521; border: 1px solid #323248; border-radius: 6px; padding: 12px; max-height: 200px; overflow-y: auto; display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px 20px; box-sizing: border-box; width: 100%;">
        <?php $allProducts = ['Посуда', 'Сантехника', 'ЕКМ', 'МПДУ', 'Резервуары', 'УОКТ', 'Прочее']; ?>
        <?php foreach ($allProducts as $pOption): ?>
            <label style="display: flex; align-items: center; gap: 8px; color: #fff; font-size: 13px; cursor: pointer; user-select: none;">
                <input type="checkbox" name="ct_type[]" value="<?= htmlspecialchars($pOption) ?>" class="js-product-cb" style="width: 15px; height: 16px; cursor: pointer; accent-color: #818cf8;">
                <span><?= htmlspecialchars($pOption) ?></span>
            </label>
        <?php endforeach; ?>
        

</div>
            </div>

            <!-- БЛОК КНОПОК УПРАВЛЕНИЯ -->
            <div style="display: flex; gap: 10px; justify-content: flex-end; align-items: center; width: 100%; box-sizing: border-box; margin-top: 20px; border-top: 1px solid #323248; padding-top: 15px;">
                <button type="button" onclick="document.getElementById('complexModal').style.display = 'none';" style="height: 38px; padding: 0 20px; background: rgba(255,255,255,0.05); border: 1px solid #323248; color: #fff; border-radius: 6px; font-size: 13px; font-weight: bold; cursor: pointer;">Отмена</button>
                <button type="submit" style="height: 38px; padding: 0 22px; background: #059669; border: none; color: #fff; border-radius: 6px; font-size: 13px; font-weight: bold; cursor: pointer;">🚀 Создать связку</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- СКРИПТЫ УПРАВЛЕНИЯ МОДАЛКАМИ -->
<!-- ============================================================ -->
<script>
// ============================================================
// 1. ОТКРЫТИЕ МОДАЛКИ ДЛЯ ДОБАВЛЕНИЯ
// ============================================================
function openAddModal() {
    console.log('📝 ОТКРЫТИЕ ДОБАВЛЕНИЯ (НЕ РЕДАКТИРОВАНИЕ!)');
    
    const form = document.getElementById('clientForm');
    if (!form) {
        console.error('❌ Форма не найдена!');
        return;
    }
    
    // ✅ Очищаем ID - ДЛЯ ДОБАВЛЕНИЯ
    const idInput = document.getElementById('client_id');
    if (idInput) {
        idInput.value = '';
        console.log('✅ client_id очищен');
    }
    
    // ✅ Устанавливаем action для добавления
    form.action = 'create_client.php';
    
    // Сбрасываем все поля
    form.reset();
    document.getElementById('client_name').value = '';
    document.getElementById('unp').value = '';
    document.getElementById('client_website').value = '';
    document.getElementById('contact_person').value = '';
    document.getElementById('phone').value = '';
    document.getElementById('email').value = '';
    document.getElementById('comment').value = '';
    document.getElementById('status').value = 'Новый';
    document.getElementById('source').value = 'Запрос';
    
    // Сбрасываем галочки продукции
    document.querySelectorAll('.js-product-cb').forEach(cb => cb.checked = false);
    
    // Устанавливаем даты
    const dateInp = document.getElementById('first_contact_date');
    if(dateInp) {
        dateInp.value = new Date().toISOString().split('T')[0];
        dateInp.readOnly = false;
    }
    
    const nextDate = document.getElementById('next_contact_date');
    if(nextDate) {
        const d = new Date();
        d.setDate(d.getDate() + 7);
        nextDate.value = d.toISOString().split('T')[0];
    }
    
    document.getElementById('modalTitle').innerText = 'Добавить клиента';
    document.getElementById('clientModal').style.display = 'flex';
    console.log('✅ Модалка добавления открыта');
}

// ============================================================
// 2. ОТКРЫТИЕ МОДАЛКИ ДЛЯ РЕДАКТИРОВАНИЯ
// ============================================================
async function openProtectedEditModal(id) {
    console.log('📝 ОТКРЫТИЕ РЕДАКТИРОВАНИЯ #' + id);
    
    const modal = document.getElementById('clientModal');
    const form = document.getElementById('clientForm');
    
    if (!modal || !form) {
        console.error('❌ Модалка или форма не найдены!');
        return;
    }

    try {
        // Загружаем данные клиента
        const res = await fetch('get_client.php?id=' + parseInt(id, 10));
        if (!res.ok) throw new Error('Ошибка загрузки');
        
        const response = await res.json();
        if (response.status !== 'success' || !response.data) {
            throw new Error('Нет данных');
        }

        const c = response.data;
        console.log('✅ Данные клиента загружены:', c);

        // ✅ Устанавливаем action для редактирования
        form.action = 'update_client.php';

        // Заполняем поля
        document.getElementById('client_id').value = c.id;
        document.getElementById('modalTitle').innerText = 'Редактирование клиента #' + c.id;
        document.getElementById('client_name').value = c.client_name || '';
        document.getElementById('unp').value = c.unp || '';
        document.getElementById('client_website').value = c.website || '';
        document.getElementById('contact_person').value = c.contact_person || '';
        document.getElementById('phone').value = c.phone || '';
        document.getElementById('email').value = c.email || '';
        document.getElementById('comment').value = c.comment || '';
        document.getElementById('first_contact_date').value = c.first_contact_date || '';
        document.getElementById('next_contact_date').value = c.next_contact_date || '';
        document.getElementById('status').value = c.status || 'Новый';
        document.getElementById('source').value = c.source || 'Запрос';

        // Галочки продукции
        const productCheckboxes = document.querySelectorAll('.js-product-cb');
        if (productCheckboxes.length > 0) {
            productCheckboxes.forEach(cb => cb.checked = false);
            
            let products = [];
            const rawProduct = (c.product_type || '').toString().trim();
            
            if (rawProduct.startsWith('[') && rawProduct.endsWith(']')) {
                try { products = JSON.parse(rawProduct); } catch(e) { products = []; }
            } else if (rawProduct.includes(',')) {
                products = rawProduct.split(',').map(p => p.trim());
            } else if (rawProduct) {
                products = [rawProduct];
            }
            
            productCheckboxes.forEach(cb => {
                if (products.includes(cb.value)) {
                    cb.checked = true;
                }
            });
        }

        modal.style.display = 'flex';
        console.log('✅ Модалка редактирования открыта');

    } catch (err) {
        console.error('❌ Ошибка:', err);
        alert('Ошибка загрузки данных клиента!');
    }
}

// ============================================================
// 3. ЗАКРЫТИЕ МОДАЛКИ
// ============================================================
function closeModal() {
    const modal = document.getElementById('clientModal');
    if (modal) {
        modal.style.display = 'none';
        console.log('✅ Модалка закрыта');
    }
}

// ============================================================
// 4. ОТКРЫТИЕ МОДАЛКИ СВЯЗКИ
// ============================================================
function openComplexModal() {
    const modal = document.getElementById('complexModal');
    if (modal) {
        modal.style.display = 'flex';
        // Устанавливаем дату
        const dateInput = document.getElementById('complex_contract_date');
        if (dateInput) {
            dateInput.value = new Date().toISOString().split('T')[0];
        }
    }
}

// ============================================================
// 5. ЕДИНСТВЕННЫЙ ОБРАБОТЧИК ФОРМЫ (И ДЛЯ ДОБАВЛЕНИЯ, И ДЛЯ РЕДАКТИРОВАНИЯ)
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('clientForm');
    
    if (!form) {
        console.error('❌ Форма clientForm не найдена!');
        return;
    }

    console.log('✅ Форма clientForm найдена');
    
    // Удаляем все старые обработчики
    form.onsubmit = null;
    
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        console.log('📤 Отправка формы...');
        console.log('  ➜ action:', form.action);
        
        const fd = new FormData(this);
        const clientId = document.getElementById('client_id').value;
        
        console.log('  ➜ client_id:', clientId);
        
        // Определяем URL (используем action формы)
        const url = form.action;
        
        // Проверяем, что URL не пустой
        if (!url || url === '') {
            alert('❌ Ошибка: не указан URL для отправки!');
            return;
        }
        
        // Выводим все данные
        console.log('📦 Данные формы:');
        for (let pair of fd.entries()) {
            console.log('  ➜', pair[0], '=', pair[1]);
        }
        
        // Блокируем кнопку
        const submitBtn = this.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerText = '⏳ Сохранение...';
        }
        
        try {
            const res = await fetch(url, {
                method: 'POST',
                body: fd
            });
            
            console.log('📥 Статус:', res.status);
            
            const text = await res.text();
            console.log('📄 Ответ:', text);
            
            try {
                const data = JSON.parse(text);
                if (data.status === 'success') {
                    console.log('✅ Успешно сохранено!');
                    closeModal();
                    window.location.reload();
                } else {
                    alert('❌ Ошибка: ' + (data.message || 'Неизвестная ошибка'));
                }
            } catch(e) {
                console.error('❌ Не JSON:', text);
                alert('❌ Ошибка сервера! Смотрите консоль F12.');
            }
        } catch(err) {
            console.error('❌ Ошибка сети:', err);
            alert('❌ Ошибка соединения с сервером!');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerText = 'Сохранить изменения';
            }
        }
    });
});

// ============================================================
// 6. ПЕРЕХВАТ ОШИБОК
// ============================================================
window.onerror = function(msg, url, line, col, error) {
    console.warn('⚠️ Поймана ошибка (подавлена):', msg);
    return true;
};

window.addEventListener('unhandledrejection', function(e) {
    console.warn('⚠️ Поймана Promise-ошибка (подавлена):', e.reason);
    e.preventDefault();
});

console.log('🛡️ Перехват ошибок включен!');


// ============================================================
// КАРТОЧКА КЛИЕНТА - УПРАВЛЕНИЕ
// ============================================================
let currentCardClientId = 0;

// ОТКРЫТИЕ КАРТОЧКИ
async function openClientCard(clientId) {
    console.log('📇 Открытие карточки клиента #' + clientId);
    currentCardClientId = clientId;
    
    const modal = document.getElementById('clientCardModal');
    if (!modal) {
        console.error('❌ Карточка не найдена!');
        return;
    }
    
    // Показываем загрузку
    document.getElementById('cardClientName').innerText = 'Загрузка...';
    modal.style.display = 'flex';
    
    try {
        // Загружаем данные клиента через get_client.php
        const res = await fetch('get_client.php?id=' + clientId);
        if (!res.ok) throw new Error('Ошибка загрузки');
        
        const response = await res.json();
        if (response.status !== 'success' || !response.data) {
            throw new Error('Нет данных');
        }
        
        const c = response.data;
        console.log('✅ Данные клиента загружены:', c);
        
        // ============================================================
        // ЗАПОЛНЯЕМ КАРТОЧКУ
        // ============================================================
        
        // Шапка
        document.getElementById('cardClientName').innerText = c.client_name || 'Без названия';
        document.getElementById('cardClientId').innerText = 'ID: ' + c.id;
        document.getElementById('cardClientUnp').innerText = 'УНП: ' + (c.unp || '—');
        
        const statusEl = document.getElementById('cardClientStatus');
        statusEl.innerText = c.status || 'Новый';
        statusEl.style.color = getStatusColor(c.status);
        
        document.getElementById('cardClientManager').innerText = 'Менеджер: ' + (c.manager_name || 'Не назначен');
        
        // Контакты
        document.getElementById('cardClientPhone').innerText = c.phone || '—';
        document.getElementById('cardClientEmail').innerText = c.email || '—';
        document.getElementById('cardClientWebsite').innerText = c.website || '—';
        document.getElementById('cardClientFirstContact').innerText = c.first_contact_date || '—';
        document.getElementById('cardClientComment').innerText = c.comment || '—';
        
        // ============================================================
        // КОНТАКТНЫЕ ЛИЦА
        // ============================================================
        const contactsContainer = document.getElementById('cardContactsList');
        contactsContainer.innerHTML = '';
        
        if (c.contacts && Array.isArray(c.contacts) && c.contacts.length > 0) {
            // Фильтруем главный контакт (is_main = 1)
            const filteredContacts = c.contacts.filter(contact => !contact.is_main);
            
            if (filteredContacts.length > 0) {
                filteredContacts.forEach(contact => {
                    const div = document.createElement('div');
                    div.style.cssText = 'background: #151521; border: 1px solid #2a2a3f; border-radius: 8px; padding: 10px 14px;';
                    div.innerHTML = `
                        <div style="font-weight: 600; color: #fff; font-size: 13px;">👤 ${contact.name || 'Без имени'}</div>
                        ${contact.position ? `<div style="color: #6b6b85; font-size: 12px;">💼 ${contact.position}</div>` : ''}
                        ${contact.phone ? `<div style="color: #818cf8; font-size: 12px; font-family: monospace;">📞 ${contact.phone}</div>` : ''}
                        ${contact.email ? `<div style="color: #cbd5e1; font-size: 12px;">✉️ ${contact.email}</div>` : ''}
                    `;
                    contactsContainer.appendChild(div);
                });
            } else {
                contactsContainer.innerHTML = '<div style="color: #4b4b5e; font-size: 13px; padding: 10px; text-align: center;">Контактные лица не добавлены</div>';
            }
        } else {
            contactsContainer.innerHTML = '<div style="color: #4b4b5e; font-size: 13px; padding: 10px; text-align: center;">Контактные лица не добавлены</div>';
        }
        
        // ============================================================
        // ДОГОВОРЫ
        // ============================================================
        const contractsContainer = document.getElementById('cardContractsList');
        contractsContainer.innerHTML = '';
        
        if (c.contracts && Array.isArray(c.contracts) && c.contracts.length > 0) {
            let totalAmount = 0;
            c.contracts.forEach(contract => {
                const amt = parseFloat(contract.total_amount || 0);
                totalAmount += amt;
                
                const div = document.createElement('div');
                div.style.cssText = 'background: #151521; border: 1px solid #2a2a3f; border-radius: 8px; padding: 10px 14px; display: flex; justify-content: space-between; align-items: center;';
                div.innerHTML = `
                    <div>
                        <span style="font-weight: 600; color: #10b981;">№${contract.contract_number || '—'}</span>
                        <span style="color: #6b6b85; font-size: 12px; margin-left: 12px;">📅 ${contract.contract_date || '—'}</span>
                        <span style="color: #6b6b85; font-size: 12px; margin-left: 12px;">${contract.product_type || '—'}</span>
                    </div>
                    <div style="font-weight: 700; color: #10b981; font-family: monospace;">
                        ${amt.toFixed(2)} ${contract.currency || 'BYN'}
                    </div>
                `;
                contractsContainer.appendChild(div);
            });
            
            // Итог по договорам
            const totalDiv = document.createElement('div');
            totalDiv.style.cssText = 'background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.15); border-radius: 8px; padding: 10px 14px; display: flex; justify-content: space-between; align-items: center;';
            totalDiv.innerHTML = `
                <span style="color: #92929f; font-weight: 600;">💰 Итого по всем договорам</span>
                <span style="font-weight: 700; color: #10b981; font-size: 16px; font-family: monospace;">
                    ${totalAmount.toFixed(2)} BYN
                </span>
            `;
            contractsContainer.appendChild(totalDiv);
            
        } else {
            contractsContainer.innerHTML = '<div style="color: #4b4b5e; font-size: 13px; padding: 10px; text-align: center;">Договоров пока нет</div>';
        }
        
        console.log('✅ Карточка клиента загружена!');
        
    } catch (err) {
        console.error('❌ Ошибка загрузки карточки:', err);
        document.getElementById('cardClientName').innerText = 'Ошибка загрузки';
        alert('Ошибка загрузки данных клиента!');
    }
}

// ============================================================
// ЗАКРЫТИЕ КАРТОЧКИ
// ============================================================
function closeClientCard() {
    const modal = document.getElementById('clientCardModal');
    if (modal) {
        modal.style.display = 'none';
        console.log('✅ Карточка закрыта');
    }
}

// ============================================================
// ДЕЙСТВИЯ ИЗ КАРТОЧКИ
// ============================================================
function openEditFromCard() {
    if (currentCardClientId > 0) {
        closeClientCard();
        openProtectedEditModal(currentCardClientId);
    }
}

function openContractFromCard() {
    if (currentCardClientId > 0) {
        closeClientCard();
        // Находим кнопку с данными клиента и открываем модалку договора
        const row = document.querySelector(`tr[data-id="${currentCardClientId}"]`);
        if (row) {
            const btn = row.querySelector('.btn-add-contract') || row.querySelector('button[data-client-id]');
            if (btn) {
                openContractModalFromRow(btn);
            } else {
                // Если кнопки нет - перенаправляем на страницу договоров
                window.location.href = 'contracts.php?auto_open_client_id=' + currentCardClientId;
            }
        }
    }
}

function openTtnFromCard() {
    if (currentCardClientId > 0) {
        closeClientCard();
        // Ищем договоры клиента и переходим к ТТН
        window.location.href = 'contracts.php?open_ttn_for_client=' + currentCardClientId;
    }
}

// ============================================================
// ЦВЕТ СТАТУСА
// ============================================================
function getStatusColor(status) {
    const colors = {
        'Новый': '#818cf8',
        'Текущий': '#f59e0b',
        'Отказ': '#ef4444',
        'Потенциальный': '#10b981'
    };
    return colors[status] || '#6b6b85';
}

// ============================================================
// ЗАКРЫТИЕ ПО ESC
// ============================================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const card = document.getElementById('clientCardModal');
        if (card && card.style.display === 'flex') {
            closeClientCard();
        }
    }
});


// Авторасширение текстовых полей
document.querySelectorAll('textarea').forEach(textarea => {
    textarea.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = this.scrollHeight + 'px';
    });
});
</script>
<!-- ============================================================ -->
<!-- КАРТОЧКА КЛИЕНТА (ТОЛЬКО ПРОСМОТР) -->
<!-- ============================================================ -->
<div id="clientCardModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); justify-content: center; align-items: center; z-index: 999999; padding: 20px; backdrop-filter: blur(6px);">
    <div style="background: #1e1e2d; border-radius: 20px; border: 1px solid #323248; padding: 0; width: 750px; max-width: 100%; max-height: 95vh; overflow-y: auto; box-shadow: 0 30px 60px rgba(0,0,0,0.6);">
        
        <!-- ШАПКА -->
        <div style="background: linear-gradient(135deg, #1a1a32, #242448); padding: 25px 30px; border-radius: 20px 20px 0 0; border-bottom: 2px solid #323248; position: sticky; top: 0; z-index: 10;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                <div style="flex: 1;">
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
                        <span style="font-size: 28px;">🏢</span>
                        <h2 id="cardClientName" style="margin: 0; font-size: 22px; font-weight: 700; color: #fff;">Загрузка...</h2>
                    </div>
                    <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                        <span id="cardClientStatus" style="background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.2); color: #10b981; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;">—</span>
                        <span id="cardClientUnp" style="color: #6b6b85; font-size: 13px; font-family: monospace;">УНП: —</span>
                        <span id="cardClientId" style="color: #4b4b5e; font-size: 12px;">ID: —</span>
                        <span id="cardClientManager" style="color: #6b6b85; font-size: 12px;">Менеджер: —</span>
                    </div>
                </div>
                <button onclick="closeClientCard()" style="background: rgba(255,255,255,0.05); border: 1px solid #323248; color: #92929f; padding: 8px 16px; border-radius: 8px; cursor: pointer; font-size: 13px; transition: 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.1)'" onmouseout="this.style.background='rgba(255,255,255,0.05)'">✕ Закрыть</button>
            </div>
        </div>
        
        <!-- ТЕЛО -->
        <div style="padding: 25px 30px;">
            <!-- Контакты -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; padding-bottom: 20px; border-bottom: 1px solid #2a2a3f; margin-bottom: 20px;">
                <div><label style="font-size: 10px; color: #6b6b85; text-transform: uppercase; font-weight: 700;">📞 Телефон</label><div id="cardClientPhone" style="color: #e2e8f0; font-size: 14px;">—</div></div>
                <div><label style="font-size: 10px; color: #6b6b85; text-transform: uppercase; font-weight: 700;">✉️ Email</label><div id="cardClientEmail" style="color: #e2e8f0; font-size: 14px;">—</div></div>
                <div><label style="font-size: 10px; color: #6b6b85; text-transform: uppercase; font-weight: 700;">🌐 Сайт</label><div id="cardClientWebsite" style="color: #818cf8; font-size: 14px;">—</div></div>
                <div><label style="font-size: 10px; color: #6b6b85; text-transform: uppercase; font-weight: 700;">📅 Первый контакт</label><div id="cardClientFirstContact" style="color: #e2e8f0; font-size: 14px;">—</div></div>
            </div>
            
            <!-- Контактные лица -->
            <div style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #2a2a3f;">
                <h4 style="margin: 0 0 12px 0; font-size: 13px; color: #818cf8; text-transform: uppercase;">👥 Контактные лица</h4>
                <div id="cardContactsList" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;"></div>
            </div>
            
            <!-- Договоры -->
            <div style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #2a2a3f;">
                <h4 style="margin: 0 0 12px 0; font-size: 13px; color: #10b981; text-transform: uppercase;">📄 Договоры</h4>
                <div id="cardContractsList" style="display: flex; flex-direction: column; gap: 8px;"></div>
            </div>
            
            <!-- Комментарий -->
            <div style="margin-bottom: 20px;">
                <h4 style="margin: 0 0 8px 0; font-size: 13px; color: #f59e0b; text-transform: uppercase;">📝 Комментарий</h4>
                <div id="cardClientComment" style="background: #151521; border: 1px solid #2a2a3f; border-radius: 8px; padding: 12px 16px; color: #cbd5e1; font-size: 13px; line-height: 1.6; min-height: 40px; white-space: pre-wrap;">—</div>
            </div>
        </div>
    </div>
</div>
<script>
    // ============================================================
// ОТКРЫТИЕ КАРТОЧКИ КЛИЕНТА
// ============================================================
async function openClientCard(clientId) {
    console.log('📇 Открытие карточки клиента #' + clientId);
    const modal = document.getElementById('clientCardModal');
    if (!modal) return;

    // Показываем загрузку
    document.getElementById('cardClientName').innerText = 'Загрузка...';
    modal.style.display = 'flex';

    try {
        const res = await fetch('get_client.php?id=' + clientId);
        if (!res.ok) throw new Error('Ошибка загрузки');
        const response = await res.json();
        if (response.status !== 'success' || !response.data) {
            alert('Не удалось загрузить данные клиента');
            return;
        }
        const c = response.data;

        // Заполняем шапку
        document.getElementById('cardClientName').innerText = c.client_name || 'Без названия';
        document.getElementById('cardClientId').innerText = 'ID: ' + c.id;
        document.getElementById('cardClientUnp').innerText = 'УНП: ' + (c.unp || '—');
        document.getElementById('cardClientStatus').innerText = c.status || 'Новый';
        document.getElementById('cardClientManager').innerText = 'Менеджер: ' + (c.manager_name || 'Не назначен');

        // Контакты
        document.getElementById('cardClientPhone').innerText = c.phone || '—';
        document.getElementById('cardClientEmail').innerText = c.email || '—';
        document.getElementById('cardClientWebsite').innerText = c.website || '—';
        document.getElementById('cardClientFirstContact').innerText = c.first_contact_date || '—';
        document.getElementById('cardClientComment').innerText = c.comment || '—';

        // Контактные лица
        const contactsContainer = document.getElementById('cardContactsList');
        contactsContainer.innerHTML = '';
        if (c.contacts && c.contacts.length > 0) {
            c.contacts.forEach(contact => {
                const div = document.createElement('div');
                div.style.cssText = 'background: #151521; border: 1px solid #2a2a3f; border-radius: 8px; padding: 10px 14px;';
                div.innerHTML = `
                    <div style="font-weight: 600; color: #fff; font-size: 13px;">👤 ${contact.name || 'Без имени'}</div>
                    ${contact.position ? `<div style="color: #6b6b85; font-size: 12px;">💼 ${contact.position}</div>` : ''}
                    ${contact.phone ? `<div style="color: #818cf8; font-size: 12px; font-family: monospace;">📞 ${contact.phone}</div>` : ''}
                    ${contact.email ? `<div style="color: #cbd5e1; font-size: 12px;">✉️ ${contact.email}</div>` : ''}
                `;
                contactsContainer.appendChild(div);
            });
        } else {
            contactsContainer.innerHTML = '<div style="color: #4b4b5e; font-size: 13px; padding: 10px; text-align: center;">Контактные лица не добавлены</div>';
        }

        // Договоры
        const contractsContainer = document.getElementById('cardContractsList');
        contractsContainer.innerHTML = '';
        if (c.contracts && c.contracts.length > 0) {
            let total = 0;
            c.contracts.forEach(contract => {
                const amt = parseFloat(contract.total_amount || 0);
                total += amt;
                const div = document.createElement('div');
                div.style.cssText = 'background: #151521; border: 1px solid #2a2a3f; border-radius: 8px; padding: 10px 14px; display: flex; justify-content: space-between; align-items: center;';
                div.innerHTML = `
                    <div>
                        <span style="font-weight: 600; color: #10b981;">№${contract.contract_number || '—'}</span>
                        <span style="color: #6b6b85; font-size: 12px; margin-left: 12px;">📅 ${contract.contract_date || '—'}</span>
                        <span style="color: #6b6b85; font-size: 12px; margin-left: 12px;">${contract.product_type || '—'}</span>
                    </div>
                    <div style="font-weight: 700; color: #10b981; font-family: monospace;">${amt.toFixed(2)} ${contract.currency || 'BYN'}</div>
                `;
                contractsContainer.appendChild(div);
            });
            // Итого
            const totalDiv = document.createElement('div');
            totalDiv.style.cssText = 'background: rgba(16,185,129,0.05); border: 1px solid rgba(16,185,129,0.15); border-radius: 8px; padding: 10px 14px; display: flex; justify-content: space-between; align-items: center;';
            totalDiv.innerHTML = `<span style="color: #92929f; font-weight: 600;">💰 Итого по договорам</span><span style="font-weight: 700; color: #10b981; font-size: 16px; font-family: monospace;">${total.toFixed(2)} BYN</span>`;
            contractsContainer.appendChild(totalDiv);
        } else {
            contractsContainer.innerHTML = '<div style="color: #4b4b5e; font-size: 13px; padding: 10px; text-align: center;">Договоров пока нет</div>';
        }

    } catch (err) {
        console.error('Ошибка карточки:', err);
        document.getElementById('cardClientName').innerText = 'Ошибка загрузки';
        alert('Не удалось загрузить данные');
    }
}

// ============================================================
// ЗАКРЫТИЕ КАРТОЧКИ
// ============================================================
function closeClientCard() {
    document.getElementById('clientCardModal').style.display = 'none';
}

// ============================================================
// ЗАКРЫТИЕ ПО ESC
// ============================================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('clientCardModal');
        if (modal && modal.style.display === 'flex') {
            closeClientCard();
        }
    }
});
// ============================================================
// ЗАКРЫТИЕ ПО КЛИКУ НА ПОДЛОЖКЕ
// ============================================================
document.getElementById('clientCardModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeClientCard();
});
    </script>  
</body>
</html>