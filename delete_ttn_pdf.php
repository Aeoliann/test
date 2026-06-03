<?php
// delete_ttn_pdf.php — Безопасное физическое удаление скана накладной ТТН с диска и из СУБД
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

    // Ловим ID ТТН из классического POST-запроса URLSearchParams
    $ttn_id = (int)($_POST['ttn_id'] ?? 0);

    if ($ttn_id <= 0) {
        throw new Exception("Критическая ошибка: Не передан системный ID накладной!");
    }

    // 1. Сначала вытаскиваем путь к файлу из базы, чтобы удалить его физически
    $stmt = $pdo->prepare("SELECT scan_path FROM project_ttns WHERE id = ?");
    $stmt->execute([$ttn_id]);
    $filePath = $stmt->fetchColumn();

    // 2. Если файл реально существует на диске Windows — стираем его
    if (!empty($filePath) && file_exists($filePath)) {
        @unlink($filePath);
    }

    // 3. Обнуляем колонку пути к скану в таблице project_ttns
    $uStmt = $pdo->prepare("UPDATE project_ttns SET scan_path = NULL WHERE id = ?");
    $uStmt->execute([$ttn_id]);

    // Отдаем JavaScript идеальный JSON-ответ успеха
    echo json_encode(['status' => 'success']);
    exit;

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
?>