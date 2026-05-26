<?php
session_start();
require 'db.php';

// ЖЕСТКАЯ БЕЗОПАСНОСТЬ: Только для админов
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// Фильтр по пользователю
$filterUser = isset($_GET['user_filter']) ? trim($_GET['user_filter']) : '';
$params = [];

$sql = "SELECT al.*, u.login 
        FROM action_logs al 
        LEFT JOIN users u ON al.user_id = u.id 
        WHERE 1=1";

if (!empty($filterUser)) {
    // ИСПРАВЛЕНО: Меняем линуксовый username на правильное поле u.login
    $sql .= " AND u.login = ?";
    $params[] = $filterUser;
}

// Завершаем сборку запроса
       
if (!empty($filterUser)) {
    $sql .= " AND username = ?";
    $params[] = $filterUser;
}
$sql .= " ORDER BY id DESC LIMIT 500"; // Выводим последние 500 действий

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Список всех пользователей для фильтра
// ИСПРАВЛЕНО: Заменили линуксовый username на login под Windows
$usersStmt = $pdo->query("SELECT DISTINCT u.id, u.login FROM users u INNER JOIN action_logs al ON u.id = al.user_id ORDER BY u.login ASC");

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Лог активности пользователей - Santeks CRM</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { background: #151521; color: #fff; font-family: sans-serif; padding: 30px; margin:0; }
        .log-container { background: #1e1e2d; padding: 25px; border-radius: 16px; border: 1px solid #323248; box-shadow: 0 10px 35px rgba(0,0,0,0.3); }
        .log-table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; }
        .log-table th { background: #242434; color: #92929f; padding: 12px; border: 1px solid #2b2b40; text-transform: uppercase; font-size: 11px; }
        .log-table td { padding: 12px; border: 1px solid #2b2b40; color: #e2e8f0; }
        .log-table tr:hover { background: #222235; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .badge-insert { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .badge-update { background: rgba(246, 173, 85, 0.15); color: #f6ad55; }
        .badge-delete { background: rgba(245, 101, 101, 0.15); color: #f56565; }
    </style>
</head>
<body>

   
      
      
              <aside>
        <?php include 'sidebar.php'; ?>
        </aside>
        </form>
    </div>
        <!-- ФИЛЬТР ПОЛЬЗОВАТЕЛЕЙ -->
        <form method="GET" style="display:flex; gap:10px; align-items:center;">
            <select name="user_filter" onchange="this.form.submit()" style="padding: 8px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; cursor:pointer;">
                <option value="">Все пользователи</option>
                <?php foreach($usersList as $u): ?>
                    <option value="<?= htmlspecialchars($u) ?>" <?= $filterUser === $u ? 'selected' : '' ?>><?= htmlspecialchars($u) ?></option>
                <?php endforeach; ?>
            </select>
    <div class="log-container" >
          <h2 style="margin: 0; font-size: 18px;">📋 Журнал аудита действий пользователей</h2>
        <table class="tasks-table" style="width: 100%; border-collapse: collapse; margin: 0;">
            <thead>
                <tr>
                    <th style="width: 150px;">Дата / Время</th>
                    <th style="width: 150px;">Пользователь</th>
                    <th style="width: 100px; text-align:center;">Операция</th>
                    <th style="width: 120px;">Таблица</th>
                    <th>Описание действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $l): 
                    $badgeClass = 'badge-update';
                    if($l['action_type'] === 'INSERT') $badgeClass = 'badge-insert';
                    if($l['action_type'] === 'DELETE') $badgeClass = 'badge-delete';
                ?>
                <tr>
                    <td style="color:#92929f;"><?= date('d.m.Y H:i:s', strtotime($l['action_date'])) ?></td>
                    <td><strong><?= htmlspecialchars($l['login']) ?></strong></td>
                    <td style="text-align:center;"><span class="badge <?= $badgeClass ?>"><?= $l['action_type'] ?></span></td>
                    <td style="color:#94a3b8;"><?= htmlspecialchars($l['table_name']) ?></td>
                    <td><?= htmlspecialchars($l['details']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($logs)): ?>
                    <tr><td colspan="5" style="text-align:center; color:#666;">Журнал логов пока пуст</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</body>
</html>

<style>
    .log-container { 
        max-height: 550px;
         overflow-y: auto; 
         border: 1px solid #323248;
          border-radius: 8px; 
          background: #1e1e2d;
           box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    }
</style>