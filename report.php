<?php
// report.php — Продуктовый анализ отгрузок (в стиле CRM)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$date_from = !empty($_GET['date_from']) ? trim($_GET['date_from']) : date('Y-m-01');
$date_to   = !empty($_GET['date_to']) ? trim($_GET['date_to']) : date('Y-m-t');
$manager_filter = isset($_GET['manager_id']) ? (int)$_GET['manager_id'] : 0;

// Курсы валют
$rates = [
    'BYN' => 1.0,
    'RUB' => 0.035,
    'USD' => 3.25,
    'EUR' => 3.55,
    'CNY' => 0.45
];

// Нормализация категорий
function normalizeCategory($raw) {
    $raw = trim($raw);
    $map = [
        'агрего'   => 'ЕКМ',
        'ось'      => 'Другое',
        'сантех'   => 'Сантехника',
        'посуд'    => 'Посуда',
        'екм'      => 'ЕКМ',
        'мпду'     => 'МПДУ',
        'резервуар'=> 'Резервуары',
        'уокт'     => 'УОКТ',
        'аругое'   => 'Другое'
    ];
    $lower = mb_strtolower($raw, 'UTF-8');
    foreach ($map as $key => $value) {
        if (strpos($lower, $key) !== false) {
            return $value;
        }
    }
    return mb_convert_case($raw, MB_CASE_TITLE, 'UTF-8');
}

try {
    // 1. Получаем все ТТН за период с привязкой к проектам и менеджерам
    $sql = "SELECT 
                t.id AS ttn_id,
                t.ttn_number,
                t.ttn_date,
                t.amount,
                t.currency,
                t.product_info,
                p.id AS project_id,
                p.contract_number,
                c.id AS client_id,
                c.client_name,
                u.login AS manager_name
            FROM project_ttns t
            LEFT JOIN projects p ON t.project_id = p.id
            LEFT JOIN clients c ON p.client_id = c.id
            LEFT JOIN users u ON c.manager_id = u.id
            WHERE t.ttn_date BETWEEN :date_from AND :date_to
              AND t.id IS NOT NULL
            " . ($manager_filter > 0 ? " AND c.manager_id = :manager_id" : "") . "
            ORDER BY t.id DESC";

    $stmt = $pdo->prepare($sql);
    $params = ['date_from' => $date_from, 'date_to' => $date_to];
    if ($manager_filter > 0) {
        $params['manager_id'] = $manager_filter;
    }
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Группировка по категории, менеджеру, валюте
    $grouped = [];
    foreach ($rows as $row) {
        $category = normalizeCategory($row['product_info'] ?? 'Не указано');
        $currency = strtoupper(trim($row['currency'] ?? 'BYN'));
        $manager = $row['manager_name'] ?? 'Не указан';

        $key = $category . '||' . $currency . '||' . $manager;

        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'category' => $category,
                'currency' => $currency,
                'manager'  => $manager,
                'contracts' => [],
                'ttn_count' => 0,
                'total_amount' => 0,
                'ttn_details' => []
            ];
        }

        $contract = $row['contract_number'] ?? 'Б/Н';
        if (!empty($contract) && !in_array($contract, $grouped[$key]['contracts'])) {
            $grouped[$key]['contracts'][] = $contract;
        }

        $grouped[$key]['ttn_details'][] = [
            'id' => $row['ttn_id'],
            'number' => $row['ttn_number'] ?? '—',
            'date' => $row['ttn_date'] ? date('d.m.Y', strtotime($row['ttn_date'])) : '—',
            'amount' => (float)$row['amount'],
            'currency' => $currency
        ];

        $grouped[$key]['ttn_count']++;
        $grouped[$key]['total_amount'] += (float)$row['amount'];
    }

    // 3. Перегруппировка по категориям
    $categories = [];
    foreach ($grouped as $data) {
        $cat = $data['category'];
        if (!isset($categories[$cat])) {
            $categories[$cat] = [];
        }
        $categories[$cat][] = $data;
    }
    ksort($categories);

    // 4. Глобальный максимум (в BYN) для прогресс-баров
    $globalMax = 0;
    foreach ($grouped as $data) {
        $byn = $data['total_amount'] * ($rates[$data['currency']] ?? 1.0);
        if ($byn > $globalMax) $globalMax = $byn;
    }

    // 5. Итоги по валютам
    $globalTotals = [];
    foreach ($grouped as $data) {
        $cur = $data['currency'];
        $globalTotals[$cur] = ($globalTotals[$cur] ?? 0) + $data['total_amount'];
    }
    uksort($globalTotals, function($a, $b) {
        if ($a === 'BYN') return -1;
        if ($b === 'BYN') return 1;
        return strcmp($a, $b);
    });

    // Список менеджеров для фильтра
    $managers = $pdo->query("SELECT id, login FROM users WHERE role = 'manager' ORDER BY login")->fetchAll();

} catch (Exception $e) {
    die("<div style='color:#ef4444; padding:20px;'>Ошибка: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Продуктовый анализ отгрузок — Santeks CRM</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* ===== ОСНОВНЫЕ СТИЛИ (как в index.php) ===== */
        body {
            background: #0f0f1a;
            color: #fff;
            font-family: 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 0;
            display: flex;
            min-height: 100vh;
        }
        aside { width: 260px; flex-shrink: 0; background: #1e1e2d; border-right: 1px solid #323248; }
        main {
            flex: 1;
            padding: 30px 35px;
            min-width: 0;
            box-sizing: border-box;
            overflow-y: auto;
            max-height: 100vh;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            padding: 18px 28px;
            background: #1e1e2d;
            border: 1px solid #323248;
            border-radius: 14px;
            margin-bottom: 25px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.3);
        }
        .topbar h1 {
            margin:0;
            font-size:20px;
            font-weight:700;
            color:#fff;
            display:flex;
            align-items:center;
            gap:12px;
        }
        .topbar h1 span { font-size:24px; }
        .filter-form {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .filter-form label {
            font-size:11px;
            color:#92929f;
            text-transform:uppercase;
            font-weight:700;
        }
        .filter-form input, .filter-form select {
            background:#151521;
            border:1px solid #323248;
            border-radius:8px;
            padding:8px 12px;
            color:#fff;
            font-size:13px;
            outline:none;
            height:38px;
        }
        .filter-form input:focus, .filter-form select:focus { border-color:#4f46e5; }
        .btn-submit {
            background:#4f46e5;
            border:none;
            color:#fff;
            padding:8px 20px;
            border-radius:8px;
            font-weight:700;
            cursor:pointer;
            transition:0.2s;
            height:38px;
        }
        .btn-submit:hover { background:#6366f1; }
        .btn-reset {
            background:transparent;
            border:1px solid #323248;
            color:#92929f;
            padding:8px 16px;
            border-radius:8px;
            font-weight:600;
            cursor:pointer;
            text-decoration:none;
            display:inline-block;
            height:38px;
            line-height:20px;
        }
        .btn-reset:hover { background:#2a2a3f; color:#fff; }

        .table-wrapper {
            background: #1a1a28;
            border: 1px solid #323248;
            border-radius: 14px;
            overflow-x: auto;
            box-shadow: 0 8px 35px rgba(0,0,0,0.4);
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            background: #1a1a28;
            min-width: 1100px;
        }
        .data-table th {
            background: #242438;
            color: #92929f;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 14px 12px;
            text-align: left;
            border-bottom: 2px solid #323248;
            white-space: nowrap;
        }
        .data-table td {
            padding: 12px 12px;
            border-bottom: 1px solid #26263a;
            color: #cbd5e1;
            vertical-align: middle;
        }
        .data-table tbody tr:hover td {
            background: #1e1e32;
        }

        .category-label {
            font-weight: 700;
            color: #818cf8;
            font-size: 15px;
            padding: 8px 12px;
            background: #242438;
            border-radius: 6px;
            display: inline-block;
        }
        .category-row td {
            background: #1a1a2a;
            padding: 8px 12px;
            border-bottom: 2px solid #323248;
        }

        .contracts-list {
            font-size: 12px;
            color: #cbd5e1;
        }
        .contracts-list span {
            background: rgba(129,140,248,0.08);
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            color: #818cf8;
            margin-right: 4px;
            display: inline-block;
            margin-bottom: 4px;
        }

        .badge-status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .badge-status.by { background: rgba(16,185,129,0.12); color: #10b981; border:1px solid rgba(16,185,129,0.2); }
        .badge-status.rub { background: rgba(245,158,11,0.12); color: #f59e0b; border:1px solid rgba(245,158,11,0.2); }
        .badge-status.usd { background: rgba(99,102,241,0.12); color: #6366f1; border:1px solid rgba(99,102,241,0.2); }
        .badge-status.eur { background: rgba(236,72,153,0.12); color: #ec4899; border:1px solid rgba(236,72,153,0.2); }
        .badge-status.cny { background: rgba(168,85,247,0.12); color: #a855f7; border:1px solid rgba(168,85,247,0.2); }

        .bar-container {
            background: #151521;
            border-radius: 6px;
            height: 8px;
            overflow: hidden;
            border: 1px solid #2a2a3f;
            width: 100%;
            max-width: 150px;
        }
        .bar-fill {
            height: 100%;
            border-radius: 6px;
            background: linear-gradient(90deg, #4f46e5, #818cf8);
            transition: width 0.3s ease;
        }
        .bar-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .bar-percent {
            font-size: 11px;
            color: #6b6b85;
            min-width: 40px;
            text-align: right;
        }

        .btn-detail-sm {
            background: transparent;
            border: 1px solid #323248;
            color: #92929f;
            padding: 2px 10px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.25s ease;
        }
        .btn-detail-sm:hover {
            border-color: #4f46e5;
            color: #fff;
        }

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
        .footer-panel .label {
            font-size:11px;
            color:#92929f;
            text-transform:uppercase;
            font-weight:700;
        }
        .currency-total-block {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #0f0f1a;
            border-radius: 10px;
            padding: 6px 16px;
            border: 1px solid;
        }
        .currency-total-block .amount {
            font-weight: 700;
            font-family: monospace;
            font-size: 18px;
        }
        .currency-total-block .code {
            font-size: 13px;
            font-weight: 600;
        }

        .modal-overlay {
            display:none;
            position:fixed;
            top:0; left:0;
            width:100%; height:100%;
            background:rgba(0,0,0,0.75);
            justify-content:center;
            align-items:center;
            z-index:99999;
            padding:20px;
            backdrop-filter:blur(4px);
        }
        .modal-content {
            background:#1e1e2d;
            border:1px solid #323248;
            border-radius:16px;
            padding:30px;
            width:600px;
            max-width:100%;
            max-height:90vh;
            overflow-y:auto;
            box-shadow:0 20px 50px rgba(0,0,0,0.5);
        }
        .modal-content h3 { margin-top:0; color:#10b981; border-bottom:1px solid #323248; padding-bottom:12px; }
        .modal-content .ttn-item {
            background:#151521;
            border:1px solid #2a2a3f;
            border-radius:8px;
            padding:10px 14px;
            margin-bottom:8px;
            display:flex;
            justify-content:space-between;
            align-items:center;
        }
        .modal-content .ttn-item .ttn-number { font-weight:600; color:#fff; }
        .modal-content .ttn-item .ttn-date { color:#6b6b85; font-size:12px; }
        .modal-content .ttn-item .ttn-amount { font-weight:700; color:#10b981; font-family:monospace; }
        .modal-close {
            background:#4f46e5;
            border:none;
            color:#fff;
            padding:8px 20px;
            border-radius:8px;
            font-weight:600;
            cursor:pointer;
            margin-top:15px;
        }
        .modal-close:hover { background:#6366f1; }

        @media (max-width: 768px) {
            main { padding: 15px; }
            .topbar { flex-direction: column; align-items: stretch; }
            .filter-form { flex-direction: column; align-items: stretch; }
            .filter-form input, .filter-form select { min-width: 100%; }
            .data-table { min-width: 700px; }
            .bar-container { max-width: 100px; }
        }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<main>
    <div class="topbar">
        <h1><span>📊</span> Продуктовый анализ отгрузок</h1>
        <form method="GET" class="filter-form">
            <label>Период:</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
            <label>Менеджер:</label>
            <select name="manager_id">
                <option value="0">Все</option>
                <?php foreach ($managers as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= $manager_filter == $m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['login']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-submit">Сформировать</button>
            <a href="?" class="btn-reset">Сбросить</a>
        </form>
    </div>

    <?php if (empty($grouped)): ?>
        <div style="text-align:center; padding:50px; color:#4b4b5e;">
            <h2>📭 За выбранный период отгрузок нет</h2>
            <p>Измените даты или проверьте наличие ТТН.</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:40px;">#</th>
                        <th style="min-width:160px;">Категория</th>
                        <th style="min-width:130px;">Менеджер</th>
                        <th style="width:80px; text-align:center;">Валюта</th>
                        <th style="min-width:200px;">Договоры</th>
                        <th style="width:80px; text-align:center;">ТТН</th>
                        <th style="width:150px; text-align:center;">Объём</th>
                        <th style="width:150px; text-align:right;">Сумма</th>
                        <th style="width:110px; text-align:center;">Действие</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $rowNum = 0;
                    foreach ($categories as $categoryName => $items): 
                    ?>
                        <!-- Строка-разделитель категории -->
                        <tr class="category-row">
                            <td colspan="9">
                                <span class="category-label">📂 <?= htmlspecialchars($categoryName) ?></span>
                                <?php
                                    $catTotalByn = 0;
                                    foreach ($items as $item) {
                                        $cur = $item['currency'];
                                        $rate = $rates[$cur] ?? 1.0;
                                        $catTotalByn += $item['total_amount'] * $rate;
                                    }
                                ?>
                                <span style="color:#10b981; font-weight:700; font-family:monospace; margin-left:15px;">
                                    <?= number_format($catTotalByn, 2, '.', ' ') ?> BYN
                                </span>
                            </td>
                        </tr>
                        <?php foreach ($items as $item): 
                            $rowNum++;
                            $bynAmount = $item['total_amount'] * ($rates[$item['currency']] ?? 1.0);
                            $percent = ($globalMax > 0) ? round(($bynAmount / $globalMax) * 100, 1) : 0;
                            $currency = $item['currency'];
                            $badgeClass = 'badge-status';
                            if ($currency === 'BYN') $badgeClass .= ' by';
                            elseif ($currency === 'RUB') $badgeClass .= ' rub';
                            elseif ($currency === 'USD') $badgeClass .= ' usd';
                            elseif ($currency === 'EUR') $badgeClass .= ' eur';
                            elseif ($currency === 'CNY') $badgeClass .= ' cny';
                            else $badgeClass .= '';
                        ?>
                            <tr>
                                <td><?= $rowNum ?></td>
                                <td><span style="color:#818cf8; font-weight:600;"><?= htmlspecialchars($item['category']) ?></span></td>
                                <td style="color:#a855f7;">👤 <?= htmlspecialchars($item['manager']) ?></td>
                                <td style="text-align:center;">
                                    <span class="<?= $badgeClass ?>"><?= $currency ?></span>
                                </td>
                                <td>
                                    <div class="contracts-list">
                                        <?php
                                        $contracts = array_unique($item['contracts']);
                                        foreach ($contracts as $c) {
                                            if (!empty(trim($c))) {
                                                echo '<span>№' . htmlspecialchars(trim($c)) . '</span>';
                                            }
                                        }
                                        if (empty($contracts)) echo '<span style="color:#6b6b85;">—</span>';
                                        ?>
                                    </div>
                                </td>
                                <td style="text-align:center;"><strong><?= $item['ttn_count'] ?></strong></td>
                                <td>
                                    <div class="bar-wrapper">
                                        <div class="bar-container">
                                            <div class="bar-fill" style="width: <?= $percent ?>%;"></div>
                                        </div>
                                        <span class="bar-percent"><?= $percent ?>%</span>
                                    </div>
                                </td>
                                <td style="text-align:right; font-weight:700; font-family:monospace; color:#10b981;">
                                    <?= number_format($item['total_amount'], 2, '.', ' ') ?> <span style="font-size:11px; color:#4b5563;"><?= $currency ?></span>
                                </td>
                                <td style="text-align:center;">
                                    <button class="btn-detail-sm" 
                                            data-ttns='<?= json_encode($item['ttn_details'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>'
                                            data-category="<?= htmlspecialchars($categoryName) ?>"
                                            data-manager="<?= htmlspecialchars($item['manager']) ?>"
                                            onclick="showTtnDetails(this)">
                                        Подробнее
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="footer-panel">
        <span class="label">💰 Глобальный итог отгрузок</span>
        <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: center;">
            <?php
            $currencyColors = [
                'BYN' => '#10b981',
                'RUB' => '#f59e0b',
                'USD' => '#6366f1',
                'EUR' => '#ec4899',
                'CNY' => '#a855f7'
            ];
            foreach ($globalTotals as $code => $total):
                $color = $currencyColors[$code] ?? '#6b6b85';
            ?>
                <div class="currency-total-block" style="border-color: <?= $color ?>40;">
                    <span class="amount" style="color: <?= $color ?>;">
                        <?= number_format($total, 2, '.', ' ') ?>
                    </span>
                    <span class="code" style="color: <?= $color ?>;">
                        <?= $code ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</main>

<!-- Модальное окно ТТН -->
<div id="ttnDetailModal" class="modal-overlay">
    <div class="modal-content">
        <h3 id="ttnModalTitle">📋 ТТН</h3>
        <div id="ttnModalList" style="max-height:400px; overflow-y:auto;"></div>
        <button class="modal-close" onclick="closeTtnDetail()">Закрыть</button>
    </div>
</div>

<script>
function showTtnDetails(btn) {
    const raw = btn.getAttribute('data-ttns');
    const category = btn.getAttribute('data-category');
    const manager = btn.getAttribute('data-manager');
    const modal = document.getElementById('ttnDetailModal');
    const title = document.getElementById('ttnModalTitle');
    const list = document.getElementById('ttnModalList');

    if (!modal || !title || !list) return;

    title.innerText = '📋 ТТН по категории "' + category + '" (менеджер: ' + manager + ')';
    list.innerHTML = '';
    if (!raw) {
        list.innerHTML = '<div style="color:#4b4b5e; padding:20px; text-align:center;">Нет данных</div>';
        modal.style.display = 'flex';
        return;
    }
    try {
        const ttns = JSON.parse(raw);
        if (!Array.isArray(ttns) || ttns.length === 0) {
            list.innerHTML = '<div style="color:#4b4b5e; padding:20px; text-align:center;">Нет данных</div>';
        } else {
            let html = '';
            ttns.forEach(t => {
                const amount = parseFloat(t.amount || 0).toFixed(2);
                const currency = t.currency || 'BYN';
                const number = t.number || '—';
                const date = t.date || '—';
                html += `
                    <div class="ttn-item">
                        <div>
                            <span class="ttn-number">ТТН №${number}</span>
                            <span class="ttn-date">📅 ${date}</span>
                        </div>
                        <div class="ttn-amount">${amount} ${currency}</div>
                    </div>
                `;
            });
            list.innerHTML = html;
        }
    } catch(e) {
        list.innerHTML = '<div style="color:#ef4444; padding:20px; text-align:center;">Ошибка данных</div>';
    }
    modal.style.display = 'flex';
}

function closeTtnDetail() {
    document.getElementById('ttnDetailModal').style.display = 'none';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeTtnDetail();
});
document.getElementById('ttnDetailModal').addEventListener('click', function(e) {
    if (e.target === this) closeTtnDetail();
});
</script>
</body>
</html>