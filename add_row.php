<?php
session_start();
require 'db.php';
header('Content-Type: application/json');

$mid = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 1;

try {
    // Вместо 'Новый клиент' записываем пустую строку
    $sql = "INSERT INTO clients (client_name, status, client_type, manager_id) 
            VALUES ('', 'Новый', 'Новый', ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$mid]);
    
    echo json_encode(['status' => 'success', 'id' => $pdo->lastInsertId()]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}