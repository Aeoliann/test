<?php
session_start();
require 'db.php';

$data = json_decode(file_get_contents('php://input'), true);
$login = $data['login'] ?? '';
$pass = $data['password'] ?? '';

$stmt = $pdo->prepare("SELECT * FROM users WHERE login = ?");
$stmt->execute([$login]);
$user = $stmt->fetch();

// Теперь мы сравниваем просто текст из базы с текстом из формы
if ($user && $pass === $user['password']) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Неверный логин или пароль']);
}
?>