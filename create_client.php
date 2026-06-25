<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php'; // Подключение PDO ($pdo)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Собираем основные данные контрагента
    $client_name  = trim($_POST['client_name'] ?? '');
    $unp          = trim($_POST['unp'] ?? '');
    $website      = trim($_POST['website'] ?? ''); // НАМЕРТВО ИСПРАВЛЕНО: Принимаем сайт
    $product_type = trim($_POST['product_type'] ?? 'Сантехника');
    $status       = trim($_POST['status'] ?? 'Новый');
    $source       = trim($_POST['source'] ?? '');
    $comment      = trim($_POST['comment'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $manager_id   = intval($_SESSION['user_id'] ?? 0);
    
    // ВАЖНО: Забираем массив динамических контактов из FormData
    $contacts = $_POST['contacts'] ?? [];

    if (empty($client_name)) {
        echo json_encode(['status' => 'error', 'message' => 'Название организации обязательно для заполнения']);
        exit;
    }

    try {
        // Стартуем транзакцию, чтобы данные клиента и контактов записывались атомарно
        $pdo->beginTransaction();

        // 2. Записываем базовую карточку компании
        $sqlClient = "INSERT INTO clients (client_name, unp, website, product_type, status, source, comment, email, manager_id, first_contact_date, next_contact_date) 
                      VALUES (:client_name, :unp, :website, :product_type, :status, :source, :comment, :email, :manager_id, NOW(), NOW())";
        
        $stmtClient = $pdo->prepare($sqlClient);
        $stmtClient->execute([
            ':client_name'  => $client_name,
            ':unp'          => $unp,
            ':website'      => $website,
            ':product_type' => $product_type,
            ':status'       => $status,
            ':source'       => $source,
            ':comment'      => $comment,
            ':email'        => $email,
            ':manager_id'   => $manager_id
        ]);

        // Получаем ID только что созданного клиента из базы
        $clientId = $pdo->lastInsertId();

        // 3. ПЕРЕБИРАЕМ И ЗАПИСЫВАЕМ МАССИВ КОНТАКТОВ
        if (!empty($contacts) && is_array($contacts)) {
            $sqlContact = "INSERT INTO client_contacts (client_id, name, position, phone, email, function_notes) 
                           VALUES (:client_id, :name, :position, :phone, :email, :function_notes)";
            $stmtContact = $pdo->prepare($sqlContact);

            foreach ($contacts as $c) {
                $contactName = trim($c['name'] ?? '');
                // Пропускаем пустые карточки, если менеджер случайно нажал кнопку "+ Добавить лицо" лишний раз
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

        // Если дошли сюда без ошибок — фиксируем данные в СУБД
        $pdo->commit();
        
        echo json_encode(['status' => 'success', 'message' => 'Клиент и контактные лица успешно зарегистрированы']);
        exit;

    } catch (Exception $e) {
        // При любом сбое SQL полностью откатываем изменения, чтобы не плодить битые записи
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Критическая ошибка СУБД: ' . $e->getMessage()]);
        exit;
    }
}