<?php
// top_clients.php — Топ клиентов и менеджеров по отгрузкам (финальная версия)
if (session_status() === PHP_SESSION_NONE) session_start();
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: auth.html");
    exit;
}

$date_from = !empty($_GET['date_from']) ? trim($_GET['date_from']) : date('Y-m-01');
$date_to   = !empty($_GET['date_to']) ? trim($_GET['date_to']) : date('Y-m-t');
$manager_filter = isset($_GET['manager_id']) ? (int)$_GET['manager_id'] : 0;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

$rates = ['BYN' => 1.0, 'RUB' => 0.035, 'USD' => 3.25, 'EUR' => 3.55, 'CNY' => 0.45];

try {
    // 1. Все валюты, присутствующие в отгрузках за период
    $curStmt = $pdo->prepare("SELECT DISTINCT currency FROM project_ttns WHERE ttn_date BETWEEN ? AND ?");
    $curStmt->execute([$date_from, $date_to]);
    $currencies = $curStmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($currencies)) $currencies = ['BYN'];
    usort($currencies, function($a, $b) {
        if ($a === 'BYN') return -1;
        if ($b === 'BYN') return 1;
        return strcmp($a, $b);
    });

    // ============================================================
    // 2. МЕНЕДЖЕРЫ
    // ============================================================
    $sqlManagers = "SELECT 
                        COALESCE(u.login, 'Не указан') AS manager_name,
                        COUNT(DISTINCT c.id) AS client_count,
                        COUNT(t.id) AS ttn_count,
                        t.currency,
                        SUM(t.amount) AS total_currency
                    FROM project_ttns t
                    LEFT JOIN projects p ON t.project_id = p.id
                    LEFT JOIN clients c ON p.client_id = c.id
                    LEFT JOIN users u ON c.manager_id = u.id
                    WHERE t.ttn_date BETWEEN :date_from AND :date_to
                      AND t.id IS NOT NULL
                      " . ($manager_filter > 0 ? " AND c.manager_id = :manager_id" : "") . "
                    GROUP BY u.login, t.currency
                    ORDER BY u.login";

    $stmt = $pdo->prepare($sqlManagers);
    $params = ['date_from' => $date_from, 'date_to' => $date_to];
    if ($manager_filter > 0) $params['manager_id'] = $manager_filter;
    $stmt->execute($params);
    $rowsManagers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $managersData = [];
    foreach ($rowsManagers as $row) {
        $manager = $row['manager_name'] ?? 'Не указан';
        if (!isset($managersData[$manager])) {
            $managersData[$manager] = [
                'client_count' => (int)$row['client_count'],
                'ttn_count' => 0,
                'currencies' => [],
                'total_byn' => 0
            ];
        }
        $currency = $row['currency'] ?? 'BYN';
        $amount = (float)$row['total_currency'];
        $managersData[$manager]['currencies'][$currency] = ($managersData[$manager]['currencies'][$currency] ?? 0) + $amount;
        $managersData[$manager]['ttn_count'] += (int)$row['ttn_count'];
        $rate = isset($rates[$currency]) ? (float)$rates[$currency] : 1.0;
        $managersData[$manager]['total_byn'] += $amount * $rate;
    }
    uasort($managersData, fn($a, $b) => $b['total_byn'] <=> $a['total_byn']);

    // ============================================================
    // 3. КЛИЕНТЫ
    // ============================================================
    $sqlClients = "SELECT 
                        c.id AS client_id,
                        COALESCE(c.client_name, 'Без названия') AS client_name,
                        COALESCE(u.login, 'Не указан') AS manager_name,
                        COUNT(DISTINCT p.id) AS contract_count,
                        COUNT(t.id) AS ttn_count,
                        t.currency,
                        SUM(t.amount) AS total_currency
                    FROM project_ttns t
                    LEFT JOIN projects p ON t.project_id = p.id
                    LEFT JOIN clients c ON p.client_id = c.id
                    LEFT JOIN users u ON c.manager_id = u.id
                    WHERE t.ttn_date BETWEEN :date_from AND :date_to
                      AND t.id IS NOT NULL
                      " . ($manager_filter > 0 ? " AND c.manager_id = :manager_id" : "") . "
                    GROUP BY c.id, t.currency
                    ORDER BY c.id";

    $stmt = $pdo->prepare($sqlClients);
    $stmt->execute($params);
    $rowsClients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $clientsData = [];
    foreach ($rowsClients as $row) {
        $id = (int)$row['client_id'];
        if (!isset($clientsData[$id])) {
            $clientsData[$id] = [
                'client_name' => $row['client_name'] ?? 'Без названия',
                'manager_name' => $row['manager_name'] ?? 'Не указан',
                'contract_count' => (int)$row['contract_count'],
                'ttn_count' => 0,
                'currencies' => [],
                'total_byn' => 0
            ];
        }
        $currency = $row['currency'] ?? 'BYN';
        $amount = (float)$row['total_currency'];
        $clientsData[$id]['currencies'][$currency] = ($clientsData[$id]['currencies'][$currency] ?? 0) + $amount;
        $clientsData[$id]['ttn_count'] += (int)$row['ttn_count'];
        $rate = isset($rates[$currency]) ? (float)$rates[$currency] : 1.0;
        $clientsData[$id]['total_byn'] += $amount * $rate;
    }
    uasort($clientsData, fn($a, $b) => $b['total_byn'] <=> $a['total_byn']);
    if ($limit > 0 && $limit < count($clientsData)) {
        $clientsData = array_slice($clientsData, 0, $limit, true);
    }

    $totalAllByn = array_sum(array_column($clientsData, 'total_byn'));

    $managersList = $pdo->query("SELECT id, login FROM users WHERE role = 'manager' ORDER BY login")->fetchAll();

} catch (Exception $e) {
    die("<div style='color:#ef4444; padding:20px;'>Ошибка: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Топ клиентов и менеджеров — Santeks CRM</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { background: #0f0f1a; color: #fff; font-family: 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; display: flex; min-height: 100vh; }
        aside { width: 260px; flex-shrink: 0; background: #1e1e2d; border-right: 1px solid #323248; }
        main { flex: 1; padding: 30px 35px; min-width: 0; box-sizing: border-box; }
        .topbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px; padding: 18px 28px; background: #1e1e2d; border: 1px solid #323248; border-radius: 14px; margin-bottom: 25px; box-shadow: 0 4px 25px rgba(0,0,0,0.3); }
        .topbar h1 { margin:0; font-size:20px; font-weight:700; display:flex; align-items:center; gap:12px; }
        .topbar h1 span { font-size:24px; }
        .filter-form { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
        .filter-form label { font-size:11px; color:#92929f; text-transform:uppercase; font-weight:700; }
        .filter-form input, .filter-form select {
            background:#151521; border:1px solid #323248; border-radius:8px; padding:8px 12px;
            color:#fff; font-size:13px; outline:none; height:38px;
        }
        .filter-form input:focus, .filter-form select:focus { border-color:#4f46e5; }
        .btn-submit { background:#4f46e5; border:none; color:#fff; padding:8px 20px; border-radius:8px; font-weight:700; cursor:pointer; transition:0.2s; height:38px; }
        .btn-submit:hover { background:#6366f1; }
        .btn-reset { background:transparent; border:1px solid #323248; color:#92929f; padding:8px 16px; border-radius:8px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; height:38px; line-height:20px; }
        .btn-reset:hover { background:#2a2a3f; color:#fff; }

        .section-title { color:#818cf8; font-size:16px; font-weight:600; margin:24px 0 12px 0; display:flex; align-items:center; gap:10px; }
        .section-title span { font-size:20px; }

        /* ТАБЛИЦА МЕНЕДЖЕРОВ — БЕЗ ОГРАНИЧЕНИЙ ПО ВЫСОТЕ */
        .table-manager-wrapper {
            background: #1a1a28;
            border: 1px solid #323248;
            border-radius: 14px;
            overflow-x: auto;
            box-shadow: 0 8px 35px rgba(0,0,0,0.4);
            margin-bottom: 25px;
            /* max-height и overflow-y не заданы – таблица растягивается по содержимому */
        }
        .table-manager {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            min-width: 800px;
            background: #1a1a28;
        }

        /* ТАБЛИЦА КЛИЕНТОВ — КОМПАКТНАЯ, С ОГРАНИЧЕНИЕМ ВЫСОТЫ 280px */
        .table-clients-wrapper {
            background: #1a1a28;
            border: 1px solid #323248;
            border-radius: 14px;
            overflow-x: auto;
            overflow-y: auto;
            max-height: 280px;          /* ← УМЕНЬШЕНО ДО 280px, ЧТОБЫ ТАБЛИЦА КЛИЕНТОВ БЫЛА МЕНЬШЕ */
            box-shadow: 0 8px 35px rgba(0,0,0,0.4);
            margin-bottom: 25px;
        }
        .table-clients {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            min-width: 650px;
            background: #1a1a28;
        }

        /* Общие стили заголовков */
        .table-manager th,
        .table-clients th {
            background: #242438;
            padding: 12px 10px;
            color: #9ca3af;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: left;
            border-bottom: 2px solid #323248;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 5;
        }
        .table-manager th:not(:first-child):not(:nth-child(2)),
        .table-clients th:not(:first-child):not(:nth-child(2)) { text-align: right; }
        .table-manager th:first-child,
        .table-clients th:first-child { text-align: center; }

        .table-manager td,
        .table-clients td {
            padding: 10px 10px;
            border-bottom: 1px solid #26263a;
            color: #e2e8f0;
            vertical-align: middle;
        }
        .table-manager td:not(:first-child):not(:nth-child(2)),
        .table-clients td:not(:first-child):not(:nth-child(2)) { text-align: right; }
        .table-manager td:first-child,
        .table-clients td:first-child { text-align: center; }
        .table-manager tbody tr:hover td,
        .table-clients tbody tr:hover td { background: #1e1e32; }

        .client-link {
            color: #818cf8;
            font-weight: 600;
            text-decoration: none;
        }
        .client-link:hover { text-decoration: underline; }

        .currency-amount { font-weight: 600; font-family: monospace; }
        .currency-byn { color: #10b981; }
        .currency-rub { color: #f59e0b; }
        .currency-usd { color: #6366f1; }
        .currency-eur { color: #ec4899; }
        .currency-cny { color: #a855f7; }
        .currency-default { color: #6b6b85; }
        .total-byn { font-weight: 700; color: #10b981; font-size: 14px; }
        .top-number { color: #6b6b85; font-weight: 600; font-size: 14px; }

        .empty-state { text-align: center; padding: 40px; color: #4b4b5e; }
        .empty-state h3 { margin-bottom: 10px; }
        .footer-panel {
            background: #1a1a28;
            border: 1px solid #323248;
            border-radius: 14px;
            padding: 18px 28px;
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .footer-panel .label { font-size:11px; color:#92929f; text-transform:uppercase; font-weight:700; }
        .footer-panel .value { font-size:20px; font-weight:800; color:#10b981; font-family:monospace; background:#0f0f1a; padding:8px 20px; border-radius:10px; border:1px solid #26263a; }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<main>
    <div class="topbar">
        <h1><span>🏆</span> Топ клиентов и менеджеров по отгрузкам</h1>
        <form method="GET" class="filter-form">
            <label>Период:</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
            <label>Менеджер:</label>
            <select name="manager_id">
                <option value="0">Все</option>
                <?php foreach ($managersList as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= $manager_filter == $m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['login']) ?></option>
                <?php endforeach; ?>
            </select>
            <label>Топ клиентов:</label>
            <select name="limit">
                <option value="10"  <?= $limit == 10 ? 'selected' : '' ?>>10</option>
                <option value="20"  <?= $limit == 20 ? 'selected' : '' ?>>20</option>
                <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                <option value="100"<?= $limit == 100 ? 'selected' : '' ?>>100</option>
                <option value="200"<?= $limit == 200 ? 'selected' : '' ?>>200</option>
                <option value="500"<?= $limit == 500 ? 'selected' : '' ?>>500</option>
                <option value="999999"<?= $limit == 999999 ? 'selected' : '' ?>>Все</option>
            </select>
            <button type="submit" class="btn-submit">Показать</button>
            <a href="?" class="btn-reset">Сбросить</a>
        </form>
    </div>

    <!-- ТАБЛИЦА МЕНЕДЖЕРОВ (БЕЗ СКРОЛЛА, ВСЯ ВИДНА) -->
    <?php if (!empty($managersData)): ?>
        <div class="section-title"><span>👔</span> Топ менеджеров по сумме отгрузок</div>
        <div class="table-manager-wrapper">
            <table class="table-manager">
                <thead>
                    <tr>
                        <th style="width:40px;">#</th>
                        <th style="min-width:160px;">Менеджер</th>
                        <th style="width:80px; text-align:center;">Клиентов</th>
                        <th style="width:80px; text-align:center;">ТТН</th>
                        <?php foreach ($currencies as $cur): ?>
                            <th style="min-width:100px; text-align:right;"><?= htmlspecialchars($cur) ?></th>
                        <?php endforeach; ?>
                        <th style="min-width:120px; text-align:right;">Итого BYN</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $rankM = 1; foreach ($managersData as $managerName => $data): ?>
                    <tr>
                        <td class="top-number"><?= $rankM++ ?></td>
                        <td style="color:#a855f7; font-weight:600;">👤 <?= htmlspecialchars($managerName) ?></td>
                        <td style="text-align:center;"><?= $data['client_count'] ?></td>
                        <td style="text-align:center;"><?= $data['ttn_count'] ?></td>
                        <?php foreach ($currencies as $cur): ?>
                            <td class="currency-amount <?= $cur === 'BYN' ? 'currency-byn' : ($cur === 'RUB' ? 'currency-rub' : 'currency-default') ?>">
                                <?php
                                $amount = $data['currencies'][$cur] ?? 0;
                                echo number_format($amount, 2, '.', ' ');
                                ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="currency-amount total-byn"><?= number_format($data['total_byn'], 2, '.', ' ') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- ТАБЛИЦА КЛИЕНТОВ (КОМПАКТНАЯ, МАКСИМУМ 280px) -->
    <div class="section-title"><span>🏢</span> Топ клиентов по отгрузкам</div>
    <?php if (empty($clientsData)): ?>
        <div class="empty-state"><h3>📭 За выбранный период отгрузок не найдено</h3><p>Попробуйте изменить даты или проверьте наличие ТТН.</p></div>
    <?php else: ?>
        <div class="table-clients-wrapper">
            <table class="table-clients">
                <thead>
                    <tr>
                        <th style="width:30px;">#</th>
                        <th style="min-width:140px;">Клиент</th>
                        <th style="width:110px;">Менеджер</th>
                        <th style="width:50px; text-align:center;">Дог.</th>
                        <th style="width:50px; text-align:center;">ТТН</th>
                        <?php foreach ($currencies as $cur): ?>
                            <th style="min-width:80px; text-align:right;"><?= htmlspecialchars($cur) ?></th>
                        <?php endforeach; ?>
                        <th style="min-width:100px; text-align:right;">Итого BYN</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $rank = 1; foreach ($clientsData as $id => $client): ?>
                    <tr>
                        <td class="top-number"><?= $rank++ ?></td>
                        <td>
                            <a href="#" onclick="openClientCard(<?= (int)$id ?>); return false;" class="client-link">
                                🏢 <?= htmlspecialchars($client['client_name']) ?>
                            </a>
                        </td>
                        <td style="color:#a855f7;">👤 <?= htmlspecialchars($client['manager_name']) ?></td>
                        <td style="text-align:center;"><?= $client['contract_count'] ?></td>
                        <td style="text-align:center;"><?= $client['ttn_count'] ?></td>
                        <?php foreach ($currencies as $cur): ?>
                            <td class="currency-amount <?= $cur === 'BYN' ? 'currency-byn' : ($cur === 'RUB' ? 'currency-rub' : 'currency-default') ?>">
                                <?php
                                $amount = $client['currencies'][$cur] ?? 0;
                                echo number_format($amount, 2, '.', ' ');
                                ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="currency-amount total-byn"><?= number_format($client['total_byn'], 2, '.', ' ') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="footer-panel">
            <span class="label">💰 Общая сумма по всем клиентам (в BYN)</span>
            <span class="value"><?= number_format($totalAllByn, 2, '.', ' ') ?> BYN</span>
        </div>
    <?php endif; ?>
</main>

<script>
if (typeof openClientCard !== 'function') {
    window.openClientCard = function(clientId) {
        window.location.href = 'index.php?open_card=' + clientId;
    };
}
</script>
</body>
</html>