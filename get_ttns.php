<?php
// get_ttns.php — Единый API-контроллер для отгрузок ТТН (Выгрузка + Загрузка сканов)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

header('Content-Type: application/json');

// Очищаем буфер вывода, чтобы пробелы не ломали JSON
if (ob_get_length()) ob_clean();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Доступ запрещен.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// =========================================================================
// ПОДПРОГРАММА 1: ОБРАБОТКА POST (ЗАГРУЗКА PDF-СКАНА ИЛИ ПРИЕМ JSON)
// =========================================================================
if ($method === 'POST') {
    // Читаем сырой JSON-поток на случай, если фронтенд запрашивает список ТТН через POST
    $rawInput = json_decode(file_get_contents('php://input'), true) ?? [];
    
    // Если это НЕ запрос данных, а реальная загрузка файла (передан ttn_id)
    if (isset($_POST['ttn_id']) || isset($rawInput['ttn_id'])) {
        try {
            $ttn_id = (int)($_POST['ttn_id'] ?? ($rawInput['ttn_id'] ?? 0));
            if ($ttn_id <= 0) {
                throw new Exception("Некорректный системный ID накладной!");
            }

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

            $uploadDir = 'uploads/ttn_scans/';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0777, true);
            }

            $newFileName = 'ttn_' . $ttn_id . '_' . time() . '.pdf';
            $fullPath    = $uploadDir . $newFileName;

            if (!move_uploaded_file($_FILES[$fileKey]['tmp_name'], $fullPath)) {
                throw new Exception("Не удалось сохранить файл на диск сервера.");
            }

            $stmt = $pdo->prepare("UPDATE project_ttns SET scan_path = ? WHERE id = ?");
            $stmt->execute([$fullPath, $ttn_id]);

            echo json_encode(['status' => 'success', 'file_path' => $fullPath]);
            exit;

        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    } 
    // Если пришел POST запрос, но без файла — значит фронтенд запрашивает список ТТН через POST JSON!
    else {
        $pid = (int)($rawInput['pid'] ?? ($rawInput['project_id'] ?? ($_POST['pid'] ?? ($_POST['project_id'] ?? 0))));
        outputTtnList($pid);
    }
}

// =========================================================================
// ПОДПРОГРАММА 2: ОБРАБОТКА GET (ВЫГРУЗКА СПИСКА ТТН В МОДАЛКУ КЛИЕНТА)
// =========================================================================
if ($method === 'GET') {
    $pid = (int)($_GET['pid'] ?? ($_GET['project_id'] ?? 0));
    outputTtnList($pid);
}

/**
 * Универсальная функция вывода списка ТТН для модального окна
 */
function outputTtnList($pid) {
    global $pdo;
    $pid = (int)$pid;

    if ($pid > 0) {
        try {
            // Узнаем номер договора, чтобы лог был максимально понятным
            $getContract = $pdo->prepare("SELECT contract_number FROM projects WHERE id = ?");
            $getContract->execute([$pid]);
            $contractNumber = $getContract->fetchColumn() ?: "ID {$pid}";

            $stmt = $pdo->prepare("SELECT id, ttn_number, ttn_date, amount, currency, product_quantity, product_info, scan_path 
                                   FROM project_ttns 
                                   WHERE project_id = ? 
                                   ORDER BY ttn_date DESC, id DESC");
            $stmt->execute([$pid]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // ВШИВАЕМ ЛОГ ПРОСМОТРА:
            // Фиксируем, что менеджер открыл список накладных по конкретному договору
            if (function_exists('logAction')) {
                logAction('AUTH', 'project_ttns', "Просмотрел список отгрузок ТТН по договору № {$contractNumber}");
            }

            // Форматируем дату в читаемый вид (дд.мм.гггг) для модалки
            foreach ($data as &$row) {
                if (!empty($row['ttn_date'])) {
                    $row['ttn_date'] = date('d.m.Y', strtotime($row['ttn_date']));
                }
            }
        } catch (Exception $e) {
            $data = [];
        }
    } else {
        $data = [];
    }

    echo json_encode($data);
    exit;
}   

?>