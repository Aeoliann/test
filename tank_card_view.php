<?php
/**
 * tank_card_view.php — Просмотр карточки резервуара + прогресс-бар жизненного цикла
 * 
 * Вкладки: Обзор | КД | СЗ | Производство | Монтаж | Поручения | Аналитика
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

if (!can('card', 'view')) {
    die('Недостаточно прав для просмотра карточки');
}

$cardId = (int)($_GET['id'] ?? 0);
if ($cardId <= 0) {
    header('Location: tank_cards.php');
    exit;
}

// =========================================================================
// ЗАГРУЗКА КАРТОЧКИ
// =========================================================================
try {
    $stmt = $pdo->prepare("
        SELECT tc.*, 
               c.client_name, c.unp AS client_unp, c.phone AS client_phone, c.email AS client_email,
               u.login AS manager_name,
               p.contract_number AS linked_contract_number,
               p.contract_date AS linked_contract_date,
               p.scan_path AS linked_contract_scan
        FROM tank_cards tc
        LEFT JOIN clients c ON tc.client_id = c.id
        LEFT JOIN users u ON tc.manager_id = u.id
        LEFT JOIN projects p ON tc.contract_id = p.id
        WHERE tc.id = ?
    ");
    $stmt->execute([$cardId]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$card) {
        die('Карточка резервуара не найдена');
    }
} catch (Exception $e) {
    die('Ошибка загрузки: ' . $e->getMessage());
}

// =========================================================================
// ЗАГРУЗКА СВЯЗАННЫХ ДАННЫХ
// =========================================================================

// События жизненного цикла
$events = [];
try {
    $stmt = $pdo->prepare("
        SELECT e.*, u.login AS user_name
        FROM tank_events e
        LEFT JOIN users u ON e.user_id = u.id
        WHERE e.tank_card_id = ?
        ORDER BY e.event_at ASC
    ");
    $stmt->execute([$cardId]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// КД (привязанные)
$kdList = [];
try {
    $stmt = $pdo->prepare("
        SELECT kd.*, u.login AS loaded_by_name
        FROM tank_kd kd
        LEFT JOIN users u ON kd.loaded_by = u.id
        WHERE kd.tank_card_id = ?
        ORDER BY kd.loaded_at DESC
    ");
    $stmt->execute([$cardId]);
    $kdList = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// СЗ
$szList = [];
try {
    $stmt = $pdo->prepare("
        SELECT sz.*, u.login AS created_by_name
        FROM tank_sz sz
        LEFT JOIN users u ON sz.created_by = u.id
        WHERE sz.tank_card_id = ?
        ORDER BY sz.created_at DESC
    ");
    $stmt->execute([$cardId]);
    $szList = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Этапы производства
$productionStages = [];
try {
    $stmt = $pdo->prepare("
        SELECT ps.*, u.login AS responsible_name
        FROM tank_production_stages ps
        LEFT JOIN users u ON ps.responsible_id = u.id
        WHERE ps.tank_card_id = ?
        ORDER BY ps.id ASC
    ");
    $stmt->execute([$cardId]);
    $productionStages = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Этапы монтажа
$installationStages = [];
try {
    $stmt = $pdo->prepare("
        SELECT ist.*, u.login AS responsible_name
        FROM tank_installation_stages ist
        LEFT JOIN users u ON ist.responsible_id = u.id
        WHERE ist.tank_card_id = ?
        ORDER BY ist.id ASC
    ");
    $stmt->execute([$cardId]);
    $installationStages = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Поручения
$tasksList = [];
try {
    $stmt = $pdo->prepare("
        SELECT t.*, u.login AS assigned_name, cu.login AS created_name
        FROM tank_tasks t
        LEFT JOIN users u ON t.assigned_to = u.id
        LEFT JOIN users cu ON t.created_by = cu.id
        WHERE t.tank_card_id = ?
        ORDER BY t.id DESC
    ");
    $stmt->execute([$cardId]);
    $tasksList = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Определяем текущую вкладку
$tab = $_GET['tab'] ?? 'overview';
$allowedTabs = ['overview', 'kd', 'sz', 'production', 'installation', 'tasks', 'analytics'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'overview';

// Прогресс для бар-топа
$stages = rvsStages();
$currentStageIdx = rvsStageIndex($card['status']);
$stageKeys = array_keys($stages);

// Считаем прогресс в %
$progressPercent = (int)round((($currentStageIdx + 1) / count($stages)) * 100);

// Следующее действие — что логично сделать дальше
$nextAction = null;
$nextActionMap = [
    'card_created'        => ['title' => 'Загрузить КД',        'icon' => '📐', 'link' => '?id=' . $cardId . '&tab=kd'],
    'kd_loaded'           => ['title' => 'Создать СЗ',          'icon' => '📋', 'link' => '?id=' . $cardId . '&tab=sz'],
    'sz_created'          => ['title' => 'Проверить склад',     'icon' => '🏪', 'link' => '?id=' . $cardId . '&tab=sz'],
    'warehouse_checked'   => ['title' => 'Закупить материалы',  'icon' => '🛒', 'link' => '?id=' . $cardId . '&tab=sz'],
    'materials_purchased' => ['title' => 'Начать производство', 'icon' => '🏭', 'link' => '?id=' . $cardId . '&tab=production'],
    'in_production'       => ['title' => 'Начать монтаж',       'icon' => '🔧', 'link' => '?id=' . $cardId . '&tab=installation'],
    'in_installation'     => ['title' => 'Завершить проект',    'icon' => '🏁', 'link' => '?id=' . $cardId . '&tab=overview'],
    'completed'           => null,
];
$nextAction = $nextActionMap[$card['status']] ?? null;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?= rvsEscape($card['name']) ?> — Santeks CRM</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #0f0f1a; color: #fff; font-family: 'Segoe UI', Roboto, sans-serif; display: flex; min-height: 100vh; margin: 0; }

        main { flex: 1; padding: 0; min-width: 0; display: flex; flex-direction: column; max-height: 100vh; overflow: hidden; }

        /* ============ ГЛОБАЛЬНЫЙ БАР-ТОП ============ */
        .global-topbar {
            padding: 16px 30px 0 30px;
            background: linear-gradient(180deg, #16162a 0%, #0f0f1a 100%);
            border-bottom: 1px solid #323248;
            flex-shrink: 0;
        }

        .topbar-head {
            display: flex; justify-content: space-between; align-items: flex-start;
            gap: 20px; margin-bottom: 18px; flex-wrap: wrap;
        }
        .topbar-title { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
        .back-link {
            color: #6b6b85; text-decoration: none; font-size: 13px;
            padding: 6px 12px; border-radius: 6px;
            border: 1px solid #323248; transition: all 0.2s;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .back-link:hover { color: #fff; border-color: #4f46e5; background: rgba(79,70,229,0.08); }

        .topbar-title h1 {
            font-size: 22px; font-weight: 700; margin: 0;
            display: flex; align-items: center; gap: 10px;
        }
        .topbar-subtitle {
            color: #92929f; font-size: 13px;
            display: flex; gap: 16px; flex-wrap: wrap;
            margin-top: 6px;
        }
        .topbar-subtitle span { display: inline-flex; align-items: center; gap: 5px; }
        .topbar-subtitle a { color: #818cf8; text-decoration: none; }
        .topbar-subtitle a:hover { text-decoration: underline; }

        .topbar-actions { display: flex; gap: 10px; flex-wrap: wrap; }

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
        .btn-success { background: #10b981; color: #fff; }
        .btn-success:hover { background: #059669; }
        .btn-sm { padding: 6px 14px; font-size: 12px; }

        /* ============ ЛИФЛАЙН-ПРОГРЕСС ============ */
        .lifecycle-bar {
            display: flex; gap: 0; align-items: center;
            padding: 6px 0 20px 0;
            position: relative;
            overflow-x: auto;
        }
        .lifecycle-bar::-webkit-scrollbar { height: 6px; }
        .lifecycle-bar::-webkit-scrollbar-thumb { background: #323248; border-radius: 6px; }

        .lifecycle-step {
            flex: 1; min-width: 120px;
            display: flex; flex-direction: column; align-items: center;
            position: relative;
            padding: 0 6px;
            cursor: default;
        }
        .lifecycle-step .step-dot {
            width: 32px; height: 32px; border-radius: 50%;
            background: #26263a;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px;
            border: 2px solid #323248;
            transition: all 0.3s;
            position: relative; z-index: 2;
        }
        .lifecycle-step .step-label {
            font-size: 11px; color: #6b6b85; font-weight: 600;
            text-align: center; margin-top: 8px;
            text-transform: uppercase; letter-spacing: 0.3px;
            line-height: 1.25;
        }
        .lifecycle-step .step-date {
            font-size: 10px; color: #4b4b5e;
            margin-top: 2px; font-family: monospace;
        }

        /* Соединительная линия */
        .lifecycle-step::before {
            content: '';
            position: absolute; top: 16px;
            left: 0; right: 50%;
            height: 2px; background: #26263a;
            z-index: 1;
        }
        .lifecycle-step::after {
            content: '';
            position: absolute; top: 16px;
            left: 50%; right: 0;
            height: 2px; background: #26263a;
            z-index: 1;
        }
        .lifecycle-step:first-child::before { display: none; }
        .lifecycle-step:last-child::after { display: none; }

        /* Пройденные шаги */
        .lifecycle-step.done .step-dot {
            background: var(--stage-color, #4f46e5);
            border-color: var(--stage-color, #4f46e5);
            color: #fff;
            box-shadow: 0 0 12px var(--stage-color, #4f46e5);
        }
        .lifecycle-step.done .step-label { color: #cbd5e1; }
        .lifecycle-step.done::before,
        .lifecycle-step.done::after {
            background: var(--stage-color, #4f46e5);
            opacity: 0.6;
        }

        /* Текущий шаг — пульсирует */
        .lifecycle-step.current .step-dot {
            animation: pulse-stage 2s infinite;
            transform: scale(1.1);
        }
        @keyframes pulse-stage {
            0%, 100% { box-shadow: 0 0 12px var(--stage-color, #4f46e5); }
            50% { box-shadow: 0 0 20px var(--stage-color, #4f46e5), 0 0 30px var(--stage-color, #4f46e5); }
        }
        .lifecycle-step.current .step-label {
            color: var(--stage-color, #4f46e5);
            font-weight: 800;
        }

        /* ============ ВКЛАДКИ ============ */
        .tabs-bar {
            padding: 0 30px;
            background: #0f0f1a;
            border-bottom: 1px solid #323248;
            display: flex; gap: 2px;
            overflow-x: auto;
            flex-shrink: 0;
        }
        .tabs-bar::-webkit-scrollbar { height: 4px; }
        .tabs-bar::-webkit-scrollbar-thumb { background: #323248; }

        .tab-item {
            padding: 14px 20px;
            color: #92929f; text-decoration: none;
            font-size: 13px; font-weight: 600;
            border-bottom: 3px solid transparent;
            white-space: nowrap;
            transition: all 0.2s;
            display: flex; align-items: center; gap: 6px;
        }
        .tab-item:hover { color: #fff; background: rgba(255,255,255,0.02); }
        .tab-item.active {
            color: #fff;
            border-bottom-color: #4f46e5;
        }
        .tab-badge {
            background: #26263a; color: #cbd5e1;
            font-size: 10px; font-weight: 700;
            padding: 2px 7px; border-radius: 10px;
            font-family: monospace;
        }
        .tab-item.active .tab-badge { background: #4f46e5; color: #fff; }

        /* ============ КОНТЕНТ ============ */
        .content-area {
            flex: 1; overflow-y: auto;
            padding: 25px 30px;
        }
        .content-area::-webkit-scrollbar { width: 8px; }
        .content-area::-webkit-scrollbar-thumb { background: #26263a; border-radius: 8px; }
        .content-area::-webkit-scrollbar-thumb:hover { background: #4f46e5; }

        /* Карточки информации */
        .info-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px; margin-bottom: 24px;
        }
        .info-card {
            background: #1a1a28; border: 1px solid #26263a;
            border-radius: 12px; padding: 18px 20px;
        }
        .info-card-title {
            font-size: 11px; font-weight: 700; color: #92929f;
            text-transform: uppercase; letter-spacing: 0.5px;
            margin-bottom: 10px;
            display: flex; align-items: center; gap: 6px;
        }
        .info-card-value {
            font-size: 15px; color: #fff; font-weight: 600;
            line-height: 1.4; word-break: break-word;
        }
        .info-card-value.mono { font-family: monospace; font-size: 14px; }
        .info-card-value.dim { color: #6b6b85; font-style: italic; font-weight: 400; }
        .info-card-value a { color: #818cf8; text-decoration: none; }
        .info-card-value a:hover { text-decoration: underline; }

        /* Секции */
        .section {
            background: #1a1a28; border: 1px solid #26263a;
            border-radius: 12px; margin-bottom: 20px; overflow: hidden;
        }
        .section-header {
            padding: 16px 20px;
            background: #1e1e2d;
            border-bottom: 1px solid #26263a;
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 10px;
        }
        .section-title {
            font-size: 14px; font-weight: 700; color: #fff;
            display: flex; align-items: center; gap: 8px;
        }
        .section-body { padding: 20px; }

        /* Empty / заглушка */
        .coming-soon {
            text-align: center; padding: 60px 20px;
            color: #6b6b85;
        }
        .coming-soon .big-icon {
            font-size: 56px; margin-bottom: 16px; opacity: 0.4;
        }
        .coming-soon h3 { color: #cbd5e1; font-size: 18px; margin-bottom: 8px; }
        .coming-soon p { font-size: 13px; max-width: 460px; margin: 0 auto; line-height: 1.6; }

        /* Список КД/СЗ */
        .items-list { display: flex; flex-direction: column; gap: 10px; }
        .item-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 12px 16px; background: #151521;
            border: 1px solid #26263a; border-radius: 8px;
            gap: 12px;
        }
        .item-row:hover { border-color: #323248; }
        .item-info { flex: 1; min-width: 0; }
        .item-title { color: #fff; font-weight: 600; font-size: 13px; margin-bottom: 3px; }
        .item-meta { color: #6b6b85; font-size: 11px; display: flex; gap: 12px; flex-wrap: wrap; }
        .item-actions { display: flex; gap: 6px; }

        /* Таймлайн событий */
        .timeline { position: relative; padding-left: 30px; }
        .timeline::before {
            content: ''; position: absolute; left: 9px; top: 8px; bottom: 8px;
            width: 2px; background: #26263a;
        }
        .timeline-event {
            position: relative; padding: 8px 0 14px 0;
        }
        .timeline-event::before {
            content: ''; position: absolute;
            left: -25px; top: 14px;
            width: 10px; height: 10px;
            border-radius: 50%;
            background: #4f46e5;
            border: 2px solid #0f0f1a;
            box-shadow: 0 0 8px #4f46e5;
        }
        .timeline-event-time {
            font-size: 11px; color: #6b6b85;
            font-family: monospace; margin-bottom: 2px;
        }
        .timeline-event-title {
            color: #fff; font-size: 13px; font-weight: 600;
        }
        .timeline-event-meta {
            color: #92929f; font-size: 11px; margin-top: 2px;
        }

        /* Плашка "следующее действие" */
        .next-action {
            background: linear-gradient(135deg, rgba(79,70,229,0.15), rgba(129,140,248,0.08));
            border: 1px solid rgba(79,70,229,0.4);
            border-radius: 12px; padding: 16px 20px;
            display: flex; justify-content: space-between; align-items: center;
            gap: 16px; margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .next-action-left { display: flex; align-items: center; gap: 14px; }
        .next-action-icon {
            font-size: 28px; flex-shrink: 0;
        }
        .next-action-label { font-size: 11px; color: #92929f; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; }
        .next-action-text { font-size: 16px; color: #fff; font-weight: 700; margin-top: 3px; }

        /* Хелперы */
        .empty-block {
            color: #4b4b5e; font-size: 13px; padding: 20px;
            text-align: center; border: 1px dashed #26263a; border-radius: 8px;
        }
        .tag {
            display: inline-block; padding: 3px 10px; border-radius: 12px;
            font-size: 11px; font-weight: 700;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <main>
        <!-- ============================================================ -->
        <!-- ГЛОБАЛЬНЫЙ БАР-ТОП С ЖИЗНЕННЫМ ЦИКЛОМ -->
        <!-- ============================================================ -->
        <div class="global-topbar">
            <div class="topbar-head">
                <div>
                    <div class="topbar-title">
                        <a href="tank_cards.php" class="back-link">← Реестр</a>
                        <h1>🛢️ <?= rvsEscape($card['name']) ?></h1>
                        <?= rvsStatusBadge($card['status']) ?>
                    </div>
                    <div class="topbar-subtitle">
                        <?php if (!empty($card['client_name'])): ?>
                            <span>🏢 <a href="index.php?open_client_id=<?= (int)$card['client_id'] ?>" target="_blank"><?= rvsEscape($card['client_name']) ?></a></span>
                        <?php endif; ?>
                        <?php if (!empty($card['contract_number'])): ?>
                            <span>📄 Договор № <?= rvsEscape($card['contract_number']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($card['manager_name'])): ?>
                            <span>👤 <?= rvsEscape($card['manager_name']) ?></span>
                        <?php endif; ?>
                        <span>📅 Создано: <?= rvsFormatDateTime($card['created_at']) ?></span>
                        <span style="color:#4f46e5; font-weight:700;">📊 Прогресс: <?= $progressPercent ?>%</span>
                    </div>
                </div>
                <div class="topbar-actions">
                    <?php if ($nextAction): ?>
                        <a href="<?= rvsEscape($nextAction['link']) ?>" class="btn btn-success">
                            <?= $nextAction['icon'] ?> <?= rvsEscape($nextAction['title']) ?> →
                        </a>
                    <?php endif; ?>
                    <?php if (can('card', 'edit')): ?>
                        <a href="tank_cards.php?edit=<?= (int)$card['id'] ?>" class="btn btn-outline">✏️ Редактировать</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ЛИФЛАЙН -->
            <div class="lifecycle-bar">
                <?php 
                $eventByType = [];
                foreach ($events as $ev) {
                    $eventByType[$ev['event_type']] = $ev;
                }
                // Маппинг стадий → типы событий
                $stageEventMap = [
                    'card_created'        => 'card_created',
                    'kd_loaded'           => 'kd_loaded',
                    'sz_created'          => 'sz_materials_created',
                    'warehouse_checked'   => 'warehouse_checked',
                    'materials_purchased' => 'materials_purchased',
                    'in_production'       => 'production_started',
                    'in_installation'     => 'installation_started',
                    'completed'           => 'project_completed',
                ];

                $i = 0;
                foreach ($stages as $key => $stage):
                    $isDone = $i <= $currentStageIdx;
                    $isCurrent = $i === $currentStageIdx;
                    $cls = $isDone ? 'done' : '';
                    if ($isCurrent) $cls .= ' current';

                    $eventType = $stageEventMap[$key] ?? null;
                    $eventDate = null;
                    if ($eventType && isset($eventByType[$eventType])) {
                        $eventDate = date('d.m.Y', strtotime($eventByType[$eventType]['event_at']));
                    }
                ?>
                <div class="lifecycle-step <?= $cls ?>" style="--stage-color: <?= $stage['color'] ?>;">
                    <div class="step-dot"><?= $stage['icon'] ?></div>
                    <div class="step-label"><?= rvsEscape($stage['title']) ?></div>
                    <?php if ($eventDate): ?>
                        <div class="step-date"><?= $eventDate ?></div>
                    <?php endif; ?>
                </div>
                <?php $i++; endforeach; ?>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- ВКЛАДКИ -->
        <!-- ============================================================ -->
        <div class="tabs-bar">
            <?php 
            $tabs = [
                'overview'     => ['icon' => '📋', 'title' => 'Обзор',        'count' => null],
                'kd'           => ['icon' => '📐', 'title' => 'КД',           'count' => count($kdList)],
                'sz'           => ['icon' => '📄', 'title' => 'Служебные записки', 'count' => count($szList)],
                'production'   => ['icon' => '🏭', 'title' => 'Производство', 'count' => count($productionStages)],
                'installation' => ['icon' => '🔧', 'title' => 'Монтаж',       'count' => count($installationStages)],
                'tasks'        => ['icon' => '✔️', 'title' => 'Поручения',    'count' => count($tasksList)],
                'analytics'    => ['icon' => '📊', 'title' => 'Аналитика',    'count' => null],
            ];
            foreach ($tabs as $tabKey => $tabInfo):
                $isActive = $tab === $tabKey;
            ?>
                <a href="?id=<?= $cardId ?>&tab=<?= $tabKey ?>" class="tab-item <?= $isActive ? 'active' : '' ?>">
                    <span><?= $tabInfo['icon'] ?></span>
                    <span><?= rvsEscape($tabInfo['title']) ?></span>
                    <?php if ($tabInfo['count'] !== null && $tabInfo['count'] > 0): ?>
                        <span class="tab-badge"><?= (int)$tabInfo['count'] ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- ============================================================ -->
        <!-- КОНТЕНТ ВКЛАДОК -->
        <!-- ============================================================ -->
        <div class="content-area">

            <?php if ($tab === 'overview'): ?>
                <!-- ========== ОБЗОР ========== -->

                <?php if ($nextAction): ?>
                    <div class="next-action">
                        <div class="next-action-left">
                            <div class="next-action-icon"><?= $nextAction['icon'] ?></div>
                            <div>
                                <div class="next-action-label">Следующее действие</div>
                                <div class="next-action-text"><?= rvsEscape($nextAction['title']) ?></div>
                            </div>
                        </div>
                        <a href="<?= rvsEscape($nextAction['link']) ?>" class="btn btn-primary">Перейти →</a>
                    </div>
                <?php endif; ?>

                <div class="info-grid">
                    <div class="info-card">
                        <div class="info-card-title">🏢 Клиент</div>
                        <div class="info-card-value">
                            <?php if (!empty($card['client_name'])): ?>
                                <a href="index.php?open_client_id=<?= (int)$card['client_id'] ?>" target="_blank">
                                    <?= rvsEscape($card['client_name']) ?>
                                </a>
                                <?php if (!empty($card['client_unp'])): ?>
                                    <div style="font-size:12px; color:#6b6b85; margin-top:4px; font-family:monospace;">
                                        УНП: <?= rvsEscape($card['client_unp']) ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="dim">Не указан</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="info-card-title">📄 Договор</div>
                        <div class="info-card-value">
                            <?php if (!empty($card['contract_number'])): ?>
                                № <?= rvsEscape($card['contract_number']) ?>
                                <?php if (!empty($card['contract_date'])): ?>
                                    <div style="font-size:12px; color:#6b6b85; margin-top:4px;">
                                        от <?= rvsFormatDate($card['contract_date']) ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="dim">Не привязан</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="info-card-title">👤 Менеджер проекта</div>
                        <div class="info-card-value">
                            <?= rvsEscape($card['manager_name'] ?: '—') ?>
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="info-card-title">📅 Дата создания</div>
                        <div class="info-card-value mono"><?= rvsFormatDateTime($card['created_at']) ?></div>
                    </div>
                </div>

                <!-- Скан договора -->
                <?php if (!empty($card['contract_file']) || !empty($card['linked_contract_scan'])): 
                    $scanFile = $card['contract_file'] ?: $card['linked_contract_scan'];
                ?>
                <div class="section">
                    <div class="section-header">
                        <div class="section-title">📎 Скан договора</div>
                    </div>
                    <div class="section-body">
                        <a href="<?= rvsEscape($scanFile) ?>" target="_blank" class="btn btn-outline">
                            📄 Открыть скан
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Таймлайн событий -->
                <div class="section">
                    <div class="section-header">
                        <div class="section-title">⏱ История событий</div>
                        <span style="color:#6b6b85; font-size:12px;"><?= count($events) ?> записей</span>
                    </div>
                    <div class="section-body">
                        <?php if (empty($events)): ?>
                            <div class="empty-block">Пока нет событий</div>
                        <?php else: ?>
                            <div class="timeline">
                                <?php foreach ($events as $ev): 
                                    $evTitle = $stages[$stageEventMapReverse[$ev['event_type']] ?? ''] ?? null;
                                    $eventLabels = [
                                        'card_created'          => ['📝', 'Карточка создана'],
                                        'card_updated'          => ['✏️', 'Карточка отредактирована'],
                                        'kd_loaded'             => ['📐', 'КД загружена'],
                                        'sz_materials_created'  => ['📋', 'Создана СЗ на материалы'],
                                        'sz_production_created' => ['📋', 'Создана СЗ на производство'],
                                        'warehouse_checked'     => ['🏪', 'Склад проверил наличие'],
                                        'materials_purchased'   => ['🛒', 'Материалы закуплены'],
                                        'production_started'    => ['🏭', 'Начато производство'],
                                        'production_finished'   => ['✅', 'Производство завершено'],
                                        'installation_started'  => ['🔧', 'Начат монтаж'],
                                        'installation_finished' => ['✅', 'Монтаж завершён'],
                                        'project_completed'     => ['🏁', 'Проект завершён'],
                                    ];
                                    $labelInfo = $eventLabels[$ev['event_type']] ?? ['•', $ev['event_type']];
                                ?>
                                <div class="timeline-event">
                                    <div class="timeline-event-time"><?= rvsFormatDateTime($ev['event_at']) ?></div>
                                    <div class="timeline-event-title">
                                        <?= $labelInfo[0] ?> <?= rvsEscape($labelInfo[1]) ?>
                                    </div>
                                    <?php if (!empty($ev['meta']) || !empty($ev['user_name'])): ?>
                                        <div class="timeline-event-meta">
                                            <?= rvsEscape($ev['user_name'] ?? '') ?>
                                            <?= !empty($ev['meta']) ? ' • ' . rvsEscape($ev['meta']) : '' ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($tab === 'kd'): ?>
    <!-- ========== КД ========== -->
    
    <div class="section">
        <div class="section-header">
            <div class="section-title">📐 Конструкторская документация</div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <?php if (can('kd', 'archive_manage')): ?>
                    <a href="tank_archive.php" class="btn btn-outline btn-sm" target="_blank">📦 Управление архивом</a>
                <?php endif; ?>
                <?php if (can('kd', 'create')): ?>
                    <button class="btn btn-primary btn-sm" onclick="openKdModal()">➕ Загрузить КД</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="section-body">
            <?php if (empty($kdList)): ?>
                <div class="empty-block" style="padding:40px;">
                    <div style="font-size:48px; opacity:0.3; margin-bottom:12px;">📐</div>
                    <div style="font-size:15px; color:#cbd5e1; margin-bottom:6px;">Конструкторская документация ещё не загружена</div>
                    <div style="font-size:12px; color:#6b6b85;">Загрузите КД из архива или разработанный файл</div>
                </div>
            <?php else: ?>
                <div class="items-list">
                    <?php foreach ($kdList as $kd): 
                        $srcLabel = $kd['source_type'] === 'archive' ? '📦 Из архива' : '🆕 Разработка';
                        $srcColor = $kd['source_type'] === 'archive' ? '#f59e0b' : '#10b981';
                    ?>
                    <div class="item-row">
                        <div class="item-info">
                            <div class="item-title">
                                <span style="color:<?= $srcColor ?>; font-size:12px; font-weight:700;"><?= $srcLabel ?></span>
                                <?php if (!empty($kd['original_name'])): ?>
                                    <span style="color:#92929f; font-weight:400; margin-left:8px;">
                                        — <?= rvsEscape($kd['original_name']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="item-meta">
                                <span>📅 <?= rvsFormatDateTime($kd['loaded_at']) ?></span>
                                <span>👤 <?= rvsEscape($kd['loaded_by_name'] ?? '—') ?></span>
                                <?php if (!empty($kd['notes'])): ?>
                                    <span>💬 <?= rvsEscape($kd['notes']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="item-actions">
                            <a href="<?= rvsEscape($kd['file_path']) ?>" target="_blank" class="btn btn-outline btn-sm">📄 Открыть</a>
                            <?php if (can('kd', 'delete')): ?>
                                <button class="btn btn-outline btn-sm" style="color:#ef4444; border-color:#ef444455;"
                                        onclick="deleteKd(<?= (int)$kd['id'] ?>)">✖</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- МОДАЛКА ЗАГРУЗКИ КД -->
    <div id="kdModal" class="modal-overlay-kd" style="display:none;">
        <div class="modal-content-kd">
            <div class="modal-header">
                <h2>📐 Загрузить КД</h2>
                <button class="modal-close" onclick="closeKdModal()">&times;</button>
            </div>
            <form id="kdForm" onsubmit="uploadKd(event)">
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="card_id" value="<?= (int)$card['id'] ?>">

                <div class="form-group">
                    <label>Способ получения КД *</label>
                    <select name="source_type" id="kd_source_type" onchange="toggleKdSource()" required>
                        <option value="developed">🆕 Разработка (загрузить свой файл)</option>
                        <option value="archive">📦 Из архива системы</option>
                    </select>
                </div>

                <!-- Блок для разработки -->
                <div id="kdDevelopedBlock" class="form-group">
                    <label>Файл КД *</label>
                    <input type="file" name="file" id="kd_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.zip"
                           style="padding:8px; background:#151521; border:1px solid #323248; border-radius:8px; color:#cbd5e1; width:100%;">
                    <div class="field-hint">PDF, DOC, DOCX, JPG, PNG, ZIP. Максимум 30 МБ.</div>
                </div>

                <!-- Блок для архива -->
                <div id="kdArchiveBlock" style="display:none;">
                    <div class="form-group">
                        <label>Поиск по архиву</label>
                        <input type="text" id="kd_archive_search" placeholder="Введите название..." oninput="loadArchiveList()">
                    </div>
                    <div class="form-group">
                        <label>Выберите КД из архива *</label>
                        <div id="kd_archive_list" class="archive-list-box">
                            <div class="archive-empty">Введите запрос или оставьте пустым — загрузится весь список</div>
                        </div>
                    </div>
                    <input type="hidden" name="archive_kd_id" id="kd_archive_kd_id" value="0">
                </div>

                <div class="form-group">
                    <label>Примечание (необязательно)</label>
                    <textarea name="notes" rows="2" placeholder="Например: чертёж с изменениями от 14.10"></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeKdModal()">Отмена</button>
                    <button type="submit" class="btn btn-primary" id="kdSubmitBtn">💾 Загрузить</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .modal-overlay-kd {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.75); backdrop-filter: blur(4px);
            justify-content: center; align-items: center;
            z-index: 99999; padding: 20px;
        }
        .modal-content-kd {
            background: #1e1e2d; border: 1px solid #323248;
            border-radius: 16px; padding: 28px;
            max-width: 640px; width: 100%; max-height: 90vh; overflow-y: auto;
            box-shadow: 0 25px 60px rgba(0,0,0,0.5);
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            border-bottom: 1px solid #323248; padding-bottom: 14px; margin-bottom: 20px;
        }
        .modal-header h2 { margin: 0; font-size: 19px; }
        .modal-close {
            background: none; border: none; color: #92929f;
            font-size: 26px; cursor: pointer; line-height: 1;
        }
        .modal-close:hover { color: #ef4444; }
        .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
        .form-group label {
            font-size: 11px; font-weight: 700; color: #92929f;
            text-transform: uppercase; letter-spacing: 0.3px;
        }
        .form-group input[type="text"], .form-group select, .form-group textarea {
            width: 100%; padding: 10px 14px; background: #151521;
            border: 1px solid #323248; border-radius: 8px; color: #fff;
            font-size: 13px; outline: none; font-family: inherit;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: #4f46e5;
        }
        .field-hint { font-size: 11px; color: #6b6b85; }
        .modal-actions {
            display: flex; justify-content: flex-end; gap: 12px;
            margin-top: 18px; border-top: 1px solid #323248; padding-top: 16px;
        }
        .archive-list-box {
            max-height: 240px; overflow-y: auto;
            background: #151521; border: 1px solid #323248;
            border-radius: 8px;
        }
        .archive-empty {
            padding: 20px; color: #6b6b85; font-size: 12px; text-align: center;
        }
        .archive-item {
            padding: 10px 14px; border-bottom: 1px solid #26263a;
            cursor: pointer; transition: background 0.15s;
            display: flex; justify-content: space-between; align-items: center;
            gap: 10px;
        }
        .archive-item:last-child { border-bottom: none; }
        .archive-item:hover { background: #1e1e2d; }
        .archive-item.selected {
            background: rgba(79,70,229,0.15);
            border-left: 3px solid #4f46e5;
        }
        .archive-item-name {
            color: #fff; font-size: 13px; font-weight: 600;
        }
        .archive-item-meta {
            color: #6b6b85; font-size: 11px; margin-top: 2px;
        }
    </style>

    <script>
        function openKdModal() {
            document.getElementById('kdModal').style.display = 'flex';
            document.getElementById('kdForm').reset();
            document.getElementById('kd_archive_kd_id').value = 0;
            document.getElementById('kd_archive_list').innerHTML = '<div class="archive-empty">Введите запрос или оставьте пустым — загрузится весь список</div>';
            toggleKdSource();
        }
        function closeKdModal() {
            document.getElementById('kdModal').style.display = 'none';
        }
        function toggleKdSource() {
            const src = document.getElementById('kd_source_type').value;
            document.getElementById('kdDevelopedBlock').style.display = src === 'developed' ? 'flex' : 'none';
            document.getElementById('kdArchiveBlock').style.display = src === 'archive' ? 'block' : 'none';
            document.getElementById('kd_file').required = src === 'developed';
            if (src === 'archive') loadArchiveList();
        }
        function loadArchiveList() {
            const q = document.getElementById('kd_archive_search').value.trim();
            const list = document.getElementById('kd_archive_list');
            list.innerHTML = '<div class="archive-empty">Загрузка...</div>';
            fetch('tank_kd.php?action=list_archive&q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    if (data.status !== 'success') {
                        list.innerHTML = '<div class="archive-empty">Ошибка: ' + (data.message || '') + '</div>';
                        return;
                    }
                    if (!data.data || data.data.length === 0) {
                        list.innerHTML = '<div class="archive-empty">Ничего не найдено. Обратитесь к конструктору для пополнения архива.</div>';
                        return;
                    }
                    list.innerHTML = '';
                    data.data.forEach(item => {
                        const div = document.createElement('div');
                        div.className = 'archive-item';
                        div.dataset.id = item.id;
                        div.innerHTML = `
                            <div>
                                <div class="archive-item-name">📄 ${escapeHtmlKd(item.name)}</div>
                                <div class="archive-item-meta">
                                    ${item.tank_type ? '🛢 ' + escapeHtmlKd(item.tank_type) + ' • ' : ''}
                                    ${escapeHtmlKd(item.original_name || '')}
                                </div>
                            </div>
                            <div style="color:#6b6b85; font-size:11px;">${escapeHtmlKd((item.uploaded_at || '').substring(0, 10))}</div>
                        `;
                        div.onclick = () => {
                            document.querySelectorAll('.archive-item').forEach(el => el.classList.remove('selected'));
                            div.classList.add('selected');
                            document.getElementById('kd_archive_kd_id').value = item.id;
                        };
                        list.appendChild(div);
                    });
                })
                .catch(err => {
                    list.innerHTML = '<div class="archive-empty">Ошибка соединения</div>';
                });
        }
        async function uploadKd(e) {
            e.preventDefault();
            const form = e.target;
            const fd = new FormData(form);
            const src = document.getElementById('kd_source_type').value;

            if (src === 'archive' && !document.getElementById('kd_archive_kd_id').value) {
                alert('Выберите КД из архива');
                return;
            }

            const btn = document.getElementById('kdSubmitBtn');
            btn.disabled = true;
            const oldText = btn.innerText;
            btn.innerText = '⏳ Загрузка...';

            try {
                const res = await fetch('tank_kd.php', { method: 'POST', body: fd });
                const text = await res.text();
                let data;
                try { data = JSON.parse(text); }
                catch (err) { alert('Сервер вернул не JSON:\n' + text.substring(0, 300)); return; }

                if (data.status === 'success') {
                    closeKdModal();
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
        async function deleteKd(kdId) {
            if (!confirm('Удалить эту КД из карточки резервуара?')) return;
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('kd_id', kdId);
            try {
                const res = await fetch('tank_kd.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') location.reload();
                else alert('Ошибка: ' + data.message);
            } catch (err) { alert('Ошибка сети'); }
        }
        function escapeHtmlKd(s) {
            if (s === null || s === undefined) return '';
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeKdModal();
        });
    </script>
            <?php elseif ($tab === 'sz'): ?>
                <!-- ========== СЛУЖЕБНЫЕ ЗАПИСКИ ========== -->
                <?php if (empty($szList)): ?>
                    <div class="coming-soon">
                        <div class="big-icon">📋</div>
                        <h3>Служебные записки не созданы</h3>
                        <p>Модуль СЗ на материалы и производство — следующий в очереди. Он будет включать цепочку Склад → Снабжение с частичными закупками.</p>
                    </div>
                <?php else: ?>
                    <div class="section">
                        <div class="section-header">
                            <div class="section-title">📋 Служебные записки</div>
                            <span style="color:#6b6b85; font-size:12px;"><?= count($szList) ?> шт.</span>
                        </div>
                        <div class="section-body">
                            <div class="items-list">
                                <?php foreach ($szList as $sz): 
                                    $szInfo = szStatuses()[$sz['status']] ?? ['title' => $sz['status'], 'color' => '#6b6b85'];
                                    $szTypeLabel = $sz['type'] === 'materials' ? '🛒 Материалы' : '🏭 Производство';
                                ?>
                                <div class="item-row">
                                    <div class="item-info">
                                        <div class="item-title"><?= $szTypeLabel ?> • СЗ #<?= (int)$sz['id'] ?></div>
                                        <div class="item-meta">
                                            <span style="color: <?= $szInfo['color'] ?>;"><?= rvsEscape($szInfo['title']) ?></span>
                                            <span>Создана: <?= rvsFormatDateTime($sz['created_at']) ?></span>
                                            <span>Автор: <?= rvsEscape($sz['created_by_name'] ?? '—') ?></span>
                                        </div>
                                    </div>
                                    <div class="item-actions">
                                        <a href="tank_sz_view.php?id=<?= (int)$sz['id'] ?>" class="btn btn-outline btn-sm">Открыть</a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            <?php elseif ($tab === 'production'): ?>
                <!-- ========== ПРОИЗВОДСТВО ========== -->
                <div class="coming-soon">
                    <div class="big-icon">🏭</div>
                    <h3>Модуль «Производство»</h3>
                    <p>Здесь появятся 4 технологических этапа: Подготовительный, Сварка/Эмалирование, Цинкование, Упаковка. С фиксацией дат, ответственных и фактических часов работы мастера.</p>
                </div>

            <?php elseif ($tab === 'installation'): ?>
                <!-- ========== МОНТАЖ ========== -->
                <div class="coming-soon">
                    <div class="big-icon">🔧</div>
                    <h3>Модуль «Монтаж»</h3>
                    <p>Здесь будет фиксация этапов монтажа, ответственных и учёт стоимости работ подрядных организаций.</p>
                </div>

            <?php elseif ($tab === 'tasks'): ?>
                <!-- ========== ПОРУЧЕНИЯ ========== -->
                <?php if (empty($tasksList)): ?>
                    <div class="coming-soon">
                        <div class="big-icon">✔️</div>
                        <h3>Поручений пока нет</h3>
                        <p>Модуль точечных задач для исполнителей — в разработке. Позволит ставить задачи мастерам с дедлайном и статусами «Новая → В работе → Выполнено».</p>
                    </div>
                <?php else: ?>
                    <div class="section">
                        <div class="section-header">
                            <div class="section-title">✔️ Поручения</div>
                        </div>
                        <div class="section-body">
                            <div class="items-list">
                                <?php foreach ($tasksList as $t): ?>
                                <div class="item-row">
                                    <div class="item-info">
                                        <div class="item-title"><?= rvsEscape($t['title']) ?></div>
                                        <div class="item-meta">
                                            <span>Исполнитель: <?= rvsEscape($t['assigned_name'] ?? '—') ?></span>
                                            <span>Дедлайн: <?= rvsFormatDate($t['deadline']) ?></span>
                                            <span>Статус: <?= rvsEscape($t['status']) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            <?php elseif ($tab === 'analytics'): ?>
                <!-- ========== АНАЛИТИКА ========== -->
                <div class="coming-soon">
                    <div class="big-icon">📊</div>
                    <h3>Аналитика по резервуару</h3>
                    <p>Здесь появятся дельты T1–T8 (время между этапами), калькулятор плановой и фактической себестоимости, экспорт отчётов в Excel/PDF.</p>
                </div>

            <?php endif; ?>

        </div>
    </main>

    <script>
        // Обновление страницы при переключении вкладок — просто через ссылки.
        // Ничего дополнительного не нужно.
    </script>
</body>
</html>