<?php
session_start();
require 'db.php';

// ЖЕСТКАЯ БЕЗОПАСНОСТЬ: Доступ только админам
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish_update') {
    $upTitle = trim($_POST['update_title'] ?? '');
    $upText  = trim($_POST['update_text'] ?? '');
    
    if (!empty($upTitle) && !empty($upText)) {
        // 1. Записываем системное уведомление в базу данных
        $stmtUp = $pdo->prepare("INSERT INTO system_updates (title, text) VALUES (?, ?)");
        $stmtUp->execute([$upTitle, $upText]);
        
        // 2. Пишем лог в центральный журнал аудита
        if (function_exists('logAction')) {
            logAction($pdo, 'INSERT', 'system_updates', "Админ опубликовал системное уведомление: '{$upTitle}'");
        }
        
        // 3. ПОДТВЕРЖДЕНИЕ ДЛЯ ТЕБЯ: Теперь ты на 100% знаешь, что всё дошло!
        echo "<script>
            alert('🚀 УСПЕХ! Оповещение успешно записано в базу данных и запущено для всех менеджеров!'); 
            window.location.href='suggestions.php';
        </script>";
        exit;
    }
}
// СВЯЗЫВАНИЕ ТАБЛИЦ: Вытаскиваем предложения и текстовый LOGIN автора вместо ID
$sql = "SELECT s.*, u.login 
        FROM suggestions s 
        LEFT JOIN users u ON s.user_id = u.id 
        ORDER BY s.id DESC";
$stmt = $pdo->query($sql);
$suggestions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Журнал предложений - Santeks CRM</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { background: #151521; color: #fff; font-family: sans-serif; padding: 30px; margin:0; display: flex; }
        aside { width: 250px; }
        .main-content { flex: 1; padding-left: 20px; }
        .sug-container { background: #1e1e2d; padding: 25px; border-radius: 16px; border: 1px solid #323248; box-shadow: 0 10px 35px rgba(0,0,0,0.3); }
        .sug-table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; }
        .sug-table th { background: #242434; color: #92929f; padding: 12px; border: 1px solid #2b2b40; text-transform: uppercase; font-size: 11px; }
        .sug-table td { padding: 12px; border: 1px solid #2b2b40; color: #e2e8f0; vertical-align: top; }
        .sug-table tr:hover { background: #222235; }
        
        /* Стили статусов */
        .status-select { padding: 4px 8px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 4px; font-size: 11px; cursor: pointer; }
        .badge-new { color: #10b981; font-weight: bold; }
        .badge-work { color: #f6ad55; font-weight: bold; }
        .badge-done { color: #6366f1; font-weight: bold; }
        .badge-reject { color: #f56565; font-weight: bold; }
    </style>
</head>
<body>

   
        <?php include 'sidebar.php'; ?>


    <div class="main-content">
                <!-- ФОРМА ПУБЛИКАЦИИ ОБНОВЛЕНИЙ СИСТЕМЫ (ИСПРАВЛЕНИЕ/РАСШИРЕНИЕ) -->
        <?php
        // Обработка отправки нового апдейта админом
        if (isset($_POST['action']) && $_POST['action'] === 'publish_update') {
            $upTitle = trim($_POST['update_title'] ?? '');
            $upText = trim($_POST['update_text'] ?? '');
            
            if (!empty($upTitle) && !empty($upText)) {
                $stmtUp = $pdo->prepare("INSERT INTO system_updates (title, text) VALUES (?, ?)");
                $stmtUp->execute([$upTitle, $upText]);
                
                if (function_exists('logAction')) {
                    logAction($pdo, 'INSERT', 'system_updates', "Админ опубликовал системное уведомление: '{$upTitle}'");
                }
                echo "<script>alert('🚀 Уведомление успешно запущено в систему!'); window.location.href='view_suggestions.php';</script>";
                exit;
            }
        }
        ?>
        <?php if ($u_role === 'admin'): ?>
        <div style="background: #1e1e2d; padding: 20px; border-radius: 16px; border: 1px solid #323248; margin-bottom: 25px; box-shadow: 0 10px 35px rgba(0,0,0,0.2);">
            <h3 style="margin: 0 0 15px 0; font-size: 15px; color: #818cf8; text-transform: uppercase; letter-spacing: 0.5px;">📢 Оповестить менеджеров об обновлении базы</h3>
            <form method="POST" style="display: flex; flex-direction: column; gap: 12px; margin: 0; padding: 0;">
                <input type="hidden" name="action" value="publish_update">
                <input type="text" name="update_title" required placeholder="Заголовок (например: Обновление модуля Контрактов)" style="height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px;">
                <textarea name="update_text" required placeholder="Текст сообщения (что изменилось, как пользоваться...)" style="height: 80px; padding: 10px 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; resize: none; font-family: sans-serif;"></textarea>
                <div style="display: flex; justify-content: flex-end;">
                    <button type="submit" style="height: 38px; padding: 0 25px; background: #4f46e5; border: none; color: #fff; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px; transition: 0.2s;">🚀 Запустить рассылку апдейта</button>
                </div>
            </form>
        </div>
        endif; ?>
        <div class="sug-container">
            <h2 style="margin: 0; font-size: 18px; margin-bottom: 20px;">💡 Журнал предложений и идей от менеджеров</h2>
            
            <table class="sug-table">
                <thead>
                    <tr>
                        <th style="width: 130px;">Дата подачи</th>
                        <th style="width: 140px;">Автор (Логин)</th>
                        <th style="width: 200px;">Суть идеи</th>
                        <th>Подробное описание</th>
                        <th style="width: 150px; text-align: center;">Статус</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($suggestions as $s): ?>
                    <tr>
                        <td style="color:#92929f;"><?= date('d.m.Y H:i', strtotime($s['created_at'])) ?></td>
                        <!-- ИСПРАВЛЕНО: Выводим текстовый логин вместо ID -->
                        <td><strong><?= htmlspecialchars($s['login'] ?? 'Удален') ?></strong></td>
                        <td><span style="color: #818cf8; font-weight: bold;"><?= htmlspecialchars($s['title']) ?></span></td>
                        <td style="white-space: pre-line; color: #cbd5e1;"><?= htmlspecialchars($s['text']) ?></td>
                        <td style="text-align: center;">
                            <!-- Форма быстрой смены статуса прямо из таблицы -->
                            <form method="POST" style="margin:0; padding:0;">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="suggestion_id" value="<?= $s['id'] ?>">
                                <select name="status" onchange="this.form.submit()" class="status-select">
                                    <option value="Новое" <?= $s['status'] === 'Новое' ? 'selected' : '' ?> class="badge-new">Новое</option>
                                    <option value="В работе" <?= $s['status'] === 'В работе' ? 'selected' : '' ?> class="badge-work">В работе</option>
                                    <option value="Реализовано" <?= $s['status'] === 'Реализовано' ? 'selected' : '' ?> class="badge-done">Реализовано</option>
                                    <option value="Отклонено" <?= $s['status'] === 'Отклонено' ? 'selected' : '' ?> class="badge-reject">Отклонено</option>
                                </select>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($suggestions)): ?>
                        <tr><td colspan="5" style="text-align:center; color:#64748b; padding: 30px;">Идей от менеджеров пока не поступало</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
