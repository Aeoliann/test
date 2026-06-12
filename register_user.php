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

try {
    // Вытягиваем всех сотрудников из базы данных, сортируя их по логину
    $stmtUsers = $pdo->query("SELECT id, login, role FROM users ORDER BY login ASC");
    $allCrmUsers = $stmtUsers->fetchAll();
} catch (Exception $e) {
    $allCrmUsers = [];
}?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Регистрация сотрудников - Santeks CRM</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Добавь эти правила внутрь тега <style> в шапке страницы: */

/* Двухколоночный контейнер для формы и таблицы */
.dashboard-row {
    display: flex;
    gap: 24px;
    width: 100%;
    align-items: flex-start;
    box-sizing: border-box;
}

/* Стилизация компактной таблицы пользователей */
.user-list-card {
    background: #1e1e2d;
    padding: 25px;
    border-radius: 16px;
    border: 1px solid #323248;
    flex: 1; /* Таблица займет всё оставшееся пространство справа */
    box-shadow: 0 20px 40px rgba(0,0,0,0.5);
    box-sizing: border-box;
}

.u-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
    font-size: 13px;
}
.u-table th {
    background: #161624;
    color: #7f7f9c;
    padding: 12px;
    text-transform: uppercase;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #323248;
    text-align: left;
}
.u-table td {
    padding: 12px;
    border-bottom: 1px solid #1c1c28;
    color: #cbd5e1;
    text-align: left;
}
.u-table tr:hover td {
    background: #171725;
}
        /* БАЗОВАЯ ГИБКАЯ СЕТКА И СИСТЕМНЫЕ ШРИФТЫ */
        body { background: #13131a; color: #fff; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; padding: 30px; margin: 0; display: flex; gap: 30px; box-sizing: border-box; min-height: 100vh; }
        aside { width: 250px; flex-shrink: 0; }
        
        /* ПРЕМИАЛЬНЫЙ КОНТЕЙНЕР ФОРМЫ */
        .form-container { background: #1e1e2d; padding: 30px; border-radius: 16px; border: 1px solid #323248; width: 420px; box-shadow: 0 20px 40px rgba(0,0,0,0.5); text-align: left; height: fit-content; box-sizing: border-box; }
        
        /* ИНТЕРАКТИВНЫЕ ИНПУТЫ С НЕОНОВОЙ ПОДСВЕТКОЙ ПРИ КЛИКЕ */
        input, select { width: 100%; height: 42px; padding: 0 14px; background: #151521; border: 1px solid #323248; color: #cbd5e1; border-radius: 8px; box-sizing: border-box; outline: none; margin-bottom: 16px; font-size: 13px; transition: all 0.15s ease-in-out; }
        input:focus, select:focus { border-color: #4f46e5; background: #191926; color: #ffffff; box-shadow: 0 0 10px rgba(79, 70, 229, 0.15); }
        
        /* Устранение бага отображения календаря в тёмной теме */
        input[type="date"] { color-scheme: dark; font-weight: bold; }
        
        /* КНОПКА ОТПРАВКИ С ЭФФЕКТОМ ПЛАВНОГО НАЖАТИЯ */
        button { width: 100%; height: 42px; padding: 0; background: #10b981; color: #fff; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 13px; letter-spacing: 0.3px; text-transform: uppercase; transition: all 0.15s ease; box-sizing: border-box; }
        button:hover { background: #059669; transform: translateY(-1px); box-shadow: 0 5px 15px rgba(16, 185, 129, 0.2); }
        button:active { transform: translateY(0); }
        
        /* СОЧНЫЕ СИСТЕМНЫЕ ПАРАМЕТРЫ ПРЕДУПРЕЖДЕНИЙ */
        .alert-success { background: rgba(16, 185, 129, 0.08); color: #10b981; padding: 14px; border-radius: 8px; border: 1px solid rgba(16, 185, 129, 0.2); margin-bottom: 16px; font-size: 13px; font-weight: 600; line-height: 1.4; }
        .alert-danger { background: rgba(239, 68, 68, 0.08); color: #ef4444; padding: 14px; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.2); margin-bottom: 16px; font-size: 13px; font-weight: 600; line-height: 1.4; }
        
        /* Красивые подписи полей */
        label { display: block; font-size: 11px; color: #92929f; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
    </style>
</head>
<body>

    <!-- ПОДКЛЮЧАЕМ НАШ НАДЁЖНЫЙ САЙДБАР СЛЕВА -->
    <aside style="flex-shrink: 0;">
        <?php include 'sidebar.php'; ?>
    </aside>

    <!-- ГЛАВНАЯ РАБОЧАЯ ОБЛАСТЬ СТРАНИЦЫ -->
    <main style="flex: 1; min-width: 0; padding: 30px; box-sizing: border-box; display: flex; flex-direction: column; gap: 20px;">
        
        <!-- РОСКОШНЫЙ ТОПБАР СТРАНИЦЫ (В ЕДИНОМ СТИЛЕ SANTEKS) -->
        <header style="width: 100%; display: flex; align-items: center; justify-content: space-between; padding: 16px 24px; background: #1e1e2d; border-bottom: 1px solid #323248; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); box-sizing: border-box;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <span style="font-size: 20px;">➕</span>
                <h1 style="margin: 0; font-size: 18px; font-weight: 700; color: #ffffff; letter-spacing: 0.3px;">
                    Управление персоналом и сотрудниками
                </h1>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 13px; color: #92929f; font-weight: 500;">Вы:</span>
                <span style="background: rgba(168, 85, 247, 0.12); color: #a855f7; border: 1px solid rgba(168, 85, 247, 0.25); padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase;">
                    👤 <?= htmlspecialchars($_SESSION['login'] ?? 'admin') ?>
                </span>
            </div>
        </header>

     
    <!-- ДВУХКОЛОНОЧНЫЙ ДАШБОРД -->
        <div class="dashboard-row">
            
            <!-- КОЛОНКА 1: ФОРМА РЕГИСТРАЦИИ (ЛЕВАЯ СТОРОНА) -->
            <div class="form-container" style="flex-shrink: 0;">
                <h3 style="margin-top: 0; font-size: 14px; color: #a855f7; text-transform: uppercase; margin-bottom: 25px; font-weight: bold; letter-spacing: 0.5px;">
                    👤 Добавить сотрудника
                </h3>

                <?php if (!empty($message)): ?>
                    <div class="alert-success">✓ <?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="alert-danger">⚠ <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="register_user.php" style="margin: 0; padding: 0;">
                    <label>Логин (Учетная запись) *</label>
                    <input type="text" name="login" required placeholder="Введите уникальный логин для входа...">

                    <label>Пароль *</label>
                    <input type="password" name="password" required placeholder="Задайте пароль доступа...">

                    <label>Роль в системе *</label>
                    <select name="role" required>
                        <option value="manager" selected>Менеджер отдела продаж</option>
                        <option value="admin">Администратор (Директор)</option>
                    </select>

                    <button type="submit">Создать аккаунт сотрудника</button>
                </form>
            </div>

            <!-- КОЛОНКА 2: СПИСОК АКТИВНЫХ СОТРУДНИКОВ (ЗАПОЛНЯЕТ ПУСТОТУ СПРАВА) -->
            <div class="user-list-card">
                <h3 style="margin-top: 0; font-size: 14px; color: #10b981; text-transform: uppercase; margin-bottom: 25px; font-weight: bold; letter-spacing: 0.5px;">
                    📋 Действующий персонал системы
                </h3>
                
                <table class="u-table">
                    <thead>
                        <tr>
                            <th style="width: 60px; text-align: center;">ID</th>
                            <th>Имя пользователя (Логин)</th>
                            <th style="width: 220px; text-align: center;">Уровень доступа / Роль</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allCrmUsers as $userItem): 
                            $uRole = $userItem['role'];
                            // Красивые неоновые бейджи ролей в общем стиле СРМ
                            $roleBadge = "background: rgba(129, 140, 248, 0.1); color: #818cf8; border: 1px solid rgba(129, 140, 248, 0.2);";
                            $roleText = "Менеджер";
                            
                            if ($uRole === 'admin') {
                                $roleBadge = "background: rgba(168, 85, 247, 0.1); color: #a855f7; border: 1px solid rgba(168, 85, 247, 0.2);";
                                $roleText = "Администратор";
                            }
                        ?>
                        <tr>
                            <td style="text-align: center; color: #52526b; font-family: monospace; font-weight: bold;">
                                #<?= (int)$userItem['id'] ?>
                            </td>
                            <td style="color: #ffffff; font-weight: 600;">
                                👤 <?= htmlspecialchars($userItem['login']) ?>
                            </td>
                            <td style="text-align: center;">
                                <span style="<?= $roleBadge ?> padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 800; display: inline-block; letter-spacing: 0.5px; text-transform: uppercase;">
                                    <?= $roleText ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($allCrmUsers)): ?>
                            <tr><td colspan="3" style="text-align: center; color: #64748b; padding: 20px;">Пользователи не найдены</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div> <!-- Закрытие .dashboard-row -->
    </main>

</body>
</html>