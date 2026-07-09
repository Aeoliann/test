<?php
// db.php — Подключение к СУБД MariaDB и функции безопасности
$host = 'localhost';
$db   = 'test';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';


$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     header('Content-Type: application/json');
     echo json_encode(['status' => 'error', 'message' => 'Крит подключения к ноде vh108: ' . $e->getMessage()]);
     exit;
}

/**
 * ГЛОБАЛЬНАЯ ФУНКЦИЯ ЗАПИСИ ЛОГОВ АКТИВНОСТИ
 */
if (!function_exists('logAction')) {
    function logAction($actionType, $tableName = '', $description = '') {
        global $pdo;
        if (!$pdo) return;

        // УМНЫЙ ХАК для старых вызовов с $pdo первым аргументом
        if (is_object($actionType) && $actionType instanceof PDO) {
            $args = func_get_args();
            if (count($args) >= 5) {
                $actionType  = $args[1];
                $tableName   = $args[2];
                $description = $args[4];
            } else {
                $actionType  = $args[1] ?? 'UPDATE';
                $tableName   = $args[2] ?? 'system';
                $description = $args[3] ?? '';
            }
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
      if ($userId > 0) {
    try {
        // Записываем текущее время в карточку сотрудника
        $pdo->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?")->execute([$userId]);
    } catch (Exception $e) {
        // Мягко игнорируем, если что-то пошло не так, чтобы не вешать CRM
    }
}

        try {
            $sql = "INSERT INTO action_logs (user_id, action_type, table_name, details, action_date) 
                    VALUES (?, ?, ?, ?, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $userId, 
                strtoupper(trim((string)$actionType)), 
                trim((string)$tableName), 
                trim((string)$description)
            ]);
        } catch (\PDOException $e) {
            error_log("КРИТИЧЕСКАЯ ОШИБКА АУДИТА: " . $e->getMessage());
        }
    }
}

// =========================================================================
// АВТОМАТИЧЕСКИЙ ДВИЖОК ПЕРЕХВАТА ДЕЙСТВИЙ (ИЗОЛИРОВАННАЯ CRM-ВЕРСИЯ)
// =========================================================================
(function() {
    $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // КРИТИЧЕСКИЙ ФИЛЬТР: Если это чисто фоновые скрипты выгрузки JSON-данных для интерфейса, 
    // полностью выключаем логгер, чтобы он не трогал нативный поток php://input и не ломал модалки!
    $apiScripts = ['get_ttns.php', 'get_matrix_details.php', 'ping.php'];
    if (in_array($currentScript, $apiScripts)) {
        return; // Мгновенно выходим, сохраняя поток php://input нетронутым для модального окна!
    }

    $req = $_POST; 
    if ($method === 'POST' && empty($req)) {
        $raw = file_get_contents('php://input');
        $req = json_decode($raw, true) ?? [];
    }

    // 1. ОБРАБОТКА ИЗМЕНЕНИЙ В БД И СИСТЕМНЫХ ДЕЙСТВИЙ (POST-ЗАПРОСЫ)
    if ($method === 'POST') {
        switch ($currentScript) {
            case 'save.php':
                $clientId = (int)($req['id'] ?? 0);
                $clientName = htmlspecialchars($req['client_name'] ?? $req['name'] ?? '');
                $unp = htmlspecialchars($req['unp'] ?? '');
                if ($clientId > 0) {
                    logAction('UPDATE', 'clients', "Обновлена карточка клиента '{$clientName}' (ID: {$clientId}, УНП: {$unp})");
                } else {
                    logAction('INSERT', 'clients', "Создан новый клиент '{$clientName}' (УНП: {$unp})");
                }
                break;

            case 'register_user.php':
                $newUser = htmlspecialchars($req['login'] ?? ''); $newRole = htmlspecialchars($req['role'] ?? 'manager');
                logAction('INSERT', 'users', "Администратор зарегистрировал нового пользователя: '{$newUser}' с ролью [{$newRole}]");
                break;

            case 'update_user.php':
                $targetId = (int)($req['id'] ?? 0); $targetLogin = htmlspecialchars($req['login'] ?? ''); $targetRole = htmlspecialchars($req['role'] ?? '');
                logAction('UPDATE', 'users', "Изменены данные пользователя '{$targetLogin}' (ID: {$targetId}, Новая роль: [{$targetRole}])");
                break;

            case 'force-fix.php': logAction('UPDATE', 'system', "Запущен скрипт принудительного исправления структуры БД (force-fix.php)"); break;
            case 'rates.php': logAction('UPDATE', 'system', "Менеджер инициировал ручное обновление курсов валют"); break;

            case 'upload_ttn_pdf.php':
                $ttnId = (int)($req['ttn_id'] ?? 0); $fileName = htmlspecialchars($_FILES[key($_FILES)]['name'] ?? 'документ');
                logAction('UPDATE', 'project_ttns', "К накладной ТТН ID {$ttnId} прикреплен PDF-файл оригинала (Файл: {$fileName})");
                break;

            case 'update_cell.php':
                $id = (int)($req['id'] ?? 0); $field = htmlspecialchars($req['field'] ?? ''); $val = htmlspecialchars($req['value'] ?? '');
                if ($field === 'is_contract_signed' && (int)$val === 0) logAction('UPDATE', 'clients', "Аннулирован контракт у клиента ID: {$id}");
                elseif ($field === 'is_contract_signed' && (int)$val === 1) logAction('UPDATE', 'clients', "Менеджер инициировал подписание договора у клиента ID: {$id}");
                else logAction('UPDATE', 'clients', "Отредактирован client ID: {$id}. Изменено поле [{$field}] на '{$val}'");
                break;

            case 'update_contract_cell.php':
                $id = (int)($req['id'] ?? 0); $field = htmlspecialchars($req['field'] ?? ''); $val = htmlspecialchars($req['value'] ?? '');
                logAction('UPDATE', 'projects', "В договоре ID {$id} изменено поле [{$field}] на значение: '{$val}'");
                break;

            case 'add_row.php': logAction('INSERT', 'clients', "Инициировано создание нового лида (пустой строки)"); break;
            case 'save_new_contract.php':
                $cid = (int)($req['client_id'] ?? 0); $num = htmlspecialchars($req['contract_number'] ?? ''); $sum = htmlspecialchars($req['amount'] ?? '0');
                logAction('INSERT', 'projects', "Оформлен новый договор № {$num} для клиента ID: {$cid} (Сумма: {$sum})");
                break;

            case 'save_ttn.php':
                $ttnId = (int)($req['ttn_id'] ?? 0); $projId = (int)($req['project_id'] ?? 0); $num = htmlspecialchars($req['ttn_number'] ?? ''); $amt = htmlspecialchars($req['new_ttn_amount'] ?? '0'); $cur = htmlspecialchars($req['ttn_currency_select'] ?? 'BYN');
                if ($ttnId > 0) logAction('UPDATE', 'project_ttns', "Изменена ТТН №{$num} (ID ТТН: {$ttnId}): сумма {$amt} {$cur}");
                else logAction('INSERT', 'project_ttns', "Добавлена ТТН №{$num} по договору ID {$projId}: сумма {$amt} {$cur}");
                break;

            case 'save_multiple_ttns.php':
                $projId = (int)($req['project_id'] ?? 0); $num = htmlspecialchars($req['ttn_number'] ?? '');
                logAction('INSERT', 'project_ttns', "Добавлена ТТН № {$num} к проекту ID: {$projId}");
                break;

            case 'upload_scan.php':
                $pid = (int)($req['project_id'] ?? 0);
                if (isset($req['action_mode']) && $req['action_mode'] === 'delete_contract_scan_full') logAction('DELETE', 'projects', "Полностью удалена скан-копия договора ID {$pid}");
                else { $fileName = htmlspecialchars($_FILES[key($_FILES)]['name'] ?? 'файл'); logAction('UPDATE', 'projects', "Загружен новый скан к договору ID {$pid} (Файл: {$fileName})"); }
                break;

            case 'upload_contract.php':
                $pid = (int)($_POST['project_id'] ?? 0); $fileName = htmlspecialchars($_FILES['contract_pdf']['name'] ?? 'документ');
                logAction('UPDATE', 'projects', "Прикрепил скан договора к контракту ID {$pid} (Файл: {$fileName})");
                break;

                   case 'get_ttns.php':
                $ttnId = (int)($req['ttn_id'] ?? 0);
                // Если ttn_id нет, значит идет обычный POST-запрос на добавление ТТН из формы модалки
                if ($ttnId <= 0 && isset($req['ttn_number'])) {
                    $num = htmlspecialchars($req['ttn_number']);
                    $pid = (int)($req['project_id'] ?? 0);
                    $amt = htmlspecialchars($req['new_ttn_amount'] ?? '0');
                    $cur = htmlspecialchars($req['ttn_currency'] ?? 'BYN');
                    logAction('INSERT', 'project_ttns', "Быстрое добавление ТТН №{$num} из модалки договора ID {$pid} на сумму {$amt} {$cur}");
                }
                break;
        }
    }

    // 2. СКАЧИВАНИЕ ОТЧЕТОВ И ПЕРЕХОДЫ ПО СТРАНИЦАМ (GET-ЗАПРОСЫ)
    if ($method === 'GET') {
        $pagesMap = [
            'index.php'                  => 'Главная страница (Рабочий стол CRM)',
            'contracts.php'              => 'Раздел «Договоры и Контракты»',
            'activity_logs.php'          => 'Журнал системного аудита безопасности',
            'clients.php'                => 'База клиентов и лидов',
            'tasks.php'                  => 'Раздел «Поручения и задачи»',
            'users_admin.php'            => 'Панель управления пользователями (Админка)',
            'report.php'                 => 'Просмотр/генерация системного отчета эффективности',
            'export_excel.php'           => 'Экспорт базы клиентов в Excel',
            'export_contracts_excel.php' => 'Экспорт базы договоров в Excel',
        ];

        if (array_key_exists($currentScript, $pagesMap)) {
            $actionType = (strpos($currentScript, 'export') !== false) ? 'DELETE' : 'AUTH'; 
            $details = !empty($_GET) ? ' с фильтрами: ' . htmlspecialchars(json_encode($_GET, JSON_UNESCAPED_UNICODE)) : '';
            logAction($actionType, 'system', "Действие: " . $pagesMap[$currentScript] . $details);
        }
    }
})();
?>