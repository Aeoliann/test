<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html"); 
    exit;
}

$userId  = (int)$_SESSION['user_id'];
$u_role  = $_SESSION['role'] ?? 'manager';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'update_comment') {
        header('Content-Type: application/json');
        if (ob_get_length()) ob_clean();
        try {
            $t_id = (int)($_POST['id'] ?? 0);
            $comment = trim($_POST['comment'] ?? '');
            $pdo->prepare("UPDATE tasks SET manager_comment = ? WHERE id = ?")->execute([comment, $t_id]);
            echo json_encode(["status" => "success"]); 
            exit;
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]); 
            exit;
        }
    }
    
    if (isset($_POST['task_text'])) {
        $task_text = trim($_POST['task_text']);
        $assigned_to = ($u_role === 'admin' && isset($_POST['assigned_to'])) ? (int)$_POST['assigned_to'] : $userId;
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : date('Y-m-d');

        if (!empty($task_text)) {
            $stmt = $pdo->prepare("INSERT INTO tasks (task_text, due_date, user_id, status, created_by) VALUES (?, ?, ?, 'pending', ?)");
            $stmt->execute([$task_text, $due_date, $assigned_to, $userId]);
            header("Location: tasks.php"); 
            exit;
        }
    }
}



if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Первым делом читаем сырой JSON поток, если JavaScript шлёт fetch
    $rawInput = json_decode(file_get_contents('php://input'), true) ?: [];
    
    $action = $_POST['action'] ?? ($rawInput['action'] ?? '');
    $t_id   = (int)($_POST['id'] ?? ($rawInput['id'] ?? 0));
    $report = trim($_POST['report'] ?? ($rawInput['report'] ?? ''));

    // 1. ПОДПРОГРАММА: Асинхронное сохранение отчета менеджера по задаче
    if ($action === 'update_task_report') {
        header('Content-Type: application/json');
        if (ob_get_length()) ob_clean();
        try {
            if ($t_id <= 0) {
                throw new Exception("Критическая ошибка: Некорректный ID задачи!");
            }
            
            // Жестко фиксируем текст отчета в базе данных Windows XAMPP
            $stmt = $pdo->prepare("UPDATE tasks SET manager_comment = ? WHERE id = ?");
            $stmt->execute([$report, $t_id]);
            
            echo json_encode(["status" => "success"]); 
            exit;
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]); 
            exit;
        }
    }

    // 2. ПОДПРОГРАММА: Классическая отправка новой задачи из формы (для Админа)
    if (isset($_POST['task_text'])) {
        if ($u_role !== 'admin') {
            die("Критическая ошибка безопасности: У вашей роли нет прав на создание задач!");
        }
        
        $task_text   = trim($_POST['task_text']);
        $assigned_to = isset($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : $userId;
        $due_date    = !empty($_POST['due_date']) ? $_POST['due_date'] : date('Y-m-d');
        
        if (!empty($task_text)) {
            $stmt = $pdo->prepare("INSERT INTO tasks (task_text, assigned_to, due_date, created_by, status) VALUES (?, ?, ?, ?, 'pending')");
            $stmt->execute([$task_text, $assigned_to, $due_date, $userId]);
            header("Location: tasks.php");
            exit;
        }
    }
}

// =========================================================================
// ИСПРАВЛЕНО: Безопасная смена статуса задачи с мгновенной проверкой отчета
// =========================================================================
if (isset($_GET['toggle_id'])) {
    $t_id = (int)$_GET['toggle_id'];
    
    // Вытягиваем текущий статус и отчет напрямую из базы MariaDB
    $stmt_check = $pdo->prepare("SELECT status, manager_comment FROM tasks WHERE id = ?");
    $stmt_check->execute([$t_id]);
    $task_data = $stmt_check->fetch();
    
    if ($task_data) {
        $current_status = $task_data['status'];
        $current_comment = trim($task_data['manager_comment'] ?? '');
        
        // Блокируем ТОЛЬКО если задача была в ожидании, а отчет РЕАЛЬНО абсолютно пустой
        if ($current_status === 'pending' && empty($current_comment)) {
            die("<script>alert('⚠️ Ошибка CRM: Нельзя перевести задачу в статус Выполнено без заполнения текстового отчета по проделанной работе!'); window.location.href='tasks.php';</script>");
        }
        
        // Меняем статус на противоположный
        $new_status = ($current_status === 'pending') ? 'completed' : 'pending';
        $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ?")->execute([$new_status, $t_id]);
    }
    header("Location: tasks.php"); 
    exit;
}


if (isset($_GET['delete_id'])) {
    if ($u_role !== 'admin') {
        die("Ошибка безопасности!");
    }
    $pdo->prepare("DELETE FROM tasks WHERE id = ?")->execute([(int)$_GET['delete_id']]);
    header("Location: tasks.php"); 
    exit;
}

$tasks = [];
try {
    if ($u_role === 'admin') {
        $sql = "SELECT t.*, u1.login as executor_name, u2.login as creator_name 
                FROM tasks t 
                LEFT JOIN users u1 ON t.user_id = u1.id 
                LEFT JOIN users u2 ON t.created_by = u2.id 
                ORDER BY t.id DESC";
        $tasks = $pdo->query($sql)->fetchAll();
        $managers = $pdo->query("SELECT id, login FROM users WHERE role = 'manager'")->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT t.*, u.login as creator_name 
                               FROM tasks t 
                               LEFT JOIN users u ON t.created_by = u.id 
                               WHERE t.user_id = ? ORDER BY t.id DESC");
        $stmt->execute([$userId]);
        $tasks = $stmt->fetchAll();
    }
} catch (Exception $e) { }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Задачи — Santeks CRM</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { background: #151521; color: #fff; font-family: sans-serif; margin: 0; padding: 0; }
        .wrapper { display: flex; min-height: 100vh; }
        .main-content { flex: 1; padding: 30px; background: #151521; box-sizing: border-box; }
        .task-card { background: #1e1e2d; border-radius: 8px; border: 1px solid #323248; padding: 20px; margin-bottom: 25px; }
        .task-input { padding: 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 14px; box-sizing: border-box; }
        .task-date { padding: 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 14px; color-scheme: dark; box-sizing: border-box; }
        .task-select { padding: 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; cursor: pointer; font-size: 14px; box-sizing: border-box; }
        .btn-add { background: #10b981; color: #fff; border: none; padding: 0 24px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 14px; transition: 0.15s; }
        .btn-add:hover { background: #059669; }
        .tasks-table { width: 100%; border-collapse: collapse; text-align: left; background: #1e1e2d; border-radius: 8px; overflow: hidden; border: 1px solid #323248; }
        .tasks-table th { background: #242434; padding: 14px 12px; color: #92929f; font-size: 11px; text-transform: uppercase; font-weight: bold; border-bottom: 1px solid #323248; }
        .tasks-table td { padding: 14px 12px !important; border-bottom: 1px solid #2b2b40 !important; font-size: 14px; color: #fff !important; background-color: #1e1e2d !important; vertical-align: middle; }
        .status-badge { padding: 6px 12px; border-radius: 4px; font-size: 12px; font-weight: bold; text-decoration: none; display: inline-block; }
        .status-pending { background: #3d2d1d; color: #f59e0b; }
        .status-completed { background: #1a2e26; color: #10b981; text-decoration: line-through; }
        .comment-input { width: 100%; padding: 8px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 4px; outline: none; font-size: 13px; box-sizing: border-box; }
    </style>
</head>
<body>

    <div class="wrapper">
        <div style="flex-shrink: 0; width: 260px;"><?php include 'sidebar.php'; ?></div>

        <div class="main-content">
            <h1 style="margin-top: 0; font-size: 24px; margin-bottom: 25px;">📝 Список внутренних задач и поручений</h1>
            <?php if ($u_role === 'admin'): ?>
            <div class="task-card">
                <form action="tasks.php" method="POST" style="margin: 0; padding: 0;">
                    <div style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                        <div style="display: flex; flex-direction: column; gap: 4px; flex: 2; min-width: 300px;">
                            <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Что нужно сделать:</label>
                            <input type="text" name="task_text" required placeholder="Напр: Созвониться по поводу отгрузки..." class="task-input" style="width: 100%;">
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 4px; width: 160px;">
                            <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Срок (Дедлайн):</label>
                            <input type="date" name="due_date" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" class="task-date" style="width: 100%;">
                        </div>

                        <?php if ($u_role === 'admin'): ?>
                            <div style="display: flex; flex-direction: column; gap: 4px; width: 180px;">
                                <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Исполнитель:</label>
                                <select name="assigned_to" class="task-select" style="width: 100%;">
                                    <?php foreach ($managers as $m): ?>
                                        <option value="<?= $m['id'] ?>">👤 <?= htmlspecialchars($m['login']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        
                        <button type="submit" class="btn-add" style="height: 45px;">🚀 Поставить задачу</button>
                    </div>
                </form>
            </div>
            <?php endif;?>
            <table class="tasks-table">
                <thead>
                    <tr>
                        <th style="width: 120px;">Статус</th>
                        <th>Текст поручения</th>
                        <th style="width: 140px; color: #ef4444 !important;">Срок исполнения</th>
                        <th style="width: 280px;">Комментарий исполнителя (Отчёт)</th>
                        <th style="width: 120px;">Постановщик</th>
                        <?php if ($u_role === 'admin'): ?><th style="width: 120px;">Исполнитель</th><?php endif; ?>
                        <th style="width: 130px;">Дата создания</th>
                        <th style="width: 50px; text-align: center;">🗑</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($tasks) > 0): ?>
                        <?php foreach ($tasks as $t): 
                            $isComp = ($t['status'] === 'completed'); 
                            $isOverdue = (!$isComp && !empty($t['due_date']) && $t['due_date'] < date('Y-m-d'));
                        ?>
                            <tr>
                                <td>
                                    <a href="tasks.php?toggle_id=<?= $t['id'] ?>" 
                                       onclick="return checkTaskReportBeforeClose(<?= $t['id'] ?>, this);" 
                                       class="status-badge <?= $isComp ? 'status-completed' : 'status-pending' ?>">
                                        <?= $isComp ? '✓ Выполнено' : '⏳ В ожидании' ?>
                                    </a>
                                </td>
                                <td style="color: <?= $isComp ? '#64748b' : '#fff' ?> !important; text-decoration: <?= $isComp ? 'line-through' : 'none' ?>;">
                                    <?= htmlspecialchars($t['task_text']) ?>
                                </td>
                                
                                <td style="font-weight: bold; color: <?= $isOverdue ? '#ef4444' : '#f59e0b' ?> !important;">
                                    📅 <?= !empty($t['due_date']) ? date('d.m.Y', strtotime($t['due_date'])) : '—' ?>
                                    <?= $isOverdue ? ' <span style="font-size:10px; background:#3d1d1d; padding:2px 4px; border-radius:3px; color:#ef4444;">🔥 СГОРЕЛ</span>' : '' ?>
                                </td>

                               <td style="padding: 12px; vertical-align: middle;">
    <!-- Текстовый вид (виден по умолчанию) -->
    <div id="report_view_<?= (int)$t['id'] ?>" 
         onclick="switchToEditReport(<?= (int)$t['id'] ?>)"
         style="cursor: pointer; color: #10b981; font-size: 13px; font-weight: 500; min-height: 20px; transition: color 0.15s;"
         title="Кликните, чтобы написать отчет по выполнению задачи"
         onmouseover="this.style.color='#fff';"
         onmouseout="this.style.color='#10b981';">
        <?= !empty($t['manager_comment']) ? '💬 ' . htmlspecialchars($t['manager_comment']) : '<span style="color:#64748b;">Написать отчет...</span>' ?>
    </div>

    <!-- Поле ввода (скрыто, появляется при клике) -->
    <input type="text" 
           id="report_input_<?= (int)$t['id'] ?>" 
           value="<?= htmlspecialchars($t['manager_comment'] ?? '') ?>" 
           placeholder="Что было сделано по задаче?..."
           onblur="saveInlineReport(<?= (int)$t['id'] ?>, this.value)"
           onkeydown="if(event.key === 'Enter') this.blur();"
           style="display: none; width: 100%; height: 32px; padding: 0 8px; background: #151521; border: 1px solid #4f46e5; color: #fff; border-radius: 4px; outline: none; box-sizing: border-box; font-size: 13px;">
</td>
                                
                                <td style="color: #92929f !important;">✍ <?= htmlspecialchars($t['creator_name'] ?? 'Админ') ?></td>
                                <?php if ($u_role === 'admin'): ?><td style="font-weight: bold; color: #a855f7 !important;">👤 <?= htmlspecialchars($t['executor_name'] ?? 'Админ') ?></td><?php endif; ?>
                                <td style="color: #64748b !important; font-size: 13px;"><?= date('d.m.Y H:i', strtotime($t['created_at'])) ?></td>
                                
                                <td style="text-align: center;">
                                    <?php if ($u_role === 'admin'): ?>
                                        <a href="tasks.php?delete_id=<?= $t['id'] ?>" onclick="return confirm('Удалить?')" style="text-decoration: none; color: #ef4444;">❌</a>
                                    <?php else: ?>
                                        <span style="color: #323248; font-size: 13px; user-select: none;">🔒</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="<?= $u_role === 'admin' ? 8 : 7 ?>" style="text-align: center; color: #64748b !important; padding: 40px !important;">Список внутренних задач пуст.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    async function saveTaskComment(taskId, commentValue) {
        const fd = new FormData(); 
        fd.append('action', 'update_comment'); 
        fd.append('id', taskId); 
        fd.append('comment', commentValue);
        try { 
            await fetch('tasks.php', { method: 'POST', body: fd }); 
        } catch (err) { }
    }
    function checkTaskReportBeforeClose(taskId, element) {
        if (element.classList.contains('status-completed')) return true; 
        const commentInput = document.querySelector(`input[data-task-id="${taskId}"]`);
        if (commentInput && commentInput.value.trim() === '') {
            alert('⚠️ Напишите отчет перед закрытием задачи!');
            commentInput.focus(); 
            return false;
        }
        return true;
    }

    function switchToEditReport(taskId) {
    const viewDiv = document.getElementById('report_view_' + taskId);
    const inputField = document.getElementById('report_input_' + taskId);
    
    if (viewDiv && inputField) {
        viewDiv.style.display = 'none';
        inputField.style.display = 'block';
        inputField.focus();
    }
}

// Функция 2: AJAX-отправка отчета в базу Windows XAMPP
async function saveInlineReport(taskId, reportValue) {
    const viewDiv = document.getElementById('report_view_' + taskId);
    const inputField = document.getElementById('report_input_' + taskId);
    const trimmedVal = reportValue.trim();

    if (viewDiv && inputField) {
        viewDiv.innerHTML = trimmedVal !== '' ? '💬 ' + trimmedVal : '<span style="color:#64748b;">Написать отчет...</span>';
        inputField.style.display = 'none';
        viewDiv.style.display = 'block';
    }

    try {
        await fetch('tasks.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'update_task_report',
                id: parseInt(taskId, 10),
                report: trimmedVal
            })
        });
    } catch (err) {
        console.error("Ошибка связи при сохранении отчета:", err);
    }
}
    </script>
</body>
</html>