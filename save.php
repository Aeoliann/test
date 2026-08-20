<?php
// save.php — Главный контроллер сохранения транзакций Santeks CRM
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';

// =========================================================================
// ЖЕЛЕЗНЫЙ ИЗОЛИРОВАННЫЙ ПЕРЕХВАТЧИК ИНЛАЙН-ОБНОВЛЕНИЯ ДАТЫ ДОГОВОРА
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_mode']) && $_POST['action_mode'] === 'update_contract_date_live') {
    header('Content-Type: application/json');
    if (ob_get_length()) ob_clean();

    try {
        $project_id    = (int)($_POST['project_id'] ?? 0);
        $contract_date = trim($_POST['contract_date'] ?? '');

        if ($project_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Некорректный системный ID проекта']);
            exit;
        }

        $final_date = !empty($contract_date) ? $contract_date : null;
        $stmt = $pdo->prepare("UPDATE projects SET contract_date = ? WHERE id = ?");
        $stmt->execute([$final_date, $project_id]);

        echo json_encode(['status' => 'success']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Ошибка СУБД: ' . $e->getMessage()]);
        exit;
    }
}

require_once 'logger.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Сессия завершена. Авторизуйтесь заново.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'manager';

// =========================================================================
// 1. ЖИВАЯ АСИНХРОННАЯ ПРОВЕРКА УНП НА ДУБЛИКАТЫ
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_mode']) && $_POST['action_mode'] === 'check_unp_duplicate_live') {
    header('Content-Type: application/json');
    if (ob_get_length()) ob_clean();

    try {
        $unp = trim($_POST['unp'] ?? '');
        if (empty($unp)) {
            echo json_encode(['status' => 'clean']); 
            exit;
        }

        $stmt = $pdo->prepare("SELECT client_name FROM clients WHERE UNP = ? LIMIT 1");
        $stmt->execute([$unp]);
        $existingClient = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingClient) {
            echo json_encode([
                'status' => 'duplicate',
                'client_name' => htmlspecialchars($existingClient['client_name'])
            ]);
            exit;
        } else {
            echo json_encode(['status' => 'clean']); 
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); 
        exit;
    }
}

// =========================================================================
// 2. ЖЕСТКИЙ БАРЬЕР СУБД ПЕРЕД КЛИЕНТСКИМ INSERT (БЛОКИРОВКА ДУБЛИКАТОВ УНП)
// =========================================================================
$current_unp = trim($_POST['unp'] ?? '');
$current_id  = (int)($_POST['client_id'] ?? ($_POST['id'] ?? 0));

if ($current_id === 0 && !empty($current_unp)) {
    $checkDbUnp = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE UNP = ?");
    $checkDbUnp->execute([$current_unp]);
    $unpCount = (int)$checkDbUnp->fetchColumn();

    if ($unpCount > 0) {
        header('Content-Type: application/json');
        if (ob_get_length()) ob_clean();
        
        echo json_encode([
            'status' => 'error',
            'message' => 'Критическая блокировка дубликата! Контрагент с УНП ' . htmlspecialchars($current_unp) . ' уже существует.'
        ]);
        exit;
    }
}

try {
    $action_mode = $_POST['action'] ?? '';

  // =========================================================================
// РЕЖИМ: ДОБАВЛЕНИЕ ДОГОВОРА К СУЩЕСТВУЮЩЕМУ КЛИЕНТУ (add_contract)
// =========================================================================
if (isset($_POST['action']) && $_POST['action'] === 'add_contract') {
    $client_id = (int)($_POST['client_id'] ?? 0);
    $contract_number = trim($_POST['contract_number'] ?? '');
    $contract_date = !empty($_POST['contract_date']) ? $_POST['contract_date'] : date('Y-m-d');
    $product_type = trim($_POST['product_type'] ?? 'Сантехника');
    $currency = trim($_POST['currency'] ?? 'BYN');
    
    if ($client_id <= 0) {
        throw new Exception("Не указан ID клиента!");
    }
    if (empty($contract_number)) {
        throw new Exception("Номер договора обязателен!");
    }
    
    $pdo->beginTransaction();
    
    $sql = "INSERT INTO projects (client_id, contract_number, contract_date, product_type, currency) 
            VALUES (?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$client_id, $contract_number, $contract_date, $product_type, $currency]);
    $project_id = $pdo->lastInsertId();
    
    $pdo->prepare("UPDATE clients SET is_contract_signed = 1 WHERE id = ?")->execute([$client_id]);
    
    if (function_exists('logAction')) {
        logAction('INSERT', 'projects', "Создан договор №{$contract_number} для клиента ID {$client_id}");
    }
    
    $pdo->commit();
    
    // ✅ ПРОВЕРЯЕМ: ЭТО AJAX-ЗАПРОС ИЛИ ОБЫЧНАЯ ФОРМА?
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest')
              || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);
    
    if ($isAjax) {
        // AJAX - возвращаем JSON с redirect_url
        echo json_encode([
            'status' => 'success',
            'project_id' => $project_id,
            'redirect' => true,
            'redirect_url' => 'contracts.php'
        ]);
        exit;
    } else {
        // Обычная форма - редирект
        header("Location: contracts.php");
        exit;
    }
}
// =========================================================================
// РЕЖИМ: ПАКЕТНАЯ СВЯЗКА КЛИЕНТ + КОНТРАКТ (complex)
// =========================================================================
if ($action_mode === 'complex') {
    $client_name     = trim($_POST['client_name'] ?? '');
    $unp             = trim($_POST['unp'] ?? '');
    $phone           = trim($_POST['phone'] ?? '');
    $contact_person  = trim($_POST['contact_person'] ?? '');
    $contract_number = trim($_POST['contract_number'] ?? '');
    $contract_date   = trim($_POST['contract_date'] ?? date('Y-m-d'));
    $product_type    = trim($_POST['product_type'] ?? 'Сантехника');
    $currency        = trim($_POST['currency'] ?? 'BYN');

    if (empty($client_name) || empty($contract_number)) {
        throw new Exception("Не заполнены обязательные поля: Название или Номер договора.");
    }

    $pdo->beginTransaction();

    $next_contact_default = date('Y-m-d', strtotime('+7 days'));
    $sqlClient = "INSERT INTO clients (client_name, unp, contact_person, phone, status, source, manager_id, product_type, first_contact_date, next_contact_date, is_contract_signed) 
                  VALUES (?, ?, ?, ?, 'Текущий', 'Связка', ?, ?, CURDATE(), ?, 1)";
    
    $pdo->prepare($sqlClient)->execute([$client_name, $unp, $contact_person, $phone, $userId, $product_type, $next_contact_default]);
    $newClientId = (int)$pdo->lastInsertId();

    $sqlContract = "INSERT INTO projects (client_id, contract_number, contract_date, product_type, currency) 
                    VALUES (?, ?, ?, ?, ?)";
    $pdo->prepare($sqlContract)->execute([$newClientId, $contract_number, $contract_date, $product_type, $currency]);
    $newProjectId = (int)$pdo->lastInsertId();

    if (function_exists('logAction')) {
        logAction('INSERT', 'projects', $newProjectId, "Создана комплексная связка: Клиент '{$client_name}' (ID: {$newClientId}) и Договор №{$contract_number} (Валюта: {$currency})");
    }

    $pdo->commit();
    
    // ✅ ТОЖЕ ПРОВЕРЯЕМ AJAX
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest')
              || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);
    
    if ($isAjax) {
        echo json_encode([
            'status' => 'success',
            'project_id' => $newProjectId,
            'client_id' => $newClientId,
            'redirect' => true,
            'redirect_url' => 'contracts.php'
        ]);
        exit;
    } else {
        header("Location: contracts.php");
        exit;
    }
}
    // =========================================================================
    // РЕЖИМ: СТАНДАРТНОЕ ОДИНОЧНОЕ СОХРАНЕНИЕ / РЕДАКТИРОВАНИЕ
    // =========================================================================
    else {
        $client_id          = (int)($_POST['id'] ?? ($_POST['client_id'] ?? 0)); 
        $client_name        = trim($_POST['client_name'] ?? '');
        $unp                = trim($_POST['unp'] ?? '');
        $contact_person     = trim($_POST['contact_person'] ?? '');
        $phone              = trim($_POST['phone'] ?? '');
        $email              = trim($_POST['email'] ?? '');
        $status             = trim($_POST['status'] ?? 'Новый');
        $source             = trim($_POST['source'] ?? 'Запрос');
        $manager_comment    = trim($_POST['comment'] ?? '');

        $first_contact_date = !empty($_POST['first_contact_date']) ? trim($_POST['first_contact_date']) : date('Y-m-d');
        $next_contact_date  = !empty($_POST['next_contact_date']) ? trim($_POST['next_contact_date']) : (!empty($_POST['next_date']) ? trim($_POST['next_date']) : date('Y-m-d', strtotime('+7 days')));

        $posted_products = $_POST['product_type'] ?? [];
        if (is_array($posted_products) && !empty($posted_products)) {
            $final_product_type = json_encode($posted_products, JSON_UNESCAPED_UNICODE);
        } else {
            $final_product_type = json_encode(['Сантехника'], JSON_UNESCAPED_UNICODE);
        }

        if (empty($client_name)) {
            throw new Exception("Наименование организации не может быть пустым!");
        }

        if ($client_id > 0) {
            $is_signed = isset($_POST['is_contract_signed']) ? (int)$_POST['is_contract_signed'] : 0;
            if (isset($_POST['signed'])) {
                $is_signed = (int)$_POST['signed'];
            }

            $sql = "UPDATE clients SET 
                        client_name = ?, 
                        unp = ?,
                        contact_person = ?,
                        first_contact_date = ?, 
                        source = ?, 
                        phone = ?, 
                        email = ?, 
                        product_type = ?,
                        next_contact_date = ?, 
                        status = ?, 
                        comment = ?, 
                        is_contract_signed = ?
                    WHERE id = ?";
            
            $pdo->prepare($sql)->execute([
                $client_name, 
                $unp,
                $contact_person,
                $first_contact_date, 
                $source, 
                $phone, 
                $email, 
                $final_product_type,
                $next_contact_date, 
                $status, 
                $manager_comment, 
                $is_signed, 
                $client_id
            ]);
            
            if (function_exists('logAction')) {
                logAction('UPDATE', 'clients', $client_id, "Отредактирован клиент ID: {$client_id}. Продукция: {$final_product_type}");
            }
            
            echo json_encode(['status' => 'success']);
            exit;
        } else {
            $sql = "INSERT INTO clients (client_name, unp, contact_person, phone, email, status, source, manager_id, product_type, first_contact_date, next_contact_date, comment) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$client_name, $unp, $contact_person, $phone, $email, $status, $source, $userId, $final_product_type, $first_contact_date, $next_contact_date, $manager_comment]);
            
            $newId = $pdo->lastInsertId();
            
            if (function_exists('logAction')) {
                logAction('INSERT', 'clients', $newId, "Создан лид: '{$client_name}' (ID: {$newId})");
            }
            
            echo json_encode(['status' => 'success']);
            exit;
        }
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
?>