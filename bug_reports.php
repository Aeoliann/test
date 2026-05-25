<?php
// bug_reports.php — Модуль фиксации технических ошибок Santeks CRM (Windows XAMPP)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html"); exit;
}

$userId  = (int)$_SESSION['user_id'];
$u_role  = $_SESSION['role'] ?? 'manager';

// Обработка отправки нового тикета
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bug_title'])) {
    $title = trim($_POST['bug_title']);
    $desc  = trim($_POST['bug_desc']);

    if (!empty($title) && !empty($desc)) {
        $stmt = $pdo->prepare("INSERT INTO bug_reports (title, description, user_id) VALUES (?, ?, ?)");
        $stmt->execute([$title, $desc, $userId]);
        header("Location: bug_reports.php"); exit;
    }
}

// Забор багов из базы данных Windows
$bugs = [];
try {
    $sql = "SELECT b.*, u.login FROM bug_reports b LEFT JOIN users u ON b.user_id = u.id ORDER BY b.id DESC";
    $bugs = $pdo->query($sql)->fetchAll();
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
        .bug-table td { padding: 14px 12px !important; border-bottom: 1px solid #2b2b40 !important; font-size: 14px; color: #fff !important; background-color: #1e1e2d !important; vertical-align: top; }
    </style>
</head>
<body>

    <div class="wrapper">
        <div style="flex-shrink: 0; width: 260px;"><?php include 'sidebar.php'; ?></div>

        <div class="main-content">
            <h1 style="margin-top: 0; font-size: 24px; margin-bottom: 25px;">🪲 Технический журнал багов и ошибок системы</h1>

            <!-- Форма отправки зафиксирована сверху -->
            <div class="bug-card">
                <form action="bug_reports.php" method="POST" style="margin: 0; padding: 0;">
                    <label style="display:block; font-size:12px; color:#92929f; margin-bottom:5px; font-weight:bold;">Краткая суть сбоя:</label>
                    <input type="text" name="bug_title" required placeholder="Напр: Кнопка скачивания Excel выдает 404..." class="bug-input">
                    
                    <label style="display:block; font-size:12px; color:#92929f; margin-bottom:5px; font-weight:bold;">Подробное описание и шаги воспроизведения:</label>
                    <textarea name="bug_desc" required placeholder="Опишите, после каких действий выскочила ошибка..." class="bug-textarea" style="height: 80px; resize: vertical; font-family: sans-serif;"></textarea>
                    
                    <button type="submit" class="btn-add">🚨 Зарегистрировать баг</button>
                </form>
            </div>

            <h2 style="font-size: 18px; margin-bottom: 15px;">📋 Список зарегистрированных тикетов</h2>
            
            <!-- ОГРАНИЧЕННЫЙ СКРОЛЛ-КОНТЕЙНЕР ДЛЯ ТАБЛИЦЫ БАГОВ -->
            <div style="max-height: 400px; overflow-y: auto; border: 1px solid #323248; border-radius: 8px; background: #1e1e2d;">
                <table class="bug-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th>
                            <th style="width: 120px;">Статус</th>
                            <th style="width: 250px;">Краткая суть</th>
                            <th>Детали / Шаги сбоя</th>
                            <th style="width: 130px;">Репортер</th>
                            <th style="width: 140px;">Дата фиксации</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($bugs) > 0): ?>
                            <?php foreach ($bugs as $b): ?>
                                <tr>
                                    <td style="color: #64748b !important;"><?= (int)$b['id'] ?></td>
                                    <td>
                                        <span style="background: #3d1d1d; color: #ef4444; padding: 3px 8px; border-radius: 4px; font-size: 12px; font-weight: bold;">
                                            ⚠️ <?= htmlspecialchars($b['status']) ?>
                                        </span>
                                    </td>
                                    <td style="font-weight: bold; color: #ef4444 !important;"><?= htmlspecialchars($b['title']) ?></td>
                                    <td style="color: #92929f !important; font-size: 13px; line-height: 1.4;"><?= nl2br(htmlspecialchars($b['description'])) ?></td>
                                    <td style="color: #a855f7 !important; font-weight: bold;">👤 <?= htmlspecialchars($b['login'] ?? 'Система') ?></td>
                                    <td style="color: #64748b !important; font-size: 13px;"><?= date('d.m.Y H:i', strtotime($b['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align: center; color: #64748b !important; padding: 40px !important;">Технических багов в системе не обнаружено.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

</body>
</html>
