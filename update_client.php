<?php
// update_client.php — Бесперебойный VIP-контроллер перезаписи карточки контрагента и мульти-контактов
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

header('Content-Type: application/json');
if (ob_get_length()) ob_clean();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // =========================================================================
    // 1. ВСЕЯДНЫЙ СКАНЕР ID КОМПАНИИ
    // =========================================================================
    $clientId = 0;
    if (isset($_POST['client_id']) && intval($_POST['client_id']) > 0) {
        $clientId = intval($_POST['client_id']);
    } elseif (isset($_POST['id']) && intval($_POST['id']) > 0) {
        $clientId = intval($_POST['id']);
    }

    if ($clientId <= 0) {
        echo json_encode([
            'status' => 'error', 
            'message' => 'Неверный системный ID контрагента.'
        ]);
        exit;
    }

    // =========================================================================
    // 2. СБОР ОСНОВНЫХ ПАРАМЕТРОВ
    // =========================================================================
    $client_name  = trim($_POST['client_name'] ?? '');
    $unp          = trim($_POST['unp'] ?? '');
    $website      = trim($_POST['website'] ?? '');
    $status       = trim($_POST['status'] ?? 'Новый');
    $source       = trim($_POST['source'] ?? 'Запрос');
    $comment      = trim($_POST['comment'] ?? '');
    $next_contact_date = trim($_POST['next_contact_date'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    
    // ✅ ИСПРАВЛЕНО: Определяем $is_signed
    $is_signed = isset($_POST['is_contract_signed']) ? (int)$_POST['is_contract_signed'] : 0;
    if (isset($_POST['signed'])) {
        $is_signed = (int)$_POST['signed'];
    }
    
    // Принимаем входящий динамический массив контактных лиц
    $contacts = $_POST['contacts'] ?? [];

    // =========================================================================
    // 3. СБОРКА ПРОДУКЦИИ
    // =========================================================================
    $posted_products = $_POST['product_type'] ?? ($_POST['ct_type'] ?? []);
    $final_product_type = 'Сантехника';

    if (is_array($posted_products) && !empty($posted_products)) {
        $final_product_type = implode(', ', array_map('trim', $posted_products));
    } else if (is_string($posted_products) && !empty(trim($posted_products))) {
        $final_product_type = trim($posted_products);
    }

    if (empty($client_name)) {
        echo json_encode(['status' => 'error', 'message' => 'Наименование организации не может быть пустым!']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // =========================================================================
        // 4. ОБНОВЛЕНИЕ КАРТОЧКИ КЛИЕНТА
        // =========================================================================
        $sql = "UPDATE clients SET 
                    client_name = ?, 
                    unp = ?,
                    contact_person = ?,
                    website = ?,
                    source = ?, 
                    phone = ?, 
                    email = ?, 
                    product_type = ?,
                    next_contact_date = ?, 
                    status = ?, 
                    comment = ?, 
                    is_contract_signed = ?,
                    ct_type = ?
                WHERE id = ?";
        
        $pdo->prepare($sql)->execute([
            $client_name, 
            $unp,
            $contact_person,
            $website,
            $source, 
            $phone, 
            $email, 
            $final_product_type,
            (!empty($next_contact_date) && $next_contact_date !== '0000-00-00') ? $next_contact_date : null, 
            $status, 
            $comment, 
            $is_signed,  // ✅ Теперь определена!
            $final_product_type,
            $clientId
        ]);

        // =========================================================================
        // 5. ОБНОВЛЕНИЕ ПРОДУКЦИИ В ПРОЕКТАХ
        // =========================================================================
        $stmtProjectUpdate = $pdo->prepare("UPDATE projects SET product_type = ? WHERE client_id = ?");
        $stmtProjectUpdate->execute([$final_product_type, $clientId]);

        // =========================================================================
        // 6. ЗАГРУЗКА СКАНА КП
        // =========================================================================
        if (isset($_FILES['kp_file']) && $_FILES['kp_file']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['kp_file']['tmp_name'];
            $fileName    = $_FILES['kp_file']['name'];
            
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
            
            if (in_array($fileExtension, $allowedExtensions)) {
                $newFileName = 'kp_' . $clientId . '_' . md5(time() . $fileName) . '.' . $fileExtension;
                $uploadFileDir = __DIR__ . '/uploads/kp/';
                
                if (!is_dir($uploadFileDir)) {
                    mkdir($uploadFileDir, 0755, true);
                }
                
                $dest_path = $uploadFileDir . $newFileName;
                
                if (move_uploaded_file($fileTmpPath, $dest_path)) {
                    $stmtKp = $pdo->prepare("UPDATE clients SET kp_file = ? WHERE id = ?");
                    $stmtKp->execute([$newFileName, $clientId]);
                }
            }
        }

        // =========================================================================
        // 7. ОБНОВЛЕНИЕ МУЛЬТИКОНТАКТОВ
        // =========================================================================
        // Удаляем старые контакты
        $pdo->prepare("DELETE FROM client_contacts WHERE client_id = ?")->execute([$clientId]);

        if (is_array($contacts) && !empty($contacts)) {
            $sqlContactInsert = "INSERT INTO client_contacts (client_id, name, contact_role, phone, email, postal_address, function_notes) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmtContactInsert = $pdo->prepare($sqlContactInsert);

            foreach ($contacts as $contact) {
                $cName = trim($contact['name'] ?? '');
                if (empty($cName) || $cName === 'Без имени') continue;

                $stmtContactInsert->execute([
                    $clientId,
                    $cName,
                    trim($contact['position'] ?? ''),
                    trim($contact['phone'] ?? ''),
                    trim($contact['email'] ?? ''),
                    trim($contact['postal_address'] ?? ''),
                    trim($contact['function_notes'] ?? '')
                ]);
            }
        }

        // =========================================================================
        // 8. ЛОГИРОВАНИЕ
        // =========================================================================
        if (function_exists('logAction')) {
            logAction('UPDATE', 'clients', $clientId, "Отредактирован клиент ID: {$clientId}. Продукция: {$final_product_type}");
        }

        $pdo->commit();

        echo json_encode([
            'status' => 'success', 
            'saved_products' => $final_product_type
        ]);
        exit;

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['status' => 'error', 'message' => 'Ошибка СУБД: ' . $e->getMessage()]);
        exit;
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Разрешены только POST-запросы.']);
    exit;
}
?>