<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

header('Content-Type: application/json');

// Полностью очищаем буфер вывода (убираем скрытые пробелы)
if (ob_get_length()) ob_clean();

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Пользователь не авторизован в сессии CRM!");
    }

    // Считываем pid, переданный из инлайн JavaScript
    $pid = isset($_POST['pid']) ? (int)$_POST['pid'] : 0;
    if ($pid <= 0) {
        throw new Exception("Критическая ошибка: Передан некорректный или пустой ID договора (pid).");
    }

    // ВСЕЯДНЫЙ СБОР ФАЙЛА: Автоматически находим массив файла, как бы менеджер его ни назвал
    $fileKey = '';
    if (isset($_FILES['contract_pdf'])) { $fileKey = 'contract_pdf'; }
    elseif (isset($_FILES['file'])) { $fileKey = 'file'; }
    elseif (!empty($_FILES)) { $fileKey = key($_FILES); } // Берем самый первый прилетевший ключ

    if (empty($fileKey) || !isset($_FILES[$fileKey])) {
        throw new Exception("Файл договора не был получен сервером. Проверьте размер файла.");
    }

    $file = $_FILES[$fileKey];

    // Проверяем системные ошибки загрузки файлов в Apache/XAMPP
    if ($file['error'] !== 0) {
        throw new Exception("Сбой загрузки на стороне сервера. Код ошибки PHP: " . $file['error']);
    }

    // Проверяем расширение файла
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        throw new Exception("Разрешено загружать файлы строго в формате PDF! У вас файл: ." . $ext);
    }

    // ЖЕСТКАЯ ПОДСТРАХОВКА ПАПКИ: Принудительно создаем директорию, если её нет на диске
    $uploadDir = 'uploads/contracts/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0777, true)) {
            throw new Exception("Сервер XAMPP не смог создать папку 'uploads/contracts/'. Проверьте свободное место на диске C:");
        }
    }

    // Формируем красивое уникальное имя файла
    $newFileName = 'contract_' . $pid . '_' . time() . '.pdf';
    $targetPath = $uploadDir . $newFileName;

    // Перемещаем файл из временной папки XAMPP в боевое хранилище
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        
        // Обновляем запись в базе данных в таблице projects строго по колонке contract_file
        $stmt = $pdo->prepare("UPDATE projects SET contract_file = ? WHERE id = ?");
        $stmt->execute([$newFileName, $pid]);
        
        // Отдаем JSON об успешном завершении
        echo json_encode(['status' => 'success', 'filename' => $newFileName]);
        exit;
    } else {
        throw new Exception("Не удалось переместить файл во внутреннее хранилище сайта. Проверьте права папки uploads.");
    }

} catch (Exception $e) {
    // Выводим ошибку ЧИСТЫМ КРИСТАЛЬНЫМ РУССКИМ ТЕКСТОМ (Без кодировок Юникода)
    echo json_encode([
        'status' => 'error', 
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
exit;
