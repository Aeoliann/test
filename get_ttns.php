<?php
session_start();
require 'db.php';
header('Content-Type: application/json');

// Проверяем просто наличие авторизации (и админ, и менеджер имеют право читать ТТН)
if (!isset($_SESSION['user_id'])) {
    echo json_encode([]); 
    exit;
}

$pid = (int)($_GET['pid'] ?? 0);
$stmt = $pdo->prepare("SELECT id, ttn_number, ttn_date, amount, product_info, product_quantity, scan_path FROM project_ttns WHERE project_id = ? ORDER BY id DESC");
$stmt->execute([$pid]);
$ttns = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($ttns ?: []);
exit;
?>