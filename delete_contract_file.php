<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php"); exit;
}

$pid = isset($_GET['pid']) ? (int)$_GET['pid'] : 0;

if ($pid > 0) {
    // 1. Ищем имя файла в базе данных
    $stmt = $pdo->prepare("SELECT contract_file FROM projects WHERE id = ?");
    $stmt->execute([$pid]);
    $fileName = $stmt->fetchColumn();

    if (!empty($fileName)) {
        $filePath = 'uploads/contracts/' . $fileName;
        // 2. Физически удаляем файл с диска
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }

    // 3. Очищаем поле в таблице projects
    $updateStmt = $pdo->prepare("UPDATE projects SET contract_file = NULL WHERE id = ?");
    $updateStmt->execute([$pid]);
}

header("Location: contracts.php");
exit;