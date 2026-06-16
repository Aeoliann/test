<?php
// upload_scan.php — Всеядный API-контроллер загрузки сканов контрактов
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';
require_once 'logger.php';

header('Content-Type: application/json');
if (ob_get_length()) ob_clean();

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Доступ запрещен. Авторизуйтесь.");
    }

    // Ловим ID проекта и параметры файла
    $project_id = (int)($_POST['project_id'] ?? 0);
    if ($project_id <= 0) {
        throw new Exception("Не указан системный ID договора для привязки скана.");
    }

    // Ищем ключ файла в массиве $_FILES
    $fileKey = '';
    if (isset($_FILES['contract_scan'])) { $fileKey = 'contract_scan'; }
    elseif (isset($_FILES['scan_file'])) { $fileKey = 'scan_file'; }
    elseif (isset($_FILES['file'])) { $fileKey = 'file'; }

    if (empty($fileKey) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Файл не передан или превысил допустимый сервером размер.");
    }

    $fileName = $_FILES[$fileKey]['name'];
    $fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    // Наш всеядный массив расширений: PDF + фото с телефона
    $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];

    if (!in_array($fileExt, $allowedExtensions)) {
        throw new Exception("Недопустимый формат файла! Разрешены только документы PDF и изображения (JPG, JPEG, PNG).");
    }

    // Создаем директорию для хранения сканов на диске
    $uploadDir = 'uploads/contract_scans/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    // Генерируем уникальное имя файла для исключения затирания данных
    $newFileName = 'contract_' . $project_id . '_' . time() . '_' . uniqid() . '.' . $fileExt;
    $fullPath    = $uploadDir . $newFileName;

    // ПЕРЕМЕЩАЕМ ФАЙЛ ИЗ ВРЕМЕННОГО БУФЕРА НА ДИСК
    if (!move_uploaded_file($_FILES[$fileKey]['tmp_name'], $fullPath)) {
        throw new Exception("Не удалось сохранить файл на диск сервера XAMPP. Проверьте права папки.");
    }

    // ОБНОВЛЯЕМ ПУТЬ К СКАНУ В ТАБЛИЦЕ PROJECTS
    $stmt = $pdo->prepare("UPDATE projects SET scan_path = ? WHERE id = ?");
    $stmt->execute([$fullPath, $project_id]);

    // Логируем действие менеджера (5 параметров)
    if (function_exists('logAction')) {
        logAction($pdo, 'UPDATE', 'projects', $project_id, "Загружен новый скан договора. Формат: .{$fileExt}, Путь: {$fullPath}");
    }

    echo json_encode(['status' => 'success', 'file_path' => $fullPath, 'ext' => $fileExt]);
    exit;

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
// ПОДПРОГРАММА УДАЛЕНИЯ СКАНА И ФИЗИЧЕСКОГО СТИРАНИЯ ФАЙЛА С ДИСКА
    if (isset($_POST['action_mode']) && $_POST['action_mode'] === 'delete_contract_scan_full') {
        $project_id = (int)($_POST['project_id'] ?? 0);
        if ($project_id <= 0) {
            throw new Exception("Не указан корректный ID договора.");
        }

        // 1. Сначала вытаскиваем текущий путь к файлу из базы данных, чтобы стереть его с жесткого диска
        $getOld = $pdo->prepare("SELECT scan_path FROM projects WHERE id = ?");
        $getOld->execute([$project_id]);
        $oldPath = trim($getOld->fetchColumn() ?: '');

        // 2. Если файл реально существует на сервере — физически уничтожаем его
        if (!empty($oldPath) && file_exists($oldPath) && is_file($oldPath)) {
            @unlink($oldPath); 
        }

        // 3. Обнуляем ячейку пути в таблице projects СУБД
        $updateStmt = $pdo->prepare("UPDATE projects SET scan_path = NULL WHERE id = ?");
        $updateStmt->execute([$project_id]);

        // 4. Логируем зачистку (5 параметров)
        if (function_exists('logAction')) {
            logAction($pdo, 'DELETE', 'projects', $project_id, "Менеджер полностью удалил скан-копию договора. Старый файл: {$oldPath}");
        }

        echo json_encode(['status' => 'success']);
        exit;
    }
?>
