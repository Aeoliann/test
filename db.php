<?php
$host = 'localhost';
$db   = 'crm_db'; // Проверь в phpMyAdmin, что база называется именно так
$user = 'root';
$pass = ''; // ВАЖНО: Тут должно быть пусто, без пробелов

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die(json_encode(['status' => 'error', 'message' => 'БД: ' . $e->getMessage()]));
}
?>