<?php
// update_cell.php — Микроконтроллер мгновенного сохранения ячеек сетки CRM
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php'; // Здесь находится наше подключение и универсальная функция logAction

header('Content-Type: application/json');

// Замените старое чтение php://input во всех обработчиках на эту строчку:
$data = !empty($_POST) ? $_POST : ($GLOBALS['__JSON_CACHE__'] ?? json_decode(file_get_contents('php://input'), true));
if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'Данные пакета потеряны']); 
    exit;
}

$id    = (int)$data['id'];
$field = trim($data['field']);
$role  = $_SESSION['role'] ?? 'manager';

// Если поле currency или текстовое — пишем строку, иначе — принудительно число!
// Добавили проверку на строку для текстовых полей, чтобы не превращать имена в нули
$isStringField = in_array($field, ['currency', 'client_name', 'status', 'client_type', 'phone', 'comment']);
$value = $isStringField ? trim($data['value']) : (int)$data['value'];

try {
    // 1. Обновляем целевое поле в клиентах или контрактах
    if ($field === 'currency') {
        $stmt = $pdo->prepare("UPDATE projects SET currency = ? WHERE id = ?");
        $stmt->execute([$value, $id]);
        
        // ИСПРАВЛЕНО: Приведено к стандарту из 3-х параметров
        if (function_exists('logAction')) {
            logAction('UPDATE', 'projects', "Изменена валюта договора ID {$id} на {$value}");
        }
    } else {
        $stmt = $pdo->prepare("UPDATE clients SET $field = ? WHERE id = ?");
        $stmt->execute([$value, $id]);
        
        // ВШИВАЕМ ЛОГ ДЛЯ ЛЮБЫХ ДРУГИХ ИЗМЕНЕНИЙ (например, изменение статуса или имени)
        // Исключаем 'is_contract_signed', так как для него ниже отдельные красивые логи
        if (function_exists('logAction') && $field !== 'is_contract_signed') {
            logAction('UPDATE', 'clients', "Отредактирован клиент ID: {$id}. Поле [{$field}] изменено на: '{$value}'");
        }
    }

    // 2. Если галку контракта СНЯЛИ (value = 0) — Полная очистка связанных контрактов и ТТН
    if ($field === 'is_contract_signed' && (int)$value === 0) {
        if ($role === 'admin') {
            // Суперадмин сносит всё безвозвратно
            $pdo->prepare("DELETE FROM project_ttns WHERE project_id IN (SELECT id FROM projects WHERE client_id = ?)")->execute([$id]);
            $pdo->prepare("DELETE FROM projects WHERE client_id = ?")->execute([$id]);
        } else {
            // Менеджер может удалить только пустой черновик без ТТН
            $pdo->prepare("DELETE FROM projects WHERE client_id = ? AND (contract_number = '' OR contract_number IS NULL)")->execute([$id]);
        }
        
        // ИСПРАВЛЕНО: Теперь пишется строго по скриншоту, без лишних аргументов
        if (function_exists('logAction')) {
            logAction('UPDATE', 'clients', "Аннулирован контракт у клиента ID: {$id}");
        }
    }
$website = trim($_POST['website'] ?? '');
    // 3. Если галку контракта ПОСТАВИЛИ (value = 1)
    if ($field === 'is_contract_signed' && (int)$value === 1) {
        // ИСПРАВЛЕНО: Синхронизировано с базой логов
        if (function_exists('logAction')) {
            logAction('UPDATE', 'clients', "Менеджер инициировал подписание договора у клиента ID: {$id}");
        }
    }

    echo json_encode(['status' => 'success']);
    exit;

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
?>