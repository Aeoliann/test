<?php
// db.php — Подключение к СУБД MariaDB и общие функции безопасности
$host = 'localhost';
$db   = 'crm_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

/**
 * Универсальная функция логов безопасности
 */
function logAction($pdo, $actionType, $tableName, $details) {
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    try {
        $sql = "INSERT INTO action_logs (user_id, action_type, table_name, details, action_date) 
                VALUES (?, ?, ?, ?, NOW())";
        $pdo->prepare($sql)->execute([$userId, strtoupper($actionType), $tableName, $details]);
    } catch (Exception $e) {
        error_log("Ошибка логгера CRM: " . $e->getMessage());
    }
}
?>
