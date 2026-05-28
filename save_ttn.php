<?php
// save_ttn.php — Полностью исправленный обработчик (Без дублирования)
session_start();
require 'db.php';
require 'logger.php';

header('Content-Type: application/json');

// Очищаем случайный буфер вывода (убираем скрытые варнинги PHP)
if (ob_get_length()) ob_clean();

try {
    // Получаем входящий JSON поток от JavaScript
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data) {
        throw new Exception("Не удалось прочитать входящие данные формы");
    }

    $ttnId = isset($data['ttn_id']) ? (int)$data['ttn_id'] : 0;
  $pid = 0;
    if (isset($data['project_id'])) {
        $pid = (int)$data['project_id'];
    } elseif (isset($data['pid'])) {
        $pid = (int)$data['pid'];
    } elseif (isset($_POST['project_id'])) {
        $pid = (int)$_POST['project_id'];
    } elseif (isset($_POST['pid'])) {
        $pid = (int)$_POST['pid'];
    }
    $num   = isset($data['ttn_number']) ? trim($data['ttn_number']) : '';
    $date  = isset($data['ttn_date']) ? trim($data['ttn_date']) : '';
    $amt   = isset($data['amount']) ? (float)$data['amount'] : 0.00;
    $prod  = isset($data['product_info']) ? trim($data['product_info']) : '';
    $qty   = isset($data['product_quantity']) ? (int)$data['product_quantity'] : 0;

    // ВАЛИДАЦИЯ ПЕРЕМЕННЫХ
    if (empty($num)) {
        throw new Exception("Номер ТТН обязателен для заполнения!");
    }
    if (empty($date)) {
        $date = date('Y-m-d');
    }

    if ($ttnId > 0) {
        // ==========================================================
        // РЕЖИМ РЕДАКТИРОВАНИЯ (UPDATE) — ВЫПОЛНЯЕТСЯ СТРОГО 1 РАЗ
        // ==========================================================
        $sql = "UPDATE project_ttns SET ttn_number = ?, ttn_date = ?, amount = ?, product_quantity = ?, product_info = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$num, $date, $amt, $qty, $prod, $ttnId]);

        logAction($pdo, 'UPDATE', 'project_ttns', $ttnId, "Отредактировал ТТН №{$num}. Новая сумма: {$amt} BYN, кол-во: {$qty} шт.");
    } else {
        // ==========================================================
        // РЕЖИМ ДОБАВЛЕНИЯ (INSERT) — ВЫПОЛНЯЕТСЯ СТРОГО 1 РАЗ
        // ==========================================================
        if ($pid <= 0) {
            throw new Exception("Критическая ошибка: Пустой или некорректный ID контракта!");
        }
        
        $sql = "INSERT INTO project_ttns (project_id, ttn_number, ttn_date, amount, product_quantity, product_info) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$pid, $num, $date, $amt, $qty, $prod]);
        
        $newTtnId = $pdo->lastInsertId();
        logAction($pdo, 'INSERT', 'project_ttns', $newTtnId, "Добавил новую ТТН №{$num} на сумму {$amt} BYN, кол-во: {$qty} шт.");
    }

    // Возвращаем чистый JSON об успехе
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    // Выводим точную техническую причину сбоя в JS-alert
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
exit;
?>