<?php
session_start();
require 'db.php'; // Наш главный файл, где уже крутится авто-логгер

// ЖЕСТКАЯ БЕЗОПАСНОСТЬ: Доступ только для администраторов
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// 1. ПАРСИНГ ФИЛЬТРОВ И ПОИСКА
$filterUser = isset($_GET['user_filter']) ? trim($_GET['user_filter']) : '';
$filterType = isset($_GET['type_filter']) ? trim($_GET['type_filter']) : '';
$params = [];

// 2. СБОР ОСНОВНОГО МАССИВА ЛОГОВ С УЧЕТОМ СЕЛЕКТОВ
$sql = "SELECT al.*, u.login 
        FROM action_logs al 
        LEFT JOIN users u ON al.user_id = u.id 
        WHERE 1=1";

if (!empty($filterUser)) {
    $sql .= " AND u.login = ?";
    $params[] = $filterUser;
}

if (!empty($filterType)) {
    $sql .= " AND al.action_type = ?";
    $params[] = $filterType;
}

$sql .= " ORDER BY al.id DESC LIMIT 500"; 

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// 3. ВСПОМОГАТЕЛЬНЫЕ СПИСКИ ДЛЯ ФИЛЬТРОВ (Выпадающие списки)
// Список сотрудников
$usersStmt = $pdo->query("SELECT DISTINCT u.login FROM users u INNER JOIN action_logs al ON u.id = al.user_id ORDER BY u.login ASC");
$usersList = $usersStmt->fetchAll(PDO::FETCH_COLUMN);

// Список уникальных типов операций, которые уже есть в базе
$typesStmt = $pdo->query("SELECT DISTINCT action_type FROM action_logs ORDER BY action_type ASC");
$typesList = $typesStmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Журнал аудита безопасности — Santeks CRM</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* БАЗОВАЯ МОДУЛЬНАЯ СЕТКА ИНТЕРФЕЙСА */
        body { 
            background: #151521; 
            color: #fff; 
            font-family: 'Segoe UI', Roboto, sans-serif; 
            padding: 30px; 
            margin: 0; 
            display: flex; 
            box-sizing: border-box; 
            min-height: 100vh;
        }
        
        /* Фиксированный контейнер под ваш sidebar.php */
        aside { 
            width: 260px; 
            flex-shrink: 0; 
        }
        
        /* Главная рабочая область */
        .main-content { 
            flex: 1; 
            padding-left: 30px; 
            box-sizing: border-box; 
            display: flex; 
            flex-direction: column; 
            min-width: 0; 
        }
        
        /* ШАПКА ФИЛЬТРОВ СЕЛЕКТА */
        .filter-bar {
            display: flex; 
            gap: 20px; 
            align-items: center; 
            margin-bottom: 20px;
            background: #1e1e2d;
            padding: 15px 25px;
            border-radius: 12px;
            border: 1px solid #2b2b40;
        }
        
        .filter-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-group label {
            font-size: 13px; 
            color: #92929f; 
            font-weight: 600;
        }

        .filter-select {
            padding: 8px 14px; 
            background: #151521; 
            border: 1px solid #323248; 
            color: #fff; 
            border-radius: 8px; 
            cursor: pointer; 
            font-size: 13px;
            outline: none;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        .filter-select:hover { border-color: #4f46e5; background: #242434; }
        
        /* ОСНОВНОЙ КОНТЕЙНЕР ЖУРНАЛА */
        .log-container { 
            background: #1e1e2d; 
            padding: 30px; 
            border-radius: 16px; 
            border: 1px solid #323248; 
            box-shadow: 0 15px 40px rgba(0,0,0,0.4); 
            box-sizing: border-box;
            width: 100%;
            display: flex;
            flex-direction: column;
        }
        
        /* СКРОЛЛБАР ТАБЛИЦЫ С СИСТЕМОЙ ЗАЩИТЫ ВЕРСТКИ */
        .table-scroll {
            max-height: 700px;
            overflow-y: auto;
            border: 1px solid #2b2b40;
            border-radius: 10px;
            background: #151521;
            margin-top: 20px;
            width: 100%;
            box-sizing: border-box;
        }
        
        /* Тюнинг кастомного неонового скроллбара */
        .table-scroll::-webkit-scrollbar { width: 8px; }
        .table-scroll::-webkit-scrollbar-track { background: #151521; border-top-right-radius: 10px; border-bottom-right-radius: 10px; }
        .table-scroll::-webkit-scrollbar-thumb { background: #2b2b40; border-radius: 4px; }
        .table-scroll::-webkit-scrollbar-thumb:hover { background: #4f46e5; }

        /* ТАБЛИЦА С ЖЕСТКОЙ КЛЕТОЧНОЙ ФИКСАЦИЕЙ */
        .log-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; text-align: left; table-layout: fixed; }
        .log-table th { 
            background: #242434; 
            color: #92929f; 
            padding: 16px 14px; 
            border-bottom: 2px solid #323248; 
            text-transform: uppercase; 
            font-size: 11px; 
            font-weight: 700;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
            z-index: 5;
        }
        .log-table td { padding: 14px 12px; border-bottom: 1px solid #2b2b40; color: #cbd5e1; box-sizing: border-box; word-break: break-word; vertical-align: middle; }
        .log-table tr:last-child td { border-bottom: none; }
        .log-table tr:hover td { background: #222235; color: #fff; }
        
        /* НЕОНОВЫЕ ПОЛУПРОЗРАЧНЫЕ МАРКЕРЫ (БЕЙДЖИ) ОПЕРАЦИЙ */
        .badge { 
            padding: 5px 10px; 
            border-radius: 6px; 
            font-size: 10px; 
            font-weight: 800; 
            letter-spacing: 0.5px;
            display: inline-block;
            text-align: center;
            min-width: 75px;
            box-sizing: border-box;
            text-transform: uppercase;
        }
        .badge-insert { background: rgba(16, 185, 129, 0.12); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.25); }
        .badge-update { background: rgba(245, 158, 11, 0.12); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.25); }
        .badge-delete { background: rgba(239, 68, 68, 0.12); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.25); }
        .badge-auth   { background: rgba(99, 102, 241, 0.12); color: #818cf8; border: 1px solid rgba(99, 102, 241, 0.25); }
        .badge-system { background: rgba(14, 165, 233, 0.12); color: #38bdf8; border: 1px solid rgba(14, 165, 233, 0.25); }
    </style>
</head>
<body>
   
    <!-- ИНТЕГРАЦИЯ СЛЕПОЙ ЗОНЫ САЙДБАРА -->

        <?php include 'sidebar.php'; ?>
  
    <div class="main-content">
        <?php
// Этот блок можно вставить в sidebar.php или любой другой файл, где подключен db.php

try {
    // Вытаскиваем пользователей, чья активность была в течение последних 5 минут
    $onlineStmt = $pdo->query("
        SELECT login, role, last_activity 
        FROM users 
        WHERE last_activity >= NOW() - INTERVAL 5 MINUTE 
        ORDER BY login ASC
    ");
    $onlineUsers = $onlineStmt->fetchAll();
} catch (Exception $e) {
    $onlineUsers = [];
}
?>

<!-- Визуальный блок в стиле вашей темной темы CRM -->
<div class="online-widget" style="background: #1e1e2d; border: 1px solid #323248; padding: 15px; border-radius: 10px; margin-top: 15px;">
    <h4 style="margin: 0 0 10px 0; font-size: 11px; text-transform: uppercase; color: #92929f; letter-spacing: 0.5px; display: flex; align-items: center; gap: 6px;">
        <span style="width: 8px; height: 8px; background: #10b981; border-radius: 50%; display: inline-block; box-shadow: 0 0 8px #10b981;"></span> 
        Менеджеры онлайн (<?= count($onlineUsers) ?>)
    </h4>
    
    <ul style="list-style: none; padding: 0; margin: 0; font-size: 13px;">
        <?php if (empty($onlineUsers)): ?>
            <li style="color: #707084; font-style: italic; font-size: 12px;">В системе никого нет</li>
        <?php else: ?>
            <?php foreach ($onlineUsers as $ou): ?>
                <li style="padding: 4px 0; display: flex; justify-content: space-between; align-items: center;">
                    <span style="color: #fff; font-weight: 600;">👤 <?= htmlspecialchars($ou['login']) ?></span>
                    <span style="font-size: 10px; background: rgba(99, 102, 241, 0.15); color: #818cf8; padding: 2px 6px; border-radius: 4px; font-weight: bold; text-transform: uppercase;">
                        <?= htmlspecialchars($ou['role']) ?>
                    </span>
                </li>
            <?php endforeach; ?>
        <?php endif; ?>
    </ul>
</div>
        <!-- УПРАВЛЕНИЕ АУДИТОМ И СЕЛЕКТЫ ФИЛЬТРАЦИИ -->
        <form method="GET" class="filter-bar">
            <div class="filter-group">
                <label>👤 Сотрудник в системе:</label>
                <select name="user_filter" onchange="this.form.submit()" class="filter-select">
                    <option value="">👤 Все пользователи базы</option>
                    <?php foreach($usersList as $u): ?>
                        <option value="<?= htmlspecialchars($u) ?>" <?= $filterUser === $u ? 'selected' : '' ?>><?= htmlspecialchars($u) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>⚡ Тип действия:</label>
                <select name="type_filter" onchange="this.form.submit()" class="filter-select">
                    <option value="">⚡ Все типы операций</option>
                    <?php foreach($typesList as $t): ?>
                        <option value="<?= htmlspecialchars($t) ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
        
        <!-- ЖУРНАЛ АУДИТА С СЕТКОЙ ИЗ ВАШЕГО ИНТЕРФЕЙСА -->
        <div class="log-container">
            <h2 style="margin: 0; font-size: 18px; font-weight: bold; letter-spacing: 0.3px; display: flex; align-items: center; gap: 10px;">
                📋 Журнал системного аудита безопасности
            </h2>
            
            <div class="table-scroll">
                <table class="log-table">
                    <thead>
                        <tr>
                            <th style="width: 150px; text-align: center;">Дата / Время</th>
                            <th style="width: 140px;">Пользователь</th>
                            <th style="width: 105px; text-align: center;">Операция</th>
                            <th style="width: 130px;">Таблица БД</th>
                            <th>Детализированное описание совершенного действия</th>
                        </tr>
                    </thead>
              <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: #707084; padding: 30px;">
                                    Логи по выбранным критериям фильтрации отсутствуют в СУБД.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $l): 
                                $rawAction = strtoupper($l['action_type'] ?? ''); 
                                
                                // Автоматический подбор класса под типы событий
                                $badgeClass = 'badge-system';
                                if ($rawAction === 'INSERT') $badgeClass = 'badge-insert';
                                if ($rawAction === 'UPDATE') $badgeClass = 'badge-update';
                                if ($rawAction === 'DELETE') $badgeClass = 'badge-delete';
                                if ($rawAction === 'AUTH')   $badgeClass = 'badge-auth';
                            ?>
                            <tr>
                                <!-- Время операции -->
                                <td style="color: #707084; text-align: center; font-family: monospace; font-size: 12px;">
                                    <?= !empty($l['action_date']) ? date('d.m.Y H:i:s', strtotime($l['action_date'])) : '—' ?>
                                </td>
                                
                                <!-- Подсветка пользователя -->
                                <td style="color: #a855f7; font-weight: bold;">
                                    👤 <?= htmlspecialchars($l['login'] ?? 'Система') ?>
                                </td>
                                
                                <!-- Бейдж операции -->
                                <td style="text-align: center;">
                                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($rawAction) ?></span>
                                </td>

                                <!-- Целевая таблица БД -->
                                <td style="color: #eab308; font-family: monospace; font-size: 12px; font-weight: 600;">
                                    📁 <?= htmlspecialchars($l['table_name'] ?? 'system') ?>
                                </td>
                                
                                <!-- ИСПРАВЛЕНО: Чистый вывод деталей без дублирования HTML-тегов -->
                                <td style="color: #cbd5e1; font-size: 13px; line-height: 1.4;">
                                    <?= htmlspecialchars($l['details'] ?? '') ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
    </div>
</body>
</html>