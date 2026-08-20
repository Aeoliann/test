<?php
// create_draft.php — создаёт черновик договора с продукцией из клиента
if (session_status() === PHP_SESSION_NONE) session_start();
require 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Не авторизован']);
    exit;
}

$clientId = (int)($_POST['client_id'] ?? 0);
if ($clientId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Неверный ID клиента']);
    exit;
}

try {
    // Проверяем, есть ли уже черновик
    $check = $pdo->prepare("
        SELECT id 
        FROM projects 
        WHERE client_id = ? 
          AND (contract_number IS NULL 
               OR contract_number = '' 
               OR contract_number = 'Б/Н' 
               OR contract_number = 'Пустой черновик' 
               OR contract_number = 'Черновик')
        LIMIT 1
    ");
    $check->execute([$clientId]);
    if ($check->fetchColumn()) {
        echo json_encode(['status' => 'success', 'message' => 'Черновик уже существует']);
        exit;
    }

    // ✅ Получаем продукцию из карточки клиента
    $prodStmt = $pdo->prepare("SELECT product_type FROM clients WHERE id = ?");
    $prodStmt->execute([$clientId]);
    $productType = $prodStmt->fetchColumn();
    if ($productType === null || $productType === '') {
        $productType = ''; // будет подставлено из клиента при выводе
    }

    // Создаём черновик
    $stmt = $pdo->prepare("
        INSERT INTO projects (client_id, contract_number, contract_date, product_type, currency) 
        VALUES (?, 'Б/Н', CURDATE(), ?, 'BYN')
    ");
    $stmt->execute([$clientId, $productType]);

    if (function_exists('logAction')) {
        logAction('INSERT', 'projects', "Создан черновик через create_draft.php для клиента ID: {$clientId} с продукцией: {$productType}");
    }

    echo json_encode(['status' => 'success', 'message' => 'Черновик создан']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}