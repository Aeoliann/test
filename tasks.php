<?php
// tasks.php — Задачи с множественными исполнителями и отчётами
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
// ОБРАБОТЧИКИ POST
// =========================================================================

// 1. СОЗДАНИЕ ЗАДАЧИ С НЕСКОЛЬКИМИ ИСПОЛНИТЕЛЯМИ
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

    if (empty($title)) {
        echo json_encode(['status' => 'error', 'message' => 'Заголовок обязателен']);
        exit;
    }
    if (empty($assignees)) {
        echo json_encode(['status' => 'error', 'message' => 'Выберите хотя бы одного исполнителя']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Вставляем задачу
        $stmt = $pdo->prepare("INSERT INTO tasks (title, description, created_by, due_date, priority, category, status) VALUES (?, ?, ?, ?, ?, ?, 'proposed')");
        $stmt->execute([$title, $description, $userId, $due_date, $priority, $category]);
        $taskId = $pdo->lastInsertId();

        // Назначаем исполнителей
        $assignStmt = $pdo->prepare("INSERT INTO task_assignees (task_id, user_id, status) VALUES (?, ?, 'pending')");
        foreach ($assignees as $uid) {
            $assignStmt->execute([$taskId, $uid]);
        }

        // Файл задачи
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
                    $fileStmt->execute([$taskId, $newName, $_FILES['task_file']['name'], $dest, $userId]);
                }
            }
        }

        // Уведомления исполнителям
        $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message, link, is_read, created_at) VALUES (?, 'task', ?, 'tasks.php', 0, NOW())");
        foreach ($assignees as $uid) {
            $notifStmt->execute([$uid, "Новая задача: $title"]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success', 'task_id' => $taskId]);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// 2. СДАЧА ОТЧЁТА (ЗАГРУЗКА ФАЙЛА)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_report') {
    header('Content-Type: application/json');
    $taskId = (int)($_POST['task_id'] ?? 0);
    if ($taskId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Неверный ID задачи']);
        exit;
    }

    // Проверяем, является ли пользователь исполнителем
    $check = $pdo->prepare("SELECT id FROM task_assignees WHERE task_id = ? AND user_id = ?");
    $check->execute([$taskId, $userId]);
    if (!$check->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Вы не являетесь исполнителем этой задачи']);
        exit;
    }

    // Загружаем файл отчёта
    if (!isset($_FILES['report_file']) || $_FILES['report_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Файл отчёта не загружен']);
        exit;
    }

    $uploadDir = __DIR__ . '/uploads/reports/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    $ext = strtolower(pathinfo($_FILES['report_file']['name'], PATHINFO_EXTENSION));
    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
    if (!in_array($ext, $allowedExt)) {
        echo json_encode(['status' => 'error', 'message' => 'Недопустимый формат файла']);
        exit;
    }
    $newName = 'report_' . $taskId . '_' . $userId . '_' . time() . '.' . $ext;
    $dest = $uploadDir . $newName;
    if (!move_uploaded_file($_FILES['report_file']['tmp_name'], $dest)) {
        echo json_encode(['status' => 'error', 'message' => 'Не удалось сохранить файл']);
        exit;
    }

    // Обновляем запись в task_assignees
    $update = $pdo->prepare("UPDATE task_assignees SET report_submitted = 1, report_file = ?, submitted_at = NOW() WHERE task_id = ? AND user_id = ?");
    $update->execute([$dest, $taskId, $userId]);

    // Уведомление автору
    $author = $pdo->prepare("SELECT created_by FROM tasks WHERE id = ?");
    $author->execute([$taskId]);
    $authorId = $author->fetchColumn();
    if ($authorId && $authorId != $userId) {
        $notif = $pdo->prepare("INSERT INTO notifications (user_id, type, message, link, is_read, created_at) VALUES (?, 'report', ?, 'tasks.php?get_task_details=$taskId', 0, NOW())");
        $notif->execute([$authorId, "Пользователь сдал отчёт по задаче: $taskId"]);
    }

    echo json_encode(['status' => 'success']);
    exit;
}

// 3. ОТМЕТКА ВЫПОЛНЕНИЯ ЗАДАЧИ ИСПОЛНИТЕЛЕМ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_task_assignee') {
    header('Content-Type: application/json');
    $taskId = (int)($_POST['task_id'] ?? 0);
    if ($taskId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Неверный ID задачи']);
        exit;
    }

    // Проверяем, является ли пользователь исполнителем
    $check = $pdo->prepare("SELECT id, report_submitted FROM task_assignees WHERE task_id = ? AND user_id = ?");
    $check->execute([$taskId, $userId]);
    $assignee = $check->fetch(PDO::FETCH_ASSOC);
    if (!$assignee) {
        echo json_encode(['status' => 'error', 'message' => 'Вы не являетесь исполнителем этой задачи']);
        exit;
    }
    if ($assignee['report_submitted'] != 1) {
        echo json_encode(['status' => 'error', 'message' => 'Сначала сдайте отчёт']);
        exit;
    }

    $update = $pdo->prepare("UPDATE task_assignees SET status = 'completed', completed_at = NOW() WHERE task_id = ? AND user_id = ?");
    $update->execute([$taskId, $userId]);

    // Проверяем, все ли исполнители завершили задачу
    $allCompleted = $pdo->prepare("SELECT COUNT(*) FROM task_assignees WHERE task_id = ? AND status != 'completed'");
    $allCompleted->execute([$taskId]);
    if ($allCompleted->fetchColumn() == 0) {
        // Все завершили – обновляем статус задачи
        $pdo->prepare("UPDATE tasks SET status = 'completed', completion_time = NOW() WHERE id = ?")->execute([$taskId]);
    }

    echo json_encode(['status' => 'success']);
    exit;
}

// 4. ГОЛОСОВАНИЕ
// 4. ГОЛОСОВАНИЕ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'vote_task') {
    header('Content-Type: application/json');

    $taskId = (int)($_POST['task_id'] ?? 0);
    $vote = (int)($_POST['vote'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');

    if ($taskId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Неверный ID задачи']);
        exit;
    }
    if (!in_array($vote, [1, -1])) {
        echo json_encode(['status' => 'error', 'message' => 'Неверное значение голоса']);
        exit;
    }
    if (empty($comment)) {
        echo json_encode(['status' => 'error', 'message' => 'Комментарий обязателен']);
        exit;
    }

    // Проверяем существование задачи
    $taskCheck = $pdo->prepare("SELECT id FROM tasks WHERE id = ?");
    $taskCheck->execute([$taskId]);
    if (!$taskCheck->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Задача не найдена']);
        exit;
    }

    try {
        // Проверяем, голосовал ли уже пользователь
        $existing = $pdo->prepare("SELECT id FROM task_votes WHERE task_id = ? AND user_id = ?");
        $existing->execute([$taskId, $userId]);
        $voteRow = $existing->fetch(PDO::FETCH_ASSOC);

        if ($voteRow) {
            // Обновляем голос с явной привязкой типов
            $update = $pdo->prepare("UPDATE task_votes SET vote = :vote, comment = :comment, created_at = NOW() WHERE id = :id");
            $update->bindValue(':vote', $vote, PDO::PARAM_INT);
            $update->bindValue(':comment', $comment, PDO::PARAM_STR);
            $update->bindValue(':id', $voteRow['id'], PDO::PARAM_INT);
            $update->execute();
        } else {
            // Вставляем новый голос с явной привязкой типов
            $insert = $pdo->prepare("INSERT INTO task_votes (task_id, user_id, vote, comment, created_at) VALUES (:task_id, :user_id, :vote, :comment, NOW())");
            $insert->bindValue(':task_id', $taskId, PDO::PARAM_INT);
            $insert->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $insert->bindValue(':vote', $vote, PDO::PARAM_INT);
            $insert->bindValue(':comment', $comment, PDO::PARAM_STR);
            $insert->execute();
        }

        echo json_encode(['status' => 'success']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// 5. ОБНОВЛЕНИЕ СТАТУСА ЗАДАЧИ (админ/автор)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_task_status') {
    header('Content-Type: application/json');

    $taskId = (int)($_POST['task_id'] ?? 0);
    $newStatus = $_POST['status'] ?? '';

    if ($taskId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Неверный ID задачи']);
        exit;
    }

    $allowedStatuses = ['proposed', 'voting', 'approved', 'in_progress', 'completed', 'rejected'];
    if (!in_array($newStatus, $allowedStatuses)) {
        echo json_encode(['status' => 'error', 'message' => 'Недопустимый статус']);
        exit;
    }

    // Проверяем права: admin, semi-admin или автор задачи
    $taskInfo = $pdo->prepare("SELECT created_by FROM tasks WHERE id = ?");
    $taskInfo->execute([$taskId]);
    $task = $taskInfo->fetch(PDO::FETCH_ASSOC);
    if (!$task) {
        echo json_encode(['status' => 'error', 'message' => 'Задача не найдена']);
        exit;
    }

    if (!in_array($userRole, ['admin', 'semi-admin']) && $task['created_by'] != $userId) {
        echo json_encode(['status' => 'error', 'message' => 'Недостаточно прав']);
        exit;
    }

    try {
        $update = $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ?");
        $update->execute([$newStatus, $taskId]);

        echo json_encode(['status' => 'success']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// 6. УДАЛЕНИЕ ФАЙЛА (из task_files)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_task_file') {
    header('Content-Type: application/json');

    $fileId = (int)($_POST['file_id'] ?? 0);

    if ($fileId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Неверный ID файла']);
        exit;
    }

    // Получаем информацию о файле и задаче
    $fileInfo = $pdo->prepare("SELECT tf.*, t.created_by FROM task_files tf JOIN tasks t ON tf.task_id = t.id WHERE tf.id = ?");
    $fileInfo->execute([$fileId]);
    $file = $fileInfo->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        echo json_encode(['status' => 'error', 'message' => 'Файл не найден']);
        exit;
    }

    // Проверяем права: admin, semi-admin или автор задачи
    if (!in_array($userRole, ['admin', 'semi-admin']) && $file['created_by'] != $userId) {
        echo json_encode(['status' => 'error', 'message' => 'Недостаточно прав']);
        exit;
    }

    try {
        // Удаляем запись из БД
        $delete = $pdo->prepare("DELETE FROM task_files WHERE id = ?");
        $delete->execute([$fileId]);

        // Удаляем файл с диска
        if (file_exists($file['file_path'])) {
            unlink($file['file_path']);
        }

        echo json_encode(['status' => 'success']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// =========================================================================
// ВЫБОРКА ДАННЫХ
// =========================================================================
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$filterCategory = isset($_GET['category']) ? $_GET['category'] : '';
$filterPriority = isset($_GET['priority']) ? $_GET['priority'] : '';
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
if ($filterStatus !== '') {
    $sql .= " AND t.status = ?";
    $params[] = $filterStatus;
}
if ($filterCategory !== '') {
    $sql .= " AND t.category = ?";
    $params[] = $filterCategory;
}
if ($filterPriority !== '') {
    $sql .= " AND t.priority = ?";
    $params[] = $filterPriority;
}
if ($filterAuthor > 0) {
    $sql .= " AND t.created_by = ?";
    $params[] = $filterAuthor;
}
if ($filterAssignee > 0) {
    $sql .= " AND EXISTS (SELECT 1 FROM task_assignees ta WHERE ta.task_id = t.id AND ta.user_id = ?)";
    $params[] = $filterAssignee;
}

$sql .= " ORDER BY t.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tasks = $stmt->fetchAll();

// Список пользователей для фильтров и выбора исполнителей
$allUsers = $pdo->query("SELECT id, login FROM users ORDER BY login")->fetchAll();

// =========================================================================
// AJAX ДЕТАЛИ ЗАДАЧИ (с расширенной информацией)
// =========================================================================
if (isset($_GET['get_task_details']) && (int)$_GET['get_task_details'] > 0) {
    header('Content-Type: application/json');
    $taskId = (int)$_GET['get_task_details'];
    try {
        $detailStmt = $pdo->prepare("SELECT t.*, u.login AS author_name,
                                     (SELECT COUNT(*) FROM task_votes WHERE task_id = t.id AND vote = 1) AS votes_for,
                                     (SELECT COUNT(*) FROM task_votes WHERE task_id = t.id AND vote = -1) AS votes_against,
                                     (SELECT COUNT(*) FROM task_votes WHERE task_id = t.id) AS total_votes
                                     FROM tasks t
                                     LEFT JOIN users u ON t.created_by = u.id
                                     WHERE t.id = ?");
        $detailStmt->execute([$taskId]);
        $task = $detailStmt->fetch(PDO::FETCH_ASSOC);
        if (!$task) {
            echo json_encode(['status' => 'error', 'message' => 'Задача не найдена']);
            exit;
        }

        // Исполнители с их статусами
        $assigneesStmt = $pdo->prepare("SELECT ta.*, u.login FROM task_assignees ta JOIN users u ON ta.user_id = u.id WHERE ta.task_id = ?");
        $assigneesStmt->execute([$taskId]);
        $task['assignees'] = $assigneesStmt->fetchAll();

        // Файлы задачи
        $fileStmt = $pdo->prepare("SELECT * FROM task_files WHERE task_id = ? ORDER BY uploaded_at DESC");
        $fileStmt->execute([$taskId]);
        $task['files'] = $fileStmt->fetchAll();

        // Голоса и комментарии
        $voteStmt = $pdo->prepare("SELECT v.*, u.login AS user_login 
                                   FROM task_votes v 
                                   JOIN users u ON v.user_id = u.id 
                                   WHERE v.task_id = ? 
                                   ORDER BY v.created_at DESC");
        $voteStmt->execute([$taskId]);
        $task['votes'] = $voteStmt->fetchAll();

        echo json_encode(['status' => 'success', 'data' => $task]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Задачи и голосование — Santeks</title>
    <style>
        /* ===== Все стили (как в предыдущей версии, плюс новые) ===== */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #0f0f1a; color: #fff; font-family: 'Segoe UI', Roboto, sans-serif; display: flex; min-height: 100vh; margin: 0; }
        aside { width: 260px; flex-shrink: 0; background: #1e1e2d; border-right: 1px solid #323248; padding: 20px 16px; }
        main { flex: 1; padding: 30px 35px; min-width: 0; box-sizing: border-box; overflow-y: auto; display: flex; flex-direction: column; gap: 20px; max-height: 100vh; }
        .topbar { display: flex; align-items: center; justify-content: space-between; padding: 18px 28px; background: #1e1e2d; border: 1px solid #323248; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 4px 25px rgba(0,0,0,0.3); flex-wrap: wrap; gap: 15px; }
        .topbar h1 { margin: 0; font-size: 20px; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 12px; }
        .topbar-actions { display: flex; gap: 12px; flex-wrap: wrap; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 8px; font-weight: 600; font-size: 13px; cursor: pointer; transition: all 0.25s ease; border: none; text-decoration: none; white-space: nowrap; }
        .btn-primary { background: #4f46e5; color: #fff; }
        .btn-primary:hover { background: #6366f1; transform: translateY(-2px); box-shadow: 0 4px 15px rgba(79,70,229,0.3); }
        .btn-outline { background: transparent; border: 1px solid #323248; color: #92929f; }
        .btn-outline:hover { border-color: #4f46e5; color: #fff; background: rgba(79,70,229,0.08); }
        .btn-success { background: #10b981; color: #fff; }
        .btn-success:hover { background: #059669; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-danger:hover { background: #dc2626; }
        .btn-sm { padding: 4px 12px; font-size: 12px; }

        .filter-bar { background: #1e1e2d; border: 1px solid #323248; border-radius: 12px; padding: 15px 20px; margin-bottom: 20px; }
        .filter-form { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 15px; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; min-width: 140px; }
        .filter-group label { font-size: 11px; color: #92929f; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; }
        .filter-select, .filter-input { padding: 8px 12px; background: #151521; border: 1px solid #323248; border-radius: 8px; color: #fff; font-size: 13px; outline: none; height: 38px; box-sizing: border-box; width: 100%; min-width: 140px; }
        .filter-select:focus, .filter-input:focus { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,0.15); }
        .filter-actions { display: flex; gap: 10px; align-items: center; margin-top: 6px; }

        .table-wrapper { background: #1a1a28; border: 1px solid #323248; border-radius: 14px; overflow-x: auto; box-shadow: 0 8px 35px rgba(0,0,0,0.4); }
        .data-table { width: 100%; border-collapse: collapse; font-size: 13px; background: #1a1a28; min-width: 1300px; }
        .data-table th { background: #242438; color: #92929f; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; padding: 14px 12px; text-align: left; border-bottom: 2px solid #323248; white-space: nowrap; }
        .data-table td { padding: 12px 12px; border-bottom: 1px solid #26263a; color: #cbd5e1; vertical-align: middle; }
        .data-table tbody tr:hover td { background: #1e1e32; }
        .task-title { color: #fff; font-weight: 600; cursor: pointer; }
        .task-title:hover { color: #818cf8; }
        .badge-status { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; white-space: nowrap; }
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
        .btn-detail-sm { background: transparent; border: 1px solid #323248; color: #92929f; padding: 2px 10px; border-radius: 4px; font-size: 12px; cursor: pointer; transition: all 0.25s ease; }
        .btn-detail-sm:hover { border-color: #4f46e5; color: #fff; }

        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.75); justify-content: center; align-items: center; z-index: 99999; padding: 20px; backdrop-filter: blur(4px); }
        .modal-content { background: #1e1e2d; border: 1px solid #323248; border-radius: 16px; padding: 30px; max-width: 90%; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 60px rgba(0,0,0,0.5); animation: modalSlideIn 0.3s ease; }
        @keyframes modalSlideIn { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; width: 100%; }
        .form-group label { font-size: 11px; font-weight: 700; color: #92929f; text-transform: uppercase; letter-spacing: 0.3px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 14px; background: #151521; border: 1px solid #323248; border-radius: 8px; color: #fff; font-size: 13px; outline: none; transition: border-color 0.25s, box-shadow 0.25s; box-sizing: border-box; font-family: inherit; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,0.15); }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .file-upload-wrapper { position: relative; margin-bottom: 16px; }
        .file-upload-wrapper input[type="file"] { width: 100%; padding: 8px; background: #151521; border: 1px solid #323248; border-radius: 8px; color: #cbd5e1; font-size: 13px; cursor: pointer; }

        .modal-detail .vote-item { background: #151521; border-radius: 8px; padding: 10px 14px; margin-bottom: 8px; border-left: 3px solid #323248; }
        .modal-detail .vote-item.for { border-left-color: #10b981; }
        .modal-detail .vote-item.against { border-left-color: #ef4444; }
        .modal-detail .vote-item .vote-user { font-weight: 600; color: #fff; }
        .modal-detail .vote-item .vote-comment { color: #cbd5e1; font-size: 13px; margin-top: 4px; }
        .modal-detail .vote-item .vote-date { font-size: 11px; color: #6b6b85; }

        .assignee-item { display: flex; justify-content: space-between; align-items: center; padding: 6px 10px; background: #151521; border-radius: 6px; margin-bottom: 4px; border-left: 3px solid #323248; }
        .assignee-item.completed { border-left-color: #10b981; }
        .assignee-item .status-badge { font-size: 11px; font-weight: 600; }
        .assignee-item .status-badge.pending { color: #f59e0b; }
        .assignee-item .status-badge.completed { color: #10b981; }
        .assignee-item .report-badge { font-size: 11px; padding: 2px 6px; border-radius: 4px; background: rgba(16,185,129,0.12); color: #10b981; }
        .assignee-item .report-badge.not-submitted { background: rgba(239,68,68,0.12); color: #ef4444; }

        .empty-state { text-align: center; padding: 40px; color: #4b4b5e; }
        .fade-up { animation: fadeUp 0.6s cubic-bezier(0.16,1,0.3,1) forwards; }
        @keyframes fadeUp { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }
        .delay-1 { animation-delay:0.1s; }
        .delay-2 { animation-delay:0.2s; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <main>
        <div class="topbar fade-up">
            <h1>📋 Задачи и голосование</h1>
            <div class="topbar-actions">
                <?php if (in_array($userRole, ['admin', 'semi-admin', 'manager'])): ?>
                    <button class="btn btn-primary" onclick="openCreateTaskModal()">➕ Создать задачу</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="filter-bar fade-up delay-1">
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

        <div class="table-wrapper fade-up delay-2">
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
                            $priorityLabel = ['low' => 'Низкий', 'medium' => 'Средний', 'high' => 'Высокий'][$task['priority']];
                            $priorityClass = 'priority-' . $task['priority'];
                            $categoryLabel = ['task' => 'Задача', 'proposal' => 'Предложение', 'question' => 'Вопрос'][$task['category']];
                            $statusLabels = [
                                'proposed' => 'Предложена',
                                'voting' => 'Голосование',
                                'approved' => 'Утверждена',
                                'in_progress' => 'В работе',
                                'completed' => 'Выполнена',
                                'rejected' => 'Отклонена'
                            ];
                            $assigneesList = $task['assignees'] ?? '';
                            $reportsCount = (int)$task['reports_submitted_count'];
                            $totalAssignees = (int)$task['total_assignees'];
                            $completedCount = (int)$task['completed_count'];
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
                            <td><?= htmlspecialchars($assigneesList) ?></td>
                            <td style="text-align:center;"><?= $reportsCount ?> / <?= $totalAssignees ?></td>
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

    <!-- ============================================================
    МОДАЛЬНОЕ ОКНО СОЗДАНИЯ ЗАДАЧИ (с выбором нескольких исполнителей)
    ============================================================ -->
    <div id="createTaskModal" class="modal-overlay" style="display:none;">
        <div class="modal-content" style="max-width: 700px; width: 100%;">
            <h2 style="margin-top:0; font-size:22px;">➕ Создать задачу</h2>
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
                <!-- Выбор исполнителей -->
                <div class="form-group">
                    <label>Исполнители *</label>
                    <select name="assignees[]" multiple required style="height: auto; min-height: 80px;">
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $u['id'] == $userId ? 'selected' : '' ?>><?= htmlspecialchars($u['login']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:#6b6b85;">Зажмите Ctrl для выбора нескольких</small>
                </div>
                <!-- Файл -->
                <div class="form-group file-upload-wrapper">
                    <label>Прикрепить файл (необязательно)</label>
                    <input type="file" name="task_file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.txt,.zip">
                </div>
                <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:20px;">
                    <button type="button" class="btn btn-outline" onclick="closeCreateTaskModal()">Отмена</button>
                    <button type="submit" class="btn btn-primary">Создать</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================
    МОДАЛЬНОЕ ОКНО ДЕТАЛЕЙ (с исполнителями и отчётами)
    ============================================================ -->
    <div id="taskDetailModal" class="modal-overlay modal-detail" style="display:none;">
        <div class="modal-content" style="max-width: 800px; width: 100%;">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #323248; padding-bottom:12px; margin-bottom:20px;">
                <h2 id="detailTitle" style="margin:0; font-size:20px;">Загрузка...</h2>
                <button onclick="closeTaskDetail()" style="background:none; border:none; color:#92929f; font-size:24px; cursor:pointer;">&times;</button>
            </div>
            <div id="detailBody">
                <!-- Загружается через AJAX -->
            </div>
        </div>
    </div>

    <script>
        // ============================================================
        // ФУНКЦИИ
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

                let html = '';
                html += `<div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; background:#151521; padding:15px; border-radius:8px; margin-bottom:15px;">`;
                html += `<div><strong>Автор:</strong> ${task.author_name || 'Система'}</div>`;
                html += `<div><strong>Дата создания:</strong> ${task.created_at ? new Date(task.created_at).toLocaleDateString() : '—'}</div>`;
                html += `<div><strong>Срок:</strong> ${task.due_date ? new Date(task.due_date).toLocaleDateString() : 'Не указан'}</div>`;
                html += `<div><strong>Приоритет:</strong> ${task.priority}</div>`;
                html += `<div><strong>Категория:</strong> ${task.category}</div>`;
                html += `<div><strong>Статус:</strong> ${task.status}</div>`;
                html += `<div><strong>Голосов ЗА:</strong> <span style="color:#10b981;">${task.votes_for}</span></div>`;
                html += `<div><strong>Голосов ПРОТИВ:</strong> <span style="color:#ef4444;">${task.votes_against}</span></div>`;
                html += `<div style="grid-column: span 2;"><strong>Описание:</strong><br>${task.description || '—'}</div>`;
                html += `</div>`;

                // ===== ИСПОЛНИТЕЛИ И ОТЧЁТЫ =====
                if (task.assignees && task.assignees.length > 0) {
                    html += `<h4 style="margin-top:15px; border-top:1px solid #323248; padding-top:15px;">👥 Исполнители</h4>`;
                    task.assignees.forEach(a => {
                        const statusText = a.status === 'completed' ? '✅ Выполнено' : (a.report_submitted ? '📄 Отчёт сдан' : '⏳ Ожидание');
                        const statusClass = a.status === 'completed' ? 'completed' : '';
                        const reportBadge = a.report_submitted ? 'report-badge' : 'report-badge not-submitted';
                        const reportLabel = a.report_submitted ? 'Отчёт сдан' : 'Отчёт не сдан';
                        html += `
                            <div class="assignee-item ${statusClass}">
                                <div>
                                    <strong>${a.login}</strong>
                                    <span class="status-badge ${a.status}">${statusText}</span>
                                    ${a.report_submitted ? ' <span class="report-badge">📎 <a href="'+a.report_file+'" target="_blank">Скачать отчёт</a></span>' : ''}
                                </div>
                                <div>
                                    ${a.report_submitted ? '<span class="report-badge">✔ Отчёт</span>' : ''}
                                    ${a.status === 'completed' ? '<span class="status-badge completed">Завершено</span>' : ''}
                                </div>
                            </div>
                        `;
                    });

                    // Кнопки для текущего пользователя (если он исполнитель)
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

                // Файлы задачи
                if (task.files && task.files.length > 0) {
                    html += `<h4 style="margin-top:15px; border-top:1px solid #323248; padding-top:15px;">📎 Прикреплённые файлы</h4><div class="file-list">`;
                    task.files.forEach(f => {
                        html += `
                            <div class="file-item">
                                <a href="${f.file_path}" target="_blank">📄 ${f.original_name}</a>
                                <span class="file-size">${f.uploaded_at ? new Date(f.uploaded_at).toLocaleDateString() : ''}</span>
                                <button class="delete-file" onclick="deleteTaskFile(${f.id}, ${task.id})" title="Удалить файл">✕</button>
                            </div>
                        `;
                    });
                    html += `</div>`;
                }

                // Кнопки изменения статуса задачи (админ/автор)
                if (<?= json_encode(in_array($userRole, ['admin', 'semi-admin']) || $task['created_by'] == $userId) ?>) {
                    html += `<div style="margin-bottom:15px; margin-top:10px;">`;
                    html += `<button onclick="changeTaskStatus(${task.id}, 'completed')" class="btn btn-success btn-sm">✅ Завершить задачу</button> `;
                    html += `<button onclick="changeTaskStatus(${task.id}, 'rejected')" class="btn btn-danger btn-sm">❌ Отклонить</button>`;
                    html += `</div>`;
                }

                // Голоса и комментарии
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

                // Форма голосования
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

        // ============================================================
        // ОТПРАВКА ОТЧЁТА
        // ============================================================
        async function submitReport(e, taskId) {
            e.preventDefault();
            const form = e.target;
            const fd = new FormData(form);
            fd.append('action', 'submit_report');
            fd.append('task_id', taskId);

            try {
                const res = await fetch('tasks.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    alert('Отчёт сдан!');
                    openTaskDetail(taskId);
                } else {
                    alert('Ошибка: ' + data.message);
                }
            } catch (err) {
                alert('Ошибка соединения');
            }
        }

        // ============================================================
        // ЗАВЕРШЕНИЕ ЗАДАЧИ ИСПОЛНИТЕЛЕМ
        // ============================================================
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
                    openTaskDetail(taskId);
                } else {
                    alert('Ошибка: ' + data.message);
                }
            } catch (err) {
                alert('Ошибка соединения');
            }
        }

        // ============================================================
        // ОСТАЛЬНЫЕ ФУНКЦИИ (голосование, изменение статуса, удаление файла)
        // ============================================================
        async function submitVote(e, taskId) {
            e.preventDefault();
            const form = e.target;
            const fd = new FormData(form);
            fd.append('action', 'vote_task');
            fd.append('task_id', taskId);

            try {
                const res = await fetch('tasks.php', { method: 'POST', body: fd });
                const text = await res.text();
                console.log('📄 Ответ сервера:', text);

                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    alert('❌ Сервер вернул не JSON:\n' + text.substring(0, 300) + '\n\nПроверьте логи PHP.');
                    return;
                }

                if (data.status === 'success') {
                    alert('✅ Голос учтён!');
                    openTaskDetail(taskId);
                } else {
                    alert('❌ Ошибка: ' + data.message);
                }
            } catch (err) {
                console.error('❌ Ошибка сети:', err);
                alert('Ошибка соединения: ' + err.message);
            }
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
                    openTaskDetail(taskId);
                } else {
                    alert('Ошибка: ' + data.message);
                }
            } catch (err) {
                alert('Ошибка соединения');
            }
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
                } else {
                    alert('Ошибка: ' + data.message);
                }
            } catch (err) {
                alert('Ошибка соединения');
            }
        }

        // Закрытие по ESC
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