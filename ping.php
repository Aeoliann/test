<?php
// ping.php — Фоновый микро-контроллер статуса Онлайн
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

header('Content-Type: application/json');

if (isset($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
    try {
        // Просто обновляем штамп времени активности
        $pdo->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?")->execute([$userId]);
        echo json_encode(['status' => 'alive']);
        exit;
    } catch (Exception $e) {
        // Мягкий выход при сбое БД
    }
}

echo json_encode(['status' => 'guest']);
exit;
?>