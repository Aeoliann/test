<?php
// activity_logs.php — Журнал аудита с фильтрами, поиском по дате и экспортом в CSV
session_start();
require 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// Параметры фильтров
$filterUser = isset($_GET['user_filter']) ? trim($_GET['user_filter']) : '';
$filterType = isset($_GET['type_filter']) ? trim($_GET['type_filter']) : '';
$date_from  = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to    = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$export = isset($_GET['export']) ? (int)$_GET['export'] : 0;

$params = [];
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
if (!empty($date_from)) {
    $sql .= " AND DATE(al.action_date) >= ?";
    $params[] = $date_from;
}
if (!empty($date_to)) {
    $sql .= " AND DATE(al.action_date) <= ?";
    $params[] = $date_to;
}

$sql .= " ORDER BY al.id DESC LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Списки для фильтров (выпадающие)
$usersStmt = $pdo->query("SELECT DISTINCT u.login FROM users u INNER JOIN action_logs al ON u.id = al.user_id ORDER BY u.login ASC");
$usersList = $usersStmt->fetchAll(PDO::FETCH_COLUMN);

$typesStmt = $pdo->query("SELECT DISTINCT action_type FROM action_logs ORDER BY action_type ASC");
$typesList = $typesStmt->fetchAll(PDO::FETCH_COLUMN);

// ============================================================
// ЭКСПОРТ В CSV (если параметр export = 1)
// ============================================================
if ($export === 1) {
    $sqlExport = "SELECT al.*, u.login 
                  FROM action_logs al 
                  LEFT JOIN users u ON al.user_id = u.id 
                  WHERE 1=1";
    $paramsExport = [];
    if (!empty($filterUser)) {
        $sqlExport .= " AND u.login = ?";
        $paramsExport[] = $filterUser;
    }
    if (!empty($filterType)) {
        $sqlExport .= " AND al.action_type = ?";
        $paramsExport[] = $filterType;
    }
    if (!empty($date_from)) {
        $sqlExport .= " AND DATE(al.action_date) >= ?";
        $paramsExport[] = $date_from;
    }
    if (!empty($date_to)) {
        $sqlExport .= " AND DATE(al.action_date) <= ?";
        $paramsExport[] = $date_to;
    }
    $sqlExport .= " ORDER BY u.login ASC, al.action_date DESC";
    $stmtExport = $pdo->prepare($sqlExport);
    $stmtExport->execute($paramsExport);
    $allLogs = $stmtExport->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_logs_' . date('Y-m-d_H-i') . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM

    $delimiter = ';';
    fputcsv($output, ['Пользователь', 'Дата и время', 'Тип действия', 'Таблица', 'Детали'], $delimiter);

    foreach ($allLogs as $log) {
        fputcsv($output, [
            $log['login'] ?? 'Система',
            date('d.m.Y H:i:s', strtotime($log['action_date'])),
            $log['action_type'] ?? '',
            $log['table_name'] ?? '',
            $log['details'] ?? ''
        ], $delimiter);
    }

    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Журнал аудита безопасности — Santeks CRM</title>
    <link rel="stylesheet" href="style.css">
    <style>
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
        aside { width: 260px; flex-shrink: 0; }
        .main-content {
            flex: 1;
            padding-left: 30px;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px 20px;
            align-items: center;
            background: #1e1e2d;
            padding: 12px 20px;
            border-radius: 12px;
            border: 1px solid #2b2b40;
            margin-bottom: 20px;
        }
        .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .filter-group label {
            font-size: 12px;
            color: #92929f;
            font-weight: 600;
            white-space: nowrap;
        }
        .filter-group input,
        .filter-group select {
            padding: 6px 10px;
            background: #151521;
            border: 1px solid #323248;
            color: #fff;
            border-radius: 6px;
            font-size: 12px;
            outline: none;
            height: 34px;
        }
        .filter-group input[type="date"] { color-scheme: dark; }
        .filter-group input:focus,
        .filter-group select:focus { border-color: #4f46e5; background: #242434; }
        .btn-export {
            background: #10b981;
            border: none;
            color: #fff;
            padding: 6px 16px;
            border-radius: 6px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
            height: 34px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-export:hover { background: #059669; }
        .btn-reset-filter {
            background: transparent;
            border: 1px solid #323248;
            color: #92929f;
            padding: 6px 16px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            height: 34px;
            display: inline-flex;
            align-items: center;
        }
        .btn-reset-filter:hover { background: #2a2a3f; color: #fff; }
        .log-container {
            background: #1e1e2d;
            padding: 25px;
            border-radius: 14px;
            border: 1px solid #323248;
            box-shadow: 0 15px 40px rgba(0,0,0,0.4);
            box-sizing: border-box;
            width: 100%;
            display: flex;
            flex-direction: column;
        }
        .log-container h2 {
            margin: 0;
            font-size: 18px;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 10px;
        }
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
        .table-scroll::-webkit-scrollbar { width: 8px; }
        .table-scroll::-webkit-scrollbar-track { background: #151521; border-radius: 10px; }
        .table-scroll::-webkit-scrollbar-thumb { background: #2b2b40; border-radius: 4px; }
        .table-scroll::-webkit-scrollbar-thumb:hover { background: #4f46e5; }
        .log-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 13px;
            text-align: left;
            table-layout: fixed;
        }
        .log-table th {
            background: #242434;
            color: #92929f;
            padding: 12px 12px;
            border-bottom: 2px solid #323248;
            text-transform: uppercase;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
            z-index: 5;
        }
        .log-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #2b2b40;
            color: #cbd5e1;
            box-sizing: border-box;
            word-break: break-word;
            vertical-align: middle;
        }
        .log-table tr:last-child td { border-bottom: none; }
        .log-table tr:hover td { background: #222235; color: #fff; }
        .badge {
            padding: 4px 10px;
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
        .badge-insert { background: rgba(16,185,129,0.12); color: #10b981; border:1px solid rgba(16,185,129,0.25); }
        .badge-update { background: rgba(245,158,11,0.12); color: #f59e0b; border:1px solid rgba(245,158,11,0.25); }
        .badge-delete { background: rgba(239,68,68,0.12); color: #ef4444; border:1px solid rgba(239,68,68,0.25); }
        .badge-auth   { background: rgba(99,102,241,0.12); color: #818cf8; border:1px solid rgba(99,102,241,0.25); }
        .badge-system { background: rgba(14,165,233,0.12); color: #38bdf8; border:1px solid rgba(14,165,233,0.25); }
        .online-widget {
            background: #1e1e2d;
            border: 1px solid #323248;
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 15px;
        }
        .online-widget h4 {
            margin:0;
            font-size:11px;
            text-transform:uppercase;
            color:#92929f;
            letter-spacing:0.5px;
            display:flex;
            align-items:center;
            gap:6px;
        }
        .online-widget ul {
            list-style:none;
            padding:0;
            margin:0;
            font-size:12px;
        }
        .online-widget li {
            padding:2px 0;
            display:flex;
            justify-content:space-between;
            align-items:center;
        }
        .online-widget li span.login { color:#fff; font-weight:600; }
        .online-widget li span.role {
            font-size:10px;
            background:rgba(99,102,241,0.15);
            color:#818cf8;
            padding:1px 6px;
            border-radius:4px;
            font-weight:bold;
            text-transform:uppercase;
        }
        .empty-state { text-align:center; color:#707084; padding:30px; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <!-- Онлайн-виджет -->
        <?php
        try {
            $onlineStmt = $pdo->query("SELECT login, role, last_activity FROM users WHERE last_activity >= NOW() - INTERVAL 5 MINUTE ORDER BY login ASC");
            $onlineUsers = $onlineStmt->fetchAll();
        } catch (Exception $e) {
            $onlineUsers = [];
        }
        ?>
        <div class="online-widget">
            <h4><span style="width:8px; height:8px; background:#10b981; border-radius:50%; display:inline-block; box-shadow:0 0 8px #10b981;"></span> Менеджеры онлайн (<?= count($onlineUsers) ?>)</h4>
            <ul>
                <?php if (empty($onlineUsers)): ?>
                    <li style="color:#707084; font-style:italic;">В системе никого нет</li>
                <?php else: ?>
                    <?php foreach ($onlineUsers as $ou): ?>
                        <li><span class="login">👤 <?= htmlspecialchars($ou['login']) ?></span><span class="role"><?= htmlspecialchars($ou['role']) ?></span></li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Фильтры -->
        <form method="GET" class="filter-bar">
            <div class="filter-group">
                <label>👤 Сотрудник:</label>
                <select name="user_filter" onchange="this.form.submit()">
                    <option value="">Все</option>
                    <?php foreach ($usersList as $u): ?>
                        <option value="<?= htmlspecialchars($u) ?>" <?= $filterUser === $u ? 'selected' : '' ?>><?= htmlspecialchars($u) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>⚡ Тип:</label>
                <select name="type_filter" onchange="this.form.submit()">
                    <option value="">Все</option>
                    <?php foreach ($typesList as $t): ?>
                        <option value="<?= htmlspecialchars($t) ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>📅 Дата от:</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" onchange="this.form.submit()">
            </div>
            <div class="filter-group">
                <label>до:</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" onchange="this.form.submit()">
            </div>
            <div class="filter-group" style="margin-left:auto; display:flex; gap:8px;">
                <button type="submit" class="btn-export" style="background:#4f46e5;">🔍 Применить</button>
                <a href="activity_logs.php" class="btn-reset-filter">Сбросить</a>
                <button type="submit" name="export" value="1" class="btn-export">⬇ Скачать CSV</button>
            </div>
        </form>

        <!-- Таблица логов -->
        <div class="log-container">
            <h2>📋 Журнал системного аудита</h2>
            <div class="table-scroll">
                <table class="log-table">
                    <thead>
                        <tr>
                            <th style="width:150px; text-align:center;">Дата / Время</th>
                            <th style="width:140px;">Пользователь</th>
                            <th style="width:105px; text-align:center;">Операция</th>
                            <th style="width:130px;">Таблица</th>
                            <th>Детали</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="5" class="empty-state">Логов по выбранным критериям нет</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $l): 
                                $rawAction = strtoupper($l['action_type'] ?? '');
                                $badgeClass = 'badge-system';
                                if ($rawAction === 'INSERT') $badgeClass = 'badge-insert';
                                if ($rawAction === 'UPDATE') $badgeClass = 'badge-update';
                                if ($rawAction === 'DELETE') $badgeClass = 'badge-delete';
                                if ($rawAction === 'AUTH')   $badgeClass = 'badge-auth';
                            ?>
                            <tr>
                                <td style="color:#707084; text-align:center; font-family:monospace; font-size:12px;">
                                    <?= !empty($l['action_date']) ? date('d.m.Y H:i:s', strtotime($l['action_date'])) : '—' ?>
                                </td>
                                <td style="color:#a855f7; font-weight:bold;">👤 <?= htmlspecialchars($l['login'] ?? 'Система') ?></td>
                                <td style="text-align:center;"><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($rawAction) ?></span></td>
                                <td style="color:#eab308; font-family:monospace; font-size:12px; font-weight:600;">📁 <?= htmlspecialchars($l['table_name'] ?? 'system') ?></td>
                                <td style="color:#cbd5e1; font-size:13px; line-height:1.4;"><?= htmlspecialchars($l['details'] ?? '') ?></td>
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