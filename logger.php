<?php
// logger_core.php — Глобальный автоматический перехватчик аудита безопасности

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Функция ручной записи (оставляем для совместимости и кастомных логов)
if (!function_exists('logAction')) {
    function logAction($actionType, $tableName, $description) {
        global $pdo;
        if (!$pdo) return;

        $userId = $_SESSION['user_id'] ?? null;

        try {
            $sql = "INSERT INTO action_logs (user_id, action_type, table_name, description, action_date) 
                    VALUES (?, ?, ?, ?, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId, strtoupper($actionType), $tableName, $description]);
        } catch (Exception $e) {
            error_log("Ошибка авто-логирования: " . $e->getMessage());
        }
    }
}

// 2. АВТОМАТИЧЕСКИЙ ПЕРЕХВАТЧИК ДЕЙСТВИЙ (Выполняется сам при подключении файла)
// Запускаем перехват только для POST/PUT запросов, которые меняют базу данных
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Получаем текущий файл, который вызвал JS/форма (например, update_cell.php)
    $currentScript = basename($_SERVER['SCRIPT_NAME']);
    
    // Читаем сырой JSON-поток, если данные пришли через fetch()
    $rawInput = json_decode(file_get_contents('php://input'), true) ?? [];
    // Объединяем обычный POST и JSON-данные для удобства анализа
    $req = array_merge($_POST, $rawInput);

    $actionType = 'UPDATE';
    $tableName = 'system';
    $description = '';

    switch ($currentScript) {
        
        // Перехват добавления новой строки клиента
        case 'add_row.php':
            $actionType = 'INSERT';
            $tableName = 'clients';
            $description = "Инициировано создание нового лида (пустой строки)";
            break;

        // Перехват быстрой смены ячеек в таблице клиентов
        case 'update_cell.php':
            $tableName = 'clients';
            $id = (int)($req['id'] ?? 0);
            $field = htmlspecialchars($req['field'] ?? '');
            $val = htmlspecialchars($req['value'] ?? '');

            if ($field === 'is_contract_signed' && (int)$val === 0) {
                $description = "Аннулирован контракт у клиента ID: {$id}";
            } elseif ($field === 'is_contract_signed' && (int)$val === 1) {
                $description = "Менеджер инициировал подписание договора у клиента ID: {$id}";
            } else {
                $description = "Отредактирован клиент ID: {$id}. Поле [{$field}] изменено на: '{$val}'";
            }
            break;

        // Перехват быстрой смены ячеек в договорах
        case 'update_contrac_cell.php':
            $tableName = 'projects';
            $id = (int)($req['id'] ?? 0);
            $field = htmlspecialchars($req['field'] ?? '');
            $val = htmlspecialchars($req['value'] ?? '');
            $description = "В договоре ID {$id} изменено поле [{$field}] на значение: '{$val}'";
            break;

        // Перехват добавления/редактирования ТТН
        case 'save_ttn.php':
            $tableName = 'project_ttns';
            $ttnId = (int)($req['ttn_id'] ?? 0);
            $projId = (int)($req['project_id'] ?? 0);
            $num = htmlspecialchars($req['ttn_number'] ?? '');
            $amt = htmlspecialchars($req['new_ttn_amount'] ?? '0');
            $cur = htmlspecialchars($req['ttn_currency_select'] ?? 'BYN');
            
            if ($ttnId > 0) {
                $description = "Изменена ТТН №{$num} (ID ТТН: {$ttnId}): сумма {$amt} {$cur}";
            } else {
                $actionType = 'INSERT';
                $description = "Добавлена ТТН №{$num} по договору ID {$projId}: сумма {$amt} {$cur}";
            }
            break;

        // Перехват массового сохранения ТТН
        case 'save_multiple_ttns.php':
            $actionType = 'INSERT';
            $tableName = 'project_ttns';
            $projId = (int)($req['project_id'] ?? 0);
            $num = htmlspecialchars($req['ttn_number'] ?? '');
            $description = "Добавлена ТТН № {$num} к проекту ID: {$projId}";
            break;

        // Перехват сохранения нового договора/черновика
        case 'save_new_contract.php':
            $tableName = 'projects';
            $cid = (int)($req['client_id'] ?? 0);
            $num = htmlspecialchars($req['contract_number'] ?? '');
            $sum = htmlspecialchars($req['amount'] ?? '0');
            $description = "Оформлен/изменен договор № {$num} для клиента ID: {$cid} (Сумма: {$sum})";
            break;

        // Перехват загрузки или удаления сканов файлов
        case 'upload_scan.php':
            $tableName = 'projects';
            $pid = (int)($req['project_id'] ?? 0);
            if (isset($req['action_mode']) && $req['action_mode'] === 'delete_contract_scan_full') {
                $actionType = 'DELETE';
                $description = "Полностью удалена скан-копия договора ID {$pid}";
            } else {
                $fileExt = isset($_FILES) ? strtolower(pathinfo(reset($_FILES)['name'] ?? '', PATHINFO_EXTENSION)) : 'pdf';
                $description = "Загружен новый скан договора ID {$pid}. Формат: .{$fileExt}";
            }
            break;

        // Авторизация пользователя в системе
        case 'login.php':
            $actionType = 'AUTH';
            $tableName = 'users';
            // Логин запишем чуть позже, когда сессия заполнится в самом скрипте,
            // поэтому для login.php и logout.php мы оставим вызов вручную внутри их файлов.
            break;
    }

    // Если лог успешно распознан — записываем его в базу
    if (!empty($description)) {
        logAction($actionType, $tableName, $description);
    }
}