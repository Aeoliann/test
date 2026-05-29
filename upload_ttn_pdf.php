<?php
// upload_ttn_pdf.php — Асинхронная загрузка PDF-сканов для накладных ТТН/CMR в Windows XAMPP
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

header('Content-Type: application/json');
if (ob_get_length()) ob_clean();

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Доступ запрещен. Авторизуйтесь в CRM.");
    }

    // 1. Всеядный перехват системного ID накладной ТТН (из POST или из инлайн- fetch)
    $ttn_id = 0;
    if (isset($_POST['ttn_id'])) {
        $ttn_id = (int)$_POST['ttn_id'];
    } elseif (isset($_POST['id'])) {
        $ttn_id = (int)$_POST['id'];
    } elseif (isset($_POST['pid'])) {
        $ttn_id = (int)$_POST['pid'];
    }

    if ($ttn_id <= 0) {
        throw new Exception("Критическая ошибка: Некорректный системный ID накладной ТТН!");
    }

    // 2. Всеядный перехват бинарного файла из FormData (ловим любые имена ключей)
    $fileKey = '';
    if (isset($_FILES['ttn_pdf'])) {
        $fileKey = 'ttn_pdf';
    } elseif (isset($_FILES['contract_pdf'])) {
        $fileKey = 'contract_pdf';
    } elseif (isset($_FILES['file'])) {
        $fileKey = 'file';
    }

    if (empty($fileKey) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Файл не передан или превышен лимит размера (макс. 20МБ)!");
    }

    // Проверяем расширение файла на безопасность
    $fileName = $_FILES[$fileKey]['name'];
    $fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($fileExt !== 'pdf') {
        throw new Exception("Запрещенный формат! Разрешена загрузка строго документов PDF.");
    }

    // 3. Создаем изолированную директорию для документов отгрузок (если ее еще нет)
    $uploadDir = 'uploads/ttn_scans/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    // Генерируем уникальное, чистое имя файла во избежание затирания (Напр: ttn_124_timestamp.pdf)
    $newFileName = 'ttn_' . $ttn_id . '_' . time() . '.pdf';
    $fullPath    = $uploadDir . $newFileName;

    // 4. Переносим файл из временной папки Windows в наш рабочий каталог CRM
    if (!move_uploaded_file($_FILES[$fileKey]['tmp_name'], $fullPath)) {
        throw new Exception("Не удалось сохранить файл на жесткий диск сервера. Проверьте права папки uploads!");
    }

    // 5. Записываем путь к файлу в СУБД Windows XAMPP
    // (Используем всеядную колонку пути скана, проверь имя scan_path или ttn_file в своей базе)
    $sql = "UPDATE project_ttns SET scan_path = ? WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$fullPath, $ttn_id]);

    // Если всё прошло гладко — отдаем JavaScript идеальный JSON-ответ успеха
    echo json_encode(['status' => 'success', 'file_path' => $fullPath]);
    exit;

} catch (Exception $e) {
    // Любой сбой не вешает форму, а красиво возвращается алертом в JS
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
    