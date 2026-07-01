<?php
session_start(); // Инициализируем сессию, чтобы функция логов знала, какой менеджер делает правку
require 'db.php';
header('Content-Type: application/json');
// замените старое чтение php://input во всех обработчиках на эту строчку:
$data = !empty($_post) ? $_post : ($globals['__json_cache__'] ?? json_decode(file_get_contents('php://input'), true));

if (isset($data['id']) && isset($data['field'])) {
    try {
        $stmt = $pdo->prepare("UPDATE projects SET {$data['field']} = ? WHERE id = ?");
        $stmt->execute([$data['value'], (int)$data['id']]);

        // ВШИВАЕМ ЗАПИСЬ В ЖУРНАЛ АУДИТА:
        if (function_exists('logAction')) {
            $projectId = (int)$data['id'];
            $fieldName = htmlspecialchars($data['field']);
            $newValue  = htmlspecialchars($data['value']);
            
            logAction(
                'UPDATE', 
                'projects', 
                "В договоре ID {$projectId} изменено поле [{$fieldName}] на значение: '{$newValue}'"
            );
        }

        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
exit;
?>