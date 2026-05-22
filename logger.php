<?php
// logger.php - Автоматическая система логирования действий

function logAction($pdo, $actionType, $targetTable, $recordId, $description) {
    // Безопасно проверяем, авторизован ли пользователь в сессии
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    
    // Если скрипт вызван до авторизации, пишем "Система"
    $username = 'Система';
    
    if ($userId > 0) {
        // Быстро вытягиваем имя пользователя, совершившего действие
        $stmt = $pdo->prepare("SELECT login FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $fetchedName = $stmt->fetchColumn();
        if ($fetchedName) {
            $username = $fetchedName;
        }
    }

    try {
        $logStmt = $pdo->prepare("INSERT INTO action_logs (user_id, username, action_type, target_table, record_id, description) VALUES (?, ?, ?, ?, ?, ?)");
        $logStmt->execute([$userId, $username, $actionType, $targetTable, (int)$recordId, $description]);
    } catch (Exception $e) {
        // Консервативно глушим ошибку самого лога, чтобы из-за неё не падал основной функционал CRM
        error_log("Ошибка логирования: " . $e->getMessage());
    }
}
?>