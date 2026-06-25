<?php
session_start();
require 'db.php';

// замените старое чтение php://input во всех обработчиках на эту строчку:
$data = !empty($_post) ? $_post : ($globals['__json_cache__'] ?? json_decode(file_get_contents('php://input'), true));
$login = $data['login'] ?? '';
$pass = $data['password'] ?? '';

$stmt = $pdo->prepare("SELECT * FROM users WHERE login = ?");
$stmt->execute([$login]);
$user = $stmt->fetch();

// Теперь мы сравниваем просто текст из базы с текстом из формы
if ($user && $pass === $user['password']) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];
    
    // ВШИВАЕМ ЗАПИСЬ В ЖУРНАЛ АУДИТА:
    // Функция из db.php сама возьмет только что созданный $_SESSION['user_id']
    logAction('AUTH', 'users', "Пользователь '{$user['login']}' успешно авторизовался в системе");
    
    echo json_encode(['status' => 'success']);
} else {
    // НЕОБЯЗАТЕЛЬНО, НО ПОЛЕЗНО: Логируем неудачную попытку входа для безопасности
    if (function_exists('logAction')) {
        logAction('AUTH', 'users', "Неудачная попытка входа под логином: '" . htmlspecialchars($login) . "'");
    }
    
    echo json_encode(['status' => 'error', 'message' => 'Неверный логин или пароль']);
}
?>
