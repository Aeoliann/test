<?php
// save.php — Бронебойный бэкенд сохранения/редактирования клиента с фиксацией типа продукции
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

    // Перехватываем все входящие POST-данные из пакета FormData
    $client_id      = (int)($_POST['id'] ?? 0); // Если ID есть — это редактирование, если нет — создание нового
    $client_name    = trim($_POST['client_name'] ?? '');
    $unp            = trim($_POST['unp'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $phone          = trim($_POST['phone'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $status         = trim($_POST['status'] ?? 'Новый');
    $source         = trim($_POST['source'] ?? 'Запрос');
    $manager_id     = (int)($_POST['manager_id'] ?? $userId);
    
    // Перехватываем наш тип продукции (УОКТ, ЕКМ, МПДУ)
    $product_type   = trim($_POST['product_type'] ?? ($_POST['product_info'] ?? 'Сантехника'));

    if (empty($client_name)) {
        throw new Exception("Ошибка: Наименование организации не может быть пустым!");
    }

    if ($client_id > 0) {
        // РЕЖИМ 1: ОБНОВЛЕНИЕ ТЕКУЩЕГО КЛИЕНТА (РЕДАКТИРОВАНИЕ)
        $sql = "UPDATE clients SET 
                    client_name = :client_name, 
                    unp = :unp, 
                    contact_person = :contact_person, 
                    phone = :phone, 
                    email = :email, 
                    status = :status, 
                    source = :source, 
                    manager_id = :manager_id,
                    product_type = :product_type
                WHERE id = :id";
                
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':client_name'    => $client_name,
            ':unp'            => $unp,
            ':contact_person' => $contact_person,
            ':phone'          => $phone,
            ':email'          => $email,
            ':status'         => $status,
            ':source'         => $source,
            ':manager_id'     => $manager_id,
            ':product_type'   => $product_type,
            ':id'             => $client_id
        ]);
    } else {
        // РЕЖИМ 2: СОЗДАНИЕ НОВОГО КЛИЕНТА С НУЛЯ (ДОБАВЛЕНИЕ)
        $sql = "INSERT INTO clients (client_name, unp, contact_person, phone, email, status, source, manager_id, product_type, first_contact_date) 
                VALUES (:client_name, :unp, :contact_person, :phone, :email, :status, :source, :manager_id, :product_type, CURDATE())";
                
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':client_name'    => $client_name,
            ':unp'            => $unp,
            ':contact_person' => $contact_person,
            ':phone'          => $phone,
            ':email'          => $email,
            ':status'         => $status,
            ':source'         => $source,
            ':manager_id'     => $manager_id,
            ':product_type'   => $product_type
        ]);
    }

    echo json_encode(['status' => 'success']);
    exit;

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
