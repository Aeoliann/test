<?php
// mark_notification_read.php — отмечает уведомление как прочитанное
if (session_status() === PHP_SESSION_NONE) session_start();
require 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Не авторизован']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$notifId = (int)($data['id'] ?? 0);
if ($notifId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Неверный ID уведомления']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$notifId, $_SESSION['user_id']]);
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}