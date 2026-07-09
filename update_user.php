<?php
session_start();
require 'db.php';
// замените старое чтение php://input во всех обработчиках на эту строчку:
$data = !empty($_post) ? $_post : ($globals['__json_cache__'] ?? json_decode(file_get_contents('php://input'), true));

if ($_SESSION['role'] === 'admin' && isset($data['id'], $data['field'], $data['value'])) {
    $field = $data['field'];
    // Разрешаем только эти поля
    if (in_array($field, ['login', 'password', 'role', 'full_name'])) {
        $stmt = $pdo->prepare("UPDATE users SET $field = ? WHERE id = ?");
        $stmt->execute([$data['value'], $data['id']]);
        echo json_encode(['status' => 'success']);
    }
}
?>