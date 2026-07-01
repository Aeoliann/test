<?php
// save_ttn.php — Автоматическая привязка ТТН к выбранной руками валюте
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php'; // Здесь находится наше подключение и универсальная функция logAction

header('Content-Type: application/json');
if (ob_get_length()) ob_clean();

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Доступ запрещен. Авторизуйтесь.");
    }

    $project_id = (int)($_POST['project_id'] ?? 0);
    $ttn_id     = (int)($_POST['ttn_id'] ?? 0);
    $ttn_number = trim($_POST['ttn_number'] ?? '');
    $ttn_date   = !empty($_POST['ttn_date']) ? trim($_POST['ttn_date']) : date('Y-m-d');
    $amount     = (float)($_POST['new_ttn_amount'] ?? 0.00);
    $qty        = (int)($_POST['product_quantity'] ?? 0);
    $prod_info  = trim($_POST['product_info'] ?? 'Сантехника');

    if (empty($ttn_number)) {
        throw new Exception("Не указан номер накладной ТТН.");
    }
    if ($project_id <= 0 && $ttn_id <= 0) {
        throw new Exception("Некорректный системный ID договора.");
    }

    // ВСЕЯДНЫЙ ВАЛЮТНЫЙ ПЕРЕХВАТЧИК: Проверяем абсолютно все ключи, которые мог прислать JS!
    $currency = strtoupper(trim($_POST['ttn_currency_select'] ?? ($_POST['currency'] ?? ($_POST['ttn_currency'] ?? ''))));

    // Если фронтенд вообще ничего не прислал, подстраховываемся валютой самого договора из базы
    if (empty($currency)) {
        if ($project_id > 0) {
            $getCur = $pdo->prepare("SELECT currency FROM projects WHERE id = ?");
            $getCur->execute([$project_id]);
            $currency = $getCur->fetchColumn() ?: 'BYN';
        } else {
            $getCur = $pdo->prepare("SELECT p.currency FROM projects p JOIN project_ttns t ON p.id = t.project_id WHERE t.id = ?");
            $getCur->execute([$ttn_id]);
            $currency = $getCur->fetchColumn() ?: 'BYN';
        }
    }

    $pdo->beginTransaction();

    if ($ttn_id > 0) {
        // Режим редактирования существующей накладной
        $sql = "UPDATE project_ttns SET ttn_number = ?, ttn_date = ?, amount = ?, currency = ?, product_info = ?, product_quantity = ? WHERE id = ?";
        $pdo->prepare($sql)->execute([$ttn_number, $ttn_date, $amount, $currency, $prod_info, $qty, $ttn_id]);
        
        // ИСПРАВЛЕНО: Привели вызов функции к единому 3-параметровому стандарту CRM
        if (function_exists('logAction')) {
            logAction('UPDATE', 'project_ttns', "Изменена ТТН №{$ttn_number} (ID ТТН: {$ttn_id}): сумма {$amount} {$currency}");
        }
    } else {
        // Режим создания новой накладной с нуля
        $sql = "INSERT INTO project_ttns (project_id, ttn_number, ttn_date, amount, currency, product_info, product_quantity) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $pdo->prepare($sql)->execute([$project_id, $ttn_number, $ttn_date, $amount, $currency, $prod_info, $qty]);
        $new_ttn_id = $pdo->lastInsertId();
        
        // ИСПРАВЛЕНО: Убрали лишние параметры, мешавшие корректному INSERT запросу логов
        if (function_exists('logAction')) {
            logAction('INSERT', 'project_ttns', "Добавлена ТТН №{$ttn_number} по договору ID {$project_id} (ID ТТН: {$new_ttn_id}): сумма {$amount} {$currency}");
        }
    }

    $pdo->commit();
    echo json_encode(['status' => 'success']);
    exit;

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
?>