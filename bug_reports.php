<?php
// bug_reports.php — Технический журнал багов Santeks CRM (Числовые статусы 0, 1, 2)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html"); exit;
}

$userId  = (int)$_SESSION['user_id'];
$u_role  = $_SESSION['role'] ?? 'manager';

// =========================================================================
// 1. ОБРАБОТКА ДЕЙСТВИЙ И AJAX ЗАПРОСОВ (ЧИСЛОВЫЕ СТАТУСЫ)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = json_decode(file_get_contents('php://input'), true) ?: [];
    
    $action     = $_POST['action'] ?? ($rawInput['action'] ?? '');
    $b_id       = (int)($_POST['id'] ?? ($rawInput['id'] ?? 0));
    $comment    = trim($_POST['comment'] ?? ($rawInput['comment'] ?? ''));
    $new_status = isset($_POST['status']) ? (int)$_POST['status'] : (isset($rawInput['status']) ? (int)$rawInput['status'] : -1);

    if ($action === 'update_admin_comment') {
        header('Content-Type: application/json');
        if (ob_get_length()) ob_clean();
        try {
            $pdo->prepare("UPDATE bug_reports SET admin_comment = ? WHERE id = ?")->execute([$comment, $b_id]);
            echo json_encode(["status" => "success"]); exit;
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]); exit;
        }
    }

    if ($action === 'update_bug_status') {
        header('Content-Type: application/json');
        if (ob_get_length()) ob_clean();
        try {
            if ($b_id <= 0 || $new_status < 0) {
                throw new Exception("Некорректные числовые параметры");
            }
            $pdo->prepare("UPDATE bug_reports SET status = ? WHERE id = ?")->execute([$new_status, $b_id]);
            echo json_encode(["status" => "success"]); exit;
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]); exit;
        }
    }
    
    if (isset($_POST['bug_title'])) {
        $title = trim($_POST['bug_title']);
        $desc  = trim($_POST['bug_desc']);
        if (!empty($title) && !empty($desc)) {
            $pdo->prepare("INSERT INTO bug_reports (title, description, user_id, status) VALUES (?, ?, ?, 0)")->execute([$title, $desc, $userId]);
            header("Location: bug_reports.php"); exit;
        }
    }
}

// =========================================================================
// 2. СБОР СВЕЖЕЙ СТАТИСТИКИ ДЛЯ ДАШБОРДА (БЕЗ КОНФЛИКТОВ С КИРИЛЛИЦЕЙ)
// =========================================================================
$bugStats = ['total' => 0, 'new' => 0, 'work' => 0, 'done' => 0];
try {
    $statsData = $pdo->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as `new`,
        SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as `work`,
        SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) as `done`
    FROM bug_reports")->fetch();
    if ($statsData) {
        $bugStats = [
            'total' => (int)$statsData['total'],
            'new'   => (int)$statsData['new'],
            'work'  => (int)$statsData['work'],
            'done'  => (int)$statsData['done']
        ];
    }
} catch (Exception $e) { }

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
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; text-transform: uppercase; display: inline-block; }
        .badge-new { background: #3d1d1d; color: #ef4444; }
        .badge-work { background: #3d2d1d; color: #f59e0b; }
        .badge-done { background: #1a2e26; color: #10b981; }
        .stat-card { flex: 1; min-width: 140px; background: #1e1e2d; border: 1px solid #323248; border-radius: 8px; padding: 15px; text-align: center; }
        .bug-checkbox { width: 18px; height: 18px; cursor: pointer; accent-color: #10b981; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div style="flex-shrink: 0; width: 260px;"><?php include 'sidebar.php'; ?></div>
        <div class="main-content">
            <h1 style="margin-top: 0; font-size: 24px; margin-bottom: 25px;">🪲 Technical bug tracker</h1>
            
            <div style="display: flex; gap: 15px; margin-bottom: 25px; flex-wrap: wrap;">
                <div class="stat-card"><div style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; margin-bottom: 5px;">Всего багов</div><div style="font-size: 24px; font-weight: bold; color: #fff;"><?= $bugStats['total'] ?></div></div>
                <div class="stat-card"><div style="font-size: 11px; color: #ef4444; font-weight: bold; text-transform: uppercase; margin-bottom: 5px;">🔴 Новые</div><div style="font-size: 24px; font-weight: bold; color: #ef4444;"><?= $bugStats['new'] ?></div></div>
                <div class="stat-card"><div style="font-size: 11px; color: #f59e0b; font-weight: bold; text-transform: uppercase; margin-bottom: 5px;">🟡 В работе</div><div style="font-size: 24px; font-weight: bold; color: #f59e0b;"><?= $bugStats['work'] ?></div></div>
                <div class="stat-card"><div style="font-size: 11px; color: #10b981; font-weight: bold; text-transform: uppercase; margin-bottom: 5px;">🟢 Исправлено</div><div style="font-size: 24px; font-weight: bold; color: #10b981;"><?= $bugStats['done'] ?></div></div>
            </div>

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
                            <th style="width: 140px; text-align: center;">Статус / Галка</th>
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
                                $status = (int)$b['status'];
                                $badgeClass = 'badge-new'; $rusStatus = 'Новый';
                                if ($status === 1) { $badgeClass = 'badge-work'; $rusStatus = 'В работе'; }
                                if ($status === 2) { $badgeClass = 'badge-done'; $rusStatus = 'Исправлено'; }
                            ?>
                                <tr>
                                    <td style="color: #64748b !important;"><?= (int)$b['id'] ?></td>
                                    <td style="text-align: center;">
                                        <?php if ($u_role === 'admin'): ?>
                                            <div style="display: flex; align-items: center; gap: 8px; justify-content: center;">
                                                                                            <div style="display: flex; align-items: center; gap: 8px; justify-content: center;">
                                                <input type="checkbox" class="bug-checkbox" 
                                                       data-bug-id="<?= $b['id'] ?>" 
                                                       <?= $status === 2 ? 'checked' : '' ?>
                                                       onchange="toggleBugStatus(<?= $b['id'] ?>, this.checked);">
                                                <span id="badge_text_<?= $b['id'] ?>" class="badge <?= $badgeClass ?>" style="font-size: 10px; padding: 2px 6px;">
                                                    <?= $rusStatus ?>
                                                </span>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge <?= $badgeClass ?>"><?= $rusStatus ?></span>
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
    async function toggleBugStatus(bugId, isChecked) {
        const statusCode = isChecked ? 2 : 1;
        const badge = document.getElementById('badge_text_' + bugId);
        
        if (badge) {
            badge.innerText = isChecked ? 'Исправлено' : 'В работе';
            badge.className = isChecked ? 'badge badge-done' : 'badge badge-work';
        }

        try {
            await fetch('bug_reports.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update_bug_status', id: bugId, status: statusCode })
            });
        } catch (err) { console.error("Ошибка сети"); }
    }

    async function saveBugReply(bugId, commentValue) {
        try {
            await fetch('bug_reports.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update_admin_comment', id: bugId, comment: commentValue })
            });
        } catch (err) { console.error("Ошибка сети"); }
    }
    </script>
</body>
</html>
