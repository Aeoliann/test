<?php
// upload_scan.php — Безопасный обработчик загрузки PDF-сканов под Windows XAMPP
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

header('Content-Type: application/json');
if (ob_get_length()) ob_clean();

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Ошибка авторизации. Доступ запрещен.");
    }

  $projectId = 0;
    if (isset($_POST['project_id'])) {
        $projectId = (int)$_POST['project_id'];
    } elseif (isset($_POST['pid'])) {
        $projectId = (int)$_POST['pid'];
    }

    if (!isset($_FILES['contract_pdf']) || $_FILES['contract_pdf']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Файл не был передан на сервер или загружен с ошибкой.");
    }

    $file = $_FILES['contract_pdf'];
    
    // Проверяем формат файла — строго PDF
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        throw new Exception("Недопустимый формат файла! Разрешена загрузка только документов PDF.");
    }

    // Жестко привязываем имя папки загрузок
    $uploadDir = 'uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true); // Автоматически создаем папку, если её нет
    }

    // Генерируем уникальное имя файла, чтобы избежать перезаписи
    $newFileName = 'contract_' . $projectId . '_' . time() . '.pdf';
    $targetPath = $uploadDir . $newFileName;

    // Переносим файл из временного хранилища Windows в папку uploads
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception("Серверу XAMPP не удалось сохранить файл на жесткий диск. Проверьте права папки.");
    }

    // Записываем путь к скану в колонку scan_path таблицы проектов
    $stmt = $pdo->prepare("UPDATE projects SET scan_path = ? WHERE id = ?");
    $stmt->execute([$targetPath, $projectId]);

    echo json_encode(['status' => 'success', 'message' => 'Скан договора успешно загружен и привязан!']);
    exit;

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
