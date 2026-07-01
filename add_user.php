<?php
session_start();
require 'db.php';
// Замените старое чтение php://input во всех обработчиках на эту строчку:
$data = !empty($_POST) ? $_POST : ($GLOBALS['__JSON_CACHE__'] ?? json_decode(file_get_contents('php://input'), true));

if ($_SESSION['role'] === 'admin' && isset($data['login'])) {
    $stmt = $pdo->prepare("INSERT INTO users (login, password, role) VALUES (?, '12345', 'manager')");
    $stmt->execute([$data['login']]);
    echo json_encode(['status' => 'success']);
}
?>