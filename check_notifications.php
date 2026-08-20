<?php
// check_notifications.php — возвращает количество непрочитанных уведомлений для текущего пользователя
if (session_status() === PHP_SESSION_NONE) session_start();
require 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['count' => 0, 'notifications' => []]);
    exit;
}

$userId = (int)$_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("SELECT id, message, link, created_at FROM notifications WHERE user_id = ? AND is_read = 0 AND type = 'task' ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['count' => count($notifications), 'notifications' => $notifications]);
} catch (Exception $e) {
    echo json_encode(['count' => 0, 'notifications' => []]);
}