<?php
session_start(); // Инициализируем сессию, чтобы иметь к ней доступ

// 1. Очищаем все переменные сессии
$_SESSION = array();

// 2. Если используются куки сессии, удаляем их (для полной очистки в браузере)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Уничтожаем сессию на сервере
session_destroy();

// 4. Перенаправляем пользователя на страницу логина
header("Location: auth.html");
exit;
?>