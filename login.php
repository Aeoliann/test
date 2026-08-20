<?php
// login.php — простая авторизация с редиректом
// Включаем отображение ошибок для диагностики на хостинге
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db.php';

// Проверяем, пришли ли данные через POST
$login = trim($_POST['login'] ?? '');
$pass = trim($_POST['password'] ?? '');

if (empty($login) || empty($pass)) {
    header('Location: auth.html?error=empty');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE login = ?");
    $stmt->execute([$login]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $valid = false;
    if ($user) {
        // Проверка пароля (поддерживаем и хеш, и plain)
        if (password_verify($pass, $user['password'] ?? '')) {
            $valid = true;
        } elseif ($pass === ($user['password'] ?? '')) {
            $valid = true;
        }
    }

    if ($valid) {
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['role'] = $user['role'] ?? 'manager';
        $_SESSION['login'] = $user['login'];

        // Обновляем время активности
        if (isset($pdo)) {
            $pdo->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?")->execute([$user['id']]);
        }

        if (function_exists('logAction')) {
            logAction('AUTH', 'users', "Пользователь '{$user['login']}' успешно авторизовался");
        }

        // Редирект по роли
        $redirect = ($user['role'] === 'executor') ? 'tasks.php' : 'index.php';
        header('Location: ' . $redirect);
        exit;
    } else {
        header('Location: auth.html?error=invalid');
        exit;
    }
} catch (Exception $e) {
    // Логируем ошибку в файл
    error_log("Ошибка авторизации: " . $e->getMessage());
    // Выводим сообщение об ошибке на страницу (для отладки)
    echo "Ошибка авторизации: " . $e->getMessage();
    // Или делаем редирект с ошибкой
    // header('Location: auth.html?error=server');
    exit;
}