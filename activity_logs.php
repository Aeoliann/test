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
    $sql .= " AND u.login = ?";
    $params[] = $filterUser;
}

$sql .= " ORDER BY al.id DESC LIMIT 500"; 

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Список всех пользователей для фильтра
$usersStmt = $pdo->query("SELECT DISTINCT u.login FROM users u INNER JOIN action_logs al ON u.id = al.user_id ORDER BY u.login ASC");
$usersList = $usersStmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Лог активности пользователей - Santeks CRM</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* БАЗОВАЯ СЕТКА ЖУРНАЛА */
        body { background: #151521; color: #fff; font-family: 'Segoe UI', Roboto, sans-serif; padding: 30px; margin:0; display: flex; box-sizing: border-box; }
        aside { width: 250px; flex-shrink: 0; }
        
        .main-content { flex: 1; padding-left: 25px; box-sizing: border-box; display: flex; flex-direction: column; min-width: 0; }
        
        /* КОНТЕЙНЕР ЖУРНАЛА */
        .log-container { 
            background: #1e1e2d; 
            padding: 30px; 
            border-radius: 16px; 
            border: 1px solid #323248; 
            box-shadow: 0 15px 40px rgba(0,0,0,0.4); 
            margin-top: 15px; 
            box-sizing: border-box;
            width: 100%;
        }
        
        /* СКРОЛЛБАР ТАБЛИЦЫ С ЗАЩИТОЙ ВЕРСТКИ */
        .table-scroll {
            max-height: 650px;
            overflow-y: auto;
            border: 1px solid #2b2b40;
            border-radius: 10px;
            background: #151521;
            margin-top: 15px;
            width: 100%;
            box-sizing: border-box;
        }
        
        /* Кастомный скроллбар */
        .table-scroll::-webkit-scrollbar { width: 8px; }
        .table-scroll::-webkit-scrollbar-track { background: #151521; border-top-right-radius: 10px; border-bottom-right-radius: 10px; }
        .table-scroll::-webkit-scrollbar-thumb { background: #2b2b40; border-radius: 4px; }
        .table-scroll::-webkit-scrollbar-thumb:hover { background: #4f46e5; }

        /* ТАБЛИЦА ЛОГОВ С ЖЕСТКОЙ ФИКСАЦИЕЙ */
        .log-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; text-align: left; table-layout: fixed; }
        .log-table th { 
            background: #242434; 
            color: #92929f; 
            padding: 14px 12px; 
            border-bottom: 2px solid #323248; 
            text-transform: uppercase; 
            font-size: 11px; 
            font-weight: 700;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
            z-index: 5;
        }
        .log-table td { padding: 12px; border-bottom: 1px solid #2b2b40; color: #cbd5e1; box-sizing: border-box; word-break: break-word; }
        .log-table tr:last-child td { border-bottom: none; }
        .log-table tr:hover td { background: #222235; color: #fff; }
        
        /* СОЧНЫЕ ПОЛУПРОЗРАЧНЫЕ НЕОНОВЫЕ БЕЙДЖИ ОПЕРАЦИЙ */
        .badge { 
            padding: 5px 10px; 
            border-radius: 6px; 
            font-size: 10px; 
            font-weight: 800; 
            letter-spacing: 0.5px;
            display: inline-block;
            text-align: center;
            min-width: 65px;
            box-sizing: border-box;
            text-transform: uppercase;
        }
        .badge-insert { background: rgba(16, 185, 129, 0.12); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.25); }
        .badge-update { background: rgba(245, 158, 11, 0.12); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.25); }
        .badge-delete { background: rgba(239, 68, 68, 0.12); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.25); }
        .badge-auth   { background: rgba(99, 102, 241, 0.12); color: #818cf8; border: 1px solid rgba(99, 102, 241, 0.25); }
        
        /* СТИЛЬ СЕЛЕКТА ФИЛЬТРА */
        .filter-select {
            padding: 8px 14px; 
            background: #1e1e2d; 
            border: 1px solid #323248; 
            color: #fff; 
            border-radius: 8px; 
            cursor: pointer; 
            font-size: 13px;
            outline: none;
            font-weight: 600;
            transition: 0.15s;
        }
        .filter-select:hover { border-color: #4f46e5; background: #242434; }
    </style>
</head>
<body>

    <!-- ПОДКЛЮЧЕНИЕ САЙДБАРА -->
    <aside>
        <?php include 'sidebar.php'; ?>
    </aside>

    <div class="main-content">
        <!-- ФИЛЬТР ПОЛЬЗОВАТЕЛЕЙ -->
        <form method="GET" style="display:flex; gap:12px; align-items:center; margin-bottom: 5px;">
            <label style="font-size: 13px; color: #92929f; font-weight: bold;">Сотрудник в системе:</label>
            <select name="user_filter" onchange="this.form.submit()" class="filter-select">
                <option value="">👤 Все пользователи базы</option>
                <?php foreach($usersList as $u): ?>
                    <option value="<?= htmlspecialchars($u) ?>" <?= $filterUser === $u ? 'selected' : '' ?>><?= htmlspecialchars($u) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <!-- ЖУРНАЛ АУДИТА -->
        <div class="log-container">
            <h2 style="margin: 0; font-size: 18px; font-weight: bold; letter-spacing: 0.3px; display: flex; align-items: center; gap: 8px;">
                📋 Журнал системного аудита безопасности
            </h2>
            
            <div class="table-scroll">
                <table class="log-table">
                    <thead>
                        <tr>
                            <th style="width: 140px; text-align: center;">Дата / Время</th>
                            <th style="width: 140px;">Пользователь</th>
                            <th style="width: 95px; text-align:center;">Операция</th>
                            <th style="width: 130px;">Таблица БД</th>
                            <th>Детализированное описание совершенного действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $l): 
                            $rawAction = strtoupper($l['action_type'] ?? ''); 
                            
                            // Автоматически подбираем неоновый бейдж под тип операции
                            $badgeClass = 'badge-update';
                            if ($rawAction === 'INSERT') $badgeClass = 'badge-insert';
                            if ($rawAction === 'DELETE') $badgeClass = 'badge-delete';
                            if ($rawAction === 'AUTH')   $badgeClass = 'badge-auth';
                        ?>
                        <tr>
                            <!-- Время операции -->
                            <td style="color:#707084; text-align: center; font-family: monospace; font-size: 12px;">
                                <?= !empty($l['action_date']) ? date('d.m.Y H:i:s', strtotime($l['action_date'])) : '—' ?>
                            </td>
                            
                            <!-- Подсветка пользователя -->
                            <td style="color: #a855f7; font-weight: bold;">
                                👤 <?= htmlspecialchars($l['login'] ?? 'Система') ?>
                            </td>
                            
                            <!-- Бейдж операции -->
                            <td style="text-align:center;">
                                <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($rawAction) ?></span>
                            </td>
                            
                            <!-- Системное имя таблицы БД -->
                            <td style="color:#94a3b8; font-family: monospace; font-size: 12px; font-weight: 600;">
                                📂 <?= htmlspecialchars($l['table_name'] ?? '—') ?>
                            </td>
                            
                            <!-- Полный текст описания действия -->
                            <td style="color: #f1f5f9; white-space: normal; line-height: 1.4;">
                                <?= htmlspecialchars($l['details'] ?? '—') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($logs)): ?>
                            <tr><td colspan="5" style="text-align:center; color:#64748b; padding: 40px; font-size: 14px;">Журнал системного аудита пуст</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div> <!-- Закрытие .main-content -->

</body>
</html>
