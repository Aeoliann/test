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
    header("Location: login.html");
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
$productFilter = isset($_GET['product_type']) ? trim($_GET['product_type']) : '';
$dateFilter    = isset($_GET['next_contact_date_filter']) ? trim($_GET['next_contact_date_filter']) : '';
// 2. Очищаем дефолтные текстовые заглушки, чтобы они не летели в базу данных
if ($sourceFilter === 'Все источники') $sourceFilter = '';
if ($statusFilter === 'Все статусы')   $statusFilter = '';
if ($productFilter === 'Все виды')     $productFilter = '';


// ИСПРАВЛЕНО: Запрос на главной теперь подтягивает реальный тип продукции ИЗ ДОГОВОРА (project_product_type)
if ($userRole === 'admin') {
    if ($current_tab === 'refused') {
        $sql = "SELECT c.*, p.product_type AS project_product_type FROM clients c LEFT JOIN projects p ON c.id = p.client_id WHERE c.status = 'Отказ'";
    } else {
        $sql = "SELECT c.*, p.product_type AS project_product_type FROM clients c LEFT JOIN projects p ON c.id = p.client_id WHERE c.status != 'Отказ'";
    }
    $params = [];
} else {
    if ($current_tab === 'refused') {
        $sql = "SELECT c.*, p.product_type AS project_product_type FROM clients c LEFT JOIN projects p ON c.id = p.client_id WHERE c.manager_id = ? AND c.status = 'Отказ'";
    } else {
        $sql = "SELECT c.*, p.product_type AS project_product_type FROM clients c LEFT JOIN projects p ON c.id = p.client_id WHERE c.manager_id = ? AND c.status != 'Отказ'";
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
        $sql .= " AND product_type = ?";
        $params[] = $productFilter;
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
            SUM(CASE WHEN status = 'Текущий' THEN 1 ELSE 0 END) as in_work,
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
// ИСПРАВЛЕНО НАМЕРТВО: Именованный безопасный INSERT лида с жестким контролем product_type
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_new_client') {
    $client_name    = trim($_POST['client_name'] ?? '');
    $unp            = trim($_POST['unp'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $phone          = trim($_POST['phone'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $status         = trim($_POST['status'] ?? 'Новый');
    $source         = trim($_POST['source'] ?? '');
    $manager_id     = (int)($_POST['manager_id'] ?? $userId);
    
    // Перехватываем выбранную продукцию (УОКТ, ЕКМ и т.д.)
    $product_type   = trim($_POST['product_type'] ?? 'Сантехника');

    if (!empty($client_name)) {
        try {
            // --- НАЧАЛО ФИКСА БАГА: ПРОВЕРКА НА ДУБЛИКАТЫ ---
            $check_sql = "SELECT COUNT(*) FROM clients WHERE client_name = :client_name AND unp = :unp";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([
                ':client_name' => $client_name,
                ':unp'         => $unp
            ]);
            
            if ($check_stmt->fetchColumn() > 0) {
                // Если дубликат найден, выводим красивую ошибку и останавливаем скрипт
                die("<div style='background: #f8d7da; color: #721c24; padding: 20px; border: 1px solid #f5c6cb; border-radius: 6px; margin: 20px; font-family: sans-serif;'>
                        <strong>⚠️ Ошибка дублирования данных:</strong> Контрагент с названием «" . htmlspecialchars($client_name) . "» и УНП «" . htmlspecialchars($unp) . "» уже существует в системе! 
                        <br><br><a href='index.php' style='color: #721c24; font-weight: bold;'>Вернуться назад</a>
                     </div>");
            }
            // --- КОНЕЦ ФИКСА БАГА ---

            // Используем именованные параметры :name вместо знаков вопроса, чтобы исключить путаницу мест!
            $sql = "INSERT INTO clients (
                        client_name, 
                        unp, 
                        contact_person, 
                        phone, 
                        email, 
                        status, 
                        source, 
                        manager_id, 
                        product_type
                    ) VALUES (
                        :client_name, 
                        :unp, 
                        :contact_person, 
                        :phone, 
                        :email, 
                        :status, 
                        :source, 
                        :manager_id, 
                        :product_type
                    )";
            
            $stmt = $pdo->prepare($sql);
            
            // Жестко привязываем каждую переменную к конкретному имени колонки в СУБД
            $stmt->execute([
                ':client_name'    => $client_name,
                ':unp'            => $unp,
                ':contact_person' => $contact_person,
                ':phone'          => $phone,
                ':email'          => $email,
                ':status'         => $status,
                ':source'         => $source,
                ':manager_id'     => $manager_id,
                ':product_type'   => $product_type // Наш УОКТ прилетит строго сюда!
            ]);
            
            header("Location: index.php");
            exit;
        } catch (Exception $e) {
            // Если в СУБД имя колонки пишется как product_info — бэкенд честно выведет ошибку на экран
            die("Критический сбой СУБД при добавлении клиента: " . $e->getMessage());
        }
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
   


<!-- СТИЛИ ДЛЯ КРАСИВОГО ХОВЕРА (НАВЕДЕНИЯ МЫШКИ) -->
<style>
   
</style>
    <main>

        <header style =" background-color: #151521 !important;
    background: #151521 !important;
    border-bottom: 1px solid #323248 !important; margin-left: 15px;">

       <button onclick="openAddModal()" class="btn-primary">+ Добавить клиента</button>
               <!-- ИСПРАВЛЕНО НАМЕРТВО: Кнопка сохраняет все PHP-фильтры вкладок и на лету подхватывает живой поиск из инпута -->
<a href="export_excel.php?tab=<?= htmlspecialchars($current_tab) ?>&manager_id=<?= $filterManagerId ?>&source=<?= urlencode($sourceFilter) ?>&status=<?= urlencode($statusFilter) ?>&product_type=<?= urlencode($productFilter) ?>" 
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
</button>
<div id="complexModal" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.6); justify-content: center; align-items: center; z-index: 99999; box-sizing: border-box; padding: 15px;">
    <div style="background: #1e1e2d; border-radius: 8px; border: 1px solid #323248; padding: 25px; width: 550px; box-sizing: border-box; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
        
        <h3 style="margin-top: 0; color: #fff; font-size: 16px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #323248; padding-bottom: 10px; margin-bottom: 20px;">
            🗂 Создание контрагента и договора в одной связке
        </h3>

        <!-- ИСПРАВЛЕНО: Убрали action="index.php", привязали JS обработчик на событие onsubmit -->
        <form id="jointClientContractForm" onsubmit="return saveComplexFormDirectly(event, this);" style="margin: 0; padding: 0;">
            <input type="hidden" name="action_type" value="create_client_with_contract">

            <!-- БЛОК 1: ДАННЫЕ ОРГАНИЗАЦИИ -->
            <div style="margin-bottom: 15px; display: flex; gap: 15px;">
                <div style="flex: 2; display: flex; flex-direction: column; gap: 4px;">
                    <label style="font-size: 10px; color: #92929f; font-weight: bold; text-transform: uppercase;">Название компании</label>
                    <input type="text" name="client_name" id="complex_client_name" required placeholder="ООО СантехМонтаж" style="height: 38px; padding: 0 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px;">
                </div>
                <div style="flex: 1; display: flex; flex-direction: column; gap: 4px;">
                    <label style="font-size: 10px; color: #92929f; font-weight: bold; text-transform: uppercase;">УНП / ИНН</label>
                    <input type="text" name="unp" id="complex_unp" placeholder="123456789" style="height: 38px; padding: 0 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px;">
                </div>
            </div>

            <div style="margin-bottom: 15px; display: flex; gap: 15px;">
                <div style="flex: 1; display: flex; flex-direction: column; gap: 4px;">
                    <label style="font-size: 10px; color: #92929f; font-weight: bold; text-transform: uppercase;">Телефон связи</label>
                    <input type="text" name="phone" id="complex_phone" placeholder="+375 (...)" style="height: 38px; padding: 0 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px;">
                </div>
                <div style="flex: 1; display: flex; flex-direction: column; gap: 4px;">
                    <label style="font-size: 10px; color: #92929f; font-weight: bold; text-transform: uppercase;">Контактное лицо</label>
                    <input type="text" name="contact_person" id="complex_contact_person" placeholder="Иванов И.И." style="height: 38px; padding: 0 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px;">
                </div>
            </div>

            <!-- БЛОК 2: ДАННЫЕ ДОГОВОРА -->
            <div style="border-top: 1px dashed #323248; padding-top: 15px; margin-bottom: 15px; display: flex; gap: 15px;">
                <div style="flex: 1; display: flex; flex-direction: column; gap: 4px;">
                    <label style="font-size: 10px; color: #818cf8; font-weight: bold; text-transform: uppercase;">№ Нового договора</label>
                    <input type="text" name="contract_number" id="complex_contract_number" required placeholder="Напр: 240/Т" style="height: 38px; padding: 0 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; border-color: #4f46e5;">
                </div>
                <div style="flex: 1; display: flex; flex-direction: column; gap: 4px;">
                    <label style="font-size: 10px; color: #818cf8; font-weight: bold; text-transform: uppercase;">Дата заключения</label>
                    <input type="date" name="contract_date" id="complex_contract_date" value="<?= date('Y-m-d') ?>" style="height: 38px; padding: 0 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; color-scheme: dark; border-color: #4f46e5;">
                </div>
            </div>

            <!-- БЛОК 3: ТИП ПРОДУКЦИИ -->
            <div style="display: flex; flex-direction: column; gap: 4px; margin-bottom: 15px; text-align: left;">
                <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Тип продукции:</label>
                <!-- ИСПРАВЛЕНО: Полностью удален атрибут form="newClientForm", ломавший отправку значения -->
                <select name="product_type" id="complex_product_select" style="width: 100%; height: 40px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; cursor: pointer; font-size: 13px; box-sizing: border-box;">
                    <option value="Сантехника">Сантехника</option>
                    <option value="Посуда">Посуда</option>
                    <option value="Резервуары">Резервуары</option>
                    <option value="ЕКМ">ЕКМ</option>
                    <option value="МПДУ">МПДУ</option>
                    <option value="Эмалированные таблички">Эмалированные таблички</option>
                    <option value="УОКТ">УОКТ</option>
                    <option value="другое">другое</option>
                </select>
            </div>

            <!-- ПОДВАЛ МОДАЛКИ: КНОПКИ -->
            <div style="display: flex; justify-content: flex-end; gap: 12px; border-top: 1px solid #323248; padding-top: 15px; background: transparent !important;">
                <button type="button" onclick="closeComplexModal();" style="height: 40px; padding: 0 20px; background: #242434; border: 1px solid #323248; color: #92929f; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px;">Отмена</button>
                <!-- ИСПРАВЛЕНО: Сменили тип на submit, убрали onclick="this.form.submit()" ломавший асинхронность -->
                <button type="submit" class="btn-contract-save" style="height: 40px; padding: 0 20px; background: #10b981; border: none; color: #fff; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px;">🚀 Создать связку</button>
            </div>
        </form>
    </div>
</div>
<script>
function openComplexModal() {
    document.getElementById('complexModal').style.display = 'flex';
}
function closeComplexModal() {
    const modal = document.getElementById('complexModal');
    if (modal) modal.style.display = 'none';
}

// Новая асинхронная функция обработки формы
async function saveComplexFormDirectly(event, formElement) {
    // Жестко пресекаем перезагрузку страницы браузером
    event.preventDefault();
    event.stopPropagation();
    
    console.log("Запущена изолированная транзакция пакетного создания...");
    
    // --- НАЧАЛО ФИКСА БАГА: ПРОВЕРКА НА ДУБЛИКАТЫ ПЕРЕД ОТПРАВКОЙ ---
    const clientNameInput = document.getElementById('complex_client_name');
    const unpInput = document.getElementById('complex_unp');
    
    const clientName = clientNameInput ? clientNameInput.value.trim() : '';
    const unp = unpInput ? unpInput.value.trim() : '';

    if (clientName && unp) {
        try {
            // Делаем экспресс-запрос к бэкенду для поиска дубликатов по Названию и УНП
            const checkRes = await fetch(`check_duplicate.php?name=${encodeURIComponent(clientName)}&unp=${encodeURIComponent(unp)}`);
            const checkData = await checkRes.json();
            
            if (checkData.exists) {
                alert(`⚠️ Отказ создания дубликата!\nКонтрагент с названием «${clientName}» и УНП «${unp}» уже зарегистрирован в системе.`);
                return false; // Полностью блокируем дальнейшую отправку формы
            }
        } catch (checkErr) {
            console.error("Не удалось выполнить проверку дубликатов:", checkErr);
            // Если скрипт проверки недоступен, пропускаем транзакцию дальше, чтобы не ломать CRM
        }
    }
    // --- КОНЕЦ ФИКСА БАГА ---
    
    try {
        const complexFormData = new FormData(formElement);
        
        // Принудительно инжектируем скрытый маркер для бэкенда save.php, чтобы он железно включил Режим А!
        complexFormData.set('action', 'complex');

        // Направляем пакет на наш всеядный транзакционный save.php
        const res = await fetch('save.php', {
            method: 'POST',
            body: complexFormData
        });
        
        const rawText = await res.text();
        console.log("Сырой ответ save.php для комплексной формы:", rawText);
        
        if (!rawText.trim().startsWith('{')) {
            alert("🚨 КРИТИЧЕСКИЙ СБОЙ ТРАНЗАКЦИИ СУБД!\nСервер вернул ошибку PHP вместо JSON:\n\n" + rawText);
            return false;
        }
        
        const result = JSON.parse(rawText);
        if (result.status === 'success') {
            console.log("Транзакция успешно зафиксирована во всех таблицах базы!");
            
            // Скрываем модальное окно перед обновлением
            closeComplexModal();
            
            // ЮЗАБИЛИТИ ХОТФИКС: Автоматически перенаправляем менеджера в Раздел контрактов (ТТН)
            window.location.replace('contracts.php');
        } else {
            alert("⚠️ Отказ СУБД при сохранении контрагента:\n" + result.message);
        }
    } catch (err) {
        console.error("Критический сбой JavaScript:", err);
        alert("🚨 Системный сбой JavaScript! Проверьте консоль F12 (Вкладка Console).");
    }
    return false;
}
</script>



            
   
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
            <select name="product_type" style="padding: 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; cursor: pointer; font-size: 13px;">
                <option value="Все виды" <?= $productFilter === '' ? 'selected' : '' ?>>Все виды</option>
                <option value="Посуда" <?= $productFilter === 'Посуда' ? 'selected' : '' ?>>Посуда</option>
                <option value="Сантехника" <?= $productFilter === 'Сантехника' ? 'selected' : '' ?>>Сантехника</option>
                <option value="Резервуары" <?= $productFilter === 'Резервуары' ? 'selected' : '' ?>>Резервуары</option>
                <option value="ЕКМ" <?= $productFilter === 'ЕКМ' ? 'selected' : '' ?>>ЕКМ</option>
                <option value="МПДУ" <?= $productFilter === 'МПДУ'? 'selected' : '' ?>>МПДУ</option> 
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
            </select>
        </div>
       

            <div style="display: flex; flex-direction: column; gap: 4px; width: 300 px;">
    <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Быстрый поиск компании:</label>
    <input type="text" 
           id="client_live_search" 
           placeholder="Введите любые сведения, которые помните" 
           oninput="runLiveClientFilter(this.value)"
           style="height: 42px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; box-sizing: border-box; font-size: 13px; width: 100%;">
</div>


 <!-- Фильтр: Статус лида (Возвращен в систему) -->
        <div style="display: flex; flex-direction: column; gap: 4px; width: 160px;">
            <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Статус клиента:</label>
            <select name="status" style="height: 42px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; cursor: pointer; font-size: 13px; box-sizing: border-box; width: 100%;">
                <option value="" <?= empty($statusFilter) ? 'selected' : '' ?>>Все статусы</option>
                <option value="Новый" <?= $statusFilter === 'Новый' ? 'selected' : '' ?>>🔴 Новый</option>
                <option value="Текущий" <?= $statusFilter === 'Текущий' ? 'selected' : '' ?>>🟡 Текущий</option>
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
       <div id="contract-reminder-box" style="display:none; background: #fff1f2; border: 2px solid #fb7185; border-radius: 12px; padding: 15px; margin: 20px; box-shadow: 0 4px 10px rgba(225, 29, 72, 0.15);">
    <h3 style="margin: 0 0 10px 0; color: #e11d48; font-size: 16px; display: flex; align-items: center; gap: 8px;">
        <i class="fa-solid fa-bell-range"></i> ВНИМАНИЕ! ГОРЯЩИЕ СРОКИ:
    </h3>
    <ul id="contract-reminder-list" style="margin: 0; padding-left: 20px; color: #4c0519; font-weight: bold; line-height: 1.6;">
        <!-- Сюда JS добавит список -->
    </ul>
</div>

<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin: 5px 0 5px 0; width: 100%;">
    
    <!-- Карточка 1 -->
    <div style="background: #1e1e2d; padding: 15px; border-radius: 12px; border-left: 5px solid #4f46e5; box-shadow: 0 4px 6px rgba(0,0,0,0.2); margin-left:25px; margin-right: 25px;">
        <div style="color: #92929f; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px;">Всего клиентов</div>
        <div style="color: #fff; font-size: 28px; font-weight: bold; line-height: 1;"><?= (int)$stats['total'] ?></div>
    </div>

    <!-- Карточка 2 -->
    <div style="background: #1e1e2d; padding: 20px; border-radius: 12px; border-left: 4px solid #f6ad55; box-shadow: 0 4px 6px rgba(0,0,0,0.2); margin-left:25px; margin-right:25px;">
        <div style="color: #92929f; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px;">Новые</div>
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
            <th style="padding: 16px 10px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-align: left; white-space: nowrap; position: relative; overflow: hidden; text-overflow: ellipsis; border: none; background: #161624;">Контактное лицо<div class="resizer"></div></th>
            <th style="padding: 16px 10px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-align: center; white-space: nowrap; position: relative; overflow: hidden; text-overflow: ellipsis; border: none; background: #161624;">Телефон<div class="resizer"></div></th>
            <th style="padding: 16px 10px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-align: left; white-space: nowrap; position: relative; overflow: hidden; text-overflow: ellipsis; border: none; background: #161624;">Email<div class="resizer"></div></th>
            <th style="padding: 16px 10px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-align: center; white-space: nowrap; position: relative; overflow: hidden; text-overflow: ellipsis; border: none; background: #161624;">Статус<div class="resizer"></div></th>
            <th style="padding: 16px 10px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-align: center; white-space: nowrap; position: relative; overflow: hidden; text-overflow: ellipsis; border: none; background: #161624;">Источник<div class="resizer"></div></th>
            <th style="padding: 16px 10px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-align: center; white-space: nowrap; position: relative; overflow: hidden; text-overflow: ellipsis; border: none; background: #161624;">След. контакт<div class="resizer"></div></th>
            <th style="padding: 16px 10px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-align: center; white-space: nowrap; position: relative; overflow: hidden; text-overflow: ellipsis; border: none; background: #161624;">Вид продукции<div class="resizer"></div></th>
            <th style="padding: 16px 10px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-align: center; white-space: nowrap; position: relative; overflow: hidden; text-overflow: ellipsis; border: none; background: #161624;">Контракт<div class="resizer"></div></th>
            <th style="padding: 16px 10px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.8px; text-align: center; white-space: nowrap; position: relative; overflow: hidden; text-overflow: ellipsis; border: none; background: #161624;">Действие<div class="resizer"></div></th>
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
});</script>
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
            <td class="cell-name" style="padding: 14px 10px; text-align: left; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><strong style="color: #ffffff; font-weight: 700; letter-spacing: 0.3px; font-size: 13px;"><?= htmlspecialchars($c['client_name']) ?></strong></td>
            
            <!-- 4. УНП (Пепельный) -->
            <td class="cell-unp" style="padding: 14px 10px; text-align: center; color: #71717a; font-family: monospace; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($c['unp'] ?: '—') ?></td>
            
            <!-- 5. Контактное лицо (Мягкий серебряный) -->
            <td class="cell-person" style="padding: 14px 10px; text-align: left; color: #cbd5e1; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($c['contact_person'] ?: '—') ?></td>
            
            <!-- 6. Телефон -->
            <td class="cell-phone" style="padding: 14px 10px; text-align: center; color: #cbd5e1; font-family: monospace; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($c['phone'] ?: '—') ?></td>
            
            <!-- 7. Email -->
            <td class="cell-email" style="padding: 14px 10px; text-align: left; color: #cbd5e1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($c['email'] ?: '—') ?></td>
            
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
            
            <!-- 10. След. contact -->
            <td style="padding: 14px 10px; text-align: center; color: #a1a1aa; font-family: monospace; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                <?php if (!empty($c['next_contact_date']) && $c['next_contact_date'] !== '0000-00-00'): ?>
                    <!-- Проверка на просрочку для подсветки даты -->
                    <span style="<?= $isOverdue ? 'color: #ef4444; font-weight: bold; background: rgba(239,68,68,0.1); padding: 2px 6px; border-radius: 4px;' : '' ?>">
                        <?= date('d.m.Y', strtotime($c['next_contact_date'])) ?>
                    </span>
                <?php else: ?>
                    <span style="color: #4b5563;">—</span>
                <?php endif; ?>
            </td>
       <!-- ЯЧЕЙКА КОММЕНТАРИЯ С КЛИКОМ ДЛЯ ПРОСМОТРА -->
 <!--   <td class="cell-comment js-comment-preview"</td>
    data-client-name="<?= htmlspecialchars($c['client_name'], ENT_QUOTES, 'UTF-8') ?>"
    style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; cursor: pointer; color: #92929f;"
    title="Кликните для просмотра полного комментария">
    <?= htmlspecialchars($c['comment'] ?? '—') ?>

    <script>function openCommentHistoryModal(clientId, clientName) {
    currentActiveHistoryClientId = clientId;
    
    // Находим элементы модалки
    const modal = document.getElementById('commentHistoryModal');
    const inputId = document.getElementById('history_modal_client_id');
    const spanName = document.getElementById('historyModalClientName');
    const inputField = document.getElementById('new_history_comment_input');
    const container = document.getElementById('historyLogsContainer');
    
    // Находим ячейку с текущим комментарием
    const viewDiv = document.getElementById('comment_view_' + clientId);

    if (inputId) inputId.value = clientId;
    if (spanName) spanName.innerText = clientName || '';

    // 1. Вытягиваем всю сырую историю для блока логов
    const rawHistory = viewDiv ? viewDiv.getAttribute('data-raw-history') : '';
    if (container) {
        container.innerText = rawHistory && rawHistory.trim() !== '' ? rawHistory : 'История переговоров пуста.';
    }

    // 2. ХИРУРГИЧЕСКИЙ ВПРЫСК: Забираем текущий видимый текст ячейки ("раавыы") и шлем в форму
    if (inputField && viewDiv) {
        const currentText = viewDiv.innerText.trim();
        // Если там прочерк — оставляем поле пустым, если текст есть — подставляем для редактирования
        inputField.value = (currentText === '—') ? '' : currentText;
    }

    // Показываем окно
    if (modal) {
        modal.style.display = 'flex';
    }
    if (inputField) {
        inputField.focus();
    }
}</script>
-->


            <!-- ИСПРАВЛЕНО: Выводим тип продукции привязанного договора вместо дефолтного значения -->
<!-- ИСПРАВЛЕНО НАМЕРТВО: Проверяем все возможные имена колонок продукции из СУБД (product_type, product_info, prod), убирая жесткий дефолт -->
 <td style="padding: 14px 10px; text-align: center; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                <span style="background: rgba(129, 140, 248, 0.08); color: #818cf8; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: bold; border: 1px solid rgba(129, 140, 248, 0.15); display: inline-block;">
                    <?= htmlspecialchars($c['product_type'] ?? ($c['product_info'] ?? ($c['product'] ?? ($c['prod'] ?? 'Не указан')))) ?>
                </span>
            </td>

   <td style="padding: 14px 10px; text-align: center; vertical-align: middle; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
        <?php 
        $currentClientId = (int)($c['id'] ?? 0);
        
        // Способ 1: Прямая проверка наличия договора в таблице проектов projects
        $checkContractStmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE client_id = ?");
        $checkContractStmt->execute([$currentClientId]);
        $hasRealContract = ((int)$checkContractStmt->fetchColumn() > 0);
        
        // Способ 2: Проверка флага из самой таблицы клиентов clients
        $isSignedFlag = (isset($c['is_contract_signed']) && (int)$c['is_contract_signed'] === 1);
        // ИТОГОВЫЙ СИНХРОНИЗАТОР: Если есть запись в projects ИЛИ взведен флаг в clients — галочка ЖЕЛЕЗНО будет чекнута!
        $contractExists = ($hasRealContract || $isSignedFlag);
        $jsContractFlag = $contractExists ? 1 : 0;
        ?>
        <input type="checkbox" 
               id="contract_signed_<?= $currentClientId ?>"
               data-has-contract="<?= $jsContractFlag ?>"
               <?= $contractExists ? 'checked' : '' ?> 
               onchange="toggleContractStatus(<?= $currentClientId ?>, event, <?= $jsContractFlag ?>)"
               style="cursor: pointer; width: 16px; height: 16px; position: relative; z-index: 10;">
    </td>
            <script>
              // ИСПРАВЛЕНО НАМЕРТВО: Защита от перепутанных мест параметров HTML (this и ID)
// ИСПРАВЛЕНО НАМЕРТВО: Прямая логика по точному флагу из базы данных проектов
window.toggleContractStatus = async function(clientId, event, dbHasContract) {
    const ev = (event && event.target) ? event : (window.event || null);
    
    let checkboxElement = ev ? ev.target : document.getElementById('contract_signed_' + clientId);
    if (!checkboxElement) return;

    const isCheckedNow = checkboxElement.checked;
    const hasContract = (typeof dbHasContract !== 'undefined') 
        ? Boolean(dbHasContract) 
        : (checkboxElement.getAttribute('data-has-contract') === '1');

    console.log("=== СИНХРОНИЗАЦИЯ ГАЛОЧКИ С СУБД ===");
    window.activeContractCheckbox = checkboxElement;

    // СИТУАЦИЯ 1: Контракт РЕАЛЬНО ЕСТЬ в базе projects
    if (hasContract) {
        checkboxElement.checked = true; 
        if (!confirm("⚠️ Вы уверены, что хотите полностью расторгнуть контракт этого клиента со всеми ТТН?\nЭто действие удалит договор из базы контрактов!")) {
            window.activeContractCheckbox = null;
            return false;
        }
        
        try {
            const res = await fetch('update_cell.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: clientId, field: 'is_contract_signed', value: 0 })
            });
            if ((await res.json()).status === 'success') {
                location.reload();
            }
        } catch (err) { alert("Ошибка связи с сервером"); }
        return;
    } 

    // СИТУАЦИЯ 2: Контракта в базе еще нет (Открытие формы договора)
    else {
        if (isCheckedNow) {
            try {
                const res = await fetch('update_cell.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: clientId, field: 'is_contract_signed', value: 1 })
                });
                const result = await res.json();
                
                if (result.status === 'success') {
                    console.log("Флаг успешно сохранен в clients.");
                    
                    // Умный инспектор модалок на index.php
                    if (typeof openNewContractModal === 'function') {
                        openNewContractModal(clientId);
                    } else if (typeof openContractModal === 'function') {
                        openContractModal(clientId);
                    } else {
                        const modal = document.getElementById('contractModal') || document.getElementById('newContractModal') || document.getElementById('addContractModal');
                        const inputId = document.getElementById('modal_client_id') || document.getElementById('contract_client_id_storage');
                        
                        if (modal) {
                            if (inputId) inputId.value = parseInt(clientId, 10);
                            modal.style.display = 'flex';
                        } else {
                            alert("Вы зафиксировали лид под договор! Обновите страницу или перейдите в раздел 'Договоры'.");
                            location.reload();
                        }
                    }
                } else {
                    checkboxElement.checked = false;
                    alert("Ошибка СУБД: " + result.message);
                }
            } catch (err) {
                checkboxElement.checked = false;
                alert("Ошибка сети");
            }
        } else {
            try {
                await fetch('update_cell.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: clientId, field: 'is_contract_signed', value: 0 })
                });
            } catch (err) { console.error(err); }
            window.activeContractCheckbox = null;
        }
    }
};


// Принудительный сброс галочки при клике на Отмену
function closeContractModal() {
    const modal = document.getElementById('contractModal') || document.getElementById('newContractModal');
    if (modal) {
        modal.style.display = 'none';
    }

    if (window.activeContractCheckbox) {
        window.activeContractCheckbox.checked = false; // Галочка принудительно снимается
        window.activeContractCheckbox = null;         // Очищаем память
        console.log("Менеджер нажал 'Отмена'. Галочка контракта успешно погашена без записи в СУБД.");
    }
}
            </script>
            <td>
      <!-- МАКСИМАЛЬНО ПРОСТАЯ И НАДЕЖНАЯ КНОПКА РЕДАКТИРОВАНИЯ -->
<?php 
// Жесткое условие блокировки: у клиента есть договор ($c['is_contract_signed'] == 1) и залогинен менеджер
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
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<!-- МОДАЛЬНОЕ ОКНО (ОДНО НА ВЕСЬ ФАЙЛ, ВНЕ ЦИКЛА!) -->
<div id="clientModal" class="modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); justify-content:center; align-items:center; z-index:9999;">
    <div class="stylish-modal" style="background:#1e1e2d; padding:25px; border-radius:12px; height: 800px; width:800px; color:#fff; font-family: sans-serif;">
        <h2 id="modalTitle" style="margin-top:0; text-align: left;">Добавить клиента</h2>
        
        <form id="clientForm" style="margin: 0; padding: 0;">
            <!-- Скрытое поле ID обязательно должно иметь name="id" -->
            <input type="hidden" id="client_id" name="id">
            
             
    <!-- РЯД 1: НАЗВАНИЕ И УНП -->
    <div class="form-row" style="display: flex; gap: 15px; margin-bottom: 15px;">
        <div class="form-group" style="flex: 2; text-align: left;">
            <label style="display:block; font-size:12px; color:#92929f; margin-bottom:5px;">Название организации <span style="color:red;">*</span></label>
            <input type="text" id="client_name" name="client_name" required placeholder="Напр: СЗАО «Сантэкс»" style="width: 100%; padding: 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; box-sizing: border-box;">
        </div>
        <div class="form-group" style="flex: 1; text-align: left;">
            <label style="display:block; font-size:12px; color:#92929f; margin-bottom:5px;">УНП <span style="color:red;">*</span></label>
            <input type="text" id="unp" name="unp" required placeholder="9 цифр" style="width: 100%; padding: 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; box-sizing: border-box;">
        </div>
    </div>

          <!-- РЯД 2: КОНТАКТНОЕ ЛИЦО И ТЕЛЕФОН -->
    <div class="form-row" style="display: flex; gap: 15px; margin-bottom: 15px;">
        <div class="form-group" style="flex: 1; text-align: left;">
            <label style="display:block; font-size:12px; color:#92929f; margin-bottom:5px;">Контактное лицо <span style="color:red;">*</span></label>
            <input type="text" id="contact_person" name="contact_person" required style="width: 100%; padding: 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; box-sizing: border-box;">
        </div>
        <div class="form-group" style="flex: 1; text-align: left;">
            <label style="display:block; font-size:12px; color:#92929f; margin-bottom:5px;">Телефон</label>
            <input type="text" id="phone" name="phone" placeholder="Введите телефон..." style="width: 100%; padding: 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; box-sizing: border-box;">
        </div>
    </div>

           <!-- РЯД 3: E-MAIL И ВИД ПРОДУКЦИИ -->
    <div class="form-row" style="display: flex; gap: 15px; margin-bottom: 15px;">
        <div class="form-group" style="flex: 1; text-align: left;">
            <label style="display:block; font-size:12px; color:#92929f; margin-bottom:5px;">E-mail</label>
            <input type="email" id="email" name="email" placeholder="Введите email..." style="width: 100%; padding: 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; box-sizing: border-box;">
        </div>
        <div class="form-group" style="flex: 1; text-align: left;">
            <label style="display:block; font-size:12px; color:#92929f; margin-bottom:5px;">Вид продукции <span style="color:red;">*</span></label>
            <select id="product_type" name="product_type" required style="width: 100%; padding: 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; box-sizing: border-box; cursor: pointer;">
                <option value="Посуда">Посуда</option>
                <option value="Сантехника">Сантехника</option>
                <option value="Резервуары">Резервуары</option>
                <option value="ЕКМ">ЕКМ</option>
                <option value="МПДУ">МПДУ</option>
                <option value="Эмалированные таблички">Эмалированные таблички</option>
                <option value="УОКТ">УОКТ</option>
                <option value="другое">другое   </option>
            </select>
        </div>
    </div>  <!-- РЯД 4: ДАТА ПЕРВОГО КОНТАКТА И СЛЕДУЮЩИЙ КОНТАКТ -->
    <div class="form-row" style="display: flex; gap: 15px; margin-bottom: 15px;">
        <div class="form-group" style="flex: 1; text-align: left;">
            <label style="display:block; font-size:12px; color:#92929f; margin-bottom:5px;">Дата первого контакта</label>
            <input type="date" id="first_contact_date" name="first_contact_date" value="<?= date('Y-m-d') ?>" readonly style="width: 100%; padding: 10px; background: #242434; border: 1px solid #323248; color: #64748b; border-radius: 6px; outline: none; box-sizing: border-box; cursor: not-allowed;">
        </div>
       <div style="display: flex; flex-direction: column; gap: 4px; margin-bottom: 15px; text-align: left;">
    <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Следующий контакт:</label>
    <input type="date" 
           name="next_contact_date" 
           id="add_client_next_date"
           style="width: 100%; height: 40px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; color-scheme: dark; box-sizing: border-box;">
</div>
    </div>
    <!-- РЯД 5: ИСТОЧНИК И СТАТУС -->
    <div class="form-row" style="display: flex; gap: 15px; margin-bottom: 15px;">
        <div class="form-group" style="flex: 1; text-align: left;">
            <label style="display:block; font-size:12px; color:#92929f; margin-bottom:5px;">Источник привлечения <span style="color:red;">*</span></label>
            <select id="source" name="source" required style="width: 100%; padding: 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; box-sizing: border-box; cursor: pointer;">
                <option value="Запрос">Запрос</option>
                <option value="Холодный поиск">Холодный поиск</option>
                <option value="Закупки  ">Закупки</option>
            </select>
        </div>
        <div class="form-group" style="flex: 1; text-align: left;">
            <label style="display:block; font-size:12px; color:#92929f; margin-bottom:5px;">Статус <span style="color:red;">*</span></label>
            <select id="status" name="status" required style="width: 100%; padding: 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; box-sizing: border-box; cursor: pointer;">
                <option value="Новый">Новый</option>
                <option value="Текущий">Текущий</option>
                <option value="Отказ">Отказ</option>
            </select>
        </div>
    </div>

    <!-- РЯД 6: КОММЕНТАРИЙ МЕНЕДЖЕРА (НА ВСЮ ШИРИНУ) -->
    <div class="form-row" style="margin-bottom: 5px;">
        <div class="form-group" style="width: 100%; text-align: left;">
            <label style="display:block; font-size:12px; color:#92929f; margin-bottom:5px;">Комментарий менеджера</label>
            <textarea id="comment" name="comment" placeholder="Введите примечание..." style="width: 100%; height: 210px; padding: 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; box-sizing: border-box; resize: vertical; font-family: sans-serif;"></textarea>
        </div>
    </div>
<!-- ИСПРАВЛЕНО: Скрытый транзит истории комментариев для защиты от затирания при редактировании -->
<input type="hidden" id="modal_client_hidden_comment" name="comment">

<input type="hidden" id="modal_client_hidden_comment" name="comment">

<script>
document.addEventListener("DOMContentLoaded", function() {
    const firstDate = document.getElementById('first_contact_date');
    const nextDate = document.getElementById('next_contact_date');
    if(firstDate && nextDate) {
        firstDate.addEventListener('change', function() {
            nextDate.min = this.value;
        });
    }
});
</script>
               
            <!-- Кнопки управления формой -->
   <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
        <button type="button" onclick="closeClientModal()" style="background: #323248; border: none; padding: 10px 20px; border-radius: 6px; color: #fff; cursor: pointer; font-weight: bold;">Отмена</button>
            <script>
                function closeClientModal() {
    const modal = document.getElementById('clientModal');
    if (modal) {
        modal.style.setProperty('display', 'none', 'important');
    }
}   
            </script>
        <button type="submit" style="background: #4f46e5; border: none; padding: 10px 20px; border-radius: 6px; color: #fff; cursor: pointer; font-weight: bold;">Сохранить клиента</button>
    </div>
</div>

        </form>
    </div>
</div>
<style>
   .stylish-modal {
    background: #1e1e2d; /* Глубокий темный */
    border-radius: 20px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.4);
    border: 1px solid #323248;
}

.modal-header {
    padding: 25px 30px;
    border-bottom: 1px solid #323248;
    display: flex; justify-content: space-between; align-items: center;
}

.modal-header h2 { color: #fff; font-size: 18px; margin: 0; font-weight: 600; }

.form-section { margin-bottom: 20px; padding: 10px 0; }
.form-row { 
    display: flex; 
    gap: 15px; /* Расстояние между колонками */
    margin-bottom: 15px; 
    padding: 0 30px; 
    flex-wrap: wrap; /* Если места мало, поля уйдут на новую строку, а не налезут друг на друга */
}
.flex-2 { flex: 2; }
.flex-1 { flex: 1; }
.form-group { flex: 1; display: flex; flex-direction: column; }
.three-cols .form-group {
    min-width: 140px; /* Минимальная ширина, чтобы текст не обрезался */
}
.form-group label {
    color: #92929f; font-size: 12px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;
}

.form-group input, .form-group select, .form-group textarea {
    background: #1b1b28;
    border: 1px solid #323248;
    border-radius: 8px;
    padding: 12px 15px;
    color: #fff;
    font-size: 14px;
    transition: 0.3s;
}

.form-group input:focus { border-color: #4f46e5; outline: none; background: #212130; }

/* Кнопки */
.modal-footer {
    background: #1b1b28;
    padding: 20px 30px;
    border-bottom-left-radius: 20px;
    border-bottom-right-radius: 20px;
    display: flex; justify-content: flex-end; gap: 15px;
}

.btn-submit {
    background: #4f46e5; color: #fff; border: none; padding: 12px 25px; border-radius: 10px;
    font-weight: 600; cursor: pointer; transition: 0.3s;
}
.btn-submit:hover { background: #6366f1; transform: translateY(-2px); }

.btn-cancel { background: transparent; color: #92929f; border: none; cursor: pointer; }
/* Остальные стили .modal-content, .form-row и т.д. из прошлого сообщения */
</style>
<div id="commentViewModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); justify-content: center; align-items: center; z-index: 999999;">
    <div style="background: #1e1e2d; padding: 20px; border-radius: 12px; width: 450px; max-width: 90%; border: 1px solid #323248; box-shadow: 0 10px 30px rgba(0,0,0,0.5); font-family: sans-serif; display: flex; flex-direction: column; box-sizing: border-box;">
        
        <!-- Заголовок окна -->
        <h3 style="margin-top: 0; margin-bottom: 5px; font-size: 14px; color: #92929f; font-weight: normal; text-transform: uppercase; letter-spacing: 0.5px;">
            📝 История комментариев
        </h3>
        <p id="commentModalClientLabel" style="color: #4f46e5; margin-top: 0; font-size: 15px; margin-bottom: 15px; font-weight: bold;"></p>
        
        <!-- Контейнер для текста с адаптивной высотой и скроллом -->
        <div id="commentModalTextContainer" style="background: #151521; border: 1px solid #2b2b40; border-radius: 8px; padding: 15px; color: #e2e8f0; font-size: 13px; line-height: 1.6; word-wrap: break-word; white-space: pre-wrap; overflow-y: auto; max-height: 400px; min-height: 100px; box-sizing: border-box;">
            <!-- Сюда JS вставит полный текст -->
        </div>

        <!-- Кнопка закрытия -->
        <div style="margin-top: 20px; text-align: right;">
            <button type="button" onclick="closeCommentViewModal()" style="background: #4f46e5; color: #fff; border: none; padding: 8px 20px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px; transition: background 0.15s;">
                Закрыть
            </button>
        </div>
    </div>
</div>
    </main>
    <script>
// ЖИВОЙ ФИЛЬТР: Фильтрация строк таблицы по первой и любым последующим буквам
function runLiveClientFilter(searchQuery) {
    // Переводим поисковый запрос в нижний регистр для игнорирования регистра
    const query = searchQuery.toLowerCase().trim();
    
    // Находим все рабочие строки клиентов в нашей таблице базы (исключая шапку)
    // Убедись, что у тебя у строк клиентов в tbody стоит класс или ищи просто tr внутри tbody
    const tableRows = document.querySelectorAll("table tbody tr");

    tableRows.forEach(row => {
        // Ищем ячейку с именем клиента (обычно это второй или третий td, либо элемент с классом)
        // Если у тебя есть класс на названии компании (например, .cell-name), укажи его, иначе берем текст всей строки
        const rowText = row.innerText.toLowerCase();

        if (query === "") {
            // Если поле пустое — показываем вообще всех обратно
            row.style.display = "";
        } else if (rowText.includes(query)) {
            // Если буквы совпали (в любом месте названия) — оставляем строку видимой
            row.style.display = "";
        } else {
            // Если совпадений нет — мгновенно прячем строку с экрана
            row.style.display = "none";
        }
    });
}

        // =========================================================================
// ИСПРАВЛЕНО НАМЕРТВО: Автономный защищенный движок открытия модалки редактирования
// =========================================================================
async function openProtectedEditModal(id) {
    console.log("Запрос точных данных из базы для клиента ID:", id);
    
    const modal = document.getElementById('clientModal');
    const form = document.getElementById('clientForm');
    
    if (!modal) {
        alert("Критическая ошибка интерфейса: Форма clientModal не найдена в разметке!");
        return;
    }

    if (form) form.reset(); // Очищаем форму перед заполнением

    try {
        const res = await fetch('get_client.php?id=' + parseInt(id, 10));
        const responseData = await res.json();
        
        if (responseData.status !== 'success') {
            alert("Ошибка получения данных: " + responseData.message);
            return;
        }

        const c = responseData.data; 
        console.log("УСПЕХ API: Данные успешно получены:", c);

        const targetRow = document.querySelector(`tr[data-id="${id}"]`);

        // =========================================================================
        // УЛЬТИМАТИВНЫЙ ФИКС БАГА 105: Снятие ложной блокировки КУП "Брестжилстрой"
        // =========================================================================
        if (targetRow) {
            const contractCheckbox = targetRow.querySelector('.contract-checkbox') || targetRow.querySelector('input[type="checkbox"]');
            if (contractCheckbox && !contractCheckbox.checked) {
                console.log("ДИАГНОСТИКА: Галочка снята на экране. Блокировка КУП 'Брестжилстрой' снята.");
            } else if (contractCheckbox && contractCheckbox.checked) {
                alert("⚠️ Редактирование заблокировано: Данный клиент имеет активный связанный договор в Разделе контрактов (ТТН).");
                return;
            }
        }
        // =========================================================================

        // Заполнение базовых текстовых полей формы
        if(document.getElementById('client_id')) document.getElementById('client_id').value = c.id;
        if(document.getElementById('modalTitle')) document.getElementById('modalTitle').innerText = 'Редактирование клиента #' + c.id;
        
        const nameField = document.getElementById('client_name') || document.getElementById('name');
        if (nameField) nameField.value = c.client_name || c.name || '';
        
        if(document.getElementById('unp')) document.getElementById('unp').value = c.unp || '';
        if(document.getElementById('contact_person')) document.getElementById('contact_person').value = c.contact_person || '';
        if(document.getElementById('phone')) document.getElementById('phone').value = c.phone || '';
        
        const emailField = document.getElementById('e_mail') || document.getElementById('email');
        if (emailField) emailField.value = c.email || c.e_mail || '';
        
        if(document.getElementById('product_type')) document.getElementById('product_type').value = c.product_type || '';
        if(document.getElementById('first_contact_date')) document.getElementById('first_contact_date').value = c.first_contact_date || '';

        // =========================================================================
        // БРОНЕБОЙНЫЙ ОДНОСТРОЧНЫЙ ФИКС БАГА 104: Конвертер даты без массивов split
        // =========================================================================
              // =========================================================================
        // ЖЕЛЕЗОБЕТОННЫЙ ФИКС БАГА 104 (ИСПРАВЛЕНО НАМЕРТВО): Запись в add_client_next_date
        // =========================================================================
        // Ищем инпут строго по твоему реальному HTML-идентификатору модалки!
        const nextDateInput = document.getElementById('add_client_next_date') || document.getElementById('next_contact_date');
        if (nextDateInput) {
            let apiDateValue = (c.next_contact_date || c.next_date || c.date_next || '').toString().trim();
            console.log("ДИАГНОСТИКА: Записываем в HTML-поле '" + nextDateInput.id + "' значение:", apiDateValue);

            if (apiDateValue && apiDateValue !== '—' && apiDateValue !== 'NULL' && apiDateValue !== '0000-00-00') {
                if (apiDateValue.includes('-')) {
                    apiDateValue = apiDateValue.substring(0, 10); // Убираем хвост времени ISO
                } else if (apiDateValue.includes('.')) {
                    apiDateValue = apiDateValue.replace(/^(\d{2})\.(\d{2})\.(\d{4})$/, '$3-$2-$1');
                }
                
                nextDateInput.value = apiDateValue;
                console.log("ПОЛНАЯ ПОБЕДА: Инпут календаря намертво зафиксировал дату:", apiDateValue);
            } else {
                nextDateInput.value = ''; // Очищаем, если в базе пусто
            }
        } else {
            console.error("Критический сбой: Инпут add_client_next_date физически не найден в HTML!");
        }
        // =========================================================================

        // =========================================================================

        if(document.getElementById('status')) document.getElementById('status').value = c.status || '';
        
        const commentField = document.getElementById('comment') || document.getElementById('client_comment');
        if (commentField) commentField.value = c.comment || '';

        const sourceField = document.getElementById('source') || document.getElementById('client_source');
        if (sourceField && c.source) sourceField.value = c.source;

        // Показываем идеально заполненную форму
        modal.style.display = 'flex';
        console.log("Данные успешно подтянуты из API без единого сбоя.");

    } catch (err) {
        console.error("Критическая ошибка JS внутри потока fetch:", err);
        alert("🚨 Системный сбой JavaScript! Проверьте консоль F12.");
    }
}


async function closeEditModal() { 
    const modal = document.getElementById('clientModal') || document.getElementById('EditModal');
    if (modal) {
        modal.style.display = 'none';
    }
} 


// Функция закрытия окна просмотра комментариев
function closeCommentViewModal() {
    const modal = document.getElementById('commentViewModal');
    if (modal) modal.style.display = 'none';
}
document.addEventListener('change', async function(e) {


    // Проверяем, что кликнули именно по чекбоксу контракта
    if (e.target.classList.contains('contract-checkbox')) {
        const cb = e.target;
        const clientId = cb.dataset.clientId;
        const isChecked = cb.checked;
        const val = isChecked ? 1 : 0;

        // Если АДМИН снимает галку — подтверждение удаления
        if (!isChecked && userRole === 'admin') {
            if (!confirm("ВНИМАНИЕ: Снятие галки УДАЛИТ все договоры этого клиента! Продолжить?")) {
                cb.checked = true; // Возвращаем галку назад
                return;
            }
        }

        try {
            const res = await fetch('update_cell.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ id: clientId, field: 'is_contract_signed', value: val })
            });
            
            const result = await res.json();
            
            if (result.status === 'success') {
                if (isChecked) {
                    // Если поставили — летим в Контракты оформлять договор
                    window.location.href = 'contracts.php?auto_open_client_id=' + clientId;
                } else {
                    // Если сняли — просто обновляем страницу (база уже почищена в update_cell.php)
                    location.reload();
                }
            } else {
                alert("Ошибка: " + result.message);
                cb.checked = !isChecked; // Откатываем галку в интерфейсе
            }
        } catch (err) {
            console.error("Ошибка связи:", err);
            cb.checked = !isChecked;
        }
    }
})


 const userRole = '<?= $_SESSION['role'] ?>';

// 1. УПРАВЛЕНИЕ МОДАЛКОЙ
function openAddModal() {
    document.getElementById('clientForm').reset();
    document.getElementById('client_id').value = '';
    const dateInp = document.getElementById('first_contact_date');
    if(dateInp) {
        dateInp.value = new Date().toISOString().split('T')[0];
        dateInp.readOnly = false; // При добавлении можно менять
    }
    document.getElementById('clientModal').style.display = 'flex';
}
// 1. ОТКРЫТИЕ МОДАЛЬНОГО ОКНА РЕДАКТИРОВАНИЯ (ИСПРАВЛЕНО ТОЧЕЧНО)
function openEditModal(id) {
    console.log("Запущена функция openEditModal для ID:", id);
    
    const modal = document.getElementById('clientModal');
    const row = document.querySelector(`tr[data-id="${id}"]`);
    
    if (!row || !modal) {
        console.error("Критическая ошибка: Строка tr с data-id='" + id + "' или окно 'clientModal' не найдены в HTML!");
        return;
    }

    // Сбрасываем старые данные формы
    const form = document.getElementById('clientForm');
    if (form) form.reset();
    
    // Записываем ID редактируемого клиента
    const idInput = document.getElementById('client_id');
    if (idInput) idInput.value = id;
    
    const titleEl = document.getElementById('modalTitle');
    if (titleEl) titleEl.innerText = 'Редактирование клиента #' + id;

    // ЗАЩИЩЕННАЯ ФУНКЦИЯ ЗАПОЛНЕНИЯ ПОЛЕЙ (Не падает, если элемента нет в HTML)
    const fillField = (inputId, tableClass) => {
        const inputElement = document.getElementById(inputId);
        const tableCell = row.querySelector(tableClass);
        if (inputElement && tableCell) {
            inputElement.value = tableCell.innerText.trim();
        } else {
            console.log("Инпут '" + inputId + "' или ячейка '" + tableClass + "' отсутствуют на этой странице.");
        }
    };

    // Посимвольный поочередный сбор данных из таблицы в форму
    fillField('client_name', '.cell-name');
    fillField('unp', '.cell-unp');
    fillField('contact_person', '.cell-person');
    fillField('phone', '.cell-phone');
    fillField('email', '.cell-email');
    fillField('product_type', '.cell-product');
    fillField('first_contact_date', '.cell-date');
    fillField('next_contact_date', '.cell-next');
    fillField('status', '.cell-status');
    fillField('source', '.cell-source');

    // ЖЕСТКИЙ ФИКС БАГА РЕДАКТИРОВАНИЯ: Если ячейки .cell-comment в таблице нет,
    // мы изящно пытаемся забрать старый комментарий из кастомного атрибута data-comment строки tr
    const commInput = document.getElementById('comment');
    if (commInput) {
        const tableCellComm = row.querySelector('.cell-comment');
        if (tableCellComm && tableCellComm.innerText.trim() !== '') {
            commInput.value = tableCellComm.innerText.trim();
        } else if (row.getAttribute('data-comment')) {
            commInput.value = row.getAttribute('data-comment').trim();
        } else {
            commInput.value = ''; // Если комментарий действительно пустой
        }
    }

    // Логика блокировки даты первого контакта для менеджера
    const dateInput = document.getElementById('first_contact_date');
    if (dateInput) {
        dateInput.disabled = (typeof userRole !== 'undefined' && userRole !== 'admin');
    }

    // Отображаем окно
    modal.style.display = 'flex';
}


</script>

<!-- ФИКСИРОВАННЫЙ СЕРВИСНЫЙ ФУТЕР-НАПОМИНАНИЕ -->
<?php
if (isset($_SESSION['user_id'])) {
    $currentUserId = (int)$_SESSION['user_id'];
    $currentUserRole = $_SESSION['role'] ?? 'manager';

    // Запрос: вытаскиваем клиентов, у которых дата контакта наступила или прошла
    if ($currentUserRole === 'admin') {
        $remStmt = $pdo->prepare("SELECT id, client_name, next_contact_date FROM clients WHERE status != 'Отказ' AND next_contact_date <= CURDATE() ORDER BY next_contact_date ASC");
        $remStmt->execute();
    } else {
        $remStmt = $pdo->prepare("SELECT id, client_name, next_contact_date FROM clients WHERE status != 'Отказ' AND next_contact_date <= CURDATE() AND manager_id = ? ORDER BY next_contact_date ASC");
        $remStmt->execute([$currentUserId]);
    }
    $remindList = $remStmt->fetchAll();
    $remindCount = count($remindList);
?>
    <?php
// 1. ВСТРОЕННЫЙ СЕРВЕРНЫЙ ДВИЖОК ВЫБОРКИ ДЛЯ ВИДЖЕТА (ПРОСРОЧКА + 6 ДНЕЙ ВПЕРЕД)
try {
    if ($userRole === 'admin') {
        $remind_stmt = $pdo->prepare("SELECT * FROM clients 
            WHERE status != 'Отказ' 
              AND next_contact_date IS NOT NULL 
              AND next_contact_date <= DATE_ADD(CURDATE(), INTERVAL 4 DAY)
            ORDER BY next_contact_date ASC LIMIT 5");
        $remind_stmt->execute();
    } else {
        $remind_stmt = $pdo->prepare("SELECT * FROM clients 
            WHERE manager_id = ? 
              AND status != 'Отказ' 
              AND next_contact_date IS NOT NULL 
              AND next_contact_date <= DATE_ADD(CURDATE(), INTERVAL 4 DAY)
            ORDER BY next_contact_date ASC LIMIT 5");
        $remind_stmt->execute([$userId]);
    }
    $remindList = $remind_stmt->fetchAll() ?: [];
} catch (Exception $e) { 
    $remindList = []; 
}

$remindCount = count($remindList);
?>

<!-- 2. ВИЗУАЛЬНЫЙ БЛОК ИНТЕРАКТИВНОГО ВИДЖЕТА С СУПЕР-ПАСПОРТОМ НА 6 ДНЕЙ -->
<div id="crmReminderWidget" style="position: fixed; bottom: 20px; right: 20px; z-index: 999999; font-family: sans-serif;">
    
    <!-- Круглая кнопка (Если задачи есть — оранжевая, если нет — серая) -->
    <div onclick="toggleReminderBoxWindow()" style="background: <?= $remindCount > 0 ? '#f6ad55' : '#3f3f46' ?>; color: <?= $remindCount > 0 ? '#151521' : '#fff' ?>; width: 55px; height: 55px; border-radius: 50%; display: flex; justify-content: center; align-items: center; cursor: pointer; box-shadow: 0 4px 15px rgba(0,0,0,0.3); font-weight: bold; font-size: 16px; user-select: none; position: relative;">
        🔔 <?php if ($remindCount > 0): ?>
            <span style="position: absolute; top: 0; right: 0; background: #ef4444; color: #fff; font-size: 11px; padding: 2px 6px; border-radius: 10px; border: 2px solid #1e1e2d;"><?= $remindCount ?></span>
        <?php endif; ?>
    </div>  

    <!-- Окно со списком фирм (ИСПРАВЛЕНО под коридор дедлайнов на неделю) -->
    <div id="crmReminderBox" style="display: none; position: absolute; bottom: 65px; right: 0; width: 320px; background: #1e1e2d; border: 1px solid #323248; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); padding: 15px; box-sizing: border-box; flex-direction: column; gap: 10px;">
        <h4 style="margin: 0 0 10px 0; font-size: 13px; color: #f6ad55; text-transform: uppercase; letter-spacing: 0.5px; text-align: left; border-bottom: 1px solid #323248; padding-bottom: 5px;">
            <?= $remindCount > 0 ? '🔥 Горящие контакты (≤ 4 дн.):' : '✅ Все контакты обработаны' ?>
        </h4>
        
        <div style="max-height: 200px; overflow-y: auto; display: flex; flex-direction: column; gap: 6px;">
            <?php if ($remindCount > 0): ?>
                <?php foreach ($remindList as $item): 
                    $isOverdue = (strtotime($item['next_contact_date']) < strtotime(date('Y-m-d')));
                ?>
                    <div style="background: #151521; padding: 8px 10px; border-radius: 6px; border-left: 3px solid <?= $isOverdue ? '#f56565' : '#f6ad55' ?>; text-align: left;">
                        <div style="font-size: 12px; font-weight: bold; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($item['client_name']) ?></div>
                        <div style="font-size: 10px; color: <?= $isOverdue ? '#f56565' : '#92929f' ?>; margin-top: 2px;">
                            <?= $isOverdue ? 'Просрочено: ' : 'Дата контакта: ' ?><?= date('d.m.Y', strtotime($item['next_contact_date'])) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <span style="color: #64748b; font-size: 12px; padding: 10px 0; display: block; text-align: center;">Все клиенты обработаны вовремя!</span>
            <?php endif; ?>
        </div>
    </div>
</div>

    <script>
    function toggleReminderBoxWindow() {
        const box = document.getElementById('crmReminderBox');
        if (box) {
            box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'flex' : 'none';
        }
    }
    </script>
<?php 
} 
?>
   <script>
// 1. Делаем функцию глобальной, чтобы кнопка onclick="openAddModal()" видела её из любого места
window.openAddModal = function() {
    const form = document.getElementById('clientForm');
    if (form) form.reset();
    
    const clientId = document.getElementById('client_id');
    if (clientId) clientId.value = '';
    
    const dateInp = document.getElementById('first_contact_date');
    if (dateInp) {
        dateInp.value = new Date().toISOString().split('T')[0];
        dateInp.readOnly = false; 
    }
    
    const modal = document.getElementById('clientModal');
    if (modal) modal.style.display = 'flex';
};

// 2. Привязываем обработчик отправки формы ТОЛЬКО после того, как вся страница полностью загрузилась
document.addEventListener("DOMContentLoaded", function() {
    const clientForm = document.getElementById('clientForm');
    
    if (!clientForm) {
        console.error("Критическая ошибка CRM: Форма с id='clientForm' не найдена на странице!");
        return;
    }

    // Сброс красной подсветки при вводе даты
    document.getElementById('next_contact_date')?.addEventListener('input', function() {
        this.style.border = '';
        this.style.backgroundColor = '';
    });

    // Навешиваем событие отправки
    clientForm.onsubmit = async function(e) {
        e.preventDefault();
        console.log("Сбор данных формы для отправки на save.php...");
        
        const nextContactInput = document.getElementById('next_contact_date');
        if (nextContactInput) {
            nextContactInput.style.border = ''; 
            nextContactInput.style.backgroundColor = ''; 
            
            if (!nextContactInput.value.trim()) {
                nextContactInput.style.border = '2px solid #ff4d4d';
                nextContactInput.style.backgroundColor = '#fff2f2'; 
                nextContactInput.focus();
                alert("⚠️ Ошибка заполнения:\nПожалуйста, укажите дату следующего контакта.");
                return; 
            }
        }

        const fd = new FormData(this);
        const safeAppend = (key, elementId) => {
            const el = document.getElementById(elementId);
            if (el) {
                fd.set(key, el.value); 
            }
        };

        safeAppend('id', 'client_id');
        safeAppend('client_name', 'client_name');
        safeAppend('unp', 'unp');
        safeAppend('contact_person', 'contact_person');
        safeAppend('phone', 'phone');
        safeAppend('product_type', 'product_type');
        safeAppend('source', 'source');
        safeAppend('first_contact_date', 'first_contact_date');
        safeAppend('next_contact_date', 'next_contact_date');
        safeAppend('status', 'status');
        safeAppend('email', 'email');
        safeAppend('comment', 'comment');

        try {
            const res = await fetch('save.php', { method: 'POST', body: fd });
            const rawText = await res.text();
            
            if (!rawText.trim().startsWith('{')) {
                alert("🚨 КРИТИЧЕСКИЙ СБОЙ БЭКЕНДА!\nСервер вернул ошибку PHP вместо JSON:\n\n" + rawText);
                return;
            }
            
            const result = JSON.parse(rawText);
            if (result.status === 'success') {
                window.location.reload(); 
            } else {
                if (result.message && result.message.includes('next_contact_date')) {
                    alert("⚠️ Не удалось сохранить данные:\nПоле 'Следующий контакт' обязательно для заполнения.");
                } else {
                    alert("⚠️ Не удалось сохранить изменения:\nПроверьте правильность заполнения полей или обратитесь к администратору.");
                }
            }
        } catch (err) {
            console.error("Критическая ошибка JS:", err);
            alert("🚨 Системный сбой JavaScript! Проверьте консоль F12.");
        }
    };
});
</script>
</body>
</html>