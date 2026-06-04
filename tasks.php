<?php
// tasks.php — Монолитный модуль задач Santeks CRM с комментариями и формой постановки
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = (int)$_SESSION['user_id'];
$u_role = $_SESSION['role'] ?? 'manager'; 
// =========================================================================
// БЛОК 0.5: ИСПРАВЛЕНО НАМЕРТВО: Создание новой задачи строго по структуре СУБД
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_new_task') {
    $task_text = trim($_POST['task_text'] ?? '');
    $due_date  = !empty($_POST['due_date']) ? $_POST['due_date'] : date('Y-m-d', strtotime('+3 days'));
    $assign_to = (int)($_POST['user_id'] ?? $userId); // Кому поручаем
    $manager_comment = trim($_POST['manager_comment'] ?? '');
    
    // Перехватываем текстовый логин текущего пользователя (например, 'admin')
    $current_user_login = $_SESSION['login'] ?? 'Система';

    if (!empty($task_text)) {
        try {
            // Записываем текстовый логин напрямую в колонку created_by
            $stmt = $pdo->prepare("INSERT INTO tasks (task_text, created_by, due_date, user_id, status, manager_comment) VALUES (?, ?, ?, ?, 'pending', ?)");
            $stmt->execute([$task_text, $current_user_login, $due_date, $assign_to, $manager_comment]);
            
            header("Location: tasks.php");
            exit;
        } catch (Exception $e) {
            die("Критический сбой СУБД при создании задачи: " . $e->getMessage());
        }
    }
}

// =========================================================================
// БЛОК 1: ИСПРАВЛЕНО НАМЕРТВО: Ролевой контроль закрытия и отправки на доработку
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_task_status') {
    header('Content-Type: application/json');
    if (ob_get_length()) ob_clean();

    $t_id = (int)($_POST['task_id'] ?? 0);

    if ($t_id > 0) {
        try {
            // Смотрим текущий статус строки напрямую в СУБД
            $checkStmt = $pdo->prepare("SELECT status FROM tasks WHERE id = ?");
            $checkStmt->execute([$t_id]);
            $realStatus = $checkStmt->fetchColumn() ?: 'pending';

            if ($realStatus === 'completed') {
                // Если задача уже выполнена, вернуть её в работу может ТОЛЬКО АДМИН!
                if ($u_role === 'admin') {
                    $new_status  = 'pending'; // Отправляем на доработку
                    $executed_at = null;      // Стираем дату выполнения
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Ошибка доступа: Менеджерам запрещено откатывать выполненные задачи. Обратитесь к Администратору для отправки на доработку.']);
                    exit;
                }
            } else {
                // Если задача была в работе — переводим в выполненные и фиксируем дату
                $new_status  = 'completed';
                $executed_at = date('Y-m-d'); // 03.06.2026
            }

            $stmt = $pdo->prepare("UPDATE tasks SET status = ?, executed_at = ? WHERE id = ?");
            $stmt->execute([$new_status, $executed_at, $t_id]);

            echo json_encode(['status' => 'success']);
            exit;
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }
    exit;
}// =========================================================================
// БЛОК 1.5: Ролевая блокировка изменения комментария выполненных задач
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_task_comment') {
    header('Content-Type: application/json');
    if (ob_get_length()) ob_clean();

    $t_id    = (int)($_POST['task_id'] ?? 0);
    $comment = trim($_POST['manager_comment'] ?? '');

    if ($t_id > 0) {
        try {
            $checkStmt = $pdo->prepare("SELECT status FROM tasks WHERE id = ?");
            $checkStmt->execute([$t_id]);
            if ($checkStmt->fetchColumn() === 'completed' && $u_role !== 'admin') {
                echo json_encode(['status' => 'error', 'message' => 'Редактирование отчета заблокировано! Изменять комментарий выполненного поручения может только Администратор.']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE tasks SET manager_comment = ? WHERE id = ?");
            $stmt->execute([$comment, $t_id]);
            echo json_encode(['status' => 'success']);
            exit;
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }
    exit;
}


// =========================================================================
// БЛОК 2: АСИНХРОННЫЙ ПРИЕМ ДАННЫХ СМЕНЫ СТАТУСА (UPDATE)
// =========================================================================
try {
    // Вытягиваем всех живых менеджеров/админов для выпадающего списка формы
    $uStmt = $pdo->query("SELECT id, login FROM users ORDER BY login ASC");
    $all_users = $uStmt->fetchAll() ?: [];

    // Выгружаем задачи (t.created_by теперь сразу содержит готовый текстовый логин)
    if ($u_role === 'admin') {
        $sql = "SELECT t.*, u.login AS manager_name 
                FROM tasks t 
                LEFT JOIN users u ON t.user_id = u.id 
                ORDER BY t.id DESC";
        $stmt = $pdo->query($sql);
    } else {
        $sql = "SELECT t.*, u.login AS manager_name 
                FROM tasks t 
                LEFT JOIN users u ON t.user_id = u.id 
                WHERE t.user_id = ? 
                ORDER BY t.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
    }
    $tasks = $stmt->fetchAll() ?: [];
} catch (Exception $e) {
    die("Критический сбой СУБД при выгрузке списка задач: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_task_comment') {
    header('Content-Type: application/json');
    if (ob_get_length()) ob_clean();

    $t_id    = (int)($_POST['task_id'] ?? 0);
    $comment = trim($_POST['manager_comment'] ?? '');

    if ($t_id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE tasks SET manager_comment = ? WHERE id = ?");
            $stmt->execute([$comment, $t_id]);
            echo json_encode(['status' => 'success']);
            exit;
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }
    exit;
}

// =========================================================================
// БЛОК 3: ВЫГРУЗКА ДАННЫХ ДЛЯ ТАБЛИЦЫ И ФОРМЫ СПИСКА МЕНЕДЖЕРОВ
// =========================================================================
try {
    // Вытягиваем всех живых менеджеров/админов для выпадающего списка формы
    $uStmt = $pdo->query("SELECT id, login FROM users ORDER BY login ASC");
    $all_users = $uStmt->fetchAll() ?: [];

    // Выгружаем задачи с джоином ответственного исполнителя
    if ($u_role === 'admin') {
        $sql = "SELECT t.*, u.login AS manager_name FROM tasks t LEFT JOIN users u ON t.user_id = u.id ORDER BY t.id DESC";
        $stmt = $pdo->query($sql);
    } else {
        $sql = "SELECT t.*, u.login AS manager_name FROM tasks t LEFT JOIN users u ON t.user_id = u.id WHERE t.user_id = ? ORDER BY t.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
    }
    $tasks = $stmt->fetchAll() ?: [];
} catch (Exception $e) {
    die("Критический сбой СУБД: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Поручения и задачи — Santeks</title>
    <style>
        body { background: #151521; color: #fff; font-family: sans-serif; padding: 0; margin: 0; display: flex; min-height: 100vh; }
        aside { width: 240px; background: #1e1e2d; border-right: 1px solid #323248; flex-shrink: 0; }
        main { flex: 1; min-width: 0; padding: 40px; box-sizing: border-box; overflow-y: auto; display: flex; flex-direction: column; gap: 20px; }
        .card { background: #1e1e2d; border: 1px solid #323248; border-radius: 8px; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); box-sizing: border-box; width: 100%; }
        .form-inline { display: flex; align-items: flex-end; gap: 15px; flex-wrap: wrap; }
        .f-group { display: flex; flex-direction: column; gap: 4px; }
        .f-label { font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; }
        .f-input { height: 40px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; box-sizing: border-box; }
        table { width: 100%; border-collapse: collapse; margin: 0; }
        th { background: #242434; padding: 14px 10px; color: #92929f; text-transform: uppercase; font-size: 11px; font-weight: bold; text-align: center; border-bottom: 2px solid #323248; white-space: nowrap; }
        td { padding: 12px 10px; border-bottom: 1px solid #2b2b40; font-size: 13px; text-align: center; background: #1e1e2d; color: #fff; }
        .btn-status { border: none; padding: 6px 12px; border-radius: 4px; font-weight: bold; font-size: 11px; cursor: pointer; color: #fff; text-transform: uppercase; }
        .status-pending { background: #f59e0b; }
        .status-completed { background: #10b981; }
    </style>
</head>
<body>

    <!-- МЕНЮ SIDEBAR -->
    <aside>
        <?php include 'sidebar.php'; ?>
    </aside>

    <!-- ОСНОВНОЙ КОНТЕНТ -->
    <main>
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #323248; padding-bottom: 15px;">
            <h1 style="margin: 0; font-size: 24px; font-weight: bold; letter-spacing: -0.5px;">📋 Поручения и текущие задачи</h1>
            <span style="color: #64748b; font-size: 14px;">Авторизован: <strong style="color:#fff;"><?= htmlspecialchars($_SESSION['login'] ?? '') ?></strong></span>
        </div>
<?php if ($u_role === 'admin'): ?>
        <!-- ГОРИЗОНТАЛЬНАЯ ФОРМА ПОСТАНОВКИ ЗАДАЧ -->
        <div class="card">
            <h3 style="margin: 0 0 15px 0; font-size: 14px; text-transform: uppercase; color: #818cf8; letter-spacing: 0.5px;">Поставить новое поручение:</h3>
            <form method="POST" action="tasks.php" class="form-inline">
                <input type="hidden" name="action" value="create_new_task">
                
                <div class="f-group" style="flex: 2; min-width: 250px;">
                    <label class="f-label">Что нужно сделать:</label>
                    <input type="text" name="task_text" required placeholder="Введите текст задачи..." class="f-input" style="width: 100%;">
                </div>

                <div class="f-group" style="flex: 1; min-width: 180px;">
                    <label class="f-label">Комментарий / Примечание:</label>
                    <input type="text" name="manager_comment" placeholder="Доп. информация..." class="f-input" style="width: 100%;">
                </div>

                <div class="f-group" style="width: 150px;">
                    <label class="f-label">Срок до:</label>
                    <input type="date" name="due_date" value="<?= date('Y-m-d', strtotime('+3 days')) ?>" class="f-input" style="width: 100%;">
                </div>

                <?php if ($u_role === 'admin'): ?>
                    <div class="f-group" style="width: 160px;">
                        <label class="f-label">Исполнитель:</label>
                        <select name="user_id" class="f-input" style="width: 100%; cursor: pointer;">
                            <?php foreach ($all_users as $u): ?>
                                <option value="<?= (int)$u['id'] ?>" <?= $u['id'] == $userId ? 'selected' : '' ?>>
                                    👤 <?= htmlspecialchars($u['login']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <button type="submit" style="height: 40px; padding: 0 20px; background: #4f46e5; border: none; color: #fff; border-radius: 6px; font-weight: bold; font-size: 13px; cursor: pointer; transition: 0.15s;" onmouseover="this.style.background='#4338ca';" onmouseout="this.style.background='#4f46e5';">
                    ➕ Поставить задачу
                </button>
                 <?php endif; ?>
            </form>
        </div>

        <!-- ТАБЛИЦА С ВЕРТИКАЛЬНЫМ И ГОРИЗОНТАЛЬНЫМ СКРОЛЛОМ -->
        <div class="card" style="padding: 0; overflow: hidden;">
            <div style="max-height: 600px; overflow-y: auto; overflow-x: auto; width: 100%;">
                <table style="width: 100%; min-width: 1200px;">
                   <thead>
    <tr>
        <th style="width: 50px;">П/П</th>
        <th style="text-align: left; min-width: 300px;">Текст поручения</th>
        <th style="text-align: left; min-width: 250px;">Комментарий менеджера</th>
        <th style="width: 130px;">Срок исполнения</th>
        
        <!-- НАШ НОВЫЙ СТОЛБЕЦ В ШАПКЕ -->
        <th style="width: 140px; color: #818cf8;">Отправитель</th> 
        
        <th style="width: 140px;">Ответственный</th>
        <th style="width: 120px;">Статус</th>
        <th style="width: 140px; color: #10b981;">Дата выполнения</th>
    </tr>
</thead>
                    <tbody>
                        <?php if (!empty($tasks)): $pp = 1; foreach ($tasks as $row): ?>
                            <tr>
                                <td style="color: #64748b; font-weight: bold;"><?= $pp++ ?></td>
<td style="text-align: left; line-height: 1.4; color: #fff; max-width: 400px;">
                                    <?= htmlspecialchars($row['task_text'] ?? '') ?>
                                </td>
                                
                                <!-- ВЫВОД КОММЕНТАРИЯ МЕНЕДЖЕРА -->
    <?php 
$isDone = ($row['status'] === 'completed'); 
// Менеджер не может редактировать закрытое, а Админ — может всегда!
$canEditComment = !$isDone || ($u_role === 'admin');
?>
<td style="text-align: left; line-height: 1.4; padding: 8px; background: <?= $canEditComment ? '#1a1a24' : '#151521' ?>; border: 1px <?= $canEditComment ? 'dashed #323248' : 'solid #2b2b40' ?>; border-radius: 6px; min-width: 250px;">
    
    <div id="comment_edit_<?= (int)$row['id'] ?>"
         contenteditable="<?= $canEditComment ? 'true' : 'false' ?>"
         style="color: <?= $canEditComment ? '#92929f' : '#64748b' ?>; outline: none; cursor: <?= $canEditComment ? 'text' : 'not-allowed' ?>; min-height: 20px; box-sizing: border-box;"
         placeholder="Добавить комментарий..."
         onfocus="document.getElementById('comment_btn_<?= (int)$row['id'] ?>').style.display = 'inline-block';">
        <?= htmlspecialchars($row['manager_comment'] ?? '') ?>
    </div>

    <?php if ($canEditComment): ?>
    <div id="comment_btn_<?= (int)$row['id'] ?>" style="display: none; margin-top: 6px; text-align: right; width: 100%;">
        <button type="button" onclick="saveInlineTaskComment(<?= (int)$row['id'] ?>); return false;" style="background: #10b981; color: white; border: none; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; cursor: pointer;">💾 Сохранить</button>
    </div>
    <?php endif; ?>
</td>

                                <!-- СРОК ИСПОЛНЕНИЯ -->
                                <td style="color: #f43f5e; font-weight: 500;">
                                    <?= !empty($row['due_date']) ? date('d.m.Y', strtotime($row['due_date'])) : '—' ?>
                                </td>
                                <td style="color: #818cf8; font-weight: bold;">
            👑 <?= htmlspecialchars($row['created_by'] ?? 'Система') ?>
        </td>
                                <!-- ОТВЕТСТВЕННЫЙ ИСПОЛНИТЕЛЬ -->
                                <td style="color: #a1a1aa; font-weight: bold;">
                                    <?= htmlspecialchars($row['manager_name'] ?? 'Не назначен') ?>
                                </td>
                                
                                <!-- КНОПКА СМЕНЫ СТАТУСА (PENDING / COMPLETED) -->
                               <td>
    <?php 
    // Кнопка заблокирована для менеджера, если задача выполнена, но Админ имеет доступ ВСЕГДА!
    $isButtonDisabled = $isDone && ($u_role !== 'admin'); 
    ?>
    <button type="button" 
            <?= $isButtonDisabled ? 'disabled' : '' ?>
            class="btn-status <?= $isDone ? 'status-completed' : 'status-pending' ?>"
            style="cursor: <?= $isButtonDisabled ? 'not-allowed' : 'pointer' ?>; opacity: <?= $isButtonDisabled ? '0.65' : '1' ?>;"
            onclick="toggleTaskStatus(<?= (int)$row['id'] ?>, '<?= htmlspecialchars($row['status']) ?>')"
            onmouseover="if(<?= $isDone ? 'true' : 'false' ?> && '<?= $u_role ?>' === 'admin') { this.style.background='#f43f5e'; this.innerText='На доработку ↩'; }"
            onmouseout="if(<?= $isDone ? 'true' : 'false' ?>) { this.style.background='#10b981'; this.innerText='Выполнено'; }">
        <?= $isDone ? 'Выполнено' : 'В работе' ?>
    </button>
</td>

                                <!-- АВТОМАТИЧЕСКАЯ ДАТА ИСПОЛНЕНИЯ ИЗ БАЗЫ ДАННЫХ -->
                                <td style="font-weight: bold; color: #10b981;">
                                    <?php if (!empty($row['executed_at']) && $row['executed_at'] !== '0000-00-00'): ?>
                                        <?= date('d.m.Y', strtotime($row['executed_at'])) ?>
                                    <?php else: ?>
                                        <span style="color: #64748b;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="7" style="padding: 30px; color: #64748b; text-align: center;">Список поручений пуст.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

<script>
// ЖИВОЙ ДВИЖОК АСИНХРОННОЙ СМЕНЫ СТАТУСОВ И ФИКСАЦИИ ДАТ ПОД WINDOWS XAMPP
// ИСПРАВЛЕНО НАМЕРТВО: Запрет закрытия задачи без заполненного комментария менеджера
async function toggleTaskStatus(taskId, currentStatus) {
    // 1. Находим ячейку комментария для этой конкретной задачи
    const commentBlock = document.getElementById('comment_edit_' + taskId);
    const commentText = commentBlock ? commentBlock.innerText.trim() : '';

    // 2. Если задачу пытаются перевести в "Выполнено" (из статуса pending)
    if (currentStatus === 'pending' || currentStatus === 'В работе') {
        if (commentText === '' || commentText === '—') {
            console.log("Блокировка: Попытка закрыть задачу ID " + taskId + " без отчета!");
            
            // Визуальный сигнал ошибки: красим границы и фон блока комментария в красный цвет
            if (commentBlock) {
                const parentTd = commentBlock.parentElement;
                parentTd.style.transition = 'all 0.3s ease';
                parentTd.style.border = '1px solid #f43f5e';
                parentTd.style.background = 'rgba(244, 63, 94, 0.15)';
                
                // Фокусируем курсор менеджера на поле, чтобы он сразу начал писать
                commentBlock.focus();
                
                // Всплывающее предупреждение
                alert("⚠️ Внимание! Нельзя перевести поручение в статус 'Выполнено' без заполнения поля 'Комментарий менеджера'. Пожалуйста, напишите краткий отчет о выполнении.");
                
                // Через 3 секунды плавно возвращаем стандартный стиль, но оставляем фокус
                setTimeout(() => {
                    parentTd.style.border = '1px dashed #323248';
                    parentTd.style.background = '#1a1a24';
                }, 3000);
            }
            return; // МЕРТВАЯ БЛОКИРОВКА: Прерываем выполнение функции, статус не меняется!
        }
    }

    // 3. Если проверка пройдена (или задачу возвращают обратно в работу) — шлем стандартный POST
    console.log("Проверка пройдена. Смена статуса для задачи ID:", taskId);
    
    try {
        const params = new URLSearchParams();
        params.append('action', 'update_task_status');
        params.append('task_id', parseInt(taskId, 10));
        params.append('status', currentStatus);

        const res = await fetch('tasks.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        });
        
        const result = await res.json();
        if (result.status === 'success') {
            window.location.reload(); // Перезагружаем для отрисовки даты
        } else {
            alert("Ошибка СУБД: " + result.message);
        }
    } catch (err) {
        console.error("Сбой сети:", err);
        alert("Критическая ошибка отправки запроса.");
    }
}

async function saveInlineTaskComment(taskId) {
    // Находим блок текста по его уникальному ID
    const textBlock = document.getElementById('comment_edit_' + taskId);
    const saveBtnBlock = document.getElementById('comment_btn_' + taskId);
    
    if (!textBlock) return;
    const cleanText = textBlock.innerText.trim();

    console.log("Сохранение комментария по кнопке. ID:", taskId, "Текст:", cleanText);

    try {
        const params = new URLSearchParams();
        params.append('action', 'update_task_comment');
        params.append('task_id', parseInt(taskId, 10));
        params.append('manager_comment', cleanText);

        const res = await fetch('tasks.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        });

        const result = await res.json();
        
        if (result.status === 'success') {
            console.log("Комментарий успешно зафиксирован в СУБД MariaDB!");
            
            // 1. Скрываем кнопку сохранения обратно, так как данные уже на сервере
            if (saveBtnBlock) {
                saveBtnBlock.style.display = 'none';
            }
            
            // 2. Делаем красивую короткую подсветку блока зелёным цветом для индикации успеха
            textBlock.style.transition = 'color 0.2s';
            textBlock.style.color = '#10b981';
            setTimeout(() => {
                textBlock.style.color = '#92929f';
            }, 800);

        } else {
            alert("Ошибка СУБД при сохранении: " + result.message);
        }
    } catch (err) {
        console.error("Сбой сети при отправке комментария:", err);
        alert("Критический сбой сети. Проверьте соединение с Windows XAMPP.");
    }
}
</script>
</body>
</html>