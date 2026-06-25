<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Сбор сквозных маркеров действия и системных ID из FormData и JSON потоков
    $rawInput = json_decode(file_get_contents('php://input'), true) ?: [];
    
    $action     = $_POST['action_mode'] ?? ($_POST['action'] ?? ($rawInput['action'] ?? ''));
    $b_id       = (int)($_POST['bug_id'] ?? ($_POST['id'] ?? ($rawInput['id'] ?? 0)));
    $comment    = trim($_POST['comment'] ?? ($rawInput['comment'] ?? ''));
    $new_status = isset($_POST['status']) ? (int)$_POST['status'] : (isset($rawInput['status']) ? (int)$rawInput['status'] : -1);

    // =========================================================================
    // ЭКШЕН 1: МГНОВЕННЫЙ AJAX ПЕРЕКЛЮЧАТЕЛЬ ЧЕКБОКСА СТАТУСА
    // =========================================================================
    if ($action === 'toggle_status' && $b_id > 0 && $new_status !== -1) {
        header('Content-Type: application/json');
        if (ob_get_length()) ob_clean();
        try {
            $update_stmt = $pdo->prepare("UPDATE bug_reports SET status = :status WHERE id = :id");
            $update_stmt->execute([
                ':status' => $new_status,
                ':id'     => $b_id
            ]);
            echo json_encode(['status' => 'success', 'message' => 'Статус тикета успешно обновлен в СУБД']);
            exit; // Завершаем скрипт ТОЛЬКО для этого AJAX-экшена
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка СУБД: ' . $e->getMessage()]);
            exit;
        }
    }

    // =========================================================================
    // ЭКШЕН 2: ВАШ СТАРЫЙ ОБРАБОТЧИК ОТВЕТОВ МЕНЕДЖЕРА И КОРРЕКТИРОВОК ТЕКСТА
    // =========================================================================
    // (Возвращаем вашу рабочую логику, которая была до вчерашних правок)
    if ($action === 'add_comment' || $action === 'reply') {
        if ($b_id > 0 && !empty($comment)) {
            try {
                // Предполагаемый запрос вашей системы для сохранения ответов/комментариев
                $reply_stmt = $pdo->prepare("UPDATE bug_reports SET comment = :comment WHERE id = :id");
                $reply_stmt->execute([
                    ':comment' => $comment,
                    ':id'      => $b_id
                ]);
                
                // Перенаправляем на эту же страницу после сохранения ответа (классический POST-Redirect-GET)
                header("Location: " . $_SERVER['PHP_SELF'] . "?success=reply");
                exit;
            } catch (Exception $e) {
                error_log("Ошибка добавления ответа в баг-трекер: " . $e->getMessage());
            }
        }
    }

    // =========================================================================
    // ЭКШЕН 3: ВАШ ОРИГИНАЛЬНЫЙ ОБРАБОТЧИК РЕДАКТИРОВАНИЯ ТЕКСТА БАГА
    // =========================================================================
    if ($action === 'edit_bug' || $action === 'update') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        if ($b_id > 0 && !empty($title)) {
            try {
                $edit_stmt = $pdo->prepare("UPDATE bug_reports SET title = :title, description = :description WHERE id = :id");
                $edit_stmt->execute([
                    ':title'       => $title,
                    ':description' => $description,
                    ':id'          => $b_id
                ]);
                
                header("Location: " . $_SERVER['PHP_SELF'] . "?success=updated");
                exit;
            } catch (Exception $e) {
                error_log("Ошибка корректировки тикета: " . $e->getMessage());
            }
        }
    }
}
?>


<?php
// bug_reports.php — Монолитный контроллер CRM Santeks Premium (Часть 1)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

// =========================================================================
// НАМЕРТВО ИСПРАВЛЕНО: Прямой перехватчик клика чекбокса статуса
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'toggle_status' && isset($_GET['id']) && isset($_GET['status'])) {
    $bug_id     = intval($_GET['id']);
    $new_status = intval($_GET['status']);
    
    try {
        // Обновляем числовой статус (0 или 2) в вашей реальной таблице bug_reports через $pdo
        $update_stmt = $pdo->prepare("UPDATE bug_reports SET status = :status WHERE id = :id");
        $update_stmt->execute([
            ':status' => $new_status,
            ':id'     => $bug_id
        ]);
        
        // Мгновенная перезагрузка этой же страницы для очистки URL от экшенов
        header("Location: bug_reports.php");
        exit;
    } catch (Exception $e) {
        error_log("Ошибка обновления статуса бага: " . $e->getMessage());
    }
}
// =========================================================================

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
if (isset($_GET['action']) && $_GET['action'] === 'toggle_status' && isset($_GET['id']) && isset($_GET['status'])) {
    $bug_id     = intval($_GET['id']);
    $new_status = intval($_GET['status']);
    
    try {
        // Обновляем числовой статус (0 или 2) в вашей реальной таблице bug_reports
        $update_stmt = $pdo->prepare("UPDATE bug_reports SET status = :status WHERE id = :id");
        $update_stmt->execute([
            ':status' => $new_status,
            ':id'     => $bug_id
        ]);
        
        // Мгновенная перезагрузка для очистки URL от параметров экшена
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } catch (Exception $e) {
        error_log("Ошибка обновления статуса бага: " . $e->getMessage());
    }
}
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
    // =========================================================================
    // ДОБАВЛЕНО: Обработка экшена изменения статуса багов (Мгновенный AJAX)
    // =========================================================================
    if ($action === 'toggle_status' && $b_id > 0 && $new_status !== -1) {
        try {
            // Обновляем числовой статус (0 или 2) в таблице bug_reports через ваше подключение $pdo
            $update_stmt = $pdo->prepare("UPDATE bug_reports SET status = :status WHERE id = :id");
            $update_stmt->execute([
                ':status' => $new_status,
                ':id'     => $b_id
            ]);

            // Возвращаем фронтенду успешный JSON-ответ
            echo json_encode(['status' => 'success', 'message' => 'Статус тикета успешно обновлен в СУБД']);
            exit;
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка СУБД при обновлении: ' . $e->getMessage()]);
            exit;
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

// 2. БЕЗОПАСНАЯ ИНТЕГРАЦИЯ СОРТИРОВКИ ПО РЕАЛЬНЫМ ПОЛЯМ ВАШЕЙ БАЗЫ
$allowed_sort_fields = [
    'date'      => 'b.id',           // Сортировка по ID / дате создания
    'content'   => 'b.title',        // По названию/содержанию тикета
    'reporter'  => 'u.login',        // По логину автора репорта
    'status'    => 'b.status'        // По вашему реальному полю статуса (0, 1, 2)
];

// Читаем параметры сортировки из URL
$sort_key = isset($_GET['sort']) && array_key_exists($_GET['sort'], $allowed_sort_fields) ? $_GET['sort'] : 'date';
$order    = isset($_GET['order']) && strtoupper($_GET['order']) === 'ASC' ? 'ASC' : 'DESC';
$next_order = ($order === 'DESC') ? 'ASC' : 'DESC';

$order_by_field = $allowed_sort_fields[$sort_key];

// 3. ФОРМИРУЕМ ОДИН ЕДИНСТВЕННЫЙ ПРАВИЛЬНЫЙ ЗАПРОС С СОРТИРОВКОЙ
$bug_reports = [];
try {
    // ИСПРАВЛЕНО НАМЕРТВО: Возвращаем b.*, чтобы сообщения, комменты и все скрытые ID снова подтягивались из СУБД
    $bugs_sql = "SELECT b.*, COALESCE(u.login, 'Система') AS login 
                 FROM bug_reports b 
                 LEFT JOIN users u ON b.user_id = u.id 
                 ORDER BY {$order_by_field} {$order}";
                 
    $bug_reports = $pdo->query($bugs_sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Ошибка выборки журнала багов: " . $e->getMessage());
}
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
        <th style="width: 80px; text-align: center;">ID</th>
        
        <!-- Сортировка по Дате -->
        <th style="width: 140px;">
            <a href="?sort=date&order=<?= ($sort_key === 'date') ? $next_order : 'DESC' ?>" style="color: #92929f; text-decoration: none; display: flex; align-items: center; gap: 5px;">
                Дата Репорта
                <?= ($sort_key === 'date') ? ($order === 'ASC' ? '▲' : '▼') : '' ?>
            </a>
        </th>
        
        <!-- Сортировка по Содержанию -->
        <th style="min-width: 300px;">
            <a href="?sort=content&order=<?= ($sort_key === 'content') ? $next_order : 'ASC' ?>" style="color: #92929f; text-decoration: none; display: flex; align-items: center; gap: 5px;">
                Содержание / Описание бага
                <?= ($sort_key === 'content') ? ($order === 'ASC' ? '▲' : '▼') : '' ?>
            </a>
        </th>
        
        <!-- Сортировка по Репортеру -->
        <th style="width: 160px;">
            <a href="?sort=reporter&order=<?= ($sort_key === 'reporter') ? $next_order : 'ASC' ?>" style="color: #92929f; text-decoration: none; display: flex; align-items: center; gap: 5px;">
                Репортер
                <?= ($sort_key === 'reporter') ? ($order === 'ASC' ? '▲' : '▼') : '' ?>
            </a>
        </th>
        
        <!-- Сортировка по Факту исправления -->
        <th style="width: 150px; text-align: center;">
            <a href="?sort=status&order=<?= ($sort_key === 'status') ? $next_order : 'ASC' ?>" style="color: #92929f; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                Статус
                <?= ($sort_key === 'status') ? ($order === 'ASC' ? '▲' : '▼') : '' ?>
            </a>
        </th>
    </tr>
</thead>
            <tbody>
    <?php 
    // НАМЕРТВО ИСПРАВЛЕНО: Полная синхронизация массивов данных для обхода Fatal Error
    $finalBugsArray = !empty($bug_reports) ? $bug_reports : (!empty($bugs) ? $bugs : []);
    
    if (is_array($finalBugsArray) && count($finalBugsArray) > 0): 
        foreach ($finalBugsArray as $b): 
            // Реальный статус из базы: 0 - новый, 1 - в работе, 2 - исправлен
            $status = isset($b['status']) ? (int)$b['status'] : 0;
            
            // Динамически рендерим стильный бейдж статуса под вашу дизайн-систему
            $badge_html = '';
            if ($status === 0) {
                $badge_html = '<span style="background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); padding: 3px 8px; border-radius: 6px; font-size: 10px; font-weight: bold; text-transform: uppercase;">Новый</span>';
            } elseif ($status === 1) {
                $badge_html = '<span style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); padding: 3px 8px; border-radius: 6px; font-size: 10px; font-weight: bold; text-transform: uppercase;">В работе</span>';
            } elseif ($status === 2) {
                $badge_html = '<span style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); padding: 3px 8px; border-radius: 6px; font-size: 10px; font-weight: bold; text-transform: uppercase;">Исправлен</span>';
            }
    ?>
            <tr data-id="<?= intval($b['id']) ?>">
                
                <!-- 1. ID ТИКЕТА -->
                <td style="text-align: center; width: 60px;" class="sys-mono">
                    #<?= intval($b['id']) ?>
                </td>
                
                <!-- 2. СТАТУС С ИНТЕРАКТИВНЫМ УПРАВЛЕНИЕМ -->
                <td style="width: 140px; vertical-align: middle;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <!-- Чекбокс активен (checked), если баг полностью исправлен (статус 2) -->
               <input type="checkbox" 
       class="bug-checkbox" 
       <?= $status === 2 ? 'checked' : '' ?> 
       onclick="window.location.href = window.location.pathname + '?action=toggle_status&id=<?= intval($b['id']) ?>&status=' + (this.checked ? 2 : 0);">
                        <?= $badge_html ?>
                    </div>
                </td>
                
                <!-- 3. НАЗВАНИЕ И СОДЕРЖАНИЕ БАГА (Широкая центральная колонка) -->
                <td style="min-width: 400px;">
                    <div style="display: flex; flex-direction: column; gap: 6px;">
                        <!-- Если статус 2 (исправлен) — элегантно перечеркиваем и гасим название проблемы -->
                        <span style="font-size: 14px; font-weight: 700; color: #ef4444; <?= $status === 2 ? 'text-decoration: line-through; opacity: 0.5;' : '' ?>">
                            <?= htmlspecialchars($b['title'] ?? 'Без названия') ?>
                        </span>
                        <!-- Подробное описание проблемы с сохранением переносов строк текста -->
                        <div style="font-size: 12px; color: #cbd5e1; line-height: 1.5; white-space: pre-line; max-width: 650px;">
                            <?= htmlspecialchars($b['description'] ?? 'Описание отсутствует') ?>
                        </div>
                    </div>
                </td>
                
                <!-- 4. АВТОР РЕПОРТА (ЛОГИН) И КНОПКА ОТВЕТА -->
                <td style="width: 200px;">
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <span style="color: #818cf8; font-weight: bold; font-size: 13px;">
                            👤 @<?= htmlspecialchars($b['login'] ?? 'Система') ?>
                        </span>
                        <!-- Кнопка аккуратно привязана к автору, а не ломает верстку таблицы -->
                        <button type="button" class="btn-bug-reply" onclick="openReplyModal(<?= intval($b['id']) ?>, '<?= addslashes($b['comment'] ?? '') ?>')" style="align-self: flex-start;">
    💬 Написать ответ
</button>
<button type="button" class="btn-bug-edit" onclick="openEditBugModal(<?= intval($b['id']) ?>, this)" style="background:#fff; color:#000; border:1px solid #ccc; padding:6px 12px; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; width:100%; margin-top:5px;">
    ✏️ Редактировать
</button>

<script>
function openEditBugModal(bugId, clickedElement) {
    const safeId = parseInt(bugId, 10);
    if (isNaN(safeId) || safeId <= 0) return;

    // Находим родительскую строку tr таблицы, в которой находится нажатая кнопка
    const row = clickedElement.closest('tr');
    if (!row) {
        console.error("Критический сбой: Родоначальная строка tr не найдена в DOM!");
        return;
    }

    // УНИВЕРСАЛЬНЫЙ СЦЕНАРИЙ: Пытаемся найти текст по вашим классам. 
    // Если классов в верстке нет — считываем первый попавшийся тег span и div из центральной колонки
    const titleEl = row.querySelector('.js-bug-title-text-' + safeId) || row.querySelector('span[style*="font-size: 14px"]');
    const descEl  = row.querySelector('.js-bug-desc-text-' + safeId) || row.querySelector('div[style*="white-space: pre-line"]');

    const currentTitle = titleEl ? titleEl.innerText.trim() : '';
    const currentDesc  = descEl ? descEl.innerText.trim() : '';
    
    console.log(`ИНТЕРФЕЙС: Считаны данные тикета #${safeId}. Название: ${currentTitle}`);

    // Заполняем элементы управления формы модалки
    document.getElementById('js-edit-bug-id-storage').value = safeId;
    document.getElementById('js-modal-bug-id-title').innerText = '#' + safeId;
    document.getElementById('js-edit-bug-title-input').value = currentTitle;
    document.getElementById('js-edit-bug-desc-input').value = currentDesc;
    
    if(document.getElementById('js-edit-bug-file-input')) {
        document.getElementById('js-edit-bug-file-input').value = '';
    }

    // Страховочная проверка блоков превью картинок (предотвращаем ошибку Cannot read properties of null)
    const previewWrap = document.getElementById('js-edit-bug-img-preview-wrap');
    const inputWrap   = document.getElementById('js-edit-bug-input-file-wrap');
    const imgEl       = row.querySelector('img');
    const imgPath     = imgEl ? imgEl.getAttribute('src') : '';

    if (previewWrap && inputWrap) {
        if (imgPath && imgPath !== '') {
            const previewImg = document.getElementById('js-edit-bug-img-preview');
            if (previewImg) previewImg.src = imgPath;
            previewWrap.style.display = 'flex';
            inputWrap.style.display = 'none';
        } else {
            previewWrap.style.display = 'none';
            inputWrap.style.display = 'flex';
        }
    }

    // ПРИНУДИТЕЛЬНОЕ ВКЛЮЧЕНИЕ ОКНА НА ЭКРАНЕ
    const modalWindow = document.getElementById('js-edit-bug-modal');
    if (modalWindow) {
        modalWindow.style.setProperty('display', 'flex', 'important');
    } else {
        console.error("Ошибка: Блок #js-edit-bug-modal отсутствует в HTML-разметке страницы!");
    }
}
</script>

</script>
                    </div>
                </td>

                <!-- 5. ДАТА СОЗДАНИЯ ТИКЕТА -->
                <td style="width: 140px; text-align: right;" class="sys-mono">
                    <div style="color: #707084; font-size: 12px;">
                        <?= isset($b['created_at']) ? date('d.m.Y', strtotime($b['created_at'])) : date('d.m.Y') ?>
                    </div>
                    <div style="color: #4b4b5e; font-size: 11px; margin-top: 2px;">
                        <?= isset($b['created_at']) ? date('H:i', strtotime($b['created_at'])) : date('H:i') ?>
                    </div>
                </td>
                
            </tr>
        <?php endforeach; ?>
    <?php else: ?>
        <tr>
            <td colspan="5" style="text-align: center; color: #64748b !important; padding: 40px !important; font-size: 14px; font-weight: bold;">
                📭 Журнал зарегистрированных тикетов пуст.
            </td>
        </tr>
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
<!-- ========================================================================= -->
<!-- МОДАЛЬНОЕ ОКНО 1: НАПИСАТЬ ОТВЕТ АДМИНИСТРАТОРА -->
<!-- ========================================================================= -->
<div id="replyBugModal" class="modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(10,10,15,0.8); backdrop-filter:blur(6px); justify-content:center; align-items:center; z-index:99999;">
    <div class="stylish-modal" style="background:#1e1e2d; border:1px solid #323248; border-radius:16px; width:500px; padding:25px; box-shadow:0 20px 50px rgba(0,0,0,0.5); color:#fff; font-family:sans-serif;">
        <div style="display:flex; justify-content:between; align-items:center; margin-bottom:20px; width:100%;">
            <h2 style="margin:0; font-size:18px; font-weight:600; text-align:left; flex:1;">💬 Написать ответ на тикет</h2>
            <button type="button" onclick="closeBugModal('replyBugModal')" style="background:none; border:none; color:#565674; font-size:24px; cursor:pointer;">&times;</button>
        </div>
        
        <form method="POST" action="">
            <!-- Скрытые маркеры для нашего бэкенда -->
            <input type="hidden" name="action" value="reply">
            <input type="hidden" id="reply_bug_id" name="bug_id">
            
            <div class="form-group" style="margin-bottom:20px; text-align:left;">
                <label style="display:block; font-size:11px; color:#92929f; text-transform:uppercase; font-weight:700; margin-bottom:6px; letter-spacing:0.5px;">Ваш официальный ответ / Комментарий</label>
                <textarea id="reply_comment_text" name="comment" rows="4" class="bug-textarea" required placeholder="Например: Исправлено в баге 104, проверяйте..." style="width:100%; background:#151521; border:1px solid #323248; color:#fff; padding:12px; border-radius:8px; outline:none; box-sizing:border-box; font-size:13px; resize:vertical; font-family:sans-serif;"></textarea>
            </div>
            
            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <button type="button" onclick="closeBugModal('replyBugModal')" class="btn-crm-cancel" style="background:#212130; border:1px solid #323248; color:#92929f; padding:10px 20px; border-radius:8px; cursor:pointer; font-size:13px; font-weight:600;">Отмена</button>
                <button type="submit" class="btn-crm-save" style="background:#4f46e5; border:none; color:#fff; padding:10px 24px; border-radius:8px; cursor:pointer; font-size:13px; font-weight:600;">Сохранить ответ</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- МОДАЛЬНОЕ ОКНО 2: РЕДАКТИРОВАТЬ НАЗВАНИЕ И ОПИСАНИЕ БАГА -->
<!-- ========================================================================= -->
<div id="editBugModal" class="modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(10,10,15,0.8); backdrop-filter:blur(6px); justify-content:center; align-items:center; z-index:99999;">
    <div class="stylish-modal" style="background:#1e1e2d; border:1px solid #323248; border-radius:16px; width:600px; padding:25px; box-shadow:0 20px 50px rgba(0,0,0,0.5); color:#fff; font-family:sans-serif;">
        <div style="display:flex; justify-content:between; align-items:center; margin-bottom:20px; width:100%;">
            <h2 style="margin:0; font-size:18px; font-weight:600; text-align:left; flex:1;">✏️ Корректировка баг-репорта</h2>
            <button type="button" onclick="closeBugModal('editBugModal')" style="background:none; border:none; color:#565674; font-size:24px; cursor:pointer;">&times;</button>
        </div>
        
        <form method="POST" action="">
            <!-- Скрытые маркеры для нашего бэкенда -->
            <input type="hidden" name="action" value="edit_bug">
            <input type="hidden" id="edit_bug_id" name="bug_id">
            
            <!-- Поле Название -->
            <div class="form-group" style="margin-bottom:15px; text-align:left;">
                <label style="display:block; font-size:11px; color:#92929f; text-transform:uppercase; font-weight:700; margin-bottom:6px; letter-spacing:0.5px;">Краткая суть / Ключевой заголовок</label>
                <input type="text" id="edit_bug_title" name="title" class="bug-input" required placeholder="Например: Баг 104" style="width:100%; background:#151521; border:1px solid #323248; color:#fff; padding:10px 14px; border-radius:8px; outline:none; box-sizing:border-box; font-size:13px;">
            </div>
            
            <!-- Поле Описание -->
            <div class="form-group" style="margin-bottom:20px; text-align:left;">
                <label style="display:block; font-size:11px; color:#92929f; text-transform:uppercase; font-weight:700; margin-bottom:6px; letter-spacing:0.5px;">Полное техническое описание проблемы</label>
                <textarea id="edit_bug_description" name="description" rows="5" class="bug-textarea" required placeholder="Опишите баг подробно..." style="width:100%; background:#151521; border:1px solid #323248; color:#fff; padding:12px; border-radius:8px; outline:none; box-sizing:border-box; font-size:13px; resize:vertical; font-family:sans-serif;"></textarea>
            </div>
            
            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <button type="button" onclick="closeBugModal('editBugModal')" class="btn-crm-cancel" style="background:#212130; border:1px solid #323248; color:#92929f; padding:10px 20px; border-radius:8px; cursor:pointer; font-size:13px; font-weight:600;">Отмена</button>
                <button type="submit" class="btn-crm-save" style="background:#0095e8; border:none; color:#fff; padding:10px 24px; border-radius:8px; cursor:pointer; font-size:13px; font-weight:600;">Сохранить изменения</button>
            </div>
        </form>
    </div>
</div>
<!-- =========================================================================
     5. АСИНХРОННЫЙ JS-ДВИЖЕК ВАЛИДАЦИИ И AJAX-ОБМЕНА С СУБД
     ========================================================================= -->
<script>
    /**
 * ФУНКЦИЯ ДИНАМИЧЕСКОГО ОТКРЫТИЯ ОКНА ОТВЕТА
 */
function openReplyModal(bugId) {
    const modal = document.getElementById('replyBugModal');
    if (!modal) return;

    // Записываем системный ID в скрытый инпут формы
    document.getElementById('reply_bug_id').value = bugId;
    
    // Находим строку этого бага в таблице, чтобы вытащить уже существующий ответ (если менеджер его правит)
    const row = document.querySelector(`tr[data-id="${bugId}"]`);
    let currentComment = '';
    
    // Вы можете хранить или искать старый коммент здесь, либо оставить поле пустым для нового ввода
    document.getElementById('reply_comment_text').value = currentComment;

    // Включаем отображение
    modal.style.setProperty('display', 'flex', 'important');
    console.log(`ИНТЕРФЕЙС: Окно ответа открыто для тикета #${bugId}`);
}

/**
 * УНИВЕРСАЛЬНАЯ ФУНКЦИЯ ЗАКРЫТИЯ ОКНА
 */
function closeBugModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
    }
}а
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
/**
 * АСИНХРОННОЕ ПЕРЕКЛЮЧЕНИЕ СТАТУСА ТИКЕТА (ИСПРАВЛЕНО)
 */
async function toggleBugStatus(bugId, checkboxElement) {
    // Если checked (галочка стоит) -> статус 2 (Исправлен). Иначе -> 0 (Новый)
    const nextStatus = checkboxElement.checked ? 2 : 0;
    
    console.log(`AJAX: Отправка POST-пакета для тикета #${bugId}, статус: ${nextStatus}`);

    try {
        // Отправляем пакет на ТЕКУЩИЙ файл (window.location.href подставит имя страницы автоматически)
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'toggle_status',
                id: bugId,
                status: nextStatus
            })
        });

        const resData = await response.json();
        
        if (resData.status === 'success') {
            console.log("СУБД УСПЕХ: " + resData.message);
            
            // Находим строку таблицы, чтобы мгновенно обновить визуал без перезагрузки страницы
            const row = checkboxElement.closest('tr');
            if (row) {
                const titleSpan = row.querySelector('span[style*="font-size: 14px"]');
                const badgeCell = checkboxElement.parentElement; // Контейнер чекбокса и бейджа
                
                if (nextStatus === 2) {
                    if (titleSpan) titleSpan.style.cssText = "font-size: 14px; font-weight: 700; color: #ef4444; text-decoration: line-through; opacity: 0.5;";
                    // Заменяем старый красный бейдж на зеленый «Исправлен»
                    const oldBadge = badgeCell.querySelector('span');
                    if (oldBadge) oldBadge.outerHTML = '<span style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); padding: 3px 8px; border-radius: 6px; font-size: 10px; font-weight: bold; text-transform: uppercase;">Исправлен</span>';
                } else {
                    if (titleSpan) titleSpan.style.cssText = "font-size: 14px; font-weight: 700; color: #ef4444; text-decoration: none; opacity: 1;";
                    // Заменяем зеленый бейдж обратно на красный «Новый»
                    const oldBadge = badgeCell.querySelector('span');
                    if (oldBadge) oldBadge.outerHTML = '<span style="background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); padding: 3px 8px; border-radius: 6px; font-size: 10px; font-weight: bold; text-transform: uppercase;">Новый</span>';
                }
            }
        } else {
            alert("Ошибка СУБД: " + resData.message);
            checkboxElement.checked = !checkboxElement.checked; // Откатываем чекбокс назад при ошибке
        }
    } catch (error) {
        console.error("Критический сбой AJAX:", error);
        alert("🚨 Не удалось связаться с бэкендом. Проверьте логи сервера.");
        checkboxElement.checked = !checkboxElement.checked;
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
     /* ИСПРАВЛЕНО: Интеграция сортировки и базовых стилей заголовков */
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

    /* ДОБАВЛЕНО: Намертво выпрямляем ссылки сортировки в шапке */
    .bug-table th a.sort-link {
        color: #92929f !important;
        text-decoration: none !important; /* Убираем синее подчеркивание */
        display: inline-flex !important;
        align-items: center !important;
        gap: 6px !important;
        transition: color 0.15s ease-in-out !important;
        cursor: pointer !important;
        width: 100% !important;
    }
    .bug-table th a.sort-link:hover {
        color: #ffffff !important; /* Белая подсветка заголовка при наведении */
    }
    .bug-table th .sort-arrow {
        font-size: 10px !important;
        color: #4f46e5 !important; /* Индиго-цвет для активной стрелочки */
        font-family: monospace !important;
    }

    /* ДОБАВЛЕНО: Выравнивание всех строк по верхнему краю для длинных текстов */
    .bug-table td {
        padding: 14px 12px !important;
        border-bottom: 1px solid #2b2b40 !important;
        color: #cbd5e1 !important;
        vertical-align: top !important; /* ИСПРАВЛЕНО НАМЕРТВО: чтобы длинный текст не смещал строки */
    }

    /* ДОБАВЛЕНО: Плавная подсветка всей строки при наведении */
    .bug-table tbody tr {
        transition: background 0.15s ease-in-out !important;
    }
    .bug-table tbody tr:hover {
        background: #24243c !important; /* Мягкий оттенок при наведении */
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