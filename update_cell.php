<?php
// update_cell.php — Микроконтроллер мгновенного сохранения ячеек сетки CRM
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php'; // Здесь находится наше подключение и универсальная функция logAction

header('Content-Type: application/json');

// Чтение входящих данных из POST или JSON-потока fetch-запроса
$data = !empty($_POST) ? $_POST : ($GLOBALS['__JSON_CACHE__'] ?? json_decode(file_get_contents('php://input'), true));
if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'Данные пакета потеряны']); 
    exit;
}

// =========================================================================
// НАМЕРТВО ИСПРАВЛЕНО: Защита инлайн-календаря таблицы от сброса в ноль
// =========================================================================
$id    = (int)($data['id'] ?? 0);
$field = trim($data['field'] ?? '');

// Указываем PHP, какие поля из таблицы нужно принимать как ЧИСТУЮ СТРОКУ, а не число
$isStringField = in_array($field, [
    'currency', 'client_name', 'status', 'client_type', 'phone', 'comment', 
    'next_contact_date', 
]);

// ЖЕСТКИЙ ФИКС: Если это инлайн-дата или текст — пишем строку, иначе — принудительно число!
$value = $isStringField ? trim($data['value'] ?? '') : (int)($data['value'] ?? 0);

// Перехватываем роль пользователя из сессии для логики очистки
$role = $_SESSION['role'] ?? ($_SESSION['user_role'] ?? 'manager');
$userId = $_SESSION['user_id'] ?? 0;
// =========================================================================
// НАМЕРТВО ИСПРАВЛЕНО: Каскадный перехват Источника и Даты (Защита от сброса)
// =========================================================================
// 1. Вытаскиваем дату следующего контакта из всех возможных инпутов формы
$next_contact_date = trim($_POST['next_contact_date'] ?? ($_POST['next_date'] ?? ($_POST['add_client_next_date'] ?? '')));
if ($next_contact_date === '0000-00-00' || empty($next_contact_date)) {
    $next_contact_date = null; // Защита от записи пустых дат-заглушек
}

// 2. Вытаскиваем источник привлечения, страхуясь от любых вариантов name="" селекта
$source = trim($_POST['source'] ?? ($_POST['source_id'] ?? ($_POST['source_attraction'] ?? 'Холодный поиск')));
if (empty($source)) {
    $source = 'Холодный поиск'; // Ставим жесткий дефолт, чтобы поле не затиралось пустотой
}

// 3. Подставляем проверенные переменные в твой итоговый SQL-запрос UPDATE:
$sqlMainUpdate = "UPDATE clients SET 
                    client_name = ?, 
                    unp = ?, 
                    website = ?, 
                    contact_person = ?, 
                    phone = ?, 
                    email = ?, 
                    product_type = ?, 
                    status = ?, 
                    comment = ?,
                    source = ?,             -- Намертво фиксируем источник
                    next_contact_date = ?    -- Намертво фиксируем дату следующего созвона
                  WHERE id = ?";
                  
$stmtMainUpdate = $pdo->prepare($sqlMainUpdate);
$stmtMainUpdate->execute([
    trim($_POST['client_name'] ?? ''),
    trim($_POST['unp'] ?? ''),
    trim($_POST['website'] ?? ''),
    trim($_POST['contact_person'] ?? ''),
    trim($_POST['phone'] ?? ''),
    trim($_POST['email'] ?? ''),
    trim($_POST['product_type'] ?? 'Сантехника'),
    trim($_POST['status'] ?? 'Новый'),
    trim($_POST['comment'] ?? ''),
    $source,
    $next_contact_date,
    (int)$_POST['client_id']
]);
    // 2. Логика переключения галочки "is_contract_signed"
   if ($field === 'is_contract_signed') {
        if ((int)$value === 0) {
            // Если галку СНЯЛИ — сносим только битые пустые черновики
            $pdo->prepare("DELETE FROM projects WHERE client_id = ? AND (contract_number = '' OR contract_number IS NULL OR contract_number = 'Б/Н' OR contract_number = 'Пустой черновик')")->execute([$id]);
        } 
        elseif ((int)$value === 1) {
            // Если галку ПОСТАВИЛИ — только логируем, НИКАКИХ INSERT INTO projects тут нет!
            if (function_exists('logAction')) {
                logAction('UPDATE', 'clients', "Менеджер инициировал подписание договора у клиента ID: {$id}");
            }
        }
    }

    echo json_encode(['status' => 'success']);
    exit;

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
?>