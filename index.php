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
$productFilter = isset($_GET['product_type']) ? trim($_GET['product_type']) : '';
$dateFilter    = isset($_GET['next_contact_date_filter']) ? trim($_GET['next_contact_date_filter']) : '';
// 2. Очищаем дефолтные текстовые заглушки, чтобы они не летели в базу данных
if ($sourceFilter === 'Все источники') $sourceFilter = '';
if ($statusFilter === 'Все статусы')   $statusFilter = '';
if ($productFilter === 'Все виды')     $productFilter = '';
if ($userRole === 'admin') {
    if ($current_tab === 'refused') {
        $sql = "SELECT c.*, c.next_contact_date AS client_next_contact_date, p.product_type AS project_product_type, p.contract_number, p.id as pid 
                FROM clients c 
                LEFT JOIN projects p ON c.id = p.client_id 
                WHERE c.status = 'Отказ'";
    } else {
        $sql = "SELECT c.*, c.next_contact_date AS client_next_contact_date, p.product_type AS project_product_type, p.contract_number, p.id as pid 
                FROM clients c 
                LEFT JOIN projects p ON c.id = p.client_id 
                WHERE c.status != 'Отказ'";
    }
    $params = [];
} else {
    if ($current_tab === 'refused') {
        $sql = "SELECT c.*, c.next_contact_date AS client_next_contact_date, p.product_type AS project_product_type, p.contract_number, p.id as pid 
                FROM clients c 
                LEFT JOIN projects p ON c.id = p.client_id 
                WHERE c.manager_id = ? AND c.status = 'Отказ'";
    } else {
        $sql = "SELECT c.*, c.next_contact_date AS client_next_contact_date, p.product_type AS project_product_type, p.contract_number, p.id as pid 
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
    
    // ИСПРАВЛЕНО НАМЕРТВО: Кавычка возвращена на место внутри $_POST
    $next_contact_date = trim($_POST['next-contact-date'] ?? '0000-00-00');
    
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
                
            ]);
            
            if ($check_stmt->fetchColumn() > 0) {
                // Если дубликат найден, выводим красивую ошибку и останавливаем скрипт
                die("<div style='background: #f8d7da; color: #721c24; padding: 20px; border: 1px solid #f5c6cb; border-radius: 6px; margin: 20px; font-family: sans-serif;'>
                        <strong>⚠️ Ошибка дублирования данных:</strong> Контрагент с названием «" . htmlspecialchars($client_name) . "» и УНП «" . htmlspecialchars($unp) . "» уже существует в системе! 
                        <br><br><a href='index.php' style='color: #721c24; font-weight: bold;'>Вернуться назад</a>
                     </div>");
            }
            $check_stmt->execute([
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
                        next_contact_date,
                        manager_id, 
                        product_type,
                        website
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
                        :product_type
                        :website
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
                ':next_contact_date' => $next_contact_date,
                ':manager_id'     => $manager_id,
                ':product_type'   => $product_type, // Наш УОКТ прилетит строго сюда!
                ':website' => $website
            ]);
            
            header("Location: index.php");
            exit;
        } catch (Exception $e) {
            // Если в СУБД имя колонки пишется как product_info — бэкенд честно выведет ошибку на экран
            die("Критический сбой СУБД при добавлении клиента: " . $e->getMessage());
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_mode']) && $_POST['action_mode'] === 'check_unp_duplicate_live') {
    header('Content-Type: application/json');
    if (ob_get_length()) ob_clean();

    try {
        $unp = trim($_POST['unp'] ?? '');

        if (empty($unp)) {
            echo json_encode(['status' => 'clean']); exit;
        }

        // Ищем компанию с таким же УНП в таблице clients
        $stmt = $pdo->prepare("SELECT client_name FROM clients WHERE unp = ? LIMIT 1");
        $stmt->execute([$unp]);
        $existingClient = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingClient) {
            // Если нашли — отдаем JSON с флагом дубликата и именем компании
            echo json_encode([
                'status' => 'duplicate',
                'client_name' => htmlspecialchars($existingClient['client_name'])
            ]);
            exit;
        } else {
            echo json_encode(['status' => 'clean']); 
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); 
        exit;
    }
} 
$stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

<script>
document.addEventListener("DOMContentLoaded", function() {
    // ЖЕЛЕЗНЫЙ ХИКС ID: Находим инпут по любому из двух возможных ID в твоей системе
    const unpInputElement = document.getElementById('complex_unp') || document.getElementById('add_client_unp');
    const errorMsg = document.getElementById('complex_unp_error_msg');

    if (!unpInputElement) {
        console.error("Крит: Инпут УНП не найден ни по ID 'complex_unp', ни по 'add_client_unp'!");
        return;
    }

    // Навешиваем живую проверку дубликата при вводе
    unpInputElement.addEventListener('input', async function() {
        const unpValue = this.value.trim();
        
        // Находим форму и кнопку сохранения динамически
        const currentForm = document.getElementById('clientForm') || this.closest('form') || document.querySelector('form');
        const submitBtn = currentForm ? currentForm.querySelector('button[type="submit"]') : document.querySelector('button[type="submit"]');

        // Отключаем блокировку, если это режим РЕДАКТИРОВАНИЯ существующего клиента
        const clientIdInput = document.getElementById('client_id');
        if (clientIdInput && parseInt(clientIdInput.value, 10) > 0) return;

        // Если символов не 9, возвращаем дефолтные стили и тушим ошибку
        if (unpValue.length !== 9) {
            this.style.borderColor = '#323248';
            this.style.boxShadow = 'none';
            if (errorMsg) errorMsg.style.display = 'none';
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                submitBtn.style.cursor = 'pointer';
            }
            return;
        }

        console.log("=== ЖИВАЯ ПРОВЕРКА УНП НА ДУБЛИКАТЫ ЧЕРЕЗ SAVE.PHP: " + unpValue + " ===");

        const fd = new FormData();
        fd.append('action_mode', 'check_unp_duplicate_live');
        fd.append('unp', unpValue);

        try {
            const res = await fetch('save.php', { method: 'POST', body: fd });
            const result = await res.json();

            if (result.status === 'duplicate') {
                // Красная неоновая блокировка поля и кнопки
                this.style.borderColor = '#ef4444';
                this.style.boxShadow = '0 0 10px rgba(239, 68, 68, 0.3)';
                
                if (errorMsg) {
                    errorMsg.innerText = `⚠️ Такой УНП уже зарегистрирован за компанией "${result.client_name}"!`;
                    errorMsg.style.display = 'block';
                }
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.style.opacity = '0.5';
                    submitBtn.style.cursor = 'not-allowed';
                }
            } else {
                // Изумрудный успех — УНП чист!
                this.style.borderColor = '#10b981';
                this.style.boxShadow = '0 0 10px rgba(16, 185, 129, 0.2)';
                if (errorMsg) errorMsg.style.display = 'none';
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                    submitBtn.style.cursor = 'pointer';
                }
            }
        } catch (err) {
            console.error("Ошибка асинхронной проверки УНП:", err);
        }
    });

    // Проверка корректности длины при потере фокуса (blur)
    unpInputElement.addEventListener('blur', function() {
        const unpValue = this.value.trim();
        
        if (errorMsg && errorMsg.style.display === 'block' && errorMsg.innerText.includes('зарегистрирован')) return;

        if (unpValue.length > 0 && unpValue.length !== 9) {
            this.style.borderColor = '#ef4444';
            if (errorMsg) {
                errorMsg.innerText = '⚠️ Внимание: УНП должен содержать 9 знаков. Проверьте корректность данных.';
                errorMsg.style.display = 'block';
            }
        }
    });
});
</script>
    

          

<script>
    function openComplexModal() {
    console.log("=== ПОПЫТКА ОТКРЫТИЯ МОДАЛКИ СВЯЗКИ КОНТРАГЕНТА И ДОГОВОРА ===");
    
    // Всеядный поиск контейнера модалки по ID
    const modal = document.getElementById('complexModal');
    
    if (!modal) {
        console.error("🚨 КРИТ: Элемент с id='complexModal' физически не найден в DOM-дереве файла index.php!");
        alert("Ошибка интерфейса: Модальное окно связки не найдено на странице. Проверьте ID контейнера внизу файла.");
        return;
    }

    // 1. Красиво разворачиваем оверлей по центру экрана
    modal.style.setProperty('display', 'flex', 'important');
    
    // 2. СИНХРОНИЗАЦИЯ: Автоматически ставим сегодняшнюю дату в календарь заключения договора
    const complexDateInput = document.getElementById('complex_contract_date');
    if (complexDateInput) {
        const today = new Date();
        const yyyy = today.getFullYear();
        const mm = String(today.getMonth() + 1).padStart(2, '0');
        const dd = String(today.getDate()).padStart(2, '0');
        
        complexDateInput.value = `${yyyy}-${mm}-${dd}`;
        console.log("📅 АВТО-ДАТА: В инпут успешно установлена текущая дата:", complexDateInput.value);
    }

    // 3. Вычищаем старые ошибки дубликатов УНП при новом открытии
    const errorMsg = document.getElementById('complex_unp_error_msg');
    if (errorMsg) errorMsg.style.display = 'none';
    
    const unpInput = document.getElementById('complex_unp');
    if (unpInput) {
        unpInput.style.borderColor = '#323248';
        unpInput.style.boxShadow = 'none';
    }
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
                <option value="Резервуар"<?= $productFilter === 'Резервуары'? 'selected' : '' ?>>Резервуар</option>
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
 <!-- НАМЕРТВО ИСПРАВЛЕНО: Всеядный сбор ID внутри ячейки для вывода подсказок -->
<td class="cell-email" style="padding: 14px 10px; text-align: left; color: #cbd5e1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
    <?php
    $hintText = '';
    try {
        // Жесткая проверка: ищем ID в переменной $c по всем регистрам
        $currentClientId = (int)($c['id'] ?? ($c['client_id'] ?? 0));
        
        if ($currentClientId > 0) {
            $stmtHint = $pdo->prepare("SELECT name, position, phone, email FROM client_contacts WHERE client_id = ?");
            $stmtHint->execute([$currentClientId]);
            $subContacts = $stmtHint->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($subContacts)) {
                $lines = [];
                foreach ($subContacts as $sc) {
                    $line = "👤 " . trim($sc['name']);
                    if (!empty($sc['position'])) $line .= " (" . trim($sc['position']) . ")";
                    if (!empty($sc['phone']))    $line .= " 📞 " . trim($sc['phone']);
                    if (!empty($sc['email']))    $line .= " ✉️ " . trim($sc['email']);
                    $lines[] = $line;
                }
                $hintText = "Дополнительные контакты контрагента:\n" . implode("\n", $lines);
            } else {
                $hintText = "Дополнительных контактных лиц не зарегистрировано";
            }
        } else {
            $hintText = "Ошибка фронтенда: ID клиента равен 0";
        }
    } catch (Exception $e) {
        $hintText = "Ошибка СУБД: " . $e->getMessage();
    }
    ?>
    <span style="cursor: help; border-bottom: 1px dashed rgba(203, 213, 225, 0.3); display: inline-block; width: 100%;" 
          title="<?= htmlspecialchars($hintText, ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars($c['email'] ?: '—') ?>
    </span>
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
<!-- СТОЛБЕЦ ДАТЫ СЛЕДУЮЩЕГО КОНТАКТА (БРОНЕБОЙНАЯ ПРОВЕРКА ПО ID ПРОЕКТА) -->
<!-- СТОЛБЕЦ ДАТЫ СЛЕДУЮЩЕГО КОНТАКТА (ТУПАЯ ПРЯМАЯ ПРОВЕРКА ПО ПОЛЮ NEXT_CONTACT_DATE) -->
<!-- 8. СЛЕДУЮЩИЙ КОНТАКТ (НАМЕРТВО ИСПРАВЛЕНО: ВСТАЛО НА СВОЁ МЕСТО И ЧИТАЕТ МАССИВ $c) -->
     <td class="cell-next-contact" style="padding: 14px 10px; text-align: center; font-size: 13px; color: #a1a1aa; font-family: monospace; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; border: none !important;">
                <?php 
                // 1. Вытаскиваем чистый системный ID текущего клиента из строки
                $directClientId = (int)($c['id'] ?? 0);
                
                if ($directClientId > 0) {
                    // 2. ХИРУРГИЧЕСКИЙ ЗАПРОС: тащим флаг договора и оригинальную дату созвона клиента напрямую из БД
                    $dbQuery = $pdo->prepare("SELECT is_contract_signed, next_contact_date FROM clients WHERE id = ? LIMIT 1");
                    $dbQuery->execute([$directClientId]);
                    $clientDirectData = $dbQuery->fetch(PDO::FETCH_ASSOC);
                    
                    $isSignedFlag = (int)($clientDirectData['is_contract_signed'] ?? 0);
                    $rawNextDate  = trim($clientDirectData['next_contact_date'] ?? '');
                    
                    // 3. ПРОВЕРКА НАЛИЧИЯ ДОГОВОРА: заглядываем в таблицу проектов, привязанных к этому клиенту
                    $projectQuery = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE client_id = ? AND contract_number IS NOT NULL AND contract_number != '' AND contract_number != '—'");
                    $projectQuery->execute([$directClientId]);
                    $hasRealProjectsInDb = ((int)$projectQuery->fetchColumn() > 0);
                    
                    // РЕГЛАМЕНТ Е: Если договор уже подписан ИЛИ у компании есть активные проекты в СУБД — ЖЁСТКО ставим прочерк!
                    if ($isSignedFlag === 1 || $hasRealProjectsInDb) {
                        echo '<span style="color: #4b5563; font-weight: bold;">—</span>';
                    } else {
                        // Если договора нет — выводим настоящую дату созвона из карточки контрагента
                        if (!empty($rawNextDate) && $rawNextDate !== '0000-00-00' && strtolower($rawNextDate) !== 'null') {
                            echo date('d.m.Y', strtotime($rawNextDate));
                        } else {
                            echo '<span style="color: #4b5563; font-weight: bold;">—</span>';
                        }
                    }
                } else {
                    // Подстраховка на случай сбоя структуры цикла
                    echo '<span style="color: #4b5563; font-weight: bold;">—</span>';
                }
                ?>
            </td>
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
     

        console.log("Данные успешно подтянуты из API без единого сбоя.");



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
<script>
let ContactIndex = 0;

/**
 * ГЛОБАЛЬНАЯ ФУНКЦИЯ ДОБАВЛЕНИЯ ПОЛЕЙ КОНТАКТА
 */
// НАМЕРТВО ИСПРАВЛЕНО: Полная синхронизация имен переменных и структуры СУБД Santeks
function addContactField(data = null) {
    const container = document.getElementById('contactsContainer');
    if (!container) return; 

    // ЖЕЛЕЗНЫЙ ФИКС ИМЕН: Переменные строго соответствуют выводу в HTML-шаблоне!
    let cPostal    = data ? (data.postal_address || '') : '';
    let cNotes     = data ? (data.function_notes || '') : '';
    let cName      = data ? (data.name || (data.contact_name || '')) : '';
    let cPosition  = data ? (data.position || (data.contact_role || '')) : '';
    let cPhone     = data ? (data.phone || (data.contact_phone || '')) : '';
    let cEmail     = data ? (data.email || (data.contact_email || '')) : '';

    const contactRow = document.createElement('div');
    contactRow.className = 'contact-card';
    contactRow.setAttribute('data-index', contactIndex);

    const deleteBtn = contactIndex > 0 
        ? `<button type="button" onclick="removeContactField(${contactIndex})" style="position: absolute; top: 12px; right: 12px; background: none; border: none; color: #ef4444; font-size: 20px; cursor: pointer; font-weight: bold; padding: 0; line-height: 1;">&times;</button>`
        : '';

    contactRow.innerHTML = `
        ${deleteBtn}
        <div class="crm-grid-2" style="margin-bottom: 10px;">
            <div class="form-group">
                <label>ФИО ответственного лица ${contactIndex === 0 ? '<span style="color:#ef4444;">*</span>' : ''}</label>
                <input type="text" name="contacts[${contactIndex}][name]" ${contactIndex === 0 ? 'required' : ''} value="${escapeHtmlQuotes(cName)}" class="crm-input" placeholder="Иванов Иван Иванович">
            </div>
            <div class="form-group">
                <label>Должность лица</label>
                <input type="text" name="contacts[${contactIndex}][position]" value="${escapeHtmlQuotes(cPosition)}" class="crm-input" placeholder="Напр: Главный снабженец">
            </div>
        </div>
        <div class="crm-grid-2" style="margin-bottom: 10px;">
            <div class="form-group">
                <label>Телефон прямой</label>
                <input type="text" name="contacts[${contactIndex}][phone]" value="${escapeHtmlQuotes(cPhone)}" class="crm-input" placeholder="+375 (...)">
            </div>
            <div class="form-group">
                <label>Email лица</label>
                <input type="email" name="contacts[${contactIndex}][email]" value="${escapeHtmlQuotes(cEmail)}" class="crm-input" placeholder="ivanov@partner.com">
            </div>
        </div>
        
        <!-- Почтовый адрес контрагента -->
        <div class="form-group" style="margin-bottom: 10px;">
            <label>Почтовый адрес</label>
            <input type="text" name="contacts[${contactIndex}][postal_address]" value="${escapeHtmlQuotes(cPostal)}" class="crm-input" placeholder="246000">
        </div>

        <!-- Сфера ответственности и Примечания -->
        <div class="form-group">
            <label>Примечания по функциям / Сфера ответственности</label>
            <textarea name="contacts[${contactIndex}][function_notes]" rows="2" class="crm-textarea" placeholder="ЛПР, принимает итоговые решения по отгрузкам...">${escapeHtmlQuotes(cNotes)}</textarea>
        </div>
    `;

    container.appendChild(contactRow);
    contactIndex++;
    container.scrollTop = container.scrollHeight;
}

// Вспомогательная функция защиты от кавычек, ломающих атрибуты HTML-тегов
function escapeHtmlQuotes(text) {
    if (!text) return '';
    return text.toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}


function removeContactField(index) {
    const row = document.querySelector(`.contact-item-row[data-index="${index}"]`);
    if (row) row.remove();
}

/**
 * ФУНКЦИЯ РЕДАКТИРОВАНИЯ КЛИЕНТА (ВЫНЕСЕНА В ФИНАЛЬНЫЙ БЛОК)
 */
async function openProtectedEditModal(id) {
    console.log("=== ЗАПУСК ПАКЕТНОЙ ВЫГРУЗКИ ДАННЫХ ИЗ СУБД ДЛЯ КЛИЕНТА #" + id + " ===");
    
    const modal = document.getElementById('clientModal');
    const form = document.getElementById('clientForm');
    
    try {
        // Фоновый AJAX-запрос к API-контроллеру карточки
        const res = await fetch('get_client.php?id=' + parseInt(id, 10));
        const responseData = await res.json();
        
        if (responseData.status !== 'success' || !responseData.data) {
            alert("🚨 Ошибка API СУБД: " + (responseData.message || "Не удалось получить данные клиента."));
            return;
        }

        const c = responseData.data; 
        console.log("CHECKPOINT 1: Данные успешно получены из базы данных.", c);

        // 1. Инициализация базовых системных маркеров ID и Заголовка
        if (document.getElementById('client_id')) {
            document.getElementById('client_id').value = c.id;
        }
        if (document.getElementById('modalTitle')) {
            document.getElementById('modalTitle').innerText = 'Редактирование клиента #' + c.id;
        }
        
        // 2. Локальный поиск и заполнение Имени компании
        const nameField = modal ? (modal.querySelector('#client_name') || modal.querySelector('#name') || modal.querySelector('input[name="client_name"]')) : null;
        if (nameField) nameField.value = c.client_name || '';
        
        // 3. ХИРУРГИЧЕСКИЙ ФИКС УНП: Ищем строго внутри модалки, чтобы не путать с формой добавления!
        if (modal) {
            const modalUnpInput = modal.querySelector('#unp') 
                               || modal.querySelector('#complex_unp') 
                               || modal.querySelector('input[name="unp"]');
            
            if (modalUnpInput) {
                // Приоритет заглавному ключу UNP из структуры СУБД Santeks
                modalUnpInput.value = c.UNP || (c.unp || '');
                
                // Намертво тушим старые красные рамки ошибок от формы добавления
                modalUnpInput.style.borderColor = '#323248';
                modalUnpInput.style.boxShadow = 'none';
            }
            
            // Скрываем текстовую подсказку ошибки дубликата в модалке правок
            const errorMsg = modal.querySelector('#complex_unp_error_msg') || modal.querySelector('#add_unp_error_msg');
            if (errorMsg) errorMsg.style.display = 'none';
        }
        console.log("CHECKPOINT 2: Базовые текстовые поля (Имя, УНП) заполнены.");

        // 4. Заполнение поля Сайта / Веб-ресурса
        const websiteField = modal ? (modal.querySelector('#client_website') || modal.querySelector('input[name="website"]')) : null;
        if (websiteField) {
            websiteField.value = c.website || '';
        }
        console.log("CHECKPOINT 3: Поле сайта обработано.");

        // 5. НАМЕРТВО ИСПРАВЛЕНО: Динамическая заливка серверного грида contactsGrid данными из API
        const gridContainer = document.getElementById('contactsGrid');
        if (gridContainer) {
            gridContainer.innerHTML = ''; // Стираем серую заглушку или старые карточки
            
            // Намертво сбрасываем глобальный счетчик инпутов для сохранения
            window.contactIndex = 0; 

            if (c.contacts && Array.isArray(c.contacts) && c.contacts.length > 0) {
                console.log(`=== ЖИВАЯ ОТРИСОВКА ГРИДА: Найдено ${c.contacts.length} лиц для модалки ===`, c.contacts);
                
                c.contacts.forEach((contact, idx) => {
                    // Вытаскиваем значения, страхуясь от NULL под структуру твоей СУБД
                    let lcName   = contact.contact_name || (contact.name || '');
                    let lcRole   = contact.contact_role || (contact.position || '');
                    let lcPhone  = contact.phone || '';
                    let lcEmail  = contact.email || '';
                    let lcPostal = contact.postal_address || '';
                    let lcNotes  = contact.function_notes || '';

                    // Создаем карточку лица в фирменном стиле Santeks Premium
                    const card = document.createElement('div');
                    card.style.cssText = "background: #151521; border: 1px solid #323248; border-radius: 8px; padding: 12px; display: flex; flex-direction: column; gap: 4px; position: relative; text-align: left; box-sizing: border-box; width: 100%;";
                    
                    card.innerHTML = `
                        <div style="font-weight: bold; color: #fff; font-size: 13px; padding-right: 30px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            👤 ${escapeHtmlQuotes(lcName)}
                        </div>
                        ${lcRole ? `<div style="color: #92929f; font-size: 11px;">💼 ${escapeHtmlQuotes(lcRole)}</div>` : ''}
                        ${lcPhone ? `<div style="color: #818cf8; font-family: monospace; font-size: 11px;">📞 ${escapeHtmlQuotes(lcPhone)}</div>` : ''}
                        ${lcEmail ? `<div style="color: #cbd5e1; font-size: 11px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">✉️ ${escapeHtmlQuotes(lcEmail)}</div>` : ''}
                        
                        <!-- Кнопка удаления лица из массива карточек -->
                        <div style="position: absolute; right: 8px; top: 8px;">
                            <button type="button" onclick="this.parentElement.parentElement.remove();" style="background: rgba(255,255,255,0.03); border: 1px solid #323248; color: #ef4444; border-radius: 4px; padding: 2px 6px; font-size: 11px; cursor: pointer; font-weight: bold;">×</button>
                        </div>
                        
                        <!-- Скрытая структура инпутов для сборщика FormData при сохранении формы -->
                        <input type="hidden" name="contacts[${window.contactIndex}][name]" value="${escapeHtmlQuotes(lcName)}">
                        <input type="hidden" name="contacts[${window.contactIndex}][position]" value="${escapeHtmlQuotes(lcRole)}">
                        <input type="hidden" name="contacts[${window.contactIndex}][phone]" value="${escapeHtmlQuotes(lcPhone)}">
                        <input type="hidden" name="contacts[${window.contactIndex}][email]" value="${escapeHtmlQuotes(lcEmail)}">
                        <input type="hidden" name="contacts[${window.contactIndex}][postal_address]" value="${escapeHtmlQuotes(lcPostal)}">
                        <input type="hidden" name="contacts[${window.contactIndex}][function_notes]" value="${escapeHtmlQuotes(lcNotes)}">
                    `;
                    
                    gridContainer.appendChild(card);
                    window.contactIndex++;
                });
            } else {
                // Если у клиента в базе реально нет лиц
                gridContainer.innerHTML = `<div style="grid-column: span 2; color: #64748b; font-size: 13px; padding: 15px; text-align: center; border: 1px dashed #323248; border-radius: 6px; width: 100%; box-sizing: border-box;">У этого контрагента пока нет зарегистрированных лиц. Нажмите «Добавить лицо» для создания.</div>`;
            }
        }
        
        // Всегда принудительно сбрасываем отображение на режим списка (грида), пряча форму добавления
        if (typeof toggleContactView === 'function') {
            toggleContactView(false); 
        }
        console.log("CHECKPOINT 4: Динамический VIP-грид контактов успешно заполнен данными СУБД.");
       // 6. НАМЕРТВО ИСПРАВЛЕНО: Поля обратной совместимости пишут строго в главные инпуты компании
        // Ищем инпуты компании строго вне контейнера контактов, чтобы не затирать динамические лица!
        const mainForm = form || document.getElementById('clientForm');
        
        if (mainForm) {
            const mainContactPerson = mainForm.querySelector('#contact_person') || document.getElementById('contact_person');
            if (mainContactPerson) mainContactPerson.value = c.contact_person || '';
            
            // Ищем базовый телефон компании (проверяем, чтобы это не был инпут из контактов)
            const mainPhone = mainForm.querySelector('#phone') || document.getElementById('phone');
            if (mainPhone && !mainPhone.closest('#contactsContainer')) mainPhone.value = c.phone || '';
            
            const mainEmail = mainForm.querySelector('#e_mail') || mainForm.querySelector('#email') || document.getElementById('email');
            if (mainEmail && !mainEmail.closest('#contactsContainer')) mainEmail.value = c.email || '';
        }
        console.log("CHECKPOINT 5: Поля обратной совместимости пройдены без затирания контактов.");

        // 7. Парсинг календаря и валидация даты следующего контакта (Фикс бага 104)
        const nextDateInput = document.getElementById('add_client_next_date') || document.getElementById('next_contact_date');
        if (nextDateInput) {
            let apiDateValue = (c.next_contact_date || '').toString().trim();
            if (apiDateValue && apiDateValue !== '—' && apiDateValue !== 'NULL' && apiDateValue !== '0000-00-00') {
                if (apiDateValue.includes('-')) apiDateValue = apiDateValue.substring(0, 10);
                nextDateInput.value = apiDateValue;
            } else {
                nextDateInput.value = ''; 
            }
            nextDateInput.style.borderColor = '#323248';
            nextDateInput.style.boxShadow = 'none';
        }
        console.log("CHECKPOINT 6: Даты и календари зафиксированы.");

        // 8. Синхронизация системных селектов Статуса и Комментария
        if (document.getElementById('status')) document.getElementById('status').value = c.status || 'Новый';
        if (document.getElementById('comment')) document.getElementById('comment').value = c.comment || '';
        
        // 9. Фикс селекта вида продукции
        const productTypeSelect = document.getElementById('product_type') || document.querySelector('select[name="product_type"]');
        if (productTypeSelect) productTypeSelect.value = c.product_type || '';

        // 10. Фикс источника привлечения (Поддержка кастомного DIV-модуля Santeks)
        const sourceValue = c.source || (c.lead_source || '');
        const customSourceText = document.getElementById('js-custom-select-text');
        const customSourceInput = document.getElementById('js-real-lead-source');
        
        if (customSourceInput && customSourceText) {
            customSourceInput.value = sourceValue;
            customSourceText.innerText = sourceValue ? sourceValue : 'Выберите источник...';
            customSourceText.style.color = sourceValue ? '#ffffff' : '#64748b';
        }
        if (document.getElementById('source')) document.getElementById('source').value = sourceValue;
        
        console.log("CHECKPOINT 7: Системные выпадающие списки синхронизированы.");

        // ФИНАЛЬНЫЙ ЗАПУСК И ОТКРЫТИЕ ОКНА МОДАТКИ
        if (modal) {
            modal.style.setProperty('display', 'flex', 'important');
            modal.classList.add('active');
            
            // Гарантированно возвращаем кнопку сохранения в рабочее состояние
            const submitBtn = modal.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                submitBtn.style.cursor = 'pointer';
                submitBtn.innerText = "Сохранить изменения";
            }
            console.log("CHECKPOINT ФИНАЛ: МОДАЛКА РЕАКТИВНО РАЗВЕРНУТА И ПОЛНОСТЬЮ ЗАПОЛНЕНА!");
        }

    } catch (err) {
        console.error("🚨 КРИТИЧЕСКИЙ СБОЙ ВНУТРИ ФУНКЦИИ openProtectedEditModal:", err);
    }
}
    </script>
</div>
<!-- МОДАЛЬНОЕ ОКНО (ОДНО НА ВЕСЬ ФАЙЛ, ВНЕ ЦИКЛА!) -->
<div id="clientModal" class="modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); justify-content:center; align-items:center; z-index:9999;">
    <div class="stylish-modal" style="background:#1e1e2d; padding:25px; border-radius:12px; height:800px; width:800px; color:#fff; font-family:sans-serif;">
        
        <h2 id="modalTitle" style="margin-top:0; text-align:left;">Добавить клиента</h2>
        
        <!-- ОСТАВЛЯЕМ СТРОГО ОДИН ТЕГ ФОРМЫ С НАШИМ СКРЫТЫМ ИНПУТОМ ID -->
        <form id="clientForm" autocomplete="off" style="margin: 0; padding: 0;">
            <input type="hidden" id="client_id" name="id" value="0">
            
            <div class="modal-body">
                
                <!-- РЯД 1: КЛЮЧЕВАЯ ИНФОРМАЦИЯ -->
                <div class="crm-grid-3">
                    <div class="form-group">
                        <label>Название организации <span style="color:#ef4444;">*</span></label>
                        <input type="text" id="client_name" name="client_name" required class="crm-input" placeholder="СЗАО «Сантэкс»">
                    </div>
                    <div class="form-group">
                        <label>УНП контрагента <span style="color:#ef4444;">*</span></label>
                        <input type="text" 
           id="js_client_unp_input" 
           name="unp" 
           required 
           maxlength="9"
           placeholder="9 знаков" 
           oninput="checkUnpDuplicateLive(this.value);"
           style="height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; transition: all 0.2s;">
           <script>
          async function checkUnpDuplicateLive(unpValue) {
    const unpInput = document.getElementById('add_client_unp') || document.getElementById('unp');
    const errorMsg = document.getElementById('add_unp_error_msg');
    
    // ЖЕЛЕЗНЫЙ ПОИСК КНОПКИ: Ищем форму по ID или тегу, вытаскивая кнопку отправки
    const currentForm = document.getElementById('clientForm') || document.querySelector('form');
    const submitBtn = currentForm ? currentForm.querySelector('button[type="submit"]') : document.querySelector('button[type="submit"]');

    // Проверяем: если мы РЕДАКТИРУЕМ старого клиента (в поле client_id лежит число > 0), то проверку дублей отключаем
    const clientIdInput = document.getElementById('client_id');
    const isEditing = clientIdInput && parseInt(clientIdInput.value, 10) > 0;
    if (isEditing) {
        if (unpInput) unpInput.style.borderColor = '';
        if (errorMsg) errorMsg.style.display = 'none';
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
            submitBtn.style.cursor = 'pointer';
        }
        return;
    }

    const cleanUnp = unpValue.trim();

    // Валидация запускается строго при вводе полных 9 цифр УНП
    if (cleanUnp.length !== 9) {
        if (unpInput) unpInput.style.borderColor = '';
        if (errorMsg) errorMsg.style.display = 'none';
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
            submitBtn.style.cursor = 'pointer';
        }
        return;
    }

    console.log("=== ЖИВАЯ ПРОВЕРКА УНП НА ДУБЛИКАТЫ ЧЕРЕЗ SAVE.PHP: " + cleanUnp + " ===");

    const fd = new FormData();
    fd.append('action_mode', 'check_unp_duplicate_live');
    fd.append('unp', cleanUnp);

    try {
        const res = await fetch('save.php', { method: 'POST', body: fd });
        const result = await res.json();

        if (result.status === 'duplicate') {
            // Мгновенная красная неоновая блокировка интерфейса
            if (unpInput) {
                unpInput.style.borderColor = '#ef4444';
                unpInput.style.boxShadow = '0 0 10px rgba(239, 68, 68, 0.3)';
            }
            if (errorMsg) {
                errorMsg.innerText = `⚠️ Такой УНП уже зарегистрирован за компанией "${result.client_name}"!`;
                errorMsg.style.display = 'block';
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.5';
                submitBtn.style.cursor = 'not-allowed';
            }
        } else {
            // Всё чисто — подсвечиваем изумрудным успехом
            if (unpInput) {
                unpInput.style.borderColor = '#10b981';
                unpInput.style.boxShadow = '0 0 10px rgba(16, 185, 129, 0.2)';
            }
            if (errorMsg) errorMsg.style.display = 'none';
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                submitBtn.style.cursor = 'pointer';
            }
        }
    } catch (err) {
        console.error("Ошибка асинхронного транспорта УНП:", err);
    }
}
           </script>
                        <div id="js-unp-error-block" style="display:none; font-size:10px; color:#ef4444; font-weight:600;"></div>
                    </div>
                    <div class="form-group">
                        <label>Сайт компании</label>
                        <input type="text" id="client_website" name="website" class="crm-input" placeholder="example.com">
            <script>
        function toggleContactView(showForm = null) {
    const grid = document.getElementById('contactsGrid');
    const formArea = document.getElementById('contactsFormArea');
    const btn = document.getElementById('toggleContactViewBtn');
    
    if (!grid || !formArea || !btn) return;

    // Если режим не передан принудительно, переключаем по кругу
    const isFormVisible = (showForm !== null) ? !showForm : (grid.style.display === 'none');

    if (isFormVisible) {
        // Показываем Грид, скрываем Форму
        grid.style.display = 'grid';
        formArea.style.display = 'none';
        btn.innerText = "➕ Добавить лицо";
        btn.style.background = "#4f46e5";
        window.editingContactIndex = null;
    } else {
        // Скрываем Грид, показываем Форму
        grid.style.display = 'none';
        formArea.style.display = 'block';
        btn.innerText = "⬅️ К списку лиц";
        btn.style.background = "#323248";
    }
}
         
function openContactFormWithData(c = null) {
    const formArea = document.getElementById('contactsFormArea');
    if (!formArea) return;

    const name = c ? (c.name || c.contact_name || '') : '';
    const pos = c ? (c.contact_role || '') : '';
    const phone = c ? (c.contact_phone || '') : '';
    const email = c ? (c.contact_email || '') : '';
    const postal = c ? (c.postal_address || '') : '';
    const notes = c ? (c.function_notes || '') : '';

    formArea.innerHTML = `
        <h4 style="margin: 0 0 12px 0; font-size: 12px; color: #818cf8; text-transform: uppercase;">Параметры контактного лица</h4>
        
        <div class="crm-grid-2" style="margin-bottom: 10px;">
            <div class="form-group">
                <label>ФИО ответственного лица <span style="color:#ef4444;">*</span></label>
                <input type="text" id="tmp_contact_name" value="${escapeHtmlQuotes(name)}" class="crm-input" placeholder="Иванов Иван Иванович">
            </div>
            <div class="form-group">
                <label>Должность лица</label>
                <input type="text" id="tmp_contact_position" value="${escapeHtmlQuotes(pos)}" class="crm-input" placeholder="Напр: Главный снабженец">
            </div>
        </div>
        <div class="crm-grid-2" style="margin-bottom: 10px;">
            <div class="form-group">
                <label>Телефон прямой</label>
                <input type="text" id="tmp_contact_phone" value="${escapeHtmlQuotes(phone)}" class="crm-input" placeholder="+375 (...)">
            </div>
            <div class="form-group">
                <label>Email лица</label>
                <input type="email" id="tmp_contact_email" value="${escapeHtmlQuotes(email)}" class="crm-input" placeholder="ivanov@partner.com">
            </div>
        </div>
        <div class="form-group" style="margin-bottom: 10px;">
            <label>Почтовый адрес</label>
            <input type="text" id="tmp_contact_postal" value="${escapeHtmlQuotes(postal)}" class="crm-input" placeholder="246000">
        </div>
        <div class="form-group" style="margin-bottom: 15px;">
            <label>Примечания по функциям / Сфера ответственности</label>
            <textarea id="tmp_contact_notes" rows="2" class="crm-textarea" placeholder="ЛПР, принимает итоговые решения по отгрузкам...">${escapeHtmlQuotes(notes)}</textarea>
        </div>

        <div style="display: flex; gap: 8px; justify-content: flex-end;">
            <button type="button" onclick="saveContactFormToGrid();" class="btn-success" style="background: #10b981; border: none; color:#fff; padding: 6px 15px; border-radius: 6px; font-weight: bold; cursor: pointer;">Сохранить лицо</button>
            
            <button type="button" onclick="toggleContactView(false);" class="btn-secondary" style="background: rgba(255,255,255,0.05); border: 1px solid #323248; color:#fff; padding: 6px 15px; border-radius: 6px; cursor: pointer;">Отмена</button>
        </div>
    `;
}

// Перенос данных из временных инпутов формы обратно в массив Грида
 

// Модифицируем триггер кнопки "+ Добавить лицо", чтобы он открывал ЧИСТУЮ форму
const oldToggle = window.toggleContactView;
window.toggleContactView = function(showForm = null) {
    const grid = document.getElementById('contactsGrid');
    // Если менеджер кликнул на "+ Добавить лицо" (когда грид виден и форма скрыта)
    if (showForm === null && grid && grid.style.display !== 'none') {
        openContactFormWithData(null); // Генерируем чистые пустые поля
    }
    oldToggle(showForm);
}
 async function saveContactFormToGrid() {
    const nameInput = document.getElementById('tmp_contact_name');
    if (!nameInput || !nameInput.value.trim()) {
        alert("ФИО ответственного лица является обязательным полем!");
        nameInput.focus();
        return;
    }

    // Собираем чистый объект данных с инпутов формы
    const contactData = {
        name: nameInput.value.trim(),
        position: document.getElementById('tmp_contact_position') ? document.getElementById('tmp_contact_position').value.trim() : '',
        phone: document.getElementById('tmp_contact_phone') ? document.getElementById('tmp_contact_phone').value.trim() : '',
        email: document.getElementById('tmp_contact_email') ? document.getElementById('tmp_contact_email').value.trim() : '',
        postal_address: document.getElementById('tmp_contact_postal') ? document.getElementById('tmp_contact_postal').value.trim() : '',
        function_notes: document.getElementById('tmp_contact_notes') ? document.getElementById('tmp_contact_notes').value.trim() : ''
    };

    // ЖЕЛЕЗНЫЙ ТРИГГЕР: Строго проверяем, что индекс — это число, а не null или undefined
    if (typeof window.editingContactIndex === 'number' && window.editingContactIndex !== null && window.editingContactIndex >= 0) {
        // Режим РЕДАКТИРОВАНИЯ существующего лица из списка
        window.currentModalContacts[window.editingContactIndex] = contactData;
        console.log("💬 ИНТЕРФЕЙС: Лицо успешно обновлено под индексом:", window.editingContactIndex);
    } else {
        // Режим СОЗДАНИЯ абсолютно нового лица
        if (!Array.isArray(window.currentModalContacts)) {
            window.currentModalContacts = [];
        }
        window.currentModalContacts.push(contactData);
        console.log("💬 ИНТЕРФЕЙС: Создано новое лицо, добавлено в массив.");
    }

    // Сбрасываем индекс редактирования обратно в null для безопасности
    window.editingContactIndex = null;

    // Перерисовываем компактную сетку и возвращаем менеджера к списку лиц
    renderContactsGrid(window.currentModalContacts);
    toggleContactView(false); 
}
function deleteContactFromGrid(index) {
    if (confirm("Удалить это контактное лицо из списка? Изменения вступят в силу после нажатия кнопки «Сохранить изменения».")) {
        window.currentModalContacts.splice(index, 1);
        renderContactsGrid(window.currentModalContacts);
    }
}
function renderContactsGrid(contactsArray) {
    const grid = document.getElementById('contactsGrid');
    if (!grid) return;
    
    grid.innerHTML = '';
    window.currentModalContacts = Array.isArray(contactsArray) ? contactsArray : [];

    if (window.currentModalContacts.length === 0) {
        grid.innerHTML = `<div style="grid-column: span 2; color: #64748b; font-size: 13px; padding: 15px; text-align: center; border: 1px dashed #323248; border-radius: 6px;">У этого контрагента пока нет зарегистрированных лиц. Нажмите «Добавить лицо» для создания.</div>`;
        return;
    }

    window.currentModalContacts.forEach((c, idx) => {
        const card = document.createElement('div');
        card.style.cssText = "background: #151521; border: 1px solid #323248; border-radius: 8px; padding: 12px; display: flex; flex-direction: column; gap: 4px; position: relative; text-align: left;";
        
        card.innerHTML = `
            <div style="font-weight: bold; color: #fff; font-size: 13px; padding-right: 50px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">👤 ${escapeHtmlQuotes(c.name || c.contact_name || 'Без имени')}</div>
            ${c.position ? `<div style="color: #92929f; font-size: 11px;">💼 ${escapeHtmlQuotes(c.position || c.contact_role)}</div>` : ''}
            ${c.phone ? `<div style="color: #818cf8; font-family: monospace; font-size: 11px;">📞 ${escapeHtmlQuotes(c.phone)}</div>` : ''}
            
            <div style="position: absolute; right: 8px; top: 8px; display: flex; gap: 4px;">
                <!-- Кнопка редактирования лица -->
                <button type="button" onclick="editContactInForm(${idx});" style="background: rgba(255,255,255,0.03); border: 1px solid #323248; color: #cbd5e1; border-radius: 4px; padding: 2px 6px; font-size: 11px; cursor: pointer;" onmouseover="this.style.background='#4f46e5'" onmouseout="this.style.background='rgba(255,255,255,0.03)'">⚙️</button>
                <!-- Кнопка удаления лица -->
                <button type="button" onclick="deleteContactFromGrid(${idx});" style="background: rgba(255,255,255,0.03); border: 1px solid #323248; color: #ef4444; border-radius: 4px; padding: 2px 6px; font-size: 11px; cursor: pointer;" onmouseover="this.style.background='#ef4444';this.style.color='#fff'" onmouseout="this.style.background='rgba(255,255,255,0.03)';this.style.color='#ef4444'">×</button>
            </div>
            
            <!-- Скрытые инпуты, чтобы FormData при сабмите всей формы забирала данные в update_client.php -->
            <input type="hidden" name="contacts[${idx}][name]" value="${escapeHtmlQuotes(c.name || c.contact_name || '')}">
            <input type="hidden" name="contacts[${idx}][position]" value="${escapeHtmlQuotes(c.position || c.contact_role || '')}">
            <input type="hidden" name="contacts[${idx}][phone]" value="${escapeHtmlQuotes(c.phone || '')}">
            <input type="hidden" name="contacts[${idx}][email]" value="${escapeHtmlQuotes(c.email || '')}">
            <input type="hidden" name="contacts[${idx}][postal_address]" value="${escapeHtmlQuotes(c.postal_address || '')}">
            <input type="hidden" name="contacts[${idx}][function_notes]" value="${escapeHtmlQuotes(c.function_notes || '')}">
        `;
        grid.appendChild(card);
    });
}
    </script>    
    <!-- МОДУЛЬ МУЛЬТИ-КОНТАКТОВ С ДИНАМИЧЕСКИМ ПЕРЕКЛЮЧЕНИЕМ ГРИД / ФОРМА -->
<!-- ИСПРАВЛЕНО НАМЕРТВО: Изолированный PREMIUM-модуль мульти-контактов (Защищен от сдвигов сетки) -->

    </div>
<!-- ИСПРАВЛЕНО НАМЕРТВО: Модуль мульти-контактов, расширенный на всю ширину сетки grid-column -->
<div style="grid-column: 1 / -1; margin-top: 25px; margin-bottom: 25px; border-top: 1px dashed #323248; padding-top: 20px; text-align: left; width: 100%; box-sizing: border-box; display: block; clear: both;">
    
    <!-- ШАПКА МОДУЛЯ -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; width: 100%; box-sizing: border-box; gap: 15px;">
        <h3 style="margin: 0; font-size: 13px; color: #fff; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold; font-family: sans-serif;">
            👥 Контактные лица компании
        </h3>
        
        <!-- АВТОНОМНАЯ КНОПКА (Убран класс btn-primary во избежание конфликтов скриптов темы) -->
        <button type="button" id="toggleContactViewBtn" onclick="toggleContactView(); event.preventDefault();" style="background: #4f46e5; border: 1px solid #4f46e5; color: #fff; padding: 0 16px; border-radius: 6px; font-size: 12px; font-weight: bold; cursor: pointer; transition: all 0.2s; white-space: nowrap; height: 36px; line-height: 36px; box-sizing: border-box; outline: none;">
            ➕ Добавить лицо
        </button>
    </div>

    <!-- 1. ГРИД-СПИСОК КОНТАКТОВ -->
    <div id="contactsGrid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; max-height: 250px; overflow-y: auto; padding-right: 5px; margin-bottom: 10px; width: 100%; box-sizing: border-box; min-height: 50px;">
      <?php
        try {
            // Берем ID текущего клиента из контекста модалки
            $currentModalClientId = (int)($c['id'] ?? ($client['id'] ?? 0));
            $loadedContacts = [];

            if ($currentModalClientId > 0) {
                // Прямой изолированный запрос в таблицу контактов Santeks
                $stmtGrid = $pdo->prepare("SELECT contact_name, contact_role, phone, email, postal_address, function_notes FROM client_contacts WHERE client_id = ? ORDER BY id ASC");
                $stmtGrid->execute([$currentModalClientId]);
                $loadedContacts = $stmtGrid->fetchAll(PDO::FETCH_ASSOC);
            }

            if (!empty($loadedContacts)):
                foreach ($loadedContacts as $idx => $lc):
                    $lcName = trim($lc['contact_name'] ?? '');
                    $lcRole = trim($lc['contact_role'] ?? '');
                    $lcPhone = trim($lc['phone'] ?? '');
                    $lcEmail = trim($lc['email'] ?? '');
                    $lcPostal = trim($lc['postal_address'] ?? '');
                    $lcNotes = trim($lc['function_notes'] ?? '');
        ?>
                    <!-- Карточка одного контактного лица -->
                    <div style="background: #151521; border: 1px solid #323248; border-radius: 8px; padding: 12px; display: flex; flex-direction: column; gap: 4px; position: relative; text-align: left; box-sizing: border-box; width: 100%;">
                        <div style="font-weight: bold; color: #fff; font-size: 13px; padding-right: 30px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            👤 <?= htmlspecialchars($lcName) ?>
                        </div>
                        <?php if (!empty($lcRole)): ?>
                            <div style="color: #92929f; font-size: 11px;">💼 <?= htmlspecialchars($lcRole) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($lcPhone)): ?>
                            <div style="color: #818cf8; font-family: monospace; font-size: 11px;">📞 <?= htmlspecialchars($lcPhone) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($lcEmail)): ?>
                            <div style="color: #cbd5e1; font-size: 11px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">✉️ <?= htmlspecialchars($lcEmail) ?></div>
                        <?php endif; ?>
                        
                        <!-- Кнопка локального удаления карточки на фронтенде -->
                        <div style="position: absolute; right: 8px; top: 8px;">
                            <button type="button" onclick="this.parentElement.parentElement.remove();" style="background: rgba(255,255,255,0.03); border: 1px solid #323248; color: #ef4444; border-radius: 4px; padding: 2px 6px; font-size: 11px; cursor: pointer; font-weight: bold;">×</button>
                        </div>
                        
                        <!-- Скрытая структура инпутов, чтобы FormData при общем сохранении забирала массив в update_client.php -->
                        <input type="hidden" name="contacts[<?= $idx ?>][name]" value="<?= htmlspecialchars($lcName, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="contacts[<?= $idx ?>][position]" value="<?= htmlspecialchars($lcRole, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="contacts[<?= $idx ?>][phone]" value="<?= htmlspecialchars($lcPhone, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="contacts[<?= $idx ?>][email]" value="<?= htmlspecialchars($lcEmail, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="contacts[<?= $idx ?>][postal_address]" value="<?= htmlspecialchars($lcPostal, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="contacts[<?= $idx ?>][function_notes]" value="<?= htmlspecialchars($lcNotes, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
        <?php 
                endforeach;
            else:
                // Если в базе нет лиц, выводим аккуратную заглушку-инструкцию
                echo '<div style="grid-column: span 2; color: #64748b; font-size: 13px; padding: 15px; text-align: center; border: 1px dashed #323248; border-radius: 6px; width: 100%; box-sizing: border-box;">У этого контрагента пока нет зарегистрированных лиц. Нажмите «Добавить лицо» для создания.</div>';
            endif;
        } catch (Exception $e) {
            echo '<div style="grid-column: span 2; color: #ef4444; font-size: 11px; padding: 10px; text-align: center;">Ошибка загрузки лиц из СУБД</div>';
        }
        ?>
    
    <!-- Сюда JS будет рендерить компактные карточки лиц -->
    </div>

    <!-- 2. ФОРМА РЕДАКТИРОВАНИЯ/ДОБАВЛЕНИЯ -->
    <div id="contactsFormArea" style="display: none; background: rgba(255, 255, 255, 0.01); border: 1px solid #323248; border-radius: 8px; padding: 15px; position: relative; box-sizing: border-box; width: 100%;">
        <!-- Сюда JS будет подставлять форму конкретного лица -->
    </div>

</div>
    <!-- 1. ГРИД-СПИСОК КОНТАКТОВ (Основной экран при открытии модалки) -->
    <div id="contactsGrid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; max-height: 250px; overflow-y: auto; padding-right: 5px;">
        <!-- Сюда JS будет рендерить компактные карточки лиц -->
    </div>

    <!-- 2. ФОРМА РЕДАКТИРОВАНИЯ/ДОБАВЛЕНИЯ (Изначально скрыта) -->
    <div id="contactsFormArea" style="display: none; background: rgba(255, 255, 255, 0.01); border: 1px solid #323248; border-radius: 8px; padding: 15px; position: relative;">
        <!-- Сюда JS будет подставлять форму конкретного лица для редактирования или создания -->
    </div>
    <script>
        // Глобальный массив для временного хранения контактов текущей модалки
window.currentModalContacts = [];
window.editingContactIndex = null; // Индекс контакта, который мы правим в данный момент

// Переключатель отображения Грид / Форма

// Отрисовка компактного грид-списка лиц


// Открытие формы для редактирования конкретного лица из грида
function editContactInForm(index) {
    const c = window.currentModalContacts[index];
    window.editingContactIndex = index;
    
    openContactFormWithData(c);
    toggleContactView(true); // Форсируем показ формы
}

// Удаление контакта из локального грида

function openContactFormWithData(c = null) {
    const formArea = document.getElementById('contactsFormArea');
    if (!formArea) return;

    const name = c ? (c.name || c.contact_name || '') : '';
    const pos = c ? (c.position || c.contact_role || '') : '';
    const phone = c ? (c.phone || '') : '';
    const email = c ? (c.email || '') : '';
    const postal = c ? (c.postal_address || '') : '';
    const notes = c ? (c.function_notes || '') : '';

    formArea.innerHTML = `
        <h4 style="margin: 0 0 12px 0; font-size: 12px; color: #818cf8; text-transform: uppercase;">Параметры контактного лица</h4>
        
        <div class="crm-grid-2" style="margin-bottom: 10px;">
            <div class="form-group">
                <label>ФИО ответственного лица <span style="color:#ef4444;">*</span></label>
                <input type="text" id="tmp_contact_name" value="${escapeHtmlQuotes(name)}" class="crm-input" placeholder="Иванов Иван Иванович">
            </div>
            <div class="form-group">
                <label>Должность лица</label>
                <input type="text" id="tmp_contact_position" value="${escapeHtmlQuotes(pos)}" class="crm-input" placeholder="Напр: Главный снабженец">
            </div>
        </div>
        <div class="crm-grid-2" style="margin-bottom: 10px;">
            <div class="form-group">
                <label>Телефон прямой</label>
                <input type="text" id="tmp_contact_phone" value="${escapeHtmlQuotes(phone)}" class="crm-input" placeholder="+375 (...)">
            </div>
            <div class="form-group">
                <label>Email лица</label>
                <input type="email" id="tmp_contact_email" value="${escapeHtmlQuotes(email)}" class="crm-input" placeholder="ivanov@partner.com">
            </div>
        </div>
        <div class="form-group" style="margin-bottom: 10px;">
            <label>Почтовый адрес</label>
            <input type="text" id="tmp_contact_postal" value="${escapeHtmlQuotes(postal)}" class="crm-input" placeholder="246000">
        </div>
        <div class="form-group" style="margin-bottom: 15px;">
            <label>Примечания по функциям / Сфера ответственности</label>
            <textarea id="tmp_contact_notes" rows="2" class="crm-textarea" placeholder="ЛПР, принимает итоговые решения по отгрузкам...">${escapeHtmlQuotes(notes)}</textarea>
        </div>

        <div style="display: flex; gap: 8px; justify-content: flex-end;">
            <button type="button" onclick="saveContactFormToGrid();" class="btn-success" style="background: #10b981; border: none; color:#fff; padding: 6px 15px; border-radius: 6px; font-weight: bold; cursor: pointer;">Сохранить лицо</button>
           
            <button type="button" onclick="toggleContactView(false);" class="btn-secondary" style="background: rgba(255,255,255,0.05); border: 1px solid #323248; color:#fff; padding: 6px 15px; border-radius: 6px; cursor: pointer;">Отмена</button>
        </div>
    `;
}

// Перенос данных из временных инпутов формы обратно в массив Грида


// Модифицируем триггер кнопки "+ Добавить лицо", чтобы он открывал ЧИСТУЮ форму
const oldToggle = window.toggleContactView;
window.toggleContactView = function(showForm = null) {
    const grid = document.getElementById('contactsGrid');
    // Если менеджер кликнул на "+ Добавить лицо" (когда грид виден и форма скрыта)
    if (showForm === null && grid && grid.style.display !== 'none') {
        openContactFormWithData(null); // Генерируем чистые пустые поля
    }
    oldToggle(showForm);
}
    </script>
</div>

                <!-- РЯД 3: СВЯЗЬ И ПРОДУКЦИЯ -->
                <div class="crm-grid-2">
                    <div class="form-group">
                        <label>E-Mail компании</label>
                        <input type="email" id="e_mail" name="email" class="crm-input" placeholder="info@partner.com">
                    </div>
                    <div class="form-group">
                        <label>Вид продукции <span style="color:#ef4444;">*</span></label>
                        <select id="product_type" name="product_type" class="crm-select">
                            <option value="ЕКМ">ЕКМ</option>
                            <option value="Сантехника">Сантехника</option>
                            <option value="Посуда">Посуда</option>
                            <option value="МПДУ">МПДУ</option>
                            <option value="Резервуар">Резервуар</option>
                            <option value="Эмалированные таблички">Эмалированные таблички</option>
                            <option value="УОКТ">УОКТ</option>
                            <option value="Другое">Другое</option>
                        </select>
                    </div>
                </div>

                <!-- РЯД 4: КАЛЕНДАРИ -->
                <div class="crm-grid-2">
                    <div class="form-group">
                        <label>Дата первого контакта</label>
                        <input type="text" id="first_contact_date" readonly class="crm-input" style="color:#707084; background:#1a1a26; cursor:not-allowed;">
                    </div>
                    <div class="form-group">
                        <label>Следующий контакт <span style="color:#ef4444;">*</span></label>
                        <input type="date" id="add_client_next_date" name="next_contact_date" required class="crm-input">
                    </div>
                </div>

                <!-- РЯД 5: МЕНЕДЖМЕНТ -->
              
                    <div class="form-group">
                        <label>Источник привлечения <span style="color:#ef4444;">*</span></label>
                        <select id="source" name="source" class="crm-select">
                            <option value="Холодный поиск">Холодный поиск</option>
                            <option value="Запрос">Запрос</option>
                            <option value="Закупки">Закупки</option>
                            
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Статус клиента <span style="color:#ef4444;">*</span></label>
                        <select id="status" name="status" class="crm-select">
                            <option value="Новый">Новый</option>
                            <option value="Текущий">Текущий</option>
                            <option value="Потенциальный">Потенциальный</option>
                        </select>
                    </div>
                

                <!-- РЯД 6: КОММЕНТАРИЙ -->
                <div class="form-group">
                    <label>Комментарий менеджера / Договора</label>
                    <textarea id="comment" name="comment" rows="3" class="crm-textarea" placeholder="Договор № 1509/25К от 05.11.2025 г..."></textarea>
                </div>

            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-crm-cancel" onclick="closeModal()">Отмена</button>
                <button type="submit" class="btn-crm-save">Сохранить изменения</button>
            </div>
            
        </form>
    </div>
</div>
   
       <!-- ИСПРАВЛЕНО НАМЕРТВО: Инпут УНП с умной AJAX-валидацией и блокировкой дублей -->

    <!-- Абсолютно позиционированный блок ошибки, чтобы он не раздвигал и не ломал сетку инпутов! -->
    <div id="js-unp-error-block" style="display: none; font-size: 10px; color: #ef4444; margin-top: 2px; font-weight: 600; text-align: left; flex-direction: column; gap: 4px; width: 100%;">
        <span>⚠️ УНП уже зарегистрирован (<strong id="js-duplicate-name-span">Имя</strong>)!</span>
            <button type="button" onclick="bypassUnpLockForAdmin()" style="align-self: flex-start; background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: #10b981; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 800; cursor: pointer; transition: all 0.15s;">
                🔓 Пропустить как филиал 
            </button>
    </div>

</div>
<style>
.modal-overlay {
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(10, 10, 15, 0.8);
    backdrop-filter: blur(6px);
    justify-content: center;
    align-items: center;
    z-index: 9999;
    display: none; /* Управляется строго через JS: display = 'flex' / 'none' */
}
.modal-overlay.active {
    opacity: 1;
    pointer-events: auto;
}
.stylish-modal {
    background: #1e1e2d;
    border: 1px solid #323248;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
    border-radius: 16px;
    width: 900px;
    height: 85vh;            /* Жесткая высота относительно экрана */
    max-height: 800px;
    display: flex;
    flex-direction: column;  /* Строго вертикальное распределение */
    color: #fff;
    font-family: 'Segoe UI', Roboto, sans-serif;
    overflow: hidden;        /* Не дает внутренностям вылезать за рамки */
    transform: translateY(-20px);
    transition: transform 0.25s ease;
}

/* ИСПРАВЛЕНО: Форма внутри модалки ТАКЖЕ должна наследоваться как flex-контейнер */
#clientForm {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0; /* Критично для корректной работы скролла в Firefox/Chrome */
    margin: 0;
    padding: 0;
}

/* ИСПРАВЛЕНО: Контентная зона сжимается и включает скроллбар */
.modal-body {
    flex: 1;
    overflow-y: auto;        /* Вертикальный скролл только здесь */
    min-height: 0;           /* Разрешает блоку уменьшаться */
    padding: 25px;
}

/* ИСПРАВЛЕНО: Футер прибит к низу намертво и никогда не ужмется */
.modal-footer {
    padding: 15px 25px;
    border-top: 1px solid #323248;
    background: #1a1a26;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    flex-shrink: 0;          /* Запрещает выталкивать или сжимать футер */
}

/* ИСПРАВЛЕНО: Футер теперь намертво прибит к низу окна и не уползает */
.modal-footer {
    padding: 15px 25px;
    border-top: 1px solid #323248;
    background: #1a1a26;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    flex-shrink: 0; /* Не дает кнопкам сжиматься или пропадать */
}

/* Кастомизация скроллбара для тела самой модалки */
.modal-body::-webkit-scrollbar {
    width: 6px;
}
.modal-body::-webkit-scrollbar-track {
    background: #1e1e2d;
}
.modal-body::-webkit-scrollbar-thumb {
    background: #323248;
    border-radius: 3px;
}
.modal-body::-webkit-scrollbar-thumb:hover {
    background: #0095e8;
}
.modal-overlay.active .stylish-modal {
    transform: translateY(0);
}

.modal-header {
    padding: 20px 25px;
    border-bottom: 1px solid #323248;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.modal-header h2 {
    margin: 0;
    font-size: 20px;
    font-weight: 600;
    letter-spacing: 0.3px;
}
.modal-close-x {
    background: none;
    border: none;
    color: #565674;
    font-size: 24px;
    cursor: pointer;
    transition: color 0.15s;
    line-height: 1;
}
.modal-close-x:hover { color: #fff; }

.modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 25px;
}

/* Сетка для полей */
.crm-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}
.crm-grid-3 {
    display: grid;
    grid-template-columns: 2fr 1.2fr 1.5fr;
    gap: 15px;
    margin-bottom: 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.form-group label {
    font-size: 11px;
    color: #92929f;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.crm-input, .crm-select, .crm-textarea {
    width: 100%;
    height: 42px;
    padding: 0 14px;
    background: #151521;
    border: 1px solid #323248;
    color: #fff;
    border-radius: 8px;
    outline: none;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.2s ease;
    box-sizing: border-box;
}
.crm-textarea {
    height: auto;
    padding: 10px 14px;
    resize: vertical;
}
.crm-input:focus, .crm-select:focus, .crm-textarea:focus {
    border-color: #0095e8;
    box-shadow: 0 0 0 2px rgba(0, 149, 232, 0.15);
}

/* Стили динамических контактов */
.contacts-section {
    border-top: 1px solid #323248;
    padding-top: 20px;
    margin-bottom: 20px;
}
.contacts-section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}
.contacts-section-header h3 {
    margin: 0;
    font-size: 14px;
    color: #fff;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.btn-add-contact {
    background: rgba(0, 149, 232, 0.1);
    border: 1px solid rgba(0, 149, 232, 0.3);
    color: #0095e8;
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s;
}
.btn-add-contact:hover {
    background: #0095e8;
    color: #fff;
}

.contacts-scroll-container {
    max-height: 250px;
    overflow-y: auto;
    padding-right: 5px;
    display: flex;
    flex-direction: column;
    gap: 15px;
}
.contacts-scroll-container::-webkit-scrollbar { width: 6px; }
.contacts-scroll-container::-webkit-scrollbar-track { background: #1e1e2d; }
.contacts-scroll-container::-webkit-scrollbar-thumb { background: #323248; border-radius: 3px; }

.contact-card {
    background: #151521;
    border: 1px solid #323248;
    padding: 15px;
    border-radius: 10px;
    position: relative;
    display: none;J
}

.modal-footer {
    padding: 15px 25px;
    border-top: 1px solid #323248;
    background: #1a1a26;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}
.btn-crm-cancel {
    background: #212130;
    border: 1px solid #323248;
    color: #92929f;
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s;
}
.btn-crm-cancel:hover { background: #262638; color: #fff; }

.btn-crm-save {
    background: #0095e8;
    border: none;
    color: #fff;
    padding: 10px 24px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
}
.btn-crm-save:hover { background: #0086d1; }
/* Жесткий фикс для самих полей выбора в форме добавления/редактирования */
form select, 
select[name="product_type"], 
select[name="status"], 
select[name="lead_source"] {
    color-scheme: dark !important; /* Принудительно заставляет движок Chrome включать ночную схему */
    background-color: #151521 !important;
    color: #ffffff !important;
    border: 1px solid #323248 !important;
    border-radius: 6px !important;
    outline: none !important;
    font-size: 13px !important;
    cursor: pointer !important;
    transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out !important;
}

/* Хирургический фикс внутренних пунктов списка (option) при выпадении меню */
form select option,
select[name="product_type"] option, 
select[name="status"] option, 
select[name="lead_source"] option {
    background-color: #1e1e2d !important; /* Глубокий графитовый фон плашки */
    color: #ffffff !important; /* Белый читаемый текст наименований */
    padding: 10px 14px !important;
}

/* Красивая неоновая подсветка рамок индиго при фокусе на селекте */
form select:focus,
select[name="product_type"]:focus,
select[name="status"]:focus,
select[name="lead_source"]:focus {
    border-color: #4f46e5 !important;
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15) !important;
}
</style>
<script>
window.isUnpBlocked = false;

let contactIndex = 0;

// Убедитесь, что функция объявлена глобально и без синтаксических ошибок
function addContactField(data = null) {
    const container = document.getElementById('contactsContainer');
    if (!container) return; 

    // Инициализируем индекс, если он вдруг потерялся в DOM
    if (typeof contactIndex === 'undefined' || contactIndex === null) {
        window.contactIndex = 0;
    }
    const idx = window.contactIndex;

    // Вытаскиваем значения, страхуясь от NULL
    let cName         = data ? (data.name || '') : '';
    let cPosition     = data ? (data.position || '') : '';
    let cPhone        = data ? (data.phone || '') : '';
    let cEmail        = data ? (data.email || '') : '';
    let cPostal       = data ? (data.postal_address || '') : '';
    let cNotes        = data ? (data.function_notes || '') : '';

    const contactRow = document.createElement('div');
    contactRow.className = 'contact-card';
    contactRow.setAttribute('data-index', idx);

    // Кнопка удаления для всех блоков, кроме самого первого
    const deleteBtn = idx > 0 
        ? `<button type="button" onclick="this.parentElement.remove();" style="position: absolute; top: 12px; right: 12px; background: none; border: none; color: #ef4444; font-size: 20px; cursor: pointer; font-weight: bold; padding: 0; line-height: 1;">&times;</button>`
        : '';

    contactRow.innerHTML = `
        ${deleteBtn}
        <div class="crm-grid-2" style="margin-bottom: 10px;">
            <div class="form-group">
                <label>ФИО ответственного лица ${idx === 0 ? '<span style="color:#ef4444;">*</span>' : ''}</label>
                <input type="text" name="contacts[${idx}][name]" ${idx === 0 ? 'required' : ''} value="${escapeHtmlQuotes(cName)}" class="crm-input" placeholder="Иванов Иван Иванович">
            </div>
            <div class="form-group">
                <label>Должность лица</label>
                <input type="text" name="contacts[${idx}][position]" value="${escapeHtmlQuotes(cPosition)}" class="crm-input" placeholder="Напр: Главный снабженец">
            </div>
        </div>
        <div class="crm-grid-2" style="margin-bottom: 10px;">
            <div class="form-group">
                <label>Телефон прямой</label>
                <input type="text" name="contacts[${idx}][phone]" value="${escapeHtmlQuotes(cPhone)}" class="crm-input" placeholder="+375 (...)">
            </div>
            <div class="form-group">
                <label>Email лица</label>
                <input type="email" name="contacts[${idx}][email]" value="${escapeHtmlQuotes(cEmail)}" class="crm-input" placeholder="ivanov@partner.com">
            </div>
        </div>
        
        <div class="form-group" style="margin-bottom: 10px;">
            <label>Почтовый адрес</label>
            <input type="text" name="contacts[${idx}][postal_address]" value="${escapeHtmlQuotes(cPostal)}" class="crm-input" placeholder="246000">
        </div>

        <div class="form-group">
            <label>Примечания по функциям / Сфера ответственности</label>
            <textarea name="contacts[${idx}][function_notes]" rows="2" class="crm-textarea" placeholder="ЛПР, принимает итоговые решения по отгрузкам...">${escapeHtmlQuotes(cNotes)}</textarea>
        </div>
    `;

    container.appendChild(contactRow);
    
    // Инкрементируем глобальный счетчик для следующего клика по кнопке "+ Добавить лицо"
    window.contactIndex++; 
    container.scrollTop = container.scrollHeight;
}

// Экранирование кавычек, чтобы названия компаний не рушили value=""
function escapeHtmlQuotes(text) {
    if (!text) return '';
    return text.toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function removeContactField(index) {
    const row = document.querySelector(`.contact-item-row[data-index="${index}"]`);
    if (row) row.remove();
}

function resetUnpInputStyle(input, block, btn) {
    window.isUnpBlocked = false;
    input.style.borderColor = '#323248';
    input.style.boxShadow = 'none';
    input.style.color = '#fff';
    if (block) block.style.display = 'none';
    if (btn) {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
    }
}

// Исключение PS: Функция обхода блокировки для Админа
function bypassUnpLockForAdmin() {
    window.isUnpBlocked = false;
    const submitBtn = document.querySelector('#contractForm button[type="submit"]') || document.querySelector('form button[type="submit"]');
    const inputElement = document.getElementById('js-client-unp-input');
    
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.style.opacity = '1';
        submitBtn.style.cursor = 'pointer';
    }
    if (inputElement) {
        inputElement.style.borderColor = '#6366f1'; // Перекрашиваем в индиго (режим исключения)
        inputElement.style.boxShadow = '0 0 10px rgba(99,102,241,0.2)';
    }
    alert("🔓 Блокировка УНП. Запись будет сохранена как филиал.");
}

</script>
</div>

          <!-- РЯД 2: КОНТАКТНОЕ ЛИЦО И ТЕЛЕФОН -->
   <div style="margin-top: 15px; border-top: 1px solid #323248; padding-top: 15px; margin-bottom: 15px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">

    </div>
          <div id="contactsContainer" style="max-height: 280px; overflow-y: auto; padding-right: 5px; display: flex; flex-direction: column; gap: 12px;">
        <!-- Сюда JavaScript будет автоматически генерировать группы полей -->
    </div>
</div>
  <!-- РЯД 1: НАЗВАНИЕ, УНП И САЙТ (ОБНОВЛЕНО) -->
    <div class="form-row" style="display: flex; gap: 15px; margin-bottom: 15px; align-items: flex-start;">
     

    </div>
  
<!-- ИСПРАВЛЕНО: Скрытый транзит истории комментариев для защиты от затирания при редактировании -->
<input type="hidden" id="modal_client_hidden_comment" name="comment">

<input type="hidden" id="modal_client_hidden_comment" name="comment">

<script>
    document.addEventListener("DOMContentLoaded", () => {
    const unpInput = document.getElementById('js-client-unp-input');
    const errorBlock = document.getElementById('js-unp-error-block');

    if (unpInput) {
        unpInput.addEventListener('input', function() {
            // Очищаем пробелы и лишние символы
            const value = this.value.trim();
            
            // Если поле пустое — скрываем ошибки (оно сработает по атрибуту required)
            if (value === '') {
                if (errorBlock) errorBlock.style.display = 'none';
                this.setCustomValidity(''); 
                return;
            }

            // Проверка: для резидентов РБ/РФ стандарт — 9 знаков
            if (value.length !== 9) {
                // 1. Формируем текст мягкого предупреждения
                if (errorBlock) {
                    errorBlock.innerHTML = `<span>⚠️ Длина УНП (${value.length} зн.) отличается от стандарта РБ (9 зн.). Если это иностранный партнер — всё в порядке, сохранение разрешено.</span>`;
                    errorBlock.style.cssText = "display: flex; font-size: 10px; color: #f59e0b; margin-top: 4px; font-weight: 600; text-align: left;";
                }
                
                // 2. ВАЖНО: Разрешаем сохранение формы (очищаем блокировку браузера)
                this.setCustomValidity(''); 
                this.style.borderColor = '#f59e0b'; // Подсвечиваем инпут предупреждающим желтым цветом
                this.style.boxShadow = '0 0 0 2px rgba(245, 158, 11, 0.15)';
            } else {
                // Если знаков ровно 9 — возвращаем стандартный стиль CRM (индиго/голубой)
                if (errorBlock) errorBlock.style.display = 'none';
                this.setCustomValidity('');
                this.style.borderColor = '#323248';
                this.style.boxShadow = 'none';
                
                // Здесь может продолжаться ваша старая AJAX-проверка на дубликаты УНП в СУБД, если она была:
                // checkUnpDuplicateInDatabase(value);
            }
        });
    }
});
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

    /**
 * ОЧИСТКА И ПОДГОТОВКА МОДАЛКИ ДЛЯ ДОБАВЛЕНИЯ НОВОГО КЛИЕНТА
 */
// ЕДИНСТВЕННЫЙ ИЗОЛИРОВАННЫЙ ОБРАБОТЧИК ФОРМЫ (ИСПРАВЛЕНО)
// НАМЕРТВО ИСПРАВЛЕНО: Защита от двойного сохранения лидов-пустышек
document.getElementById('clientForm').addEventListener('submit', async function(e) {
    e.preventDefault(); // Глушим стандартный сабмит браузера
    
    const submitBtn = this.querySelector('button[type="submit"]');
    if (submitBtn && submitBtn.disabled) return; // Защита от спам-кликов

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerText = "Сохранение...";
        submitBtn.style.opacity = "0.7";
    }
    
    // Получаем ID клиента из скрытого инпута формы
    const clientId = document.getElementById('client_id').value;
    
    const formData = new FormData(this);
    
    // ИСПРАВЛЕНО: Если ID пустой или равен 0 — значит мы СОЗДАЕМ нового клиента.
    // Если ID заполнен числом — значит мы РЕДАКТИРУЕМ старого клиента.
    const isNewClient = (!clientId || parseInt(clientId, 10) <= 0);
    const url = isNewClient ? 'create_client.php' : 'update_client.php'; 

    console.log(`ИНТЕРФЕЙС: Режим ${isNewClient ? 'СОЗДАНИЯ' : 'ОБНОВЛЕНИЯ'}. Пакет улетает на ${url}`);

    try {
        const res = await fetch(url, {
            method: 'POST',
            body: formData
        });
        
        if (res.redirected) {
            window.location.href = res.url;
            return;
        }

        const result = await res.json();
        if (result.status === 'success') {
            alert(result.message);
            closeModal();
            window.location.reload(); // Перезагружаем страницу для обновления списков
        } else {
            alert("Ошибка СУБД: " + result.message);
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerText = "Сохранить изменения";
                submitBtn.style.opacity = "1";
            }
        }

    } catch (err) {
        console.error("Сбой отправки формы:", err);
        window.location.reload();
    }
});
function prepareModalForNewClient() {
    // Очищаем текстовые поля формы
    document.getElementById('clientForm').reset();
    
    // ПРИНУДИТЕЛЬНО ОБНУЛЯЕМ ID, чтобы сработал create_client.php
    if (document.getElementById('client_id')) {
        document.getElementById('client_id').value = "";
    }
    
    // Сбрасываем контейнер динамических контактов
    const contactsContainer = document.getElementById('contactsContainer');
    if (contactsContainer) {
        contactsContainer.innerHTML = '';
        contactIndex = 0;
        addContactField(); // Создаем одно чистое обязательное поле контакта
    }
    
    // Сбрасываем сайт и УНП
    if(document.getElementById('client_website')) document.getElementById('client_website').value = '';
    if(document.getElementById('js-client-unp-input')) document.getElementById('js-client-unp-input').value = '';

    // Меняем заголовок модального окна
    if(document.getElementById('modalTitle')) {
        document.getElementById('modalTitle').innerText = "Добавить клиента";
    }
    
    // Плавно открываем модальное окно через добавление класса active
    const modal = document.getElementById('clientModal');
    if (modal) {
        modal.style.setProperty('display', 'flex', 'important');
        modal.classList.add('active');
    }
}

// Функция закрытия модалки
function closeModal() {
    const modal = document.getElementById('clientModal');
    const form = document.getElementById('clientForm');
    
    if (modal) {
        // 1. Плавный уход анимации
        modal.classList.remove('active'); 
        
        // 2. УДАЛЯЕМ инлайновый display: flex !important, чтобы окно физически исчезло
        modal.style.removeProperty('display'); 
        modal.style.display = 'none'; 
    }
    
    // 3. Сбрасываем поля формы, чтобы при следующем открытии не было старых данных
    if (form) {
        form.reset();
    }
    
    console.log("ИНТЕРФЕЙС ТРИГГЕР: Модальное окно успешно закрыто и очищено.");
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
            ORDER BY next_contact_date ASC LIMIT 99999999");
        $remind_stmt->execute();
    } else {
        $remind_stmt = $pdo->prepare("SELECT * FROM clients 
            WHERE manager_id = ? 
              AND status != 'Отказ' 
              AND next_contact_date IS NOT NULL 
              AND next_contact_date <= DATE_ADD(CURDATE(), INTERVAL 4 DAY)
            ORDER BY next_contact_date ASC LIMIT 9999999");
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

        // ЖЕЛЕЗНЫЙ БЛОК: Ищем и тушим кнопку отправки, исключая задвоение в СУБД!
        const submitBtn = this.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.5';
            submitBtn.style.cursor = 'not-allowed';
            submitBtn.dataset.oldText = submitBtn.innerText;
            submitBtn.innerText = "⌛ Сохранение...";
        }

        const fd = new FormData(this);
        const safeAppend = (key, elementId) => {
            const el = document.getElementById(elementId);
            if (el) {
                fd.set(key, el.value); 
            }
        };

        // Забиваем поля (Синхронизировано под кастомные селекты без белых багов)
        safeAppend('client_name', 'client_name');
        safeAppend('unp', 'unp');
        safeAppend('contact_person', 'contact_person');
        safeAppend('phone', 'phone');
        safeAppend('product_type', 'product_type');
        safeAppend('first_contact_date', 'first_contact_date');
        safeAppend('next_contact_date', 'next_contact_date');
        safeAppend('status', 'status');
        safeAppend('email', 'email');
        safeAppend('comment', 'comment');

        // ЖЕЛЕЗНЫЙ ФИКС СИНХРОНИЗАЦИИ КЛЮЧЕЙ И ID КЛИЕНТА
        const clientIdInput = document.getElementById('client_id');
        const clientId = clientIdInput ? parseInt(clientIdInput.value, 10) : 0;
        
        if (clientId > 0) {
            // Если редактируем старого — шлем явный маркер пакетного апдейта и дублируем ID в оба ключа для надежности бэкенда!
            fd.set('action_mode', 'update_client_full_package');
            fd.set('client_id', clientId);
            fd.set('id', clientId);
        } else {
            // Если создаем нового — шлем маркер чистой вставки
            fd.set('action_mode', 'create_new_client_package');
        }

        // Если у тебя на форме работает наш кастомный источник без выпадашек (Шаг 264)
        const customSource = document.getElementById('js-real-lead-source');
        if (customSource && customSource.value.trim() !== '') {
            fd.set('source', customSource.value.trim());
        } else {
            safeAppend('source', 'source');
        }

        try {
            // Бьем точно в файл save.php
            const res = await fetch('save.php', { method: 'POST', body: fd });
            const rawText = await res.text();
            
            if (!rawText.trim().startsWith('{')) {
                alert("🚨 КРИТИЧЕСКИЙ СБОЙ БЭКЕНДА!\nСервер вернул ошибку PHP вместо JSON:\n\n" + rawText);
                
                // Возвращаем кнопку в рабочее состояние при ошибке сервера
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                    submitBtn.style.cursor = 'pointer';
                    submitBtn.innerText = submitBtn.dataset.oldText;
                }
                return;
            }
            
            const result = JSON.parse(rawText);
            if (result.status === 'success') {
                window.location.reload(); 
            } else {
                alert("Ошибка СУБД: " + result.message);
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                    submitBtn.style.cursor = 'pointer';
                    submitBtn.innerText = submitBtn.dataset.oldText;
                }
            }
        } catch (err) {
            console.error("Критическая ошибка JS:", err);
            alert("🚨 Системный сбой JavaScript! Проверьте консоль F12.");
            
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                submitBtn.style.cursor = 'pointer';
                submitBtn.innerText = submitBtn.dataset.oldText;
            }
        }
        const result = JSON.parse(rawText);
            if (result.status === 'success') {
                // Если всё отлично — выводим твой стандартный алерт успеха
                alert("🎉 Клиент и контактные лица успешно зарегистрированы!");
                window.location.reload(); 
            } else {
                // НАМЕРТВО ИСПРАВЛЕНО: Если бэкенд save.php завернул дубликат УНП
                alert("⚠️ ОТКАЗ В РЕГИСТРАЦИИ КЛИЕНТА:\n\n" + result.message);
                
                // ЖЕЛЕЗНЫЙ РЕАКТИВНЫЙ UI: Возвращаем кнопку "Сохранить" в рабочее состояние!
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                    submitBtn.style.cursor = 'pointer';
                    submitBtn.innerText = submitBtn.dataset.oldText || "Сохранить изменения";
                }
                
                // Подсвечиваем инпут УНП красным неоном, чтобы привлечь внимание
                const unpInput = document.getElementById('add_client_unp') || document.getElementById('unp');
                if (unpInput) {
                    unpInput.style.borderColor = '#ef4444';
                    unpInput.style.boxShadow = '0 0 10px rgba(239, 68, 68, 0.4)';
                    unpInput.focus(); // Автоматически ставим курсор на ошибочный УНП
                }
            }
    };
   
</script>
<div id="complexModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); justify-content: center; align-items: center; z-index: 999999; box-sizing: border-box; padding: 15px;">
    <div style="background: #1e1e2d; border-radius: 8px; border: 1px solid #323248; padding: 25px; width: 550px; box-sizing: border-box; box-shadow: 0 10px 30px rgba(0,0,0,0.5); color: #fff; font-family: sans-serif;"> 
        
        <h3 style="margin-top: 0; color: #fff; font-size: 16px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #323248; padding-bottom: 10px; margin-bottom: 20px;">
            🗂 Создание контрагента и договора в одной связке
        </h3>

        <form id="jointClientContractForm" onsubmit="return saveComplexFormDirectly(event, this);" style="margin: 0; padding: 0;">
            <input type="hidden" name="action_type" value="create_client_with_contract">

            <!-- РЯД 1: ДАННЫЕ ОРГАНИЗАЦИИ -->
            <div style="margin-bottom: 15px; display: flex; gap: 15px; width: 100%; box-sizing: border-box;">
                <div style="flex: 3; display: flex; flex-direction: column; gap: 4px; min-width: 0;">
                    <label style="font-size: 10px; color: #92929f; font-weight: bold; text-transform: uppercase;">Название компании</label>
                    <input type="text" name="client_name" id="complex_client_name" required placeholder="ООО СантехМонтаж" style="height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; width: 100%; box-sizing: border-box;">
                </div>
                <div style="flex: 2; display: flex; flex-direction: column; gap: 4px; min-width: 0; position: relative;">
                    <label style="font-size: 10px; color: #92929f; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3px;">УНП / ИНН</label>                    
                    <input type="text" name="unp" id="complex_unp" placeholder="123456789" maxlength="9" style="height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; width: 100%; box-sizing: border-box;">
                    <span id="complex_unp_error_msg" style="display: none; font-size: 11px; color: #ef4444; font-weight: bold; margin-top: 4px; line-height: 1.3;"></span>
                </div>
            </div>

            <!-- РЯД 2: ПЕРВИЧНЫЕ КОНТАКТЫ СВЯЗИ -->
            <div style="margin-bottom: 15px; display: flex; gap: 15px; width: 100%; box-sizing: border-box;">
                <div style="flex: 1; display: flex; flex-direction: column; gap: 4px; min-width: 0; text-align: left;">
                    <label style="font-size: 10px; color: #92929f; font-weight: bold; text-transform: uppercase;">Телефон связи</label>
                    <input type="text" name="phone" id="complex_phone" placeholder="+375 (...)" style="height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; width: 100%; box-sizing: border-box;">
                </div>
                <div style="flex: 1; display: flex; flex-direction: column; gap: 4px; min-width: 0; text-align: left;">
                    <label style="font-size: 10px; color: #92929f; font-weight: bold; text-transform: uppercase;">Контактное лицо</label>
                    <input type="text" name="contact_person" id="complex_contact_person" placeholder="Иванов И.И." style="height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; width: 100%; box-sizing: border-box;">
                </div>
            </div>

            <!-- РЯД 3: ПАРАМЕТРЫ НОВОГО ДОГОВОРА -->
            <div style="margin-bottom: 20px; display: flex; gap: 15px; width: 100%; box-sizing: border-box;">
                <div style="flex: 4; display: flex; flex-direction: column; gap: 4px; min-width: 0; text-align: left;">
                    <label style="font-size: 10px; color: #92929f; font-weight: bold; text-transform: uppercase;">№ Нового договора</label>
                    <input type="text" name="contract_number" id="complex_contract_number" required placeholder="Напр: 240/Т" style="height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; width: 100%; box-sizing: border-box;">
                </div>
                <div style="flex: 3; display: flex; flex-direction: column; gap: 4px; min-width: 0; text-align: left;">
                    <label style="font-size: 10px; color: #92929f; font-weight: bold; text-transform: uppercase;">Дата заключения</label>
                    <input type="date" name="contract_date" id="complex_contract_date" required style="height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; width: 100%; box-sizing: border-box; color-scheme: dark;">
                </div>
                <div style="flex: 4; display: flex; flex-direction: column; gap: 4px; min-width: 0; text-align: left;">
                    <label style="font-size: 10px; color: #92929f; font-weight: bold; text-transform: uppercase;">Тип продукции</label>
                    <select name="product_type" id="complex_product_type" style="height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; width: 100%; box-sizing: border-box; cursor: pointer;">
                        <option value="Сантехника">Сантехника</option>
                        <option value="Оборудование">Оборудование</option>
                        <option value="Трубопровод">Трубопровод</option>
                        <option value="Кастом">Кастом</option>
                    </select>
                </div>
            </div>

            <!-- БЛОК КНОПОК УПРАВЛЕНИЯ -->
            <div style="display: flex; gap: 10px; justify-content: flex-end; align-items: center; width: 100%; box-sizing: border-box; margin-top: 20px; border-top: 1px solid #323248; padding-top: 15px;">
                <button type="button" onclick="document.getElementById('complexModal').style.display = 'none';" style="height: 38px; padding: 0 20px; background: rgba(255,255,255,0.05); border: 1px solid #323248; color: #fff; border-radius: 6px; font-size: 13px; font-weight: bold; cursor: pointer;">
                    Отмена
                </button>
                <button type="submit" style="height: 38px; padding: 0 22px; background: #059669; border: none; color: #fff; border-radius: 6px; font-size: 13px; font-weight: bold; cursor: pointer;">
                    🚀 Создать связку
                </button>
            </div>
        </form>
    </div>
</div>
</body>
</html>