<?php
/**
 * tank_kd.php — API модуля «Конструкторская документация»
 * 
 * Действия:
 *   - upload            POST   загрузка КД к резервуару
 *   - delete            POST   удаление КД
 *   - list_archive      GET    список архивных КД (с фильтром)
 *   - upload_archive    POST   загрузка КД в архив
 *   - delete_archive    POST   удаление из архива
 * 
 * @version 1.0
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';
require_once 'tank_helpers.php';
require_once 'tank_roles.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Не авторизован']);
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
header('Content-Type: application/json');

// =========================================================================
// 1. СПИСОК АРХИВНЫХ КД
// =========================================================================
if ($action === 'list_archive') {
    if (!can('kd', 'view')) {
        echo json_encode(['status' => 'error', 'message' => 'Нет прав']); exit;
    }

    $q = trim($_GET['q'] ?? '');
    $tankType = trim($_GET['tank_type'] ?? '');

    $sql = "SELECT ak.*, u.login AS uploaded_by_name 
            FROM tank_archive_kd ak
            LEFT JOIN users u ON ak.uploaded_by = u.id
            WHERE 1=1";
    $params = [];

    if ($q !== '') {
        $sql .= " AND (ak.name LIKE ? OR ak.original_name LIKE ?)";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
    }
    if ($tankType !== '') {
        $sql .= " AND (ak.tank_type = ? OR ak.tank_type IS NULL)";
        $params[] = $tankType;
    }

    $sql .= " ORDER BY ak.uploaded_at DESC LIMIT 100";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $items]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// 2. ЗАГРУЗКА КД К РЕЗЕРВУАРУ
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload') {
    if (!can('kd', 'create')) {
        echo json_encode(['status' => 'error', 'message' => 'Недостаточно прав для загрузки КД']); exit;
    }

    $cardId     = (int)($_POST['card_id'] ?? 0);
    $sourceType = $_POST['source_type'] ?? '';
    $notes      = trim($_POST['notes'] ?? '');

    if ($cardId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Не передан ID резервуара']); exit;
    }
    if (!in_array($sourceType, ['archive', 'developed'], true)) {
        echo json_encode(['status' => 'error', 'message' => 'Укажите способ получения КД']); exit;
    }

    // Проверяем, что карточка существует
    $stmt = $pdo->prepare("SELECT id, name, status FROM tank_cards WHERE id = ?");
    $stmt->execute([$cardId]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$card) {
        echo json_encode(['status' => 'error', 'message' => 'Резервуар не найден']); exit;
    }

    $archiveKdId = null;
    $filePath    = null;
    $originalName = null;

    try {
        if ($sourceType === 'archive') {
            // Из архива — берём готовый файл
            $archiveKdId = (int)($_POST['archive_kd_id'] ?? 0);
            if ($archiveKdId <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Выберите КД из архива']); exit;
            }
            $stmt = $pdo->prepare("SELECT file_path, original_name FROM tank_archive_kd WHERE id = ?");
            $stmt->execute([$archiveKdId]);
            $arch = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$arch) {
                echo json_encode(['status' => 'error', 'message' => 'Архивная КД не найдена']); exit;
            }
            $filePath = $arch['file_path'];
            $originalName = $arch['original_name'];
        } else {
            // Разработка — загружаем файл
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['status' => 'error', 'message' => 'Файл КД не загружен']); exit;
            }
            $uploaded = rvsUploadFile($_FILES['file'], 'kd', 'kd_' . $cardId);
            if (!$uploaded) {
                echo json_encode(['status' => 'error', 'message' => 'Не удалось сохранить файл. Проверьте формат (PDF, DOC, DOCX, JPG, PNG, ZIP).']); exit;
            }
            $filePath = $uploaded;
            $originalName = $_FILES['file']['name'];
        }

        // Сохраняем запись КД
        $insert = $pdo->prepare("
            INSERT INTO tank_kd 
                (tank_card_id, source_type, archive_kd_id, file_path, original_name, loaded_at, loaded_by, notes)
            VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)
        ");
        $insert->execute([$cardId, $sourceType, $archiveKdId, $filePath, $originalName, $userId, $notes]);
        $kdId = (int)$pdo->lastInsertId();

        // Пишем событие
        logTankEvent($pdo, $cardId, 'kd_loaded', $userId, "Загружена КД ({$sourceType}): " . ($originalName ?? ''));

        // Если статус резервуара был "card_created" — двигаем на "kd_loaded"
        if ($card['status'] === 'card_created') {
            $pdo->prepare("UPDATE tank_cards SET status = 'kd_loaded' WHERE id = ?")->execute([$cardId]);
        }

        if (function_exists('logAction')) {
            logAction($pdo, 'INSERT', 'tank_kd', $kdId, "Загружена КД для резервуара «{$card['name']}»");
        }

        echo json_encode(['status' => 'success', 'kd_id' => $kdId]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// 3. УДАЛЕНИЕ КД ИЗ РЕЗЕРВУАРА
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    $kdId = (int)($_POST['kd_id'] ?? 0);
    if ($kdId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Неверный ID']); exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT kd.*, tc.name AS card_name, tc.id AS card_id FROM tank_kd kd LEFT JOIN tank_cards tc ON kd.tank_card_id = tc.id WHERE kd.id = ?");
        $stmt->execute([$kdId]);
        $kd = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$kd) {
            echo json_encode(['status' => 'error', 'message' => 'КД не найдена']); exit;
        }

        if (!can('kd', 'delete')) {
            echo json_encode(['status' => 'error', 'message' => 'Только администратор может удалять КД']); exit;
        }

        // Удаляем файл с диска (только если это разработанная КД, а не ссылка на архив)
        if ($kd['source_type'] === 'developed' && !empty($kd['file_path'])) {
            $fullPath = __DIR__ . '/' . $kd['file_path'];
            if (file_exists($fullPath)) @unlink($fullPath);
        }

        $pdo->prepare("DELETE FROM tank_kd WHERE id = ?")->execute([$kdId]);

        logTankEvent($pdo, (int)$kd['card_id'], 'kd_deleted', $userId, "Удалена КД #{$kdId}");

        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// 4. ЗАГРУЗКА КД В АРХИВ (для конструкторов)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload_archive') {
    if (!can('kd', 'archive_manage')) {
        echo json_encode(['status' => 'error', 'message' => 'Нет прав для управления архивом']); exit;
    }

    $name     = trim($_POST['name'] ?? '');
    $tankType = trim($_POST['tank_type'] ?? '');

    if ($name === '') {
        echo json_encode(['status' => 'error', 'message' => 'Укажите название архивной КД']); exit;
    }
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Файл не загружен']); exit;
    }

    $uploaded = rvsUploadFile($_FILES['file'], 'kd_archive', 'arch');
    if (!$uploaded) {
        echo json_encode(['status' => 'error', 'message' => 'Не удалось сохранить файл']); exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO tank_archive_kd (name, tank_type, file_path, original_name, uploaded_at, uploaded_by) VALUES (?, ?, ?, ?, NOW(), ?)");
        $stmt->execute([$name, $tankType ?: null, $uploaded, $_FILES['file']['name'], $userId]);
        $newId = (int)$pdo->lastInsertId();

        echo json_encode(['status' => 'success', 'id' => $newId]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// 5. УДАЛЕНИЕ ИЗ АРХИВА
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_archive') {
    if (!can('kd', 'archive_manage')) {
        echo json_encode(['status' => 'error', 'message' => 'Нет прав']); exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Неверный ID']); exit;
    }

    try {
        // Проверяем, используется ли эта КД в карточках
        $check = $pdo->prepare("SELECT COUNT(*) FROM tank_kd WHERE archive_kd_id = ?");
        $check->execute([$id]);
        if ((int)$check->fetchColumn() > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Нельзя удалить: КД используется в карточках резервуаров']); exit;
        }

        $stmt = $pdo->prepare("SELECT file_path FROM tank_archive_kd WHERE id = ?");
        $stmt->execute([$id]);
        $fp = $stmt->fetchColumn();
        if ($fp && file_exists(__DIR__ . '/' . $fp)) @unlink(__DIR__ . '/' . $fp);

        $pdo->prepare("DELETE FROM tank_archive_kd WHERE id = ?")->execute([$id]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Неизвестное действие']);