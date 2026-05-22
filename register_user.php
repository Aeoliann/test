<?php
session_start();
require 'db.php';
require 'logger.php'; // Система логов

// ЖЕСТКАЯ БЕЗОПАСНОСТЬ: Только для админов
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? 'manager');

    if (empty($login) || empty($password)) {
        $error = 'Пожалуйста, заполните все обязательные поля!';
    } else {
        try {
            // 1. Проверяем, не занят ли уже такой логин в базе данных
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE login = ?");
            $checkStmt->execute([$login]);
            
            if ($checkStmt->fetchColumn() > 0) {
                $error = "Пользователь с логином «{$login}» уже существует в системе!";
            } else {
                // 2. Хешируем пароль (стандарт безопасности PHP) или сохраняем как у вас настроено.
                // Если у вас в базе пароли хранятся текстом (для тестов), используйте просто $password.
                // В данном коде сохраняем ТЕКСТОМ, так как в простых CRM часто не используют password_hash, пока не попросят.
                $sql = "INSERT INTO users (login, password, role) VALUES (?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$login, $password, $role]);
                
                $newUserId = $pdo->lastInsertId();
                
                // 3. Пишем в журнал логов для истории
                logAction($pdo, 'INSERT', 'users', $newUserId, "Зарегистрировал нового пользователя: {$login} с ролью {$role}");
                
                $message = "Пользователь «{$login}» успешно создан и готов к работе!";
            }
        } catch (Exception $e) {
            $error = 'Ошибка базы данных: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Регистрация сотрудников - Santeks CRM</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { background: #151521; color: #fff; font-family: sans-serif; padding: 30px; margin: 0; display: flex; gap: 30px; }
        .form-container { background: #1e1e2d; padding: 25px; border-radius: 12px; border: 1px solid #323248; width: 400px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); text-align: left; height: fit-content; }
        input, select { width: 100%; padding: 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; box-sizing: border-box; outline: none; margin-bottom: 15px; font-size: 13px; }
        button { width: 100%; padding: 11px; background: #10b981; color: #fff; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 13px; transition: background 0.15s; }
        button:hover { background: #059669; }
        .alert-success { background: rgba(16, 185, 129, 0.15); color: #10b981; padding: 12px; border-radius: 6px; border: 1px solid rgba(16, 185, 129, 0.3); margin-bottom: 15px; font-size: 13px; }
        .alert-danger { background: rgba(245, 101, 101, 0.15); color: #f56565; padding: 12px; border-radius: 6px; border: 1px solid rgba(245, 101, 101, 0.3); margin-bottom: 15px; font-size: 13px; }
    </style>
</head>
<body>

    <!-- ПОДКЛЮЧАЕМ НАШ ЛЮБИМЫЙ САЙДБАР СЛЕВА -->
    <div style="flex-shrink: 0;">
        <?php include 'sidebar.php'; ?>
    </div>

    <!-- ИНТЕРФЕЙС ФОРМЫ РЕГИСТРАЦИИ -->
    <div class="form-container">
        <h3 style="margin-top: 0; font-size: 15px; color: #a855f7; text-transform: uppercase; margin-bottom: 20px; font-weight: bold; letter-spacing: 0.5px;">
            👤 Добавить сотрудника
        </h3>

        <?php if (!empty($message)): ?>
            <div class="alert-success">✓ <?= $message ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert-danger">⚠ <?= $error ?></div>
        <?php endif; ?>

        <form method="POST" action="register_user.php">
            <label style="font-size:11px; color:#92929f; display:block; margin-bottom:5px; text-transform:uppercase;">Логин (Учетная запись) *</label>
            <input type="text" name="login" required placeholder="Введите уникальный логин для входа...">

            <label style="font-size:11px; color:#92929f; display:block; margin-bottom:5px; text-transform:uppercase;">Пароль *</label>
            <input type="password" name="password" required placeholder="Задайте пароль доступа...">

            <label style="font-size:11px; color:#92929f; display:block; margin-bottom:5px; text-transform:uppercase;">Роль в системе *</label>
            <select name="role" required style="cursor: pointer;">
                <option value="manager" selected>Менеджер отдела продаж</option>
                <option value="admin">Администратор (Директор)</option>
            </select>

            <button type="submit">Создать аккаунт сотрудника</button>
        </form>
    </div>

</body>
</html>