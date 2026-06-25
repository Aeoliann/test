<?php
session_start();
require 'db.php';
header('Content-Type: application/json');

try {
    $cid = (int)$_POST['client_id'];
    $num = trim($_POST['contract_number']);
    $date = $_POST['contract_date'];
    $sum = (float)$_POST['amount'];

    // Проверяем, есть ли уже пустой черновик для этого клиента
    $check = $pdo->prepare("SELECT id FROM projects WHERE client_id = ? AND (contract_number = '' OR contract_number IS NULL) LIMIT 1");
    $check->execute([$cid]);
    $existingId = $check->fetchColumn();

    if ($existingId) {
        // ИСПРАВЛЕНО: Добавлено поле amount = ? в SQL запрос, чтобы не было ошибки несовпадения параметров
        $sql = "UPDATE projects SET contract_number = ?, contract_date = ?, amount = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$num, $date, $sum, $existingId]);

        // ВШИВАЕМ ЗАПИСЬ В ЖУРНАЛ АУДИТА (UPDATE):
        if (function_exists('logAction')) {
            logAction('UPDATE', 'projects', "Заполнен черновик договора № {$num} для клиента ID: {$cid} (Сумма: {$sum})");
        }
    } else {
        // Если это уже второй/третий договор клиента — делаем обычный INSERT
        $sql = "INSERT INTO projects (client_id, contract_number, contract_date, amount) VALUES (?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$cid, $num, $date, $sum]);

        // ВШИВАЕМ ЗАПИСЬ В ЖУРНАЛ АУДИТА (INSERT):
        if (function_exists('logAction')) {
            logAction('INSERT', 'projects', "Создан новый договор № {$num} для клиента ID: {$cid} (Сумма: {$sum})");
        }
    }

    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
exit;
?>