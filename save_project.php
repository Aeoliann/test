<?php
// save_project.php — Монолитный обработчик сохранения новых договоров и сканов документов ТТН
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php'; // Наше боевое PDO-подключение и логгер

header('Content-Type: application/json');

// Перехватываем роль и ID пользователя из сессии
$userId = $_SESSION['user_id'] ?? ($_SESSION['id'] ?? 0);

try {
    // Собираем базовые POST параметры из FormData
    $client_id       = (int)($_POST['client_id'] ?? 0);
    $contract_number = trim($_POST['contract_number'] ?? '');
    $contract_date   = !empty($_POST['contract_date']) ? trim($_POST['contract_date']) : date('Y-m-d');
    $product_type    = isset($_POST['product_type']) ? trim($_POST['product_type']) : '';
    $currency        = trim($_POST['currency'] ?? 'BYN');

    if ($client_id <= 0) {
        throw new Exception("Не передан системный идентификатор клиента.");
    }
    if (empty($contract_number)) {
        throw new Exception("Поле '№ договора' является обязательным для заполнения!");
    }

    // =========================================================================
    // ВЫПОЛНЕНИЕ КРИТЕРИЯ E: Авто-подстановка продукции из карточки лида, если селект пуст
    // =========================================================================
    if (empty($product_type)) {
        $getProdStmt = $pdo->prepare("SELECT product_type FROM clients WHERE id = ? LIMIT 1");
        $getProdStmt->execute([$client_id]);
        $product_type = trim($getProdStmt->fetchColumn() ?: '');
    }
    if (empty($product_type)) {
        $product_type = 'Сантехника'; // Страховочный дефолт
    }

    // Начинаем транзакцию для безопасной атомарной записи в СУБД
    $pdo->beginTransaction();

    // 1. Создаем физическую строчку договора в таблице projects
    $sqlProject = "INSERT INTO projects (client_id, contract_number, contract_date, product_type, currency) 
                   VALUES (?, ?, ?, ?, ?)";
    $stmtProject = $pdo->prepare($sqlProject);
    $stmtProject->execute([$client_id, $contract_number, $contract_date, $product_type, $currency]);
    
    // Перехватываем сгенерированный ID нового контракта
    $new_project_id = (int)$pdo->lastInsertId();

    if ($new_project_id <= 0) {
        throw new Exception("Сбой СУБД: не удалось сгенерировать ID договора.");
    }

    // =========================================================================
    // БЕЗОПАСНЫЙ ЗАГРУЗЧИК СКАНА ДОГОВОРА НА СЕРВЕР HOSTER.BY
    // =========================================================================
    $uploaded_file_name = null;
    if (isset($_FILES['kp_file']) && $_FILES['kp_file']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['kp_file']['tmp_name'];
        $fileName    = $_FILES['kp_file']['name'];
        
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
        
        if (in_array($fileExtension, $allowedExtensions)) {
            // Крипто-имя файла: жестко связываем с ID договора для исключения перезаписи
            $uploaded_file_name = 'contract_' . $new_project_id . '_' . md5(time() . $fileName) . '.' . $fileExtension;
            
            $uploadFileDir = __DIR__ . '/uploads/kp/';
            if (!is_dir($uploadFileDir)) {
                mkdir($uploadFileDir, 0755, true);
            }
            
            if (move_uploaded_file($fileTmpPath, $uploadFileDir . $uploaded_file_name)) {
                // Фиксируем имя файла в созданной строке проекта
                $updateFileStmt = $pdo->prepare("UPDATE projects SET kp_file = ? WHERE id = ?");
                $updateFileStmt->execute([$uploaded_file_name, $new_project_id]);
            } else {
                throw new Exception("Не удалось перенести файл скана в директорию uploads.");
            }
        } else {
            throw new Exception("Недопустимый формат файла! Разрешены только PDF, JPG, JPEG, PNG.");
        }
    }

    // 2. Взводим флаг подписания договора в главной карточке клиента в clients
    $uClient = $pdo->prepare("UPDATE clients SET is_contract_signed = 1 WHERE id = ?");
    $uClient->execute([$client_id]);

    // Пишем лог действия в системный журнал
    if (function_exists('logAction')) {
        logAction($pdo, 'INSERT', 'projects', $new_project_id, "Создан договор №{$contract_number} (Валюта: {$currency}, Продукция: {$product_type}) для клиента ID {$client_id}. Файл: {$uploaded_file_name}");
    }

    $pdo->commit();

    // Возвращаем идеальный чистый JSON успеха
    echo json_encode(['status' => 'success', 'message' => 'Договор и скан успешно сохранены в СУБД']);
    exit;

} catch (Exception $e) {
    // В случае сбоя откатываем транзакцию СУБД, чтобы не плодить битые строки
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
?>