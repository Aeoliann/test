<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientId = intval($_POST['id'] ?? 0); // Получаем ID редактируемого клиента
    if ($clientId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Неверный системный ID контрагента']);
        exit;
    }

    $client_name  = trim($_POST['client_name'] ?? '');
    $unp          = trim($_POST['unp'] ?? '');
    $website      = trim($_POST['website'] ?? '');
    $status       = trim($_POST['status'] ?? 'Новый');
    $product_type = trim($_POST['product_type'] ?? 'Сантехника');
    $source       = trim($_POST['source'] ?? '');
    $comment      = trim($_POST['comment'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    
    // Массив измененных/новых контактов
    $contacts = $_POST['contacts'] ?? [];

    try {
        $pdo->beginTransaction();

        // 1. Обновляем основные параметры компании
        $sqlClient = "UPDATE clients 
                      SET client_name = :client_name, 
                          unp = :unp, 
                          website = :website, 
                          status = :status,
                          product_type = :product_type,
                          source = :source,
                          comment = :comment,
                          email = :email
                      WHERE id = :id";
                      
        $stmtClient = $pdo->prepare($sqlClient);
        $stmtClient->execute([
            ':client_name'  => $client_name,
            ':unp'          => $unp,
            ':website'      => $website,
            ':status'       => $status,
            ':product_type' => $product_type,
            ':source'       => $source,
            ':comment'      => $comment,
            ':email'        => $email,
            ':id'           => $clientId
        ]);

        // 2. УДАЛЯЕМ АБСОЛЮТНО ВСЕ СТАРЫЕ КОНТАКТЫ ЭТОЙ КОМПАНИИ
        $sqlDelete = "DELETE FROM client_contacts WHERE client_id = :client_id";
        $stmtDelete = $pdo->prepare($sqlDelete);
        $stmtDelete->execute([':client_id' => $clientId]);

        // 3. ЗАПИСЫВАЕМ НОВЫЙ АКТУАЛЬНЫЙ СПИСОК КОНТАКТОВ
        if (!empty($contacts) && is_array($contacts)) {
            $sqlContact = "INSERT INTO client_contacts (client_id, name, position, phone, email, function_notes) 
                           VALUES (:client_id, :name, :position, :phone, :email, :function_notes)";
            $stmtContact = $pdo->prepare($sqlContact);

            foreach ($contacts as $c) {
                $contactName = trim($c['name'] ?? '');
                if (empty($contactName)) {
                    continue;
                }

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
        echo json_encode(['status' => 'success', 'message' => 'Карточка компании и контакты успешно перезаписаны']);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Ошибка обновления СУБД: ' . $e->getMessage()]);
        exit;
    }
}
