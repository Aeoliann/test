<?php
/**
 * tank_sz_view.php — Просмотр СЗ + цепочка Склад → Снабжение
 * 
 * API:
 *   - save_warehouse        POST   сохранение проверки склада по одной позиции
 *   - finish_warehouse      POST   завершить проверку склада → передать в снабжение
 *   - save_supply           POST   сохранение данных снабжения по позиции
 *   - finish_supply         POST   завершить закупку (все позиции закуплены)
 *   - export_docx           GET    экспорт СЗ в DOCX
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

$szId = (int)($_GET['id'] ?? 0);
if ($szId <= 0) {
    header('Location: tank_sz.php');
    exit;
}

if (!can('sz_materials', 'view') && !can('sz_production', 'view')) {
    die('Недостаточно прав');
}

// =========================================================================
// ЗАГРУЗКА СЗ
// =========================================================================
try {
    $stmt = $pdo->prepare("
        SELECT sz.*, tc.name AS tank_name, tc.id AS card_id, tc.status AS card_status,
               u.login AS created_by_name, c.client_name
        FROM tank_sz sz
        LEFT JOIN tank_cards tc ON sz.tank_card_id = tc.id
        LEFT JOIN users u ON sz.created_by = u.id
        LEFT JOIN clients c ON tc.client_id = c.id
        WHERE sz.id = ?
    ");
    $stmt->execute([$szId]);
    $sz = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sz) die('СЗ не найдена');
} catch (Exception $e) {
    die('Ошибка: ' . $e->getMessage());
}

// =========================================================================
// ЗАГРУЗКА ПОЗИЦИЙ С ИНФОРМАЦИЕЙ СКЛАДА И СНАБЖЕНИЯ
// =========================================================================
$items = [];
try {
    $stmt = $pdo->prepare("
        SELECT i.*, 
               m.name AS material_name, 
               u.short_name AS unit_short,
               w.in_stock, w.stock_quantity, w.checked_at, w.checked_by,
               wu.login AS checked_by_name,
               s.currency, s.price, s.purchase_date, s.arrival_date, s.arrival_quantity, s.supplier,
               s.updated_at AS supply_updated_at,
               su.login AS supply_updated_by_name,
               p.material_id AS parent_material_id,
               pm.name AS parent_material_name
        FROM tank_sz_items i
        LEFT JOIN materials m ON i.material_id = m.id
        LEFT JOIN units u ON i.unit_id = u.id
        LEFT JOIN tank_sz_items_warehouse w ON w.sz_item_id = i.id
        LEFT JOIN users wu ON w.checked_by = wu.id
        LEFT JOIN tank_sz_items_supply s ON s.sz_item_id = i.id
        LEFT JOIN users su ON s.updated_by = su.id
        LEFT JOIN tank_sz_items p ON i.parent_item_id = p.id
        LEFT JOIN materials pm ON p.material_id = pm.id
        WHERE i.sz_id = ?
        ORDER BY 
            COALESCE(i.parent_item_id, i.id) ASC,
            i.parent_item_id IS NOT NULL ASC,
            i.id ASC
    ");
    $stmt->execute([$szId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    die('Ошибка загрузки позиций: ' . $e->getMessage());
}

// =========================================================================
// ЗАГРУЗКА ПРИВЯЗАННЫХ КД
// =========================================================================
$kdList = [];
try {
    $stmt = $pdo->prepare("
        SELECT kd.*, u.login AS loaded_by_name
        FROM tank_sz_kd_links l
        LEFT JOIN tank_kd kd ON l.kd_id = kd.id
        LEFT JOIN users u ON kd.loaded_by = u.id
        WHERE l.sz_id = ?
    ");
    $stmt->execute([$szId]);
    $kdList = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// =========================================================================
// ВЫЧИСЛЕНИЕ СТАТИСТИКИ
// =========================================================================
$stats = [
    'total_items' => 0,
    'stock_full' => 0,
    'stock_partial' => 0,
    'stock_none' => 0,
    'stock_not_checked' => 0,
    'supply_pending' => 0,
    'supply_done' => 0,
];
$totalQuantity = 0;
$totalStockQuantity = 0;

foreach ($items as $it) {
    $stats['total_items']++;
    $totalQuantity += (float)$it['quantity'];

    if ($it['in_stock'] === null) {
        $stats['stock_not_checked']++;
    } elseif ((int)$it['in_stock'] === 1) {
        $stockQty = (float)$it['stock_quantity'];
        if ($stockQty >= (float)$it['quantity']) {
            $stats['stock_full']++;
            $totalStockQuantity += (float)$it['quantity'];
        } else {
            $stats['stock_partial']++;
            $totalStockQuantity += $stockQty;
        }
    } else {
        $stats['stock_none']++;
    }

    // Снабжение
    if (!empty($it['purchase_date'])) {
        $stats['supply_done']++;
    } elseif ($it['in_stock'] === 0 || ($it['parent_item_id'] !== null)) {
        $stats['supply_pending']++;
    }
}

// Прогресс-бар СЗ
$szProgress = 0;
if ($stats['total_items'] > 0) {
    $szProgress = (int)round(
        (($stats['stock_full'] + $stats['stock_partial'] + $stats['stock_none'] + $stats['supply_done']) / 
        ($stats['total_items'] + $stats['stock_none'] + $stats['stock_partial'])) * 100
    );
}
$szProgress = min(100, max(0, $szProgress));

// Определяем доступные действия по ролям
$canWarehouse = in_array($userRole, ['admin', 'semi-admin', 'warehouse'], true);
$canSupply    = in_array($userRole, ['admin', 'semi-admin', 'supply'], true);
$canExport    = can('sz_materials', 'export');

$statusInfo = szStatuses()[$sz['status']] ?? ['title' => $sz['status'], 'color' => '#6b6b85'];
$typeLabel = $sz['type'] === 'materials' ? '🛒 Закупка материалов' : '🏭 Производство РВР';

// =========================================================================
// API: СОХРАНИТЬ ПОЗИЦИЮ СКЛАДА
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_warehouse') {
    header('Content-Type: application/json');
    if (!$canWarehouse) {
        echo json_encode(['status' => 'error', 'message' => 'Недостаточно прав']); exit;
    }

    $itemId = (int)($_POST['item_id'] ?? 0);
    $inStock = (int)($_POST['in_stock'] ?? 0);
    $stockQty = (float)($_POST['stock_quantity'] ?? 0);
    $parentItemId = (int)($_POST['parent_item_id'] ?? 0) ?: null;

    if ($itemId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Неверный ID позиции']); exit;
    }

    try {
        $pdo->beginTransaction();

        // Получаем исходные данные позиции
        $stmt = $pdo->prepare("SELECT * FROM tank_sz_items WHERE id = ?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            throw new Exception('Позиция не найдена');
        }

        if ($inStock === 1 && $stockQty <= 0) {
            throw new Exception('Укажите количество на складе (больше 0)');
        }
        if ($inStock === 1 && $stockQty > (float)$item['quantity']) {
            throw new Exception('На складе не может быть больше, чем требуется');
        }

        // Сохраняем/обновляем запись склада
        $saveStmt = $pdo->prepare("
            INSERT INTO tank_sz_items_warehouse (sz_item_id, in_stock, stock_quantity, checked_at, checked_by)
            VALUES (?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE 
                in_stock = VALUES(in_stock),
                stock_quantity = VALUES(stock_quantity),
                checked_at = NOW(),
                checked_by = VALUES(checked_by)
        ");
        $saveStmt->execute([$itemId, $inStock, $inStock === 1 ? $stockQty : null, $userId]);

        // Логика частичного остатка: если отмечено "есть частично"
        // Удаляем старую строку-остаток (если есть)
        $pdo->prepare("DELETE FROM tank_sz_items WHERE parent_item_id = ?")->execute([$itemId]);

        if ($inStock === 1 && $stockQty < (float)$item['quantity']) {
            // Создаём строку-остаток, которая уйдёт в снабжение
            $remainder = (float)$item['quantity'] - $stockQty;
            $insStmt = $pdo->prepare("
                INSERT INTO tank_sz_items (sz_id, material_id, unit_id, quantity, parent_item_id, source_note)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $insStmt->execute([
                (int)$item['sz_id'],
                (int)$item['material_id'],
                (int)$item['unit_id'],
                $remainder,
                $itemId,
                'Остаток от складской проверки'
            ]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// API: ЗАВЕРШИТЬ ПРОВЕРКУ СКЛАДА
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'finish_warehouse') {
    header('Content-Type: application/json');
    if (!$canWarehouse) {
        echo json_encode(['status' => 'error', 'message' => 'Недостаточно прав']); exit;
    }

    try {
        // Проверяем, все ли позиции проверены
        $check = $pdo->prepare("
            SELECT COUNT(*) 
            FROM tank_sz_items i
            LEFT JOIN tank_sz_items_warehouse w ON w.sz_item_id = i.id
            WHERE i.sz_id = ? AND w.id IS NULL
        ");
        $check->execute([$szId]);
        $unchecked = (int)$check->fetchColumn();

        if ($unchecked > 0) {
            echo json_encode(['status' => 'error', 'message' => "Не все позиции проверены. Осталось: {$unchecked}"]);
            exit;
        }

        // Меняем статус СЗ
        $pdo->prepare("UPDATE tank_sz SET status = 'supply_pending' WHERE id = ?")->execute([$szId]);

        // Обновляем статус резервуара
        logTankEvent($pdo, (int)$sz['card_id'], 'warehouse_checked', $userId, "Склад проверил СЗ #{$szId}");

        // Если у резервуара статус = 'sz_created' — двигаем на 'warehouse_checked'
        if ($sz['card_status'] === 'sz_created') {
            $pdo->prepare("UPDATE tank_cards SET status = 'warehouse_checked' WHERE id = ?")->execute([$sz['card_id']]);
        }

        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// API: СОХРАНИТЬ ПОЗИЦИЮ СНАБЖЕНИЯ
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_supply') {
    header('Content-Type: application/json');
    if (!$canSupply) {
        echo json_encode(['status' => 'error', 'message' => 'Недостаточно прав']); exit;
    }

    $itemId        = (int)($_POST['item_id'] ?? 0);
    $currency      = trim($_POST['currency'] ?? 'BYN');
    $price         = (float)($_POST['price'] ?? 0);
    $purchaseDate  = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
    $arrivalDate   = !empty($_POST['arrival_date']) ? $_POST['arrival_date'] : null;
    $arrivalQty    = !empty($_POST['arrival_quantity']) ? (float)$_POST['arrival_quantity'] : null;
    $supplier      = trim($_POST['supplier'] ?? '');

    if ($itemId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Неверный ID позиции']); exit;
    }
    if (!in_array($currency, ['BYN', 'RUB', 'USD'], true)) $currency = 'BYN';

    try {
        $saveStmt = $pdo->prepare("
            INSERT INTO tank_sz_items_supply 
                (sz_item_id, currency, price, purchase_date, arrival_date, arrival_quantity, supplier, updated_at, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE 
                currency = VALUES(currency),
                price = VALUES(price),
                purchase_date = VALUES(purchase_date),
                arrival_date = VALUES(arrival_date),
                arrival_quantity = VALUES(arrival_quantity),
                supplier = VALUES(supplier),
                updated_at = NOW(),
                updated_by = VALUES(updated_by)
        ");
        $saveStmt->execute([$itemId, $currency, $price, $purchaseDate, $arrivalDate, $arrivalQty, $supplier ?: null, $userId]);

        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// API: ЗАВЕРШИТЬ ЗАКУПКУ
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'finish_supply') {
    header('Content-Type: application/json');
    if (!$canSupply) {
        echo json_encode(['status' => 'error', 'message' => 'Недостаточно прав']); exit;
    }

    try {
        // Проверяем, все ли дефицитные позиции закуплены
        $check = $pdo->prepare("
            SELECT COUNT(*) 
            FROM tank_sz_items i
            LEFT JOIN tank_sz_items_warehouse w ON w.sz_item_id = i.id
            LEFT JOIN tank_sz_items_supply s ON s.sz_item_id = i.id
            WHERE i.sz_id = ? 
              AND (w.in_stock = 0 OR i.parent_item_id IS NOT NULL)
              AND (s.id IS NULL OR s.purchase_date IS NULL)
        ");
        $check->execute([$szId]);
        $pending = (int)$check->fetchColumn();

        if ($pending > 0) {
            echo json_encode(['status' => 'error', 'message' => "Не все позиции закуплены. Осталось: {$pending}"]);
            exit;
        }

        $pdo->prepare("UPDATE tank_sz SET status = 'supplied' WHERE id = ?")->execute([$szId]);

        logTankEvent($pdo, (int)$sz['card_id'], 'materials_purchased', $userId, "Снабжение закупило материалы по СЗ #{$szId}");

        // Двигаем статус резервуара
        if (in_array($sz['card_status'], ['sz_created', 'warehouse_checked'], true)) {
            $pdo->prepare("UPDATE tank_cards SET status = 'materials_purchased' WHERE id = ?")->execute([$sz['card_id']]);
        }

        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// ЭКСПОРТ В DOCX
// =========================================================================
if ($_GET['action'] ?? '' === 'export_docx') {
    // Заголовки для скачивания как Word
    header('Content-Type: application/msword; charset=utf-8');
    header('Content-Disposition: attachment; filename="SZ_' . $szId . '_' . date('Ymd') . '.doc"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Собираем HTML для Word
    ?>
    <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">
    <head>
        <meta charset="utf-8">
        <title>СЗ №<?= $szId ?></title>
        <style>
            body { font-family: 'Times New Roman', serif; font-size: 12pt; }
            h1 { text-align: center; font-size: 14pt; }
            table { border-collapse: collapse; width: 100%; margin-top: 10pt; }
            th, td { border: 1px solid #000; padding: 4pt 8pt; font-size: 10pt; }
            th { background: #eee; font-weight: bold; }
            .header-info { margin: 10pt 0; font-size: 11pt; }
        </style>
    </head>
    <body>
        <h1>Служебная записка №<?= $szId ?></h1>
        <div class="header-info">
            <p><b>Резервуар:</b> <?= htmlspecialchars($sz['tank_name']) ?></p>
            <p><b>Клиент:</b> <?= htmlspecialchars($sz['client_name'] ?? '—') ?></p>
            <p><b>Тип:</b> <?= $sz['type'] === 'materials' ? 'На закупку материалов' : 'На производство РВР' ?></p>
            <p><b>Дата создания:</b> <?= date('d.m.Y', strtotime($sz['created_at'])) ?></p>
            <p><b>Автор:</b> <?= htmlspecialchars($sz['created_by_name'] ?? '—') ?></p>
        </div>
        <table>
            <thead>
                <tr>
                    <th style="width:5%;">№</th>
                    <th style="width:40%;">Наименование материала</th>
                    <th style="width:10%;">Ед. изм.</th>
                    <th style="width:15%;">Количество</th>
                    <th style="width:30%;">Источник (узел КД)</th>
                </tr>
            </thead>
            <tbody>
                <?php $n = 1; foreach ($items as $it): ?>
                    <tr>
                        <td style="text-align:center;"><?= $n++ ?></td>
                        <td><?= htmlspecialchars($it['material_name'] ?? '') ?><?= $it['parent_item_id'] ? ' <i>(остаток)</i>' : '' ?></td>
                        <td style="text-align:center;"><?= htmlspecialchars($it['unit_short'] ?? '') ?></td>
                        <td style="text-align:right;"><?= number_format((float)$it['quantity'], 2, ',', ' ') ?></td>
                        <td><?= htmlspecialchars($it['source_note'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin-top:20pt;">Дата: <?= date('d.m.Y') ?></p>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>СЗ #<?= $szId ?> — Santeks CRM</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #0f0f1a; color: #fff; font-family: 'Segoe UI', Roboto, sans-serif; display: flex; min-height: 100vh; margin: 0; }
        main { flex: 1; padding: 25px 30px; min-width: 0; overflow-y: auto; display: flex; flex-direction: column; gap: 18px; max-height: 100vh; }

        .topbar {
            display: flex; align-items: flex-start; justify-content: space-between;
            padding: 18px 24px; background: #1e1e2d;
            border: 1px solid #323248; border-radius: 12px;
            flex-wrap: wrap; gap: 16px;
        }
        .topbar-left { flex: 1; min-width: 300px; }
        .topbar-title {
            display: flex; align-items: center; gap: 14px; margin-bottom: 8px;
            flex-wrap: wrap;
        }
        .back-link {
            color: #6b6b85; text-decoration: none; font-size: 13px;
            padding: 6px 12px; border-radius: 6px;
            border: 1px solid #323248;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .back-link:hover { color: #fff; border-color: #4f46e5; }
        .topbar h1 { margin: 0; font-size: 20px; font-weight: 700; }
        .topbar-subtitle {
            color: #92929f; font-size: 13px;
            display: flex; gap: 18px; flex-wrap: wrap;
        }
        .topbar-subtitle a { color: #818cf8; text-decoration: none; }
        .topbar-actions { display: flex; gap: 10px; flex-wrap: wrap; }

        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 18px; border-radius: 8px; font-weight: 600;
            font-size: 13px; cursor: pointer; border: none;
            text-decoration: none; white-space: nowrap;
            transition: all 0.25s;
        }
        .btn-primary { background: #4f46e5; color: #fff; }
        .btn-primary:hover { background: #6366f1; transform: translateY(-1px); }
        .btn-outline { background: transparent; border: 1px solid #323248; color: #92929f; }
        .btn-outline:hover { border-color: #4f46e5; color: #fff; }
        .btn-success { background: #10b981; color: #fff; }
        .btn-success:hover { background: #059669; }
        .btn-warning { background: #f59e0b; color: #fff; }
        .btn-warning:hover { background: #d97706; }
        .btn-sm { padding: 5px 12px; font-size: 12px; }

        /* ============ СТАТИСТИКА ============ */
        .stats-bar {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 14px;
        }
        .stat-card {
            background: #1a1a28; border: 1px solid #26263a;
            border-radius: 12px; padding: 14px 18px;
        }
        .stat-card .label {
            font-size: 10.5px; color: #92929f; text-transform: uppercase;
            letter-spacing: 0.5px; font-weight: 700; margin-bottom: 6px;
        }
        .stat-card .value {
            font-size: 22px; font-weight: 700; font-family: monospace;
        }
        .stat-card.full .value    { color: #10b981; }
        .stat-card.partial .value { color: #f59e0b; }
        .stat-card.none .value    { color: #ef4444; }
        .stat-card.pending .value { color: #818cf8; }
        .stat-card.done .value    { color: #10b981; }
        .stat-card.not-checked .value { color: #6b6b85; }

        /* ============ ПРОГРЕСС ============ */
        .progress-wrapper {
            background: #1a1a28; border: 1px solid #26263a;
            border-radius: 12px; padding: 16px 22px;
        }
        .progress-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 10px;
        }
        .progress-header .title { font-size: 13px; color: #92929f; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .progress-header .percent { font-size: 20px; font-weight: 700; color: #4f46e5; font-family: monospace; }
        .progress-track {
            height: 10px; background: #26263a; border-radius: 6px; overflow: hidden;
        }
        .progress-fill {
            height: 100%; background: linear-gradient(90deg, #4f46e5, #818cf8);
            border-radius: 6px; transition: width 0.5s ease;
        }

        /* ============ СЕКЦИИ ============ */
        .section {
            background: #1a1a28; border: 1px solid #26263a;
            border-radius: 12px; overflow: hidden;
        }
        .section-header {
            padding: 16px 22px;
            background: #1e1e2d;
            border-bottom: 1px solid #26263a;
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 10px;
        }
        .section-title {
            font-size: 15px; font-weight: 700; color: #fff;
            display: flex; align-items: center; gap: 10px;
        }
        .section-body { padding: 20px 22px; }

        /* ============ ТАБЛИЦА ПОЗИЦИЙ ============ */
        .items-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .items-table th {
            background: #242438; color: #92929f;
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
            padding: 12px 10px; text-align: left;
            border-bottom: 2px solid #323248;
        }
        .items-table td {
            padding: 10px; border-bottom: 1px solid #26263a;
            color: #cbd5e1; vertical-align: middle;
        }
        .items-table .item-name { color: #fff; font-weight: 600; }
        .items-table .item-remainder {
            color: #f59e0b; font-size: 11px;
            font-style: italic; margin-top: 2px;
        }
        .items-table input, .items-table select {
            padding: 6px 10px; background: #0f0f1a;
            border: 1px solid #323248; border-radius: 6px;
            color: #fff; font-size: 12.5px; outline: none;
            width: 100%;
        }
        .items-table input:focus, .items-table select:focus { border-color: #4f46e5; }
        .items-table .qty { font-family: monospace; text-align: right; font-weight: 700; }

        .row-remainder { background: rgba(245,158,11,0.05); }

        .status-tag {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 10px; border-radius: 12px;
            font-size: 11px; font-weight: 700;
            white-space: nowrap;
        }
        .status-tag.full    { background: rgba(16,185,129,0.12); color: #10b981; border:1px solid rgba(16,185,129,0.3); }
        .status-tag.partial { background: rgba(245,158,11,0.12); color: #f59e0b; border:1px solid rgba(245,158,11,0.3); }
        .status-tag.none    { background: rgba(239,68,68,0.12); color: #ef4444; border:1px solid rgba(239,68,68,0.3); }
        .status-tag.wait    { background: rgba(129,140,248,0.12); color: #818cf8; border:1px solid rgba(129,140,248,0.3); }
        .status-tag.done    { background: rgba(16,185,129,0.12); color: #10b981; border:1px solid rgba(16,185,129,0.3); }

        /* ============ КД СПИСОК ============ */
        .kd-mini-list {
            display: flex; flex-wrap: wrap; gap: 8px;
        }
        .kd-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px;
            background: #151521; border: 1px solid #323248;
            border-radius: 8px; font-size: 12px;
            color: #cbd5e1; text-decoration: none;
            transition: all 0.15s;
        }
        .kd-chip:hover { border-color: #4f46e5; color: #fff; }

        .empty-block {
            color: #4b4b5e; font-size: 13px; padding: 30px;
            text-align: center; border: 1px dashed #26263a; border-radius: 8px;
        }

        /* Блок предупреждений */
        .role-warning {
            padding: 12px 18px;
            background: rgba(245,158,11,0.08);
            border: 1px solid rgba(245,158,11,0.3);
            border-radius: 10px;
            color: #f59e0b; font-size: 13px;
            display: flex; align-items: center; gap: 10px;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <main>
        <!-- ТОПБАР -->
        <div class="topbar">
            <div class="topbar-left">
                <div class="topbar-title">
                    <a href="tank_sz.php" class="back-link">← К списку СЗ</a>
                    <h1>📋 Служебная записка #<?= $szId ?></h1>
                    <span class="status-tag <?= 
                        $sz['status'] === 'supplied' ? 'done' : 
                        ($sz['status'] === 'supply_pending' ? 'wait' : 
                        ($sz['status'] === 'warehouse_check' ? 'partial' : 'wait')) 
                    ?>" style="background: <?= $statusInfo['color'] ?>22; color: <?= $statusInfo['color'] ?>; border:1px solid <?= $statusInfo['color'] ?>55;">
                        <?= rvsEscape($statusInfo['title']) ?>
                    </span>
                </div>
                <div class="topbar-subtitle">
                    <span>🛢️ <a href="tank_card_view.php?id=<?= (int)$sz['card_id'] ?>"><?= rvsEscape($sz['tank_name']) ?></a></span>
                    <span>🏢 <?= rvsEscape($sz['client_name'] ?? '—') ?></span>
                    <span>📦 <?= $typeLabel ?></span>
                    <span>👤 <?= rvsEscape($sz['created_by_name'] ?? '—') ?></span>
                    <span>📅 <?= rvsFormatDateTime($sz['created_at']) ?></span>
                </div>
            </div>
            <div class="topbar-actions">
                <?php if ($canExport): ?>
                    <a href="?id=<?= $szId ?>&action=export_docx" class="btn btn-outline">📄 Экспорт в DOCX</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- ПРОГРЕСС -->
        <div class="progress-wrapper">
            <div class="progress-header">
                <span class="title">Общий прогресс СЗ</span>
                <span class="percent"><?= $szProgress ?>%</span>
            </div>
            <div class="progress-track">
                <div class="progress-fill" style="width: <?= $szProgress ?>%;"></div>
            </div>
        </div>

        <!-- СТАТИСТИКА -->
        <div class="stats-bar">
            <div class="stat-card">
                <div class="label">Всего позиций</div>
                <div class="value" style="color:#fff;"><?= (int)$stats['total_items'] ?></div>
            </div>
            <div class="stat-card full">
                <div class="label">Есть полностью</div>
                <div class="value"><?= (int)$stats['stock_full'] ?></div>
            </div>
            <div class="stat-card partial">
                <div class="label">Есть частично</div>
                <div class="value"><?= (int)$stats['stock_partial'] ?></div>
            </div>
            <div class="stat-card none">
                <div class="label">Нет на складе</div>
                <div class="value"><?= (int)$stats['stock_none'] ?></div>
            </div>
            <div class="stat-card not-checked">
                <div class="label">Не проверено</div>
                <div class="value"><?= (int)$stats['stock_not_checked'] ?></div>
            </div>
            <div class="stat-card pending">
                <div class="label">К закупке</div>
                <div class="value"><?= (int)$stats['supply_pending'] ?></div>
            </div>
            <div class="stat-card done">
                <div class="label">Закуплено</div>
                <div class="value"><?= (int)$stats['supply_done'] ?></div>
            </div>
        </div>

        <!-- ПРИВЯЗАННЫЕ КД -->
        <div class="section">
            <div class="section-header">
                <div class="section-title">📐 Привязанные КД (<?= count($kdList) ?>)</div>
            </div>
            <div class="section-body">
                <?php if (empty($kdList)): ?>
                    <div class="empty-block">КД не привязаны</div>
                <?php else: ?>
                    <div class="kd-mini-list">
                        <?php foreach ($kdList as $kd): ?>
                            <a href="<?= rvsEscape($kd['file_path']) ?>" target="_blank" class="kd-chip">
                                <?= $kd['source_type'] === 'archive' ? '📦' : '🆕' ?>
                                <?= rvsEscape($kd['original_name'] ?? 'КД #' . $kd['id']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- РОЛЬ-ПОДСКАЗКА -->
        <?php if (!$canWarehouse && !$canSupply): ?>
            <div class="role-warning">
                <span>ℹ️</span>
                <span>Вы видите СЗ в режиме просмотра. Редактирование доступно складу и снабжению.</span>
            </div>
        <?php endif; ?>

        <!-- БЛОК СКЛАДА -->
        <?php if ($sz['type'] === 'materials'): ?>
        <div class="section">
            <div class="section-header">
                <div class="section-title">🏪 Проверка склада</div>
                <?php if ($canWarehouse && in_array($sz['status'], ['created', 'warehouse_check'], true)): ?>
                    <button class="btn btn-warning" onclick="finishWarehouse()">✔️ Завершить проверку</button>
                <?php endif; ?>
            </div>
            <div class="section-body">
                <table class="items-table">
                    <thead>
                        <tr>
                            <th style="width:50px;">#</th>
                            <th>Материал</th>
                            <th style="width:90px;">Ед.</th>
                            <th style="width:100px; text-align:right;">Требуется</th>
                            <th style="width:220px;">Наличие на складе</th>
                            <th style="width:140px; text-align:right;">Кол-во на складе</th>
                            <th style="width:140px;">Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr><td colspan="7" class="empty-block">Позиции отсутствуют</td></tr>
                        <?php else: ?>
                            <?php $n = 1; foreach ($items as $it): 
                                $isRemainder = !empty($it['parent_item_id']);
                                $inStock = $it['in_stock'];
                                $stockQty = $it['stock_quantity'] !== null ? (float)$it['stock_quantity'] : null;
                                $requiredQty = (float)$it['quantity'];
                            ?>
                            <tr class="<?= $isRemainder ? 'row-remainder' : '' ?>" data-item-id="<?= (int)$it['id'] ?>">
                                <td style="color:#6b6b85; font-family:monospace;"><?= $n++ ?></td>
                                <td>
                                    <div class="item-name"><?= rvsEscape($it['material_name'] ?? '—') ?></div>
                                    <?php if ($isRemainder): ?>
                                        <div class="item-remainder">↳ Остаток от «<?= rvsEscape($it['parent_material_name'] ?? '') ?>»</div>
                                    <?php elseif (!empty($it['source_note'])): ?>
                                        <div style="font-size:11px; color:#6b6b85; margin-top:2px;"><?= rvsEscape($it['source_note']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="font-family:monospace; color:#818cf8;"><?= rvsEscape($it['unit_short'] ?? '') ?></td>
                                <td class="qty"><?= number_format($requiredQty, 2, ',', ' ') ?></td>
                                <td>
                                    <?php if ($canWarehouse && $inStock === null): ?>
                                        <select class="wh-in-stock" onchange="onStockChange(this, <?= (int)$it['id'] ?>)">
                                            <option value="">— выбрать —</option>
                                            <option value="1">✅ Есть (полностью/частично)</option>
                                            <option value="0">❌ Нет</option>
                                        </select>
                                    <?php elseif ($inStock === null): ?>
                                        <span style="color:#6b6b85;">не проверено</span>
                                    <?php elseif ((int)$inStock === 1): ?>
                                        <span style="color:#10b981;">✅ Есть на складе</span>
                                    <?php else: ?>
                                        <span style="color:#ef4444;">❌ Нет</span>
                                    <?php endif; ?>
                                </td>
                                <td class="qty">
                                    <?php if ($canWarehouse && $inStock === 1): ?>
                                        <input type="number" step="0.01" min="0" max="<?= $requiredQty ?>"
                                               value="<?= $stockQty !== null ? $stockQty : '' ?>"
                                               placeholder="0.00"
                                               onchange="saveWarehouse(<?= (int)$it['id'] ?>, 1, this.value)"
                                               style="text-align:right;">
                                    <?php elseif ($inStock === 1 && $stockQty !== null): ?>
                                        <?= number_format($stockQty, 2, ',', ' ') ?>
                                    <?php else: ?>
                                        <span style="color:#4b4b5e;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($inStock === null): ?>
                                        <span class="status-tag wait">⏳ Ждёт проверки</span>
                                    <?php elseif ((int)$inStock === 1 && $stockQty !== null && $stockQty >= $requiredQty): ?>
                                        <span class="status-tag full">✅ Полностью</span>
                                    <?php elseif ((int)$inStock === 1): ?>
                                        <span class="status-tag partial">⚠️ Частично</span>
                                    <?php else: ?>
                                        <span class="status-tag none">❌ Нет</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- БЛОК СНАБЖЕНИЯ -->
        <div class="section">
            <div class="section-header">
                <div class="section-title">🛒 Снабжение</div>
                <?php if ($canSupply && in_array($sz['status'], ['supply_pending', 'warehouse_checked'], true)): ?>
                    <button class="btn btn-success" onclick="finishSupply()">✔️ Завершить закупку</button>
                <?php endif; ?>
            </div>
            <div class="section-body">
                <?php 
                // Отбираем позиции, которые нужны снабжению
                $supplyItems = array_filter($items, function($it) {
                    // Позиция нужна снабжению, если она "не на складе" или это строка-остаток
                    return ($it['in_stock'] === 0 || $it['parent_item_id'] !== null);
                });
                ?>
                <?php if (empty($supplyItems)): ?>
                    <div class="empty-block">
                        <?php if ($stats['stock_not_checked'] > 0): ?>
                            Снабжение станет активным после завершения проверки склада.
                        <?php else: ?>
                            ✅ Все материалы есть на складе — закупка не требуется.
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th style="width:50px;">#</th>
                                <th>Материал</th>
                                <th style="width:70px;">Ед.</th>
                                <th style="width:90px; text-align:right;">К закупке</th>
                                <th style="width:90px;">Валюта</th>
                                <th style="width:110px;">Цена за ед.</th>
                                <th style="width:130px;">Дата закупки</th>
                                <th style="width:130px;">Дата поступления</th>
                                <th style="width:140px;">Поставщик</th>
                                <th style="width:120px;">Статус</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $n = 1; foreach ($supplyItems as $it): 
                                $isRemainder = !empty($it['parent_item_id']);
                                $hasSupply = !empty($it['purchase_date']);
                            ?>
                            <tr class="<?= $isRemainder ? 'row-remainder' : '' ?>" data-item-id="<?= (int)$it['id'] ?>">
                                <td style="color:#6b6b85; font-family:monospace;"><?= $n++ ?></td>
                                <td>
                                    <div class="item-name"><?= rvsEscape($it['material_name'] ?? '—') ?></div>
                                    <?php if ($isRemainder): ?>
                                        <div class="item-remainder">↳ Остаток от складской проверки</div>
                                    <?php endif; ?>
                                </td>
                                <td style="font-family:monospace; color:#818cf8;"><?= rvsEscape($it['unit_short'] ?? '') ?></td>
                                <td class="qty"><?= number_format((float)$it['quantity'], 2, ',', ' ') ?></td>
                                <td>
                                    <?php if ($canSupply): ?>
                                        <select class="sp-currency" onchange="saveSupply(<?= (int)$it['id'] ?>)">
                                            <option value="BYN" <?= ($it['currency'] ?? 'BYN') === 'BYN' ? 'selected' : '' ?>>BYN</option>
                                            <option value="RUB" <?= ($it['currency'] ?? '') === 'RUB' ? 'selected' : '' ?>>RUB</option>
                                            <option value="USD" <?= ($it['currency'] ?? '') === 'USD' ? 'selected' : '' ?>>USD</option>
                                        </select>
                                    <?php else: ?>
                                        <?= rvsEscape($it['currency'] ?? '—') ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($canSupply): ?>
                                        <input type="number" step="0.01" min="0" class="sp-price"
                                               value="<?= $it['price'] !== null ? (float)$it['price'] : '' ?>"
                                               placeholder="0.00"
                                               onchange="saveSupply(<?= (int)$it['id'] ?>)"
                                               style="text-align:right;">
                                    <?php else: ?>
                                        <?= $it['price'] !== null ? number_format((float)$it['price'], 2, ',', ' ') : '—' ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($canSupply): ?>
                                        <input type="date" class="sp-purchase-date"
                                               value="<?= rvsEscape($it['purchase_date'] ?? '') ?>"
                                               onchange="saveSupply(<?= (int)$it['id'] ?>)">
                                    <?php else: ?>
                                        <?= rvsFormatDate($it['purchase_date']) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($canSupply): ?>
                                        <input type="date" class="sp-arrival-date"
                                               value="<?= rvsEscape($it['arrival_date'] ?? '') ?>"
                                               onchange="saveSupply(<?= (int)$it['id'] ?>)">
                                    <?php else: ?>
                                        <?= rvsFormatDate($it['arrival_date']) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($canSupply): ?>
                                        <input type="text" class="sp-supplier"
                                               value="<?= rvsEscape($it['supplier'] ?? '') ?>"
                                               placeholder="ООО Поставщик"
                                               onchange="saveSupply(<?= (int)$it['id'] ?>)">
                                    <?php else: ?>
                                        <?= rvsEscape($it['supplier'] ?? '—') ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($hasSupply): ?>
                                        <span class="status-tag done">✅ Закуплено</span>
                                    <?php else: ?>
                                        <span class="status-tag wait">⏳ К закупке</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
            <!-- СЗ на производство — пока просто заглушка, детально в модуле Производства -->
            <div class="section">
                <div class="section-body">
                    <div class="empty-block">
                        СЗ на производство обрабатывается во вкладке «Производство» карточки резервуара.
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </main>

    <script>
        // ============================================================
        // ЛОГИКА: переключение in_stock
        // ============================================================
        function onStockChange(sel, itemId) {
            const val = sel.value;
            if (val === '') return;

            if (val === '1') {
                // Показать поле для количества — просим сразу
                // Просто сохраняем "есть", количество подставится вручную
                saveWarehouse(itemId, 1, null);
                setTimeout(() => location.reload(), 300);
            } else {
                saveWarehouse(itemId, 0, null);
                setTimeout(() => location.reload(), 300);
            }
        }

        async function saveWarehouse(itemId, inStock, stockQty) {
            const fd = new FormData();
            fd.append('action', 'save_warehouse');
            fd.append('item_id', itemId);
            fd.append('in_stock', inStock);
            if (stockQty !== null && stockQty !== '') {
                fd.append('stock_quantity', stockQty);
            } else {
                // Если "есть", но количество не задано — считаем, что всё есть
                // Найдём исходный TR и подставим required
                const row = document.querySelector(`tr[data-item-id="${itemId}"]`);
                if (row) {
                    const reqCell = row.querySelectorAll('.qty')[0];
                    const required = parseFloat(reqCell.textContent.replace(/\s/g,'').replace(',', '.')) || 0;
                    fd.append('stock_quantity', required);
                } else {
                    fd.append('stock_quantity', 0);
                }
            }

            try {
                const res = await fetch('tank_sz_view.php?id=<?= $szId ?>', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status !== 'success') {
                    alert('Ошибка: ' + data.message);
                }
            } catch (e) {
                alert('Ошибка сети');
            }
        }

        // ============================================================
        // Завершить проверку склада
        // ============================================================
        async function finishWarehouse() {
            if (!confirm('Завершить проверку склада и передать СЗ в снабжение?')) return;
            const fd = new FormData();
            fd.append('action', 'finish_warehouse');
            try {
                const res = await fetch('tank_sz_view.php?id=<?= $szId ?>', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    location.reload();
                } else {
                    alert('Ошибка: ' + data.message);
                }
            } catch (e) { alert('Ошибка сети'); }
        }

        // ============================================================
        // Сохранить данные снабжения по позиции
        // ============================================================
        async function saveSupply(itemId) {
            const row = document.querySelector(`tr[data-item-id="${itemId}"]`);
            if (!row) return;

            const fd = new FormData();
            fd.append('action', 'save_supply');
            fd.append('item_id', itemId);
            fd.append('currency', row.querySelector('.sp-currency')?.value || 'BYN');
            fd.append('price', row.querySelector('.sp-price')?.value || '');
            fd.append('purchase_date', row.querySelector('.sp-purchase-date')?.value || '');
            fd.append('arrival_date', row.querySelector('.sp-arrival-date')?.value || '');
            fd.append('supplier', row.querySelector('.sp-supplier')?.value || '');

            try {
                const res = await fetch('tank_sz_view.php?id=<?= $szId ?>', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status !== 'success') {
                    alert('Ошибка: ' + data.message);
                } else {
                    // Мягко подсветим сохранение
                    row.style.transition = 'background 0.3s';
                    row.style.background = 'rgba(16,185,129,0.08)';
                    setTimeout(() => row.style.background = '', 600);
                }
            } catch (e) { alert('Ошибка сети'); }
        }

        // ============================================================
        // Завершить закупку
        // ============================================================
        async function finishSupply() {
            if (!confirm('Завершить закупку? СЗ перейдёт в статус «Закуплено».')) return;
            const fd = new FormData();
            fd.append('action', 'finish_supply');
            try {
                const res = await fetch('tank_sz_view.php?id=<?= $szId ?>', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    location.reload();
                } else {
                    alert('Ошибка: ' + data.message);
                }
            } catch (e) { alert('Ошибка сети'); }
        }
    </script>
</body>
</html>