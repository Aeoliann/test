<?php
// get_client.php — Абсолютно всеядный API-обработчик выгрузки данных карточки клиента для CRM
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

header('Content-Type: application/json');
if (ob_get_length()) ob_clean(); // Очищаем случайные пробелы, ломающие JSON-парсинг

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Ошибка авторизации сессии. Пожалуйста, перезапустите страницу.");
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        throw new Exception("Некорректный системный ID клиента.");
    }

    // 1. Достаем чистую строку из базы данных по системному идентификатору
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        throw new Exception("Критическая ошибка: Запрашиваемый клиент не найден в базе данных.");
    }

    // 2. ИНТЕЛЛЕКТУАЛЬНЫЙ СУБД-МАРШРУТИЗАТОР ДАТЫ (ФИКС БАГА 104 НА СТОРОНЕ ЯДРА)
    // Сканируем массив $client на предмет скрытых альтернативных имен полей даты следующего контакта
    if (!isset($client['next_contact_date'])) {
        if (isset($client['next_date'])) {
            $client['next_contact_date'] = $client['next_date'];
        } elseif (isset($client['date_next'])) {
            $client['next_contact_date'] = $client['date_next'];
        } elseif (isset($client['contact_next'])) {
            $client['next_contact_date'] = $client['contact_next'];
        } else {
            // Если поле в структуре вообще отсутствует, создаем дефолтный пустой маркер
            $client['next_contact_date'] = '';
        }
    }

    // 3. Возвращаем фронтенду кристально чистый, стандартизированный массив данных
    echo json_encode(['status' => 'success', 'data' => $client]);
    exit;

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
