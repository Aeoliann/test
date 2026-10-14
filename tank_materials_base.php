<?php
/**
 * tank_materials_base.php — Справочник материалов и единиц измерения
 * 
 * Доступ: 
 *   - просмотр: все роли РВС
 *   - редактирование: admin, semi-admin, tech_dept
 * 
 * API:
 *   - save_material       POST   создание/редактирование материала
 *   - delete_material     POST   удаление
 *   - save_unit           POST   создание/редактирование единицы
 *   - delete_unit         POST   удаление единицы
 *   - get_material        GET    данные материала
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

// Кто может редактировать справочник
$canEdit = in_array($userRole, ['admin', 'semi-admin', 'tech_dept'], true);

// Кто может смотреть
$canView = in_array($userRole, [
    'admin', 'semi-admin', 'manager', 'sales_manager', 'tech_dept', 
    'warehouse', 'supply', 'production_chief', 'production_master',
    'installation_chief', 'installation_master', 'director'
], true);

if (!$canView) {
    die('Доступ запрещён');
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// =========================================================================
// API: ПОЛУЧИТЬ МАТЕРИАЛ
// =========================================================================
if ($action === 'get_material') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT * FROM materials WHERE id = ?");
        $stmt->execute([$id]);
        $mat = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$mat) {
            echo json_encode(['status' => 'error', 'message' => 'Материал не найден']);
        } else {
            echo json_encode(['status' => 'success', 'data' => $mat]);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// API: СОХРАНИТЬ МАТЕРИАЛ
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_material') {
    header('Content-Type: application/json');
    if (!$canEdit) {
        echo json_encode(['status' => 'error', 'message' => 'Недостаточно прав']); exit;
    }

    $matId       = (int)($_POST['material_id'] ?? 0);
    $name        = trim($_POST['name'] ?? '');
    $category    = trim($_POST['category'] ?? '');
    $unitId      = (int)($_POST['default_unit_id'] ?? 0) ?: null;

    if ($name === '') {
        echo json_encode(['status' => 'error', 'message' => 'Укажите наименование материала']); exit;
    }

    // Проверка дубликата по имени
    $dupStmt = $pdo->prepare("SELECT id FROM materials WHERE name = ? AND id != ?");
    $dupStmt->execute([$name, $matId]);
    if ($dupStmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Материал с таким наименованием уже есть в справочнике']); exit;
    }

    try {
        if ($matId > 0) {
            $sql = "UPDATE materials SET name = ?, category = ?, default_unit_id = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$name, $category ?: null, $unitId, $matId]);
            echo json_encode(['status' => 'success', 'id' => $matId, 'mode' => 'update']);
        } else {
            $sql = "INSERT INTO materials (name, category, default_unit_id, created_at) VALUES (?, ?, ?, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$name, $category ?: null, $unitId]);
            $newId = (int)$pdo->lastInsertId();
            echo json_encode(['status' => 'success', 'id' => $newId, 'mode' => 'create']);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// API: УДАЛИТЬ МАТЕРИАЛ
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_material') {
    header('Content-Type: application/json');
    if (!$canEdit) {
        echo json_encode(['status' => 'error', 'message' => 'Недостаточно прав']); exit;
    }

    $matId = (int)($_POST['material_id'] ?? 0);
    if ($matId <= 0) { echo json_encode(['status' => 'error', 'message' => 'Неверный ID']); exit; }

    try {
        // Проверяем, используется ли материал в СЗ
        $check = $pdo->prepare("SELECT COUNT(*) FROM tank_sz_items WHERE material_id = ?");
        $check->execute([$matId]);
        $usage = (int)$check->fetchColumn();

        if ($usage > 0) {
            echo json_encode([
                'status' => 'error',
                'message' => "Нельзя удалить: материал используется в {$usage} служебных записках"
            ]);
            exit;
        }

        $pdo->prepare("DELETE FROM materials WHERE id = ?")->execute([$matId]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// API: СОХРАНИТЬ ЕДИНИЦУ ИЗМЕРЕНИЯ
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_unit') {
    header('Content-Type: application/json');
    if (!$canEdit) {
        echo json_encode(['status' => 'error', 'message' => 'Недостаточно прав']); exit;
    }

    $unitId    = (int)($_POST['unit_id'] ?? 0);
    $name      = trim($_POST['name'] ?? '');
    $shortName = trim($_POST['short_name'] ?? '');

    if ($name === '' || $shortName === '') {
        echo json_encode(['status' => 'error', 'message' => 'Заполните полное и сокращённое название']); exit;
    }

    // Проверка дубликата по сокращению
    $dupStmt = $pdo->prepare("SELECT id FROM units WHERE short_name = ? AND id != ?");
    $dupStmt->execute([$shortName, $unitId]);
    if ($dupStmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Единица с таким сокращением уже существует']); exit;
    }

    try {
        if ($unitId > 0) {
            $stmt = $pdo->prepare("UPDATE units SET name = ?, short_name = ? WHERE id = ?");
            $stmt->execute([$name, $shortName, $unitId]);
            echo json_encode(['status' => 'success', 'id' => $unitId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO units (name, short_name) VALUES (?, ?)");
            $stmt->execute([$name, $shortName]);
            $newId = (int)$pdo->lastInsertId();
            echo json_encode(['status' => 'success', 'id' => $newId]);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// API: УДАЛИТЬ ЕДИНИЦУ
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_unit') {
    header('Content-Type: application/json');
    if (!$canEdit) {
        echo json_encode(['status' => 'error', 'message' => 'Недостаточно прав']); exit;
    }

    $unitId = (int)($_POST['unit_id'] ?? 0);
    if ($unitId <= 0) { echo json_encode(['status' => 'error', 'message' => 'Неверный ID']); exit; }

    try {
        // Проверяем использование
        $check = $pdo->prepare("SELECT COUNT(*) FROM tank_sz_items WHERE unit_id = ?");
        $check->execute([$unitId]);
        $usage = (int)$check->fetchColumn();

        if ($usage > 0) {
            echo json_encode([
                'status' => 'error',
                'message' => "Нельзя удалить: единица используется в {$usage} позициях СЗ"
            ]);
            exit;
        }

        // Проверяем, не является ли единица дефолтной для какого-то материала
        $check2 = $pdo->prepare("SELECT COUNT(*) FROM materials WHERE default_unit_id = ?");
        $check2->execute([$unitId]);
        $matUsage = (int)$check2->fetchColumn();
        if ($matUsage > 0) {
            echo json_encode([
                'status' => 'error',
                'message' => "Нельзя удалить: единица используется по умолчанию у {$matUsage} материалов"
            ]);
            exit;
        }

        $pdo->prepare("DELETE FROM units WHERE id = ?")->execute([$unitId]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// ВЫБОРКА СПИСКОВ
// =========================================================================
$searchQuery = trim($_GET['q'] ?? '');
$filterCat   = trim($_GET['category'] ?? '');

// Материалы
$sql = "SELECT m.*, u.short_name AS unit_short, u.name AS unit_name,
            (SELECT COUNT(*) FROM tank_sz_items WHERE material_id = m.id) AS usage_count
        FROM materials m
        LEFT JOIN units u ON m.default_unit_id = u.id
        WHERE 1=1";
$params = [];
if ($searchQuery !== '') {
    $sql .= " AND m.name LIKE ?";
    $params[] = "%{$searchQuery}%";
}
if ($filterCat !== '') {
    $sql .= " AND m.category = ?";
    $params[] = $filterCat;
}
$sql .= " ORDER BY m.name ASC LIMIT 500";

$materials = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $materials = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Единицы измерения
$units = [];
try {
    $stmt = $pdo->query("SELECT u.*, 
        (SELECT COUNT(*) FROM tank_sz_items WHERE unit_id = u.id) AS usage_count,
        (SELECT COUNT(*) FROM materials WHERE default_unit_id = u.id) AS default_usage
        FROM units u ORDER BY u.name ASC");
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Уникальные категории (для фильтра)
$categories = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT category FROM materials WHERE category IS NOT NULL AND category != '' ORDER BY category");
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$tab = $_GET['tab'] ?? 'materials';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Справочник материалов — Santeks CRM</title>
    <style>
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
        .topbar-actions { display: flex; gap: 10px; }

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
        .btn-sm { padding: 5px 12px; font-size: 12px; }
        .btn-danger { background: rgba(239,68,68,0.1); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }
        .btn-danger:hover { background: #ef4444; color: #fff; }

        /* Вкладки */
        .tabs-bar {
            display: flex; gap: 4px;
            border-bottom: 2px solid #26263a;
            margin-bottom: 8px;
        }
        .tab-item {
            padding: 12px 22px;
            color: #92929f; text-decoration: none;
            font-size: 13px; font-weight: 600;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
            display: flex; align-items: center; gap: 8px;
            margin-bottom: -2px;
        }
        .tab-item:hover { color: #fff; }
        .tab-item.active { color: #fff; border-bottom-color: #4f46e5; }
        .tab-item .badge {
            background: #26263a; padding: 2px 8px; border-radius: 10px;
            font-size: 11px; font-family: monospace;
        }
        .tab-item.active .badge { background: #4f46e5; }

        .filter-bar { background: #1e1e2d; border: 1px solid #323248; border-radius: 12px; padding: 14px 20px; }
        .filter-form { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 14px; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; min-width: 160px; flex: 1; max-width: 280px; }
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

        .table-wrapper { background: #1a1a28; border: 1px solid #323248; border-radius: 14px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 700px; }
        thead th {
            background: #242438; color: #92929f;
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
            padding: 14px 12px; text-align: left;
            border-bottom: 2px solid #323248; white-space: nowrap;
        }
        tbody td { padding: 12px; border-bottom: 1px solid #26263a; color: #cbd5e1; vertical-align: middle; }
        tbody tr:hover td { background: #1e1e32; }

        .empty-state { text-align: center; padding: 60px 20px; color: #4b4b5e; font-size: 14px; }
        .empty-state .big-icon { font-size: 56px; margin-bottom: 12px; opacity: 0.3; }

        .cat-badge {
            display: inline-block; padding: 3px 10px; border-radius: 12px;
            background: rgba(129,140,248,0.1); color: #818cf8;
            border: 1px solid rgba(129,140,248,0.25);
            font-size: 11px; font-weight: 600;
        }
        .usage-badge {
            display: inline-block; padding: 2px 8px; border-radius: 10px;
            background: #26263a; color: #cbd5e1;
            font-family: monospace; font-size: 11px;
        }

        /* Модалки */
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
            max-width: 560px; width: 100%; max-height: 92vh; overflow-y: auto;
            box-shadow: 0 25px 60px rgba(0,0,0,0.5);
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            border-bottom: 1px solid #323248; padding-bottom: 14px; margin-bottom: 20px;
        }
        .modal-header h2 { margin: 0; font-size: 19px; }
        .modal-close { background: none; border: none; color: #92929f; font-size: 26px; cursor: pointer; }
        .modal-close:hover { color: #ef4444; }

        .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
        .form-group label {
            font-size: 11px; font-weight: 700; color: #92929f;
            text-transform: uppercase; letter-spacing: 0.3px;
        }
        .form-group input, .form-group select {
            width: 100%; padding: 10px 14px; background: #151521;
            border: 1px solid #323248; border-radius: 8px; color: #fff;
            font-size: 13px; outline: none; font-family: inherit;
        }
        .form-group input:focus, .form-group select:focus { border-color: #4f46e5; }
        .form-group input.invalid { border-color: #ef4444; }
        .form-group input.valid { border-color: #10b981; }
        .field-error { font-size: 11px; color: #ef4444; font-weight: 600; display: none; }
        .field-error.show { display: block; }

        .modal-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 20px; border-top: 1px solid #323248; padding-top: 16px; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <main>
        <div class="topbar">
            <h1>📚 Справочник материалов и единиц измерения</h1>
            <div class="topbar-actions">
                <?php if ($canEdit): ?>
                    <?php if ($tab === 'materials'): ?>
                        <button class="btn btn-primary" onclick="openMaterialModal()">➕ Новый материал</button>
                    <?php else: ?>
                        <button class="btn btn-primary" onclick="openUnitModal()">➕ Новая единица</button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="tabs-bar">
            <a href="?tab=materials" class="tab-item <?= $tab === 'materials' ? 'active' : '' ?>">
                🧱 Материалы <span class="badge"><?= count($materials) ?></span>
            </a>
            <a href="?tab=units" class="tab-item <?= $tab === 'units' ? 'active' : '' ?>">
                📏 Единицы измерения <span class="badge"><?= count($units) ?></span>
            </a>
        </div>

        <?php if ($tab === 'materials'): ?>
            <div class="filter-bar">
                <form method="GET" class="filter-form">
                    <input type="hidden" name="tab" value="materials">
                    <div class="filter-group" style="flex:2; max-width:340px;">
                        <label>Поиск по названию</label>
                        <input type="text" name="q" value="<?= rvsEscape($searchQuery) ?>" placeholder="Например: уголок, лист, труба">
                    </div>
                    <div class="filter-group">
                        <label>Категория</label>
                        <select name="category">
                            <option value="">Все категории</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= rvsEscape($cat) ?>" <?= $filterCat === $cat ? 'selected' : '' ?>>
                                    <?= rvsEscape($cat) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:flex; gap:10px;">
                        <button type="submit" class="btn btn-primary">🔍 Применить</button>
                        <a href="?tab=materials" class="btn btn-outline">✕ Сброс</a>
                    </div>
                </form>
            </div>

            <div class="table-wrapper">
                <?php if (empty($materials)): ?>
                    <div class="empty-state">
                        <div class="big-icon">📚</div>
                        <div>Материалов пока нет</div>
                        <?php if ($canEdit): ?>
                            <div style="margin-top:16px;">
                                <button class="btn btn-primary" onclick="openMaterialModal()">➕ Добавить первый материал</button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th style="width:50px;">#</th>
                                <th>Наименование материала</th>
                                <th style="width:180px;">Категория</th>
                                <th style="width:140px;">Ед. изм. по умолчанию</th>
                                <th style="width:120px; text-align:center;">Использований</th>
                                <?php if ($canEdit): ?>
                                    <th style="width:130px;">Действия</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1; foreach ($materials as $m): ?>
                            <tr>
                                <td style="color:#6b6b85; font-family:monospace;"><?= $i++ ?></td>
                                <td style="color:#fff; font-weight:600;"><?= rvsEscape($m['name']) ?></td>
                                <td>
                                    <?php if (!empty($m['category'])): ?>
                                        <span class="cat-badge"><?= rvsEscape($m['category']) ?></span>
                                    <?php else: ?>
                                        <span style="color:#4b4b5e;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($m['unit_short'])): ?>
                                        <span style="font-family:monospace; color:#cbd5e1;"><?= rvsEscape($m['unit_short']) ?></span>
                                        <span style="color:#6b6b85; font-size:11px;"> (<?= rvsEscape($m['unit_name']) ?>)</span>
                                    <?php else: ?>
                                        <span style="color:#4b4b5e;">не задана</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <span class="usage-badge"><?= (int)$m['usage_count'] ?></span>
                                </td>
                                <?php if ($canEdit): ?>
                                <td>
                                    <button class="btn btn-outline btn-sm" onclick="openMaterialModal(<?= (int)$m['id'] ?>)">✏️</button>
                                    <button class="btn btn-danger btn-sm" onclick="deleteMaterial(<?= (int)$m['id'] ?>, '<?= addslashes(rvsEscape($m['name'])) ?>', <?= (int)$m['usage_count'] ?>)">✖</button>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- ЕДИНИЦЫ ИЗМЕРЕНИЯ -->
            <div class="table-wrapper">
                <?php if (empty($units)): ?>
                    <div class="empty-state">
                        <div class="big-icon">📏</div>
                        <div>Единиц измерения пока нет</div>
                        <?php if ($canEdit): ?>
                            <div style="margin-top:16px;">
                                <button class="btn btn-primary" onclick="openUnitModal()">➕ Добавить первую единицу</button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th style="width:50px;">#</th>
                                <th>Полное название</th>
                                <th style="width:140px;">Сокращение</th>
                                <th style="width:160px; text-align:center;">В СЗ</th>
                                <th style="width:180px; text-align:center;">У материалов</th>
                                <?php if ($canEdit): ?>
                                    <th style="width:130px;">Действия</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1; foreach ($units as $u): ?>
                            <tr>
                                <td style="color:#6b6b85; font-family:monospace;"><?= $i++ ?></td>
                                <td style="color:#fff; font-weight:600;"><?= rvsEscape($u['name']) ?></td>
                                <td style="font-family:monospace; color:#818cf8; font-weight:700;"><?= rvsEscape($u['short_name']) ?></td>
                                <td style="text-align:center;"><span class="usage-badge"><?= (int)$u['usage_count'] ?></span></td>
                                <td style="text-align:center;"><span class="usage-badge"><?= (int)$u['default_usage'] ?></span></td>
                                <?php if ($canEdit): ?>
                                <td>
                                    <button class="btn btn-outline btn-sm" onclick='openUnitModal(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>✏️</button>
                                    <button class="btn btn-danger btn-sm" onclick="deleteUnit(<?= (int)$u['id'] ?>, '<?= addslashes(rvsEscape($u['short_name'])) ?>', <?= (int)$u['usage_count'] + (int)$u['default_usage'] ?>)">✖</button>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- ============ МОДАЛКА МАТЕРИАЛА ============ -->
    <div id="materialModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="matModalTitle">➕ Новый материал</h2>
                <button class="modal-close" onclick="closeMaterialModal()">&times;</button>
            </div>
            <form id="materialForm" onsubmit="saveMaterial(event)">
                <input type="hidden" name="action" value="save_material">
                <input type="hidden" name="material_id" id="material_id" value="0">

                <div class="form-group">
                    <label>Наименование материала *</label>
                    <input type="text" name="name" id="mat_name" required autocomplete="off" placeholder="Например: Уголок укороченный 50х50">
                    <div class="field-error" id="mat_name_error">Материал с таким наименованием уже есть</div>
                </div>

                <div class="form-group">
                    <label>Категория</label>
                    <input type="text" name="category" id="mat_category" list="catSuggest" placeholder="Металл, крепёж, ЛКМ..." autocomplete="off">
                    <datalist id="catSuggest">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= rvsEscape($cat) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="form-group">
                    <label>Единица измерения по умолчанию</label>
                    <select name="default_unit_id" id="mat_unit_id">
                        <option value="">— не задана —</option>
                        <?php foreach ($units as $u): ?>
                            <option value="<?= (int)$u['id'] ?>">
                                <?= rvsEscape($u['short_name']) ?> — <?= rvsEscape($u['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="font-size:11px; color:#6b6b85;">При выборе материала в СЗ подставится автоматически.</div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeMaterialModal()">Отмена</button>
                    <button type="submit" class="btn btn-primary" id="matSubmitBtn">💾 Сохранить</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============ МОДАЛКА ЕДИНИЦЫ ============ -->
    <div id="unitModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="unitModalTitle">➕ Новая единица измерения</h2>
                <button class="modal-close" onclick="closeUnitModal()">&times;</button>
            </div>
            <form id="unitForm" onsubmit="saveUnit(event)">
                <input type="hidden" name="action" value="save_unit">
                <input type="hidden" name="unit_id" id="unit_id" value="0">

                <div class="form-group">
                    <label>Полное название *</label>
                    <input type="text" name="name" id="unit_name" required placeholder="Например: Метр квадратный">
                </div>
                <div class="form-group">
                    <label>Сокращение *</label>
                    <input type="text" name="short_name" id="unit_short" required placeholder="Например: м²" maxlength="20">
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeUnitModal()">Отмена</button>
                    <button type="submit" class="btn btn-primary" id="unitSubmitBtn">💾 Сохранить</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // ============================================================
        // МОДАЛКА МАТЕРИАЛА
        // ============================================================
        function openMaterialModal(id = 0) {
            const modal = document.getElementById('materialModal');
            const form = document.getElementById('materialForm');
            form.reset();
            document.getElementById('material_id').value = 0;
            document.getElementById('mat_name_error').classList.remove('show');
            document.getElementById('mat_name').classList.remove('invalid', 'valid');

            if (id > 0) {
                document.getElementById('matModalTitle').innerText = '✏️ Редактирование материала';
                fetch('tank_materials_base.php?action=get_material&id=' + id)
                    .then(r => r.json())
                    .then(data => {
                        if (data.status !== 'success') { alert('Ошибка загрузки'); return; }
                        const m = data.data;
                        document.getElementById('material_id').value = m.id;
                        document.getElementById('mat_name').value = m.name || '';
                        document.getElementById('mat_category').value = m.category || '';
                        document.getElementById('mat_unit_id').value = m.default_unit_id || '';
                        modal.classList.add('active');
                    })
                    .catch(() => alert('Ошибка сети'));
            } else {
                document.getElementById('matModalTitle').innerText = '➕ Новый материал';
                modal.classList.add('active');
            }
        }
        function closeMaterialModal() {
            document.getElementById('materialModal').classList.remove('active');
        }

        let nameCheckTimer;
        document.getElementById('mat_name').addEventListener('input', function() {
            const name = this.value.trim();
            const id = document.getElementById('material_id').value;
            const errEl = document.getElementById('mat_name_error');
            const btn = document.getElementById('matSubmitBtn');
            this.classList.remove('invalid', 'valid');
            errEl.classList.remove('show');
            btn.disabled = false;
            if (name.length < 2) return;

            clearTimeout(nameCheckTimer);
            nameCheckTimer = setTimeout(() => {
                // Простой чек дубликата по имени — сделаем быстрый AJAX
                fetch('tank_sz.php?action=list_materials&q=' + encodeURIComponent(name))
                    .then(r => r.json())
                    .then(data => {
                        if (data.status === 'success') {
                            const dup = data.data.find(m => m.name.toLowerCase() === name.toLowerCase() && String(m.id) !== id);
                            if (dup) {
                                this.classList.add('invalid');
                                errEl.classList.add('show');
                                btn.disabled = true;
                            } else {
                                this.classList.add('valid');
                            }
                        }
                    });
            }, 500);
        });

        async function saveMaterial(e) {
            e.preventDefault();
            const fd = new FormData(e.target);
            const btn = document.getElementById('matSubmitBtn');
            btn.disabled = true;
            const old = btn.innerText;
            btn.innerText = '⏳ Сохранение...';
            try {
                const res = await fetch('tank_materials_base.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    closeMaterialModal();
                    location.reload();
                } else alert('Ошибка: ' + data.message);
            } catch (e) { alert('Ошибка сети'); }
            finally { btn.disabled = false; btn.innerText = old; }
        }

        async function deleteMaterial(id, name, usage) {
            if (usage > 0) {
                alert('Нельзя удалить: материал используется в ' + usage + ' СЗ. Удалите позиции или оставьте материал в справочнике.');
                return;
            }
            if (!confirm('Удалить материал «' + name + '» из справочника?')) return;
            const fd = new FormData();
            fd.append('action', 'delete_material');
            fd.append('material_id', id);
            try {
                const res = await fetch('tank_materials_base.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') location.reload();
                else alert('Ошибка: ' + data.message);
            } catch (e) { alert('Ошибка сети'); }
        }

        // ============================================================
        // МОДАЛКА ЕДИНИЦЫ
        // ============================================================
        function openUnitModal(data = null) {
            const modal = document.getElementById('unitModal');
            const form = document.getElementById('unitForm');
            form.reset();
            document.getElementById('unit_id').value = 0;

            if (data && data.id) {
                document.getElementById('unitModalTitle').innerText = '✏️ Редактирование единицы';
                document.getElementById('unit_id').value = data.id;
                document.getElementById('unit_name').value = data.name || '';
                document.getElementById('unit_short').value = data.short_name || '';
            } else {
                document.getElementById('unitModalTitle').innerText = '➕ Новая единица измерения';
            }
            modal.classList.add('active');
        }
        function closeUnitModal() {
            document.getElementById('unitModal').classList.remove('active');
        }

        async function saveUnit(e) {
            e.preventDefault();
            const fd = new FormData(e.target);
            const btn = document.getElementById('unitSubmitBtn');
            btn.disabled = true;
            const old = btn.innerText;
            btn.innerText = '⏳ Сохранение...';
            try {
                const res = await fetch('tank_materials_base.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    closeUnitModal();
                    location.reload();
                } else alert('Ошибка: ' + data.message);
            } catch (e) { alert('Ошибка сети'); }
            finally { btn.disabled = false; btn.innerText = old; }
        }

        async function deleteUnit(id, shortName, usage) {
            if (usage > 0) {
                alert('Нельзя удалить: единица «' + shortName + '» используется. Освободите связи сначала.');
                return;
            }
            if (!confirm('Удалить единицу «' + shortName + '»?')) return;
            const fd = new FormData();
            fd.append('action', 'delete_unit');
            fd.append('unit_id', id);
            try {
                const res = await fetch('tank_materials_base.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') location.reload();
                else alert('Ошибка: ' + data.message);
            } catch (e) { alert('Ошибка сети'); }
        }

        // ESC
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                closeMaterialModal();
                closeUnitModal();
            }
        });
        document.getElementById('materialModal').addEventListener('click', function(e) {
            if (e.target === this) closeMaterialModal();
        });
        document.getElementById('unitModal').addEventListener('click', function(e) {
            if (e.target === this) closeUnitModal();
        });
    </script>
</body>
</html>