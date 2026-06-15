<?php
// update_cell.php — Микроконтроллер мгновенного сохранения ячеек сетки CRM
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';
require_once 'logger.php'; // Безопасное подключение логгера

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'Данные пакета потеряны']); 
    exit;
}

$id    = (int)$data['id'];
$field = trim($data['field']);
$role  = $_SESSION['role'] ?? 'manager';

// Если поле currency — пишем строку, иначе — принудительно число!
$value = ($field === 'currency') ? trim($data['value']) : (int)$data['value'];

try {
    // 1. Обновляем целевое поле в клиентах или контрактах
    if ($field === 'currency') {
        $stmt = $pdo->prepare("UPDATE projects SET currency = ? WHERE id = ?");
        $stmt->execute([$value, $id]);
        if (function_exists('logAction')) {
            logAction($pdo, 'UPDATE', 'projects', $id, "Изменена валюта договора ID {$id} на {$value}");
        }
    } else {
        $stmt = $pdo->prepare("UPDATE clients SET $field = ? WHERE id = ?");
        $stmt->execute([$value, $id]);
    }

    // 2. Если галку контракта СНЯЛИ (value = 0) — Полная очистка связанных контрактов и ТТН
    if ($field === 'is_contract_signed' && $value === 0) {
        if ($role === 'admin') {
            // Суперадмин сносит всё безвозвратно
            $pdo->prepare("DELETE FROM project_ttns WHERE project_id IN (SELECT id FROM projects WHERE client_id = ?)")->execute([$id]);
            $pdo->prepare("DELETE FROM projects WHERE client_id = ?")->execute([$id]);
        } else {
            // Менеджер может удалить только пустой черновик без ТТН
            $pdo->prepare("DELETE FROM projects WHERE client_id = ? AND (contract_number = '' OR contract_number IS NULL)")->execute([$id]);
        }
        
        if (function_exists('logAction')) {
            logAction($pdo, 'UPDATE', 'clients', $id, "Аннулирован контракт у клиента ID: {$id}");
        }
    }

    // 3. ИСПРАВЛЕНО НАМЕРТВО: Автоматический скрытый INSERT удален! 
    // Договор будет создаваться исключительно силами модального окна, убирая ложное срабатывание confirm()
    if ($field === 'is_contract_signed' && $value === 1) {
        if (function_exists('logAction')) {
            logAction($pdo, 'UPDATE', 'clients', $id, "Менеджер инициировал подписание договора у клиента ID: {$id}");
        }
    }

    echo json_encode(['status' => 'success']);
    exit;

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
?>
