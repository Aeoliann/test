<?php
session_start();
require 'db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$id = (int)$data['id'];

if ($id > 0) {
    $sql = "UPDATE clients SET 
            client_name = ?, first_contact_date = ?, source = ?, 
            phone = ?, email = ?, product_type = ?, 
            next_contact_date = ?, status = ?, comment = ?
            WHERE id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $data['client_name'], $data['first_contact_date'], $data['source'],
        $data['phone'], $data['email'], $data['product_type'],
        $data['next_contact_date'], $data['status'], $data['comment'], $id
    ]);
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'ID не передан']);
}
exit;