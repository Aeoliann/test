<?php
// save_ttn.php — Прием данных ТТН, количества штук и PDF-файла под Windows XAMPP
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require 'db.php';

header('Content-Type: application/json');
if (ob_get_length()) ob_clean();

try {
    // Считываем текстовые поля из FormData
    $project_id   = (int)($_POST['project_id'] ?? 0);
    $ttn_number   = trim($_POST['ttn_number'] ?? '');
    $ttn_date     = !empty($_POST['ttn_date']) ? $_POST['ttn_date'] : date('Y-m-d');
    $amount       = (float)($_POST['amount'] ?? 0.00);
    $product_info = trim($_POST['product_info'] ?? '');
    $quantity     = (int)($_POST['product_quantity'] ?? 0); // Принимаем штуки

    if ($project_id <= 0 || empty($ttn_number) || $amount <= 0) {
        throw new Exception("Не заполнены обязательные поля формы!");
    }

    // 1. Записываем ТТН и количество штук в базу данных
    $sql = "INSERT INTO project_ttns (project_id, ttn_number, ttn_date, amount, product_info, product_quantity) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$project_id, $ttn_number, $ttn_date, $amount, $product_info, $quantity]);
    
    // Получаем ID только что созданной строки накладной
    $inserted_ttn_id = (int)$pdo->lastInsertId();

    // 2. Если менеджер прикрепил файл — переносим его на жесткий диск
    if (isset($_FILES['ttn_pdf']) && $_FILES['ttn_pdf']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/ttn_scans/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true); // Создаем папку, если ее нет
        }

        $newFileName = 'ttn_' . $inserted_ttn_id . '_' . time() . '.pdf';
        $fullPath    = $uploadDir . $newFileName;

        if (move_uploaded_file($_FILES['ttn_pdf']['tmp_name'], $fullPath)) {
            // Записываем путь к файлу в нашу новую колонку scan_path
            $uStmt = $pdo->prepare("UPDATE project_ttns SET scan_path = ? WHERE id = ?");
            $uStmt->execute([$fullPath, $inserted_ttn_id]);
        }
    }

    echo json_encode(['status' => 'success']);
    exit;

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}