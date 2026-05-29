<?php
// save_ttn.php — Сверхнадёжное асинхронное добавление ТТН под Windows XAMPP
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

header('Content-Type: application/json');
if (ob_get_length()) ob_clean();

try {
    // Читаем входящий JSON-пакет от fetch
    $rawInput = json_decode(file_get_contents('php://input'), true) ?: [];
    
    $project_id   = (int)($_POST['project_id'] ?? ($rawInput['project_id'] ?? 0));
    $ttn_number   = trim($_POST['ttn_number'] ?? ($rawInput['ttn_number'] ?? ''));
    $ttn_date     = !empty($_POST['ttn_date']) ? $_POST['ttn_date'] : (!empty($rawInput['ttn_date']) ? $rawInput['ttn_date'] : date('Y-m-d'));
    $amount       = (float)($_POST['amount'] ?? ($rawInput['amount'] ?? 0.00));
    $product_info = trim($_POST['product_info'] ?? ($rawInput['product_info'] ?? ''));

    if ($project_id <= 0 || empty($ttn_number) || $amount <= 0) {
        throw new Exception("Не заполнены обязательные поля: Номер ТТН или Сумма!");
    }

    // БРОНЕБОЙНАЯ ЗАПИСЬ: Пишем только то, что 100% есть в структуре project_ttns
    $sql = "INSERT INTO project_ttns (project_id, ttn_number, ttn_date, amount, product_info) VALUES (?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$project_id, $ttn_number, $ttn_date, $amount, $product_info]);

    echo json_encode(['status' => 'success']);
    exit;

} catch (Exception $e) {
    // Если упала СУБД, возвращаем ошибку в JSON, чтобы JS не падал в "сбой сети"
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
