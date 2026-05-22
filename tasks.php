<?php
session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php"); exit;
}

$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'manager';

// 1. Сбор менеджеров для формы постановки задач (только админу)
$managers = [];
if ($userRole === 'admin') {
    $nameCol = 'name';
    try { $pdo->query("SELECT name FROM users LIMIT 1"); } 
    catch (Exception $e) {
        try { $pdo->query("SELECT login FROM users LIMIT 1"); $nameCol = 'login'; } 
        catch (Exception $e2) { $nameCol = 'username'; }
    }
    $managers = $pdo->query("SELECT id, $nameCol AS name FROM users ORDER BY id ASC")->fetchAll();
}

// 2. Обработка POST-запросов (Добавление задачи / Сохранение отчета)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (ob_get_length()) ob_clean();
    
    try {
        if (isset($_POST['action']) && $_POST['action'] === 'create' && $userRole === 'admin') {
            $stmt = $pdo->prepare("INSERT INTO tasks (manager_id, title, description, deadline, status) VALUES (?, ?, ?, ?, 'Новая')");
            $stmt->execute([(int)$_POST['manager_id'], trim($_POST['title']), trim($_POST['description']), $_POST['deadline']]);
            echo json_encode(['status' => 'success']); exit;
        }
        
        if (isset($_POST['action']) && $_POST['action'] === 'report') {
            $taskId = (int)$_POST['task_id'];
            if ($userRole !== 'admin') {
                $check = $pdo->prepare("SELECT manager_id FROM tasks WHERE id = ?");
                $check->execute([$taskId]);
                if ($check->fetchColumn() != $userId) throw new Exception("Доступ заблокирован!");
            }
            $stmt = $pdo->prepare("UPDATE tasks SET status = ?, manager_report = ? WHERE id = ?");
            $stmt->execute([$_POST['status'], trim($_POST['manager_report']), $taskId]);
            echo json_encode(['status' => 'success']); exit;
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); exit;
    }
}

// 3. Выборка списка задач из базы данных
if ($userRole === 'admin') {
    $tasksStmt = $pdo->query("SELECT t.*, u.login as manager_name FROM tasks t LEFT JOIN users u ON t.manager_id = u.id ORDER BY t.id DESC");
} else {
    $tasksStmt = $pdo->prepare("SELECT t.*, u.login as manager_name FROM tasks t LEFT JOIN users u ON t.manager_id = u.id WHERE t.manager_id = ? ORDER BY t.id DESC");
    $tasksStmt->execute([$userId]);
}
$tasksList = $tasksStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Управление задачами - Santeks CRM</title>
    <link rel="stylesheet" href="style.css">
    <style>
       body { 
    background: #151521; 
    color: #fff; 
    font-family: sans-serif; 
    padding: 30px; 
    margin: 0; 
    /* ГАРАНТИРУЕМ СКРОЛЛ НА СТРАНИЦЕ */
    overflow-y: auto !important; 
    min-height: 100vh;
    box-sizing: border-box;
}
.task-card { 
    background: #1e1e2d; 
    padding: 20px; 
    border-radius: 12px; 
    border: 1px solid #323248; 
    margin-bottom: 15px; 
    text-align: left; 
}
.badge { 
    padding: 4px 8px; 
    border-radius: 4px; 
    font-size: 11px; 
    font-weight: bold; 
}
.badge-New { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
.badge-Work { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
.badge-Done { background: rgba(16, 185, 129, 0.15); color: #10b981; }
input, textarea, select { 
    width: 100%; 
    padding: 10px; 
    background: #151521; 
    border: 1px solid #323248; 
    color: #fff; 
    border-radius: 6px; 
    box-sizing: border-box; 
    outline: none; 
    margin-bottom: 15px; 
}

/* Красивый кастомный темный скроллбар для Santeks CRM */
::-webkit-scrollbar {
    width: 8px;
}
::-webkit-scrollbar-track {
    background: #151521;
}
::-webkit-scrollbar-thumb {
    background: #323248;
    border-radius: 4px;
}
::-webkit-scrollbar-thumb:hover {
    background: #4f46e5;
}
    </style>
</head>
<body>

   
    
<?php include 'sidebar.php'; ?>
   
    
</div>

    <div style="display: grid; <?= $userRole === 'admin' ? 'grid-template-columns: 350px 1fr;' : 'grid-template-columns: 1fr;' ?> gap: 25px;">
        
        <!-- ФОРМА СОЗДАНИЯ ЗАДАЧИ (ТОЛЬКО АДМИНУ) -->
        <?php if ($userRole === 'admin'): ?>
            <div style="background: #1e1e2d; padding: 25px; border-radius: 12px; border: 1px solid #323248; height: fit-content; text-align: left;">
                <h3 style="margin-top: 0; font-size: 14px; color: #f59e0b; text-transform: uppercase; margin-bottom: 20px;">➕ Выдать поручение</h3>
                <form id="createTaskForm">
                    <input type="hidden" name="action" value="create">
                    
                    <label style="font-size:11px; color:#92929f; display:block; margin-bottom:5px;">Исполнитель:</label>
                    <select name="manager_id" required>
                        <?php foreach($managers as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label style="font-size:11px; color:#92929f; display:block; margin-bottom:5px;">Суть поручения:</label>
                    <input type="text" name="title" required placeholder="Тема задачи...">

                    <label style="font-size:11px; color:#92929f; display:block; margin-bottom:5px;">Подробное описание:</label>
                    <textarea name="description" rows="4" required placeholder="Что нужно сделать..."></textarea>

                    <label style="font-size:11px; color:#92929f; display:block; margin-bottom:5px;">Дедлайн:</label>
                    <input type="date" name="deadline" value="<?= date('Y-m-d') ?>" required>

                    <button type="submit" style="width:100%; padding:10px; background:#10b981; color:#fff; border:none; border-radius:6px; font-weight:bold; cursor:pointer;">Отправить исполнителю</button>
                </form>
            </div>
        <?php endif; ?>

      <!-- ОБНОВЛЕННЫЙ ВЕРТИКАЛЬНЫЙ СПИСОК ЗАДАЧ С АВТОПЕРЕНОСОМ ТЕКСТА -->
<div style="display: flex; flex-direction: column; gap: 15px; width: 100%; box-sizing: border-box;">
    <?php foreach ($tasksList as $t): 
        $statusKey = $t['status'] === 'В работе' ? 'Work' : ($t['status'] === 'Выполнено' ? 'Done' : 'New');
    ?>
        <div class="task-card" style="background: #1e1e2d; padding: 20px; border-radius: 12px; border: 1px solid #323248; display: flex; flex-direction: column; gap: 12px; width: 100%; box-sizing: border-box;">
            
            <!-- ВЕРХНЯЯ СТРОКА: НАЗВАНИЕ И СТАТУСЫ -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start; width: 100%; gap: 15px; flex-wrap: wrap;">
                <h4 style="margin: 0; font-size: 15px; color: #fff; font-weight: bold; word-break: break-word; flex: 1; min-width: 200px; text-align: left;">
                    <?= htmlspecialchars($t['title']) ?>
                </h4>
                <div style="display: flex; align-items: center; gap: 10px; flex-shrink: 0;">
                    <span class="badge badge-<?= $statusKey ?>"><?= $t['status'] ?></span>
                    <span style="font-size: 11px; color: #ef4444; font-weight: bold; white-space: nowrap;">
                        Дедлайн: <?= date('d.m.Y', strtotime($t['deadline'])) ?>
                    </span>
                </div>
            </div>
            
            <!-- ОПИСАНИЕ ЗАДАЧИ С ЖЕСТКИМ ПЕРЕНОСОМ СЛОВ -->
            <p style="color: #92929f; font-size: 13px; line-height: 1.5; margin: 0; white-space: pre-wrap; word-break: break-word; text-align: left;">
                <?= htmlspecialchars($t['description']) ?>
            </p>
            
            <!-- ИСПОЛНИТЕЛЬ -->
            <div style="font-size: 11px; color: #4f46e5; text-align: left;">
                Исполнитель: 👤 <strong><?= htmlspecialchars($t['manager_name'] ?? 'Не назначен') ?></strong>
            </div>

            <!-- БЛОК ОТЧЕТА МЕНЕДЖЕРА -->
            <div style="background: #151521; padding: 15px; border-radius: 8px; border: 1px solid #2b2b40; width: 100%; box-sizing: border-box; margin-top: 5px;">
                <h5 style="margin: 0 0 10px 0; font-size: 11px; color: #92929f; text-transform: uppercase; letter-spacing: 0.5px; text-align: left;">
                    📝 Отчёт о выполнении:
                </h5>
                <form class="js-report-form" style="margin: 0; padding: 0;">
                    <input type="hidden" name="action" value="report">
                    <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                    
                    <!-- СЕТКА ДЛЯ ОТЧЕТА: Вертикальная на мобильных, адаптивная строка на дескрипте -->
                    <div style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; width: 100%;">
                        <div style="flex: 2; min-width: 250px;">
                            <textarea name="manager_report" rows="2" placeholder="Напишите, что сделано по задаче..." style="margin-bottom:0; width: 100%; box-sizing: border-box;" required><?= htmlspecialchars($t['manager_report'] ?? '') ?></textarea>
                        </div>
                        <div style="flex: 1; min-width: 130px;">
                            <select name="status" style="margin-bottom:0; width: 100%; box-sizing: border-box; cursor: pointer;">
                                <option value="Новая" <?= $t['status'] === 'Новая' ? 'selected' : '' ?>>Новая</option>
                                <option value="В работе" <?= $t['status'] === 'В работе' ? 'selected' : '' ?>>В работе</option>
                                <option value="Выполнено" <?= $t['status'] === 'Выполнено' ? 'selected' : '' ?>>Выполнено</option>
                            </select>
                        </div>
                        <button type="submit" style="padding: 10px 20px; background: #4f46e5; color: #fff; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; height: 38px; white-space: nowrap; box-sizing: border-box;">
                            Сохранить отчет
                        </button>
                    </div>
                </form>
            </div>

        </div>
    <?php endforeach; ?>
    
    <?php if(empty($tasksList)): ?>
        <div style="text-align:center; color:#444; padding:40px; font-size:14px; background: #1e1e2d; border-radius: 12px; border: 1px solid #323248;">
            Задач и поручений в системе пока нет
        </div>
    <?php endif; ?>
</div>


<script>
// Создание задачи админом
const createForm = document.getElementById('createTaskForm');
if (createForm) {
    createForm.onsubmit = async function(e) {
        e.preventDefault();
        const res = await fetch('tasks.php', { method: 'POST', body: new FormData(this) });
        if ((await res.json()).status === 'success') window.location.reload();
    };
}

// Отправка отчетов менеджерами
document.querySelectorAll('.js-report-form').forEach(form => {
    form.onsubmit = async function(e) {
        e.preventDefault();
        const res = await fetch('tasks.php', { method: 'POST', body: new FormData(this) });
        if ((await res.json()).status === 'success') window.location.reload();
    };
});
</script>
</body>
</html>