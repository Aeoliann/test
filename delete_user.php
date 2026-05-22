    <?php
session_start();
require 'db.php';
header('Content-Type: application/json');

// 1. Проверка прав: только админ может удалять людей
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die(json_encode(['status' => 'error', 'message' => 'Доступ запрещен']));
}

$data = json_decode(file_get_contents('php://input'), true);
$idToDelete = (int)($data['id'] ?? 0);

// 2. Не даем админу удалить самого себя (чтобы не заблокировать систему)
if ($idToDelete === (int)$_SESSION['user_id']) {
    die(json_encode(['status' => 'error', 'message' => 'Вы не можете удалить самого себя!']));
}

if ($idToDelete > 0) {
    try {
        // Начинаем транзакцию, чтобы всё прошло чисто
        $pdo->beginTransaction();

        // 3. Сначала отвязываем всех клиентов от этого менеджера (чтобы не сработали foreign keys)
        $stmtUpdate = $pdo->prepare("UPDATE clients SET manager_id = NULL WHERE manager_id = ?");
        $stmtUpdate->execute([$idToDelete]);

        // 4. Теперь удаляем самого пользователя
        $stmtDelete = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmtDelete->execute([$idToDelete]);

        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Ошибка БД: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'ID не передан']);
}