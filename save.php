<?php
// save.php — Сверхзащищенный автономный обработчик сохранения клиентов
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

header('Content-Type: application/json');

// НАМЕРТВО ОЧИЩАЕМ БУФЕР (Убирает любые скрытые варнинги PHP, ломающие JSON)
if (ob_get_length()) ob_clean();

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Ошибка сессии. Пожалуйста, перезайдите в систему.");
    }

    // 1. Принимаем чистые данные по атрибутам name полей формы модалки
    $id             = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $client_name    = isset($_POST['client_name']) ? trim($_POST['client_name']) : '';
    $unp            = isset($_POST['unp']) ? trim($_POST['unp']) : '';
    $contact_person = isset($_POST['contact_person']) ? trim($_POST['contact_person']) : '';
    $phone          = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $email          = isset($_POST['email']) ? trim($_POST['email']) : '';
    $product_type   = isset($_POST['product_type']) ? trim($_POST['product_type']) : '';
    $next_date      = isset($_POST['next_contact_date']) ? trim($_POST['next_contact_date']) : '';
    $status         = isset($_POST['status']) ? trim($_POST['status']) : 'Новый';
    $source         = isset($_POST['source']) ? trim($_POST['source']) : 'Холодный поиск';
    $comment        = isset($_POST['comment']) ? trim($_POST['comment']) : '';

    if (empty($client_name) || empty($unp)) {
        throw new Exception("Название организации и УНП обязательны для заполнения!");
    }

    // Автоматическая дата первого контакта для новых записей
    $first_date = isset($_POST['first_contact_date']) ? trim($_POST['first_contact_date']) : '';
    if (empty($first_date)) {
        $first_date = date('Y-m-d');
    }

    // ==========================================================
    // ЖЕСТКАЯ ЗАЩИТА ОТ ДУБЛИКАТОВ (ЗАДАЧА №30)
    // ==========================================================
    if ($id === 0 && !empty($unp) && !empty($product_type)) {
        $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE unp = ? AND product_type = ?");
        $dupStmt->execute([$unp, $product_type]);
        if ($dupStmt->fetchColumn() > 0) {
            throw new Exception("Контрагент с УНП {$unp} уже заведен в систему по направлению «{$product_type}»!");
        }
    }

    // ==========================================================
    // УПРАВЛЕНИЕ РОЛЯМИ И ПЕРЕДАЧЕЙ КЛИЕНТОВ (ЗАДАЧА №33)
    // ==========================================================
    // Проверяем: если зашел админ и передает клиента через наш новый селект — берем переданный ID
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin' && isset($_POST['change_manager_id'])) {
        $assigned_manager_id = (int)$_POST['change_manager_id'];
    } else {
        // Во всех остальных случаях владельцем остается текущий авторизованный менеджер
        $assigned_manager_id = (int)$_SESSION['user_id'];
    }

    // 2. СТРОИМ МАССИВ ПАРАМЕТРОВ ДЛЯ PDO СТРОГО ПО КОЛОНКАМ БАЗЫ ДАННЫХ
    $paramData = [
        ':client_name'    => $client_name,
        ':unp'            => $unp,
        ':contact_person' => $contact_person,
        ':phone'          => $phone,
        ':email'          => $email,
        ':product_type'   => $product_type,
        ':next_date'      => $next_date,
        ':status'         => $status,
        ':source'         => $source,
        ':comment'        => $comment
    ];

    if ($id > 0) {
        // ==========================================
        // РЕЖИМ РЕДАКТИРОВАНИЯ (UPDATE)
        // ==========================================
        
        // Автоподбор названия колонки менеджера (manager_id / user_id)
        // Скрипт сам проверит, какая колонка создана у тебя в phpMyAdmin, предотвращая Fatal Error
        $managerColumnName = 'manager_id';
        try { $pdo->query("SELECT manager_id FROM clients LIMIT 1"); } 
        catch (Exception $e) { $managerColumnName = 'user_id'; }

        $sql = "UPDATE clients SET 
                    client_name = :client_name, 
                    unp = :unp, 
                    contact_person = :contact_person, 
                    phone = :phone, 
                    email = :email, 
                    product_type = :product_type, 
                    next_contact_date = :next_date, 
                    status = :status, 
                    source = :source, 
                    comment = :comment,
                    $managerColumnName = :manager_id 
                WHERE id = :id";
        
        $paramData[':id'] = $id;
        $paramData[':manager_id'] = $assigned_manager_id;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($paramData);

        // Встроенный логгер для безопасности
        try {
            $logSql = "INSERT INTO action_logs (user_id, action_type, table_name, row_id, details, action_date) VALUES (?, 'UPDATE', 'clients', ?, ?, NOW())";
            $pdo->prepare($logSql)->execute([$_SESSION['user_id'], $id, "Обновил карточку клиента «{$client_name}»"]);
        } catch(Exception $le) {}

    } else {
        // ==========================================
        // РЕЖИМ ДОБАВЛЕНИЯ (INSERT ДЛЯ НОВЫХ ФИРМ)
        // ==========================================
        $paramData[':first_date'] = $first_date;
        $paramData[':manager_id'] = $assigned_manager_id;
        
        // Автоподбор колонки для INSERT
        $managerColumnName = 'manager_id';
        try { $pdo->query("SELECT manager_id FROM clients LIMIT 1"); } 
        catch (Exception $e) { $managerColumnName = 'user_id'; }

        $sql = "INSERT INTO clients 
                    (client_name, unp, contact_person, phone, email, ct_type, first_contact_date, next_contact_date, status, source, comment, $managerColumnName) 
                VALUES 
                    (:client_name, :unp, :contact_person, :phone, :email, :product_type, :first_date, :next_date, :status, :source, :comment, :manager_id)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($paramData);
        
        $newClientId = $pdo->lastInsertId();
        
        try {
            $logSql = "INSERT INTO action_logs (user_id, action_type, table_name, row_id, details, action_date) VALUES (?, 'INSERT', 'clients', ?, ?, NOW())";
            $pdo->prepare($logSql)->execute([$_SESSION['user_id'], $newClientId, "Добавил нового клиента «{$client_name}»"]);
        } catch(Exception $le) {}
    }

    // Возвращаем чистый валидный JSON статус об успехе
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    // ЕСЛИ ПРОИЗОШЕЛ СБОЙ SQL — МЫ ОТДАДИМ ЕГО ТЕКСТ В JSON БЕЗ ТЕГОВ <
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
exit;
