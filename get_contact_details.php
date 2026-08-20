<?php
// get_contract_details.php — возвращает данные договора и его ТТН для карточки
if (session_status() === PHP_SESSION_NONE) session_start();
require 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Не авторизован']);
    exit;
}

$contractId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($contractId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Неверный ID договора']);
    exit;
}

try {
    // Данные договора + клиент
    $sql = "SELECT p.*, c.client_name, u.login AS manager_name
            FROM projects p
            LEFT JOIN clients c ON p.client_id = c.id
            LEFT JOIN users u ON c.manager_id = u.id
            WHERE p.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$contractId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        echo json_encode(['status' => 'error', 'message' => 'Договор не найден']);
        exit;
    }

    // Список ТТН
    $ttns = $pdo->prepare("SELECT id, ttn_number, ttn_date, amount, currency, product_info, product_quantity FROM project_ttns WHERE project_id = ? ORDER BY ttn_date DESC");
    $ttns->execute([$contractId]);
    $contract['ttns'] = $ttns->fetchAll(PDO::FETCH_ASSOC);

    // Общая сумма ТТН
    $total = array_sum(array_column($contract['ttns'], 'amount'));
    $contract['total_ttn_amount'] = $total;

    echo json_encode(['status' => 'success', 'data' => $contract]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}