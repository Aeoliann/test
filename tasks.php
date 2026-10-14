<?php
// tasks.php — Задачи с множественными исполнителями и отчётами (v2.7)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'manager';

// =========================================================================
// ОБРАБОТЧИКИ POST (без изменений)
// =========================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_task') {
    header('Content-Type: application/json');
    if (!in_array($userRole, ['admin', 'semi-admin', 'manager'])) {
        die(json_encode(['status' => 'error', 'message' => 'Недостаточно прав']));
    }

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $priority = in_array($_POST['priority'] ?? '', ['low', 'medium', 'high']) ? $_POST['priority'] : 'medium';
    $category = in_array($_POST['category'] ?? '', ['task', 'proposal', 'question']) ? $_POST['category'] : 'task';
    $assignees = isset($_POST['assignees']) && is_array($_POST['assignees']) ? array_map('intval', $_POST['assignees']) : [];

    if (empty($title)) { echo json_encode(['status' => 'error', 'message' => 'Заголовок обязателен']); exit; }
    if (empty($assignees)) { echo json_encode(['status' => 'error', 'message' => 'Выберите хотя бы одного исполнителя']); exit; }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO tasks (title, description, created_by, due_date, priority, category, status) VALUES (?, ?, ?, ?, ?, ?, 'proposed')");
        $stmt->execute([$title, $description, $userId, $due_date, $priority, $category]);
        $taskId = $pdo->lastInsertId();

        $assignStmt = $pdo->prepare("INSERT INTO task_assignees (task_id, user_id, status) VALUES (?, ?, 'pending')");
        foreach ($assignees as $uid) { $assignStmt->execute([$taskId, $uid]); }

        if (isset($_FILES['task_file']) && $_FILES['task_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/uploads/tasks/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $ext = strtolower(pathinfo($_FILES['task_file']['name'], PATHINFO_EXTENSION));
            $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'];
            if (in_array($ext, $allowedExt)) {
                $newName = 'task_' . $taskId . '_' . time() . '.' . $ext;
                $dest = $uploadDir . $newName;
                if (move_uploaded_file($_FILES['task_file']['tmp_name'], $dest)) {
                    $fileStmt = $pdo->prepare("INSERT INTO task_files (task_id, file_name, original_name, file_path, uploaded_by) VALUES (?, ?, ?, ?, ?)");
                    $fileStmt->execute([$taskId, $newName, $_FILES['task_file']['name'], 'uploads/tasks/' . $newName, $userId]);
                }
            }
        }

        $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message, link, is_read, created_at) VALUES (?, 'task', ?, 'tasks.php', 0, NOW())");
        foreach ($assignees as $uid) { $notifStmt->execute([$uid, "Новая задача: $title"]); }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'task_id' => $taskId]);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_report') {
    header('Content-Type: application/json');
    $taskId = (int)($_POST['task_id'] ?? 0);
    if ($taskId <= 0) { echo json_encode(['status' => 'error', 'message' => 'Неверный ID задачи']); exit; }

    $check = $pdo->prepare("SELECT id FROM task_assignees WHERE task_id = ? AND user_id = ?");
    $check->execute([$taskId, $userId]);
    if (!$check->fetch()) { echo json_encode(['status' => 'error', 'message' => 'Вы не являетесь исполнителем этой задачи']); exit; }

    if (!isset($_FILES['report_file']) || $_FILES['report_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Файл отчёта не загружен']); exit;
    }

    $uploadDir = __DIR__ . '/uploads/reports/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    $ext = strtolower(pathinfo($_FILES['report_file']['name'], PATHINFO_EXTENSION));
    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
    if (!in_array($ext, $allowedExt)) { echo json_encode(['status' => 'error', 'message' => 'Недопустимый формат файла']); exit; }

    $newName = 'report_' . $taskId . '_' . $userId . '_' . time() . '.' . $ext;
    $dest = $uploadDir . $newName;
    if (!move_uploaded_file($_FILES['report_file']['tmp_name'], $dest)) {
        echo json_encode(['status' => 'error', 'message' => 'Не удалось сохранить файл']); exit;
    }

    $update = $pdo->prepare("UPDATE task_assignees SET report_submitted = 1, report_file = ?, submitted_at = NOW() WHERE task_id = ? AND user_id = ?");
    $update->execute(['uploads/reports/' . $newName, $taskId, $userId]);

    $author = $pdo->prepare("SELECT created_by FROM tasks WHERE id = ?");
    $author->execute([$taskId]);
    $authorId = $author->fetchColumn();
    if ($authorId && $authorId != $userId) {
        $notif = $pdo->prepare("INSERT INTO notifications (user_id, type, message, link, is_read, created_at) VALUES (?, 'report', ?, 'tasks.php?get_task_details=$taskId', 0, NOW())");
        $notif->execute([$authorId, "Пользователь сдал отчёт по задаче: $taskId"]);
    }

    echo json_encode(['status' => 'success']); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_task_assignee') {
    header('Content-Type: application/json');
    $taskId = (int)($_POST['task_id'] ?? 0);
    if ($taskId <= 0) { echo json_encode(['status' => 'error', 'message' => 'Неверный ID задачи']); exit; }

    $check = $pdo->prepare("SELECT id, report_submitted FROM task_assignees WHERE task_id = ? AND user_id = ?");
    $check->execute([$taskId, $userId]);
    $assignee = $check->fetch(PDO::FETCH_ASSOC);
    if (!$assignee) { echo json_encode(['status' => 'error', 'message' => 'Вы не являетесь исполнителем этой задачи']); exit; }
    if ($assignee['report_submitted'] != 1) { echo json_encode(['status' => 'error', 'message' => 'Сначала сдайте отчёт']); exit; }

    $update = $pdo->prepare("UPDATE task_assignees SET status = 'completed', completed_at = NOW() WHERE task_id = ? AND user_id = ?");
    $update->execute([$taskId, $userId]);

    $allCompleted = $pdo->prepare("SELECT COUNT(*) FROM task_assignees WHERE task_id = ? AND status != 'completed'");
    $allCompleted->execute([$taskId]);
    if ($allCompleted->fetchColumn() == 0) {
        $pdo->prepare("UPDATE tasks SET status = 'completed', completion_time = NOW() WHERE id = ?")->execute([$taskId]);
    }

    echo json_encode(['status' => 'success']); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'vote_task') {
    header('Content-Type: application/json');
    $taskId = (int)($_POST['task_id'] ?? 0);
    $vote = (int)($_POST['vote'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');

    if ($taskId <= 0) { echo json_encode(['status' => 'error', 'message' => 'Неверный ID задачи']); exit; }
    if (!in_array($vote, [1, -1])) { echo json_encode(['status' => 'error', 'message' => 'Неверное значение голоса']); exit; }
    if (empty($comment)) { echo json_encode(['status' => 'error', 'message' => 'Комментарий обязателен']); exit; }

    $taskCheck = $pdo->prepare("SELECT id FROM tasks WHERE id = ?");
    $taskCheck->execute([$taskId]);
    if (!$taskCheck->fetch()) { echo json_encode(['status' => 'error', 'message' => 'Задача не найдена']); exit; }

    try {
        $existing = $pdo->prepare("SELECT id FROM task_votes WHERE task_id = ? AND user_id = ?");
        $existing->execute([$taskId, $userId]);
        $voteRow = $existing->fetch(PDO::FETCH_ASSOC);

        if ($voteRow) {
            $update = $pdo->prepare("UPDATE task_votes SET vote = :vote, comment = :comment, created_at = NOW() WHERE id = :id");
            $update->bindValue(':vote', $vote, PDO::PARAM_INT);
            $update->bindValue(':comment', $comment, PDO::PARAM_STR);
            $update->bindValue(':id', $voteRow['id'], PDO::PARAM_INT);
            $update->execute();
        } else {
            $insert = $pdo->prepare("INSERT INTO task_votes (task_id, user_id, vote, comment, created_at) VALUES (:task_id, :user_id, :vote, :comment, NOW())");
            $insert->bindValue(':task_id', $taskId, PDO::PARAM_INT);
            $insert->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $insert->bindValue(':vote', $vote, PDO::PARAM_INT);
            $insert->bindValue(':comment', $comment, PDO::PARAM_STR);
            $insert->execute();
        }
        echo json_encode(['status' => 'success']); exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); exit;
    }
}

// 5. ОБНОВЛЕНИЕ СТАТУСА ЗАДАЧИ — используется и для кнопок, и для drag-and-drop
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_task_status') {
    header('Content-Type: application/json');
    $taskId = (int)($_POST['task_id'] ?? 0);
    $newStatus = $_POST['status'] ?? '';

    if ($taskId <= 0) { echo json_encode(['status' => 'error', 'message' => 'Неверный ID задачи']); exit; }

    $allowedStatuses = ['proposed', 'voting', 'approved', 'in_progress', 'completed', 'rejected'];
    if (!in_array($newStatus, $allowedStatuses)) { echo json_encode(['status' => 'error', 'message' => 'Недопустимый статус']); exit; }

    $taskInfo = $pdo->prepare("SELECT created_by FROM tasks WHERE id = ?");
    $taskInfo->execute([$taskId]);
    $task = $taskInfo->fetch(PDO::FETCH_ASSOC);
    if (!$task) { echo json_encode(['status' => 'error', 'message' => 'Задача не найдена']); exit; }

    if (!in_array($userRole, ['admin', 'semi-admin']) && $task['created_by'] != $userId) {
        echo json_encode(['status' => 'error', 'message' => 'Недостаточно прав']); exit;
    }

    try {
        $update = $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ?");
        $update->execute([$newStatus, $taskId]);
        if ($newStatus === 'completed') {
            $pdo->prepare("UPDATE tasks SET completion_time = NOW() WHERE id = ?")->execute([$taskId]);
        }
        echo json_encode(['status' => 'success']); exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_task_file') {
    header('Content-Type: application/json');
    $fileId = (int)($_POST['file_id'] ?? 0);
    if ($fileId <= 0) { echo json_encode(['status' => 'error', 'message' => 'Неверный ID файла']); exit; }

    $fileInfo = $pdo->prepare("SELECT tf.*, t.created_by FROM task_files tf JOIN tasks t ON tf.task_id = t.id WHERE tf.id = ?");
    $fileInfo->execute([$fileId]);
    $file = $fileInfo->fetch(PDO::FETCH_ASSOC);
    if (!$file) { echo json_encode(['status' => 'error', 'message' => 'Файл не найден']); exit; }

    if (!in_array($userRole, ['admin', 'semi-admin']) && $file['created_by'] != $userId) {
        echo json_encode(['status' => 'error', 'message' => 'Недостаточно прав']); exit;
    }

    try {
        $delete = $pdo->prepare("DELETE FROM task_files WHERE id = ?");
        $delete->execute([$fileId]);
        if (file_exists(__DIR__ . '/' . $file['file_path'])) @unlink(__DIR__ . '/' . $file['file_path']);
        echo json_encode(['status' => 'success']); exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); exit;
    }
}

// =========================================================================
// ВЫБОРКА ДАННЫХ
// =========================================================================
$filterStatus = $_GET['status'] ?? '';
$filterCategory = $_GET['category'] ?? '';
$filterPriority = $_GET['priority'] ?? '';
$filterAuthor = isset($_GET['author']) ? (int)$_GET['author'] : 0;
$filterAssignee = isset($_GET['assignee']) ? (int)$_GET['assignee'] : 0;

$sql = "SELECT t.*, u.login AS author_name,
               (SELECT COUNT(*) FROM task_votes WHERE task_id = t.id AND vote = 1) AS votes_for,
               (SELECT COUNT(*) FROM task_votes WHERE task_id = t.id AND vote = -1) AS votes_against,
               (SELECT COUNT(*) FROM task_votes WHERE task_id = t.id) AS total_votes,
               (SELECT GROUP_CONCAT(DISTINCT u2.login SEPARATOR ', ') FROM task_assignees ta JOIN users u2 ON ta.user_id = u2.id WHERE ta.task_id = t.id) AS assignees,
               (SELECT COUNT(*) FROM task_assignees WHERE task_id = t.id AND report_submitted = 1) AS reports_submitted_count,
               (SELECT COUNT(*) FROM task_assignees WHERE task_id = t.id AND status = 'completed') AS completed_count,
               (SELECT COUNT(*) FROM task_assignees WHERE task_id = t.id) AS total_assignees
        FROM tasks t
        LEFT JOIN users u ON t.created_by = u.id
        WHERE 1=1";

$params = [];
if ($filterStatus !== '') { $sql .= " AND t.status = ?"; $params[] = $filterStatus; }
if ($filterCategory !== '') { $sql .= " AND t.category = ?"; $params[] = $filterCategory; }
if ($filterPriority !== '') { $sql .= " AND t.priority = ?"; $params[] = $filterPriority; }
if ($filterAuthor > 0) { $sql .= " AND t.created_by = ?"; $params[] = $filterAuthor; }
if ($filterAssignee > 0) { $sql .= " AND EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ?)"; $params[] = $filterAssignee; }

$sql .= " ORDER BY t.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tasks = $stmt->fetchAll();

$allUsers = $pdo->query("SELECT id, login FROM users ORDER BY login")->fetchAll();

// AJAX ДЕТАЛИ ЗАДАЧИ
if (isset($_GET['get_task_details']) && (int)$_GET['get_task_details'] > 0) {
    header('Content-Type: application/json');
    $taskId = (int)$_GET['get_task_details'];
    try {
        $detailStmt = $pdo->prepare("SELECT t.*, u.login AS author_name,
                                     (SELECT COUNT(*) FROM task_votes WHERE task_id = t.id AND vote = 1) AS votes_for,
                                     (SELECT COUNT(*) FROM task_votes WHERE task_id = t.id AND vote = -1) AS votes_against,
                                     (SELECT COUNT(*) FROM task_votes WHERE task_id = t.id) AS total_votes
                                     FROM tasks t LEFT JOIN users u ON t.created_by = u.id WHERE t.id = ?");
        $detailStmt->execute([$taskId]);
        $task = $detailStmt->fetch(PDO::FETCH_ASSOC);
        if (!$task) { echo json_encode(['status' => 'error', 'message' => 'Задача не найдена']); exit; }

        $assigneesStmt = $pdo->prepare("SELECT ta.*, u.login FROM task_assignees ta JOIN users u ON ta.user_id = u.id WHERE ta.task_id = ?");
        $assigneesStmt->execute([$taskId]);
        $task['assignees'] = $assigneesStmt->fetchAll();

        $fileStmt = $pdo->prepare("SELECT * FROM task_files WHERE task_id = ? ORDER BY uploaded_at DESC");
        $fileStmt->execute([$taskId]);
        $task['files'] = $fileStmt->fetchAll();

        $voteStmt = $pdo->prepare("SELECT v.*, u.login AS user_login FROM task_votes v JOIN users u ON v.user_id = u.id WHERE v.task_id = ? ORDER BY v.created_at DESC");
        $voteStmt->execute([$taskId]);
        $task['votes'] = $voteStmt->fetchAll();

        echo json_encode(['status' => 'success', 'data' => $task]); exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); exit;
    }
}

// Помечаем статусы, которые можно перетаскивать
$dragAllowed = in_array($userRole, ['admin', 'semi-admin', 'manager']);

// Хелперы для рендера карточек
function categoryIcon($cat) {
    return ['task' => '📋', 'proposal' => '💡', 'question' => '❓'][$cat] ?? '📋';
}
function priorityColor($p) {
    return ['low' => '#818cf8', 'medium' => '#f59e0b', 'high' => '#ef4444'][$p] ?? '#6b6b85';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Задачи — Santeks CRM</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #0f0f1a; color: #fff; font-family: 'Segoe UI', Roboto, sans-serif; display: flex; min-height: 100vh; margin: 0; }

        main {
            flex: 1; padding: 25px 30px; min-width: 0;
            box-sizing: border-box; overflow-y: auto;
            display: flex; flex-direction: column; gap: 18px;
            max-height: 100vh;
        }

        /* ================== TOPBAR ================== */
        .topbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 24px; background: #1e1e2d;
            border: 1px solid #323248; border-radius: 12px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.3);
            flex-wrap: wrap; gap: 12px;
        }
        .topbar h1 { margin: 0; font-size: 19px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .topbar-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

        .view-switch {
            display: flex; background: #151521; border-radius: 8px; padding: 3px;
            border: 1px solid #323248;
        }
        .view-btn {
            background: transparent; border: none; color: #92929f;
            padding: 7px 14px; border-radius: 6px; cursor: pointer;
            font-size: 12.5px; font-weight: 600; transition: all 0.2s ease;
            display: flex; align-items: center; gap: 6px;
        }
        .view-btn:hover { color: #fff; }
        .view-btn.active { background: #4f46e5; color: #fff; box-shadow: 0 2px 8px rgba(79,70,229,0.4); }

        /* ================== BUTTONS ================== */
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 18px; border-radius: 8px; font-weight: 600;
            font-size: 13px; cursor: pointer; transition: all 0.25s ease;
            border: none; text-decoration: none; white-space: nowrap;
        }
        .btn-primary { background: #4f46e5; color: #fff; }
        .btn-primary:hover { background: #6366f1; transform: translateY(-1px); box-shadow: 0 4px 15px rgba(79,70,229,0.3); }
        .btn-outline { background: transparent; border: 1px solid #323248; color: #92929f; }
        .btn-outline:hover { border-color: #4f46e5; color: #fff; background: rgba(79,70,229,0.08); }
        .btn-success { background: #10b981; color: #fff; }
        .btn-success:hover { background: #059669; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-danger:hover { background: #dc2626; }
        .btn-sm { padding: 5px 12px; font-size: 12px; }

        /* ================== FILTERS ================== */
        .filter-bar {
            background: #1e1e2d; border: 1px solid #323248;
            border-radius: 12px; padding: 14px 20px;
        }
        .filter-form { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 14px; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; min-width: 140px; }
        .filter-group label {
            font-size: 10.5px; color: #92929f; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.3px;
        }
        .filter-select {
            padding: 8px 12px; background: #151521; border: 1px solid #323248;
            border-radius: 8px; color: #fff; font-size: 13px;
            outline: none; height: 38px; width: 100%; min-width: 140px;
            cursor: pointer; transition: border-color 0.2s;
        }
        .filter-select:focus { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,0.15); }
        .filter-actions { display: flex; gap: 10px; align-items: center; }

        /* ================== KANBAN ================== */
        .kanban-view { display: none; }
        .kanban-board {
            display: grid;
            grid-template-columns: repeat(6, minmax(280px, 1fr));
            gap: 14px;
            overflow-x: auto;
            padding-bottom: 10px;
        }
        .kanban-board::-webkit-scrollbar { height: 8px; }
        .kanban-board::-webkit-scrollbar-track { background: transparent; }
        .kanban-board::-webkit-scrollbar-thumb { background: #323248; border-radius: 8px; }
        .kanban-board::-webkit-scrollbar-thumb:hover { background: #4f46e5; }

        .kanban-column {
            background: #171725;
            border: 1px solid #26263a;
            border-radius: 12px;
            display: flex; flex-direction: column;
            min-height: 400px;
            transition: border-color 0.2s, background 0.2s;
        }
        .kanban-column.drag-over {
            border-color: #4f46e5;
            background: rgba(79,70,229,0.05);
        }

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

        .kanban-column[data-status="proposed"] .kanban-column-header { color: #818cf8; border-top: 3px solid #818cf8; }
        .kanban-column[data-status="voting"] .kanban-column-header { color: #f59e0b; border-top: 3px solid #f59e0b; }
        .kanban-column[data-status="approved"] .kanban-column-header { color: #10b981; border-top: 3px solid #10b981; }
        .kanban-column[data-status="in_progress"] .kanban-column-header { color: #6366f1; border-top: 3px solid #6366f1; }
        .kanban-column[data-status="completed"] .kanban-column-header { color: #059669; border-top: 3px solid #059669; }
        .kanban-column[data-status="rejected"] .kanban-column-header { color: #ef4444; border-top: 3px solid #ef4444; }

        .kanban-column-body {
            padding: 10px;
            display: flex; flex-direction: column; gap: 10px;
            flex: 1; overflow-y: auto;
            max-height: calc(100vh - 300px);
            transition: background 0.2s;
        }
        .kanban-column-body::-webkit-scrollbar { width: 6px; }
        .kanban-column-body::-webkit-scrollbar-thumb { background: #26263a; border-radius: 6px; }
        .kanban-column-body::-webkit-scrollbar-thumb:hover { background: #4f46e5; }

        /* ================== KANBAN CARD ================== */
        .kanban-card {
            background: #1e1e2d;
            border: 1px solid #2a2a3f;
            border-radius: 10px;
            padding: 12px 13px 10px 15px;
            position: relative;
            cursor: grab;
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
            overflow: hidden;
        }
        .kanban-card::before {
            content: '';
            position: absolute; left: 0; top: 0; bottom: 0;
            width: 3px; background: var(--priority-color, #6b6b85);
        }
        .kanban-card:hover {
            border-color: #4f46e5;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.35);
        }
        .kanban-card.dragging {
            opacity: 0.4;
            cursor: grabbing;
            transform: rotate(2deg);
        }

        .card-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 8px;
        }
        .card-category {
            font-size: 14px;
            display: inline-flex; align-items: center; gap: 5px;
            color: #92929f; font-size: 11px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.3px;
        }
        .card-votes {
            font-size: 11px; font-family: monospace;
            color: #92929f; display: flex; gap: 6px;
        }
        .card-votes .for { color: #10b981; }
        .card-votes .against { color: #ef4444; }

        .card-title {
            color: #fff; font-size: 13.5px; font-weight: 600;
            line-height: 1.35; margin-bottom: 10px;
            display: -webkit-box; -webkit-line-clamp: 2;
            -webkit-box-orient: vertical; overflow: hidden;
            word-break: break-word;
        }
        .card-title:hover { color: #818cf8; }

        .card-meta {
            display: flex; justify-content: space-between; align-items: center;
            font-size: 11px; color: #71717a; margin-bottom: 10px;
        }
        .card-meta .meta-item { display: flex; align-items: center; gap: 4px; }
        .card-meta .overdue { color: #ef4444 !important; font-weight: 700; }
        .card-meta .reports-done { color: #10b981; font-weight: 700; }

        .card-footer {
            display: flex; justify-content: space-between; align-items: center;
            border-top: 1px solid #26263a; padding-top: 8px; margin-top: 4px;
        }

        .card-avatars { display: flex; gap: -4px; }
        .avatar {
            width: 24px; height: 24px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4f46e5, #818cf8);
            display: flex; align-items: center; justify-content: center;
            font-size: 10px; font-weight: 700; color: #fff;
            border: 2px solid #1e1e2d;
            margin-left: -6px;
            text-transform: uppercase;
        }
        .avatar:first-child { margin-left: 0; }
        .avatar.more { background: #323248; color: #92929f; }

        .card-open {
            background: rgba(79,70,229,0.1);
            border: 1px solid rgba(79,70,229,0.3);
            color: #818cf8;
            padding: 3px 10px; border-radius: 6px;
            font-size: 11px; font-weight: 600;
            cursor: pointer; transition: all 0.2s;
        }
        .card-open:hover { background: #4f46e5; color: #fff; border-color: #4f46e5; }

        .kanban-empty {
            color: #4b4b5e; font-size: 12px;
            text-align: center; padding: 20px 10px;
            border: 1px dashed #26263a; border-radius: 8px;
            font-style: italic;
        }

        /* ================== TABLE ================== */
        .table-wrapper {
            background: #1a1a28; border: 1px solid #323248;
            border-radius: 14px; overflow-x: auto;
            box-shadow: 0 8px 35px rgba(0,0,0,0.4);
        }
        .data-table {
            width: 100%; border-collapse: collapse; font-size: 13px;
            background: #1a1a28; min-width: 1300px;
        }
        .data-table th {
            background: #242438; color: #92929f;
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
            padding: 14px 12px; text-align: left;
            border-bottom: 2px solid #323248; white-space: nowrap;
        }
        .data-table td {
            padding: 12px; border-bottom: 1px solid #26263a;
            color: #cbd5e1; vertical-align: middle;
        }
        .data-table tbody tr:hover td { background: #1e1e32; }
        .task-title { color: #fff; font-weight: 600; cursor: pointer; }
        .task-title:hover { color: #818cf8; }

        .badge-status {
            display: inline-block; padding: 3px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 700; text-transform: uppercase; white-space: nowrap;
        }
        .badge-status.proposed { background: rgba(129,140,248,0.12); color: #818cf8; border:1px solid rgba(129,140,248,0.2); }
        .badge-status.voting { background: rgba(245,158,11,0.12); color: #f59e0b; border:1px solid rgba(245,158,11,0.2); }
        .badge-status.approved { background: rgba(16,185,129,0.12); color: #10b981; border:1px solid rgba(16,185,129,0.2); }
        .badge-status.in_progress { background: rgba(79,70,229,0.12); color: #6366f1; border:1px solid rgba(79,70,229,0.2); }
        .badge-status.completed { background: rgba(16,185,129,0.2); color: #059669; border:1px solid rgba(16,185,129,0.3); }
        .badge-status.rejected { background: rgba(239,68,68,0.12); color: #ef4444; border:1px solid rgba(239,68,68,0.2); }
        .priority-low { color: #818cf8; }
        .priority-medium { color: #f59e0b; }
        .priority-high { color: #ef4444; }
        .vote-badge { font-weight: 600; font-family: monospace; font-size: 14px; }
        .vote-badge .for { color: #10b981; }
        .vote-badge .against { color: #ef4444; }
        .btn-detail-sm {
            background: transparent; border: 1px solid #323248; color: #92929f;
            padding: 3px 10px; border-radius: 4px; font-size: 12px;
            cursor: pointer; transition: all 0.25s ease;
        }
        .btn-detail-sm:hover { border-color: #4f46e5; color: #fff; }

        /* ================== MODAL ================== */
        .modal-overlay {
            display: none; position: fixed; top: 0; left: 0;
            width: 100%; height: 100%; background: rgba(0,0,0,0.75);
            justify-content: center; align-items: center;
            z-index: 99999; padding: 20px; backdrop-filter: blur(4px);
        }
        .modal-content {
            background: #1e1e2d; border: 1px solid #323248;
            border-radius: 16px; padding: 28px;
            max-width: 90%; max-height: 90vh; overflow-y: auto;
            box-shadow: 0 25px 60px rgba(0,0,0,0.5);
            animation: modalSlideIn 0.3s ease;
        }
        @keyframes modalSlideIn { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; width: 100%; }
        .form-group label {
            font-size: 11px; font-weight: 700; color: #92929f;
            text-transform: uppercase; letter-spacing: 0.3px;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 14px; background: #151521;
            border: 1px solid #323248; border-radius: 8px; color: #fff;
            font-size: 13px; outline: none; font-family: inherit;
            transition: border-color 0.25s, box-shadow 0.25s;
            box-sizing: border-box;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,0.15);
        }
        .form-group textarea { resize: vertical; min-height: 80px; }

        .assignee-item {
            display: flex; justify-content: space-between; align-items: center;
            padding: 8px 12px; background: #151521; border-radius: 6px;
            margin-bottom: 5px; border-left: 3px solid #323248;
        }
        .assignee-item.completed { border-left-color: #10b981; }
        .assignee-item .status-badge { font-size: 11px; font-weight: 600; }
        .assignee-item .status-badge.pending { color: #f59e0b; }
        .assignee-item .status-badge.completed { color: #10b981; }
        .assignee-item .report-badge {
            font-size: 11px; padding: 2px 8px; border-radius: 4px;
            background: rgba(16,185,129,0.12); color: #10b981;
        }
        .assignee-item .report-badge.not-submitted { background: rgba(239,68,68,0.12); color: #ef4444; }
        .assignee-item .report-badge a { color: #10b981; text-decoration: none; }

        .vote-item {
            background: #151521; border-radius: 8px;
            padding: 10px 14px; margin-bottom: 8px;
            border-left: 3px solid #323248;
        }
        .vote-item.for { border-left-color: #10b981; }
        .vote-item.against { border-left-color: #ef4444; }
        .vote-item .vote-user { font-weight: 600; color: #fff; }
        .vote-item .vote-comment { color: #cbd5e1; font-size: 13px; margin-top: 4px; }
        .vote-item .vote-date { font-size: 11px; color: #6b6b85; }

        .file-item {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 12px; background: #151521;
            border-radius: 6px; margin-bottom: 5px;
        }
        .file-item a { color: #818cf8; text-decoration: none; flex: 1; }
        .file-item a:hover { text-decoration: underline; }
        .file-item .delete-file {
            background: rgba(239,68,68,0.1); color: #ef4444;
            border: 1px solid rgba(239,68,68,0.3);
            padding: 2px 8px; border-radius: 4px; cursor: pointer;
            font-size: 11px;
        }
        .file-item .delete-file:hover { background: #ef4444; color: #fff; }

        .empty-state { text-align: center; padding: 40px; color: #4b4b5e; }

        /* Responsive */
        @media (max-width: 1100px) {
            .kanban-board { grid-template-columns: repeat(6, 260px); }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <main>
        <div class="topbar">
            <h1>📋 Задачи и голосование</h1>
            <div class="topbar-actions">
                <div class="view-switch">
                    <button class="view-btn active" data-view="kanban" onclick="switchView('kanban')">📊 Канбан</button>
                    <button class="view-btn" data-view="table" onclick="switchView('table')">📋 Таблица</button>
                </div>
                <?php if (in_array($userRole, ['admin', 'semi-admin', 'manager'])): ?>
                    <button class="btn btn-primary" onclick="openCreateTaskModal()">➕ Создать задачу</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="filter-bar">
            <form method="GET" action="tasks.php" class="filter-form">
                <div class="filter-group">
                    <label>Статус</label>
                    <select name="status" class="filter-select" onchange="this.form.submit()">
                        <option value="">Все</option>
                        <option value="proposed" <?= $filterStatus == 'proposed' ? 'selected' : '' ?>>Предложена</option>
                        <option value="voting" <?= $filterStatus == 'voting' ? 'selected' : '' ?>>Голосование</option>
                        <option value="approved" <?= $filterStatus == 'approved' ? 'selected' : '' ?>>Утверждена</option>
                        <option value="in_progress" <?= $filterStatus == 'in_progress' ? 'selected' : '' ?>>В работе</option>
                        <option value="completed" <?= $filterStatus == 'completed' ? 'selected' : '' ?>>Выполнена</option>
                        <option value="rejected" <?= $filterStatus == 'rejected' ? 'selected' : '' ?>>Отклонена</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Категория</label>
                    <select name="category" class="filter-select" onchange="this.form.submit()">
                        <option value="">Все</option>
                        <option value="task" <?= $filterCategory == 'task' ? 'selected' : '' ?>>Задача</option>
                        <option value="proposal" <?= $filterCategory == 'proposal' ? 'selected' : '' ?>>Предложение</option>
                        <option value="question" <?= $filterCategory == 'question' ? 'selected' : '' ?>>Вопрос</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Приоритет</label>
                    <select name="priority" class="filter-select" onchange="this.form.submit()">
                        <option value="">Все</option>
                        <option value="low" <?= $filterPriority == 'low' ? 'selected' : '' ?>>Низкий</option>
                        <option value="medium" <?= $filterPriority == 'medium' ? 'selected' : '' ?>>Средний</option>
                        <option value="high" <?= $filterPriority == 'high' ? 'selected' : '' ?>>Высокий</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Автор</label>
                    <select name="author" class="filter-select" onchange="this.form.submit()">
                        <option value="0">Все</option>
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $filterAuthor == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['login']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Исполнитель</label>
                    <select name="assignee" class="filter-select" onchange="this.form.submit()">
                        <option value="0">Все</option>
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $filterAssignee == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['login']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">🔍 Применить</button>
                    <a href="tasks.php" class="btn btn-outline">❌ Сбросить</a>
                </div>
            </form>
        </div>

        <!-- ========== KANBAN VIEW ========== -->
        <div id="kanbanView" class="kanban-view">
            <div class="kanban-board">
                <?php
                $columns = [
                    'proposed'    => ['icon' => '📝', 'title' => 'Предложена'],
                    'voting'      => ['icon' => '🗳', 'title' => 'Голосование'],
                    'approved'    => ['icon' => '✅', 'title' => 'Утверждена'],
                    'in_progress' => ['icon' => '🚀', 'title' => 'В работе'],
                    'completed'   => ['icon' => '🏁', 'title' => 'Выполнена'],
                    'rejected'    => ['icon' => '❌', 'title' => 'Отклонена'],
                ];

                // Группируем задачи по статусу
                $tasksByStatus = array_fill_keys(array_keys($columns), []);
                foreach ($tasks as $t) {
                    if (isset($tasksByStatus[$t['status']])) {
                        $tasksByStatus[$t['status']][] = $t;
                    }
                }

                foreach ($columns as $statusKey => $col):
                    $colTasks = $tasksByStatus[$statusKey];
                ?>
                <div class="kanban-column" data-status="<?= $statusKey ?>">
                    <div class="kanban-column-header">
                        <span><?= $col['icon'] ?> <?= $col['title'] ?></span>
                        <span class="col-count" data-count-for="<?= $statusKey ?>"><?= count($colTasks) ?></span>
                    </div>
                    <div class="kanban-column-body"
                         data-drop-status="<?= $statusKey ?>"
                         ondragover="onDragOver(event)"
                         ondragleave="onDragLeave(event)"
                         ondrop="onDrop(event, '<?= $statusKey ?>')">
                        <?php if (empty($colTasks)): ?>
                            <div class="kanban-empty">Нет задач</div>
                        <?php else: ?>
                            <?php foreach ($colTasks as $task):
                                $reportsCount = (int)$task['reports_submitted_count'];
                                $totalAssignees = (int)$task['total_assignees'];
                                $completedCount = (int)$task['completed_count'];
                                $assigneesList = array_filter(array_map('trim', explode(',', $task['assignees'] ?? '')));

                                // Инициалы
                                $initials = [];
                                foreach (array_slice($assigneesList, 0, 3) as $name) {
                                    $parts = preg_split('/[\s._-]+/', $name);
                                    $init = '';
                                    foreach (array_slice($parts, 0, 2) as $p) {
                                        if ($p !== '') $init .= mb_strtoupper(mb_substr($p, 0, 1));
                                    }
                                    $initials[] = $init ?: mb_strtoupper(mb_substr($name, 0, 2));
                                }
                                $extraAssignees = max(0, count($assigneesList) - 3);

                                $isOverdue = false;
                                if (!empty($task['due_date']) && !in_array($task['status'], ['completed', 'rejected'])) {
                                    $isOverdue = strtotime($task['due_date']) < strtotime(date('Y-m-d'));
                                }
                                $catIcon = categoryIcon($task['category']);
                                $prioColor = priorityColor($task['priority']);
                                $canDrag = $dragAllowed || ($task['created_by'] == $userId);
                            ?>
                            <div class="kanban-card"
                                 draggable="<?= $canDrag ? 'true' : 'false' ?>"
                                 data-task-id="<?= (int)$task['id'] ?>"
                                 data-status="<?= htmlspecialchars($task['status']) ?>"
                                 style="--priority-color: <?= $prioColor ?>;"
                                 ondragstart="onDragStart(event, <?= (int)$task['id'] ?>)"
                                 ondragend="onDragEnd(event)">
                                <div class="card-header">
                                    <span class="card-category"><?= $catIcon ?> <?= strtoupper($task['category']) ?></span>
                                    <span class="card-votes">
                                        <span class="for">👍 <?= (int)$task['votes_for'] ?></span>
                                        <span class="against">👎 <?= (int)$task['votes_against'] ?></span>
                                    </span>
                                </div>
                                <div class="card-title" onclick="openTaskDetail(<?= (int)$task['id'] ?>)">
                                    <?= htmlspecialchars($task['title']) ?>
                                </div>
                                <div class="card-meta">
                                    <span class="meta-item <?= $isOverdue ? 'overdue' : '' ?>">
                                        <?= $isOverdue ? '⚠' : '📅' ?>
                                        <?= !empty($task['due_date']) ? date('d.m.Y', strtotime($task['due_date'])) : '—' ?>
                                    </span>
                                    <span class="meta-item <?= ($totalAssignees > 0 && $reportsCount === $totalAssignees) ? 'reports-done' : '' ?>">
                                        📄 <?= $reportsCount ?>/<?= $totalAssignees ?>
                                    </span>
                                </div>
                                <div class="card-footer">
                                    <div class="card-avatars">
                                        <?php foreach ($initials as $ini): ?>
                                            <span class="avatar" title="<?= htmlspecialchars(implode(', ', $assigneesList)) ?>"><?= htmlspecialchars($ini) ?></span>
                                        <?php endforeach; ?>
                                        <?php if ($extraAssignees > 0): ?>
                                            <span class="avatar more">+<?= $extraAssignees ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <button class="card-open" onclick="openTaskDetail(<?= (int)$task['id'] ?>)">Открыть →</button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ========== TABLE VIEW ========== -->
        <div id="tableView" class="table-wrapper" style="display:none;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:40px;">#</th>
                        <th style="min-width:150px;">Заголовок</th>
                        <th style="width:110px;">Автор</th>
                        <th style="width:100px;">Дата</th>
                        <th style="width:100px;">Срок</th>
                        <th style="width:80px; text-align:center;">Приоритет</th>
                        <th style="width:80px; text-align:center;">Категория</th>
                        <th style="width:100px; text-align:center;">Статус</th>
                        <th style="width:130px;">Исполнители</th>
                        <th style="width:100px; text-align:center;">Отчёты</th>
                        <th style="width:80px; text-align:center;">Голоса</th>
                        <th style="width:100px; text-align:center;">Действие</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tasks)): ?>
                        <tr><td colspan="12" class="empty-state">Задач не найдено</td></tr>
                    <?php else: ?>
                        <?php $i = 1; foreach ($tasks as $task):
                            $statusClass = 'badge-status ' . str_replace('_', '-', $task['status']);
                            $priorityLabel = ['low' => 'Низкий', 'medium' => 'Средний', 'high' => 'Высокий'][$task['priority']] ?? '—';
                            $priorityClass = 'priority-' . $task['priority'];
                            $categoryLabel = ['task' => 'Задача', 'proposal' => 'Предложение', 'question' => 'Вопрос'][$task['category']] ?? '—';
                            $statusLabels = [
                                'proposed' => 'Предложена', 'voting' => 'Голосование',
                                'approved' => 'Утверждена', 'in_progress' => 'В работе',
                                'completed' => 'Выполнена', 'rejected' => 'Отклонена'
                            ];
                        ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><span class="task-title" onclick="openTaskDetail(<?= $task['id'] ?>)"><?= htmlspecialchars($task['title']) ?></span></td>
                            <td><?= htmlspecialchars($task['author_name'] ?? 'Система') ?></td>
                            <td><?= !empty($task['created_at']) ? date('d.m.Y', strtotime($task['created_at'])) : '—' ?></td>
                            <td><?= !empty($task['due_date']) ? date('d.m.Y', strtotime($task['due_date'])) : '—' ?></td>
                            <td style="text-align:center;"><span class="<?= $priorityClass ?>"><?= $priorityLabel ?></span></td>
                            <td style="text-align:center;"><?= $categoryLabel ?></td>
                            <td style="text-align:center;"><span class="<?= $statusClass ?>"><?= $statusLabels[$task['status']] ?? $task['status'] ?></span></td>
                            <td><?= htmlspecialchars($task['assignees'] ?? '') ?></td>
                            <td style="text-align:center;"><?= (int)$task['reports_submitted_count'] ?> / <?= (int)$task['total_assignees'] ?></td>
                            <td style="text-align:center;">
                                <span class="vote-badge">
                                    <span class="for">👍 <?= (int)$task['votes_for'] ?></span>
                                    <span class="against">👎 <?= (int)$task['votes_against'] ?></span>
                                </span>
                            </td>
                            <td style="text-align:center;">
                                <button class="btn-detail-sm" onclick="openTaskDetail(<?= $task['id'] ?>)">Подробнее</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- MODAL: CREATE TASK -->
    <div id="createTaskModal" class="modal-overlay">
        <div class="modal-content" style="max-width: 700px; width: 100%;">
            <h2 style="margin-top:0; font-size:22px; margin-bottom:16px;">➕ Создать задачу</h2>
            <form id="createTaskForm" enctype="multipart/form-data" onsubmit="submitNewTask(event)">
                <div class="form-group">
                    <label>Заголовок *</label>
                    <input type="text" name="title" required placeholder="Краткое название">
                </div>
                <div class="form-group">
                    <label>Описание</label>
                    <textarea name="description" rows="4" placeholder="Подробное описание задачи"></textarea>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                    <div class="form-group">
                        <label>Срок выполнения</label>
                        <input type="date" name="due_date">
                    </div>
                    <div class="form-group">
                        <label>Приоритет</label>
                        <select name="priority">
                            <option value="low">Низкий</option>
                            <option value="medium" selected>Средний</option>
                            <option value="high">Высокий</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Категория</label>
                        <select name="category">
                            <option value="task">Задача</option>
                            <option value="proposal">Предложение</option>
                            <option value="question">Вопрос</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Исполнители *</label>
                    <select name="assignees[]" multiple required style="height: auto; min-height: 80px;">
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $u['id'] == $userId ? 'selected' : '' ?>><?= htmlspecialchars($u['login']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:#6b6b85;">Зажмите Ctrl для выбора нескольких</small>
                </div>
                <div class="form-group">
                    <label>Прикрепить файл (необязательно)</label>
                    <input type="file" name="task_file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.txt,.zip" style="padding:8px; background:#151521; border:1px solid #323248; border-radius:8px; color:#cbd5e1;">
                </div>
                <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:20px;">
                    <button type="button" class="btn btn-outline" onclick="closeCreateTaskModal()">Отмена</button>
                    <button type="submit" class="btn btn-primary">Создать</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: TASK DETAIL -->
    <div id="taskDetailModal" class="modal-overlay">
        <div class="modal-content" style="max-width: 800px; width: 100%;">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #323248; padding-bottom:12px; margin-bottom:20px;">
                <h2 id="detailTitle" style="margin:0; font-size:20px;">Загрузка...</h2>
                <button onclick="closeTaskDetail()" style="background:none; border:none; color:#92929f; font-size:24px; cursor:pointer;">&times;</button>
            </div>
            <div id="detailBody"></div>
        </div>
    </div>

    <script>
        // ============================================================
        // ПЕРЕКЛЮЧАТЕЛЬ ВИДА
        // ============================================================
        function switchView(view) {
            const kanban = document.getElementById('kanbanView');
            const table = document.getElementById('tableView');
            const btns = document.querySelectorAll('.view-btn');

            btns.forEach(b => b.classList.remove('active'));
            const activeBtn = document.querySelector(`.view-btn[data-view="${view}"]`);
            if (activeBtn) activeBtn.classList.add('active');

            if (view === 'kanban') {
                kanban.style.display = 'block';
                table.style.display = 'none';
            } else {
                kanban.style.display = 'none';
                table.style.display = 'block';
            }
            localStorage.setItem('tasks_view', view);
        }

        document.addEventListener('DOMContentLoaded', function() {
            const savedView = localStorage.getItem('tasks_view') || 'kanban';
            switchView(savedView);
        });

        // ============================================================
        // DRAG & DROP
        // ============================================================
        let draggedTaskId = null;
        let draggedFromStatus = null;

        function onDragStart(e, taskId) {
            draggedTaskId = taskId;
            draggedFromStatus = e.target.dataset.status;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', taskId);
            e.target.classList.add('dragging');
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

        async function onDrop(e, newStatus) {
            e.preventDefault();
            const columnBody = e.currentTarget;
            const column = columnBody.closest('.kanban-column');
            if (column) column.classList.remove('drag-over');

            if (!draggedTaskId) return;
            if (draggedFromStatus === newStatus) {
                draggedTaskId = null;
                return;
            }

            const card = document.querySelector(`.kanban-card[data-task-id="${draggedTaskId}"]`);
            if (!card) { draggedTaskId = null; return; }

            // Оптимистичное перемещение
            const oldStatus = draggedFromStatus;
            card.dataset.status = newStatus;

            const emptyPlaceholder = columnBody.querySelector('.kanban-empty');
            if (emptyPlaceholder) emptyPlaceholder.remove();

            columnBody.appendChild(card);
            updateColumnCounts();

            // Убираем "пустое" сообщение из старой колонки, если там больше ничего нет
            const oldBody = document.querySelector(`.kanban-column-body[data-drop-status="${oldStatus}"]`);
            if (oldBody && oldBody.querySelectorAll('.kanban-card').length === 0) {
                if (!oldBody.querySelector('.kanban-empty')) {
                    const empty = document.createElement('div');
                    empty.className = 'kanban-empty';
                    empty.textContent = 'Нет задач';
                    oldBody.appendChild(empty);
                }
            }

            // AJAX
            const fd = new FormData();
            fd.append('action', 'update_task_status');
            fd.append('task_id', draggedTaskId);
            fd.append('status', newStatus);

            try {
                const res = await fetch('tasks.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status !== 'success') {
                    alert('❌ Ошибка: ' + (data.message || 'Неизвестно'));
                    // Откат
                    if (oldBody) {
                        oldBody.appendChild(card);
                        card.dataset.status = oldStatus;
                        const emptyOld = oldBody.querySelector('.kanban-empty');
                        if (emptyOld) emptyOld.remove();
                        updateColumnCounts();
                    }
                }
            } catch (err) {
                console.error(err);
                alert('❌ Ошибка сети. Обновление отменено.');
                if (oldBody) {
                    oldBody.appendChild(card);
                    card.dataset.status = oldStatus;
                    updateColumnCounts();
                }
            } finally {
                draggedTaskId = null;
                draggedFromStatus = null;
            }
        }

        function updateColumnCounts() {
            document.querySelectorAll('.kanban-column').forEach(col => {
                const status = col.dataset.status;
                const count = col.querySelectorAll('.kanban-card').length;
                const counter = col.querySelector(`[data-count-for="${status}"]`);
                if (counter) counter.textContent = count;
            });
        }

        // ============================================================
        // МОДАЛКИ
        // ============================================================
        function openCreateTaskModal() {
            document.getElementById('createTaskModal').style.display = 'flex';
        }
        function closeCreateTaskModal() {
            document.getElementById('createTaskModal').style.display = 'none';
        }
        function closeTaskDetail() {
            document.getElementById('taskDetailModal').style.display = 'none';
        }

        async function submitNewTask(e) {
            e.preventDefault();
            const form = e.target;
            const fd = new FormData(form);
            fd.append('action', 'create_task');

            try {
                const res = await fetch('tasks.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    alert('Задача создана!');
                    closeCreateTaskModal();
                    location.reload();
                } else {
                    alert('Ошибка: ' + data.message);
                }
            } catch (err) {
                alert('Ошибка соединения');
            }
        }

        async function openTaskDetail(taskId) {
            const modal = document.getElementById('taskDetailModal');
            const body = document.getElementById('detailBody');
            const title = document.getElementById('detailTitle');

            modal.style.display = 'flex';
            body.innerHTML = 'Загрузка...';

            try {
                const res = await fetch('tasks.php?get_task_details=' + taskId);
                const data = await res.json();
                if (data.status !== 'success') {
                    body.innerHTML = '<div style="color:red;">Ошибка загрузки</div>';
                    return;
                }
                const task = data.data;
                title.innerText = task.title;

                let html = '<div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; background:#151521; padding:15px; border-radius:8px; margin-bottom:15px;">';
                html += `<div><strong>Автор:</strong> ${task.author_name || 'Система'}</div>`;
                html += `<div><strong>Дата создания:</strong> ${task.created_at ? new Date(task.created_at).toLocaleDateString() : '—'}</div>`;
                html += `<div><strong>Срок:</strong> ${task.due_date ? new Date(task.due_date).toLocaleDateString() : 'Не указан'}</div>`;
                html += `<div><strong>Приоритет:</strong> ${task.priority}</div>`;
                html += `<div><strong>Категория:</strong> ${task.category}</div>`;
                html += `<div><strong>Статус:</strong> ${task.status}</div>`;
                html += `<div><strong>Голосов ЗА:</strong> <span style="color:#10b981;">${task.votes_for}</span></div>`;
                html += `<div><strong>Голосов ПРОТИВ:</strong> <span style="color:#ef4444;">${task.votes_against}</span></div>`;
                html += `<div style="grid-column: span 2;"><strong>Описание:</strong><br>${task.description || '—'}</div>`;
                html += '</div>';

                if (task.assignees && task.assignees.length > 0) {
                    html += `<h4 style="margin-top:15px; border-top:1px solid #323248; padding-top:15px;">👥 Исполнители</h4>`;
                    task.assignees.forEach(a => {
                        const statusText = a.status === 'completed' ? '✅ Выполнено' : (a.report_submitted ? '📄 Отчёт сдан' : '⏳ Ожидание');
                        const statusClass = a.status === 'completed' ? 'completed' : '';
                        html += `
                            <div class="assignee-item ${statusClass}">
                                <div>
                                    <strong>${a.login}</strong>
                                    <span class="status-badge ${a.status}">${statusText}</span>
                                    ${a.report_submitted ? ` <span class="report-badge">📎 <a href="${a.report_file}" target="_blank">Скачать отчёт</a></span>` : ''}
                                </div>
                                <div>
                                    ${a.status === 'completed' ? '<span class="status-badge completed">Завершено</span>' : ''}
                                </div>
                            </div>
                        `;
                    });

                    const currentAssignee = task.assignees.find(a => a.user_id == <?= $userId ?>);
                    if (currentAssignee) {
                        if (!currentAssignee.report_submitted) {
                            html += `
                                <div style="margin-top:15px; border-top:1px solid #323248; padding-top:15px;">
                                    <h4>📤 Сдать отчёт</h4>
                                    <form id="reportForm" onsubmit="submitReport(event, ${task.id})">
                                        <div class="form-group">
                                            <input type="file" name="report_file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.txt">
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-sm">Отправить отчёт</button>
                                    </form>
                                </div>
                            `;
                        } else if (currentAssignee.status !== 'completed') {
                            html += `
                                <div style="margin-top:15px; border-top:1px solid #323248; padding-top:15px;">
                                    <button onclick="completeTaskAssignee(${task.id})" class="btn btn-success btn-sm">✅ Завершить задачу</button>
                                </div>
                            `;
                        }
                    }
                }

                if (task.files && task.files.length > 0) {
                    html += `<h4 style="margin-top:15px; border-top:1px solid #323248; padding-top:15px;">📎 Прикреплённые файлы</h4>`;
                    task.files.forEach(f => {
                        html += `
                            <div class="file-item">
                                <a href="${f.file_path}" target="_blank">📄 ${f.original_name}</a>
                                <span style="color:#6b6b85; font-size:11px;">${f.uploaded_at ? new Date(f.uploaded_at).toLocaleDateString() : ''}</span>
                                <button class="delete-file" onclick="deleteTaskFile(${f.id}, ${task.id})">✕</button>
                            </div>
                        `;
                    });
                }

                if (<?= json_encode(in_array($userRole, ['admin', 'semi-admin']) || $tasks[0]['created_by'] ?? 0) ?>) {
                    html += `<div style="margin-top:15px;">`;
                    html += `<button onclick="changeTaskStatus(${task.id}, 'completed')" class="btn btn-success btn-sm">✅ Завершить задачу</button> `;
                    html += `<button onclick="changeTaskStatus(${task.id}, 'rejected')" class="btn btn-danger btn-sm">❌ Отклонить</button>`;
                    html += `</div>`;
                }

                if (task.votes && task.votes.length > 0) {
                    html += `<h4 style="margin-top:20px; border-top:1px solid #323248; padding-top:15px;">💬 Голоса и комментарии</h4>`;
                    task.votes.forEach(v => {
                        const voteClass = v.vote === 1 ? 'for' : 'against';
                        const voteLabel = v.vote === 1 ? '👍 За' : '👎 Против';
                        html += `<div class="vote-item ${voteClass}">`;
                        html += `<div class="vote-user">${v.user_login} <span style="font-weight:normal; font-size:12px; color:#6b6b85;">${voteLabel}</span></div>`;
                        html += `<div class="vote-comment">${v.comment || 'Без комментария'}</div>`;
                        html += `<div class="vote-date">${new Date(v.created_at).toLocaleString()}</div>`;
                        html += `</div>`;
                    });
                } else {
                    html += `<div style="color:#6b6b85; margin-top:15px;">Нет голосов и комментариев</div>`;
                }

                html += `
                    <div style="margin-top:20px; border-top:1px solid #323248; padding-top:15px;">
                        <h4>🗳 Проголосовать</h4>
                        <form id="voteForm" onsubmit="submitVote(event, ${task.id})">
                            <div style="display:flex; gap:10px; align-items:center;">
                                <label><input type="radio" name="vote" value="1" required> За</label>
                                <label><input type="radio" name="vote" value="-1" required> Против</label>
                            </div>
                            <div class="form-group">
                                <textarea name="comment" rows="2" placeholder="Ваш комментарий (обязательно)" required></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">Отправить голос</button>
                        </form>
                    </div>
                `;

                body.innerHTML = html;
            } catch (err) {
                body.innerHTML = '<div style="color:red;">Ошибка загрузки</div>';
                console.error(err);
            }
        }

        async function submitReport(e, taskId) {
            e.preventDefault();
            const fd = new FormData(e.target);
            fd.append('action', 'submit_report');
            fd.append('task_id', taskId);

            try {
                const res = await fetch('tasks.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    alert('Отчёт сдан!');
                    location.reload();
                } else {
                    alert('Ошибка: ' + data.message);
                }
            } catch (err) { alert('Ошибка соединения'); }
        }

        async function completeTaskAssignee(taskId) {
            if (!confirm('Вы уверены, что завершили задачу?')) return;
            const fd = new FormData();
            fd.append('action', 'complete_task_assignee');
            fd.append('task_id', taskId);

            try {
                const res = await fetch('tasks.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    alert('Задача завершена!');
                    location.reload();
                } else { alert('Ошибка: ' + data.message); }
            } catch (err) { alert('Ошибка соединения'); }
        }

        async function submitVote(e, taskId) {
            e.preventDefault();
            const fd = new FormData(e.target);
            fd.append('action', 'vote_task');
            fd.append('task_id', taskId);

            try {
                const res = await fetch('tasks.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    alert('✅ Голос учтён!');
                    location.reload();
                } else { alert('❌ Ошибка: ' + data.message); }
            } catch (err) { alert('Ошибка соединения'); }
        }

        async function changeTaskStatus(taskId, newStatus) {
            if (!confirm(`Перевести задачу в статус "${newStatus}"?`)) return;
            const fd = new FormData();
            fd.append('action', 'update_task_status');
            fd.append('task_id', taskId);
            fd.append('status', newStatus);

            try {
                const res = await fetch('tasks.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    alert('Статус изменён!');
                    location.reload();
                } else { alert('Ошибка: ' + data.message); }
            } catch (err) { alert('Ошибка соединения'); }
        }

        async function deleteTaskFile(fileId, taskId) {
            if (!confirm('Удалить прикреплённый файл?')) return;
            const fd = new FormData();
            fd.append('action', 'delete_task_file');
            fd.append('file_id', fileId);
            try {
                const res = await fetch('tasks.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    alert('Файл удалён');
                    openTaskDetail(taskId);
                } else { alert('Ошибка: ' + data.message); }
            } catch (err) { alert('Ошибка соединения'); }
        }

        // ESC + клик по оверлею
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeTaskDetail();
                closeCreateTaskModal();
            }
        });
        document.getElementById('taskDetailModal').addEventListener('click', function(e) {
            if (e.target === this) closeTaskDetail();
        });
        document.getElementById('createTaskModal').addEventListener('click', function(e) {
            if (e.target === this) closeCreateTaskModal();
        });
    </script>
</body>
</html>    