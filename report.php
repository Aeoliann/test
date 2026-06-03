<?php
// report.php — Высокотехнологичная аналитическая матрица и интерактивные графики Santeks CRM
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$u_role = $_SESSION['role'] ?? 'manager';
$userId = (int)$_SESSION['user_id'];

// 1. Парсинг и валидация временного интервала отгрузок ТТН
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : date('Y-m-01');
$date_to   = isset($_GET['date_to']) ? trim($_GET['date_to']) : date('Y-m-d');

try {
    // ИСПРАВЛЕНО НАМЕРТВО: Сбор номенклатуры по реальной структуре СУБД (через c2.manager_id)
    $sql = "SELECT 
                u.id AS manager_id,
                u.login AS manager_name,
                (SELECT COUNT(DISTINCT c.id) FROM clients c WHERE c.manager_id = u.id AND c.status != 'Отказ') AS active_clients_count,
                (SELECT COUNT(DISTINCT p.id) FROM projects p LEFT JOIN clients c ON p.client_id = c.id WHERE c.manager_id = u.id) AS active_contracts_count,
                COUNT(DISTINCT t.id) AS ttn_count_period,
                COALESCE(SUM(t.amount), 0) AS total_amount_period,
                
                -- ИНТЕЛЛЕКТУАЛЬНЫЙ АГРЕГАТОР: Сортирует товары от часто отгружаемых к редким без поля user_id
                (
                    SELECT GROUP_CONCAT(sub.product_info ORDER BY sub.cnt DESC SEPARATOR ', ')
                    FROM (
                        SELECT t2.product_info, COUNT(*) as cnt, c2.manager_id AS user_id
                        FROM project_ttns t2
                        LEFT JOIN projects p2 ON t2.project_id = p2.id
                        LEFT JOIN clients c2 ON p2.client_id = c2.id
                        WHERE t2.product_info IS NOT NULL AND t2.product_info != '' AND t2.ttn_date BETWEEN ? AND ?
                        GROUP BY t2.product_info, c2.manager_id
                    ) sub
                    WHERE sub.user_id = u.id
                ) AS shipped_products

            FROM users u
            LEFT JOIN clients c ON c.manager_id = u.id
            LEFT JOIN projects p ON p.client_id = c.id
            LEFT JOIN project_ttns t ON t.project_id = p.id AND t.ttn_date BETWEEN ? AND ?
            GROUP BY u.id, u.login
            ORDER BY total_amount_period DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$date_from, $date_to, $date_from, $date_to]);
    $matrix = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ЗАПРОС 2: Исправленный сбор данных для интерактивных графиков Chart.js
    $chartSql = "SELECT 
                    c.manager_id,
                    t.product_info,
                    SUM(t.amount) AS total_sum,
                    COUNT(t.id) AS ttn_count
                 FROM project_ttns t
                 LEFT JOIN projects p ON t.project_id = p.id
                 LEFT JOIN clients c ON p.client_id = c.id
                 WHERE t.ttn_date BETWEEN ? AND ? AND t.product_info IS NOT NULL AND t.product_info != ''
                 GROUP BY c.manager_id, t.product_info";
                 
    $cStmt = $pdo->prepare($chartSql);
    $cStmt->execute([$date_from, $date_to]);
    $chartRaw = $cStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Упаковываем данные в многомерный массив для JavaScript
    $managerCharts = [];
    foreach ($chartRaw as $c) {
        $m_id = (int)$c['manager_id'];
        if (!isset($managerCharts[$m_id])) {
            $managerCharts[$m_id] = ['labels' => [], 'sums' => [], 'counts' => []];
        }
        $managerCharts[$m_id]['labels'][] = $c['product_info'];
        $managerCharts[$m_id]['sums'][]   = round((float)$c['total_sum'], 2);
        $managerCharts[$m_id]['counts'][] = (int)$c['ttn_count'];
    }

} catch (Exception $e) {
    die("Критический сбой СУБД при расчете матрицы: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Аналитическая матрица отгрузок — Santeks</title>
    <!-- Подключаем графическое ядро Chart.js напрямую с CDN -->
    <script src="https://jsdelivr.net"></script>
    <style>
        body { background: #151521; color: #fff; font-family: 'Segoe UI', system-ui, sans-serif; padding: 0; margin: 0; display: flex; min-height: 100vh; }
        aside { width: 240px; background: #1e1e2d; border-right: 1px solid #323248; flex-shrink: 0; }
        main { flex: 1; min-width: 0; padding: 40px; box-sizing: border-box; display: flex; flex-direction: column; gap: 24px; }
        
        /* СТИЛЬНЫЕ НЕОНОВЫЕ КАРТОЧКИ И ПАНЕЛИ */
        .card { background: #1e1e2d; border: 1px solid #323248; border-radius: 12px; padding: 24px; box-shadow: 0 4px 24px rgba(0,0,0,0.4); box-sizing: border-box; width: 100%; }
        .filter-panel { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; background: #1e1e2d; border: 1px solid #323248; border-radius: 12px; padding: 16px 24px; }
        .f-input { height: 40px; padding: 0 14px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; font-size: 13px; font-weight: bold; color-scheme: dark; transition: border 0.2s; }
        .f-input:focus { border-color: #4f46e5; }
        
        /* ТАБЛИЦА РЕЙТИНГА */
        .table-wrapper { border-radius: 12px; border: 1px solid #323248; overflow: hidden; background: #1e1e2d; }
        table { width: 100%; border-collapse: collapse; margin: 0; text-align: center; }
        th { background: #242434; padding: 16px 12px; color: #92929f; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.5px; border-bottom: 2px solid #323248; white-space: nowrap; }
        td { padding: 14px 12px; border-bottom: 1px solid #2b2b40; font-size: 13px; background: #1e1e2d; color: #fff; }
        tr:last-child td { border-bottom: none; }
        .leader-tr td { background: rgba(16, 185, 129, 0.03) !important; }
        
        /* ИНТЕРАКТИВНЫЕ ВКЛАДКИ МЕНЕДЖЕРОВ */
        .tab-btn { background: #242434; border: 1px solid #323248; color: #92929f; padding: 12px 20px; border-radius: 8px; font-weight: bold; font-size: 13px; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; gap: 8px; }
        .tab-btn:hover { color: #fff; background: #2a2a3d; border-color: #4f46e5; }
        .tab-btn.active { background: #4f46e5; color: #fff; border-color: #4f46e5; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.4); }
        
        /* КОНТЕЙНЕРЫ ГРАФИКОВ */
        .charts-flex { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 24px; width: 100%; box-sizing: border-box; }
        .chart-container { flex: 1; min-width: 400px; height: 360px; background: #151521; border-radius: 12px; padding: 20px; border: 1px solid #2b2b40; box-sizing: border-box; position: relative; }
        @media (max-width: 900px) { .chart-container { min-width: 100%; } }
    </style>
</head>
<body>

    <!-- БОКОВОЕ МЕНЮ SIDEBAR -->
    <aside>
        <?php include 'sidebar.php'; ?>
    </aside>

    <!-- ОСНОВНОЙ КОНТЕНТНЫЙ БЛОК -->
    <main>
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <span style="font-size: 26px; filter: drop-shadow(0 2px 8px rgba(129,140,248,0.5));">🏆</span>
                <h1 style="margin: 0; font-size: 24px; font-weight: bold; letter-spacing: -0.5px;">Сводная матрица и коммерческие графики Santeks</h1>
            </div>
            <a href="index.php" style="color: #818cf8; text-decoration: none; font-size: 13px; font-weight: bold; padding: 8px 16px; background: rgba(129,140,248,0.1); border-radius: 8px; transition: 0.2s;" onmouseover="this.style.background='rgba(129,140,248,0.2)';" onmouseout="this.style.background='rgba(129,140,248,0.1)';">← Вернуться в CRM</a>
        </div>

        <!-- УПРАВЛЕНИЕ ВРЕМЕННЫМ ПЕРИОДОМ -->
        <form method="GET" action="report.php" class="filter-panel">
            <span style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">Период отгрузок ТТН:</span>
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="f-input">
            <span style="color: #64748b; font-size: 13px; font-weight: bold;">по</span>
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="f-input">
            
            <button type="submit" style="height: 40px; padding: 0 22px; background: #4f46e5; border: none; color: #fff; border-radius: 8px; font-weight: bold; font-size: 13px; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#4338ca'; this.style.boxShadow='0 4px 12px rgba(79,70,229,0.3)';" onmouseout="this.style.background='#4f46e5'; this.style.boxShadow='none';">
                🔄 Сформировать матрицу
            </button>
            <a href="report.php" style="color: #92929f; text-decoration: none; font-size: 13px; font-weight: bold; margin-left: 10px; transition: color 0.2s;" onmouseover="this.style.color='#fff';" onmouseout="this.style.color='#92929f';">Сбросить период</a>
        </form>

        <!-- ТАБЛИЦА СТАТИСТИКИ МЕНЕДЖЕРОВ -->
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="width: 70px;">Место</th>
                        <th style="text-align: left; min-width: 180px;">Менеджер отдела продаж</th>
                        <th style="text-align: left; min-width: 280px; color: #818cf8;">Что отгружалось (Рейтинг популярности)</th>
                        <th style="width: 120px;">Всего клиентов</th>
                        <th style="width: 140px;">Активных договоров</th>
                        <th style="width: 150px;">Накладных за период</th>
                        <th style="width: 170px; text-align: right; color: #10b981;">Выручка (BYN)</th>
                        <th style="width: 170px; text-align: right; color: #f59e0b;">Выручка (RUB)</th>
 </tr>
                </thead>
                <tbody>
                    <?php 
                    if (!empty($matrix)): 
                        $place = 1;
                        foreach ($matrix as $m): 
                            $sumByn = (float)$m['total_amount_period'];
                            $sumRub = $sumByn * 28.5;
                            $isLeader = ($place === 1 && $sumByn > 0);
                    ?>
                        <tr class="<?= $isLeader ? 'leader-tr' : '' ?>">
                            <td style="font-weight: bold; font-size: 14px; color: <?= $place === 1 ? '#10b981' : '#64748b' ?>;">
                                <?= $place === 1 ? '🥇 1' : $place ?>
                            </td>
                            <td style="text-align: left; font-weight: bold; color: #fff;">
                                <?= htmlspecialchars($m['manager_name']) ?>
                            </td>
                            
                            <!-- Вывод отсортированного списка продукции -->
                            <td style="text-align: left;">
                                <span style="background: #151521; padding: 6px 12px; border-radius: 6px; border: 1px solid #2b2b40; color: #a1a1aa; font-size: 12px; font-weight: 500; display: inline-block; line-height: 1.3;">
                                    📦 <?= htmlspecialchars($m['shipped_products'] ?? '—') ?>
                                </span>
                            </td>
                            
                            <td style="color: #92929f; font-weight: bold;"><?= (int)$m['active_clients_count'] ?></td>
                            <td style="color: #92929f; font-weight: bold;"><?= (int)$m['active_contracts_count'] ?></td>
                            <td style="font-weight: bold; color: #e2e8f0;"><?= (int)$m['ttn_count_period'] ?></td>
                            <td style="text-align: right; font-weight: bold; color: #10b981; font-size: 14px; white-space: nowrap;">
                                <?= number_format($sumByn, 2, '.', ' ') ?>
                            </td>
                            <td style="text-align: right; font-weight: bold; color: #f59e0b; font-size: 14px; white-space: nowrap;">
                                <?= number_format($sumRub, 2, '.', ' ') ?>
                            </td>
                        </tr>
                    <?php 
                            $place++;
                        endforeach; 
                    else: 
                    ?>
                        <tr><td colspan="8" style="padding: 40px; color: #64748b; font-weight: bold;">Данные за указанный период времени отсутствуют в СУБД.</td></tr>
                    <?php endif; ?>

                </tbody>
            </table>
            <div class="card" style="margin-top: 24px;">
            <h3 style="margin: 0 0 18px 0; font-size: 13px; text-transform: uppercase; color: #818cf8; font-weight: bold; letter-spacing: 0.8px;">
                📈 Товарно-финансовая инфографика по менеджерам за период:
            </h3>
            
            <!-- Интерактивные вкладки менеджеров (Генерируются нативно из СУБД) -->
            <div style="display: flex; gap: 10px; margin-bottom: 24px; flex-wrap: wrap;">
                <?php if (!empty($matrix)): $tIdx = 0; foreach ($matrix as $m): ?>
                    <button type="button" 
                            class="tab-btn <?= $tIdx === 0 ? 'active' : '' ?>" 
                            data-mid="<?= (int)$m['manager_id'] ?>"
                            onclick="switchManagerChart(<?= (int)$m['manager_id'] ?>, this)">
                        👤 <?= htmlspecialchars($m['manager_name']) ?>
                    </button>
                <?php $tIdx++; endforeach; endif; ?>
            </div>

            <!-- Контейнеры для графиков Chart.js -->
            <div class="charts-flex">
                <div class="chart-container">
                    <canvas id="barChartByn"></canvas>
                </div>
                <div class="chart-container">
                    <canvas id="pieChartQty"></canvas>
                </div>
            </div>
        </div>
    </main>

<script>
// Передаем сагрегированные данные из PHP напрямую в память JavaScript
const graphDb = <?= json_encode($managerCharts ?? []) ?>;
let activeBarChart = null;
let activePieChart = null;

// Главный движок построения графиков
function renderChartsForManager(managerId) {
    console.log("Отрисовка инфографики для менеджера ID:", managerId);
    
    const canvasBar = document.getElementById('barChartByn');
    const canvasPie = document.getElementById('pieChartQty');
    
    if (!canvasBar || !canvasPie) {
        console.error("Ошибка: Холсты для графиков Chart.js не найдены в DOM!");
        return;
    }

    const ctxBar = canvasBar.getContext('2d');
    const ctxPie = canvasPie.getContext('2d');

    // Нативно извлекаем данные или подставляем заглушку, если отгрузок не было
        // ИСПРАВЛЕНО НАМЕРТВО: Добавлены пустые массивы [] для корректной инициализации Chart.js
    const data = graphDb[managerId] || { labels: ['Нет отгрузок'], sums: [], counts: [] };

    
    // Мёртвое уничтожение старых экземпляров для предотвращения мерцания и наложения слоёв
    if (activeBarChart) activeBarChart.destroy();
    if (activePieChart) activePieChart.destroy();

    // 1. СТОЛБЧАТЫЙ ГРАФИК: ВЫРУЧКА ПО КАТЕГОРИЯМ ТОВАРОВ (BYN)
    activeBarChart = new Chart(ctxBar, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Выручка за период (BYN)',
                data: data.sums,
                backgroundColor: 'rgba(79, 70, 229, 0.65)',
                borderColor: '#818cf8',
                borderWidth: 1.5,
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { labels: { color: '#92929f', font: { size: 11, weight: 'bold' } } }
            },
            scales: {
                x: { ticks: { color: '#64748b', font: { weight: 'bold' } }, grid: { color: '#242434' } },
                y: { ticks: { color: '#64748b' }, grid: { color: '#242434' } }
            }
        }
    });

    // 2. КРУГОВОЙ ГРАФИК (ПИРОГ): ДОЛЯ ОТГРУЗОК ПО КОЛИЧЕСТВУ ТТН
    activePieChart = new Chart(ctxPie, {
        type: 'pie',
        data: {
            labels: data.labels,
            datasets: [{
                data: data.counts,
                backgroundColor: [
                    '#10b981', '#f59e0b', '#3b82f6', '#ec4899', '#8b5cf6', '#ef4444', '#14b8a6'
                ],
                borderWidth: 2,
                borderColor: '#1e1e2d'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: { color: '#92929f', font: { size: 11, weight: 'bold' } }
                },
                title: {
                    display: true,
                    text: 'Долевое распределение выписанных ТТН',
                    color: '#92929f',
                    font: { size: 12, weight: 'bold' }
                }
            }
        }
    });
}

// Переключатель вкладок
function switchManagerChart(managerId, btnElement) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    if (btnElement) {
        btnElement.classList.add('active');
    }
    renderChartsForManager(managerId);
}

// Автоматический запуск по первому менеджеру в рейтинге при прогрузке страницы
document.addEventListener("DOMContentLoaded", () => {
    setTimeout(() => {
        const firstBtn = document.querySelector('.tab-btn');
        if (firstBtn) {
            console.log("Автоматический старт первого таба...");
            firstBtn.click();
        } else {
            console.log("Кнопки менеджеров не найдены.");
        }
    }, 100); // Небольшая задержка для стопроцентной инициализации DOM-дерева
});
</script>
        </div>