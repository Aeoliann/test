<?php
// get_client.php — API-контроллер карточки клиента и мульти-контактов 
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'db.php';

header('Content-Type: application/json');
if (ob_get_length()) ob_clean();

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Ошибка авторизации сессии.");
    }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        throw new Exception("Некорректный системный ID клиента.");
    }

    // 1. Основные данные клиента + логин менеджера
    $stmt = $pdo->prepare("
        SELECT c.*, u.login AS manager_name
        FROM clients c
        LEFT JOIN users u ON c.manager_id = u.id
        WHERE c.id = ?
    ");
    $stmt->execute([$id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        throw new Exception("Запрашиваемый клиент не найден в базе данных.");
    }

    $client['contacts'] = [];

    // 2. Дополнительные контакты
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

    // 3. Фолбэк для даты следующего контакта
    if (!isset($client['next_contact_date'])) {
        $client['next_contact_date'] = $client['next_date'] ?? ($client['date_next'] ?? '');
    }

    // 4. Вставляем главный контакт в массив contacts
    $mainPerson = trim($client['contact_person'] ?? '');
    $mainPhone  = trim($client['phone'] ?? '');
    $mainEmail  = trim($client['email'] ?? '');

    if (!empty($mainPerson) || !empty($mainPhone) || !empty($mainEmail)) {
        $mainContactObject = [
            'name'           => $mainPerson !== '' ? $mainPerson : 'Главный контакт',
            'position'       => 'Основное лицо компании',
            'phone'          => $mainPhone,
            'email'          => $mainEmail,
            'postal_address' => '',
            'function_notes' => 'Основной контакт из карточки клиента',
            'is_main'        => 1
        ];
        array_unshift($client['contacts'], $mainContactObject);
    }

    echo json_encode(['status' => 'success', 'data' => $client]);
    exit;

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}