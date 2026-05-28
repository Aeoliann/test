<?php
session_start();
require 'db.php';
$data = json_decode(file_get_contents('php://input'), true);

if ($_SESSION['role'] === 'admin' && isset($data['login'])) {
    $stmt = $pdo->prepare("INSERT INTO users (login, password, role) VALUES (?, '12345', 'manager')");
    $stmt->execute([$data['login']]);
    echo json_encode(['status' => 'success']);
}
?>