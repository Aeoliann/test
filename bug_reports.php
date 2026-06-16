<?php
// bug_reports.php — Часть 1: Логика и обработка СУБД
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: auth.html"); 
    exit;
}

$userId  = (int)$_SESSION['user_id'];
$u_role  = $_SESSION['role'] ?? 'manager';

// 1. АСИНХРОННЫЙ AJAX БЭКЕНД
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = json_decode(file_get_contents('php://input'), true) ?: [];
    
    $action     = $_POST['action'] ?? ($rawInput['action'] ?? '');
    $b_id       = (int)($_POST['id'] ?? ($rawInput['id'] ?? 0));
    $comment    = trim($_POST['comment'] ?? ($rawInput['comment'] ?? ''));
    $new_status = isset($_POST['status']) ? (int)$_POST['status'] : (isset($rawInput['status']) ? (int)$rawInput['status'] : -1);

    if ($action === 'update_admin_comment') {
        header('Content-Type: application/json');
        try {
            $pdo->prepare("UPDATE bug_reports SET admin_comment = ? WHERE id = ?")->execute([$comment, $b_id]);
            echo json_encode(["status" => "success"]); exit;
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]); exit;
        }
    }

    if ($action === 'update_bug_status') {
        header('Content-Type: application/json');
        try {
            if ($b_id <= 0 || $new_status < 0) { throw new Exception("Некорректные параметры"); }
            $pdo->prepare("UPDATE bug_reports SET status = ? WHERE id = ?")->execute([$new_status, $b_id]);
            echo json_encode(["status" => "success"]); exit;
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]); exit;
        }
    }
    
    // Сохранение нового бага со скриншотом
    if (isset($_POST['bug_title']) || (isset($_POST['action_type']) && $_POST['action_type'] === 'ajax_save_bug')) {
        $title = trim($_POST['bug_title'] ?? '');
        $desc  = trim($_POST['bug_desc'] ?? '');
        $isAjax = (isset($_POST['action_type']) && $_POST['action_type'] === 'ajax_save_bug');
        $imagePath = null;

        try {
            if (empty($title) || empty($desc)) { throw new Exception("Заполните поля!"); }

            if (isset($_FILES['bug_screenshot']) && $_FILES['bug_screenshot']['error'] === UPLOAD_ERR_OK) {
                if (!file_exists('./uploads')) { mkdir('./uploads', 0777, true); }
                $fileTmpPath = $_FILES['bug_screenshot']['tmp_name'];
                $fileName = $_FILES['bug_screenshot']['name'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                
                $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
                $dest_path = './uploads/' . $newFileName;

                if (move_uploaded_file($fileTmpPath, $dest_path)) { $imagePath = $dest_path; }
            }

            $sqlBug = "INSERT INTO bug_reports (title, description, user_id, status, image_path) VALUES (?, ?, ?, 0, ?)";
            $pdo->prepare($sqlBug)->execute([$title, $desc, $userId, $imagePath]);

            if (function_exists('logAction')) {
                logAction($pdo, 'INSERT', 'bug_reports', "Зарегистрирован баг: '{$title}'");
            }

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success']); exit;
            }
            header("Location: bug_reports.php"); exit;
        } catch (Exception $e) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); exit;
            }
        }
    }
}

// 2. СБОР СТАТИСТИКИ
$bugStats = ['total' => 0, 'new' => 0, 'work' => 0, 'done' => 0];
try {
    $statsData = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as `new`, SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as `work`, SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) as `done` FROM bug_reports")->fetch();
    if ($statsData) {
        $bugStats = ['total' => (int)$statsData['total'], 'new' => (int)$statsData['new'], 'work' => (int)$statsData['work'], 'done' => (int)$statsData['done']];
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
    <title>Technical Bug Tracker - Santeks CRM</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* БАЗОВАЯ СЕТКА СИСТЕМЫ С ЗАЩИТОЙ ОТ СДВИГОВ */
        body { background: #151521; color: #fff; font-family: 'Segoe UI', Roboto, sans-serif; padding: 30px; margin: 0; display: flex; box-sizing: border-box; }
        aside { width: 250px; flex-shrink: 0; }
        
        .main-content { flex: 1; padding-left: 25px; box-sizing: border-box; display: flex; flex-direction: column; gap: 20px; min-width: 0; }
        
        /* СТИЛИ ДАШБОРДА СТАТИСТИКИ */
        .stat-container { display: flex; gap: 15px; flex-wrap: wrap; width: 100%; box-sizing: border-box; }
        .stat-card { background: #1e1e2d; padding: 20px; border-radius: 12px; border: 1px solid #323248; flex: 1; min-width: 160px; box-shadow: 0 4px 20px rgba(0,0,0,0.2); box-sizing: border-box; }
        .stat-label { font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; margin-bottom: 6px; letter-spacing: 0.5px; }
        .stat-val { font-size: 26px; font-weight: bold; color: #fff; }
        
        /* СТИЛИ ФОРМЫ РЕГИСТРАЦИИ БАГОВ */
        .bug-card { background: #1e1e2d; padding: 25px; border-radius: 16px; border: 1px solid #323248; box-shadow: 0 10px 30px rgba(0,0,0,0.3); box-sizing: border-box; width: 100%; }
        .bug-input { width: 100%; height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; font-size: 13px; box-sizing: border-box; margin-bottom: 12px; transition: border-color 0.15s; }
        .bug-input:focus { border-color: #4f46e5; }
        .bug-textarea { width: 100%; height: 85px; padding: 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; font-size: 13px; resize: vertical; font-family: sans-serif; box-sizing: border-box; transition: border-color 0.15s; }
        .btn-add { width: 100%; height: 40px; background: #ef4444; color: #fff; border: none; border-radius: 8px; font-weight: bold; font-size: 13px; cursor: pointer; transition: background 0.15s, transform 0.1s; box-sizing: border-box; margin-top: 10px; }
        .btn-add:hover { background: #dc2626; }
        .btn-add:active { transform: scale(0.99); }
        
        /* СТИЛИ РЕЕСТРА ТИКЕТОВ */
        .table-scroll { max-height: 550px; overflow-y: auto; border: 1px solid #323248; border-radius: 12px; background: #1e1e2d; box-shadow: 0 10px 35px rgba(0,0,0,0.3); width: 100%; box-sizing: border-box; }
        .bug-table { width: 100%; border-collapse: separate; border-spacing: 0; text-align: left; font-size: 13px; table-layout: fixed; }
        .bug-table th { padding: 14px 12px; background: #242434; color: #92929f; text-transform: uppercase; font-size: 11px; font-weight: bold; border-bottom: 2px solid #323248; letter-spacing: 0.5px; position: sticky; top: 0; z-index: 5; }
        .bug-table td { padding: 14px 12px; border-bottom: 1px solid #2b2b40; color: #cbd5e1; vertical-align: top; box-sizing: border-box; word-break: break-word; }
        .bug-table tr:last-child td { border-bottom: none; }
        .bug-table tr:hover td { background: #222235; }
        
        .comment-input { width: 100%; padding: 8px 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 12px; box-sizing: border-box; transition: border-color 0.15s; }
        .comment-input:focus { border-color: #10b981; background: #191926; }
        
        /* ПОЛУПРОЗРАЧНЫЕ НЕОНОВЫЕ БЕЙДЖИ СТАТУСОВ */
        .badge { padding: 4px 10px; border-radius: 6px; font-size: 10px; font-weight: 800; display: inline-block; letter-spacing: 0.5px; text-transform: uppercase; }
        .badge-new { background: rgba(239, 68, 68, 0.12); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.25); }
        .badge-work { background: rgba(245, 158, 11, 0.12); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.25); }
        .badge-done { background: rgba(16, 185, 129, 0.12); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.25); }
    </style>
</head>
<body>

    <!-- ПОДКЛЮЧЕНИЕ САЙДБАРА -->
 
        <?php include 'sidebar.php'; ?>
  

    <!-- ОСНОВНОЙ КОНТЕНТ СТРАНИЦЫ -->
    <div class="main-content">
        <h1 style="margin: 0; font-size: 24px; font-weight: bold; letter-spacing: 0.3px;">🪲 Technical Bug Tracker</h1>
        
        <!-- ДАШБОРД СТАТИСТИКИ -->
        <div class="stat-container">
            <div class="stat-card"><div class="stat-label">Всего багов</div><div class="stat-val"><?= $bugStats['total'] ?></div></div>
            <div class="stat-card"><div class="stat-label" style="color: #ef4444;">🔴 Новые</div><div class="stat-val" style="color: #ef4444;"><?= $bugStats['new'] ?></div></div>
            <div class="stat-card"><div class="stat-label" style="color: #f59e0b;">🟡 В работе</div><div class="stat-val" style="color: #f59e0b;"><?= $bugStats['work'] ?></div></div>
            <div class="stat-card"><div class="stat-label" style="color: #10b981;">🟢 Исправлено</div><div class="stat-val" style="color: #10b981;"><?= $bugStats['done'] ?></div></div>
        </div>
         <?php if ($u_role === 'admin'): ?>
        <div style="background: #1e1e2d; padding: 20px; border-radius: 16px; border: 1px solid #323248; box-shadow: 0 10px 35px rgba(0,0,0,0.2); width: 100%; box-sizing: border-box;">
            <h3 style="margin: 0 0 15px 0; font-size: 15px; color: #818cf8; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold;">📢 Оповестить менеджеров об обновлении базы</h3>
            <form method="POST" action="suggestions.php" style="display: flex; flex-direction: column; gap: 12px; margin: 0; padding: 0;">
                <input type="hidden" name="action" value="publish_update">
                <input type="text" name="update_title" required placeholder="Заголовок (например: Исправлен баг со снятием контракта)" style="height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; box-sizing: border-box; width: 100%;">
                <textarea name="update_text" required placeholder="Текст сообщения (что изменилось, как пользоваться...)" style="height: 80px; padding: 10px 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; resize: none; font-family: sans-serif; box-sizing: border-box; width: 100%;"></textarea>
                <div style="display: flex; justify-content: flex-end;">
                    <button type="submit" style="height: 38px; padding: 0 25px; background: #4f46e5; border: none; color: #fff; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px; transition: 0.2s;">🚀 Запустить рассылку апдейта</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- УЛЬТРА-КОМПАКТНАЯ ФОРМА РЕГИСТРАЦИИ БАГОВ -->
        <div class="bug-card">
            <form id="bugReportForm" onsubmit="return sendBugReportDirectly(event, this);" enctype="multipart/form-data" style="margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px;">
                
                <label style="display:block; font-size:12px; color:#92929f; font-weight:bold;">Краткая суть сбоя:</label>
                <input type="text" name="bug_title" required placeholder="Напр: Ошибка дублирования вкладок при переключении..." class="bug-input">
                
                <label style="display:block; font-size:12px; color:#92929f; font-weight:bold;">Подробное описание (Сюда же можно нажать Ctrl + V для вставки скриншота):</label>
                <textarea name="bug_desc" id="bug_desc_textarea" required placeholder="Опишите детальнее суть проблемы... Для прикрепления скриншота просто нажмите Ctrl+V внутри этого поля" class="bug-textarea" style="border-color: #4f46e5;"></textarea>
                
                <!-- СКРЫТЫЙ ИНПУТ ДЛЯ ФАЙЛА (Служит контейнером в буфере JavaScript) -->
                <input type="file" id="bug_file_input" name="bug_screenshot" accept="image/*" style="display: none;">

                <!-- БЛОК ДИНАМИЧЕСКОГО ПРЕВЬЮ СКРИНШОТА -->
          <!-- КОНТЕЙНЕР ЗАГРУЗКИ СКРИНШОТОВ (ЧИСТЫЙ С КОРНЯ UI) -->
<div style="margin-bottom: 16px; text-align: left; width: 100%; box-sizing: border-box; font-family: sans-serif;">
    <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 8px;">
        Прикрепить скриншот ошибки:
    </label>
    
    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
        <!-- Стилизованная кнопка выбора файла -->
        <label for="bug_screenshot_input" style="cursor: pointer; padding: 10px 16px; background: #242434; border: 1px solid #323248; color: #818cf8; border-radius: 8px; font-size: 13px; font-weight: bold; transition: all 0.15s ease-in-out; display: inline-block;">
            📎 Выбрать изображение
        </label>
        <input type="file" id="bug_screenshot_input" name="screenshot" accept="image/*" onchange="handleBugScreenshotPreview(this)" style="display: none;">
        <script>
            // 1. Функция генерации живого превью при выборе файла
function handleBugScreenshotPreview(inputElement) {
    if (!inputElement || !inputElement.files || !inputElement.files[0]) return;

    const file = inputElement.files[0];
    
    // Проверяем, что это точно изображение
    if (!file.type.startsWith('image/')) {
        alert("Разрешено прикреплять только графические файлы (скриншоты)!");
        inputElement.value = ""; // Сбрасываем некорректный файл
        return;
    }

    const reader = new FileReader();
    reader.onload = function(e) {
        // Подставляем картинку и имя файла в наш VIP-блок превью
        document.getElementById('js-bug-preview-img').src = e.target.result;
        document.getElementById('js-bug-preview-name').innerText = file.name;
        
        // Плавно показываем блок превью на экране
        document.getElementById('js-bug-preview-wrapper').style.display = 'flex';
    };
    reader.readAsDataURL(file);
}

// 2. ФУНКЦИЯ ПОЛНОГО И НАМЕРТВЕННОГО ОЧИЩЕНИЯ СКРИНШОТА (ТОТ САМЫЙ КРЕСТИК)
function clearBugScreenshotInline() {
    console.log("=== ЗАПУСК ПРИНУДИТЕЛЬНОЙ ОЧИСТКИ БУФЕРА КАРТИНКИ ===");
    
    // Находим наш файловый инпут
    const fileInput = document.getElementById('bug_screenshot_input');
    
    if (fileInput) {
        // САМАЯ КРИТИЧЕСКАЯ СТРОКА: Полностью сносим файлы из памяти браузера!
        fileInput.value = ""; 
        console.log("Системный стек файлового инпута успешно обнулен.");
    }

    // Скрываем блок превью с экрана обратно в темноту
    const wrapper = document.getElementById('js-bug-preview-wrapper');
    if (wrapper) {
        wrapper.style.display = 'none';
    }
    
    // Вычищаем хвосты данных из тегов, чтобы не плодить фантомы в DOM
    document.getElementById('js-bug-preview-img').src = "";
    document.getElementById('js-bug-preview-name').innerText = "";
}
        </script>
        <!-- ДИНАМИЧЕСКИЙ ПУЛЬТ ПРЕВЬЮ КАРТИНКИ -->
        <div id="js-bug-preview-wrapper" style="display: none; align-items: center; background: #151521; border: 1px solid #323248; padding: 6px 10px; border-radius: 8px; gap: 10px; position: relative;">
            <img id="js-bug-preview-img" src="" alt="Превью" style="height: 32px; width: auto; border-radius: 4px; object-fit: cover;">
            <span id="js-bug-preview-name" style="font-size: 12px; color: #e4e4e7; font-family: monospace; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">file.png</span>
            
            <!-- КНОПКА УДАЛЕНИЯ (ИСПРАВЛЕНО НАМЕРТВО: тип button + стоп-триггер) -->
            <button type="button" 
                    onclick="clearBugScreenshotInline(); return false;" 
                    style="background: #ef444420; border: 1px solid #ef444440; color: #ef4444; width: 22px; height: 22px; border-radius: 6px; font-size: 14px; font-weight: bold; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 0; line-height: 1; transition: all 0.15s;"
                    onmouseover="this.style.background='#ef4444'; this.style.color='#fff';"
                    onmouseout="this.style.background='#ef444420'; this.style.color='#ef4444';">
                &times;
            </button>
        </div>
    </div>

                <div>
                    <button type="submit" class="btn-add">
                        🚨 Зарегистрировать технический баг
                    </button>
                </div>
            </form>
        </div>
         <h2 style="font-size: 18px; margin-top: 10px; margin-bottom: 5px; font-weight: bold; letter-spacing: 0.3px;">📋 Список зарегистрированных тикетов</h2>
        <div class="table-scroll">
            <table class="bug-table">
                <thead>
                    <tr>
                        <th style="width: 50px; text-align: center;">ID</th>
                        <th style="width: 140px; text-align: center;">Статус / Галка</th>
                        <th style="width: 220px;">Краткая суть</th>
                        <th>Детали сбоя и скриншот</th>
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
                                <!-- ID тикета -->
                                <td style="color: #64748b !important; text-align: center; font-family: monospace; font-weight: bold;">
                                    #<?= (int)$b['id'] ?>
                                </td>
                                
                                <!-- Статус с интерактивным управлением -->
                                <td style="text-align: center;">
                                    <?php if ($u_role === 'admin'): ?>
                                        <div style="display: flex; align-items: center; gap: 8px; justify-content: center;">
                                            <input type="checkbox" class="bug-checkbox" 
                                                   data-bug-id="<?= $b['id'] ?>" 
                                                   <?= $status === 2 ? 'checked' : '' ?>
                                                   onchange="toggleBugStatus(<?= $b['id'] ?>, this.checked);"
                                                   style="width: 16px; height: 16px; cursor: pointer; accent-color: #4f46e5;">
                                            <span id="badge_text_<?= $b['id'] ?>" class="badge <?= $badgeClass ?>" style="font-size: 10px; padding: 2px 6px;">
                                                <?= $rusStatus ?>
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge <?= $badgeClass ?>"><?= $rusStatus ?></span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Краткая суть -->
                                <td style="font-weight: bold; color: #ef4444 !important;">
                                    <?= htmlspecialchars($b['title']) ?>
                                </td>
                                
                                <!-- Детали сбоя с живой миниатюрой скриншота -->
                                <td style="vertical-align: top; line-height: 1.5;">
                                    <div style="color: #fff; font-size: 13px; font-weight: 500; white-space: pre-line; margin-bottom: 5px;">
                                        <?= nl2br(htmlspecialchars($b['description'])) ?>
                                    </div>
                                    
                                    <?php if (!empty($b['image_path'])): ?>
                                        <div style="border-top: 1px dashed #323248; margin-top: 10px; padding-top: 8px;">
                                            <span style="font-size: 10px; color: #818cf8; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 5px;">
                                                📸 Прикрепленный скриншот:
                                            </span>
                                            <a href="<?= htmlspecialchars($b['image_path']) ?>" target="_blank" title="Кликните, чтобы открыть в полном размере">
                                                <img src="<?= htmlspecialchars($b['image_path']) ?>" style="max-width: 180px; max-height: 100px; border-radius: 6px; border: 1px solid #323248; display: block; transition: 0.2s; cursor: pointer;" onmouseover="this.style.transform='scale(1.03)'; this.style.borderColor='#ef4444';" onmouseout="this.style.transform='none'; this.style.borderColor='#323248';">
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Ответ Админа -->
                                <td>
                                    <?php if ($u_role === 'admin'): ?>
                                        <input type="text" value="<?= htmlspecialchars($b['admin_comment'] ?? '') ?>" placeholder="Напишите ответ..." class="comment-input" onchange="saveBugReply(<?= $b['id'] ?>, this.value);">
                                    <?php else: ?>
                                        <span style="color: #10b981; font-size: 13px; font-weight: 500; display: block; line-height: 1.4;">
                                            <?= !empty($b['admin_comment']) ? '💬 ' . htmlspecialchars($b['admin_comment']) : '<span style="color:#64748b;">Ожидает проверки...</span>' ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Автор (Логин) -->
                                <td style="color: #a855f7 !important; font-weight: bold;">
                                    👤 <?= htmlspecialchars($b['login'] ?? 'Система') ?>
                                </td>
                                
                                <!-- Дата создания -->
                                <td style="color: #64748b !important; font-size: 13px; font-family: monospace;">
                                    <?= date('d.m.Y H:i', strtotime($b['created_at'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" style="text-align: center; color: #64748b !important; padding: 40px !important; font-size: 14px;">Журнал багов пока пуст.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div> <!-- Закрытие тега .main-content -->
     <script>
    // Глобальная ячейка памяти для хранения файла, вытащенного из буфера обмена
    window.pastedFile = null;

    document.addEventListener("DOMContentLoaded", function() {
        const textarea = document.getElementById('bug_desc_textarea');
        
        if (textarea) {
            // Перехватываем вставку скриншотов строго внутри текстового поля описания
            textarea.addEventListener('paste', function(e) {
                const items = (e.clipboardData || e.originalEvent?.clipboardData || window.clipboardData)?.items;
                if (!items) return;

                for (let index in items) {
                    const item = items[index];
                    // Ищем элемент, который является графическим файлом (изображением)
                    if (item.kind === 'file' && item.type.indexOf('image/') !== -1) {
                        // Жестко останавливаем браузер, чтобы двоичный мусор не напечатался в текст
                        e.preventDefault();
                        
                        const blob = item.getAsFile();
                        // Упаковываем блоб в системный файл для последующей FormData-отправки
                        window.pastedFile = new File([blob], "screenshot_pasted.png", { type: blob.type });
                        
                        // Читаем файл локально для мгновенной отрисовки превью
                        const reader = new FileReader();
                        reader.onload = function(event) {
                            const img = document.getElementById('screenshot_preview');
                            const container = document.getElementById('preview_container');
                            if (img && container) {
                                img.src = event.target.result;
                                container.style.display = 'block'; // Разворачиваем зеленую зону превью
                            }
                            // Подсвечиваем рамку текстового поля зеленым в знак успеха прикрепления
                            textarea.style.borderColor = '#10b981';
                        };
                        reader.readAsDataURL(blob);
                        break; // Забираем только первое изображение из буфера
                    }
                }
            });
        }
    });

    // Монолитная асинхронная отправка баг-репорта
    async function sendBugReportDirectly(event, formElement) {
        event.preventDefault();
        event.stopPropagation();
        
        console.log("Пакетный движок готовит отправку тикета со скриншотом из текстового поля...");
        
        try {
            const bugFormData = new FormData(formElement);
            
            // Жесткая синхронизация имен полей с PHP-бэкендом (поддержка обоих вариантов ключей)
            const titleVal = formElement.querySelector('[name="bug_title"]')?.value || '';
            const descVal = formElement.querySelector('[name="bug_desc"]')?.value || '';
            bugFormData.set('bug_title', titleVal);
            bugFormData.set('bug_desc', descVal);
            
            // Если в памяти лежит скриншот из буфера — принудительно инжектируем его в POST-пакет
            if (window.pastedFile) {
                bugFormData.set('bug_screenshot', window.pastedFile);
            }
            
            // Активируем маркер асинхронной обработки для PHP-шапки
            bugFormData.set('action_type', 'ajax_save_bug');
            
            const res = await fetch('bug_reports.php', {
                method: 'POST',
                body: bugFormData
            });
            
            const rawText = await res.text();
            console.log("Сырой ответ монолитного баг-трекера:", rawText);
            
            if (!rawText.trim().startsWith('{')) {
                alert("🚨 КРИТИЧЕСКИЙ СБОЙ БЭКЕНДА!\nСервер вернул HTML вместо JSON:\n\n" + rawText);
                return false;
            }
            
            const result = JSON.parse(rawText);
            if (result.status === 'success') {
                alert("🎉 Технический баг успешно зарегистрирован!");
                window.pastedFile = null; // Очищаем память буфера
                formElement.reset();
                
                // Сбрасываем стили превью
                document.getElementById('preview_container').style.display = 'none';
                document.getElementById('bug_desc_textarea').style.borderColor = '#323248';
                
                window.location.reload(); 
            } else {
                alert("⚠️ Не удалось сохранить тикет: " + result.message);
            }
        } catch (err) {
            console.error("Сбой отправки баг-репорта:", err);
            alert("🚨 Критическая ошибка JavaScript при обработке файла. Проверьте консоль F12.");
        }
        return false;
    }

    // Сохранение текстового ответа админа (Срабатывает мгновенно по утере фокуса onchange)
    async function saveBugReply(bugId, textValue) {
        console.log("Асинхронное сохранение отчета по исправлению для тикета #" + bugId);
        try {
            const res = await fetch('bug_reports.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    action: 'update_admin_comment', 
                    id: parseInt(bugId, 10), 
                    comment: textValue 
                })
            });
            const r = await res.json();
            if (r.status !== 'success') {
                alert("⚠️ Отказ СУБД при сохранении ответа: " + r.message);
            }
        } catch (err) {
            console.error(err);
            alert("🚨 Ошибка связи с сервером при отправке комментария.");
        }
    }

    // Мгновенное изменение числового статуса чекбоксом (0 - Новый, 2 - Исправлено)
    async function toggleBugStatus(bugId, isChecked) {
        const newStatus = isChecked ? 2 : 0;
        console.log("Запрос смены статуса тикета #" + bugId + " на числовое значение: " + newStatus);
        
        try {
            const res = await fetch('bug_reports.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    action: 'update_bug_status', 
                    id: parseInt(bugId, 10), 
                    status: newStatus 
                })
            });
            
            const r = await res.json();
            if (r.status === 'success') {
                // Налету перекрашиваем неоновый бейдж и меняем текст без перезапуска экрана
                const badge = document.getElementById('badge_text_' + bugId);
                if (badge) {
                    badge.innerText = isChecked ? 'Исправлено' : 'Новый';
                    badge.className = 'badge ' + (isChecked ? 'badge-done' : 'badge-new');
                }
                console.log("Статус тикета #" + bugId + " успешно зафиксирован в СУБД!");
            } else { 
                alert("⚠️ Ошибка смены статуса: " + r.message); 
                document.querySelector(`[data-bug-id="${bugId}"]`).checked = !isChecked; // Откатываем галку обратно
            }
        } catch (err) {
            console.error(err);
            alert("🚨 Ошибка транспорта JSON при смене статуса.");
            document.querySelector(`[data-bug-id="${bugId}"]`).checked = !isChecked;
        }
    }
    </script>
</body>
</html>