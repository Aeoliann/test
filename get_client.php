<?php
session_start();
require 'db.php';

header('Content-Type: application/json');
if (ob_get_length()) ob_clean(); // Очищаем случайные пробелы

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Ошибка авторизации сессии.");
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        throw new Exception("Некорректный ID клиента.");
    }

    // Достаем чистую строку из базы данных
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        throw new Exception("Клиент не найден в базе данных.");
    }

    // Возвращаем клиенту массив данных
    echo json_encode(['status' => 'success', 'data' => $client]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
exit;
?>