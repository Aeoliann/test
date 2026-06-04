<?php
// save.php — Единый всеядный бэкенд сохранения, редактирования и комплексной связки клиентов CRM
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

    // 1. ИДЕНТИФИЦИРУЕМ РЕЖИМ РАБОТЫ ПО НАЛИЧИЮ НОМЕРА ДОГОВОРА В ПОТОКЕ $_POST
    $is_joint_action = !empty($_POST['contract_number']); // Если прилетел номер договора — это комплексная связка!

    if ($is_joint_action) {
        // =========================================================================
        // РЕЖИМ А: СВЕРХНАДЕЖНОЕ ПАКЕТНОЕ СОЗДАНИЕ КЛИЕНТА И ДОГОВОРА (В ОДИН КЛИК)
        // =========================================================================
        $client_name     = trim($_POST['client_name'] ?? '');
        $unp             = trim($_POST['unp'] ?? '');
        $contact_person  = trim($_POST['contact_person'] ?? '');
        $phone           = trim($_POST['phone'] ?? '');
        $email           = trim($_POST['email'] ?? '');
        $source          = trim($_POST['source'] ?? 'Запрос');
        $manager_id      = (int)($_POST['manager_id'] ?? $userId);
        $product_type    = trim($_POST['product_type'] ?? ($_POST['product_info'] ?? 'Сантехника'));
        
        $contract_number = trim($_POST['contract_number'] ?? '');
        $contract_date   = !empty($_POST['contract_date']) ? trim($_POST['contract_date']) : date('Y-m-d');

        if (empty($client_name) || empty($contract_number)) {
            throw new Exception("Не заполнены обязательные поля: Наименование или № Договора!");
        }

        // ВКЛЮЧАЕМ РЕЖИМ ТРАНЗАКЦИИ СУБД MARIADB
        $pdo->beginTransaction();

        // Строка 1: Вставляем клиента со статусом "Договор"
        $sqlClient = "INSERT INTO clients (client_name, unp, contact_person, phone, email, status, source, manager_id, product_type, first_contact_date) 
                      VALUES (:client_name, :unp, :contact_person, :phone, :email, 'Договор', :source, :manager_id, :product_type, CURDATE())";
        $stmtClient = $pdo->prepare($sqlClient);
        $stmtClient->execute([
            ':client_name'    => $client_name,
            ':unp'            => $unp,
            ':contact_person' => $contact_person,
            ':phone'          => $phone,
            ':email'          => $email,
            ':source'         => $source,
            ':manager_id'     => $manager_id,
            ':product_type'   => $product_type
        ]);

        $newClientId = (int)$pdo->lastInsertId();
        if ($newClientId <= 0) {
            throw new Exception("Сбой генерации системного ID клиента.");
        }

        // Строка 2: Вставляем связанный договор в таблицу проектов/контрактов
        $sqlContract = "INSERT INTO projects (client_id, contract_number, contract_date, product_type, user_id) 
                        VALUES (:client_id, :contract_number, :contract_date, :product_type, :user_id)";
        $stmtContract = $pdo->prepare($sqlContract);
        $stmtContract->execute([
            ':client_id'       => $newClientId,
            ':contract_number' => $contract_number,
            ':contract_date'   => $contract_date,
            ':product_type'    => $product_type,
            ':user_id'         => $manager_id // Связка договора с менеджером (твоё поле user_id)
        ]);

        // Фиксируем транзакцию — данные одновременно разлетаются во все таблицы!
        $pdo->commit();
        echo json_encode(['status' => 'success']);
        exit;

    } else {
        // =========================================================================
        // РЕЖИМ Б: СТАНДАРТНОЕ ОДИНОЧНОЕ СОХРАНЕНИЕ / РЕДАКТИРОВАНИЕ КЛИЕНТА (Твой прошлый код)
        // =========================================================================
        $client_id      = (int)($_POST['id'] ?? 0); 
        $client_name    = trim($_POST['client_name'] ?? '');
        $unp            = trim($_POST['unp'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone          = trim($_POST['phone'] ?? '');
        $email          = trim($_POST['email'] ?? '');
        $status         = trim($_POST['status'] ?? 'Новый');
        $source         = trim($_POST['source'] ?? 'Запрос');
        $manager_id     = (int)($_POST['manager_id'] ?? $userId);
        $product_type   = trim($_POST['product_type'] ?? ($_POST['product_info'] ?? 'Сантехника'));

        if (empty($client_name)) {
            throw new Exception("Ошибка: Наименование организации не может быть пустым!");
        }

        if ($client_id > 0) {
            // Редактирование
            $sql = "UPDATE clients SET client_name = :client_name, unp = :unp, contact_person = :contact_person, phone = :phone, email = :email, status = :status, source = :source, manager_id = :manager_id, product_type = :product_type WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':client_name' => $client_name, ':unp' => $unp, ':contact_person' => $contact_person, ':phone' => $phone, ':email' => $email, ':status' => $status, ':source' => $source, ':manager_id' => $manager_id, ':product_type' => $product_type, ':id' => $client_id]);
        } else {
            // Добавление одиночного лида
            $sql = "INSERT INTO clients (client_name, unp, contact_person, phone, email, status, source, manager_id, product_type, first_contact_date) VALUES (:client_name, :unp, :contact_person, :phone, :email, :status, :source, :manager_id, :product_type, CURDATE())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':client_name' => $client_name, ':unp' => $unp, ':contact_person' => $contact_person, ':phone' => $phone, ':email' => $email, ':status' => $status, ':source' => $source, ':manager_id' => $manager_id, ':product_type' => $product_type]);
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