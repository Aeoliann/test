<?php
session_start(); // Инициализируем сессию, чтобы функция логов знала, какой менеджер делает правку
require 'db.php';
header('Content-Type: application/json');
// замените старое чтение php://input во всех обработчиках на эту строчку:
$data = !empty($_post) ? $_post : ($globals['__json_cache__'] ?? json_decode(file_get_contents('php://input'), true));

if (isset($data['id']) && isset($data['field'])) {
    try {


       // ИСПРАВЛЕНО: Белый список разрешенных полей
$allowedFields = ['contract_number', 'contract_date', 'product_type', 'currency', 'amount', 'scan_path'];

if (isset($data['id']) && isset($data['field']) && in_array($data['field'], $allowedFields)) {
    try {
        // Используем плейсхолдеры для имени поля через динамический запрос
        $sql = "UPDATE projects SET {$data['field']} = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$data['value'], (int)$data['id']]);
        
        if (function_exists('logAction')) {
            logAction('UPDATE', 'projects', "В договоре ID {$data['id']} изменено поле [{$data['field']}]");
        }
        
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Недоступное поле для обновления']);
}
exit;
?>