<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

header('Content-Type: application/json');
if (ob_get_length()) ob_clean();

try {
    $rawInput = json_decode(file_get_contents('php://input'), true) ?: [];
    
    $project_id   = (int)($_POST['project_id'] ?? ($rawInput['project_id'] ?? 0));
    $ttn_number   = trim($_POST['ttn_number'] ?? ($rawInput['ttn_number'] ?? ''));
    $ttn_date     = !empty($_POST['ttn_date']) ? $_POST['ttn_date'] : (!empty($rawInput['ttn_date']) ? $rawInput['ttn_date'] : date('Y-m-d'));
    $amount       = (float)($_POST['amount'] ?? ($rawInput['amount'] ?? 0.00));
    $product_info = trim($_POST['product_info'] ?? ($rawInput['product_info'] ?? ''));
    
    // ИСПРАВЛЕНО: Считываем количество штук отгруженной продукции
    $quantity     = (int)($_POST['product_quantity'] ?? ($rawInput['product_quantity'] ?? 0));

    if ($project_id <= 0 || empty($ttn_number) || $amount <= 0) {
        throw new Exception("Не заполнены обязательные поля: Номер ТТН или Сумма!");
    }

    // ИСПРАВЛЕНО: Добавили product_quantity обратно в SQL-запрос СУБД
    $sql = "INSERT INTO project_ttns (project_id, ttn_number, ttn_date, amount, product_info, product_quantity) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$project_id, $ttn_number, $ttn_date, $amount, $product_info, $quantity]);

    echo json_encode(['status' => 'success']);
    exit;

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}