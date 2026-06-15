<?php
// save.php — Главный контроллер сохранения транзакций Santeks CRM
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';
require_once 'logger.php'; // НАМЕРТВО ИСПРАВЛЕНО: Подключаем логгер для предотвращения Fatal Error

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Сессия завершена. Авторизуйтесь заново.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];

try {
    $action_mode = $_POST['action'] ?? '';

    // =========================================================================
    // РЕЖИМ А: ПАКЕТНАЯ СВЯЗКА КЛИЕНТ + КОНТРАКТ (ИНТЕГРИРОВАНА МУЛЬТИВАЛЮТНОСТЬ)
    // =========================================================================
    if ($action_mode === 'complex') {
        $client_name     = trim($_POST['client_name'] ?? '');
        $unp             = trim($_POST['unp'] ?? '');
        $phone           = trim($_POST['phone'] ?? '');
        $contact_person  = trim($_POST['contact_person'] ?? '');
        $contract_number = trim($_POST['contract_number'] ?? '');
        $contract_date   = trim($_POST['contract_date'] ?? date('Y-m-d'));
        $product_type    = trim($_POST['product_type'] ?? 'Сантехника');

        // ---- ХОТФИКС: Ловим валюту договора из пакетной формы ----
        $currency        = trim($_POST['currency'] ?? 'BYN');
        // ----------------------------------------------------------

        if (empty($client_name) || empty($contract_number)) {
            throw new Exception("Не заполнены обязательные поля: Название или Номер договора.");
        }

        $pdo->beginTransaction();

        // ШАГ 1: Вставка клиента с флагом подписания 1 и автопродлением даты следующего контакта (+7 дней)
        $next_contact_default = date('Y-m-d', strtotime('+7 days'));
        $sqlClient = "INSERT INTO clients (client_name, unp, contact_person, phone, status, source, manager_id, product_type, first_contact_date, next_contact_date, is_contract_signed) 
                      VALUES (?, ?, ?, ?, 'Текущий', 'Связка', ?, ?, CURDATE(), ?, 1)";
        
        $pdo->prepare($sqlClient)->execute([$client_name, $unp, $contact_person, $phone, $userId, $product_type, $next_contact_default]);
        $newClientId = (int)$pdo->lastInsertId();

        // ШАГ 2: Вставка контракта в projects (ИСПРАВЛЕНО: Записываем переменную $currency вместо жесткого 'BYN')
        $sqlContract = "INSERT INTO projects (client_id, contract_number, contract_date, product_type, currency) 
                        VALUES (?, ?, ?, ?, ?)";
        $pdo->prepare($sqlContract)->execute([$newClientId, $contract_number, $contract_date, $product_type, $currency]);
        $newProjectId = (int)$pdo->lastInsertId();

        // Пишем в логи
        if (function_exists('logAction')) {
            logAction($pdo, 'INSERT', 'projects', $newProjectId, "Создана комплексная связка: Клиент '{$client_name}' (ID: {$newClientId}) и Договор №{$contract_number} (Валюта: {$currency})");
        }

        $pdo->commit();
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    // =========================================================================
    // РЕЖИМ Б: СТАНДАРТНОЕ ОДИНОЧНОЕ СОХРАНЕНИЕ / РЕДАКТИРОВАНИЕ
    // =========================================================================
    else {
        $client_id          = (int)($_POST['id'] ?? 0); 
        $client_name        = trim($_POST['client_name'] ?? '');
        $unp                = trim($_POST['unp'] ?? '');
        $contact_person     = trim($_POST['contact_person'] ?? '');
        $phone              = trim($_POST['phone'] ?? '');
        $email              = trim($_POST['email'] ?? '');
        $status             = trim($_POST['status'] ?? 'Новый');
        $source             = trim($_POST['source'] ?? 'Запрос');
        $product_type       = trim($_POST['product_type'] ?? 'Сантехника');
        $first_contact_date = !empty($_POST['first_contact_date']) ? trim($_POST['first_contact_date']) : date('Y-m-d');
        $next_contact_date  = !empty($_POST['next_contact_date']) ? trim($_POST['next_contact_date']) : date('Y-m-d', strtotime('+7 days'));
        $manager_comment    = trim($_POST['comment'] ?? '');

        if (empty($client_name)) {
            throw new Exception("Наименование организации не может быть пустым!");
        }

     if ($client_id > 0) {
            // ИСПРАВЛЕНО НАМЕРТВО: Честный перехват состояния чекбокса
            $is_signed = isset($_POST['is_contract_signed']) ? (int)$_POST['is_contract_signed'] : 0;
            
            // Резервная страховка на случай альтернативного имени ключа в FormData
            if (isset($_POST['signed'])) {
                $is_signed = (int)$_POST['signed'];
            }

            // Твой проверенный рабочий SQL-запрос обновления карточки клиента
            $sql = "UPDATE clients SET 
                        client_name = ?, first_contact_date = ?, source = ?, 
                        phone = ?, email = ?, product_type = ?, 
                        next_contact_date = ?, status = ?, comment = ?, is_contract_signed = ?
                    WHERE id = ?";
            
            $pdo->prepare($sql)->execute([$client_name, $first_contact_date, $source, $phone, $email, $product_type, $next_contact_date, $status, $manager_comment, $is_signed, $client_id]);
            
            if (function_exists('logAction')) {
                logAction($pdo, 'UPDATE', 'clients', $client_id, "Отредактирован клиент ID: {$client_id}. Флаг контракта: {$is_signed}");
            }
            
            echo json_encode(['status' => 'success']);
            exit;
        } else {
            // INSERT одиночного клиента
            $sql = "INSERT INTO clients (client_name, unp, contact_person, phone, email, status, source, manager_id, product_type, first_contact_date, next_contact_date, comment) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$client_name, $unp, $contact_person, $phone, $email, $status, $source, $userId, $product_type, $first_contact_date, $next_contact_date, $manager_comment]);
            
            $newId = $pdo->lastInsertId();
            
            if (function_exists('logAction')) {
                logAction($pdo, 'INSERT', 'clients', $newId, "Создан лид: '{$client_name}' (ID: {$newId})");
            }
            
            echo json_encode(['status' => 'success']);
            exit;
        }
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack(); // Откатываем трансляцию СУБД в случае любого сбоя
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
?>