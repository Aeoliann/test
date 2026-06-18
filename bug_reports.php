<?php
// bug_reports.php — Монолитный контроллер CRM Santeks Premium (Часть 1)
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

// =========================================================================
// АСИНХРОННЫЙ ПЕРЕХВАТЧИК POST-ЗАПРОСОВ (AJAX И ФОРМЫ СУБД)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Включаем заголовок JSON для асинхронных ответов фронтенду
    header('Content-Type: application/json');
    if (ob_get_length()) ob_clean();

    $rawInput = json_decode(file_get_contents('php://input'), true) ?: [];
    
    // Сбор сквозных маркеров действия и системных ID из FormData и JSON потоков
    $action     = $_POST['action_mode'] ?? ($_POST['action'] ?? ($rawInput['action'] ?? ''));
    $b_id       = (int)($_POST['bug_id'] ?? ($_POST['id'] ?? ($rawInput['id'] ?? 0)));
    $comment    = trim($_POST['comment'] ?? ($rawInput['comment'] ?? ''));
    $new_status = isset($_POST['status']) ? (int)$_POST['status'] : (isset($rawInput['status']) ? (int)$rawInput['status'] : -1);

    try {        // -----------------------------------------------------------------
        // 1. ИНТЕРАКТИВНАЯ СМЕНА ЧИСЛОВОГО СТАТУСА ТИКЕТА АДМИНОМ
        // -----------------------------------------------------------------
        if ($action === 'update_bug_status') {
            if ($b_id <= 0 || $new_status < 0) { throw new Exception("Некорректные параметры статуса."); }
            $pdo->prepare("UPDATE bug_reports SET status = ? WHERE id = ?")->execute([$new_status, $b_id]);
            echo json_encode(["status" => "success"]); 
            exit;
        }

        // -----------------------------------------------------------------
        // 2. СОХРАНЕНИЕ ТЕКСТОВОГО ОТВЕТА / ОТЧЕТА АДМИНИСТРАТОРА
        // -----------------------------------------------------------------
        if ($action === 'update_admin_comment') {
            $pdo->prepare("UPDATE bug_reports SET admin_comment = ? WHERE id = ?")->execute([$comment, $b_id]);
            echo json_encode(["status" => "success"]); 
            exit;
        }

        // -----------------------------------------------------------------
        // 3. АСИНХРОННОЕ УДАЛЕНИЕ КАРТИНКИ ИЗ ХРАНИЛИЩА (ЧЕРЕЗ КРЕСТИК)
        // -----------------------------------------------------------------
        if ($action === 'delete_bug_screenshot_direct') {
            if ($b_id <= 0) { throw new Exception("Некорректный ID тикета."); }
            
            $getImg = $pdo->prepare("SELECT image_path FROM bug_reports WHERE id = ?");
            $getImg->execute([$b_id]);
            $oldPath = $getImg->fetchColumn();
            
            if (!empty($oldPath) && file_exists($oldPath)) { 
                @unlink($oldPath); 
            }
            
            $pdo->prepare("UPDATE bug_reports SET image_path = NULL WHERE id = ?")->execute([$b_id]);
            echo json_encode(['status' => 'success']); 
            exit;
        }
        // -----------------------------------------------------------------
        // 4. ПАКЕТНОЕ ИЗМЕНЕНИЕ СУТИ И ОПИСАНИЯ ИЗ МОДАЛКИ РЕДАКТИРОВАНИЯ
        // -----------------------------------------------------------------
        if ($action === 'update_bug_text_full_package') {
            $title       = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($b_id <= 0 || empty($title) || empty($description)) {
                throw new Exception("Все поля обязательны для заполнения.");
            }

            // Контроль прав: менеджер может править только свои репорты
            if ($u_role !== 'admin') {
                $check = $pdo->prepare("SELECT user_id FROM bug_reports WHERE id = ?");
                $check->execute([$b_id]);
                if ((int)$check->fetchColumn() !== $userId) {
                    throw new Exception("Доступ к изменению чужого репорта заблокирован.");
                }
            }

            // Обработка загрузки нового скриншота при редактировании
            $newImagePath = null;
            if (isset($_FILES['bug_screenshot']) && $_FILES['bug_screenshot']['error'] === UPLOAD_ERR_OK) {
                $fileExtension = strtolower(pathinfo($_FILES['bug_screenshot']['name'], PATHINFO_EXTENSION));
                if (!in_array($fileExtension, ['png', 'jpeg', 'jpg', 'webp'])) {
                    throw new Exception("Недопустимый формат файла изображения.");
                }
                if (!is_dir('./uploads/bugs/')) { mkdir('./uploads/bugs/', 0777, true); }
                $newImagePath = './uploads/bugs/bug_' . $b_id . '_' . time() . '.' . $fileExtension;
                
                if (move_uploaded_file($_FILES['bug_screenshot']['tmp_name'], $newImagePath)) {
                    $getOld = $pdo->prepare("SELECT image_path FROM bug_reports WHERE id = ?");
                    $getOld->execute([$b_id]);
                    $oldImg = $getOld->fetchColumn();
                    if (!empty($oldImg) && file_exists($oldImg)) { @unlink($oldImg); }
                }
            }

            if ($newImagePath !== null) {
                $pdo->prepare("UPDATE bug_reports SET title = ?, description = ?, image_path = ? WHERE id = ?")->execute([$title, $description, $newImagePath, $b_id]);
            } else {
                $pdo->prepare("UPDATE bug_reports SET title = ?, description = ? WHERE id = ?")->execute([$title, $description, $b_id]);
            }

            echo json_encode(['status' => 'success']); 
            exit;
        }
        // -----------------------------------------------------------------
        // 5. ПЕРВИЧНОЕ СОЗДАНИЕ НОВОГО БАГ-РЕПОРТА (УЛЬТРА-КОМПАКТНАЯ ФОРМА)
        // -----------------------------------------------------------------
        $isOldForm = isset($_POST['bug_title']);
        $isNewForm = ($action === 'create_new_bug_report_package');

        if ($isOldForm || $isNewForm) {
            $title  = trim($_POST['bug_title'] ?? ($_POST['title'] ?? ''));
            $desc   = trim($_POST['bug_desc'] ?? ($_POST['description'] ?? ''));
            $isAjax = (isset($_POST['action_type']) && $_POST['action_type'] === 'ajax_save_bug') || $isNewForm;
            $imagePath = null;

            if (empty($title) || empty($desc)) { 
                throw new Exception("Заполните поля сути и деталей ошибки!"); 
            }

            $pdo->beginTransaction();

            $sqlBug = "INSERT INTO bug_reports (title, description, user_id, status, created_at) VALUES (?, ?, ?, 0, NOW())";
            $pdo->prepare($sqlBug)->execute([$title, $desc, $userId]);
            $newBugId = (int)$pdo->lastInsertId();

            // Принимаем скриншот (поддерживает и обычный файл, и наш screenshot из FormData)
            $fileSource = $_FILES['bug_screenshot'] ?? ($_FILES['screenshot'] ?? null);
            if ($fileSource && $fileSource['error'] === UPLOAD_ERR_OK) {
                if (!is_dir('./uploads/bugs/')) { mkdir('./uploads/bugs/', 0777, true); }
                $fileTmpPath = $fileSource['tmp_name'];
                $fileName = $fileSource['name'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                
                $newFileName = 'bug_' . $newBugId . '_' . time() . '.' . $fileExtension;
                $dest_path = './uploads/bugs/' . $newFileName;

                if (move_uploaded_file($fileTmpPath, $dest_path)) { 
                    $imagePath = $dest_path; 
                    $pdo->prepare("UPDATE bug_reports SET image_path = ? WHERE id = ?")->execute([$imagePath, $newBugId]);
                }
            }

            if (function_exists('logAction')) {
                logAction($pdo, 'INSERT', 'bug_reports', $newBugId, "Зарегистрирован баг: '{$title}'");
            }

            $pdo->commit();

            if ($isAjax) {
                echo json_encode(['status' => 'success']); 
                exit;
            }
            header("Location: bug_reports.php"); 
            exit;
        }

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); 
        exit;
    }
}
// =========================================================================
// 2. СБОР СТАТИСТИКИ ДЛЯ ДАШБОРДА (БЕЗ ВАРНИНГОВ)
// =========================================================================
$bug_reportstats = ['total' => 0, 'new' => 0, 'work' => 0, 'done' => 0];
try {
    $statsData = $pdo->query("SELECT COUNT(*) as total, 
                                     SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as `new`, 
                                     SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as `work`, 
                                     SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) as `done` 
                              FROM bug_reports")->fetch();
    if ($statsData) {
        $bug_reportstats = [
            'total' => (int)$statsData['total'], 
            'new'   => (int)$statsData['new'], 
            'work'  => (int)$statsData['work'], 
            'done'  => (int)$statsData['done']
        ];
    }
} catch (Exception $e) { }

// =========================================================================
// 3. ВЫБОРКА СТРОК ДЛЯ ВЕРСТКИ ТАБЛИЦЫ ЖУРНАЛА БАГОВ
// =========================================================================
$bug_reports = [];
try {
    $bug_reports = $pdo->query("SELECT b.*, u.login 
                                FROM bug_reports b 
                                LEFT JOIN users u ON b.user_id = u.id 
                                ORDER BY b.id DESC")->fetchAll();
} catch (Exception $e) { }
?>
<?php include "sidebar.php";?>
<!-- ОСНОВНОЙ КОНТЕНТ СТРАНИЦЫ -->
<div class="main-content">
    <h1 style="margin: 0; font-size: 24px; font-weight: bold; letter-spacing: 0.3px;">🪲 Technical Bug Tracker</h1>
    
    <!-- ДАШБОРД СТАТИСТИКИ -->
    <div class="stat-container">
        <div class="stat-card">
            <div class="stat-label">Всего багов</div>
            <div class="stat-val"><?= (int)($bug_reportstats['total'] ?? 0) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label" style="color: #ef4444;">🔴 Новые</div>
            <div class="stat-val" style="color: #ef4444;"><?= (int)($bug_reportstats['new'] ?? 0) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label" style="color: #f59e0b;">🟡 В работе</div>
            <div class="stat-val" style="color: #f59e0b;"><?= (int)($bug_reportstats['work'] ?? 0) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label" style="color: #10b981;">🟢 Исправлено</div>
            <div class="stat-val" style="color: #10b981;"><?= (int)($bug_reportstats['done'] ?? 0) ?></div>
        </div>
    </div>
        <?php if ($u_role === 'admin'): ?>
    <!-- ПАНЕЛЬ ОПОВЕЩЕНИЙ ДЛЯ АДМИНИСТРАТОРА -->
    <div style="background: #1e1e2d; padding: 20px; border-radius: 16px; border: 1px solid #323248; box-shadow: 0 10px 35px rgba(0,0,0,0.2); width: 100%; box-sizing: border-box; margin-bottom: 20px;">
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
    <div class="bug-card" style="background: #1e1e2d; padding: 20px; border-radius: 16px; border: 1px solid #323248; box-shadow: 0 10px 35px rgba(0,0,0,0.2); width: 100%; box-sizing: border-box; margin-bottom: 20px;">
        <form id="bugReportForm" onsubmit="return sendBugReportDirectly(event, this); return false;" enctype="multipart/form-data" style="margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px;">
            <!-- ЖЕЛЕЗНЫЙ МАРКЕР ДЛЯ БЭКЕНДА -->
            <input type="hidden" name="action_mode" value="create_new_bug_report_package">

            <label style="display:block; font-size:12px; color:#92929f; font-weight:bold;">Краткая суть сбоя:</label>
            <input type="text" name="bug_title" required placeholder="Напр: Ошибка дублирования вкладок при переключении..." class="bug-input">
            
            <label style="display:block; font-size:12px; color:#92929f; font-weight:bold;">Подробное описание (Сюда же можно нажать Ctrl + V для вставки скриншота):</label>
            <textarea name="bug_desc" id="bug_desc_textarea" required placeholder="Опишите детальнее суть проблемы... Для прикрепления скриншота просто нажмите Ctrl+V внутри этого поля" class="bug-textarea" style="border-color: #4f46e5;"></textarea>
<!-- СКРЫТЫЙ ИНПУТ ДЛЯ ФАЙЛА (Служит контейнером в буфере JavaScript) -->
            <input type="file" id="bug_screenshot_input" name="bug_screenshot" accept="image/*" onchange="handlebug_reportscreenshotPreview(this)" style="display: none;">

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
                    
                    <!-- ДИНАМИЧЕСКИЙ ПУЛЬТ ПРЕВЬЮ КАРТИНКИ (ОБЪЕДИНЕННЫЙ UX) -->
                    <div id="js-bug-preview-wrapper" style="display: none; align-items: center; background: #151521; border: 1px solid #323248; padding: 6px 10px; border-radius: 8px; gap: 10px; position: relative;">
                        <img id="js-bug-preview-img" src="" alt="Превью" style="height: 32px; width: auto; border-radius: 4px; object-fit: cover;">
                        <span id="js-bug-preview-name" style="font-size: 12px; color: #e4e4e7; font-family: monospace; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">file.png</span>
                        
                        <!-- КНОПКА УДАЛЕНИЯ -->
                        <button type="button" 
                                onclick="clearbug_reportscreenshotInline(); return false;" 
                                style="background: #ef444420; border: 1px solid #ef444440; color: #ef4444; width: 22px; height: 22px; border-radius: 6px; font-size: 14px; font-weight: bold; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 0; line-height: 1; transition: all 0.15s;"
                                onmouseover="this.style.background='#ef4444'; this.style.color='#fff';"
                                onmouseout="this.style.background='#ef444420'; this.style.color='#ef4444';">
                            &times;
                        </button>
                    </div>
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
                <?php 
                // НАМЕРТВО ИСПРАВЛЕНО: Полная синхронизация массивов данных для обхода Fatal Error
                $finalBugsArray = !empty($bug_reports) ? $bug_reports : (!empty($bugs) ? $bugs : []);
                
                if (is_array($finalBugsArray) && count($finalBugsArray) > 0): 
                    foreach ($finalBugsArray as $b): 
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
                            <td style="text-align: center; vertical-align: middle;">
                                <?php if ($u_role === 'admin'): ?>
                                    <div style="display: flex; align-items: center; gap: 8px; justify-content: center;">
                                        <input type="checkbox" class="bug-checkbox" 
                                               data-bug-id="<?= (int)$b['id'] ?>" 
                                               <?= $status === 2 ? 'checked' : '' ?>
                                               onchange="togglebug_reportstatus(<?= (int)$b['id'] ?>, this.checked);"
                                               style="width: 16px; height: 16px; cursor: pointer; accent-color: #4f46e5;">
                                        <span id="badge_text_<?= (int)$b['id'] ?>" class="badge <?= $badgeClass ?>" style="font-size: 10px; padding: 2px 6px;">
                                            <?= $rusStatus ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <span class="badge <?= $badgeClass ?>"><?= $rusStatus ?></span>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Краткая суть (НАМЕРТВО ИСПРАВЛЕНО: Клик открывает модалку правок) -->
                            <td style="vertical-align: top; padding: 14px 10px;">
                                <?php 
                                $isAuthor = ((int)($b['user_id'] ?? 0) === $userId);
                                $canEdit = ($u_role === 'admin' || $isAuthor);
                                ?>
                                <div <?= $canEdit ? 'onclick="openEditBugModal(' . (int)$b['id'] . ', this); return false;" title="Кликните, чтобы отредактировать"' : '' ?>
                                     class="js-bug-title-text-<?= (int)$b['id'] ?>"
                                     style="font-weight: bold; color: #ef4444 !important; <?= $canEdit ? 'cursor: pointer; text-decoration: underline dashed rgba(239,68,68,0.4);' : '' ?> transition: all 0.15s; padding: 2px 4px;">
                                    <?= htmlspecialchars($b['title'] ?? '') ?>
                                </div>
                            </td>
                            
                            <!-- Детали сбоя и скриншот (НАМЕРТВО ИСПРАВЛЕНО: Клик открывает модалку правок) -->
                            <td style="vertical-align: top; line-height: 1.5; padding: 14px 10px;">
                                <div <?= $canEdit ? 'onclick="openEditBugModal(' . (int)$b['id'] . ', this); return false;" title="Кликните, чтобы отредактировать"' : '' ?>
                                     class="js-bug-desc-text-<?= (int)$b['id'] ?>"
                                     style="color: #fff; font-size: 13px; font-weight: 500; white-space: pre-line; <?= $canEdit ? 'cursor: pointer;' : '' ?> padding: 4px; border-radius: 4px;">
                                    <?= htmlspecialchars($b['description'] ?? '') ?>
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
                                    <input type="text" value="<?= htmlspecialchars($b['admin_comment'] ?? '') ?>" placeholder="Напишите ответ..." class="comment-input" onchange="saveBugReply(<?= (int)$b['id'] ?>, this.value);">
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
<!-- =========================================================================
     4. НАДЕЖНАЯ МОДАЛКА РЕДАКТИРОВАНИЯ (ТЕКСТ + ЗАГРУЗКА/УДАЛЕНИЕ СКРИНОВ)
     ========================================================================= -->
<div id="js-edit-bug-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); justify-content: center; align-items: center; z-index: 99999; box-sizing: border-box; padding: 15px;">
    <div style="background: #1e1e2d; border-radius: 12px; border: 1px solid #323248; padding: 24px; width: 500px; box-sizing: border-box; box-shadow: 0 10px 30px rgba(0,0,0,0.5); font-family: sans-serif;">
        
        <h3 style="margin-top: 0; color: #fff; font-size: 15px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #323248; padding-bottom: 12px; margin-bottom: 20px; text-align: left;">
            ✏️ Редактирование баг-репорта <span id="js-modal-bug-id-title" style="color: #64748b;">#--</span>
        </h3>
        
        <form onsubmit="submitBugEditFormDirectly(event); return false;" style="margin: 0; padding: 0; display: flex; flex-direction: column; gap: 15px;">
            <!-- Скрытый ID текущего тикета -->
            <input type="hidden" id="js-edit-bug-id-storage" value="0">

            <!-- Поле: Краткая суть -->
            <div style="display: flex; flex-direction: column; gap: 5px; text-align: left;">
                <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3px;">Краткая суть ошибки:</label>
                <input type="text" id="js-edit-bug-title-input" required style="width: 100%; height: 40px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; font-size: 13px; font-weight: bold; box-sizing: border-box;">
            </div>

            <!-- Поле: Детали сбоя -->
            <div style="display: flex; flex-direction: column; gap: 5px; text-align: left;">
                <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3px;">Детали сбоя и шаги воспроизведения:</label>
                <textarea id="js-edit-bug-desc-input" required style="width: 100%; height: 140px; padding: 10px 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; font-size: 13px; line-height: 1.5; resize: none; box-sizing: border-box;"></textarea>
            </div>

            <!-- БЛОК СНИМКОВ ЭКРАНА В МОДАЛКЕ -->
            <div style="display: flex; flex-direction: column; gap: 5px; text-align: left; border-top: 1px dashed #323248; padding-top: 12px; box-sizing: border-box;">
                <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3px;">Снимок экрана (скриншот):</label>
                
                <!-- Блок А: Если скриншот уже загружен -->
                <div id="js-edit-bug-img-preview-wrap" style="display: none; align-items: center; gap: 15px; background: #151521; padding: 10px; border-radius: 8px; border: 1px solid #323248;">
                    <img id="js-edit-bug-img-preview" src="" style="max-width: 120px; max-height: 80px; border-radius: 6px; border: 1px solid #323248; background: #000;">
                    <div style="display: flex; flex-direction: column; gap: 6px;">
                        <span style="font-size: 11px; color: #10b981; font-weight: bold;">📸 Файл прикреплен</span>
                        <button type="button" onclick="deletebug_reportscreenshotInline();" style="background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.3); color: #ef4444; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 800; cursor: pointer; transition: all 0.15s;" onmouseover="this.style.background='#ef4444'; this.style.color='#fff';" onmouseout="this.style.background='rgba(239,68,68,0.15)'; this.style.color='#ef4444';">
                            ❌ 删除 скриншот
                        </button>
                    </div>
                </div>

                <!-- Блок Б: Если скриншота нет — поле выбора нового файла -->
                <div id="js-edit-bug-input-file-wrap" style="display: flex; width: 100%; box-sizing: border-box;">
                    <input type="file" id="js-edit-bug-file-input" accept=".png, .jpeg, .jpg, .webp" style="width: 100%; font-size: 13px; color: #92929f; background: #151521; border: 1px solid #323248; padding: 8px 10px; border-radius: 8px; outline: none; cursor: pointer; box-sizing: border-box;">
                </div>
            </div>

            <!-- Подвал формы с кнопками управления -->
            <div style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #323248; padding-top: 15px; margin-top: 5px;">
                <button type="button" onclick="closeEditBugModal();" style="height: 38px; padding: 0 16px; background: #242434; border: 1px solid #323248; color: #92929f; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px;">Отмена</button>
                <button type="submit" style="height: 38px; padding: 0 20px; background: #10b981; border: none; color: #fff; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px; transition: background 0.15s;" onmouseover="this.style.background='#059669';" onmouseout="this.style.background='#10b981';">
                    💾 Сохранить изменения
                </button>
            </div>
        </form>
    </div>
</div>
<!-- =========================================================================
     5. АСИНХРОННЫЙ JS-ДВИЖЕК ВАЛИДАЦИИ И AJAX-ОБМЕНА С СУБД
     ========================================================================= -->
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
                    
                    // Читаем файл локально для мгновенной отрисовки превью в нашем общем VIP-окне
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        document.getElementById('js-bug-preview-img').src = event.target.result;
                        document.getElementById('js-bug-preview-name').innerText = "screenshot_pasted.png";
                        
                        // Плавно разворачиваем зону превью
                        document.getElementById('js-bug-preview-wrapper').style.display = 'flex';
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
// Функция генерации живого превью при ручном выборе файла через кнопку
function handlebug_reportscreenshotPreview(inputElement) {
    if (!inputElement || !inputElement.files || !inputElement.files[0]) return;

    const file = inputElement.files[0];
    
    // Проверяем, что это точно изображение
    if (!file.type.startsWith('image/')) {
        alert("Разрешено прикреплять только графические файлы (скриншоты)!");
        inputElement.value = ""; // Сбрасываем некорректный файл
        return;
    }

    // Сбрасываем вставку из буфера Ctrl+V, так как юзер выбрал файл вручную
    window.pastedFile = null;

    const reader = new FileReader();
    reader.onload = function(e) {
        // Подставляем картинку и имя файла в наш VIP-блок превью
        document.getElementById('js-bug-preview-img').src = e.target.result;
        document.getElementById('js-bug-preview-name').innerText = file.name;
        
        // Показываем блок превью на экране
        document.getElementById('js-bug-preview-wrapper').style.display = 'flex';
    };
    reader.readAsDataURL(file);
}

// Функция полного и намертвоочищения скриншота (тот самый крестик удаления в форме)
function clearbug_reportscreenshotInline() {
    console.log("=== ЗАПУСК ПРИНУДИТЕЛЬНОЙ ОЧИСТКИ БУФЕРА КАРТИНКИ ===");
    
    // Обнуляем ручной инпут файла
    const fileInput = document.getElementById('bug_screenshot_input');
    if (fileInput) { fileInput.value = ""; }

    // Обнуляем глобальную ячейку памяти Ctrl+V вставки
    window.pastedFile = null;

    // Скрываем блок превью с экрана обратно в темноту
    const wrapper = document.getElementById('js-bug-preview-wrapper');
    if (wrapper) { wrapper.style.display = 'none'; }
    
    // Вычищаем хвосты данных из тегов, чтобы не плодить фантомы в DOM
    document.getElementById('js-bug-preview-img').src = "";
    document.getElementById('js-bug-preview-name').innerText = "";
    
    // Возвращаем рамке дефолтный цвет
    const textarea = document.getElementById('bug_desc_textarea');
    if (textarea) { textarea.style.borderColor = '#323248'; }
}
// Асинхронное добавление нового бага из формы создания (с поддержкой буфера Ctrl + V)
async function sendBugReportDirectly(event, formElement) {
    if (event) event.preventDefault();
    if (!formElement) return false;

    console.log("=== ЗАПУСК ДОБАВЛЕНИЯ НОВОГО БАГ-РЕПОРТА ===");

    // Автоматически собираем все текстовые инпуты из формы
    const fd = new FormData(formElement);
    
    // Проверяем: если картинка прилетела из буфера обмена Ctrl+V
    if (window.pastedFile) {
        fd.delete('bug_screenshot'); // Убираем пустой текстовый ключ
        fd.append('bug_screenshot', window.pastedFile); // Вживляем бинарный снимок из памяти
        console.log("В пакет FormData успешно интегрирован скриншот из буфера Ctrl+V.");
    } else {
        // Если картинка выбрана стандартным кликом по кнопке
        const fileInput = document.getElementById('bug_screenshot_input');
        if (fileInput && fileInput.files.length > 0) {
            fd.delete('bug_screenshot');
            fd.append('bug_screenshot', fileInput.files[0]); // Пишем первый чистый бинарник
        }
    }

    try {
        // Отправляем POST-пакет строго на чистый файл без GET-параметров в URL
        const res = await fetch('bug_reports.php', { method: 'POST', body: fd });
        const result = await res.json();

        if (result.status === 'success') {
            console.log("Репорт успешно записан в СУБД MariaDB.");
            formElement.reset(); // Сбрасываем поля
            clearbug_reportscreenshotInline(); // Вычищаем остатки превью
            window.location.reload(); // Перерисовываем страницу
        } else {
            alert("Ошибка сохранения баг-репорта: " + result.message);
        }
    } catch (err) {
        alert("🚨 Критическая ошибка JavaScript при обработке файла. Проверьте консоль F12.");
        console.error("Детали сбоя отправки:", err);
    }
    return false;
}

// Открытие окна редактирования баг-репорта и автозаполнение полей
function openEditBugModal(bugId, clickedElement) {
    const safeId = parseInt(bugId, 10);
    if (isNaN(safeId) || safeId <= 0) return;

    const row = clickedElement.closest('tr');
    const currentTitle = row.querySelector('.js-bug-title-text-' + safeId).innerText.trim();
    const currentDesc = row.querySelector('.js-bug-desc-text-' + safeId).innerText.trim();
    
    // Вытаскиваем старую миниатюру скриншота, если она есть
    const imgEl = row.querySelector('img');
    const imgPath = imgEl ? imgEl.getAttribute('src') : '';

    document.getElementById('js-edit-bug-id-storage').value = safeId;
    document.getElementById('js-modal-bug-id-title').innerText = '#' + safeId;
    document.getElementById('js-edit-bug-title-input').value = currentTitle;
    document.getElementById('js-edit-bug-desc-input').value = currentDesc;
    document.getElementById('js-edit-bug-file-input').value = ''; // Сброс инпута

    const previewWrap = document.getElementById('js-edit-bug-img-preview-wrap');
    const inputWrap = document.getElementById('js-edit-bug-input-file-wrap');

    if (imgPath && imgPath !== '') {
        document.getElementById('js-edit-bug-img-preview').src = imgPath;
        previewWrap.style.display = 'flex';
        inputWrap.style.display = 'none';
    } else {
        previewWrap.style.display = 'none';
        inputWrap.style.display = 'flex';
    }

    document.getElementById('js-edit-bug-modal').style.display = 'flex';
}

function closeEditBugModal() {
    document.getElementById('js-edit-bug-modal').style.display = 'none';
}
// Асинхронное пакетное сохранение правок из модалки редактирования
async function submitBugEditFormDirectly(event) {
    if (event) event.preventDefault();

    const bugId = document.getElementById('js-edit-bug-id-storage').value;
    const newTitle = document.getElementById('js-edit-bug-title-input').value.trim();
    const newDesc = document.getElementById('js-edit-bug-desc-input').value.trim();
    const fileInput = document.getElementById('js-edit-bug-file-input');

    const fd = new FormData();
    fd.append('action_mode', 'update_bug_text_full_package');
    fd.append('bug_id', bugId);
    fd.append('title', newTitle);
    fd.append('description', newDesc);
    
    if (fileInput && fileInput.files.length > 0) {
        fd.append('bug_screenshot', fileInput.files[0]); // Строго первый бинарник
    }

    try {
        const res = await fetch('bug_reports.php', { method: 'POST', body: fd });
        const result = await res.json();

        if (result.status === 'success') {
            closeEditBugModal();
            window.location.reload();
        } else {
            alert("Ошибка сохранения изменений: " + result.message);
        }
    } catch (err) {
        alert("🚨 Критическая ошибка JavaScript при изменении тикета.");
        console.error(err);
    }
}

// Асинхронное удаление скриншота из модалки правок через крестик
async function deletebug_reportscreenshotInline() {
    const bugId = document.getElementById('js-edit-bug-id-storage').value;
    if (!confirm("Вы действительно хотите удалить прикрепленный скриншот из базы данных?")) return;

    const fd = new FormData();
    fd.append('action_mode', 'delete_bug_screenshot_direct');
    fd.append('bug_id', bugId);

    try {
        const res = await fetch('bug_reports.php', { method: 'POST', body: fd });
        const result = await res.json();

        if (result.status === 'success') {
            document.getElementById('js-edit-bug-img-preview-wrap').style.display = 'none';
            document.getElementById('js-edit-bug-input-file-wrap').style.display = 'flex';
            document.getElementById('js-edit-bug-file-input').value = '';
            console.log(`Скриншот тикета #${bugId} успешно удален.`);
        } else {
            alert("Ошибка СУБД при удалении файла: " + result.message);
        }
    } catch (err) {
        alert("Ошибка сети транспорта данных.");
    }
}

// Сохранение числового ответа админа по утере фокуса (onchange)
async function saveBugReply(bugId, textValue) {
    console.log("Асинхронное сохранение комментария админа для #" + bugId);
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
            alert("⚠️ Сбой СУБД: " + r.message);
        }
    } catch (err) {
        console.error(err);
        alert("🚨 Ошибка связи с сервером при отправке комментария.");
    }
}

// Переключение числового статуса чекбоксом админа (0 - Новый, 2 - Исправлено)
async function togglebug_reportstatus(bugId, isChecked) {
    const newStatus = isChecked ? 2 : 0;
    console.log("Запрос смены статуса тикета #" + bugId + " на значение: " + newStatus);
    
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
            const badge = document.getElementById('badge_text_' + bugId);
            if (badge) {
                badge.innerText = isChecked ? 'Исправлено' : 'Новый';
                badge.className = 'badge ' + (isChecked ? 'badge-done' : 'badge-new');
            }
            console.log("Статус тикета #" + bugId + " успешно обновлен.");
        } else { 
            alert("⚠️ Ошибка смены статуса: " + r.message); 
            document.querySelector(`[data-bug-id="${bugId}"]`).checked = !isChecked;
        }
    } catch (err) {
        console.error(err);
        alert("🚨 Ошибка транспорта JSON при смене статуса.");
        document.querySelector(`[data-bug-id="${bugId}"]`).checked = !isChecked;
    }
}
</script>
<style>
    /* Намертво выпрямляем флекс-сетку всей страницы */
    body {
        background-color: #151521 !important;
        color: #ffffff !important;
        font-family: 'Segoe UI', Roboto, sans-serif !important;
        display: flex !important;
        margin: 0 !important;
        padding: 0 !important;
        min-height: 100vh !important;
        box-sizing: border-box !important;
    }
    
    /* Контейнер основного контента (справа от сайдбара) */
    .main-content {
        flex: 1 !important;
        padding: 30px !important;
        background: #151521 !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 24px !important;
        min-width: 0 !important;
        box-sizing: border-box !important;
    }

 .stat-container {
    display: flex !important;
    flex-direction: row !important;
    gap: 20px !important;
    width: 100% !important;
    box-sizing: border-box !important;
    margin-bottom: 10px !important;
}

.stat-card {
    background: linear-gradient(145deg, #1e1e2d, #141422) !important; /* Дорогой градиент */
    border: 1px solid #2b2b40 !important;
    border-radius: 14px !important;
    padding: 20px 24px !important;
    flex: 1 !important;
    min-width: 0 !important;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.05) !important; /* Двойная тень для объема */
    box-sizing: border-box !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 8px !important;
    position: relative !important;
    overflow: hidden !important;
    transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1), border-color 0.2s, box-shadow 0.2s !important;
}

/* Элементы внутри карточек */
.stat-label {
    font-size: 11px !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.8px !important;
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
}
.stat-val {
    font-size: 32px !important; /* Увеличили цифры для солидности */
    font-weight: 800 !important;
    font-family: monospace !important;
    line-height: 1 !important;
    margin-top: 4px !important;
}

/* Индивидуальная неоновая подсветка границ при наведении для каждой карточки */
.stat-card:nth-child(1):hover {
    border-color: #818cf8 !important;
    box-shadow: 0 12px 35px rgba(129, 140, 248, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.05) !important;
    transform: translateY(-3px) !important;
}
.stat-card:nth-child(2):hover {
    border-color: #ef4444 !important;
    box-shadow: 0 12px 35px rgba(239, 68, 68, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.05) !important;
    transform: translateY(-3px) !important;
}
.stat-card:nth-child(3):hover {
    border-color: #f59e0b !important;
    box-shadow: 0 12px 35px rgba(245, 158, 11, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.05) !important;
    transform: translateY(-3px) !important;
}
.stat-card:nth-child(4):hover {
    border-color: #10b981 !important;
    box-shadow: 0 12px 35px rgba(16, 185, 129, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.05) !important;
    transform: translateY(-3px) !important;
}
    /* Прокачка полей ввода и текстовых зон */
    .bug-input, .bug-textarea, .comment-input, input[type="text"], textarea {
        width: 100% !important;
        background: #151521 !important;
        border: 1px solid #323248 !important;
        color: #ffffff !important;
        border-radius: 8px !important;
        padding: 10px 14px !important;
        font-size: 13px !important;
        outline: none !important;
        transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out !important;
        box-sizing: border-box !important;
    }
    .bug-input:focus, .bug-textarea:focus, .comment-input:focus, input[type="text"]:focus, textarea:focus {
        border-color: #4f46e5 !important;
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15) !important;
    }

    /* Идеальное выравнивание и неоновый вид таблицы */
    .bug-table {
        width: 100% !important;
        border-collapse: collapse !important;
        background: #1e1e2d !important;
    }
    .bug-table th {
        background: #1a1a29 !important;
        color: #92929f !important;
        font-size: 11px !important;
        font-weight: 700 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.5px !important;
        border-bottom: 2px solid #323248 !important;
        padding: 14px 12px !important;
    }
    .bug-checkbox {
    -webkit-appearance: none !important;
    -moz-appearance: none !important;
    appearance: none !important;
    width: 18px !important;
    height: 18px !important;
    border: 2px solid #323248 !important;
    border-radius: 5px !important;
    background: #151521 !important;
    cursor: pointer !important;
    position: relative !important;
    outline: none !important;
    transition: all 0.15s ease-in-out !important;
    vertical-align: middle !important;
}
.bug-checkbox:hover {
    border-color: #4f46e5 !important;
    box-shadow: 0 0 8px rgba(79, 70, 229, 0.4) !important;
}
.bug-checkbox:checked {
    background: #10b981 !important; /* Изумрудный неон при выполнении */
    border-color: #10b981 !important;
    box-shadow: 0 0 10px rgba(16, 185, 129, 0.4) !important;
}
.bug-checkbox:checked::after {
    content: '✓' !important;
    position: absolute !important;
    color: #fff !important;
    font-size: 13px !important;
    font-weight: 900 !important;
    top: 50% !important;
    left: 50% !important;
    transform: translate(-50%, -50%) !important;
}

/* 2. Кастомизация всех главных интерактивных кнопок (Красные, Синие, Зеленые) */
.btn-add, button[type="submit"], button[style*="background: #4f46e5"], .btn-contract-save {
    height: 40px !important;
    padding: 0 24px !important;
    border: 1px solid transparent !important;
    border-radius: 8px !important;
    font-weight: 700 !important;
    font-size: 13px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    cursor: pointer !important;
    transition: all 0.15s ease-in-out !important;
    box-sizing: border-box !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
}

/* Красная кнопка (Отправка багов) */
.btn-add {
    background: rgba(239, 68, 68, 0.15) !important;
    border: 1px solid rgba(239, 68, 68, 0.3) !important;
    color: #ef4444 !important;
    width: auto !important; /* Убираем растягивание на весь экран, делаем аккуратной */
    align-self: flex-start !important;
}
.btn-add:hover {
    background: #ef4444 !important;
    color: #fff !important;
    box-shadow: 0 0 15px rgba(239, 68, 68, 0.4) !important;
    transform: translateY(-1px) !important;
}

/* Фиолетовая кнопка (Рассылка обновлений) */
button[style*="background: #4f46e5"] {
    background: rgba(79, 70, 229, 0.15) !important;
    border: 1px solid rgba(79, 70, 229, 0.3) !important;
    color: #818cf8 !important;
}
button[style*="background: #4f46e5"]:hover {
    background: #4f46e5 !important;
    color: #fff !important;
    box-shadow: 0 0 15px rgba(79, 70, 229, 0.4) !important;
    transform: translateY(-1px) !important;
}

/* Зеленая кнопка (Сохранить в модалке) */
button[style*="background: #10b981"] {
    background: rgba(16, 185, 129, 0.15) !important;
    border: 1px solid rgba(16, 185, 129, 0.3) !important;
    color: #10b981 !important;
}
button[style*="background: #10b981"]:hover {
    background: #10b981 !important;
    color: #fff !important;
    box-shadow: 0 0 15px rgba(16, 185, 129, 0.4) !important;
    transform: translateY(-1px) !important;
}

/* Кнопка отмены */
button[onclick*="closeEditBugModal"] {
    height: 38px !important;
    background: #242434 !important;
    border: 1px solid #323248 !important;
    color: #92929f !important;
    border-radius: 8px !important;
    font-weight: bold !important;
    cursor: pointer !important;
    transition: all 0.15s !important;
}
button[onclick*="closeEditBugModal"]:hover {
    background: #2d2d3f !important;
    color: #fff !important;
}
   .bug-table td {
    border-bottom: 1px solid #2b2b40 !important;
    padding: 14px 12px !important;
    color: #cbd5e1 !important;
    font-size: 13px !important;
    vertical-align: middle !important; /* Убирает прилипание к верху */
}
    .bug-table tr:hover td {
        background: rgba(255, 255, 255, 0.02) !important;
    }

.bug-table td .badge {
    margin-left: 8px !important; /* Раздвигаем галку и текст статуса */
    padding: 4px 10px !important;
    border-radius: 6px !important;
    font-size: 10px !important;
    font-weight: 800 !important;
    display: inline-block !important;
    letter-spacing: 0.5px !important;
    text-transform: uppercase !important;
    vertical-align: middle !important;
    line-height: 1 !important;
}

/* Возвращаем премиальные полупрозрачные неоновые заливки */
.badge-new { 
    background: rgba(239, 68, 68, 0.12) !important; 
    color: #ef4444 !important; 
    border: 1px solid rgba(239, 68, 68, 0.25) !important; 
}
.badge-work { 
    background: rgba(245, 158, 11, 0.12) !important; 
    color: #f59e0b !important; 
    border: 1px solid rgba(245, 158, 11, 0.25) !important; 
}
.badge-done { 
    background: rgba(16, 185, 129, 0.12) !important; 
    color: #10b981 !important; 
    border: 1px solid rgba(16, 185, 129, 0.25) !important; 
}
</style>
</div> <!-- Закрытие общего контейнера, если он открывался во внешних файлах -->
</body>
</html>