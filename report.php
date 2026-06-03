<?php
// report.php — Глобальный номенклатурный анализ отгрузок Santeks CRM по категориям
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$u_role = $_SESSION['role'] ?? 'manager';

// Временной интервал отгрузок ТТН
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : date('Y-m-01');
$date_to   = isset($_GET['date_to']) ? trim($_GET['date_to']) : date('Y-m-d');

try {
    // ИСПРАВЛЕНО НАМЕРТВО: Берём эталонный тип продукции p.product_type напрямую из договора!
    $sql = "SELECT 
                p.product_type AS product_name,
                u.login AS manager_name,
                COUNT(t.id) AS ttn_count,
                COALESCE(SUM(t.amount), 0) AS total_amount
            FROM project_ttns t
            LEFT JOIN projects p ON t.project_id = p.id
            LEFT JOIN clients c ON p.client_id = c.id
            LEFT JOIN users u ON c.manager_id = u.id
            WHERE t.ttn_date BETWEEN ? AND ? 
              AND p.product_type IS NOT NULL 
              AND p.product_type != ''
            GROUP BY p.product_type, u.id, u.login
            ORDER BY p.product_type ASC, total_amount DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$date_from, $date_to]);
    $raw_matrix = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Пересчитываем глобальный максимум для идеального масштаба шкал-прогрессбаров
    $maxAmount = 1;
    foreach ($raw_matrix as $row) {
        if ((float)$row['total_amount'] > $maxAmount) {
            $maxAmount = (float)$row['total_amount'];
        }
    }

} catch (Exception $e) {
    die("Критический сбой СУБД при расчете матрицы: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Анализ номенклатуры отгрузок — Santeks</title>
    <style>
        body { background: #151521; color: #fff; font-family: 'Segoe UI', sans-serif; padding: 0; margin: 0; display: flex; min-height: 100vh; }
        aside { width: 240px; background: #1e1e2d; border-right: 1px solid #323248; flex-shrink: 0; }
        main { flex: 1; min-width: 0; padding: 40px; box-sizing: border-box; display: flex; flex-direction: column; gap: 24px; }
        .card { background: #1e1e2d; border: 1px solid #323248; border-radius: 12px; padding: 24px; box-shadow: 0 4px 24px rgba(0,0,0,0.4); }
        .filter-panel { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; background: #1e1e2d; border: 1px solid #323248; border-radius: 12px; padding: 16px 24px; }
        .f-input { height: 40px; padding: 0 14px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; font-size: 13px; font-weight: bold; color-scheme: dark; }
        .table-wrapper { border-radius: 12px; border: 1px solid #323248; overflow: hidden; background: #1e1e2d; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin: 0; }
        th { background: #242434; padding: 16px 12px; color: #92929f; text-transform: uppercase; font-size: 11px; font-weight: 700; border-bottom: 2px solid #323248; text-align: center; }
        td { padding: 14px 12px; border-bottom: 1px solid #2b2b40; font-size: 13px; background: #1e1e2d; color: #fff; text-align: center; }
        tr:hover td { background: #242434 !important; }
        .category-header { background: #202030 !important; color: #818cf8 !important; font-weight: bold; text-align: left; padding: 12px 15px; font-size: 14px; border-bottom: 2px solid #323248; }
        .bar-outer { width: 100%; height: 8px; background: #151521; border-radius: 4px; overflow: hidden; margin-top: 6px; border: 1px solid #2b2b40; }
        .bar-inner { height: 100%; background: linear-gradient(90deg, #4f46e5, #818cf8); border-radius: 4px; }
    </style>
</head>
<body>

    <aside>
        <?php include 'sidebar.php'; ?>
    </aside>

    <main>
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <span style="font-size: 26px;">📦</span>
                <h1 style="margin: 0; font-size: 24px; font-weight: bold; letter-spacing: -0.5px;">Глобальный продуктовый анализ отгрузок Santeks</h1>
            </div>
            <a href="index.php" style="color: #818cf8; text-decoration: none; font-size: 13px; font-weight: bold; padding: 8px 16px; background: rgba(129,140,248,0.1); border-radius: 8px;">← Вернуться в CRM</a>
        </div>

        <!-- УПРАВЛЕНИЕ ВРЕМЕННЫМ ПЕРИОДОМ -->
        <form method="GET" action="report.php" class="filter-panel">
            <span style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase;">Период отгрузок ТТН:</span>
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="f-input">
            <span style="color: #64748b; font-size: 13px; font-weight: bold;">по</span>
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="f-input">
            <button type="submit" style="height: 40px; padding: 0 22px; background: #4f46e5; border: none; color: #fff; border-radius: 8px; font-weight: bold; font-size: 13px; cursor: pointer;">
                🔄 Сформировать анализ номенклатуры
            </button>
            <a href="report.php" style="color: #92929f; text-decoration: none; font-size: 13px; font-weight: bold; margin-left: 10px;">Сбросить период</a>
        </form>

        <!-- МАТРИЦА ВСЕХ ОТГРУЖЕННЫХ ТОВАРОВ И ИХ ОБЪЕМОВ -->
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="text-align: left; width: 200px;">Менеджер</th>
                        <th style="width: 140px;">Оформлено накладных</th>
                        <th style="text-align: left; min-width: 350px;">Объем отгрузок (Инфографический масштаб)</th>
                        <th style="width: 160px; text-align: right; color: #10b981;">Сумма (BYN)</th>
                        <th style="width: 160px; text-align: right; color: #f59e0b;">Сумма (RUB)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if (!empty($raw_matrix)): 
                        $current_product = '';
                        foreach ($raw_matrix as $row): 
                            $sumByn = (float)$row['total_amount'];
                            $sumRub = $sumByn * 28.5;
                            $percentWidth = min(100, max(3, round(($sumByn / $maxAmount) * 100)));

                            // Если пошел новый вид продукции — отрисовываем красивый заголовок-разделитель категории
                            if ($current_product !== $row['product_name']):
                                $current_product = $row['product_name'];
                    ?>
                                <tr>
                                    <td colspan="5" class="category-header">
                                        📦 Категория продукции: <span style="color: #fff; font-size: 15px;"><?= htmlspecialchars($current_product) ?></span>
                                    </td>
                                </tr>
                    <?php 
                            endif; 
                    ?>
                            <tr>
                                <!-- 1. Имя ответственного менеджера -->
                                <td style="text-align: left; font-weight: bold; color: #fff; padding-left: 20px;">
                                    👤 <?= htmlspecialchars($row['manager_name'] ?? 'Не указан') ?>
                                </td>
                                
                                <!-- 2. Количество накладных ТТН -->
                                <td style="font-weight: bold; color: #e2e8f0;">
                                    <?= (int)$row['ttn_count'] ?> ТТН
                                </td>
                                
                                <!-- 3. Инлайновый нативный график-прогрессбар -->
                                <td style="text-align: left; padding-right: 20px;">
                                    <div class="bar-outer">
                                        <div class="bar-inner" style="width: <?= $percentWidth ?>%;"></div>
                                    </div>
                                </td>
                                
                                <!-- 4. Сумма в BYN -->
                                <td style="text-align: right; font-weight: bold; color: #10b981; font-size: 14px; white-space: nowrap;">
                                    <?= number_format($sumByn, 2, '.', ' ') ?>
                                </td>
                                
                                <!-- 5. Сумма в RUB -->
                                <td style="text-align: right; font-weight: bold; color: #f59e0b; font-size: 14px; white-space: nowrap;">
                                    <?= number_format($sumRub, 2, '.', ' ') ?>
                                </td>
                            </tr>
                    <?php 
                        endforeach; 
                    else: 
                    ?>
                        <tr><td colspan="5" style="padding: 40px; color: #64748b; font-weight: bold;">Отгрузки по номенклатуре за выбранный период дат отсутствуют в СУБД.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>
