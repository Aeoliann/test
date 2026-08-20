<?php
// update_inline_date.php — Выделенный изолированный микро-контроллер сохранения дат
require 'db.php'; // Подключаем твое боевое PDO соединение

header('Content-Type: application/json');

// Перехватываем входящий JSON-поток от календаря таблицы
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'Пакет данных пуст']);
    exit;
}

$clientId = (int)($data['id'] ?? 0);
$nextDate = trim($data['value'] ?? '');

// ЖЕСТКАЯ ВАЛИДАЦИЯ: Проверяем, что ID корректный и дата прилетела не пустой
if ($clientId <= 0 || empty($nextDate) || $nextDate === '0000-00-00') {
    echo json_encode(['status' => 'error', 'message' => 'Невалидный ID клиента или формат даты']);
    exit;
}

try {
    // НАМЕРТВО И СТРОГО КАК СТРОКУ: Обновляем next_contact_date в СУБД clients
    $sql = "UPDATE clients SET next_contact_date = ? WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$nextDate, $clientId]);

    // Пишем лог действия, если функция существует в системе
    if (function_exists('logAction')) {
        logAction('UPDATE', 'clients', "Инлайн-календарь: Изменена дата следующего контакта у ID {$clientId} на {$nextDate}");
    }

    // Возвращаем идеальный чистый JSON успеха, в котором физически некому падать
    echo json_encode(['status' => 'success', 'saved_date' => $nextDate]);
    exit;

} catch (Exception $e) {
    // Если произойдет чудо-сбой, скрипт не упадет, а честно вернет ошибку текстом
    echo json_encode(['status' => 'error', 'message' => 'Ошибка базы данных: ' . $e->getMessage()]);
    exit;
}
?>  