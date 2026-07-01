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

        // Если дату стерли в календаре — пишем NULL, иначе сохраняем выбранную
        $final_date = !empty($contract_date) ? $contract_date : null;

        // Обновляем строго поле в таблице проектов (убедись, что таблица называется projects)
        $stmt = $pdo->prepare("UPDATE projects SET contract_date = ? WHERE id = ?");
        $stmt->execute([$final_date, $project_id]);

        // Возвращаем фронтенду статус успеха и прерываем выполнение всего save.php!
        echo json_encode(['status' => 'success']);
        exit;

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Ошибка СУБД: ' . $e->getMessage()]);
        exit;
    }
}
require_once 'logger.php'; // НАМЕРТВО ИСПРАВЛЕНО: Подключаем логгер для предотвращения Fatal Error

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Сессия завершена. Авторизуйтесь заново.']);
    exit;
}


$userId = (int)$_SESSION['user_id'];

$unp = isset($_POST['unp']) ? trim($_POST['unp']) : '';
$role = $_SESSION['role'] ?? 'manager';

// Проверяем уникальность УНП только если оно заполнено и пользователь НЕ админ
// =========================================================================
// 1. ЖИВАЯ АСИНХРОННАЯ ПРОВЕРКА УНП НА ДУБЛИКАТЫ (СИНХРОНИЗИРОВАНО С UNP)
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

        // ЖЕЛЕЗНЫЙ ФИКС: Ищем строго по имени колонки UNP из твоей структуры БД
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
// 2. ЖЕСТКИЙ БАРЬЕР СУБД ПЕРЕД КЛИЕНТСКИМ INSERT (БЛОКИРОВКА НАМЕРТВО)
// =========================================================================
$current_unp = trim($_POST['unp'] ?? '');
$current_id  = (int)($_POST['client_id'] ?? ($_POST['id'] ?? 0));

// Защищаем только СОЗДАНИЕ (когда id нового клиента равен 0)
if ($current_id === 0 && !empty($current_unp)) {
    // Проверяем наличие дубликата по точной колонке UNP
    $checkDbUnp = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE UNP = ?");
    $checkDbUnp->execute([$current_unp]);
    $unpCount = (int)$checkDbUnp->fetchColumn();

    if ($unpCount > 0) {
        header('Content-Type: application/json');
        if (ob_get_length()) ob_clean();
        
        // Прерываем сохранение, выкидываем ошибку и не пускаем код к алерту "Успешно зарегистрирован"
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
    // РЕЖИМ А: ПАКЕТНАЯ СВЯЗКА КЛИЕНТ + КОНТРАКТ (ИНТЕГРИРОВАНА МУЛЬТИВАЛЮТНОСТЬ)
    // =========================================================================
    if ($action_mode === 'complex') {
        $client_name     = trim($_POST['client_name'] ?? '');
        $unp             = trim($_POST['unp'] ?? '');
        $phone           = trim($_POST['phone'] ?? '');
        $contact_person  = trim($_POST['contact_person'] ?? '');
        $contract_number = trim($_POST['contract_number'] ?? '');
        $contract_date   = trim($_POST['contract_date'] ?? date('Y-m-d'));
        $product_type    = trim($_POST['product_type'] ?? 'Сантехника');

        // ---- ХОТФИКС: Ловим валюту договора из пакетной формы ----
        $currency        = trim($_POST['currency'] ?? 'BYN');
        // ----------------------------------------------------------

        if (empty($client_name) || empty($contract_number)) {
            throw new Exception("Не заполнены обязательные поля: Название или Номер договора.");
        }

        $pdo->beginTransaction();

        // ШАГ 1: Вставка клиента с флагом подписания 1 и автопродлением даты следующего контакта (+7 дней)
        $next_contact_default = date('Y-m-d', strtotime('+7 days'));
        $sqlClient = "INSERT INTO clients (client_name, unp, contact_person, phone, status, source, manager_id, product_type, first_contact_date, next_contact_date, is_contract_signed) 
                      VALUES (?, ?, ?, ?, 'Текущий', 'Связка', ?, ?, CURDATE(), ?, 1)";
        
        $pdo->prepare($sqlClient)->execute([$client_name, $unp, $contact_person, $phone, $userId, $product_type, $next_contact_default]);
        $newClientId = (int)$pdo->lastInsertId();

        // ШАГ 2: Вставка контракта в projects (ИСПРАВЛЕНО: Записываем переменную $currency вместо жесткого 'BYN')
        $sqlContract = "INSERT INTO projects (client_id, contract_number, contract_date, product_type, currency) 
                        VALUES (?, ?, ?, ?, ?)";
        $pdo->prepare($sqlContract)->execute([$newClientId, $contract_number, $contract_date, $product_type, $currency]);
        $newProjectId = (int)$pdo->lastInsertId();

        // Пишем в логи
        if (function_exists('logAction')) {
            logAction($pdo, 'INSERT', 'projects', $newProjectId, "Создана комплексная связка: Клиент '{$client_name}' (ID: {$newClientId}) и Договор №{$contract_number} (Валюта: {$currency})");
        }

        $pdo->commit();
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    // =========================================================================
    // РЕЖИМ Б: СТАНДАРТНОЕ ОДИНОЧНОЕ СОХРАНЕНИЕ / РЕДАКТИРОВАНИЕ
    // =========================================================================
    else {
        $client_id          = (int)($_POST['id'] ?? 0); 
        $client_name        = trim($_POST['client_name'] ?? '');
        $unp                = trim($_POST['unp'] ?? '');
        $contact_person     = trim($_POST['contact_person'] ?? '');
        $phone              = trim($_POST['phone'] ?? '');
        $email              = trim($_POST['email'] ?? '');
        $status             = trim($_POST['status'] ?? 'Новый');
        $source             = trim($_POST['source'] ?? 'Запрос');
        $product_type       = trim($_POST['product_type'] ?? 'Сантехника');
        $first_contact_date = !empty($_POST['first_contact_date']) ? trim($_POST['first_contact_date']) : date('Y-m-d');
        $next_contact_date  = !empty($_POST['next_contact_date']) ? trim($_POST['next_contact_date']) : date('Y-m-d', strtotime('+7 days'));
        $manager_comment    = trim($_POST['comment'] ?? '');

        if (empty($client_name)) {
            throw new Exception("Наименование организации не может быть пустым!");
        }

     if ($client_id > 0) {
            // ИСПРАВЛЕНО НАМЕРТВО: Честный перехват состояния чекбокса
            $is_signed = isset($_POST['is_contract_signed']) ? (int)$_POST['is_contract_signed'] : 0;
            
            // Резервная страховка на случай альтернативного имени ключа в FormData
            if (isset($_POST['signed'])) {
                $is_signed = (int)$_POST['signed'];
            }

            // Твой проверенный рабочий SQL-запрос обновления карточки клиента
            $sql = "UPDATE clients SET 
                        client_name = ?, first_contact_date = ?, source = ?, 
                        phone = ?, email = ?, product_type = ?, 
                        next_contact_date = ?, status = ?, comment = ?, is_contract_signed = ?
                    WHERE id = ?";
            
            $pdo->prepare($sql)->execute([$client_name, $first_contact_date, $source, $phone, $email, $product_type, $next_contact_date, $status, $manager_comment, $is_signed, $client_id]);
            
            if (function_exists('logAction')) {
                logAction($pdo, 'UPDATE', 'clients', $client_id, "Отредактирован клиент ID: {$client_id}. Флаг контракта: {$is_signed}");
            }
            
            echo json_encode(['status' => 'success']);
            exit;
        } else {
            // INSERT одиночного клиента
            $sql = "INSERT INTO clients (client_name, unp, contact_person, phone, email, status, source, manager_id, product_type, first_contact_date, next_contact_date, comment) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$client_name, $unp, $contact_person, $phone, $email, $status, $source, $userId, $product_type, $first_contact_date, $next_contact_date, $manager_comment]);
            
            $newId = $pdo->lastInsertId();
            
            if (function_exists('logAction')) {
                logAction($pdo, 'INSERT', 'clients', $newId, "Создан лид: '{$client_name}' (ID: {$newId})");
            }
            
            echo json_encode(['status' => 'success']);
            exit;
        }
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack(); // Откатываем трансляцию СУБД в случае любого сбоя
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
?>