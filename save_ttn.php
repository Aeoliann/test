<?php
// save_ttn.php — Всеядный бэкенд записи ТТН с защитой от рассинхронизации ключей JS
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

    // ЛОВИМ PROJECT_ID (Проверяем все возможные имена ключей из JS)
    $project_id = (int)($_POST['project_id'] ?? ($_POST['pid'] ?? 0));
    
    // ЛОВИМ НОМЕР ТТН (Проверяем ttn_number, number, num)
    $ttn_number = trim($_POST['ttn_number'] ?? ($_POST['number'] ?? ($_POST['num'] ?? '')));
    
    // ЛОВИМ ДАТУ
    $ttn_date = !empty($_POST['ttn_date']) ? trim($_POST['ttn_date']) : (!empty($_POST['date']) ? trim($_POST['date']) : date('Y-m-d'));
    
    // ЛОВИМ СУММУ (Проверяем amount, amt, sum)
    $amount = (float)($_POST['amount'] ?? ($_POST['amt'] ?? ($_POST['sum'] ?? 0.00)));
    
    // ЛОВИМ КОЛИЧЕСТВО ШТУК
    $product_quantity = (int)($_POST['product_quantity'] ?? ($_POST['qty'] ?? 0));
    
    // ЛОВИМ СПЕЦИФИКАЦИЮ
    $product_info = trim($_POST['product_info'] ?? ($_POST['prod'] ?? ($_POST['product_type'] ?? 'Прочее')));

    // ОТЛАДОЧНЫЙ ЛОГ ДЛЯ ТЕБЯ В VS CODE (Если что-то пустое, мы сразу увидим в Exception)
    if ($project_id <= 0) {
        throw new Exception("Ошибка: Системный ID договора (project_id) равен 0 или не передан!");
    }
    if (empty($ttn_number)) {
        throw new Exception("Ошибка: Номер ТТН пуст или не долетел до сервера!");
    }
    if ($amount <= 0) {
        throw new Exception("Ошибка: Сумма отгрузки должна быть больше 0! Передано: " . $_POST['amount']);
    }

    // Проверяем, это создание новой ТТН или редактирование старой (если прилетел ttn_id)
    $ttn_id = (int)($_POST['ttn_id'] ?? 0);

    if ($ttn_id > 0) {
        // РЕЖИМ РЕДАКТИРОВАНИЯ
        $sql = "UPDATE project_ttns 
                SET ttn_number = ?, ttn_date = ?, amount = ?, product_info = ?, product_quantity = ? 
                WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$ttn_number, $ttn_date, $amount, $product_info, $product_quantity, $ttn_id]);
    } else {
        // РЕЖИМ СОЗДАНИЯ С НУЛЯ
        $sql = "INSERT INTO project_ttns (project_id, ttn_number, ttn_date, amount, product_info, product_quantity) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$project_id, $ttn_number, $ttn_date, $amount, $product_info, $product_quantity]);
    }

    // Возвращаем идеальный статус успеха
    echo json_encode(['status' => 'success']);
    exit;

} catch (Exception $e) {
    // Если бэкенд забракует поля, он честно напишет КАКОЕ ИМЕННО поле оказалось пустым!
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
