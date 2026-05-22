<?php
require 'db.php';
header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['id']) && isset($data['field'])) {
    try {
        $stmt = $pdo->prepare("UPDATE projects SET {$data['field']} = ? WHERE id = ?");
        $stmt->execute([$data['value'], (int)$data['id']]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
exit;
?>