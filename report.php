<?php
// summary_report.php — Сводная матрица эффективности менеджеров по суммам отгрузок
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    die("Доступ запрещен. Авторизуйтесь.");
}

$userRole = $_SESSION['role'] ?? 'manager';

// Перехватываем даты из календарей. По умолчанию — текущий месяц
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : date('Y-m-01');
$dateTo   = isset($_GET['date_to']) ? trim($_GET['date_to']) : date('Y-m-t');

try {
    // ЖЕСТКИЙ АНАЛИТИЧЕСКИЙ SQL-ЗАПРОС: Агрегируем суммы ТТН строго за выбранный период
    $sql = "SELECT 
                u.id AS manager_id,
                u.login AS manager_name,
                COUNT(DISTINCT c.id) AS active_clients_count,
                COUNT(DISTINCT p.id) AS active_contracts_count,
                COUNT(t.id) AS ttn_count_period,
                COALESCE(SUM(t.amount), 0) AS total_amount_period
            FROM users u
            LEFT JOIN clients c ON u.id = c.manager_id
            LEFT JOIN projects p ON c.id = p.client_id
            LEFT JOIN project_ttns t ON p.id = t.project_id 
                AND t.ttn_date >= ? 
                AND t.ttn_date <= ?
            WHERE u.role = 'manager'
            GROUP BY u.id, u.login
            ORDER BY total_amount_period DESC"; // Ранжируем строго по сумме!

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$dateFrom, $dateTo]);
    $matrix = $stmt->fetchAll() ?: [];

} catch (Exception $e) {
    die("Критический сбой построения матрицы: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Сводная матрица отгрузок — Santeks</title>
    <style>
        body { background: #151521; color: #fff; font-family: sans-serif; padding: 25px; margin: 0; }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: #1e1e2d; border: 1px solid #323248; border-radius: 8px; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); }
        .toolbar { display: flex; align-items: flex-end; gap: 15px; margin-bottom: 20px; background: #1a1a24; padding: 15px; border-radius: 6px; border: 1px solid #2b2b40; }
        .t-label { font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; display: block; margin-bottom: 4px; }
        .t-input { height: 38px; padding: 0 10px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; }
        .btn-apply { height: 38px; padding: 0 20px; background: #4f46e5; border: none; color: #fff; border-radius: 6px; font-weight: bold; font-size: 13px; cursor: pointer; transition: 0.15s; }
        .btn-apply:hover { background: #4338ca; }
        .btn-reset { height: 36px; line-height: 36px; padding: 0 15px; background: #242434; border: 1px solid #323248; color: #92929f; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: bold; text-align: center; }
        .btn-reset:hover { color: #fff; background: #2b2b3d; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #242434; padding: 14px 10px; color: #92929f; text-transform: uppercase; font-size: 11px; font-weight: bold; text-align: center; border-bottom: 2px solid #323248; }
        td { padding: 14px 10px; border-bottom: 1px solid #2b2b40; font-size: 14px; text-align: center; background: #1e1e2d; }
        .leader-tr { background: #1a2e26 !important; } /* Подсветка лидера */
    </style>
</head>

<body style="display:flex;">
     <aside>
    <?php include 'sidebar.php'; ?>
   
    </aside>   
<div class="container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h1 style="margin: 0; font-size: 22px; font-weight: bold; letter-spacing: -0.5px;">📊 Сводная матрица коммерческих отгрузок Santeks</h1>
        <a href="index.php" style="color: #818cf8; text-decoration: none; font-weight: bold; font-size: 14px;">← Вернуться в CRM</a>
    </div>

    <!-- ТУЛБАР ФИЛЬТРАЦИИ ПЕРИОДА -->
<form method="GET" action="" class="toolbar" style="display: flex; align-items: flex-end; gap: 15px; margin-bottom: 20px; background: #1a1a24; padding: 15px; border-radius: 6px; border: 1px solid #2b2b40;">
        <div>
            <label class="t-label">Период ТТН с:</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="t-input">
        </div>
        <div>
            <label class="t-label">по:</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="t-input">
        </div>
        <button type="submit" class="btn-apply">🔍 Сформировать матрицу</button>
       <a href="?" class="btn-reset">Сбросить период</a>

    </form>

    <!-- ТАБЛИЦА МАТРИЦЫ -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <table>
            <thead>
                <tr>
                    <th>Место</th>
                    <th style="text-align: left;">Менеджер отдела продаж</th>
                    <th>Всего клиентов</th>
                    <th>Активных договоров</th>
                    <th>Кол-во ТТН за период</th>
                    <th style="text-align: right; color: #10b981;">Сумма отгрузок (BYN)</th>
                    <th style="text-align: right; color: #f59e0b;">Пересчёт (RUB)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                if (!empty($matrix)): 
                    $place = 1;
                    foreach ($matrix as $m): 
                        $sumByn = (float)$m['total_amount_period'];
                        $sumRub = $sumByn * 28.5; // Твой внутренний мультивалютный коэффициент
                        
                        // Первое место подсвечиваем лёгким зелёным оттенком
                        $isLeader = ($place === 1 && $sumByn > 0);
                ?>
                    <tr class="<?= $isLeader ? 'leader-tr' : '' ?>">
                        <td style="font-weight: bold; color: <?= $place === 1 ? '#10b981' : '#64748b' ?>;">
                            <?= $place === 1 ? '🥇 1' : $place ?>
                        </td>
                        <td style="text-align: left; font-weight: bold; color: #fff;">
                            <?= htmlspecialchars($m['manager_name']) ?>
                        </td>
                        <td style="color: #92929f;"><?= (int)$m['active_clients_count'] ?></td>
                        <td style="color: #92929f;"><?= (int)$m['active_contracts_count'] ?></td>
                        <td style="font-weight: 500; color: #e2e8f0;"><?= (int)$m['ttn_count_period'] ?></td>
                        <td style="text-align: right; font-weight: bold; color: #10b981; font-size: 15px;">
                            <?= number_format($sumByn, 2, '.', ' ') ?>
                        </td>
                        <td style="text-align: right; font-weight: bold; color: #f59e0b;">
                            <?= number_format($sumRub, 2, '.', ' ') ?>
                        </td>
                    </tr>
                <?php 
                        $place++;
                    endforeach; 
                else: 
                ?>
                    <tr><td colspan="7" style="padding: 30px; color: #64748b;">Данные для построения отчета отсутствуют.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>