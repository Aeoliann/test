<?php
// create_client.php — создание нового клиента с загрузкой файла КП
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ============================================================
    // 1. СБОР ДАННЫХ
    // ============================================================
    $client_name   = trim($_POST['client_name'] ?? '');
    $unp           = trim($_POST['unp'] ?? '');
    $website       = trim($_POST['website'] ?? '');
    $ct_type       = trim($_POST['ct_type'] ?? '');
    $status        = trim($_POST['status'] ?? 'Новый');
    $source        = trim($_POST['source'] ?? 'Запрос');
    $comment       = trim($_POST['comment'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $manager_id    = (int)($_SESSION['user_id'] ?? 0);
    
    // ============================================================
    // 2. ОБРАБОТКА ПРОДУКЦИИ
    // ============================================================
    $product_type = 'Сантехника';
    if (isset($_POST['product_type']) && is_array($_POST['product_type']) && !empty($_POST['product_type'])) {
        $product_type = implode(', ', array_map('trim', $_POST['product_type']));
    } elseif (!empty($ct_type)) {
        $product_type = $ct_type;
    }

    // ============================================================
    // 3. КОНТАКТЫ
    // ============================================================
    $contacts = $_POST['contacts'] ?? [];

    if (empty($client_name)) {
        echo json_encode(['status' => 'error', 'message' => 'Название организации обязательно']);
        exit;
    }

    // ============================================================
    // 4. ЗАГРУЗКА ФАЙЛА (ЕСЛИ ПРИЛОЖЕН)
    // ============================================================
    $kp_file_name = null;
    if (isset($_FILES['kp_file']) && $_FILES['kp_file']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['kp_file']['tmp_name'];
        $fileName    = $_FILES['kp_file']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];

        if (in_array($fileExtension, $allowedExtensions)) {
            $uploadDir = __DIR__ . '/uploads/kp/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $kp_file_name = 'kp_' . uniqid() . '_' . md5($fileName . time()) . '.' . $fileExtension;
            $dest_path = $uploadDir . $kp_file_name;
            if (!move_uploaded_file($fileTmpPath, $dest_path)) {
                $kp_file_name = null; // не удалось сохранить
            }
        }
    }

    try {
        $pdo->beginTransaction();

        // ============================================================
        // 5. ВСТАВКА КЛИЕНТА
        // ============================================================
        $sqlClient = "INSERT INTO clients (
            client_name, unp, website, ct_type, status, source,
            comment, email, contact_person, phone, manager_id,
            product_type, kp_file, first_contact_date, next_contact_date
        ) VALUES (
            :client_name, :unp, :website, :ct_type, :status, :source,
            :comment, :email, :contact_person, :phone, :manager_id,
            :product_type, :kp_file, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY)
        )";
        $stmtClient = $pdo->prepare($sqlClient);
        $stmtClient->execute([
            ':client_name'   => $client_name,
            ':unp'           => $unp,
            ':website'       => $website,
            ':ct_type'       => $ct_type,
            ':status'        => $status,
            ':source'        => $source,
            ':comment'       => $comment,
            ':email'         => $email,
            ':contact_person'=> $contact_person,
            ':phone'         => $phone,
            ':manager_id'    => $manager_id,
            ':product_type'  => $product_type,
            ':kp_file'       => $kp_file_name
        ]);

        $clientId = $pdo->lastInsertId();

        // ============================================================
        // 6. ВСТАВКА КОНТАКТОВ
        // ============================================================
        if (!empty($contacts) && is_array($contacts)) {
            $sqlContact = "INSERT INTO client_contacts (client_id, name, position, phone, email, function_notes) 
                           VALUES (:client_id, :name, :position, :phone, :email, :function_notes)";
            $stmtContact = $pdo->prepare($sqlContact);

            foreach ($contacts as $c) {
                $contactName = trim($c['name'] ?? '');
                if (empty($contactName)) continue;

                $stmtContact->execute([
                    ':client_id'      => $clientId,
                    ':name'           => $contactName,
                    ':position'       => trim($c['position'] ?? ''),
                    ':phone'          => trim($c['phone'] ?? ''),
                    ':email'          => trim($c['email'] ?? ''),
                    ':function_notes' => trim($c['function_notes'] ?? '')
                ]);
            }
        }

        $pdo->commit();

        // ============================================================
        // 7. ОТВЕТ
        // ============================================================
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'message' => 'Клиент успешно создан',
            'client_id' => $clientId,
            'kp_file' => $kp_file_name
        ]);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Ошибка БД: ' . $e->getMessage()]);
        exit;
    }
}