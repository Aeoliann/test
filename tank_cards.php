<?php
/**
 * tank_cards.php — Реестр карточек резервуаров (корневой объект системы РВС)
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

// =========================================================================
// AJAX ENDPOINTS
// =========================================================================
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// -------- 1. Автокомплит клиентов --------
if ($action === 'search_clients') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) { echo json_encode([]); exit; }

    try {
        $stmt = $pdo->prepare("
            SELECT id, client_name, unp 
            FROM clients 
            WHERE client_name LIKE ? OR unp LIKE ?
            ORDER BY client_name ASC 
            LIMIT 15
        ");
        $stmt->execute(["%{$q}%", "%{$q}%"]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// -------- 2. Договоры выбранного клиента --------
if ($action === 'get_client_contracts') {
    header('Content-Type: application/json');
    $clientId = (int)($_GET['client_id'] ?? 0);
    if ($clientId <= 0) { echo json_encode([]); exit; }

    try {
        $stmt = $pdo->prepare("
           SELECT id, contract_number, contract_date, scan_path, product_type
            FROM projects 
            WHERE client_id = ?
            ORDER BY id DESC
        ");
        $stmt->execute([$clientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// -------- 3. Проверка дубликата имени резервуара --------
if ($action === 'check_tank_name') {
    header('Content-Type: application/json');
    $name = trim($_GET['name'] ?? '');
    $excludeId = (int)($_GET['exclude_id'] ?? 0);
    if ($name === '') { echo json_encode(['status' => 'clean']); exit; }

    try {
        if ($excludeId > 0) {
            $stmt = $pdo->prepare("SELECT id, name FROM tank_cards WHERE name = ? AND id != ? LIMIT 1");
            $stmt->execute([$name, $excludeId]);
        } else {
            $stmt = $pdo->prepare("SELECT id, name FROM tank_cards WHERE name = ? LIMIT 1");
            $stmt->execute([$name]);
        }
        $dup = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($dup) {
            echo json_encode(['status' => 'duplicate', 'id' => $dup['id']]);
        } else {
            echo json_encode(['status' => 'clean']);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// -------- 4. Получить данные карточки для редактирования --------
if ($action === 'get_card') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['status' => 'error', 'message' => 'Неверный ID']); exit; }

    try {
        $stmt = $pdo->prepare("
            SELECT tc.*, c.client_name, c.unp AS client_unp, u.login AS manager_name
            FROM tank_cards tc
            LEFT JOIN clients c ON tc.client_id = c.id
            LEFT JOIN users u ON tc.manager_id = u.id
            WHERE tc.id = ?
        ");
        $stmt->execute([$id]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$card) {
            echo json_encode(['status' => 'error', 'message' => 'Карточка не найдена']);
            exit;
        }
        echo json_encode(['status' => 'success', 'data' => $card]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}
// -------- 7. Поиск резервуаров (для автокомплита в СЗ) --------
if ($action === 'search_tanks') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) { echo json_encode([]); exit; }

    try {
        $stmt = $pdo->prepare("
            SELECT tc.id, tc.name, c.client_name 
            FROM tank_cards tc
            LEFT JOIN clients c ON tc.client_id = c.id
            WHERE tc.name LIKE ? OR c.client_name LIKE ?
            ORDER BY tc.name ASC 
            LIMIT 15
        ");
        $stmt->execute(["%{$q}%", "%{$q}%"]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}
// -------- 5. Сохранение карточки (создание/редактирование) --------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_card') {
    header('Content-Type: application/json');

    if (!can('card', 'create') && !can('card', 'edit')) {
        echo json_encode(['status' => 'error', 'message' => 'Недостаточно прав']); exit;
    }

    $cardId       = (int)($_POST['card_id'] ?? 0);
    $name         = trim($_POST['name'] ?? '');
    $clientId     = (int)($_POST['client_id'] ?? 0);
    $contractId   = (int)($_POST['contract_id'] ?? 0) ?: null;
    $managerId    = (int)($_POST['manager_id'] ?? $userId);

    if ($name === '') {
        echo json_encode(['status' => 'error', 'message' => 'Укажите наименование резервуара']); exit;
    }
    if ($clientId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Выберите клиента']); exit;
    }

    // Проверка дубликата
    $dupStmt = $pdo->prepare("SELECT id FROM tank_cards WHERE name = ? AND id != ?");
    $dupStmt->execute([$name, $cardId]);
    if ($dupStmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Резервуар с таким наименованием уже существует']); exit;
    }

    // Получаем данные договора, чтобы подтянуть номер, дату и скан
    $contractNumber = null;
    $contractDate   = null;
    $contractFile   = null;
    if ($contractId) {
       $cStmt = $pdo->prepare("SELECT contract_number, contract_date, scan_path FROM projects WHERE id = ? AND client_id = ?");
$cStmt->execute([$contractId, $clientId]);
$contract = $cStmt->fetch(PDO::FETCH_ASSOC);
if ($contract) {
    $contractNumber = $contract['contract_number'];
    $contractDate   = $contract['contract_date'];
    $contractFile   = $contract['scan_path'];

}
    }

    // Пользовательская загрузка скана договора (если загрузил — перекрываем)
    if (isset($_FILES['contract_file']) && $_FILES['contract_file']['error'] === UPLOAD_ERR_OK) {
        $uploaded = rvsUploadFile($_FILES['contract_file'], 'contracts', 'contract');
        if ($uploaded) $contractFile = $uploaded;
    }

    try {
        if ($cardId > 0) {
            // Редактирование
            if (!can('card', 'edit')) {
                echo json_encode(['status' => 'error', 'message' => 'Нет прав на редактирование']); exit;
            }
            $sql = "UPDATE tank_cards SET 
                        name = ?, client_id = ?, contract_id = ?, contract_number = ?, 
                        contract_date = ?, contract_file = ?, manager_id = ?
                    WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$name, $clientId, $contractId, $contractNumber, $contractDate, $contractFile, $managerId, $cardId]);

            logTankEvent($pdo, $cardId, 'card_updated', $userId, 'Карточка отредактирована');
            echo json_encode(['status' => 'success', 'id' => $cardId, 'mode' => 'update']);
            exit;
        } else {
            // Создание
            $sql = "INSERT INTO tank_cards 
                        (name, client_id, contract_id, contract_number, contract_date, contract_file, status, created_by, manager_id)
                    VALUES (?, ?, ?, ?, ?, ?, 'card_created', ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$name, $clientId, $contractId, $contractNumber, $contractDate, $contractFile, $userId, $managerId]);

            $newId = (int)$pdo->lastInsertId();

            logTankEvent($pdo, $newId, 'card_created', $userId, "Создана карточка резервуара {$name}");

            if (function_exists('logAction')) {
                logAction($pdo, 'INSERT', 'tank_cards', $newId, "Создана карточка резервуара: {$name}");
            }

            echo json_encode(['status' => 'success', 'id' => $newId, 'mode' => 'create']);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// -------- 6. Удаление --------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_card') {
    header('Content-Type: application/json');
    if (!can('card', 'delete')) {
        echo json_encode(['status' => 'error', 'message' => 'Только администратор может удалять карточки']); exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['status' => 'error', 'message' => 'Неверный ID']); exit; }

    try {
        // Проверяем, есть ли привязанные данные
        $check = $pdo->prepare("
            SELECT 
                (SELECT COUNT(*) FROM tank_kd WHERE tank_card_id = ?) AS kd_count,
                (SELECT COUNT(*) FROM tank_sz WHERE tank_card_id = ?) AS sz_count,
                (SELECT COUNT(*) FROM tank_production_stages WHERE tank_card_id = ?) AS prod_count
        ");
        $check->execute([$id, $id, $id]);
        $counts = $check->fetch(PDO::FETCH_ASSOC);

        if ($counts['kd_count'] > 0 || $counts['sz_count'] > 0 || $counts['prod_count'] > 0) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Нельзя удалить: есть привязанные КД, СЗ или этапы производства. Сначала удалите их.'
            ]);
            exit;
        }

        $pdo->prepare("DELETE FROM tank_cards WHERE id = ?")->execute([$id]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// ВЫБОРКА СПИСКА
// =========================================================================
$filterClient  = (int)($_GET['client_id'] ?? 0);
$filterStatus  = trim($_GET['status'] ?? '');
$filterManager = (int)($_GET['manager_id'] ?? 0);
$searchQuery   = trim($_GET['q'] ?? '');

$sql = "SELECT tc.*, c.client_name, c.unp AS client_unp, u.login AS manager_name
        FROM tank_cards tc
        LEFT JOIN clients c ON tc.client_id = c.id
        LEFT JOIN users u ON tc.manager_id = u.id
        WHERE 1=1";
$params = [];

// Менеджер по продажам видит только свои карточки
if (!in_array($userRole, ['admin', 'semi-admin', 'director', 'tech_dept', 'designer', 'production_chief', 'production_master', 'installation_chief', 'installation_master', 'warehouse', 'supply'], true)) {
    $sql .= " AND tc.manager_id = ?";
    $params[] = $userId;
}

if ($filterClient > 0)  { $sql .= " AND tc.client_id = ?";  $params[] = $filterClient; }
if ($filterStatus !== '') { $sql .= " AND tc.status = ?";    $params[] = $filterStatus; }
if ($filterManager > 0) { $sql .= " AND tc.manager_id = ?"; $params[] = $filterManager; }
if ($searchQuery !== '') {
    $sql .= " AND (tc.name LIKE ? OR tc.contract_number LIKE ? OR c.client_name LIKE ?)";
    $like = "%{$searchQuery}%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$sql .= " ORDER BY tc.id DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $cards = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $cards = [];
    $error = $e->getMessage();
}

// Список пользователей для фильтра "Менеджер"
$managersList = [];
try {
    $stmt = $pdo->query("SELECT id, login FROM users WHERE role IN ('manager', 'sales_manager', 'admin', 'semi-admin') ORDER BY login ASC");
    $managersList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Клиенты для фильтра
$clientsList = [];
try {
    $stmt = $pdo->query("SELECT id, client_name FROM clients WHERE status != 'Отказ' ORDER BY client_name ASC LIMIT 500");
    $clientsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Карточки резервуаров — Santeks CRM</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #0f0f1a; color: #fff; font-family: 'Segoe UI', Roboto, sans-serif; display: flex; min-height: 100vh; margin: 0; }

        main {
            flex: 1; padding: 25px 30px; min-width: 0;
            overflow-y: auto; display: flex; flex-direction: column; gap: 18px;
            max-height: 100vh;
        }

        /* ============ TOPBAR ============ */
        .topbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 24px; background: #1e1e2d;
            border: 1px solid #323248; border-radius: 12px;
            flex-wrap: wrap; gap: 12px;
        }
        .topbar h1 { margin: 0; font-size: 19px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .topbar-actions { display: flex; gap: 10px; }

        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 18px; border-radius: 8px; font-weight: 600;
            font-size: 13px; cursor: pointer; border: none;
            text-decoration: none; white-space: nowrap;
            transition: all 0.25s ease;
        }
        .btn-primary { background: #4f46e5; color: #fff; }
        .btn-primary:hover { background: #6366f1; transform: translateY(-1px); box-shadow: 0 4px 15px rgba(79,70,229,0.3); }
        .btn-outline { background: transparent; border: 1px solid #323248; color: #92929f; }
        .btn-outline:hover { border-color: #4f46e5; color: #fff; background: rgba(79,70,229,0.08); }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-danger:hover { background: #dc2626; }
        .btn-sm { padding: 5px 12px; font-size: 12px; }

        /* ============ FILTERS ============ */
        .filter-bar {
            background: #1e1e2d; border: 1px solid #323248;
            border-radius: 12px; padding: 14px 20px;
        }
        .filter-form { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 14px; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; min-width: 160px; flex: 1; max-width: 260px; }
        .filter-group label {
            font-size: 10.5px; color: #92929f; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.3px;
        }
        .filter-group input, .filter-group select {
            padding: 8px 12px; background: #151521; border: 1px solid #323248;
            border-radius: 8px; color: #fff; font-size: 13px;
            outline: none; height: 38px; width: 100%;
            cursor: pointer; transition: border-color 0.2s;
        }
        .filter-group input:focus, .filter-group select:focus { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,0.15); }
        .filter-actions { display: flex; gap: 10px; align-items: center; }

        /* ============ TABLE ============ */
        .table-wrapper {
            background: #1a1a28; border: 1px solid #323248;
            border-radius: 14px; overflow-x: auto;
        }
        table {
            width: 100%; border-collapse: collapse;
            font-size: 13px; min-width: 1100px;
        }
        thead th {
            background: #242438; color: #92929f;
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
            padding: 14px 12px; text-align: left;
            border-bottom: 2px solid #323248; white-space: nowrap;
        }
        tbody td {
            padding: 12px; border-bottom: 1px solid #26263a;
            color: #cbd5e1; vertical-align: middle;
        }
        tbody tr:hover td { background: #1e1e32; }

        .card-name {
            color: #fff; font-weight: 700; font-size: 13.5px;
            cursor: pointer; transition: color 0.15s;
        }
        .card-name:hover { color: #818cf8; }
        .card-contract {
            color: #818cf8; font-size: 12px; font-family: monospace;
        }

        /* ============ PROGRESS ============ */
        .rvs-progress { display: flex; gap: 3px; align-items: center; }
        .rvs-progress .rvs-step {
            display: inline-block;
            height: 6px; width: 12px;
            border-radius: 3px;
            background: #26263a;
            transition: background 0.2s;
        }
        .rvs-progress .rvs-step.done {
            background: var(--step-color, #4f46e5);
        }
        .rvs-progress .rvs-step.current {
            box-shadow: 0 0 8px var(--step-color, #4f46e5);
        }
        .rvs-progress-mini .rvs-step { width: 8px; height: 5px; }

        /* ============ BADGE ============ */
        .rvs-status-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 700;
            white-space: nowrap;
        }

        /* ============ ACTIONS ============ */
        .row-actions { display: flex; gap: 6px; }
        .icon-btn {
            background: transparent; border: 1px solid #323248;
            color: #92929f; padding: 5px 10px;
            border-radius: 6px; cursor: pointer;
            font-size: 12px; transition: all 0.15s;
        }
        .icon-btn:hover { border-color: #4f46e5; color: #fff; }
        .icon-btn.danger:hover { border-color: #ef4444; color: #ef4444; background: rgba(239,68,68,0.08); }

        /* ============ EMPTY ============ */
        .empty-state {
            text-align: center; padding: 60px 20px;
            color: #4b4b5e; font-size: 14px;
        }
        .empty-state .big-icon { font-size: 48px; margin-bottom: 12px; opacity: 0.4; }

        /* ============ MODAL ============ */
        .modal-overlay {
            display: none; position: fixed; top: 0; left: 0;
            width: 100%; height: 100%; background: rgba(0,0,0,0.75);
            justify-content: center; align-items: center;
            z-index: 99999; padding: 20px;
            backdrop-filter: blur(4px);
        }
        .modal-overlay.active { display: flex; }
        .modal-content {
            background: #1e1e2d; border: 1px solid #323248;
            border-radius: 16px; padding: 28px;
            max-width: 90%; max-height: 92vh; overflow-y: auto;
            box-shadow: 0 25px 60px rgba(0,0,0,0.5);
            animation: modalIn 0.25s ease;
        }
        @keyframes modalIn { from { transform: translateY(-15px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            border-bottom: 1px solid #323248; padding-bottom: 14px; margin-bottom: 20px;
        }
        .modal-header h2 { margin: 0; font-size: 20px; }
        .modal-close {
            background: none; border: none; color: #92929f;
            font-size: 26px; cursor: pointer; line-height: 1;
            transition: color 0.2s;
        }
        .modal-close:hover { color: #ef4444; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; position: relative; }
        .form-group.full { grid-column: span 2; }
        .form-group label {
            font-size: 11px; font-weight: 700; color: #92929f;
            text-transform: uppercase; letter-spacing: 0.3px;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 14px; background: #151521;
            border: 1px solid #323248; border-radius: 8px; color: #fff;
            font-size: 13px; outline: none; font-family: inherit;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79,70,229,0.15);
        }
        .form-group input.invalid { border-color: #ef4444 !important; }
        .form-group input.valid { border-color: #10b981 !important; }

        .field-hint {
            font-size: 11px; color: #6b6b85;
        }
        .field-error {
            font-size: 11px; color: #ef4444;
            font-weight: 600;
            display: none;
        }
        .field-error.show { display: block; }

        /* Autocomplete */
        .autocomplete-wrapper { position: relative; }
        .autocomplete-list {
            position: absolute; top: 100%; left: 0; right: 0;
            background: #151521; border: 1px solid #323248;
            border-radius: 8px; max-height: 260px;
            overflow-y: auto; z-index: 100;
            margin-top: 4px; display: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.4);
        }
        .autocomplete-list.show { display: block; }
        .autocomplete-item {
            padding: 9px 14px; cursor: pointer;
            border-bottom: 1px solid #1e1e2d;
            font-size: 13px; color: #cbd5e1;
            transition: background 0.15s;
        }
        .autocomplete-item:last-child { border-bottom: none; }
        .autocomplete-item:hover { background: #1e1e2d; color: #fff; }
        .autocomplete-item .unp {
            color: #6b6b85; font-size: 11px;
            font-family: monospace; margin-left: 6px;
        }
        .autocomplete-empty {
            padding: 12px 14px; color: #6b6b85;
            font-size: 12px; text-align: center;
        }

        .selected-client-box {
            background: #151521; border: 1px solid #10b98133;
            border-radius: 8px; padding: 10px 14px;
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 6px;
        }
        .selected-client-box .name {
            color: #10b981; font-weight: 600; font-size: 13px;
        }
        .selected-client-box .clear-btn {
            background: transparent; border: none; color: #6b6b85;
            cursor: pointer; font-size: 18px; padding: 0 4px;
            transition: color 0.15s;
        }
        .selected-client-box .clear-btn:hover { color: #ef4444; }

        .modal-actions {
            display: flex; justify-content: flex-end; gap: 12px;
            margin-top: 20px; border-top: 1px solid #323248; padding-top: 16px;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <main>
        <div class="topbar">
            <h1>🛢️ Карточки резервуаров</h1>
            <div class="topbar-actions">
                <?php if (can('card', 'create')): ?>
                    <button class="btn btn-primary" onclick="openCardModal()">➕ Новая карточка</button>
                <?php endif; ?>
            </div>
        </div>

        <!-- ФИЛЬТРЫ -->
        <div class="filter-bar">
            <form method="GET" action="tank_cards.php" class="filter-form">
                <div class="filter-group" style="flex: 2; max-width: 320px;">
                    <label>Поиск</label>
                    <input type="text" name="q" value="<?= rvsEscape($searchQuery) ?>" placeholder="Название резервуара, клиент, договор...">
                </div>
                <div class="filter-group">
                    <label>Клиент</label>
                    <select name="client_id">
                        <option value="0">Все клиенты</option>
                        <?php foreach ($clientsList as $cl): ?>
                            <option value="<?= (int)$cl['id'] ?>" <?= $filterClient == $cl['id'] ? 'selected' : '' ?>>
                                <?= rvsEscape($cl['client_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Этап</label>
                    <select name="status">
                        <option value="">Все этапы</option>
                        <?php foreach (rvsStages() as $key => $stage): ?>
                            <option value="<?= $key ?>" <?= $filterStatus === $key ? 'selected' : '' ?>>
                                <?= $stage['icon'] ?> <?= $stage['title'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (in_array($userRole, ['admin', 'semi-admin', 'director'], true)): ?>
                <div class="filter-group">
                    <label>Менеджер</label>
                    <select name="manager_id">
                        <option value="0">Все</option>
                        <?php foreach ($managersList as $m): ?>
                            <option value="<?= (int)$m['id'] ?>" <?= $filterManager == $m['id'] ? 'selected' : '' ?>>
                                <?= rvsEscape($m['login']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">🔍 Применить</button>
                    <a href="tank_cards.php" class="btn btn-outline">✕ Сброс</a>
                </div>
            </form>
        </div>

        <!-- СПИСОК -->
        <div class="table-wrapper">
            <?php if (empty($cards)): ?>
                <div class="empty-state">
                    <div class="big-icon">🛢️</div>
                    <div>Пока нет ни одной карточки резервуара</div>
                    <?php if (can('card', 'create')): ?>
                        <div style="margin-top:16px;">
                            <button class="btn btn-primary" onclick="openCardModal()">➕ Создать первую</button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th style="min-width:150px;">Резервуар</th>
                            <th style="min-width:160px;">Клиент</th>
                            <th style="min-width:130px;">Договор</th>
                            <th style="min-width:100px;">Менеджер</th>
                            <th style="width:120px;">Создано</th>
                            <th style="min-width:180px;">Прогресс</th>
                            <th style="width:80px;">Скан</th>
                            <th style="width:140px;">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($cards as $c): ?>
                        <tr>
                            <td style="color:#6b6b85; font-family:monospace;"><?= $i++ ?></td>
                            <td>
                                <span class="card-name" onclick="openCardView(<?= (int)$c['id'] ?>)">
                                    <?= rvsEscape($c['name']) ?>
                                </span>
                            </td>
                            <td>
                                <?= rvsEscape($c['client_name'] ?? '—') ?>
                                <?php if (!empty($c['client_unp'])): ?>
                                    <div style="font-size:11px; color:#6b6b85; font-family:monospace;">УНП <?= rvsEscape($c['client_unp']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($c['contract_number'])): ?>
                                    <span class="card-contract">№ <?= rvsEscape($c['contract_number']) ?></span>
                                    <?php if (!empty($c['contract_date'])): ?>
                                        <div style="font-size:11px; color:#6b6b85;">от <?= rvsFormatDate($c['contract_date']) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#4b4b5e;">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= rvsEscape($c['manager_name'] ?? '—') ?></td>
                            <td style="font-size:12px; color:#92929f;"><?= rvsFormatDateTime($c['created_at']) ?></td>
                            <td>
                                <?= rvsProgressBar($c['status'], true) ?>
                                <div style="margin-top:6px;"><?= rvsStatusBadge($c['status']) ?></div>
                            </td>
                            <td style="text-align:center;">
                                <?php if (!empty($c['contract_file'])): ?>
                                    <a href="<?= rvsEscape($c['contract_file']) ?>" target="_blank"
                                       class="icon-btn" style="text-decoration:none; display:inline-block;">📎</a>
                                <?php else: ?>
                                    <span style="color:#4b4b5e;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <button class="icon-btn" onclick="openCardView(<?= (int)$c['id'] ?>)" title="Открыть">👁</button>
                                    <?php if (can('card', 'edit')): ?>
                                        <button class="icon-btn" onclick="openCardModal(<?= (int)$c['id'] ?>)" title="Редактировать">✏️</button>
                                    <?php endif; ?>
                                    <?php if (can('card', 'delete')): ?>
                                        <button class="icon-btn danger" onclick="deleteCard(<?= (int)$c['id'] ?>, '<?= addslashes(rvsEscape($c['name'])) ?>')" title="Удалить">✖</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>

    <!-- ============ МОДАЛКА СОЗДАНИЯ / РЕДАКТИРОВАНИЯ ============ -->
    <div id="cardModal" class="modal-overlay">
        <div class="modal-content" style="max-width: 720px; width: 100%;">
            <div class="modal-header">
                <h2 id="cardModalTitle">➕ Новая карточка резервуара</h2>
                <button class="modal-close" onclick="closeCardModal()">&times;</button>
            </div>

            <form id="cardForm" enctype="multipart/form-data" onsubmit="saveCard(event)">
                <input type="hidden" name="action" value="save_card">
                <input type="hidden" name="card_id" id="card_id" value="0">
                <input type="hidden" name="client_id" id="client_id" value="0">
                <input type="hidden" name="contract_id" id="contract_id" value="0">

                <div class="form-grid">
                    <div class="form-group full">
                        <label>Наименование резервуара *</label>
                        <input type="text" name="name" id="name" required placeholder="РВС-550" autocomplete="off">
                        <div class="field-error" id="nameError">Резервуар с таким наименованием уже есть в системе</div>
                    </div>

                    <div class="form-group full autocomplete-wrapper">
                        <label>Клиент *</label>
                        <input type="text" id="client_search" placeholder="Начните вводить название или УНП..." autocomplete="off">
                        <div class="autocomplete-list" id="clientList"></div>
                        <div id="selectedClientBox" class="selected-client-box" style="display:none;">
                            <span class="name" id="selectedClientName"></span>
                            <button type="button" class="clear-btn" onclick="clearClient()">&times;</button>
                        </div>
                    </div>

                    <div class="form-group full">
                        <label>Договор</label>
                        <select name="contract_id_select" id="contract_select" disabled>
                            <option value="0">Сначала выберите клиента</option>
                        </select>
                        <div class="field-hint">Выбор из договоров клиента. Скан подтянется автоматически.</div>
                    </div>

                    <?php if (in_array($userRole, ['admin', 'semi-admin', 'director'], true)): ?>
                    <div class="form-group full">
                        <label>Менеджер проекта</label>
                        <select name="manager_id" id="manager_id">
                            <?php foreach ($managersList as $m): ?>
                                <option value="<?= (int)$m['id'] ?>" <?= $m['id'] == $userId ? 'selected' : '' ?>>
                                    <?= rvsEscape($m['login']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                        <input type="hidden" name="manager_id" value="<?= $userId ?>">
                    <?php endif; ?>

                    <div class="form-group full">
                        <label>Скан договора (перезаписать автоматический)</label>
                        <input type="file" name="contract_file" accept=".pdf,.jpg,.jpeg,.png" style="padding:8px;">
                        <div class="field-hint" id="contractFileHint">Если не загружать — используется файл из договора.</div>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeCardModal()">Отмена</button>
                    <button type="submit" class="btn btn-primary" id="saveCardBtn">💾 Сохранить</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // ============================================================
        // МОДАЛКА
        // ============================================================
        function openCardModal(cardId = 0) {
            const modal = document.getElementById('cardModal');
            const form = document.getElementById('cardForm');
            const title = document.getElementById('cardModalTitle');

            form.reset();
            document.getElementById('card_id').value = 0;
            document.getElementById('client_id').value = 0;
            document.getElementById('contract_id').value = 0;
            document.getElementById('contract_select').innerHTML = '<option value="0">Сначала выберите клиента</option>';
            document.getElementById('contract_select').disabled = true;
            document.getElementById('selectedClientBox').style.display = 'none';
            document.getElementById('client_search').value = '';
            document.getElementById('nameError').classList.remove('show');
            document.getElementById('name').classList.remove('invalid', 'valid');
            document.getElementById('contractFileHint').textContent = 'Если не загружать — используется файл из договора.';

            if (cardId > 0) {
                title.innerText = '✏️ Редактирование карточки #' + cardId;
                fetch('tank_cards.php?action=get_card&id=' + cardId)
                    .then(r => r.json())
                    .then(data => {
                        if (data.status !== 'success') {
                            alert('Ошибка загрузки: ' + (data.message || ''));
                            return;
                        }
                        const c = data.data;
                        document.getElementById('card_id').value = c.id;
                        document.getElementById('name').value = c.name || '';
                        document.getElementById('client_id').value = c.client_id || 0;
                        document.getElementById('contract_id').value = c.contract_id || 0;

                        if (c.client_id) {
                            showSelectedClient(c.client_id, c.client_name || '', c.client_unp || '');
                            loadContracts(c.client_id, c.contract_id);
                        }

                        if (c.manager_id) {
                            const sel = document.getElementById('manager_id');
                            if (sel) sel.value = c.manager_id;
                        }

                        if (c.contract_file) {
                            document.getElementById('contractFileHint').textContent = 'Текущий скан: ' + c.contract_file;
                        }

                        modal.classList.add('active');
                    })
                    .catch(err => {
                        console.error(err);
                        alert('Ошибка соединения');
                    });
            } else {
                title.innerText = '➕ Новая карточка резервуара';
                modal.classList.add('active');
            }
        }

        function closeCardModal() {
            document.getElementById('cardModal').classList.remove('active');
        }

        // ============================================================
        // АВТОКОМПЛИТ КЛИЕНТОВ
        // ============================================================
        let searchTimer = null;

        document.getElementById('client_search').addEventListener('input', function() {
            const q = this.value.trim();
            const list = document.getElementById('clientList');

            if (q.length < 2) {
                list.classList.remove('show');
                return;
            }

            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                fetch('tank_cards.php?action=search_clients&q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(items => {
                        list.innerHTML = '';
                        if (!Array.isArray(items) || items.length === 0) {
                            list.innerHTML = '<div class="autocomplete-empty">Ничего не найдено</div>';
                            list.classList.add('show');
                            return;
                        }
                        items.forEach(item => {
                            const div = document.createElement('div');
                            div.className = 'autocomplete-item';
                            div.innerHTML = `<strong>${escapeHtml(item.client_name)}</strong>`
                                + (item.unp ? `<span class="unp">УНП ${escapeHtml(item.unp)}</span>` : '');
                            div.onclick = () => selectClient(item.id, item.client_name, item.unp || '');
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

        document.addEventListener('click', function(e) {
            const list = document.getElementById('clientList');
            if (!e.target.closest('.autocomplete-wrapper')) {
                list.classList.remove('show');
            }
        });

        function selectClient(id, name, unp) {
            document.getElementById('client_id').value = id;
            document.getElementById('client_search').value = '';
            document.getElementById('clientList').classList.remove('show');
            showSelectedClient(id, name, unp);
            loadContracts(id, 0);
        }

        function showSelectedClient(id, name, unp) {
            const box = document.getElementById('selectedClientBox');
            document.getElementById('selectedClientName').innerHTML =
                '🏢 ' + escapeHtml(name) + (unp ? ' <span style="color:#6b6b85; font-size:11px;">УНП ' + escapeHtml(unp) + '</span>' : '');
            box.style.display = 'flex';
            document.getElementById('client_search').style.display = 'none';
        }

        function clearClient() {
            document.getElementById('client_id').value = 0;
            document.getElementById('selectedClientBox').style.display = 'none';
            document.getElementById('client_search').style.display = 'block';
            document.getElementById('client_search').value = '';
            document.getElementById('contract_select').innerHTML = '<option value="0">Сначала выберите клиента</option>';
            document.getElementById('contract_select').disabled = true;
            document.getElementById('contract_id').value = 0;
        }

        // ============================================================
        // ЗАГРУЗКА ДОГОВОРОВ
        // ============================================================
        function loadContracts(clientId, preselectedId = 0) {
            const sel = document.getElementById('contract_select');
            sel.innerHTML = '<option value="0">Загрузка...</option>';
            sel.disabled = true;

            fetch('tank_cards.php?action=get_client_contracts&client_id=' + clientId)
                .then(r => r.json())
                .then(contracts => {
                    sel.innerHTML = '<option value="0">— Без договора —</option>';
                    if (Array.isArray(contracts) && contracts.length > 0) {
                        contracts.forEach(c => {
                            const num = c.contract_number || 'б/н';
                            const dt = c.contract_date ? ' от ' + c.contract_date : '';
                            const opt = document.createElement('option');
                            opt.value = c.id;
                            opt.textContent = '№ ' + num + dt;
                            if (preselectedId && c.id == preselectedId) opt.selected = true;
                            sel.appendChild(opt);
                        });
                        sel.disabled = false;
                    } else {
                        sel.innerHTML = '<option value="0">У клиента нет договоров</option>';
                    }
                })
                .catch(() => {
                    sel.innerHTML = '<option value="0">Ошибка загрузки</option>';
                });
        }

        // Сохраняем выбранный договор в скрытое поле при submit
        document.getElementById('cardForm').addEventListener('submit', function() {
            document.getElementById('contract_id').value = document.getElementById('contract_select').value || 0;
        });

        // ============================================================
        // ПРОВЕРКА ДУБЛИКАТА ИМЕНИ
        // ============================================================
        let nameCheckTimer = null;
        document.getElementById('name').addEventListener('input', function() {
            const name = this.value.trim();
            const excludeId = document.getElementById('card_id').value;
            const errEl = document.getElementById('nameError');
            const btn = document.getElementById('saveCardBtn');

            this.classList.remove('valid', 'invalid');
            errEl.classList.remove('show');
            btn.disabled = false;

            if (name.length < 2) return;

            clearTimeout(nameCheckTimer);
            nameCheckTimer = setTimeout(() => {
                fetch('tank_cards.php?action=check_tank_name&name=' + encodeURIComponent(name) + '&exclude_id=' + excludeId)
                    .then(r => r.json())
                    .then(data => {
                        if (data.status === 'duplicate') {
                            this.classList.add('invalid');
                            errEl.classList.add('show');
                            btn.disabled = true;
                        } else {
                            this.classList.add('valid');
                        }
                    });
            }, 500);
        });

        // ============================================================
        // СОХРАНЕНИЕ
        // ============================================================
        async function saveCard(e) {
            e.preventDefault();

            const clientId = document.getElementById('client_id').value;
            if (!clientId || clientId === '0') {
                alert('Выберите клиента из списка');
                return;
            }

            const form = e.target;
            const fd = new FormData(form);
            const btn = document.getElementById('saveCardBtn');
            const oldText = btn.innerText;
            btn.disabled = true;
            btn.innerText = '⏳ Сохранение...';

            try {
                const res = await fetch('tank_cards.php', { method: 'POST', body: fd });
                const text = await res.text();
                let data;
                try { data = JSON.parse(text); }
                catch (err) { alert('Сервер вернул не JSON:\n' + text.substring(0, 300)); return; }

                if (data.status === 'success') {
                    closeCardModal();
                    location.reload();
                } else {
                    alert('Ошибка: ' + (data.message || 'Неизвестно'));
                }
            } catch (err) {
                console.error(err);
                alert('Ошибка сети');
            } finally {
                btn.disabled = false;
                btn.innerText = oldText;
            }
        }

        // ============================================================
        // ПРОСМОТР (заглушка, полноценная карточка — следующий модуль)
        // ============================================================
        function openCardView(id) {
            // Пока просто ведём в отдельную страницу (создадим в следующем шаге)
            window.location.href = 'tank_card_view.php?id=' + id;
        }

        // ============================================================
        // УДАЛЕНИЕ
        // ============================================================
        async function deleteCard(id, name) {
            if (!confirm('Удалить карточку «' + name + '»?\n\nВнимание: удаление возможно только если нет привязанных КД, СЗ и этапов.')) return;

            const fd = new FormData();
            fd.append('action', 'delete_card');
            fd.append('id', id);

            try {
                const res = await fetch('tank_cards.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    location.reload();
                } else {
                    alert('Ошибка: ' + data.message);
                }
            } catch (err) {
                alert('Ошибка соединения');
            }
        }

        // ============================================================
        // ХЕЛПЕРЫ
        // ============================================================
        function escapeHtml(s) {
            if (s === null || s === undefined) return '';
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        // ESC и клик по фону
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeCardModal();
        });
        document.getElementById('cardModal').addEventListener('click', function(e) {
            if (e.target === this) closeCardModal();
        });
    </script>
</body>
</html>