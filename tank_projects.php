<?php
// tank_projects.php — Канбан-доска проектов по резервуарам
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'manager';

// =========================================================================
// ОБРАБОТЧИКИ POST
// =========================================================================

// 1. СОЗДАНИЕ / РЕДАКТИРОВАНИЕ ПРОЕКТА
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_tank_project') {
    header('Content-Type: application/json');

    $projectId  = (int)($_POST['project_id'] ?? 0);
    $title      = trim($_POST['title'] ?? '');
    $managerId  = (int)($_POST['manager_id'] ?? $userId);
    $tankName   = trim($_POST['tank_name'] ?? '');
    $location   = trim($_POST['location'] ?? '');
    $kdDeadline = !empty($_POST['kd_deadline']) ? $_POST['kd_deadline'] : null;
    $prodDeadline = !empty($_POST['production_deadline']) ? $_POST['production_deadline'] : null;
    $instDeadline = !empty($_POST['installation_deadline']) ? $_POST['installation_deadline'] : null;
    $startDate  = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $cost       = (float)($_POST['cost'] ?? 0);
    $status     = in_array($_POST['status'] ?? '', ['kd', 'production', 'installation', 'completed']) ? $_POST['status'] : 'kd';

    if (empty($title)) {
        echo json_encode(['status' => 'error', 'message' => 'Наименование проекта обязательно']);
        exit;
    }

    // Загрузка файла ТЗ
    $tzFile = null;
    if (isset($_FILES['tz_file']) && $_FILES['tz_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/tz/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['tz_file']['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'zip', 'xls', 'xlsx', 'txt'];
        if (in_array($ext, $allowed)) {
            $newName = 'tz_' . ($projectId > 0 ? $projectId : 'new') . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['tz_file']['tmp_name'], $uploadDir . $newName)) {
                $tzFile = 'uploads/tz/' . $newName;
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Недопустимый формат файла ТЗ']);
            exit;
        }
    }

    try {
        if ($projectId > 0) {
            // Обновление
            $sql = "UPDATE tank_projects SET 
                        title = ?, manager_id = ?, tank_name = ?, location = ?,
                        kd_deadline = ?, production_deadline = ?, installation_deadline = ?,
                        start_date = ?, cost = ?, status = ?";
            $params = [$title, $managerId, $tankName, $location, $kdDeadline, $prodDeadline, $instDeadline, $startDate, $cost, $status];

            if ($tzFile !== null) {
                $sql .= ", tz_file = ?";
                $params[] = $tzFile;
            }

            $sql .= " WHERE id = ?";
            $params[] = $projectId;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            echo json_encode(['status' => 'success', 'project_id' => $projectId, 'mode' => 'update']);
            exit;
        } else {
            // Создание
            $sql = "INSERT INTO tank_projects 
                        (title, manager_id, tank_name, location, kd_deadline, production_deadline,
                         installation_deadline, start_date, cost, status, tz_file)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$title, $managerId, $tankName, $location, $kdDeadline, $prodDeadline, $instDeadline, $startDate, $cost, $status, $tzFile]);

            $newId = $pdo->lastInsertId();

            if (function_exists('logAction')) {
                logAction($pdo, 'INSERT', 'tank_projects', $newId, "Создан проект резервуара: {$title}");
            }

            echo json_encode(['status' => 'success', 'project_id' => $newId, 'mode' => 'create']);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// 2. СМЕНА ЭТАПА (drag-and-drop)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_project_status') {
    header('Content-Type: application/json');

    $projectId = (int)($_POST['project_id'] ?? 0);
    $newStatus = $_POST['status'] ?? '';

    if ($projectId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Неверный ID проекта']);
        exit;
    }
    if (!in_array($newStatus, ['kd', 'production', 'installation', 'completed'])) {
        echo json_encode(['status' => 'error', 'message' => 'Недопустимый этап']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE tank_projects SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $projectId]);
        echo json_encode(['status' => 'success']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// 3. УДАЛЕНИЕ ПРОЕКТА
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_tank_project') {
    header('Content-Type: application/json');

    if (!in_array($userRole, ['admin', 'semi-admin'])) {
        echo json_encode(['status' => 'error', 'message' => 'Недостаточно прав']);
        exit;
    }

    $projectId = (int)($_POST['project_id'] ?? 0);
    if ($projectId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Неверный ID']);
        exit;
    }

    try {
        // Удаляем файл ТЗ с диска
        $stmt = $pdo->prepare("SELECT tz_file FROM tank_projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $tz = $stmt->fetchColumn();
        if ($tz && file_exists(__DIR__ . '/' . $tz)) @unlink(__DIR__ . '/' . $tz);

        $pdo->prepare("DELETE FROM tank_projects WHERE id = ?")->execute([$projectId]);
        echo json_encode(['status' => 'success']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// =========================================================================
// AJAX: получить данные проекта
// =========================================================================
if (isset($_GET['get_project']) && (int)$_GET['get_project'] > 0) {
    header('Content-Type: application/json');
    $pid = (int)$_GET['get_project'];
    try {
        $stmt = $pdo->prepare("
            SELECT tp.*, u.login AS manager_name
            FROM tank_projects tp
            LEFT JOIN users u ON tp.manager_id = u.id
            WHERE tp.id = ?
        ");
        $stmt->execute([$pid]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$project) {
            echo json_encode(['status' => 'error', 'message' => 'Проект не найден']);
            exit;
        }
        echo json_encode(['status' => 'success', 'data' => $project]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// =========================================================================
// ВЫБОРКА ДАННЫХ ДЛЯ КАНБАНА
// =========================================================================
$projects = [];
try {
    // Менеджер видит только свои проекты, админ — все
    if ($userRole === 'admin' || $userRole === 'semi-admin') {
        $stmt = $pdo->query("
            SELECT tp.*, u.login AS manager_name 
            FROM tank_projects tp
            LEFT JOIN users u ON tp.manager_id = u.id
            ORDER BY tp.id DESC
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT tp.*, u.login AS manager_name 
            FROM tank_projects tp
            LEFT JOIN users u ON tp.manager_id = u.id
            WHERE tp.manager_id = ?
            ORDER BY tp.id DESC
        ");
        $stmt->execute([$userId]);
    }
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $projects = [];
}

// Список пользователей (для селекта менеджера)
$allUsers = $pdo->query("SELECT id, login FROM users ORDER BY login")->fetchAll();

// Группировка по статусу
$columns = [
    'kd'           => ['icon' => '📐', 'title' => 'КД'],
    'teh_otdel'    => ['icon' => '', 'title' => 'Тех отдел'],
    'snabj'        => ['icon' => '', 'title' => 'Снабжение'],
    'production'   => ['icon' => '🏭', 'title' => 'Производство'],
    'packaging'    => ['icon' => '', 'title' => 'Комплектация'],  
    'export' => ['icon' => '', 'title' => 'Отгрузка'],
    'installation' => ['icon' => '🔧', 'title' => 'Монтаж'],

    'completed'    => ['icon' => '🏁', 'title' => 'Завершено'],
];
$projectsByStatus = array_fill_keys(array_keys($columns), []);
foreach ($projects as $p) {
    if (isset($projectsByStatus[$p['status']])) {
        $projectsByStatus[$p['status']][] = $p;
    }
}

// Хелпер форматирования даты
function fmtDate($d) {
    return !empty($d) ? date('d.m.Y', strtotime($d)) : '—';
}
function fmtCost($c) {
    return number_format((float)$c, 2, ',', ' ') . ' BYN';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Проекты резервуаров — Santeks CRM</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #0f0f1a; color: #fff; font-family: 'Segoe UI', Roboto, sans-serif; display: flex; min-height: 100vh; margin: 0; }

        main {
            flex: 1; padding: 25px 30px; min-width: 0;
            overflow-y: auto; display: flex; flex-direction: column; gap: 18px;
            max-height: 100vh;
        }

        .topbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 24px; background: #1e1e2d;
            border: 1px solid #323248; border-radius: 12px;
            flex-wrap: wrap; gap: 12px;
        }
        .topbar h1 { margin: 0; font-size: 19px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .topbar-actions { display: flex; gap: 10px; }

        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 18px; border-radius: 8px; font-weight: 600;
            font-size: 13px; cursor: pointer; border: none;
            text-decoration: none; white-space: nowrap;
            transition: all 0.25s ease;
        }
        .btn-primary { background: #4f46e5; color: #fff; }
        .btn-primary:hover { background: #6366f1; transform: translateY(-1px); box-shadow: 0 4px 15px rgba(79,70,229,0.3); }
        .btn-outline { background: transparent; border: 1px solid #323248; color: #92929f; }
        .btn-outline:hover { border-color: #4f46e5; color: #fff; background: rgba(79,70,229,0.08); }
        .btn-success { background: #10b981; color: #fff; }
        .btn-success:hover { background: #059669; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-danger:hover { background: #dc2626; }

        /* ============ KANBAN ============ */
        .kanban-board {
            display: grid;
            grid-template-columns: repeat(4, minmax(200px, 1fr));
            gap: 14px;
            overflow-x: auto;
            padding-bottom: 10px;
        }
        .kanban-board::-webkit-scrollbar { height: 8px; }
        .kanban-board::-webkit-scrollbar-thumb { background: #323248; border-radius: 8px; }
        .kanban-board::-webkit-scrollbar-thumb:hover { background: #4f46e5; }

        .kanban-column {
            background: #171725;
            border: 1px solid #26263a;
            border-radius: 12px;
            display: flex; flex-direction: column;
            min-height: 400px;
            width: flex;
            transition: border-color 0.2s, background 0.2s;
        }
        .kanban-column.drag-over { border-color: #4f46e5; background: rgba(79,70,229,0.05); }

        .kanban-column-header {
            padding: 12px 14px;
            border-bottom: 1px solid #26263a;
            display: flex; align-items: center; justify-content: space-between;
            font-size: 12px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
            border-radius: 12px 12px 0 0;
        }
        .kanban-column-header .col-count {
            background: rgba(255,255,255,0.08);
            padding: 1px 8px; border-radius: 10px;
            font-size: 11px; font-weight: 700;
            color: #cbd5e1; font-family: monospace;
        }

        .kanban-column[data-status="kd"]           .kanban-column-header { color: #818cf8; border-top: 3px solid #818cf8; }
        .kanban-column[data-status="production"]   .kanban-column-header { color: #f59e0b; border-top: 3px solid #f59e0b; }
        .kanban-column[data-status="installation"] .kanban-column-header { color: #6366f1; border-top: 3px solid #6366f1; }
        .kanban-column[data-status="completed"]    .kanban-column-header { color: #10b981; border-top: 3px solid #10b981; }

        .kanban-column-body {
            padding: 10px;
            display: flex; flex-direction: column; gap: 10px;
            flex: 1; overflow-y: auto;
            max-height: calc(100vh - 240px);
        }
        .kanban-column-body::-webkit-scrollbar { width: 6px; }
        .kanban-column-body::-webkit-scrollbar-thumb { background: #26263a; border-radius: 6px; }
        .kanban-column-body::-webkit-scrollbar-thumb:hover { background: #4f46e5; }

        /* ============ CARD ============ */
        .kanban-card {
            background: #1e1e2d;
            border: 1px solid #2a2a3f;
            border-radius: 10px;
            padding: 12px 13px;
            cursor: grab;
            transition: transform 0.15s, box-shadow 0.15s, border-color 0.15s;
            position: relative;
            overflow: hidden;
        }
        .kanban-card::before {
            content: '';
            position: absolute; left: 0; top: 0; bottom: 0;
            width: 3px;
            background: var(--accent, #6b6b85);
        }
        .kanban-card:hover {
            border-color: #4f46e5;
            box-shadow: 0 8px 20px rgba(0,0,0,0.35);
        }
        .kanban-card.dragging { opacity: 0.4; cursor: grabbing; }

        .card-title {
            color: #fff; font-size: 13.5px; font-weight: 700;
            line-height: 1.35; margin-bottom: 8px;
            word-break: break-word;
        }
        .card-tank {
            color: #818cf8; font-size: 12px; font-weight: 600;
            margin-bottom: 8px;
        }
        .card-row {
            display: flex; justify-content: space-between; align-items: center;
            font-size: 11px; color: #92929f; margin-bottom: 4px;
        }
        .card-row .label { color: #6b6b85; }
        .card-row .value { color: #e2e8f0; font-weight: 600; }
        .card-row .value.overdue { color: #ef4444; }
        .card-row .value.soon { color: #f59e0b; }
        .card-cost {
            margin-top: 8px; padding-top: 8px;
            border-top: 1px solid #26263a;
            display: flex; justify-content: space-between; align-items: center;
        }
        .card-cost .cost-value {
            color: #10b981; font-weight: 700;
            font-family: monospace; font-size: 13px;
        }
        .card-manager {
            font-size: 11px; color: #92929f;
            display: flex; align-items: center; gap: 5px;
        }
        .card-manager .avatar {
            width: 20px; height: 20px; border-radius: 50%;
            background: linear-gradient(135deg, #4f46e5, #818cf8);
            display: flex; align-items: center; justify-content: center;
            font-size: 9px; font-weight: 700; color: #fff;
            text-transform: uppercase;
        }
        .card-actions {
            display: flex; gap: 6px; justify-content: flex-end;
            margin-top: 8px;
        }
        .card-btn {
            background: rgba(79,70,229,0.1);
            border: 1px solid rgba(79,70,229,0.3);
            color: #818cf8;
            padding: 3px 10px; border-radius: 6px;
            font-size: 11px; font-weight: 600;
            cursor: pointer; transition: all 0.2s;
        }
        .card-btn:hover { background: #4f46e5; color: #fff; }
        .card-btn.danger {
            background: rgba(239,68,68,0.1);
            border-color: rgba(239,68,68,0.3);
            color: #ef4444;
        }
        .card-btn.danger:hover { background: #ef4444; color: #fff; }

        .kanban-empty {
            color: #4b4b5e; font-size: 12px;
            text-align: center; padding: 20px 10px;
            border: 1px dashed #26263a; border-radius: 8px;
            font-style: italic;
        }

        /* ============ MODAL ============ */
        .modal-overlay {
            display: none; position: fixed; top: 0; left: 0;
            width: 100%; height: 100%; background: rgba(0,0,0,0.75);
            justify-content: center; align-items: center;
            z-index: 99999; padding: 20px;
            backdrop-filter: blur(4px);
        }
        .modal-overlay.active { display: flex; }

        .modal-content {
            background: #1e1e2d; border: 1px solid #323248;
            border-radius: 16px; padding: 28px;
            max-width: 90%; max-height: 90vh; overflow-y: auto;
            box-shadow: 0 25px 60px rgba(0,0,0,0.5);
            animation: modalIn 0.25s ease;
        }
        @keyframes modalIn { from { transform: translateY(-15px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        .form-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 14px;
        }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group.full { grid-column: span 2; }
        .form-group label {
            font-size: 11px; font-weight: 700; color: #92929f;
            text-transform: uppercase; letter-spacing: 0.3px;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 14px; background: #151521;
            border: 1px solid #323248; border-radius: 8px; color: #fff;
            font-size: 13px; outline: none; font-family: inherit;
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79,70,229,0.15);
        }
        .form-group input[type="date"] { color-scheme: dark; }

        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            border-bottom: 1px solid #323248; padding-bottom: 14px; margin-bottom: 20px;
        }
        .modal-header h2 { margin: 0; font-size: 20px; }
        .modal-close {
            background: none; border: none; color: #92929f;
            font-size: 26px; cursor: pointer; line-height: 1;
            transition: color 0.2s;
        }
        .modal-close:hover { color: #ef4444; }

        .modal-actions {
            display: flex; justify-content: flex-end; gap: 12px;
            margin-top: 20px; border-top: 1px solid #323248; padding-top: 16px;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <main>
        <div class="topbar">
            <h1>🛢️ Проекты резервуаров</h1>
            <div class="topbar-actions">
                <button class="btn btn-primary" onclick="openProjectModal()">➕ Новый проект</button>
            </div>
        </div>

        <div class="kanban-board">
            <?php foreach ($columns as $statusKey => $col):
                $colProjects = $projectsByStatus[$statusKey];
            ?>
            <div class="kanban-column" data-status="<?= $statusKey ?>">
                <div class="kanban-column-header">
                    <span><?= $col['icon'] ?> <?= $col['title'] ?></span>
                    <span class="col-count" data-count-for="<?= $statusKey ?>"><?= count($colProjects) ?></span>
                </div>
                <div class="kanban-column-body"
                     data-drop-status="<?= $statusKey ?>"
                     ondragover="onDragOver(event)"
                     ondragleave="onDragLeave(event)"
                     ondrop="onDrop(event, '<?= $statusKey ?>')">
                    <?php if (empty($colProjects)): ?>
                        <div class="kanban-empty">Нет проектов</div>
                    <?php else: ?>
                        <?php foreach ($colProjects as $p):
                            // Инициалы менеджера
                            $managerName = $p['manager_name'] ?? '—';
                            $parts = preg_split('/[\s._-]+/', $managerName);
                            $initials = '';
                            foreach (array_slice($parts, 0, 2) as $part) {
                                if ($part !== '') $initials .= mb_strtoupper(mb_substr($part, 0, 1));
                            }
                            if ($initials === '') $initials = mb_strtoupper(mb_substr($managerName, 0, 2));

                            // Акцентный цвет по этапу
                            $accentMap = [
                                'kd' => '#818cf8',
                                'production' => '#f59e0b',
                                'installation' => '#6366f1',
                                'completed' => '#10b981',
                            ];
                            $accent = $accentMap[$p['status']] ?? '#6b6b85';

                            // Проверка просрочки ближайшего срока (зависит от этапа)
                            $deadlineForStage = null;
                            if ($p['status'] === 'kd') $deadlineForStage = $p['kd_deadline'];
                            elseif ($p['status'] === 'production') $deadlineForStage = $p['production_deadline'];
                            elseif ($p['status'] === 'installation') $deadlineForStage = $p['installation_deadline'];

                            $deadlineClass = '';
                            if ($deadlineForStage) {
                                $ts = strtotime($deadlineForStage);
                                $now = strtotime(date('Y-m-d'));
                                if ($ts < $now) $deadlineClass = 'overdue';
                                elseif ($ts <= $now + 3 * 86400) $deadlineClass = 'soon';
                            }
                        ?>
                        <div class="kanban-card"
                             draggable="true"
                             data-project-id="<?= (int)$p['id'] ?>"
                             data-status="<?= htmlspecialchars($p['status']) ?>"
                             style="--accent: <?= $accent ?>;"
                             ondragstart="onDragStart(event, <?= (int)$p['id'] ?>)"
                             ondragend="onDragEnd(event)">
                            <div class="card-title"><?= htmlspecialchars($p['title']) ?></div>
                            <?php if (!empty($p['tank_name'])): ?>
                                <div class="card-tank">🛢️ <?= htmlspecialchars($p['tank_name']) ?></div>
                            <?php endif; ?>

                            <?php if (!empty($p['location'])): ?>
                            <div class="card-row">
                                <span class="label">📍 Место:</span>
                                <span class="value" style="text-align:right; max-width:60%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                    <?= htmlspecialchars($p['location']) ?>
                                </span>
                            </div>
                            <?php endif; ?>

                            <div class="card-row">
                                <span class="label">📅 Начало:</span>
                                <span class="value"><?= fmtDate($p['start_date']) ?></span>
                            </div>

                            <?php if ($deadlineForStage): ?>
                            <div class="card-row">
                                <span class="label">⏰ Дедлайн этапа:</span>
                                <span class="value <?= $deadlineClass ?>"><?= fmtDate($deadlineForStage) ?></span>
                            </div>
                            <?php endif; ?>

                            <div class="card-cost">
                                <span class="card-manager">
                                    <span class="avatar"><?= htmlspecialchars($initials) ?></span>
                                    <?= htmlspecialchars($managerName) ?>
                                </span>
                                <span class="cost-value"><?= fmtCost($p['cost']) ?></span>
                            </div>

                            <?php if (!empty($p['tz_file'])): ?>
                            <div style="margin-top:6px;">
                                <a href="<?= htmlspecialchars($p['tz_file']) ?>" target="_blank"
                                   style="color: #10b981; font-size: 11px; text-decoration: none;">
                                    📎 Скачать ТЗ
                                </a>
                            </div>
                            <?php endif; ?>

                            <div class="card-actions">
                                <button class="card-btn" onclick="openProjectModal(<?= (int)$p['id'] ?>)">✏️ Ред.</button>
                                <?php if (in_array($userRole, ['admin', 'semi-admin'])): ?>
                                    <button class="card-btn danger" onclick="deleteProject(<?= (int)$p['id'] ?>, '<?= htmlspecialchars(addslashes($p['title'])) ?>')">✖</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </main>

    <!-- ============ MODAL: CREATE / EDIT ============ -->
    <div id="projectModal" class="modal-overlay">
        <div class="modal-content" style="max-width: 780px; width: 100%;">
            <div class="modal-header">
                <h2 id="modalTitle">➕ Новый проект резервуара</h2>
                <button class="modal-close" onclick="closeProjectModal()">&times;</button>
            </div>
            <form id="projectForm" enctype="multipart/form-data" onsubmit="saveProject(event)">
                <input type="hidden" name="project_id" id="project_id" value="0">
                <input type="hidden" name="action" value="save_tank_project">

                <div class="form-grid">
                    <div class="form-group full">
                        <label>Наименование проекта *</label>
                        <input type="text" name="title" id="title" required placeholder="Резервуар РВС-1000 для АЗС №5">
                    </div>

                    <div class="form-group">
                        <label>Менеджер проекта *</label>
                        <select name="manager_id" id="manager_id" required>
                            <?php foreach ($allUsers as $u): ?>
                                <option value="<?= (int)$u['id'] ?>" <?= $u['id'] == $userId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['login']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Наименование резервуара</label>
                        <input type="text" name="tank_name" id="tank_name" placeholder="РВС-1000">
                    </div>

                    <div class="form-group full">
                        <label>Место размещения объекта</label>
                        <input type="text" name="location" id="location" placeholder="г. Минск, ул. Промышленная, 10">
                    </div>

                    <div class="form-group">
                        <label>Начало проекта</label>
                        <input type="date" name="start_date" id="start_date">
                    </div>

                    <div class="form-group">
                        <label>Стоимость проекта (BYN)</label>
                        <input type="number" step="0.01" min="0" name="cost" id="cost" placeholder="0.00">
                    </div>

                    <div class="form-group">
                        <label>Срок КД</label>
                        <input type="date" name="kd_deadline" id="kd_deadline">
                    </div>

                    <div class="form-group">
                        <label>Срок производства</label>
                        <input type="date" name="production_deadline" id="production_deadline">
                    </div>

                    <div class="form-group">
                        <label>Срок монтажа</label>
                        <input type="date" name="installation_deadline" id="installation_deadline">
                    </div>

                    <div class="form-group">
                        <label>Этап</label>
                        <select name="status" id="status">
                            <option value="kd">📐 КД</option>
                            <option value="production">🏭 Производство</option>
                            <option value="installation">🔧 Монтаж</option>
                            <option value="completed">🏁 Завершено</option>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label>ТЗ (файл)</label>
                        <input type="file" name="tz_file" id="tz_file"
                               accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.zip,.xls,.xlsx,.txt"
                               style="padding: 8px;">
                        <small id="tz-current" style="color:#6b6b85; font-size:11px;"></small>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeProjectModal()">Отмена</button>
                    <button type="submit" class="btn btn-primary">💾 Сохранить</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // ============================================================
        // МОДАЛКА: ОТКРЫТИЕ / ЗАКРЫТИЕ
        // ============================================================
        function openProjectModal(projectId = 0) {
            const modal = document.getElementById('projectModal');
            const form = document.getElementById('projectForm');
            const title = document.getElementById('modalTitle');
            const tzInfo = document.getElementById('tz-current');

            form.reset();
            document.getElementById('project_id').value = 0;
            tzInfo.textContent = '';

            if (projectId > 0) {
                title.innerText = '✏️ Редактирование проекта #' + projectId;
                fetch('tank_projects.php?get_project=' + projectId)
                    .then(res => res.json())
                    .then(data => {
                        if (data.status !== 'success') {
                            alert('Ошибка загрузки проекта');
                            return;
                        }
                        const p = data.data;
                        document.getElementById('project_id').value = p.id;
                        document.getElementById('title').value = p.title || '';
                        document.getElementById('manager_id').value = p.manager_id || '';
                        document.getElementById('tank_name').value = p.tank_name || '';
                        document.getElementById('location').value = p.location || '';
                        document.getElementById('start_date').value = p.start_date || '';
                        document.getElementById('cost').value = p.cost || '';
                        document.getElementById('kd_deadline').value = p.kd_deadline || '';
                        document.getElementById('production_deadline').value = p.production_deadline || '';
                        document.getElementById('installation_deadline').value = p.installation_deadline || '';
                        document.getElementById('status').value = p.status || 'kd';
                        if (p.tz_file) {
                            tzInfo.innerHTML = 'Текущий файл: <a href="' + p.tz_file + '" target="_blank" style="color:#10b981;">скачать</a> (загрузите новый, чтобы заменить)';
                        }
                        modal.classList.add('active');
                    })
                    .catch(err => {
                        console.error(err);
                        alert('Ошибка соединения');
                    });
            } else {
                title.innerText = '➕ Новый проект резервуара';
                // Ставим сегодняшнюю дату по умолчанию
                document.getElementById('start_date').valueAsDate = new Date();
                modal.classList.add('active');
            }
        }

        function closeProjectModal() {
            document.getElementById('projectModal').classList.remove('active');
        }

        // ============================================================
        // СОХРАНЕНИЕ ПРОЕКТА
        // ============================================================
        async function saveProject(e) {
            e.preventDefault();
            const form = e.target;
            const fd = new FormData(form);
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) { submitBtn.disabled = true; submitBtn.innerText = '⏳ Сохранение...'; }

            try {
                const res = await fetch('tank_projects.php', { method: 'POST', body: fd });
                const text = await res.text();
                let data;
                try { data = JSON.parse(text); }
                catch (e) {
                    alert('❌ Сервер вернул не JSON:\n' + text.substring(0, 400));
                    return;
                }
                if (data.status === 'success') {
                    closeProjectModal();
                    location.reload();
                } else {
                    alert('❌ Ошибка: ' + data.message);
                }
            } catch (err) {
                console.error(err);
                alert('Ошибка сети');
            } finally {
                if (submitBtn) { submitBtn.disabled = false; submitBtn.innerText = '💾 Сохранить'; }
            }
        }

        // ============================================================
        // УДАЛЕНИЕ ПРОЕКТА
        // ============================================================
        async function deleteProject(id, title) {
            if (!confirm('Удалить проект «' + title + '»? Это действие необратимо.')) return;

            const fd = new FormData();
            fd.append('action', 'delete_tank_project');
            fd.append('project_id', id);

            try {
                const res = await fetch('tank_projects.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    location.reload();
                } else {
                    alert('Ошибка: ' + data.message);
                }
            } catch (err) {
                alert('Ошибка соединения');
            }
        }

        // ============================================================
        // DRAG & DROP + FLIP
        // ============================================================
        let draggedProjectId = null;
        let draggedFromStatus = null;

        function onDragStart(e, projectId) {
            draggedProjectId = projectId;
            draggedFromStatus = e.target.dataset.status;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', projectId);
            setTimeout(() => e.target.classList.add('dragging'), 0);
        }

        function onDragEnd(e) {
            e.target.classList.remove('dragging');
            document.querySelectorAll('.kanban-column.drag-over').forEach(c => c.classList.remove('drag-over'));
        }

        function onDragOver(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            const col = e.currentTarget.closest('.kanban-column');
            if (col) col.classList.add('drag-over');
        }

        function onDragLeave(e) {
            const col = e.currentTarget.closest('.kanban-column');
            if (col && !e.currentTarget.contains(e.relatedTarget)) {
                col.classList.remove('drag-over');
            }
        }

        function capturePositions(containers) {
            const map = new Map();
            containers.forEach(container => {
                if (!container) return;
                container.querySelectorAll('.kanban-card').forEach(card => {
                    map.set(card, card.getBoundingClientRect());
                });
            });
            return map;
        }

        function playFlipAnimations(map) {
            map.forEach((firstRect, card) => {
                if (!card.isConnected) return;
                const last = card.getBoundingClientRect();
                const dx = firstRect.left - last.left;
                const dy = firstRect.top - last.top;
                if (Math.abs(dx) < 0.5 && Math.abs(dy) < 0.5) return;

                card.style.transition = 'none';
                card.style.transform = `translate(${dx}px, ${dy}px)`;
                card.style.zIndex = '50';
                card.style.pointerEvents = 'none';

                requestAnimationFrame(() => requestAnimationFrame(() => {
                    card.style.transition = 'transform 0.42s cubic-bezier(0.2, 0.8, 0.2, 1), box-shadow 0.42s ease';
                    card.style.transform = 'translate(0,0)';
                    card.style.boxShadow = '0 12px 30px rgba(79,70,229,0.25)';
                    setTimeout(() => {
                        card.style.transition = '';
                        card.style.transform = '';
                        card.style.zIndex = '';
                        card.style.pointerEvents = '';
                        card.style.boxShadow = '';
                    }, 430);
                }));
            });
        }

        async function onDrop(e, newStatus) {
            e.preventDefault();
            const columnBody = e.currentTarget;
            const column = columnBody.closest('.kanban-column');
            if (column) column.classList.remove('drag-over');

            if (!draggedProjectId) return;
            if (draggedFromStatus === newStatus) { draggedProjectId = null; return; }

            const card = document.querySelector(`.kanban-card[data-project-id="${draggedProjectId}"]`);
            if (!card) { draggedProjectId = null; return; }

            card.classList.remove('dragging');
            const oldStatus = draggedFromStatus;
            const oldBody = document.querySelector(`.kanban-column-body[data-drop-status="${oldStatus}"]`);

            const positions = capturePositions([oldBody, columnBody]);

            card.dataset.status = newStatus;
            const emptyPlaceholder = columnBody.querySelector('.kanban-empty');
            if (emptyPlaceholder) emptyPlaceholder.remove();
            columnBody.appendChild(card);

            if (oldBody && oldBody.querySelectorAll('.kanban-card').length === 0) {
                if (!oldBody.querySelector('.kanban-empty')) {
                    const empty = document.createElement('div');
                    empty.className = 'kanban-empty';
                    empty.textContent = 'Нет проектов';
                    oldBody.appendChild(empty);
                }
            }

            updateColumnCounts();
            playFlipAnimations(positions);

            const fd = new FormData();
            fd.append('action', 'update_project_status');
            fd.append('project_id', draggedProjectId);
            fd.append('status', newStatus);

            const pid = draggedProjectId;
            draggedProjectId = null;
            draggedFromStatus = null;

            try {
                const res = await fetch('tank_projects.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status !== 'success') {
                    alert('❌ Ошибка: ' + (data.message || 'Неизвестно'));
                    rollback(card, oldBody, columnBody, oldStatus);
                }
            } catch (err) {
                console.error(err);
                alert('❌ Ошибка сети. Перемещение отменено.');
                rollback(card, oldBody, columnBody, oldStatus);
            }
        }

        function rollback(card, oldBody, newBody, oldStatus) {
            if (!oldBody) return;
            const positions = capturePositions([oldBody, newBody]);
            const emptyNew = newBody.querySelector('.kanban-empty');
            if (emptyNew) emptyNew.remove();
            oldBody.appendChild(card);
            card.dataset.status = oldStatus;
            if (newBody.querySelectorAll('.kanban-card').length === 0) {
                if (!newBody.querySelector('.kanban-empty')) {
                    const empty = document.createElement('div');
                    empty.className = 'kanban-empty';
                    empty.textContent = 'Нет проектов';
                    newBody.appendChild(empty);
                }
            }
            updateColumnCounts();
            playFlipAnimations(positions);
        }

        function updateColumnCounts() {
            document.querySelectorAll('.kanban-column').forEach(col => {
                const status = col.dataset.status;
                const count = col.querySelectorAll('.kanban-card').length;
                const counter = col.querySelector(`[data-count-for="${status}"]`);
                if (counter) counter.textContent = count;
            });
        }

        // Закрытие по ESC и клику по оверлею
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeProjectModal();
        });
        document.getElementById('projectModal').addEventListener('click', function(e) {
            if (e.target === this) closeProjectModal();
        });
    </script>
</body>
</html>