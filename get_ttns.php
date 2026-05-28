<?php
session_start();
require 'db.php';
header('Content-Type: application/json');

// Проверяем просто наличие авторизации (и админ, и менеджер имеют право читать ТТН)
if (!isset($_SESSION['user_id'])) {
    echo json_encode([]); 
    exit;
}

$pid = isset($_GET['pid']) ? (int)$_GET['pid'] : 0;

$stmt = $pdo->prepare("SELECT * FROM project_ttns WHERE project_id = ? ORDER BY id DESC");
$stmt->execute([$pid]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
exit;
?>