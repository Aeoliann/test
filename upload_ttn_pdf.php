<?php
session_start();
require 'db.php';
header('Content-Type: application/json');

if (ob_get_length()) ob_clean();

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Ошибка авторизации сессии.");
    }

    $ttnId = isset($_POST['ttn_id']) ? (int)$_POST['ttn_id'] : 0;
    
    if ($ttnId <= 0) {
        throw new Exception("Некорректный идентификатор ТТН.");
    }

    if (!isset($_FILES['ttn_pdf']) || $_FILES['ttn_pdf']['error'] !== 0) {
        throw new Exception("Файл не передан или загружен с ошибкой сервера.");
    }

    // Проверяем расширение файла на сервере
    $ext = strtolower(pathinfo($_FILES['ttn_pdf']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        throw new Exception("Допускаются только файлы с расширением .pdf");
    }

    // Создаем директорию, если её нет
    $uploadDir = 'uploads/ttn/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Формируем уникальное имя файла для защиты от перезаписи
    $newFileName = 'ttn_' . $ttnId . '_' . time() . '.pdf';
    $uploadPath = $uploadDir . $newFileName;

    if (move_uploaded_file($_FILES['ttn_pdf']['tmp_name'], $uploadPath)) {
        // Записываем имя файла в базу данных к конкретной ТТН
        $stmt = $pdo->prepare("UPDATE project_ttns SET ttn_file = ? WHERE id = ?");
        $stmt->execute([$newFileName, $ttnId]);

        echo json_encode(['status' => 'success', 'filename' => $newFileName]);
    } else {
        throw new Exception("Не удалось переместить файл в папку назначения. Проверьте права папки uploads/ttn/");
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
exit;
    