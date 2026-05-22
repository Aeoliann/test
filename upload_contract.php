<?php
session_start();
require 'db.php';

// Очищаем буфер вывода от случайных скрытых пробелов
if (ob_get_length()) ob_clean();

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Ошибка: Пользователь не авторизован в CRM сессии.");
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Некорректный метод отправки данных.");
    }

    // Проверяем, прилетел ли вообще файл с формы
    if (!isset($_FILES['contract_pdf'])) {
        throw new Exception("Файл договора не был передан на сервер.");
    }

    $file = $_FILES['contract_pdf'];
    $pid = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;

    if ($pid <= 0) {
        throw new Exception("Критическая ошибка: Передан некорректный или пустой ID контракта.");
    }

    // Проверяем системные ошибки загрузки файлов в PHP
    if ($file['error'] !== 0) {
        switch ($file['error']) {
            case 1: case 2: 
                throw new Exception("Файл слишком тяжелый! Уменьшите размер скана договора.");
            case 3: 
                throw new Exception("Файл был загружен лишь частично из-за сбоя сети.");
            case 4: 
                throw new Exception("Файл не был выбран.");
            default: 
                throw new Exception("Неизвестная ошибка сервера при загрузке (Код " . $file['error'] . ").");
        }
    }

    // Жестко проверяем расширение файла на сервере
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        throw new Exception("Ошибка: Разрешено загружать файлы строго в формате PDF (у вас ." . $ext . ").");
    }

    // ПРИНУДИТЕЛЬНО создаем директорию на сервере XAMPP, если её нет
    $uploadDir = 'uploads/contracts/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0777, true)) {
            throw new Exception("Сервер не смог создать папку 'uploads/contracts/'. Проверьте права доступа.");
        }
    }

    // Формируем уникальное имя файла для защиты от перезаписи
    $newFileName = 'contract_' . $pid . '_' . time() . '.pdf';
    $targetPath = $uploadDir . $newFileName;

    // Перемещаем файл из временной папки XAMPP в наше постоянное хранилище
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        
        // Обновляем имя файла в базе данных
        $stmt = $pdo->prepare("UPDATE projects SET contract_file = ? WHERE id = ?");
        $stmt->execute([$newFileName, $pid]);
        
        // Логируем успешное действие в журнал
        if (function_exists('logAction')) {
            logAction($pdo, 'UPDATE', 'projects', $pid, "Прикрепил скан договора к контракту ID {$pid}");
        }

        // Если всё успешно — мягко возвращаем менеджера на страницу контрактов
        header("Location: contracts.php");
        exit;
    } else {
        throw new Exception("Не удалось переместить файл в папку назначения. Проверьте свободное место на диске C:\ ");
    }

} catch (Exception $e) {
    // В случае любого сбоя останавливаем скрипт и выводим понятную ошибку на экран
    echo "<body style='background:#151521; color:#fff; font-family:sans-serif; padding:5px; text-align:center;'>";
    echo "<div style='background:#1e1e2d; border:1px solid #ef4444; padding:25px; border-radius:12px; display:inline-block; margin-top:100px;'>";
    echo "<h2 style='color:#ef4444; margin-top:0;'>⚠ Сбой прикрепления договора</h2>";
    echo "<p style='color:#92929f; font-size:14px;'>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<a href='contracts.php' style='background:#4f46e5; color:#fff; text-decoration:none; padding:8px 16px; border-radius:6px; font-weight:bold; font-size:13px; display:inline-block; margin-top:15px;'>← Вернуться назад</a>";
    echo "</div></body>";
}
exit;