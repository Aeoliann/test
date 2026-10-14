<?php
/**
 * tank_sz.php — Реестр служебных записок (СЗ) + API
 * 
 * Действия API:
 *   - list_materials        GET    список материалов из справочника
 *   - list_units            GET    список единиц измерения
 *   - get_card_kds          GET    список КД конкретного резервуара
 *   - create_sz             POST   создание СЗ
 *   - delete_sz             POST   удаление СЗ
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
    header("Location: login.php");
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'manager';
$action   = $_GET['action'] ?? $_POST['action'] ?? '';

// =========================================================================
// AJAX: СПРАВОЧНИК МАТЕРИАЛОВ
// =========================================================================
if ($action === 'list_materials') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    $sql = "SELECT m.id, m.name, m.default_unit_id, u.short_name AS unit_short, u.name AS unit_name
            FROM materials m
            LEFT JOIN units u ON m.default_unit_id = u.id
            WHERE 1=1";
    $params = [];
    if ($q !== '') {
        $sql .= " AND m.name LIKE ?";
        $params[] = "%{$q}%";
    }
    $sql .= " ORDER BY m.name ASC LIMIT 50";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// AJAX: СПРАВОЧНИК ЕДИНИЦ
// =========================================================================
if ($action === 'list_units') {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("SELECT id, name, short_name FROM units ORDER BY name ASC");
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// AJAX: КД КОНКРЕТНОГО РЕЗЕРВУАРА
// =========================================================================
if ($action === 'get_card_kds') {
    header('Content-Type: application/json');
    $cardId = (int)($_GET['card_id'] ?? 0);
    if ($cardId <= 0) { echo json_encode(['status' => 'success', 'data' => []]); exit; }
    try {
        $stmt = $pdo->prepare("
            SELECT kd.id, kd.source_type, kd.original_name, kd.file_path, kd.loaded_at
            FROM tank_kd kd
            WHERE kd.tank_card_id = ?
            ORDER BY kd.loaded_at DESC
        ");
        $stmt->execute([$cardId]);
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// СОЗДАНИЕ СЗ
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_sz') {
    header('Content-Type: application/json');

    if (!can('sz_materials', 'create') && !can('sz_production', 'create')) {
        echo json_encode(['status' => 'error', 'message' => 'Недостаточно прав для создания СЗ']);
        exit;
    }

    $cardId     = (int)($_POST['card_id'] ?? 0);
    $szType     = $_POST['sz_type'] ?? 'materials';
    $kdIds      = $_POST['kd_ids'] ?? [];       // массив ID КД
    $items      = $_POST['items'] ?? [];        // массив строк: [material_id, unit_id, quantity, source_note]

    if ($cardId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Выберите резервуар']); exit;
    }
    if (!in_array($szType, ['materials', 'production'], true)) {
        echo json_encode(['status' => 'error', 'message' => 'Неверный тип СЗ']); exit;
    }
    if (!is_array($kdIds) || empty($kdIds)) {
        echo json_encode(['status' => 'error', 'message' => 'Выберите хотя бы одну КД']); exit;
    }
    if ($szType === 'materials' && (!is_array($items) || count($items) === 0)) {
        echo json_encode(['status' => 'error', 'message' => 'Добавьте хотя бы один материал']); exit;
    }

    // Проверяем резервуар
    $stmt = $pdo->prepare("SELECT id, name, status FROM tank_cards WHERE id = ?");
    $stmt->execute([$cardId]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$card) {
        echo json_encode(['status' => 'error', 'message' => 'Резервуар не найден']); exit;
    }

    try {
        $pdo->beginTransaction();

        // Создаём СЗ
        $stmt = $pdo->prepare("
            INSERT INTO tank_sz (tank_card_id, type, status, created_at, created_by)
            VALUES (?, ?, 'created', NOW(), ?)
        ");
        $stmt->execute([$cardId, $szType, $userId]);
        $szId = (int)$pdo->lastInsertId();

        // Привязываем КД
        $linkStmt = $pdo->prepare("INSERT INTO tank_sz_kd_links (sz_id, kd_id) VALUES (?, ?)");
        foreach ($kdIds as $kdId) {
            $kdId = (int)$kdId;
            if ($kdId > 0) $linkStmt->execute([$szId, $kdId]);
        }

        // Добавляем позиции
        if ($szType === 'materials') {
            $itemStmt = $pdo->prepare("
                INSERT INTO tank_sz_items (sz_id, material_id, unit_id, quantity, source_note)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($items as $it) {
                $matId  = (int)($it['material_id'] ?? 0);
                $unitId = (int)($it['unit_id'] ?? 0);
                $qty    = (float)($it['quantity'] ?? 0);
                $note   = trim($it['source_note'] ?? '');

                if ($matId <= 0 || $unitId <= 0 || $qty <= 0) continue;
                $itemStmt->execute([$szId, $matId, $unitId, $qty, $note ?: null]);
            }
        }

        // Пишем событие
        $eventType = $szType === 'materials' ? 'sz_materials_created' : 'sz_production_created';
        logTankEvent($pdo, $cardId, $eventType, $userId, "Создана СЗ #{$szId}");

        // Обновляем статус резервуара, если он был "kd_loaded"
        if ($card['status'] === 'kd_loaded') {
            $pdo->prepare("UPDATE tank_cards SET status = 'sz_created' WHERE id = ?")->execute([$cardId]);
        }

        $pdo->commit();

        if (function_exists('logAction')) {
            logAction($pdo, 'INSERT', 'tank_sz', $szId, "Создана СЗ #{$szId} ({$szType}) для резервуара «{$card['name']}»");
        }

        echo json_encode(['status' => 'success', 'sz_id' => $szId]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// УДАЛЕНИЕ СЗ
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_sz') {
    header('Content-Type: application/json');

    $szId = (int)($_POST['sz_id'] ?? 0);
    if ($szId <= 0) { echo json_encode(['status' => 'error', 'message' => 'Неверный ID']); exit; }

    try {
        $stmt = $pdo->prepare("SELECT sz.*, tc.status AS card_status FROM tank_sz sz LEFT JOIN tank_cards tc ON sz.tank_card_id = tc.id WHERE sz.id = ?");
        $stmt->execute([$szId]);
        $sz = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sz) { echo json_encode(['status' => 'error', 'message' => 'СЗ не найдена']); exit; }

        if (!in_array($userRole, ['admin', 'semi-admin'], true)) {
            echo json_encode(['status' => 'error', 'message' => 'Только администратор может удалять СЗ']); exit;
        }

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM tank_sz_items_supply WHERE sz_item_id IN (SELECT id FROM tank_sz_items WHERE sz_id = ?)")->execute([$szId]);
        $pdo->prepare("DELETE FROM tank_sz_items_warehouse WHERE sz_item_id IN (SELECT id FROM tank_sz_items WHERE sz_id = ?)")->execute([$szId]);
        $pdo->prepare("DELETE FROM tank_sz_items WHERE sz_id = ?")->execute([$szId]);
        $pdo->prepare("DELETE FROM tank_sz_kd_links WHERE sz_id = ?")->execute([$szId]);
        $pdo->prepare("DELETE FROM tank_sz WHERE id = ?")->execute([$szId]);
        $pdo->commit();

        logTankEvent($pdo, (int)$sz['tank_card_id'], 'sz_deleted', $userId, "Удалена СЗ #{$szId}");

        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// СПИСОК СЗ (основная страница)
// =========================================================================
$filterType     = trim($_GET['type'] ?? '');
$filterStatus   = trim($_GET['status'] ?? '');
$filterCard     = (int)($_GET['card_id'] ?? 0);
$searchQuery    = trim($_GET['q'] ?? '');

$sql = "SELECT sz.*, tc.name AS tank_name, u.login AS created_by_name,
            (SELECT COUNT(*) FROM tank_sz_items i WHERE i.sz_id = sz.id) AS items_count
        FROM tank_sz sz
        LEFT JOIN tank_cards tc ON sz.tank_card_id = tc.id
        LEFT JOIN users u ON sz.created_by = u.id
        WHERE 1=1";
$params = [];

if ($filterType !== '')   { $sql .= " AND sz.type = ?";   $params[] = $filterType; }
if ($filterStatus !== '') { $sql .= " AND sz.status = ?"; $params[] = $filterStatus; }
if ($filterCard > 0)      { $sql .= " AND sz.tank_card_id = ?"; $params[] = $filterCard; }
if ($searchQuery !== '') {
    $sql .= " AND (tc.name LIKE ? OR sz.id = ?)";
    $params[] = "%{$searchQuery}%";
    $params[] = (int)$searchQuery;
}

// Менеджер по продажам не имеет дела с СЗ
if ($userRole === 'sales_manager') {
    $sql .= " AND 1=0";
}

$sql .= " ORDER BY sz.id DESC LIMIT 200";

$szList = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $szList = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) { $error = $e->getMessage(); }

// Список резервуаров для фильтра
$cardsList = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM tank_cards ORDER BY name ASC");
    $cardsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Служебные записки — Santeks CRM</title>
    <style>
        /* Мини-кнопка создания материала рядом с селектом */
.btn-mini-add {
    flex-shrink: 0;
    width: 32px; height: 32px;
    background: rgba(16, 185, 129, 0.1);
    border: 1px solid rgba(16, 185, 129, 0.4);
    color: #10b981;
    border-radius: 6px;
    cursor: pointer;
    font-size: 16px;
    font-weight: 700;
    line-height: 1;
    display: flex; align-items: center; justify-content: center;
    transition: all 0.2s;
}
.btn-mini-add:hover {
    background: #10b981; color: #fff;
    transform: scale(1.05);
}

/* Кнопка "Новый материал" над таблицей */
.btn-add-material {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 12px;
    background: rgba(79, 70, 229, 0.1);
    border: 1px solid rgba(79, 70, 229, 0.4);
    color: #818cf8;
    border-radius: 6px;
    font-size: 12px; font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-add-material:hover { background: #4f46e5; color: #fff; border-color: #4f46e5; }

/* Вложенная модалка — поверх основной */
#quickMaterialModal { z-index: 100000 !important; }

/* Тост */
.quick-toast {
    position: fixed;
    bottom: 30px; left: 50%;
    transform: translateX(-50%);
    padding: 12px 24px;
    background: #10b981;
    color: #fff;
    border-radius: 8px;
    font-size: 13px; font-weight: 600;
    box-shadow: 0 10px 30px rgba(16,185,129,0.4);
    z-index: 100001;
    animation: toastIn 0.3s ease;
}
@keyframes toastIn {
    from { opacity: 0; transform: translateX(-50%) translateY(20px); }
    to   { opacity: 1; transform: translateX(-50%) translateY(0); }
}
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #0f0f1a; color: #fff; font-family: 'Segoe UI', Roboto, sans-serif; display: flex; min-height: 100vh; margin: 0; }
        main { flex: 1; padding: 25px 30px; min-width: 0; overflow-y: auto; display: flex; flex-direction: column; gap: 18px; max-height: 100vh; }

        .topbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 24px; background: #1e1e2d;
            border: 1px solid #323248; border-radius: 12px;
            flex-wrap: wrap; gap: 12px;
        }
        .topbar h1 { margin: 0; font-size: 19px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .topbar-actions { display: flex; gap: 10px; flex-wrap: wrap; }

        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 18px; border-radius: 8px; font-weight: 600;
            font-size: 13px; cursor: pointer; border: none;
            text-decoration: none; white-space: nowrap;
            transition: all 0.25s;
        }
        .btn-primary { background: #4f46e5; color: #fff; }
        .btn-primary:hover { background: #6366f1; transform: translateY(-1px); box-shadow: 0 4px 15px rgba(79,70,229,0.3); }
        .btn-outline { background: transparent; border: 1px solid #323248; color: #92929f; }
        .btn-outline:hover { border-color: #4f46e5; color: #fff; background: rgba(79,70,229,0.08); }
        .btn-success { background: #10b981; color: #fff; }
        .btn-success:hover { background: #059669; }
        .btn-sm { padding: 5px 12px; font-size: 12px; }

        .filter-bar { background: #1e1e2d; border: 1px solid #323248; border-radius: 12px; padding: 14px 20px; }
        .filter-form { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 14px; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; min-width: 140px; flex: 1; max-width: 240px; }
        .filter-group label {
            font-size: 10.5px; color: #92929f; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.3px;
        }
        .filter-group input, .filter-group select {
            padding: 8px 12px; background: #151521; border: 1px solid #323248;
            border-radius: 8px; color: #fff; font-size: 13px;
            outline: none; height: 38px;
        }
        .filter-group input:focus, .filter-group select:focus { border-color: #4f46e5; }
        .filter-actions { display: flex; gap: 10px; align-items: center; }

        .table-wrapper { background: #1a1a28; border: 1px solid #323248; border-radius: 14px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 900px; }
        thead th {
            background: #242438; color: #92929f;
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
            padding: 14px 12px; text-align: left;
            border-bottom: 2px solid #323248; white-space: nowrap;
        }
        tbody td { padding: 12px; border-bottom: 1px solid #26263a; color: #cbd5e1; vertical-align: middle; }
        tbody tr:hover td { background: #1e1e32; }

        .sz-type-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 10px; border-radius: 12px;
            font-size: 11px; font-weight: 700;
        }
        .sz-type-badge.materials { background: rgba(245,158,11,0.12); color: #f59e0b; border:1px solid rgba(245,158,11,0.3); }
        .sz-type-badge.production { background: rgba(99,102,241,0.12); color: #818cf8; border:1px solid rgba(99,102,241,0.3); }

        .sz-status-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 10px; border-radius: 12px;
            font-size: 11px; font-weight: 700;
        }

        .empty-state { text-align: center; padding: 60px 20px; color: #4b4b5e; font-size: 14px; }
        .empty-state .big-icon { font-size: 56px; margin-bottom: 12px; opacity: 0.3; }

        /* ============ MODAL ============ */
        .modal-overlay {
            display: none; position: fixed; top: 0; left: 0;
            width: 100%; height: 100%; background: rgba(0,0,0,0.75);
            backdrop-filter: blur(4px); z-index: 99999;
            justify-content: center; align-items: center; padding: 20px;
        }
        .modal-overlay.active { display: flex; }
        .modal-content {
            background: #1e1e2d; border: 1px solid #323248;
            border-radius: 16px; padding: 28px;
            max-width: 900px; width: 100%; max-height: 92vh; overflow-y: auto;
            box-shadow: 0 25px 60px rgba(0,0,0,0.5);
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            border-bottom: 1px solid #323248; padding-bottom: 14px; margin-bottom: 20px;
        }
        .modal-header h2 { margin: 0; font-size: 20px; }
        .modal-close { background: none; border: none; color: #92929f; font-size: 26px; cursor: pointer; }
        .modal-close:hover { color: #ef4444; }

        .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
        .form-group label {
            font-size: 11px; font-weight: 700; color: #92929f;
            text-transform: uppercase; letter-spacing: 0.3px;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 14px; background: #151521;
            border: 1px solid #323248; border-radius: 8px; color: #fff;
            font-size: 13px; outline: none; font-family: inherit;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #4f46e5; }

        /* Автокомплит */
        .autocomplete-wrapper { position: relative; }
        .autocomplete-list {
            position: absolute; top: 100%; left: 0; right: 0;
            background: #151521; border: 1px solid #323248;
            border-radius: 8px; max-height: 240px; overflow-y: auto;
            z-index: 100; margin-top: 4px; display: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.4);
        }
        .autocomplete-list.show { display: block; }
        .autocomplete-item {
            padding: 9px 14px; cursor: pointer;
            border-bottom: 1px solid #1e1e2d; font-size: 13px;
        }
        .autocomplete-item:last-child { border-bottom: none; }
        .autocomplete-item:hover { background: #1e1e2d; }
        .autocomplete-empty { padding: 12px; color: #6b6b85; font-size: 12px; text-align: center; }

        /* КД чекбоксы */
        .kd-list { background: #151521; border: 1px solid #323248; border-radius: 8px; padding: 12px; max-height: 200px; overflow-y: auto; }
        .kd-item {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 10px; border-radius: 6px; cursor: pointer;
            transition: background 0.15s;
        }
        .kd-item:hover { background: rgba(79,70,229,0.08); }
        .kd-item input { accent-color: #818cf8; }
        .kd-empty { color: #6b6b85; text-align: center; padding: 20px; font-size: 12px; }

        /* Строки материалов */
        .items-table {
            width: 100%; border-collapse: collapse;
            background: #151521; border-radius: 8px; overflow: hidden;
            font-size: 13px;
        }
        .items-table th {
            background: #242438; color: #92929f;
            font-size: 10.5px; font-weight: 700;
            text-transform: uppercase; padding: 10px;
            text-align: left; letter-spacing: 0.3px;
        }
        .items-table td {
            padding: 6px;
            border-bottom: 1px solid #26263a;
        }
        .items-table input, .items-table select {
            width: 100%; padding: 7px 10px;
            background: #0f0f1a; border: 1px solid #323248;
            border-radius: 6px; color: #fff; font-size: 12.5px;
            outline: none;
        }
        .items-table input:focus, .items-table select:focus { border-color: #4f46e5; }
        .items-table .btn-remove {
            background: rgba(239,68,68,0.1); color: #ef4444;
            border: 1px solid rgba(239,68,68,0.3);
            padding: 4px 10px; border-radius: 6px;
            cursor: pointer; font-size: 12px;
        }
        .items-table .btn-remove:hover { background: #ef4444; color: #fff; }

        .btn-add-row {
            background: rgba(16,185,129,0.08); border: 1px dashed rgba(16,185,129,0.4);
            color: #10b981; padding: 10px; border-radius: 8px;
            width: 100%; cursor: pointer; font-size: 13px; font-weight: 600;
            margin-top: 8px; transition: all 0.2s;
        }
        .btn-add-row:hover { background: rgba(16,185,129,0.15); border-color: #10b981; }

        .modal-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 20px; border-top: 1px solid #323248; padding-top: 16px; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <main>
        <div class="topbar">
            <h1>📋 Служебные записки</h1>
            <div class="topbar-actions">
                <?php if (can('sz_materials', 'create') || can('sz_production', 'create')): ?>
                    <button class="btn btn-primary" onclick="openCreateSzModal()">➕ Создать СЗ</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="filter-bar">
            <form method="GET" action="tank_sz.php" class="filter-form">
                <div class="filter-group" style="flex:2; max-width:320px;">
                    <label>Поиск</label>
                    <input type="text" name="q" value="<?= rvsEscape($searchQuery) ?>" placeholder="№ СЗ или название резервуара">
                </div>
                <div class="filter-group">
                    <label>Тип СЗ</label>
                    <select name="type">
                        <option value="">Все</option>
                        <option value="materials" <?= $filterType === 'materials' ? 'selected' : '' ?>>🛒 Материалы</option>
                        <option value="production" <?= $filterType === 'production' ? 'selected' : '' ?>>🏭 Производство</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Статус</label>
                    <select name="status">
                        <option value="">Все</option>
                        <?php foreach (szStatuses() as $key => $st): ?>
                            <option value="<?= $key ?>" <?= $filterStatus === $key ? 'selected' : '' ?>>
                                <?= rvsEscape($st['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Резервуар</label>
                    <select name="card_id">
                        <option value="0">Все</option>
                        <?php foreach ($cardsList as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= $filterCard == $c['id'] ? 'selected' : '' ?>>
                                <?= rvsEscape($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">🔍 Применить</button>
                    <a href="tank_sz.php" class="btn btn-outline">✕ Сброс</a>
                </div>
            </form>
        </div>

        <div class="table-wrapper">
            <?php if (empty($szList)): ?>
                <div class="empty-state">
                    <div class="big-icon">📋</div>
                    <div>Служебных записок пока нет</div>
                    <?php if (can('sz_materials', 'create')): ?>
                        <div style="margin-top:16px;">
                            <button class="btn btn-primary" onclick="openCreateSzModal()">➕ Создать первую СЗ</button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width:70px;">№</th>
                            <th style="min-width:150px;">Резервуар</th>
                            <th style="width:150px;">Тип</th>
                            <th style="width:170px;">Статус</th>
                            <th style="width:130px; text-align:center;">Позиций</th>
                            <th style="width:160px;">Создана</th>
                            <th style="width:140px;">Автор</th>
                            <th style="width:120px;">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($szList as $sz): 
                            $typeLabel = $sz['type'] === 'materials' ? '🛒 Материалы' : '🏭 Производство';
                            $statusInfo = szStatuses()[$sz['status']] ?? ['title' => $sz['status'], 'color' => '#6b6b85'];
                        ?>
                        <tr>
                            <td style="color:#6b6b85; font-family:monospace; font-weight:700;">#<?= (int)$sz['id'] ?></td>
                            <td>
                                <a href="tank_card_view.php?id=<?= (int)$sz['tank_card_id'] ?>" style="color:#818cf8; text-decoration:none; font-weight:600;">
                                    🛢️ <?= rvsEscape($sz['tank_name'] ?? '—') ?>
                                </a>
                            </td>
                            <td><span class="sz-type-badge <?= $sz['type'] ?>"><?= $typeLabel ?></span></td>
                            <td>
                                <span class="sz-status-badge" style="background: <?= $statusInfo['color'] ?>22; color: <?= $statusInfo['color'] ?>; border:1px solid <?= $statusInfo['color'] ?>55;">
                                    <?= rvsEscape($statusInfo['title']) ?>
                                </span>
                            </td>
                            <td style="text-align:center; color:#92929f; font-family:monospace;">
                                <?= (int)$sz['items_count'] ?>
                            </td>
                            <td style="font-size:12px; color:#92929f;"><?= rvsFormatDateTime($sz['created_at']) ?></td>
                            <td style="font-size:12px;"><?= rvsEscape($sz['created_by_name'] ?? '—') ?></td>
                            <td>
                                <a href="tank_sz_view.php?id=<?= (int)$sz['id'] ?>" class="btn btn-outline btn-sm" style="text-decoration:none;">Открыть</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>

    <!-- ============ МОДАЛКА СОЗДАНИЯ СЗ ============ -->
    <div id="szModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h2>📋 Создание служебной записки</h2>
                <button class="modal-close" onclick="closeCreateSzModal()">&times;</button>
            </div>

            <form id="szForm" onsubmit="submitSz(event)">
                <input type="hidden" name="action" value="create_sz">
                <input type="hidden" name="card_id" id="sz_card_id" value="0">
                <input type="hidden" name="sz_type" id="sz_type" value="materials">

                <!-- Шаг 1: Тип СЗ -->
                <div class="form-group">
                    <label>Тип служебной записки *</label>
                    <select id="sz_type_select" onchange="onTypeChange(this.value)">
                        <option value="materials">🛒 СЗ на закупку материалов</option>
                        <option value="production">🏭 СЗ на производство РВР</option>
                    </select>
                </div>

                <!-- Шаг 2: Резервуар -->
                <div class="form-group autocomplete-wrapper">
                    <label>Резервуар *</label>
                    <input type="text" id="sz_card_search" placeholder="Начните вводить название резервуара..." autocomplete="off">
                    <div class="autocomplete-list" id="szCardList"></div>
                    <div id="szSelectedCard" style="display:none; background:#151521; border:1px solid #10b98133; border-radius:8px; padding:10px 14px; margin-top:6px; display:none; justify-content:space-between; align-items:center;">
                        <span style="color:#10b981; font-weight:600;" id="szSelectedCardName"></span>
                        <button type="button" onclick="clearSzCard()" style="background:none;border:none;color:#6b6b85;cursor:pointer;font-size:18px;">&times;</button>
                    </div>
                </div>

                <!-- Шаг 3: Выбор КД -->
                <div class="form-group">
                    <label>Привязанные КД * (выберите одну или несколько)</label>
                    <div class="kd-list" id="kdListContainer">
                        <div class="kd-empty">Сначала выберите резервуар</div>
                    </div>
                </div>

                <!-- Шаг 4: Строки материалов (только для типа materials) -->
               <div id="itemsBlock">
    <div class="form-group">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
            <label style="margin:0;">Материалы в СЗ *</label>
            <button type="button" class="btn-add-material" onclick="openQuickMaterialModal(null)">
                ➕ Новый материал
            </button>
        </div>
        <table class="items-table" id="itemsTable">
        <!-- ============ БЫСТРОЕ СОЗДАНИЕ МАТЕРИАЛА ============ -->
<div id="quickMaterialModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 520px;">
        <div class="modal-header">
            <h2>➕ Быстрое создание материала</h2>
            <button class="modal-close" onclick="closeQuickMaterialModal()">&times;</button>
        </div>
        <form id="quickMaterialForm" onsubmit="saveQuickMaterial(event)">
            <input type="hidden" name="action" value="save_material">
            <input type="hidden" name="material_id" value="0">

            <div class="form-group">
                <label>Наименование материала *</label>
                <input type="text" name="name" id="quick_mat_name" required autocomplete="off" placeholder="Например: Труба 57х3.5">
                <div class="field-error" id="quick_mat_error">Материал с таким названием уже есть</div>
            </div>

            <div class="form-group">
                <label>Категория</label>
                <input type="text" name="category" id="quick_mat_category" list="quickCatSuggest" autocomplete="off" placeholder="Металл, крепёж, ЛКМ...">
                <datalist id="quickCatSuggest">
                    <option value="Металл">
                    <option value="Крепёж">
                    <option value="ЛКМ">
                    <option value="Комплектующие">
                    <option value="Электрика">
                </datalist>
            </div>

            <div class="form-group">
                <label>Единица измерения</label>
                <select name="default_unit_id" id="quick_mat_unit">
                    <option value="">— не задана —</option>
                </select>
                <div style="font-size:11px; color:#6b6b85;">Подставится автоматически в строку СЗ</div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeQuickMaterialModal()">Отмена</button>
                <button type="submit" class="btn btn-primary" id="quickMatSubmitBtn">💾 Создать и подставить</button>
            </div>
        </form>
    </div>
</div>
                    <div style="display:flex; gap:10px;">
    <button type="button" class="btn-add-row" style="flex:1;" onclick="addItemRow()">➕ Добавить строку</button>
    <button type="button" class="btn-add-row" style="flex:0 0 auto; padding:10px 16px; background:rgba(79,70,229,0.08); border-color:rgba(79,70,229,0.4); color:#818cf8;" onclick="window.open('tank_materials_base.php?tab=materials', '_blank')">📚 Справочник</button>
</div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeCreateSzModal()">Отмена</button>
                    <button type="submit" class="btn btn-primary" id="szSubmitBtn">💾 Создать СЗ</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // ============================================================
        // СПРАВОЧНИКИ (подгружаем один раз)
        // ============================================================
        let materialsCache = [];
        let unitsCache = [];

        async function loadDirectories() {
            try {
                const [matRes, unitRes] = await Promise.all([
                    fetch('tank_sz.php?action=list_materials').then(r => r.json()),
                    fetch('tank_sz.php?action=list_units').then(r => r.json())
                ]);
                if (matRes.status === 'success') materialsCache = matRes.data;
                if (unitRes.status === 'success') unitsCache = unitRes.data;
            } catch (e) {
                console.error('Ошибка загрузки справочников', e);
            }
        }

        // ============================================================
        // МОДАЛКА
        // ============================================================
        async function openCreateSzModal() {
            if (materialsCache.length === 0) await loadDirectories();
            document.getElementById('szModal').classList.add('active');
            resetSzForm();
        }
        function closeCreateSzModal() {
            document.getElementById('szModal').classList.remove('active');
        }
        function resetSzForm() {
            document.getElementById('szForm').reset();
            document.getElementById('sz_card_id').value = 0;
            document.getElementById('sz_type').value = 'materials';
            document.getElementById('sz_type_select').value = 'materials';
            document.getElementById('sz_card_search').value = '';
            document.getElementById('sz_card_search').style.display = 'block';
            document.getElementById('szSelectedCard').style.display = 'none';
            document.getElementById('kdListContainer').innerHTML = '<div class="kd-empty">Сначала выберите резервуар</div>';
            document.getElementById('itemsBody').innerHTML = '';
            addItemRow();
            document.getElementById('itemsBlock').style.display = 'block';
        }
        function onTypeChange(type) {
            document.getElementById('sz_type').value = type;
            document.getElementById('itemsBlock').style.display = type === 'materials' ? 'block' : 'none';
        }

        // ============================================================
        // АВТОКОМПЛИТ РЕЗЕРВУАРОВ
        // ============================================================
        let szSearchTimer = null;
        document.getElementById('sz_card_search').addEventListener('input', function() {
            const q = this.value.trim();
            const list = document.getElementById('szCardList');
            if (q.length < 2) { list.classList.remove('show'); return; }
            clearTimeout(szSearchTimer);
            szSearchTimer = setTimeout(() => {
                fetch('tank_cards.php?action=search_clients&q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(() => {
                        // Используем другой endpoint — ищем резервуары
                        fetch('tank_sz.php?action=list_materials&q=' + encodeURIComponent(q)) // заглушка — потом заменим
                            .catch(() => {});
                    }).catch(() => {});
                // Прямой запрос на поиск резервуаров
                fetch('tank_cards.php?action=search_tanks&q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(items => {
                        list.innerHTML = '';
                        if (!items || items.length === 0) {
                            list.innerHTML = '<div class="autocomplete-empty">Ничего не найдено</div>';
                            list.classList.add('show');
                            return;
                        }
                        items.forEach(item => {
                            const div = document.createElement('div');
                            div.className = 'autocomplete-item';
                            div.innerHTML = '<strong>🛢️ ' + escapeHtml(item.name) + '</strong>'
                                + (item.client_name ? '<span style="color:#6b6b85;font-size:11px;margin-left:8px;">' + escapeHtml(item.client_name) + '</span>' : '');
                            div.onclick = () => selectSzCard(item.id, item.name);
                            list.appendChild(div);
                        });
                        list.classList.add('show');
                    })
                    .catch(() => {
                        list.innerHTML = '<div class="autocomplete-empty">Ошибка загрузки</div>';
                        list.classList.add('show');
                    });
            }, 300);
        });
        document.addEventListener('click', e => {
            if (!e.target.closest('.autocomplete-wrapper')) {
                document.getElementById('szCardList').classList.remove('show');
            }
        });
        function selectSzCard(id, name) {
            document.getElementById('sz_card_id').value = id;
            document.getElementById('sz_card_search').value = '';
            document.getElementById('sz_card_search').style.display = 'none';
            document.getElementById('szSelectedCardName').textContent = '🛢️ ' + name;
            document.getElementById('szSelectedCard').style.display = 'flex';
            document.getElementById('szCardList').classList.remove('show');
            loadCardKds(id);
        }
        function clearSzCard() {
            document.getElementById('sz_card_id').value = 0;
            document.getElementById('sz_card_search').style.display = 'block';
            document.getElementById('sz_card_search').value = '';
            document.getElementById('szSelectedCard').style.display = 'none';
            document.getElementById('kdListContainer').innerHTML = '<div class="kd-empty">Сначала выберите резервуар</div>';
        }

        // ============================================================
        // ЗАГРУЗКА КД РЕЗЕРВУАРА
        // ============================================================
        function loadCardKds(cardId) {
            const container = document.getElementById('kdListContainer');
            container.innerHTML = '<div class="kd-empty">Загрузка...</div>';
            fetch('tank_sz.php?action=get_card_kds&card_id=' + cardId)
                .then(r => r.json())
                .then(data => {
                    if (data.status !== 'success' || !data.data || data.data.length === 0) {
                        container.innerHTML = '<div class="kd-empty">У этого резервуара пока нет загруженных КД. Сначала загрузите КД во вкладке «КД» карточки резервуара.</div>';
                        return;
                    }
                    container.innerHTML = '';
                    data.data.forEach(kd => {
                        const label = document.createElement('label');
                        label.className = 'kd-item';
                        const srcLabel = kd.source_type === 'archive' ? '📦' : '🆕';
                        label.innerHTML = `
                            <input type="checkbox" name="kd_ids[]" value="${kd.id}">
                            <span>${srcLabel} <strong>${escapeHtml(kd.original_name || 'КД #' + kd.id)}</strong>
                            <span style="color:#6b6b85;font-size:11px;margin-left:6px;">${(kd.loaded_at || '').substring(0, 10)}</span></span>
                        `;
                        container.appendChild(label);
                    });
                })
                .catch(() => {
                    container.innerHTML = '<div class="kd-empty">Ошибка загрузки КД</div>';
                });
        }

        // ============================================================
        // СТРОКИ МАТЕРИАЛОВ
        // ============================================================
        let itemRowIdx = 0;
   

function addItemRow() {
    const tbody = document.getElementById('itemsBody');
    const row = document.createElement('tr');

    const matOptions = materialsCache.map(m =>
        `<option value="${m.id}" data-unit="${m.default_unit_id || ''}">${escapeHtml(m.name)}</option>`
    ).join('');
    const unitOptions = unitsCache.map(u =>
        `<option value="${u.id}">${escapeHtml(u.short_name)}</option>`
    ).join('');

    row.innerHTML = `
        <td>
            <div style="display:flex; gap:4px; align-items:center;">
                <select name="items[${itemRowIdx}][material_id]" onchange="onMaterialSelect(this)" required style="flex:1;">
                    <option value="">— выберите материал —</option>
                    ${matOptions}
                </select>
                <button type="button" class="btn-mini-add" onclick="openQuickMaterialModal(this)" title="Создать новый материал">+</button>
            </div>
        </td>
        <td>
            <select name="items[${itemRowIdx}][unit_id]" required>
                <option value="">—</option>
                ${unitOptions}
            </select>
        </td>
        <td><input type="number" step="0.01" min="0.01" name="items[${itemRowIdx}][quantity]" placeholder="0.00" required></td>
        <td><input type="text" name="items[${itemRowIdx}][source_note]" placeholder="Напр: балка, корзина"></td>
        <td><button type="button" class="btn-remove" onclick="this.closest('tr').remove()">✖</button></td>
    `;
    tbody.appendChild(row);
    itemRowIdx++;
}

        function onMaterialSelect(sel) {
            const row = sel.closest('tr');
            const unitId = sel.options[sel.selectedIndex].dataset.unit;
            if (unitId) {
                const unitSel = row.querySelector('select[name*="[unit_id]"]');
                if (unitSel) unitSel.value = unitId;
            }
        }

        // ============================================================
        // ОТПРАВКА
        // ============================================================
        async function submitSz(e) {
            e.preventDefault();

            const cardId = document.getElementById('sz_card_id').value;
            if (!cardId || cardId === '0') { alert('Выберите резервуар'); return; }

            const type = document.getElementById('sz_type').value;

            // Проверяем КД
            const kdChecks = document.querySelectorAll('input[name="kd_ids[]"]:checked');
            if (kdChecks.length === 0) { alert('Выберите хотя бы одну КД'); return; }

            // Для материалов — проверяем строки
            if (type === 'materials') {
                const rows = document.querySelectorAll('#itemsBody tr');
                if (rows.length === 0) { alert('Добавьте хотя бы один материал'); return; }
            }

            const form = document.getElementById('szForm');
            const fd = new FormData(form);

            // FormData автоматически соберёт items[k][field] — ок, PHP это распарсит

            const btn = document.getElementById('szSubmitBtn');
            btn.disabled = true;
            const old = btn.innerText;
            btn.innerText = '⏳ Создание...';

            try {
                const res = await fetch('tank_sz.php', { method: 'POST', body: fd });
                const text = await res.text();
                let data;
                try { data = JSON.parse(text); }
                catch (err) { alert('Сервер вернул не JSON:\n' + text.substring(0, 400)); return; }

                if (data.status === 'success') {
                    closeCreateSzModal();
                    location.reload();
                } else {
                    alert('Ошибка: ' + (data.message || 'Неизвестно'));
                }
            } catch (err) {
                alert('Ошибка сети');
            } finally {
                btn.disabled = false;
                btn.innerText = old;
            }
        }

        function escapeHtml(s) {
            if (s === null || s === undefined) return '';
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
        }

        document.getElementById('szModal').addEventListener('click', function(e) {
            if (e.target === this) closeCreateSzModal();
        });
         document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        // Сначала закрываем вложенную модалку
        if (document.getElementById('quickMaterialModal').classList.contains('active')) {
            closeQuickMaterialModal();
            return;
        }
        // Потом основную
        closeCreateSzModal();
    }
});
document.getElementById('quickMaterialModal').addEventListener('click', function(e) {
    if (e.target === this) closeQuickMaterialModal();
});
        // ============================================================
// БЫСТРОЕ СОЗДАНИЕ МАТЕРИАЛА (вложенная модалка)
// ============================================================
let quickMaterialContext = null; // ссылка на кнопку, из которой вызвано
let quickNameTimer = null;

function openQuickMaterialModal(triggerEl = null) {
    quickMaterialContext = triggerEl;

    // Заполняем список единиц из кэша
    const unitSel = document.getElementById('quick_mat_unit');
    unitSel.innerHTML = '<option value="">— не задана —</option>' +
        unitsCache.map(u => `<option value="${u.id}">${escapeHtml(u.short_name)} — ${escapeHtml(u.name)}</option>`).join('');

    // Сброс
    document.getElementById('quickMaterialForm').reset();
    document.getElementById('quick_mat_error').classList.remove('show');
    document.getElementById('quick_mat_name').classList.remove('invalid', 'valid');
    document.getElementById('quickMatSubmitBtn').disabled = false;

    document.getElementById('quickMaterialModal').classList.add('active');
    setTimeout(() => document.getElementById('quick_mat_name').focus(), 60);
}

function closeQuickMaterialModal() {
    document.getElementById('quickMaterialModal').classList.remove('active');
    quickMaterialContext = null;
}

// Живая проверка дубликата
document.getElementById('quick_mat_name').addEventListener('input', function() {
    const name = this.value.trim();
    const errEl = document.getElementById('quick_mat_error');
    const btn = document.getElementById('quickMatSubmitBtn');

    this.classList.remove('invalid', 'valid');
    errEl.classList.remove('show');
    btn.disabled = false;

    if (name.length < 2) return;

    clearTimeout(quickNameTimer);
    quickNameTimer = setTimeout(() => {
        const dup = materialsCache.find(m => m.name.toLowerCase() === name.toLowerCase());
        if (dup) {
            this.classList.add('invalid');
            errEl.classList.add('show');
            btn.disabled = true;
        } else {
            this.classList.add('valid');
        }
    }, 400);
});

async function saveQuickMaterial(e) {
    e.preventDefault();
    const form = e.target;
    const fd = new FormData(form);
    const btn = document.getElementById('quickMatSubmitBtn');
    btn.disabled = true;
    const oldText = btn.innerText;
    btn.innerText = '⏳ Создание...';

    try {
        const res = await fetch('tank_materials_base.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.status !== 'success') {
            alert('Ошибка: ' + (data.message || 'Неизвестно'));
            return;
        }

        // Собираем данные нового материала
        const unitId = fd.get('default_unit_id');
        const unitInfo = unitId ? unitsCache.find(u => String(u.id) === String(unitId)) : null;

        const newMat = {
            id: parseInt(data.id, 10),
            name: fd.get('name').trim(),
            category: (fd.get('category') || '').trim() || null,
            default_unit_id: unitId ? parseInt(unitId, 10) : null,
            unit_short: unitInfo ? unitInfo.short_name : null,
            unit_name: unitInfo ? unitInfo.name : null
        };

        // Добавляем в кэш и сортируем
        materialsCache.push(newMat);
        materialsCache.sort((a, b) => a.name.localeCompare(b.name, 'ru'));

        // Обновляем ВСЕ селекты материалов в форме СЗ
        refreshAllMaterialSelects();

        // Подставляем в нужную строку
        let targetSelect = null;
        if (quickMaterialContext) {
            const row = quickMaterialContext.closest('tr');
            targetSelect = row ? row.querySelector('select[name*="[material_id]"]') : null;
        }

        if (!targetSelect) {
            // Общая кнопка — ищем первую пустую строку
            targetSelect = Array.from(document.querySelectorAll('#itemsBody select[name*="[material_id]"]'))
                .find(sel => !sel.value);
        }

        if (!targetSelect) {
            // Нет пустой — добавим новую строку
            addItemRow();
            const rows = document.querySelectorAll('#itemsBody tr');
            if (rows.length > 0) {
                targetSelect = rows[rows.length - 1].querySelector('select[name*="[material_id]"]');
            }
        }

        if (targetSelect) {
            targetSelect.value = newMat.id;
            onMaterialSelect(targetSelect);
        }

        closeQuickMaterialModal();
        showQuickToast('✅ Материал «' + newMat.name + '» создан и подставлен');

    } catch (err) {
        console.error(err);
        alert('Ошибка сети');
    } finally {
        btn.disabled = false;
        btn.innerText = oldText;
    }
}

function refreshAllMaterialSelects() {
    document.querySelectorAll('#itemsBody select[name*="[material_id]"]').forEach(sel => {
        const currentValue = sel.value;
        sel.innerHTML = '<option value="">— выберите материал —</option>' +
            materialsCache.map(m =>
                `<option value="${m.id}" data-unit="${m.default_unit_id || ''}">${escapeHtml(m.name)}</option>`
            ).join('');
        sel.value = currentValue;
    });
}

function showQuickToast(message) {
    // Убираем старый тост
    const old = document.querySelector('.quick-toast');
    if (old) old.remove();

    const toast = document.createElement('div');
    toast.className = 'quick-toast';
    toast.textContent = message;
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.transition = 'opacity 0.3s';
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}
    </script>
</body>
</html>