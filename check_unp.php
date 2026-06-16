<?php
// check_unp.php — Асинхронная проверка УНП на дубликаты
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) { throw new Exception("Вход не выполнен"); }

    $unp = trim($_GET['unp'] ?? '');
    if (empty($unp)) {
        echo json_encode(['status' => 'clear', 'message' => 'Пустой УНП']);
        exit;
    }

    // Ищем клиента с таким УНП в СУБД
    $stmt = $pdo->prepare("SELECT id, client_name FROM clients WHERE trim(unp) = ? LIMIT 1");
    $stmt->execute([$unp]);
    $duplicate = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($duplicate) {
        echo json_encode([
            'status' => 'duplicate',
            'client_name' => $duplicate['client_name']
        ]);
    } else {
        echo json_encode(['status' => 'clear']);
    }
    exit;

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}