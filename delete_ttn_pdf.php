<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Не авторизован']);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Отладка: запишем в лог
error_log("delete_ttn_pdf input: " . $input);
error_log("delete_ttn_pdf data: " . print_r($data, true));

$ttn_id = (int)($data['ttn_id'] ?? 0);
if ($ttn_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Критическая ошибка: Не передан системный ID накладной! Получено: ' . $input]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT scan_path FROM project_ttns WHERE id = ?");
    $stmt->execute([$ttn_id]);
    $filePath = $stmt->fetchColumn();
    if ($filePath && file_exists($filePath)) {
        unlink($filePath);
    }

    $pdo->prepare("UPDATE project_ttns SET scan_path = NULL WHERE id = ?")->execute([$ttn_id]);
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}