<?php
session_start();
require 'db.php';
require 'logger.php'; // Наш логгер для фиксации действий в activity_logs.php
header('Content-Type: application/json');

if (ob_get_length()) ob_clean();

try {
    // ЖЕСТКАЯ ПРОВЕРКА БЕЗОПАСНОСТИ: Удалять файлы ТТН разрешено ТОЛЬКО админу
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        throw new Exception("Доступ заблокирован. У вас нет прав на удаление финансовых файлов!");
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $ttnId = isset($data['ttn_id']) ? (int)$data['ttn_id'] : 0;

    if ($ttnId <= 0) {
        throw new Exception("Некорректный идентификатор ТТН.");
    }

    // 1. Сначала узнаем точное имя файла в базе данных, чтобы стереть его с жесткого диска
    $stmt = $pdo->prepare("SELECT ttn_file, ttn_number FROM project_ttns WHERE id = ?");
    $stmt->execute([$ttnId]);
    $ttn = $stmt->fetch();

    if (!$ttn) {
        throw new Exception("ТТН с таким ID не найдена в системе.");
    }

    $fileName = $ttn['ttn_file'];
    $ttnNumber = $ttn['ttn_number'];

    if (!empty($fileName)) {
        $filePath = 'uploads/ttn/' . $fileName;
        // Физически удаляем файл с сервера, если он там существует
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    // 2. Очищаем поле ttn_file в базе данных (ставим NULL)
    $updateStmt = $pdo->prepare("UPDATE project_ttns SET ttn_file = NULL WHERE id = ?");
    $updateStmt->execute([$ttnId]);

    // 3. Логируем это действие в журнал аудита для директора
    logAction($pdo, 'DELETE', 'project_ttns', $ttnId, "Удалил прикрепленный PDF-файл у ТТН №{$ttnNumber}");

    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
exit;
?>