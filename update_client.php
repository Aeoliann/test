<?php
// update_client.php — Бесперебойный VIP-контроллер перезаписи карточки контрагента и мульти-контактов
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

header('Content-Type: application/json');
if (ob_get_length()) ob_clean(); // Очистка буфера от случайных пробелов, ломающих JSON

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Всеядный сканер ID компании для защиты от затирания
    $clientId = 0;
    if (isset($_POST['client_id']) && intval($_POST['client_id']) > 0) {
        $clientId = intval($_POST['client_id']);
    } elseif (isset($_POST['id']) && intval($_POST['id']) > 0) {
        $clientId = intval($_POST['id']);
    }

    if ($clientId <= 0) {
        echo json_encode([
            'status' => 'error', 
            'message' => 'Неверный системный ID контрагента. Получено: id="' . ($_POST['id'] ?? 'null') . '", client_id="' . ($_POST['client_id'] ?? 'null') . '".'
        ]);
        exit;
    }

    // Сбор основных текстовых параметров компании
    $client_name  = trim($_POST['client_name'] ?? '');
    $unp          = trim($_POST['unp'] ?? '');
    $website      = trim($_POST['website'] ?? '');
    $status       = trim($_POST['status'] ?? 'Новый');
    $product_type = trim($_POST['product_type'] ?? 'Сантехника');
    $source       = trim($_POST['source'] ?? ($_POST['lead_source'] ?? ''));
    $comment      = trim($_POST['comment'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    
    // Принимаем входящий динамический массив контактных лиц
    $contacts = $_POST['contacts'] ?? [];

    try {
        $pdo->beginTransaction();

        // 1. НАМЕРТВО ИСПРАВЛЕНО: Обновление через позиционные параметры (строго по порядку)
        $sqlClient = "UPDATE clients 
                      SET client_name = ?, 
                          unp = ?, 
                          website = ?, 
                          status = ?,
                          product_type = ?,
                          source = ?,
                          comment = ?,
                          email = ?
                      WHERE id = ?";
                      
        $stmtClient = $pdo->prepare($sqlClient);
        $stmtClient->execute([
            $client_name,
            $unp,
            $website,
            $status,
            $product_type,
            $source,
            $comment,
            $email,
            $clientId // ID строго последним, так как он идет после WHERE
        ]);

        // 2. Удаление старых контактов этой компании
        $sqlDelete = "DELETE FROM client_contacts WHERE client_id = ?";
        $stmtDelete = $pdo->prepare($sqlDelete);
        $stmtDelete->execute([$clientId]);

        // 3. НАМЕРТВО ИСПРАВЛЕНО: Запись контактов строго под твою структуру полей (5 рабочих колонок)
        if (!empty($contacts) && is_array($contacts)) {
            // Теперь у нас 7 колонок и ровно 7 знаков вопроса ?
            $sqlContact = "INSERT INTO client_contacts (client_id, name, position, phone, email, postal_address, function_notes) 
                           VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmtContact = $pdo->prepare($sqlContact);

            foreach ($contacts as $c) {
                $contactName = trim($c['name'] ?? ($c['contact_name'] ?? ''));
                if (empty($contactName)) {
                    continue;
                }

                $stmtContact->execute([
                    $clientId,
                    $contactName,
                    trim($c['position'] ?? ($c['contact_role'] ?? '')),
                    trim($c['phone'] ?? ($c['contact_phone'] ?? '')),
                    trim($c['email'] ?? ($c['contact_email'] ?? '')),
                    trim($c['postal_address'] ?? ''), // Ловим Почтовый адрес
                    trim($c['function_notes'] ?? '')  // Ловим Примечания
                ]);
            }
        }
        if (function_exists('logAction')) {
            logAction($pdo, 'UPDATE', 'clients', $clientId, "Пакетное обновление карточки контрагента: '{$client_name}'");
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Карточка компании и контакты успешно перезаписаны!']);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['status' => 'error', 'message' => 'Ошибка обновления СУБД: ' . $e->getMessage()]);
        exit;
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Разрешены только POST-запросы.']);
    exit;
}