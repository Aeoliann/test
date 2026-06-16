<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php'; // Подключаем СУБД через ваш PDO

header('Content-Type: application/json');

$name = trim($_GET['name'] ?? '');
$unp  = trim($_GET['unp'] ?? '');

$exists = false;

if (!empty($name) && !empty($unp)) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE client_name = ? AND unp = ?");
        $stmt->execute([$name, $unp]);
        $exists = $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        // Гасим ошибку и возвращаем false в случае сбоя SQL
    }
}

echo json_encode(['exists' => $exists]);