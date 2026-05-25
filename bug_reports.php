<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html"); exit;
}

$userId  = (int)$_SESSION['user_id'];
$u_role  = $_SESSION['role'] ?? 'manager';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'update_admin_comment') {
        header('Content-Type: application/json');
        if (ob_get_length()) ob_clean();
        try {
            $b_id = (int)($_POST['id'] ?? 0);
            $comment = trim($_POST['comment'] ?? '');
            $pdo->prepare("UPDATE bug_reports SET admin_comment = ? WHERE id = ?")->execute([$comment, $b_id]);
            echo json_encode(["status" => "success"]); exit;
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]); exit;
        }
    }

     if (isset($_POST['action']) && $_POST['action'] === 'update_bug_status') {
        header('Content-Type: application/json');
        if (ob_get_length()) ob_clean();
        try {
            $b_id = (int)($_POST['id'] ?? 0);
            $new_status = trim($_POST['status'] ?? 'new');
            
            // Жестко пишем системный английский статус в базу Windows XAMPP
            $stmt = $pdo->prepare("UPDATE bug_reports SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $b_id]);
            
            echo json_encode(["status" => "success"]); exit;
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]); exit;
        }
    }
    
    if (isset($_POST['bug_title'])) {
        $title = trim($_POST['bug_title']);
        $desc  = trim($_POST['bug_desc']);
        if (!empty($title) && !empty($desc)) {
            $pdo->prepare("INSERT INTO bug_reports (title, description, user_id) VALUES (?, ?, ?)")->execute([$title, $desc, $userId]);
            header("Location: bug_reports.php"); exit;
        }
    }
}

$bugs = [];
try {
    $bugs = $pdo->query("SELECT b.*, u.login FROM bug_reports b LEFT JOIN users u ON b.user_id = u.id ORDER BY b.id DESC")->fetchAll();
} catch (Exception $e) { }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Баг-репорты — Santeks CRM</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { background: #151521; color: #fff; font-family: sans-serif; margin: 0; padding: 0; }
        .wrapper { display: flex; min-height: 100vh; }
        .main-content { flex: 1; padding: 30px; background: #151521; box-sizing: border-box; }
        .bug-card { background: #1e1e2d; border-radius: 8px; border: 1px solid #323248; padding: 20px; margin-bottom: 25px; }
        .bug-input, .bug-textarea { width: 100%; padding: 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 14px; box-sizing: border-box; margin-bottom: 12px; }
        .btn-add { background: #ef4444; color: #fff; border: none; padding: 12px 24px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 14px; }
        .bug-table { width: 100%; border-collapse: collapse; text-align: left; }
        .bug-table th { background: #242434; padding: 14px 12px; color: #92929f; font-size: 11px; text-transform: uppercase; font-weight: bold; border-bottom: 1px solid #323248; }
        .bug-table td { padding: 14px 12px !important; border-bottom: 1px solid #2b2b40 !important; font-size: 14px; color: #fff !important; background-color: #1e1e2d !important; vertical-align: middle; }
        .comment-input { width: 100%; padding: 8px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 4px; outline: none; font-size: 13px; box-sizing: border-box; }
        .status-select { padding: 6px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 4px; font-size: 12px; font-weight: bold; cursor: pointer; outline: none; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; text-transform: uppercase; display: inline-block; }
        .badge-new { background: #3d1d1d; color: #ef4444; }
        .badge-work { background: #3d2d1d; color: #f59e0b; }
        .badge-done { background: #1a2e26; color: #10b981; }
    </style>
</head>
<body>  
    <div class="wrapper">
        <div style="flex-shrink: 0; width: 260px;"><?php include 'sidebar.php'; ?></div>
        <div class="main-content">
            <h1 style="margin-top: 0; font-size: 24px; margin-bottom: 25px;">🪲 Технический журнал багов и ошибок системы</h1>
            <div class="bug-card">
                <form action="bug_reports.php" method="POST" style="margin: 0; padding: 0;">
                    <label style="display:block; font-size:12px; color:#92929f; margin-bottom:5px; font-weight:bold;">Краткая суть сбоя:</label>
                    <input type="text" name="bug_title" required placeholder="Напр: Ошибка дублирования вкладок при переключении..." class="bug-input">
                    <label style="display:block; font-size:12px; color:#92929f; margin-bottom:5px; font-weight:bold;">Подробное описание и шаги воспроизведения:</label>
                    <textarea name="bug_desc" required placeholder="Опишите детальнее суть технической проблемы..." class="bug-textarea" style="height: 70px; resize: vertical; font-family: sans-serif;"></textarea>
                    <button type="submit" class="btn-add">🚨 Зарегистрировать баг</button>
                </form>
            </div>
            <h2 style="font-size: 18px; margin-bottom: 15px;">📋 Список зарегистрированных тикетов</h2>
            <div style="max-height: 500px; overflow-y: auto; border: 1px solid #323248; border-radius: 8px; background: #1e1e2d;">
                <table class="bug-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">ID</th>
                            <th style="width: 140px;">Статус</th>
                            <th style="width: 220px;">Краткая суть</th>
                            <th>Детали сбоя</th>
                            <th style="width: 250px; color: #10b981 !important;">Ответ / Отчет по исправлению</th>
                            <th style="width: 110px;">Репортер</th>
                            <th style="width: 120px;">Дата</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($bugs) > 0): ?>
                            <?php foreach ($bugs as $b): 
                                $status = $b['status'];
                                $badgeClass = 'badge-new';
                                if ($status === 'В работе') $badgeClass = 'badge-work';
                                if ($status === 'Исправлено') $badgeClass = 'badge-done';
                            ?>
                                <tr>
                                    <td style="color: #64748b !important;"><?= (int)$b['id'] ?></td>
                                    <td>
           <?php if ($u_role === 'admin'): 
    $cleanStatus = mb_strtolower(trim($status), 'UTF-8');
    // Очищаем от возможных застрявших смайликов из старого бэкапа
    if (strpos($cleanStatus, 'новый') !== false) $cleanStatus = 'новый';
    if (strpos($cleanStatus, 'работе') !== false) $cleanStatus = 'в работе';
    if (strpos($cleanStatus, 'исправлено') !== false) $cleanStatus = 'исправлено';
?>
    <select class="status-select" onchange="saveBugStatus(<?= $b['id'] ?>, this.value);">
        <option value="Новый" style="color:#ef4444;" <?= $cleanStatus === 'новый' ? 'selected' : '' ?>>⚠️ Новый</option>
        <option value="В работе" style="color:#f59e0b;" <?= $cleanStatus === 'в работе' ? 'selected' : '' ?>>⏳ В работе</option>
        <option value="Исправлено" style="color:#10b981;" <?= $cleanStatus === 'исправлено' ? 'selected' : '' ?>>✓ Исправлено</option>
    </select>
<?php else: ?>
    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($status) ?></span>
<?php endif; ?> 
                                    </td>
                                     <td style="font-weight: bold; color: #ef4444 !important;"><?= htmlspecialchars($b['title']) ?></td>
                                    <td style="color: #92929f !important; font-size: 13px; line-height: 1.4;"><?= nl2br(htmlspecialchars($b['description'])) ?></td>
                                    <td>
                                        <?php if ($u_role === 'admin'): ?>
                                            <input type="text" value="<?= htmlspecialchars($b['admin_comment'] ?? '') ?>" placeholder="Напишите ответ..." class="comment-input" onchange="saveBugReply(<?= $b['id'] ?>, this.value);">
                                        <?php else: ?>
                                            <span style="color: #10b981; font-size: 13px; font-weight: 500; display: block; line-height: 1.4;">
                                                <?= !empty($b['admin_comment']) ? '💬 ' . htmlspecialchars($b['admin_comment']) : '<span style="color:#64748b;">Ожидает проверки...</span>' ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color: #a855f7 !important; font-weight: bold;">👤 <?= htmlspecialchars($b['login'] ?? 'Система') ?></td>
                                    <td style="color: #64748b !important; font-size: 13px;"><?= date('d.m.Y H:i', strtotime($b['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" style="text-align: center; color: #64748b !important; padding: 40px !important;">Журнал пуст.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <script>
    async function saveBugStatus(bugId, statusValue) {
        const fd = new FormData();
fd.append('action', 'update_bug_status');
fd.append('id', bugId);
fd.append('status', statusValue);
try { 
    await fetch('bug_reports.php', { method: 'POST', body: fd }); 
    } catch (err) {}
}
async function saveBugReply(bugId, commentValue) {
    const fd = new FormData();
    fd.append('action', 'update_admin_comment');
    fd.append('id', bugId);
    fd.append('comment', commentValue);
    try { await fetch('bug_reports.php', { method: 'POST', body: fd });
 } catch (err) {}
    }

    // ТОЧЕЧНЫЙ ФИКС: Отказоустойчивая AJAX-отправка отчетов админа
async function saveBugReply(bugId, commentValue) {
    console.log("Отправка отчета для бага ID:", bugId, "Текст:", commentValue);
    
    const fd = new FormData(); 
    fd.append('action', 'update_admin_comment'); 
    fd.append('id', bugId); 
    fd.append('comment', commentValue);

    try { 
        const response = await fetch('bug_reports.php', { 
            method: 'POST', 
            body: fd
        }); 
        
        const rawText = await response.text();
        console.log("Сырой ответ сервера по багу:", rawText);
        
        const result = JSON.parse(rawText);
        if (result.status !== 'success') {
            alert("Ошибка сохранения на сервере: " + result.message);
        }
    } catch (err) { 
        console.error("Критический сбой сети при сохранении ответа:", err); 
    }
}
// ТОЧЕЧНЫЙ WINDOWS-ФИКС: Отказоустойчивая AJAX-смена статуса бага
async function saveBugStatus(bugId, statusValue) {
    console.log("Старт смены статуса. Баг ID:", bugId, "Новый статус:", statusValue);
    
    const fd = new FormData();
    fd.append('action', 'update_bug_status');
    fd.append('id', bugId);
    fd.append('status', statusValue);
    
    try {
        const response = await fetch('bug_reports.php', { 
            method: 'POST', 
            body: fd 
        });
        
        const rawText = await response.text();
        console.log("Сырой ответ сервера по статусу бага:", rawText);
        
        const result = JSON.parse(rawText);
        if (result.status !== 'success') {
            alert("Ошибка изменения статуса на сервере: " + result.message);
        }
    } catch (err) {
        console.error("Критический сбой сети при смене статуса:", err);
    }
}

</script>