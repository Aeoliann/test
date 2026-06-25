<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Доступ запрещен']);
    exit;
}

require 'db.php'; // Подключение PDO ($pdo)

// Читаем входящий JSON-поток от fetch
$inputData = json_decode(file_get_contents('php://input'), true);

$projectId = isset($inputData['project_id']) ? intval($inputData['project_id']) : 0;
$currency  = isset($inputData['currency']) ? trim($inputData['currency']) : '';
$dateFrom  = isset($inputData['date_from']) ? trim($inputData['date_from']) : '';
$dateTo    = isset($inputData['date_to']) ? trim($inputData['date_to']) : '';

// Базовая валидация параметров
if ($projectId <= 0 || empty($currency)) {
    echo json_encode([
        'status' => 'error', 
        'message' => 'Некорректные параметры запроса (отсутствует ID договора или валюта)'
    ]);
    exit;
}

try {
    // Высокопроизводительный SQL-запрос по индексам таблиц
    $sql = "SELECT 
                t.id,
                t.project_id,
                t.ttn_number,
                t.ttn_date,
                t.product_info,
                t.product_quantity,
                t.amount,
                t.currency
            FROM project_ttns t
            WHERE t.project_id = :project_id 
              AND t.currency = :currency";
              
    // Если в календарях на фронтенде выбраны даты — сужаем выборку ТТН
    $params = [
        ':project_id' => $projectId,
        ':currency'   => $currency
    ];

    if (!empty($dateFrom) && !empty($dateTo)) {
        $sql .= " AND t.ttn_date BETWEEN :date_from AND :date_to";
        $params[':date_from'] = $dateFrom;
        $params[':date_to']   = $dateTo;
    }

    $sql .= " ORDER BY t.ttn_date DESC, t.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ttns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Возвращаем успешный структурированный JSON ответ
    echo json_encode([
        'status' => 'success',
        'ttns'   => $ttns
    ]);

} catch (Exception $e) {
    error_log("Ошибка AJAX детализации ТТН: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Внутренняя ошибка сервера при чтении СУБД'
    ]);
}