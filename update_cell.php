<?php
// update_cell.php — Микроконтроллер мгновенного сохранения ячеек сетки CRM
session_start();
require 'db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'Данные пакета потеряны']); exit;
}

$id    = (int)$data['id'];
$field = $data['field'];
$role  = $_SESSION['role'] ?? 'manager';

// НАШ ВАЛЮТНЫЙ ФИКС: Если поле currency — пишем строку, иначе — принудительно число!
$value = ($field === 'currency') ? trim($data['value']) : (int)$data['value'];

try {
    // 1. Обновляем целевое поле в клиентах или контрактах
    if ($field === 'currency') {
        $stmt = $pdo->prepare("UPDATE projects SET currency = ? WHERE id = ?");
        $stmt->execute([$value, $id]);
        logAction($pdo, 'UPDATE', 'projects', "Изменена валюта договора ID {$id} на {$value}");
    } else {
        $stmt = $pdo->prepare("UPDATE clients SET $field = ? WHERE id = ?");
        $stmt->execute([$value, $id]);
    }

    // 2. Если галку контракта СНЯЛИ (value = 0)
    if ($field === 'is_contract_signed' && $value === 0) {
        if ($role === 'admin') {
            $pdo->prepare("DELETE FROM project_ttns WHERE project_id IN (SELECT id FROM projects WHERE client_id = ?)")->execute([$id]);
            $pdo->prepare("DELETE FROM projects WHERE client_id = ?")->execute([$id]);
        } else {
            $pdo->prepare("DELETE FROM projects WHERE client_id = ? AND (contract_number = '' OR contract_number IS NULL)")->execute([$id]);
        }
        logAction($pdo, 'UPDATE', 'clients', "Аннулирован контракт у клиента ID: {$id}");
    }

    // 3. Если галку контракта ПОСТАВИЛИ (value = 1)
    if ($field === 'is_contract_signed' && $value === 1) {
        $check = $pdo->prepare("SELECT id FROM projects WHERE client_id = ?");
        $check->execute([$id]);
        if (!$check->fetch()) {
            $pdo->prepare("INSERT INTO projects (client_id, contract_number, contract_date, currency) VALUES (?, '', CURDATE(), 'BYN')")->execute([$id]);
        }
        logAction($pdo, 'UPDATE', 'clients', "Активирован контракт у клиента ID: {$id}");
    }

    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>