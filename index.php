<?php
// =========================================================================
// ЧИСТЫЙ МОНОЛИТНЫЙ WINDOWS-БЛОК CRM SANTEKS (БЕЗ ДУБЛИРОВАНИЯ И ВАРНИНГОВ)
// =========================================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

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
$orderByLogic = "ORDER BY (status != 'Отказ' AND next_contact_date <= CURDATE()) DESC, id DESC";

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
                $sql = "SELECT * FROM clients WHERE manager_id = ? AND status != 'Отказ'";
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
            $sql = "SELECT * FROM clients WHERE manager_id = ? AND status != 'Отказ'";
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
        // АДМИН: Считает 'В работе' по всей компании
        $sql_stats = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'В работе' THEN 1 ELSE 0 END) as in_work,
            SUM(CASE WHEN status = 'Отказ' THEN 1 ELSE 0 END) as refusals,
            SUM(CASE WHEN is_contract_signed = 1 THEN 1 ELSE 0 END) as signed
        FROM clients";
        $stats = $pdo->query($sql_stats)->fetch() ?: $stats;
    } else {
        // МЕНЕДЖЕР: Считает 'В работе' только по своим клиентам
        $sql_stats = "SELECT 
            COUNT(*) as total,  
            SUM(CASE WHEN status = 'В работе' THEN 1 ELSE 0 END) as in_work,
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'create_client_with_contract') {
    try {
        // 1. Считываем данные для карточки клиента
        $client_name    = trim($_POST['client_name'] ?? '');
        $unp            = trim($_POST['unp'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone          = trim($_POST['phone'] ?? '');
        $email          = trim($_POST['email'] ?? '');
        $source         = trim($_POST['source'] ?? 'Запрос');
        $ct_type        = trim($_POST['ct_type'] ?? 'Сантехника');
        $manager_id     = ($userRole === 'admin' && isset($_POST['assigned_manager_id'])) ? (int)$_POST['assigned_manager_id'] : $userId;

        // 2. Считываем данные для договора
        $contract_number = trim($_POST['contract_number'] ?? '');
        $contract_date   = !empty($_POST['contract_date']) ? $_POST['contract_date'] : date('Y-m-d');
        $product_type    = trim($_POST['product_type'] ?? 'Сантехника');

        if (empty($client_name) || empty($contract_number)) {
            throw new Exception("Критическая ошибка: Имя клиента и номер договора обязательны для заполнения!");
        }

        // БЕЗОПАСНАЯ ТРАНЗАКЦИЯ MySQL: Создаем клиента
        $stmt_client = $pdo->prepare("INSERT INTO clients (client_name, unp, contact_person, phone, email, status, source, ct_type, manager_id, is_contract_signed, first_contact_date) VALUES (?, ?, ?, ?, ?, 'В работе', ?, ?, ?, 1, CURDATE())");
        $stmt_client->execute([$client_name, $unp, $contact_person, $phone, $email, $source, $ct_type, $manager_id]);
        
        // Перехватываем автоинкрементный ID только что созданной фирмы
        $new_client_id = (int)$pdo->lastInsertId();

        if ($new_client_id > 0) {
            // Сразу же создаем договор, намертво связав его с новым ID
            $stmt_project = $pdo->prepare("INSERT INTO projects (client_id, contract_number, contract_date, product_type) VALUES (?, ?, ?, ?)");
            $stmt_project->execute([$new_client_id, $contract_number, $contract_date, $product_type]);
        }

        // Чисто обновляем страницу без зависаний JavaScript
        header("Location: index.php");
        exit;

    } catch (Exception $e) {
        die("Критический сбой СУБД при комплексном создании: " . $e->getMessage());
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
    <button type="button" onclick="openComplexModal();" style="background: #818cf8; color: #fff; border: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; font-size: 13px; cursor: pointer; transition: 0.2s;" onmouseover="this.style.background='#6366f1';" onmouseout="this.style.background='#818cf8';">
    💎 Добавить клиента и договор
</button>
<div id="complexModal" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.6); justify-content: center; align-items: center; z-index: 99999; box-sizing: border-box; padding: 15px;">
    <div style="background: #1e1e2d; border-radius: 8px; border: 1px solid #323248; padding: 25px; width: 550px; box-sizing: border-box; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
        
        <h3 style="margin-top: 0; color: #fff; font-size: 16px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #323248; padding-bottom: 10px; margin-bottom: 20px;">
            🗂 Создание контрагента и договора в одной связке
        </h3>

        <form method="POST" action="index.php" style="margin: 0; padding: 0;">
            <input type="hidden" name="action_type" value="create_client_with_contract">

            <!-- БЛОК 1: ДАННЫЕ ОРГАНИЗАЦИИ -->
            <div style="margin-bottom: 15px; display: flex; gap: 15px;">
                <div style="flex: 2; display: flex; flex-direction: column; gap: 4px;">
                    <label style="font-size: 10px; color: #92929f; font-weight: bold; text-transform: uppercase;">Название компании</label>
                    <input type="text" name="client_name" required placeholder="ООО СантехМонтаж" style="height: 38px; padding: 0 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px;">
                </div>
                <div style="flex: 1; display: flex; flex-direction: column; gap: 4px;">
                    <label style="font-size: 10px; color: #92929f; font-weight: bold; text-transform: uppercase;">УНП / ИНН</label>
                    <input type="text" name="unp" placeholder="123456789" style="height: 38px; padding: 0 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px;">
                </div>
            </div>

            <div style="margin-bottom: 15px; display: flex; gap: 15px;">
                <div style="flex: 1; display: flex; flex-direction: column; gap: 4px;">
                    <label style="font-size: 10px; color: #92929f; font-weight: bold; text-transform: uppercase;">Телефон связи</label>
                    <input type="text" name="phone" placeholder="+375 (...)" style="height: 38px; padding: 0 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px;">
                </div>
                <div style="flex: 1; display: flex; flex-direction: column; gap: 4px;">
                    <label style="font-size: 10px; color: #92929f; font-weight: bold; text-transform: uppercase;">Контактное лицо</label>
                    <input type="text" name="contact_person" placeholder="Иванов И.И." style="height: 38px; padding: 0 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px;">
                </div>
            </div>

            <!-- БЛОК 2: ДАННЫЕ ДОГОВОРА -->
            <div style="border-top: 1px dashed #323248; padding-top: 15px; margin-bottom: 15px; display: flex; gap: 15px;">
                <div style="flex: 1; display: flex; flex-direction: column; gap: 4px;">
                    <label style="font-size: 10px; color: #818cf8; font-weight: bold; text-transform: uppercase;">№ Нового договора</label>
                    <input type="text" name="contract_number" required placeholder="Напр: 240/Т" style="height: 38px; padding: 0 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; border-color: #4f46e5;">
                </div>
                <div style="flex: 1; display: flex; flex-direction: column; gap: 4px;">
                    <label style="font-size: 10px; color: #818cf8; font-weight: bold; text-transform: uppercase;">Дата заключения</label>
                    <input type="date" name="contract_date" value="<?= date('Y-m-d') ?>" style="height: 38px; padding: 0 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; color-scheme: dark; border-color: #4f46e5;">
                </div>
            </div>

            <div style="margin-bottom: 25px; display: flex; gap: 15px;">
                <div style="flex: 1; display: flex; flex-direction: column; gap: 4px;">
                    <label style="font-size: 10px; color: #92929f; font-weight: bold; text-transform: uppercase;">Вид продукции</label>
                    <select name="product_type" style="height: 38px; padding: 0 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; font-size: 13px; cursor: pointer;">
                        <option value="Сантехника">Сантехника</option>
                        <option value="Посуда">Посуда</option>
                        <option value="Резервуары">Резервуары</option>
                        <option value="ЕКМ">ЕКМ</option>
                        <option value="МПДУ">МПДУ</option>
                        <option value="Эмалированные таблички">Эмалированные таблички</option>
                        <option value="УОКТ">УОКТ</option>
                        <option value="Прочее">Прочее</option>
                    </select>
                </div>
                <div style="flex: 1; display: flex; flex-direction: column; gap: 4px;">
                    <label style="font-size: 10px; color: #92929f; font-weight: bold; text-transform: uppercase;">Источник привлечения</label>
                    <select name="source" style="height: 38px; padding: 0 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; font-size: 13px; cursor: pointer;">
                        <option value="Запрос">Запрос</option>
                        <option value="Холодный поиск">Холодный поиск</option>
                        <option value="Закупки">Закупки</option>
                    </select>
                </div>
            </div>

            <!-- ПОДВАЛ МОДАЛКИ: КНОПКИ -->
            <div style="display: flex; justify-content: flex-end; gap: 12px; border-top: 1px solid #323248; padding-top: 15px; background: transparent !important;">
                <button type="button" onclick="closeComplexModal();" style="height: 40px; padding: 0 20px; background: #242434; border: 1px solid #323248; color: #92929f; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px;">Отмена</button>
                <button type="submit" style="height: 40px; padding: 0 20px; background: #10b981; border: none; color: #fff; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px;">🚀 Создать связку</button>
            </div>
        </form>
    </div>
</div>

<script>
function openComplexModal() {
    document.getElementById('complexModal').style.display = 'flex';
}
function closeComplexModal() {
    document.getElementById('complexModal').style.display = 'none';
}
</script>


            
           <a href="export_excel.php?tab=<?= htmlspecialchars($current_tab) ?>&manager_id=<?= $filterManagerId ?>&source=<?= urlencode($sourceFilter) ?>&status=<?= urlencode($statusFilter) ?>&product_type=<?= urlencode($productFilter) ?>" 
   style="background: #10b981; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; font-size: 13px; display: inline-block; transition: 0.2s;"
   onmouseover="this.style.background='#059669';" 
   onmouseout="this.style.background='#10b981';">
    📊 СКАЧАТЬ ОТЧЕТ В EXCEL
</a>
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
    // Фильтр №2: По коммерческому статусу лида (Новый, В работе)
    if (!empty($statusFilter) && $current_tab !== 'refused') {
        $sql .= " AND status = ?";
        $params[] = $statusFilter;
    }


?>
        <!-- Фильтр: Статус лида (Возвращен в систему) -->
        <div style="display: flex; flex-direction: column; gap: 4px; width: 160px;">
            <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Статус клиента:</label>
            <select name="status" style="height: 42px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; cursor: pointer; font-size: 13px; box-sizing: border-box; width: 100%;">
                <option value="" <?= empty($statusFilter) ? 'selected' : '' ?>>Все статусы</option>
                <option value="Новый" <?= $statusFilter === 'Новый' ? 'selected' : '' ?>>🔴 Новый</option>
                <option value="В работе" <?= $statusFilter === 'В работе' ? 'selected' : '' ?>>🟡 В работе</option>
            </select>
        </div>

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
   <table style="margin-top:20px; width:100%;">
    <thead>
        <tr>
            <th>П/П</th>
            <th>ДАТА ПЕРВОГО КОНТАКТА</th>
            <th>КЛИЕНТ</th>
            <th>УНП</th>
            <th>КОНТАКТНОЕ ЛИЦО</th>
            <th>ТЕЛЕФОН</th>
            <th>EMAIL</th>
            <th>СТАТУС</th>
            <th>ИСТОЧНИК ПРИВЛЕЧЕНИЯ</th>
            <th>СЛЕД. КОНТАКТ</th>
            <th>КОММЕНТАРИЙ</th>
            <th>ВИД ПРОДУКЦИИ</th>
            <th>КОНТРАКТ</th>
            <th>ДЕЙСТВИЕ</th>
        </tr>
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

        <tr data-id="<?= $c['id'] ?>" class="<?= $isOverdue ? 'reminder-row' : '' ?>">
            <td><?= $i++ ?></td>
            <td class="cell-date"><?= date('d.m.Y', strtotime($c['first_contact_date'])) ?></td>
            <td class="cell-name"><strong><?= htmlspecialchars($c['client_name']) ?></strong></td>
            <td class="cell-unp"><?= htmlspecialchars($c['unp']) ?></td>
            <td class="cell-person"><?= htmlspecialchars($c['contact_person']) ?></td>
            <td class="cell-phone"><?= htmlspecialchars($c['phone']) ?></td>
            <td class="cell-email"><?= htmlspecialchars($c['email']) ?></td>
            <td class="cell-status"><?= htmlspecialchars($c['status']) ?></td>
            <td class="source"><?= htmlspecialchars($c['source'])?></td>
           <td class="cell-next" 
    style="cursor: pointer; transition: color 0.15s ease;" 
    onmouseover="this.style.color='#4f46e5';" 
    onmouseout="this.style.color='#fff';">
    <?= !empty($c['next_contact_date']) ? date('d.m.Y', strtotime($c['next_contact_date'])) : '—' ?>
</td>
           <!-- ЯЧЕЙКА КОММЕНТАРИЯ С КЛИКОМ ДЛЯ ПРОСМОТРА -->
<td class="cell-comment js-comment-preview" 
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
</td>

            <td class="cell-product"><?= htmlspecialchars($c['product_type']) ?></td>
            <td>
                <!-- ИСПРАВЛЕНО: Менеджер больше не может снять ранее поставленную галку контракта -->
<input type="checkbox" 
       class="contract-checkbox" 
       data-client-id="<?= (int)$c['id'] ?>" 
       <?= $c['is_contract_signed'] ? 'checked' : '' ?>
       <?= ($c['status'] === 'Отказ') ? 'disabled title="Нельзя заключить контракт при отказе"' : '' ?>
       <?= ($c['is_contract_signed'] && $userRole === 'manager') ? 'disabled title="Договор уже заключен! Снять галку может только Администратор."' : '' ?>>

            </td>
            <td>
      <!-- МАКСИМАЛЬНО ПРОСТАЯ И НАДЕЖНАЯ КНОПКА РЕДАКТИРОВАНИЯ -->
<button type="button" 
        class="btn-edit"
        onclick="openProtectedEditModal(<?= (int)$c['id'] ?>); return false;"
        style="background: #4f46e5; color: white; border: none; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 12px;">
    Ред.
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
    <div class="stylish-modal" style="background:#1e1e2d; padding:25px; border-radius:12px; width:600px; color:#fff; font-family: sans-serif;">
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
        <div class="form-group" style="flex: 1; text-align: left;">
            <label style="display:block; font-size:12px; color:#92929f; margin-bottom:5px;">Следующий контакт <span style="color:red;">*</span></label>
            <input type="date" id="next_contact_date" name="next_contact_date" required min="<?= date('Y-m-d') ?>" style="width: 100%; padding: 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; box-sizing: border-box;">
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
                <option value="В работе">В работе</option>
                <option value="Отказ">Отказ</option>
            </select>
        </div>
    </div>

    <!-- РЯД 6: КОММЕНТАРИЙ МЕНЕДЖЕРА (НА ВСЮ ШИРИНУ) -->
    <div class="form-row" style="margin-bottom: 20px;">
        <div class="form-group" style="width: 100%; text-align: left;">
            <label style="display:block; font-size:12px; color:#92929f; margin-bottom:5px;">Комментарий менеджера</label>
            <textarea id="comment" name="comment" placeholder="Введите примечание..." style="width: 100%; height: 100px; padding: 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; box-sizing: border-box; resize: vertical; font-family: sans-serif;"></textarea>
        </div>
    </div>

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

        
async function openProtectedEditModal(id) {
    console.log("Запрос точных данных из базы для клиента ID:", id);
    
    const modal = document.getElementById('clientModal');
    const form = document.getElementById('clientForm');
    
    if (!modal) {
        alert("Критическая ошибка: Форма clientModal не найдена в разметке!");
        return;
    }

    if (form) form.reset();


    try {
        // Делаем микро-запрос к нашему новому обработчику
        const res = await fetch('get_client.php?id=' + parseInt(id));
        const responseData = await res.json();
        
        if (responseData.status !== 'success') {
            alert("Ошибка получения данных: " + responseData.message);
            return;
        }

        const c = responseData.data; // Чистый массив полей из базы данных

        // Набиваем форму данными напрямую по ключам ассоциативного массива БД
        if(document.getElementById('client_id')) document.getElementById('client_id').value = c.id;
        if(document.getElementById('modalTitle')) document.getElementById('modalTitle').innerText = 'Редактирование клиента #' + c.id;
        
        // Автоподбор названия полей организации (имя, client_name, name)
        const nameField = document.getElementById('client_name') || document.getElementById('name');
        if (nameField) nameField.value = c.client_name || c.name || '';
        
        if(document.getElementById('unp')) document.getElementById('unp').value = c.unp || '';
        if(document.getElementById('contact_person')) document.getElementById('contact_person').value = c.contact_person || '';
        if(document.getElementById('phone')) document.getElementById('phone').value = c.phone || '';
        
        // БРОНЕБОЙНЫЙ АВТОПОДБОР ДЛЯ ПОЛЯ EMAIL (e_mail или email)
        const emailField = document.getElementById('e_mail') || document.getElementById('email');
        if (emailField) emailField.value = c.email || c.e_mail || '';
        
        if(document.getElementById('product_type')) document.getElementById('product_type').value = c.product_type || '';
        if(document.getElementById('first_contact_date')) document.getElementById('first_contact_date').value = c.first_contact_date || '';
        if(document.getElementById('next_contact_date')) document.getElementById('next_contact_date').value = c.next_contact_date || '';
        if(document.getElementById('status')) document.getElementById('status').value = c.status || '';
        
        // БРОНЕБОЙНЫЙ АВТОПОДБОР ДЛЯ ПОЛЯ ИСТОЧНИКА
       const sourceField = document.getElementById('source') || document.getElementById('client_source');
if (sourceField) {
    // Чистим текст от случайных скрытых пробелов, которые мог записать PHP
    const dbSourceValue = (c.source || '').trim();
    sourceField.value = dbSourceValue;
    console.log("Устанавливаю источник привлечения в селект:", dbSourceValue);
}
        // Показываем идеально заполненную форму
        modal.style.display = 'flex';
        console.log("Данные успешно подтянуты из API без единого сбоя.");

    } catch (err) {
        console.error("Сбой fetch при запросе клиента:", err);
        alert("Не удалось загрузить данные клиента. Проверьте файл get_client.php");
    }
}
// Сохранение (обработка формы)
document.getElementById('clientForm').onsubmit = async function(e) {
    e.preventDefault();
    console.log("Отправка формы сохранения клиента...");

    try {
        const res = await fetch('save.php', {
            method: 'POST',
            body: new FormData(this)
        });
        
        // Читаем сырой текст, если PHP выплюнет ошибку — мы увидим её текст
        const rawText = await res.text();
        console.log("Сырой ответ от save.php:", rawText);
        
        const result = JSON.parse(rawText);

        if (result.status === 'success') {
            const modal = document.getElementById('clientModal');
            if (modal) modal.style.display = 'none';
            window.location.reload(); // Перезагружаем страницу для обновления таблицы
        } else {
            alert("Отказ системы: " + result.message);
        }
    } catch (err) {
        console.error("Критический сбой отправки формы:", err);
        alert("Ошибка сети или синтаксиса при связи с сервером save.php. Проверьте консоль F12.");
    }
};

document.addEventListener('change', function(e) {
    if (e.target && e.target.classList.contains('js-manager-select')) {
        const managerId = parseInt(e.target.value) || 0;
        console.log("Выбран менеджер с ID:", managerId);
        
        if (managerId > 0) {
            // Принудительно отправляем админа по точному адресу
            window.location.href = 'index.php?manager_filter=' + managerId;
        } else {
            // Если выбрали "Все менеджеры" — сбрасываем фильтр
            window.location.href = 'index.php';
        }
    }
});

        const sourceVal = document.getElementById('source').value;
        if (!sourceVal) { 
             alert("Критическая ошибка: Поле 'Источник привлечения' является обязательным для заполнения!");
        }
        
      document.addEventListener('click', function(e) {
    const cell = e.target.closest('.js-comment-preview');
    
    // Проверяем, что клик пришелся именно на ячейку комментария
    if (cell) {
        // Защита: если кликнули по кнопке редактирования внутри этой строки, ничего не делаем
        if (e.target.classList.contains('btn-edit') || e.target.closest('button')) {
            return; // Тут return легален, так как мы внутри функции addeventlistener
        }

        e.preventDefault();
        
        const clientName = cell.getAttribute('data-client-name') || 'Клиент';
        const fullComment = cell.innerText.trim();

        const modal = document.getElementById('commentViewModal');
        const labelName = document.getElementById('commentModalClientLabel');
        const textContainer = document.getElementById('commentModalTextContainer');

        if (modal && textContainer) {
            if (labelName) labelName.innerText = clientName;
            textContainer.innerText = (fullComment === '—' || fullComment === '') ? 'Комментарии отсутствуют.' : fullComment;
            
            // Показываем окно просмотра
            modal.style.display = 'flex';
        }
    }
});

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
    fillField('comment', '.cell-comment');

    // Логика блокировки даты первого контакта для менеджера
    const dateInput = document.getElementById('first_contact_date');
    if (dateInput) {
        dateInput.disabled = (typeof userRole !== 'undefined' && userRole !== 'admin');
    }

    // Отображаем окно
    modal.style.display = 'flex';
}

// 2. СОХРАНЕНИЕ (Универсальное)
document.getElementById('clientForm').onsubmit = async function(e) {
    e.preventDefault();
    
    // Создаем пустой объект FormData
    const fd = new FormData();
    
    // Прямой сбор данных по ID (как они прописаны в HTML)
    fd.append('id', document.getElementById('client_id').value);
    fd.append('client_name', document.getElementById('client_name').value);
    fd.append('unp', document.getElementById('unp').value);
    fd.append('contact_person', document.getElementById('contact_person').value);
    fd.append('phone', document.getElementById('phone').value);
    fd.append('product_type', document.getElementById('product_type').value);
    fd.append('source', document.getElementById('source').value);
    fd.append('first_contact_date', document.getElementById('first_contact_date').value);
    fd.append('next_contact_date', document.getElementById('next_contact_date').value);
    fd.append('status', document.getElementById('status').value);
    fd.append('email', document.getElementById('email').value);
    // Комментарий (необязательный, проверяем наличие элемента)
    const comm = document.getElementById('comment');
    if (comm) fd.append('comment', comm.value);

    try {
        const res = await fetch('save.php', { 
            method: 'POST', 
            body: fd 
        });
        
        const result = await res.json();
        if (result.status === 'success') {
            location.reload();
        } else {
            // Если PHP не увидел название, он напишет это здесь
            alert("Ошибка сервера: " + result.message);
        }
    } catch (err) {
        console.error("Критическая ошибка:", err);
        alert("Не удалось сохранить. Проверьте консоль F12.");
    }
};

// Ждем загрузки страницы


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
   
</body>
</html>