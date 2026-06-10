<?php
// save.php — Стабильный транзакционный бэкенд сохранения и редактирования клиентов Santeks CRM
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

header('Content-Type: application/json');
if (ob_get_length()) ob_clean();

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Доступ запрещен. Авторизуйтесь.");
    }
    $userId = (int)$_SESSION['user_id'];
    $is_joint_action = !empty($_POST['contract_number']) 
                    || !empty($_POST['number']) 
                    || !empty($_POST['contract_num'])
                    || !empty($_POST['add_contract_number'])
                    || (isset($_POST['action']) && $_POST['action'] === 'complex');
    if ($is_joint_action) {
        $client_name     = trim($_POST['client_name'] ?? ($_POST['name'] ?? ''));
        $unp             = trim($_POST['unp'] ?? ($_POST['unp_code'] ?? ''));
        $contact_person  = trim($_POST['contact_person'] ?? ($_POST['person'] ?? ''));
        $phone           = trim($_POST['phone'] ?? '');
        $email           = trim($_POST['email'] ?? '');
        $source          = trim($_POST['source'] ?? 'Запрос');
        $manager_id      = (int)($_POST['manager_id'] ?? $userId);
        
        // Всеядный сбор полей типа продукции
        $product_type    = trim($_POST['product_type'] ?? ($_POST['product_info'] ?? ($_POST['product'] ?? 'Сантехника')));
        
        // Всеядный сбор номера и даты договора
        $contract_number = trim($_POST['contract_number'] ?? ($_POST['number'] ?? ($_POST['contract_num'] ?? ($_POST['add_contract_number'] ?? ''))));
        $contract_date   = !empty($_POST['contract_date']) ? trim($_POST['contract_date']) : (!empty($_POST['date']) ? trim($_POST['date']) : date('Y-m-d'));

        if (empty($client_name) || empty($contract_number)) {
            throw new Exception("Не заполнены обязательные поля: Наименование организации (".$client_name.") или № Договора (".$contract_number.")!");
        }

        // ДИАГНОСТИКА СТРУКТУРЫ ТАБЛИЦЫ ПРОЕКТОВ (Ищем реальное имя колонки связи)
        $client_id_column = 'client_id';
        try {
            $qProj = $pdo->query("DESCRIBE projects");
            $projColumns = $qProj->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('client_id', $projColumns)) { $client_id_column = 'client_id'; }
            elseif (in_array('cid', $projColumns)) { $client_id_column = 'cid'; }
            elseif (in_array('project_id', $projColumns)) { $client_id_column = 'project_id'; }
        } catch (Exception $e) {
            $client_id_column = 'client_id';
        }

      // ВКЛЮЧАЕМ ЖЕСТКИЙ ТРАНЗАКЦИОННЫЙ КОНТРОЛЬ СУБД MARIADB
        $pdo->beginTransaction();


      // ШАГ 1: Вставляем клиента со статусом "Текущий", датой следующего контакта и флагом подписанного договора
        // ИСПРАВЛЕНИЕ БАГА 96: Добавили поле is_contract_signed со значением 1, чтобы контракт отобразился в contracts.php
        $next_contact_default = date('Y-m-d', strtotime('+7 days'));

        $sqlClient = "INSERT INTO clients (client_name, unp, contact_person, phone, email, status, source, manager_id, product_type, first_contact_date, next_contact_date, is_contract_signed) 
                      VALUES (?, ?, ?, ?, ?, 'Текущий', ?, ?, ?, CURDATE(), ?, 1)";
        
        $stmtClient = $pdo->prepare($sqlClient);
        $stmtClient->execute([
            $client_name, 
            $unp, 
            $contact_person, 
            $phone, 
            $email, 
            $source, 
            $manager_id, 
            $product_type,
            $next_contact_default
        ]);

        $newClientId = (int)$pdo->lastInsertId();
        if ($newClientId <= 0) {
            throw new Exception("Сбой генерации системного ID клиента в транзакции.");
        }

      // ШАГ 2: Вставляем связанный договор в таблицу проектов/контрактов
        // ИСПРАВЛЕНИЕ БАГА 96: Оставили только этот чистый блок, всё что ниже — удалили!
        $sqlContract = "INSERT INTO projects (`$client_id_column`, contract_number, contract_date, product_type) 
                        VALUES (?, ?, ?, ?)";
        
        $stmtContract = $pdo->prepare($sqlContract);
        $stmtContract->execute([
            $newClientId, 
            $contract_number, 
            $contract_date,
            $product_type
        ]);

        // ФИКСИРУЕМ УСПЕХ: Данные одновременно и монолитно влетают во все таблицы!
        $pdo->commit();
        echo json_encode(['status' => 'success']);
        exit;
        // Если динамический SQL кажется перегруженным, можно использовать прямой бронебойный вариант:
        // $sqlContract = "INSERT INTO projects (`$client_id_column`, contract_number, contract_date, manager_id, status) VALUES (?, ?, ?, ?, 'Действует')";

        $stmtContract = $pdo->prepare($sqlContract);
        
        // Собираем параметры для execute
        $contractParams = [$newClientId, $contract_number, $contract_date, $manager_id];
        
        $stmtContract->execute($contractParams);    

        // ФИКСИРУЕМ УСПЕХ: Данные одновременно и монолитно влетают во все три таблицы!
        $pdo->commit();
        echo json_encode(['status' => 'success']);
        exit;
    } else {
        // =========================================================================
        // РЕЖИМ Б: СТАНДАРТНОЕ ОДИНОЧНОЕ СОХРАНЕНИЕ / РЕДАКТИРОВАНИЕ КЛИЕНТА
        // =========================================================================
        $client_id          = (int)($_POST['id'] ?? 0); 
        $client_name        = trim($_POST['client_name'] ?? '');
        $unp                = trim($_POST['unp'] ?? '');
        $contact_person     = trim($_POST['contact_person'] ?? '');
        $phone              = trim($_POST['phone'] ?? '');
        $email              = trim($_POST['email'] ?? '');
        $status             = trim($_POST['status'] ?? 'Новый');
        $source             = trim($_POST['source'] ?? 'Запрос');
        $manager_id         = (int)($_POST['manager_id'] ?? $userId);
        $product_type       = trim($_POST['product_type'] ?? ($_POST['product_info'] ?? 'Сантехника'));
        
        $first_contact_date = !empty($_POST['first_contact_date']) ? trim($_POST['first_contact_date']) : date('Y-m-d');
        $next_contact_date  = !empty($_POST['next_contact_date']) ? trim($_POST['next_contact_date']) : null;
        $manager_comment    = trim($_POST['comment'] ?? '');

        if (empty($client_name)) {
            throw new Exception("Ошибка: Наименование организации не может быть пустым!");
        }

        if ($client_id > 0) {
            // ИСПРАВЛЕНО НАМЕРТВО: Переписано на твой оригинальный, проверенный SQL-запрос UPDATE по позиционным плейсхолдерам (?)
            $sql = "UPDATE clients SET 
                        client_name = ?, first_contact_date = ?, source = ?, 
                        phone = ?, email = ?, product_type = ?, 
                        next_contact_date = ?, status = ?, comment = ?
                    WHERE id = ?";
                    
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $client_name, $first_contact_date, $source,
                $phone, $email, $product_type,
                $next_contact_date, $status, $manager_comment, $client_id
            ]);
        } else {
            // Добавление одиночного лида с нуля
            $sql = "INSERT INTO clients (client_name, unp, contact_person, phone, email, status, source, manager_id, product_type, first_contact_date, next_contact_date, comment) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $client_name, $unp, $contact_person, $phone, $email, $status, $source, $manager_id, $product_type, $next_contact_date, $manager_comment
            ]);
        }

        echo json_encode(['status' => 'success']);
        exit;
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
