<?php
// save_ttn.php — Всеядный бэкенд записи ТТН с защитой от рассинхронизации ключей JS
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';
require_once 'logger.php'; // Подключаем логгер для записи действий в СУБД

header('Content-Type: application/json');
if (ob_get_length()) ob_clean();

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Доступ запрещен. Авторизуйтесь.");
    }

    // ЛОВИМ PROJECT_ID (Проверяем все возможные имена ключей из JS)
    $project_id = (int)($_POST['project_id'] ?? ($_POST['pid'] ?? 0));
    
    // ЛОВИМ НОМЕР ТТН (Проверяем ttn_number, number, num)
    $ttn_number = trim($_POST['ttn_number'] ?? ($_POST['number'] ?? ($_POST['num'] ?? '')));
    
    // ЛОВИМ ДАТУ
    $ttn_date = !empty($_POST['ttn_date']) ? trim($_POST['ttn_date']) : (!empty($_POST['date']) ? trim($_POST['date']) : date('Y-m-d'));
    
    // ЛОВИМ СУММУ (Проверяем amount, amt, sum)
    $amount = (float)($_POST['amount'] ?? ($_POST['amt'] ?? ($_POST['sum'] ?? 0.00)));
    
    // ---- НАШ ВАЛЮТНЫЙ ХОТФИКС БЭКЕНДА ----
    $currency = trim($_POST['currency'] ?? 'BYN');
    // --------------------------------------
    
    // ЛОВИМ КОЛИЧЕСТВО ШТУК
    $product_quantity = (int)($_POST['product_quantity'] ?? ($_POST['qty'] ?? 0));
    
    // ЛОВИМ СПЕЦИФИКАЦИЮ
    $product_info = trim($_POST['product_info'] ?? ($_POST['prod'] ?? ($_POST['product_type'] ?? 'Прочее')));

    // ОТЛАДОЧНЫЙ ЛОГ ДЛЯ ТЕБЯ В VS CODE
    if ($project_id <= 0) {
        throw new Exception("Ошибка: Системный ID договора (project_id) равен 0 или не передан!");
    }
    if (empty($ttn_number)) {
        throw new Exception("Ошибка: Номер ТТН пуст или не долетел до сервера!");
    }
    if ($amount <= 0) {
        throw new Exception("Ошибка: Сумма отгрузки должна быть больше 0! Передано: " . ($_POST['amount'] ?? 0));
    }

    // Проверяем, это создание новой ТТН или редактирование старой (если прилетел ttn_id)
    $ttn_id = (int)($_POST['ttn_id'] ?? 0);

    $pdo->beginTransaction();

    if ($ttn_id > 0) {
        // РЕЖИМ РЕДАКТИРОВАНИЯ (Добавили обновление валюты)
        $sql = "UPDATE project_ttns 
                SET ttn_number = ?, ttn_date = ?, amount = ?, currency = ?, product_info = ?, product_quantity = ? 
                WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$ttn_number, $ttn_date, $amount, $currency, $product_info, $product_quantity, $ttn_id]);
        
        // Пишем лог изменения (5 параметров)
        if (function_exists('logAction')) {
            logAction($pdo, 'UPDATE', 'project_ttns', $ttn_id, "Изменена ТТН №{$ttn_number}: сумма {$amount} {$currency}, кол-во {$product_quantity} шт.");
        }
    } else {
        // РЕЖИМ СОЗДАНИЯ С НУЛЯ (Добавили запись валюты)
        $sql = "INSERT INTO project_ttns (project_id, ttn_number, ttn_date, amount, currency, product_info, product_quantity) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$project_id, $ttn_number, $ttn_date, $amount, $currency, $product_info, $product_quantity]);
        $new_ttn_id = $pdo->lastInsertId();
        
        // Пишем лог добавления (5 параметров)
        if (function_exists('logAction')) {
            logAction($pdo, 'INSERT', 'project_ttns', $new_ttn_id, "Добавлена новая ТТН №{$ttn_number} по проекту ID {$project_id}: сумма {$amount} {$currency}");
        }
    }

    $pdo->commit();

    // Возвращаем идеальный статус успеха
    echo json_encode(['status' => 'success']);
    exit;

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Если бэкенд забракует поля, он честно напишет КАКОЕ ИМЕННО поле оказалось пустым!
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
