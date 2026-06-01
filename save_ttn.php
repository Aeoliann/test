<?php
// save_ttn.php — Безопасная асинхронная запись ТТН с фиксацией штук под Windows XAMPP
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

header('Content-Type: application/json');
if (ob_get_length()) ob_clean();

try {
    // Считываем JSON-поток от fetch
    $rawInput = json_decode(file_get_contents('php://input'), true) ?: [];
    
    $project_id   = (int)($_POST['project_id'] ?? ($rawInput['project_id'] ?? 0));
    $ttn_number   = trim($_POST['ttn_number'] ?? ($rawInput['ttn_number'] ?? ''));
    $ttn_date     = !empty($_POST['ttn_date']) ? $_POST['ttn_date'] : (!empty($rawInput['ttn_date']) ? $rawInput['ttn_date'] : date('Y-m-d'));
    $amount       = (float)($_POST['amount'] ?? ($rawInput['amount'] ?? 0.00));
    $product_info = trim($_POST['product_info'] ?? ($rawInput['product_info'] ?? ''));
    
    // ИСПРАВЛЕНО: Принудительно вытягиваем количество штук из пакета данных
    $product_quantity = (int)($_POST['product_quantity'] ?? ($rawInput['product_quantity'] ?? 0));

    if ($project_id <= 0 || empty($ttn_number) || $amount <= 0) {
        throw new Exception("Заполните обязательные поля: Номер ТТН и Сумму!");
    }

    // ЖЕСТКАЯ ЗАПИСЬ: Фиксируем количество штук (product_quantity) в базе Santeks
    $sql = "INSERT INTO project_ttns (project_id, ttn_number, ttn_date, amount, product_info, product_quantity) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$project_id, $ttn_number, $ttn_date, $amount, $product_info, $product_quantity]);

    echo json_encode(['status' => 'success']);
    exit;

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
