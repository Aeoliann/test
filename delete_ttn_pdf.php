<?php
session_start();
require 'db.php';
require 'logger.php'; // Фиксируем действия в журнале аудита

header('Content-Type: application/json');
if (ob_get_length()) ob_clean();

try {
    // Базовая проверка: пользователь должен быть просто авторизован в CRM
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Ошибка сессии. Пожалуйста, перезайдите в систему.");
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $ttnId = isset($data['ttn_id']) ? (int)$data['ttn_id'] : 0;

    if ($ttnId <= 0) {
        throw new Exception("Некорректный идентификатор ТТН.");
    }

    // 1. Находим имя файла ТТН, чтобы стереть его с жесткого диска сервера
    $stmt = $pdo->prepare("SELECT ttn_file, ttn_number FROM project_ttns WHERE id = ?");
    $stmt->execute([$ttnId]);
    $ttn = $stmt->fetch();

    if (!$ttn) {
        throw new Exception("ТТН не найдена в базе данных.");
    }

    $fileName = $ttn['ttn_file'];
    $ttnNumber = $ttn['ttn_number'];

    // 2. Физически удаляем файл из папки uploads/ttn/
    if (!empty($fileName)) {
        $filePath = 'uploads/ttn/' . $fileName;
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    // 3. Очищаем поле ttn_file в таблице (переводим в NULL)
    $updateStmt = $pdo->prepare("UPDATE project_ttns SET ttn_file = NULL WHERE id = ?");
    $updateStmt->execute([$ttnId]);

    // 4. Пишем в лог, какой именно менеджер стер этот документ
    logAction($pdo, 'DELETE', 'project_ttns', $ttnId, "Удалил прикрепленный PDF-файл у ТТН №{$ttnNumber}");

    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
exit;