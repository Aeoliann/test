<?php
// delete_contract_file.php — Безопасное удаление скана договора из базы Windows XAMPP
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    die("Доступ запрещен. Авторизуйтесь.");
}

try {
    // 1. ПЕРЕХВАТЫВАЕМ ID ДОГОВОРА ИЗ ССЫЛКИ И ПРИВОДИМ К ЧИСЛУ
    $pid = isset($_GET['pid']) ? (int)$_GET['pid'] : 0;
    
    if ($pid <= 0) {
        throw new Exception("Критическая ошибка: Некорректный системный ID договора!");
    }

    // 2. СНАЧАЛА НАХОДИМ ФАЙЛ НА ДИСКЕ WINDOWS И ФИЗИЧЕСКИ УДАЛЯЕМ ЕГО
    $stmt_find = $pdo->prepare("SELECT scan_path FROM projects WHERE id = ?");
    $stmt_find->execute([$pid]);
    $filePath = $stmt_find->fetchColumn();

    if ($filePath && file_exists($filePath)) {
        @unlink($filePath); // Удаляем сам pdf-файл из папки uploads
    }

    // 3. ОБНУЛЯЕМ ПУТЬ К СКАНУ В ТАБЛИЦЕ (ИСПРАВЛЕНО НА ПРАВИЛЬНОЕ ПОЛЕ scan_path)
    $stmt_update = $pdo->prepare("UPDATE projects SET scan_path = NULL WHERE id = ?");
    $stmt_update->execute([$pid]);

    // Возвращаем менеджера обратно в раздел контрактов без вылета ошибок
    header("Location: contracts.php");
    exit;

} catch (Exception $e) {
    die("Критический сбой СУБД при удалении файла: " . $e->getMessage());
}
?>