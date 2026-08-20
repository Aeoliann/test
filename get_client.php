<?php
// get_client.php — Всеядный API-контроллер карточки клиента и мульти-контактов CRM Santeks
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

// Жестко отключаем вывод любых PHP-предупреждений в поток, чтобы не сломать JSON-структуру
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');
if (ob_get_length()) ob_clean(); // Хирургическая очистка буфера от случайных пробелов

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Ошибка авторизации сессии.");
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        throw new Exception("Некорректный системный ID клиента.");
    }

    // 1. Извлекаем основные параметры компании из таблицы clients
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        throw new Exception("Запрашиваемый клиент не найден в базе данных.");
    }

    // Инициализируем пустой массив контактов на случай сбоя
    $client['contacts'] = [];

    // 2. ИЗОЛИРОВАННЫЙ ДОБОР ДОПОЛНИТЕЛЬНЫХ КОНТАКТОВ
    try {
        $sql_contacts = "SELECT 
                            name, 
                            contact_role AS position, 
                            phone, 
                            email, 
                            postal_address, 
                            function_notes 
                         FROM client_contacts 
                         WHERE client_id = ? 
                         ORDER BY id ASC";
                         
        $stmtContacts = $pdo->prepare($sql_contacts);
        $stmtContacts->execute([$id]);
        $fetched = $stmtContacts->fetchAll(PDO::FETCH_ASSOC);
        
        if (is_array($fetched)) {
            $client['contacts'] = $fetched;
        }
    } catch (Exception $subEx) {
        $client['contacts_error'] = $subEx->getMessage();
    }

    // 3. Интеллектуальный фикс даты следующего контакта (Фикс бага 104)
    if (!isset($client['next_contact_date'])) {
        $client['next_contact_date'] = $client['next_date'] ?? ($client['date_next'] ?? '');
    }

    // =========================================================================
    // НАМЕРТВО ИСПРАВЛЕНО: Интеграция главного контакта ВНУТРЬ массива ДО отправки JSON!
    // =========================================================================
    $mainPerson = trim($client['contact_person'] ?? '');
    $mainPhone  = trim($client['phone'] ?? '');
    $mainEmail  = trim($client['email'] ?? '');

    if (!empty($mainPerson) || !empty($mainPhone) || !empty($mainEmail)) {
        $mainContactObject = [
            'name'           => !empty($mainPerson) ? $mainPerson : 'Главный контакт',
            'position'       => 'Основное лицо компании', // Задаем красивый статус плашки
            'phone'          => $mainPhone,
            'email'          => $mainEmail,
            'postal_address' => '',
            'function_notes' => 'Основной контакт из карточки клиента',
            'is_main'        => 1 // Маркер, что это главный контакт (чтобы запретить его удаление)
        ];
        
        // Вставляем главный контакт в самое начало массива contacts
        array_unshift($client['contacts'], $mainContactObject);
    } 
    // =========================================================================

    // Возвращаем фронтенду идеальный валидный JSON
    echo json_encode(['status' => 'success', 'data' => $client]);
    exit;

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
?>
<?php
// get_client.php — возвращает полные данные клиента для карточки
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

header('Content-Type: application/json');
if (ob_get_length()) ob_clean();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Не авторизован']);
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Некорректный ID']);
    exit;
}

try {
    // 1. Основные данные клиента
    $stmt = $pdo->prepare("SELECT c.*, u.login AS manager_name 
                           FROM clients c 
                           LEFT JOIN users u ON c.manager_id = u.id 
                           WHERE c.id = ?");
    $stmt->execute([$id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$client) {
        echo json_encode(['status' => 'error', 'message' => 'Клиент не найден']);
        exit;
    }

    // 2. Контактные лица
    $stmt = $pdo->prepare("SELECT name, position, phone, email, postal_address, function_notes 
                           FROM client_contacts WHERE client_id = ? ORDER BY id");
    $stmt->execute([$id]);
    $client['contacts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Договоры с суммой отгрузок по каждому
    $sql = "SELECT p.id, p.contract_number, p.contract_date, p.product_type, p.currency,
                   COALESCE((
                       SELECT SUM(amount) FROM project_ttns WHERE project_id = p.id
                   ), 0) AS total_amount
            FROM projects p
            WHERE p.client_id = ?
            ORDER BY p.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $client['contracts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $client]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>