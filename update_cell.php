<?php
session_start();
require 'db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'Нет данных']); exit;
}

$id = (int)$data['id'];
$field = $data['field'];
$value = (int)$data['value'];
$role = $_SESSION['role'] ?? 'manager';

try {
    // 1. Всегда обновляем саму галку в таблице клиентов
    $stmt = $pdo->prepare("UPDATE clients SET $field = ? WHERE id = ?");
    $stmt->execute([$value, $id]);

    // 2. Если галку СНЯЛИ (value = 0)
    if ($field === 'is_contract_signed' && $value === 0) {
        if ($role === 'admin') {
            // АДМИН: Удаляет всё подчистую
            $pdo->prepare("DELETE FROM project_ttns WHERE project_id IN (SELECT id FROM projects WHERE client_id = ?)")->execute([$id]);
            $pdo->prepare("DELETE FROM projects WHERE client_id = ?")->execute([$id]);
        } else {
            // МЕНЕДЖЕР: Удаляет только пустые черновики (чтобы не плодить мусор)
            $pdo->prepare("DELETE FROM projects WHERE client_id = ? AND (contract_number = '' OR contract_number IS NULL) AND amount = 0")->execute([$id]);
        }
    }

    // 3. Если галку ПОСТАВИЛИ (value = 1)
    if ($field === 'is_contract_signed' && $value === 1) {
        $check = $pdo->prepare("SELECT id FROM projects WHERE client_id = ?");
        $check->execute([$id]);
        if (!$check->fetch()) {
            // Создаем пустой черновик, чтобы клиент появился в contracts.php
            $pdo->prepare("INSERT INTO projects (client_id, contract_number, amount, contract_date) VALUES (?, '', 0, CURDATE())")->execute([$id]);
        }
    }

    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}