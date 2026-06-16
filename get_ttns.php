<?php
// get_ttns.php — Единый API-контроллер для отгрузок ТТН (Выгрузка + Загрузка сканов)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

header('Content-Type: application/json');
if (ob_get_length()) ob_clean();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Доступ запрещен.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// =========================================================================
// ПОДПРОГРАММА 1: ОБРАБОТКА POST (ЗАГРУЗКА PDF-СКАНА НАКЛАДНОЙ)
// =========================================================================
if ($method === 'POST') {
    try {
        $ttn_id = (int)($_POST['ttn_id'] ?? 0);
        if ($ttn_id <= 0) {
            throw new Exception("Некорректный системный ID накладной!");
        }

        // Ловим бинарный файл по любому из ключей
        $fileKey = '';
        if (isset($_FILES['ttn_pdf'])) { $fileKey = 'ttn_pdf'; }
        elseif (isset($_FILES['contract_pdf'])) { $fileKey = 'contract_pdf'; }
        elseif (isset($_FILES['file'])) { $fileKey = 'file'; }

        if (empty($fileKey) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Файл не передан или превысил допустимый размер!");
        }

        $fileName = $_FILES[$fileKey]['name'];
        $fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($fileExt !== 'pdf') {
            throw new Exception("Разрешена загрузка строго документов PDF!");
        }

        // Создаем директорию, если её нет
        $uploadDir = 'uploads/ttn_scans/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        $newFileName = 'ttn_' . $ttn_id . '_' . time() . '.pdf';
        $fullPath    = $uploadDir . $newFileName;

        if (!move_uploaded_file($_FILES[$fileKey]['tmp_name'], $fullPath)) {
            throw new Exception("Не удалось сохранить файл на диск сервера.");
        }

        // Пишем путь в СУБД Windows XAMPP
        $stmt = $pdo->prepare("UPDATE project_ttns SET scan_path = ? WHERE id = ?");
        $stmt->execute([$fullPath, $ttn_id]);

        echo json_encode(['status' => 'success', 'file_path' => $fullPath]);
        exit;

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// =========================================================================
// ПОДПРОГРАММА 2: ОБРАБОТКА GET (ВЫГРУЗКА СПИСКА ТТН В МОДЕН КЛИЕНТА)
// =========================================================================
// ИСПРАВЛЕНО НАМЕРТВО: Ловим ID проекта из любых ключей фронтенда (pid или project_id)
$pid = (int)($_GET['pid'] ?? ($_GET['project_id'] ?? 0));

if ($pid > 0) {
    $stmt = $pdo->prepare("SELECT id, ttn_number, ttn_date, amount, currency, product_quantity, product_info, scan_path FROM project_ttns WHERE project_id = ? ORDER BY ttn_date DESC, id DESC");
    $stmt->execute([$pid]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $data = [];
}

echo json_encode($data);
exit;
?>
    