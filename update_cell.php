<?php
// update_cell.php — микроконтроллер мгновенного сохранения ячеек сетки CRM
// Версия 2.2 — исправлена логика черновиков и снятия галочки
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

header('Content-Type: application/json');
if (ob_get_length()) ob_clean();

// ================================================================
// 1. ПРОВЕРКА АВТОРИЗАЦИИ
// ================================================================
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Сессия не активна. Авторизуйтесь.']);
    exit;
}

// ================================================================
// 2. ЧТЕНИЕ ВХОДНЫХ ДАННЫХ (JSON или POST)
// ================================================================
$rawInput = file_get_contents('php://input');
$data = [];

if (!empty($rawInput)) {
    $jsonData = json_decode($rawInput, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
        $data = $jsonData;
    }
}
if (empty($data) && !empty($_POST)) {
    $data = $_POST;
}

if (empty($data['id']) || empty($data['field'])) {
    echo json_encode(['status' => 'error', 'message' => 'Не переданы обязательные параметры: id и field']);
    exit;
}

$id = (int)$data['id'];
$field = trim($data['field']);
$value = isset($data['value']) ? trim($data['value']) : '';

// ================================================================
// 3. БЕЛЫЙ СПИСОК РАЗРЕШЁННЫХ ПОЛЕЙ
// ================================================================
$allowedFields = [
    'client_name', 'unp', 'website', 'contact_person', 'phone',
    'email', 'product_type', 'status', 'source', 'comment',
    'next_contact_date', 'is_contract_signed', 'ct_type'
];

if (!in_array($field, $allowedFields)) {
    echo json_encode(['status' => 'error', 'message' => "Поле '{$field}' не разрешено для обновления"]);
    exit;
}

// ================================================================
// 4. ОБРАБОТКА ГАЛОЧКИ "КОНТРАКТ"
// ================================================================
if ($field === 'is_contract_signed') {
    $newValue = (int)$value;

    try {
        // ---------- УСТАНОВКА ГАЛОЧКИ (1) ----------
        if ($newValue === 1) {
            // Проверяем, есть ли уже черновик
            $check = $pdo->prepare("
                SELECT id 
                FROM projects 
                WHERE client_id = ? 
                  AND (contract_number IS NULL 
                       OR contract_number = '' 
                       OR contract_number = 'Б/Н' 
                       OR contract_number = 'Пустой черновик' 
                       OR contract_number = 'Черновик')
                LIMIT 1
            ");
            $check->execute([$id]);
            $existingDraft = $check->fetchColumn();

            if (!$existingDraft) {
                // Получаем продукцию из карточки клиента
                $prodStmt = $pdo->prepare("SELECT product_type FROM clients WHERE id = ?");
                $prodStmt->execute([$id]);
                $productType = $prodStmt->fetchColumn();
                if ($productType === null || $productType === '') {
                    $productType = ''; // будет подставлено из клиента при выводе
                }

                // Создаём черновик с продукцией из клиента
                $stmt = $pdo->prepare("
                    INSERT INTO projects (client_id, contract_number, contract_date, product_type, currency) 
                    VALUES (?, 'Б/Н', CURDATE(), ?, 'BYN')
                ");
                $stmt->execute([$id, $productType]);
                $draftId = $pdo->lastInsertId();
                if (function_exists('logAction')) {
                    logAction('INSERT', 'projects', "Создан черновик договора (Б/Н) для клиента ID: {$id} с продукцией: {$productType}");
                }
            }

            // Обновляем флаг is_contract_signed
            $pdo->prepare("UPDATE clients SET is_contract_signed = 1 WHERE id = ?")->execute([$id]);

            if (function_exists('logAction')) {
                logAction('UPDATE', 'clients', "Установлена галочка 'Контракт' для клиента ID: {$id}");
            }

            echo json_encode(['status' => 'success', 'message' => 'Галочка установлена, черновик создан']);
            exit;
        }

        // ---------- СНЯТИЕ ГАЛОЧКИ (0) ----------
        if ($newValue === 0) {
            // Проверяем наличие РЕАЛЬНЫХ договоров (не черновиков)
            $real = $pdo->prepare("
                SELECT COUNT(*) 
                FROM projects 
                WHERE client_id = ? 
                  AND contract_number IS NOT NULL 
                  AND contract_number != '' 
                  AND contract_number NOT IN ('Б/Н', 'Пустой черновик', 'Черновик')
            ");
            $real->execute([$id]);
            $realCount = $real->fetchColumn();

            if ($realCount > 0) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Невозможно снять галочку: у клиента есть активные договоры. Сначала удалите их в разделе "Контракты".'
                ]);
                exit;
            }

            // Удаляем ВСЕ черновики (все варианты)
            $pdo->prepare("
                DELETE FROM projects 
                WHERE client_id = ? 
                  AND (contract_number IS NULL 
                       OR contract_number = '' 
                       OR contract_number = 'Б/Н' 
                       OR contract_number = 'Пустой черновик' 
                       OR contract_number = 'Черновик')
            ")->execute([$id]);

            // Снимаем флаг
            $pdo->prepare("UPDATE clients SET is_contract_signed = 0 WHERE id = ?")->execute([$id]);

            if (function_exists('logAction')) {
                logAction('UPDATE', 'clients', "Снята галочка 'Контракт' у клиента ID: {$id}, черновики удалены");
            }

            echo json_encode(['status' => 'success', 'message' => 'Галочка снята, черновики удалены']);
            exit;
        }

        // Если значение не 0 и не 1
        echo json_encode(['status' => 'error', 'message' => 'Некорректное значение для поля is_contract_signed']);
        exit;

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Ошибка БД: ' . $e->getMessage()]);
        exit;
    }
}

// ================================================================
// 5. ОБНОВЛЕНИЕ ОСТАЛЬНЫХ ПОЛЕЙ (обычные текстовые/числовые)
// ================================================================
try {
    $stmt = $pdo->prepare("UPDATE clients SET {$field} = ? WHERE id = ?");
    $stmt->execute([$value, $id]);

    if (function_exists('logAction')) {
        logAction('UPDATE', 'clients', "Обновлено поле '{$field}' у клиента ID: {$id} на '{$value}'");
    }

    echo json_encode(['status' => 'success', 'message' => 'Поле успешно обновлено']);
    exit;

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Ошибка БД: ' . $e->getMessage()]);
    exit;
}
?>