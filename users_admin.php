<?php
session_start();
require 'db.php';

// Проверка: пускаем только админа
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Доступ запрещен. Только для администраторов.");
}

// 1. Получаем список всех пользователей и сумму их сделок
$sql = "SELECT u.id, u.login, u.role, u.full_name, u.password,
        (SELECT SUM(p.amount) FROM projects p 
         JOIN clients c ON p.client_id = c.id 
         WHERE c.manager_id = u.id) as total_sales
        FROM users u";
$stmt = $pdo->query($sql);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Управление персоналом</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .user-card { background: white; padding: 15px; margin-bottom: 10px; border-radius: 8px; border: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
        .stats-badge { background: #dcfce7; color: #166534; padding: 5px 10px; border-radius: 20px; font-weight: bold; }
        .btn-save-user { background: #4f46e5; color: white; border: none; padding: 8px 15px; border-radius: 5px; cursor: pointer; }
    </style>
</head>
<body>
    <aside>
        <div class="logo">Santeks CRM</div>
        <nav>
            <a href="index.php" class="nav-item">Таблица лидов</a>
            <a href="contracts.php" class="nav-item">Договоры</a>
            <a href="users_admin.php" class="nav-item">Персонал</a>
            <hr>
            <a href="logout.php" class="nav-item">Выйти</a>
        </nav>
    </aside>

    <main>
        <header>
            
            <h2>Управление сотрудниками</h2>
            <button class="btn-save-user" onclick="addUser()">+ Добавить сотрудника</button>
        </header>   

        <div style="padding: 20px;">
            <h3>Список сотрудников</h3>
            <div id="usersList">
                <?php foreach($users as $u): ?>
                <div class="user-card" data-id="<?= $u['id'] ?>">
                    <div>
                        <strong><   ?= htmlspecialchars($u['full_name'] ?: 'Без имени') ?></strong> 
                        (Логин: <span class="editable" contenteditable="true" data-f="login"><?= $u['login'] ?></span>)
                        <br>
                        Пароль: <span class="editable" contenteditable="true" data-f="password"><?= $u['password'] ?></span>
                    </div>
                    <div style="display: flex; gap: 20px; align-items: center;">
                        <div class="stats-badge"><?= number_format($u['total_sales'] ?? 0, 2) ?> BYN</div>
                        <select onchange="updateUser(<?= $u['id'] ?>, 'role', this.value)">
                            <option value="manager" <?= $u['role']=='manager'?'selected':'' ?>>Менеджер</option>
                            <option value="admin" <?= $u['role']=='admin'?'selected':'' ?>>Админ</option>
                        </select>
                        <button onclick="deleteUser(<?= $u['id'] ?>)" style="color:red; background:none; border:none; cursor:pointer;">✖</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>

    <script>
        async function updateUser(id, field, value) {
            await fetch('update_user.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ id, field, value })
            });
        }

        async function addUser() {
            const login = prompt("Введите логин нового сотрудника:");
            if(!login) return;
            const res = await fetch('add_user.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ login })
            });
            location.reload();
        }

        async function deleteUser(id) {
            if(confirm("Удалить сотрудника? Все его клиенты останутся без менеджера!")) {
                await fetch('delete_user.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ id })
                });
                location.reload();
            }
        }

        
        
        
        async function updateUser(id) { 
                    await fetch('add_user.php') { 
                        method: 'UPDATE',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ id })
                    }
                
        }

        // Автосохранение правок (логин, пароль)
        document.addEventListener('blur', (e) => {
            if(e.target.classList.contains('editable')) {
                const id = e.target.closest('.user-card').dataset.id;
                const field = e.target.dataset.f;
                updateUser(id, field, e.target.innerText);
            }
        }, true);
    </script>
</body>
</html>