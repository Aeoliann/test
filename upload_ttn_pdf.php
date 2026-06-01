<?php
// upload_ttn_pdf.php — Загрузка PDF-сканов накладных ТТН в новую колонку scan_path
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

header('Content-Type: application/json');
if (ob_get_length()) ob_clean();

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Доступ запрещен. Авторизуйтесь.");
    }

    // Перехватываем ID строки ТТН
    $ttn_id = (int)($_POST['ttn_id'] ?? 0);
    if ($ttn_id <= 0) {
        throw new Exception("Некорректный системный ID накладной!");
    }

    // Перехватываем бинарный файл под любым именем ключа FormData
    $fileKey = '';
    if (isset($_FILES['ttn_pdf'])) $fileKey = 'ttn_pdf';
    elseif (isset($_FILES['contract_pdf'])) $fileKey = 'contract_pdf';
    elseif (isset($_FILES['file'])) $fileKey = 'file';

    if (empty($fileKey) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Файл не передан или превышен лимит размера!");
    }

    $uploadDir = 'uploads/ttn_scans/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    $newFileName = 'ttn_' . $ttn_id . '_' . time() . '.pdf';
    $fullPath    = $uploadDir . $newFileName;

    if (!move_uploaded_file($_FILES[$fileKey]['tmp_name'], $fullPath)) {
        throw new Exception("Не удалось сохранить файл на сервере.");
    }

    // ИСПРАВЛЕНО: Записываем путь к скану строго в созданную колонку scan_path таблицы project_ttns
    $stmt = $pdo->prepare("UPDATE project_ttns SET scan_path = ? WHERE id = ?");
    $stmt->execute([$fullPath, $ttn_id]);

    echo json_encode(['status' => 'success', 'file_path' => $fullPath]);
    exit;

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}