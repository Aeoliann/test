<?php
session_start();
require 'db.php';
require 'rates.php'; // Живой центр курсов Нацбанка РБ

// ЖЕСТКАЯ БЕЗОПАСНОСТЬ: Если зашел не админ, выкидываем
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// Забираем живой курс рубля (стоимость 1 RUB в BYN)
$rate = isset($globalRates['RUB']) ? (float)$globalRates['RUB'] : 0.0352;

// НАДЕЖНЫЙ СБОР ПОЛЬЗОВАТЕЛЕЙ БЕЗ СТРОГИХ СВЯЗЕЙ С КЛИЕНТАМИ
$managersList = [];

// Список возможных имен колонок для проверки
$nameColumns = ['full_name'];
$chosenColumn = 'id';

// Шаг A. Определяем, как в твоей базе называется колонка с именем пользователя
foreach ($nameColumns as $col) {
    try {
        $test = $pdo->query("SELECT $col FROM users LIMIT 1");
        if ($test) {
            $chosenColumn = $col;
            break;
        }
    } catch (Exception $e) {
        continue; // Если колонки нет, пробуем следующую
    }
}

// Шаг Б. Пробуем вытащить пользователей по роли (независимо от регистра)
try {
    $managersList = $pdo->query("
        SELECT id, $chosenColumn AS name 
        FROM users 
        WHERE role LIKE '%man%' OR role LIKE '%мен%'
        ORDER BY id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $managersList = [];
}

// Шаг В. Если роли не подошли или пустые, забираем вообще ВСЕХ из таблицы users
if (empty($managersList)) {
    try {
        $managersList = $pdo->query("
            SELECT id, $chosenColumn AS name 
            FROM users 
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $managersList = [];
    }
}

// Шаг Г. Если база пользователей вообще заблокирована или пуста — создаем аварийный дефолт,
// чтобы админ панель отрисовала структуру, а не пустой экран
if (empty($managersList)) {
    $managersList = [
        ['id' => 1, 'name' => 'Администратор (Тест)']
    ];
}

// Официальный перечень видов продукции
$productTypes = ['Посуда', 'Сантехника', 'ЕКМ', 'Резервуары', 'МПДУ', 'УОКТ', 'Прочее'];
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Сводный аналитический отчёт - Santeks CRM</title>
    <link rel="stylesheet" href="style.css">
    <!-- Подключаем FontAwesome для иконок, если используется в проекте -->
    <link rel="stylesheet" href="cloudflare.com">
    
    <style>
        body {
            background: #151521;
            color: #fff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 30px;
            margin: 0;
            box-sizing: border-box;
        }
        .nav-panel {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            background: #1e1e2d;
            padding: 15px 25px;
            border-radius: 12px;
            border: 1px solid #323248;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .nav-title {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .btn-back {
            background: #4f46e5;
            color: #fff;
            text-decoration: none;
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            transition: background 0.2s, transform 0.1s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-back:hover {
            background: #4338ca;
        }
        .btn-back:active {
            transform: scale(0.98);
        }
        .matrix-container {
            background: #1e1e2d;
            padding: 25px;
            border-radius: 16px;
            border: 1px solid #323248;
            overflow-x: auto;
            box-shadow: 0 10px 35px rgba(0,0,0,0.3);
        }
        .matrix-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            text-align: left;
            font-size: 13px;
            border-radius: 8px;
            overflow: hidden;
        }
        .matrix-table th {
            background: #242434;
            color: #92929f;
            padding: 12px;
            font-weight: 600;
            border: 1px solid #2b2b40;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
        }
        .matrix-table td {
            padding: 12px;
            border: 1px solid #2b2b40;
            vertical-align: middle;
        }
        .matrix-table tbody tr {
            background: #1b1b28;
            transition: background 0.15s;
        }
        .matrix-table tbody tr:hover {
            background: #222235 !important;
        }
        .prod-title {
            font-weight: 600;
            color: #a3a3bc;
            background: #1e1e2d;
            font-size: 13px;
            border-right: 2px solid #4f46e5 !important;
        }
        .cell-count {
            text-align: center;
            color: #e2e8f0;
            font-weight: 500;
        }
        .cell-amount {
            text-align: right;
            font-weight: 600;
            color: #10b981;
        }
        .cell-empty {
            color: #3f3f56;
            font-weight: normal;
        }
        .total-row {
            background: #1e1e2d !important;
            font-weight: bold;
            border-top: 2px solid #4f46e5;
        }
        .total-row td {
            border-top: 2px solid #4f46e5 !important;
            padding: 14px 12px;
        }
        .rub-row {
            background: #161624 !important;
            font-weight: bold;
        }
        .rub-row td {
            padding: 14px 12px;
            color: #a855f7;
            font-size: 13px;
        }
    </style>
</head>
<body>

    <!-- ВЕРХНЯЯ ПАНЕЛЬ -->
    <div class="nav-panel">
        <h2 class="nav-title">
            <i class="fa-solid fa-chart-line" style="color: #a855f7;"></i> 
            Сводная аналитическая матрица отгрузок
        </h2>
        <a href="index.php" class="btn-back">
            <i class="fa-solid fa-arrow-left"></i> Вернуться в CRM
        </a>
    </div>

    <!-- ТАБЛИЦА С ДАННЫМИ -->
    <div class="matrix-container">
        <table class="matrix-table">
            <thead>
                <tr>
                    <th rowspan="2" style="width: 180px;">Вид продукции</th>
                    <?php foreach ($managersList as $m): ?>
                        <th colspan="2" style="text-align: center; color: #fff; font-size: 12px; background: #202030;">
                            <i class="fa-solid fa-user-tie" style="color: #4f46e5; margin-right: 5px;"></i> 
                            <?= htmlspecialchars($m['name']) ?>
                        </th>
                    <?php endforeach; ?>
                    <th colspan="2" style="text-align: center; background: #26263a; color: #f6ad55; font-size: 12px;">
                        <i class="fa-solid fa-layer-group" style="margin-right: 5px;"></i> ОБЩИЕ ИТОГИ
                    </th>
                </tr>
                <tr>
                    <?php foreach ($managersList as $m): ?>
                        <th style="text-align: center; width: 70px;">КОЛ-ВО</th>
                        <th style="text-align: right; width: 140px;">СУММА (BYN)</th>
                    <?php endforeach; ?>
                    <th style="text-align: center; width: 70px; background: #1e1e2d; color: #f6ad55;">КОЛ-ВО</th>
                    <th style="text-align: right; width: 150px; background: #1e1e2d; color: #f6ad55;">СУММА (BYN)</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $colTotals = [];
                $grandTotalCount = 0;
                $grandTotalSum = 0;

                foreach ($productTypes as $prod):
                    $rowTotalCount = 0;
                    $rowTotalSum = 0;
                ?>
                <tr>
                    <!-- Название категории -->
                    <td class="prod-title">
                        <?= $prod ?>
                    </td>
                    
                    <?php foreach ($managersList as $m): 
                        // Считаем отгруженные ТТН менеджера по конкретному типу продукции
                        $dataStmt = $pdo->prepare("
                            SELECT COUNT(t.id) as ttn_count, COALESCE(SUM(t.amount), 0) as ttn_sum 
                            FROM project_ttns t
                            INNER JOIN projects p ON t.project_id = p.id
                            INNER JOIN clients c ON p.client_id = c.id
                            WHERE c.manager_id = ? AND c.product_type = ?
                        ");
                        $dataStmt->execute([$m['id'], $prod]);
                        $res = $dataStmt->fetch();

                        $cnt = (int)$res['ttn_count'];
                        $sum = (float)$res['ttn_sum'];

                        $rowTotalCount += $cnt;
                        $rowTotalSum += $sum;

                        $colTotals[$m['id']]['count'] = ($colTotals[$m['id']]['count'] ?? 0) + $cnt;
                        $colTotals[$m['id']]['sum'] = ($colTotals[$m['id']]['sum'] ?? 0) + $sum;
                    ?>
                        <!-- Ячейки менеджера -->
                        <td class="cell-count">
                            <?= $cnt > 0 ? $cnt : '<span class="cell-empty">—</span>' ?>
                        </td>
                        <td class="cell-amount">
                            <?= $sum > 0 ? number_format($sum, 2, '.', ' ') : '<span class="cell-empty">0.00</span>' ?>
                        </td>
                    <?php endforeach; ?>

                    <!-- СТОЛБЕЦ ОБЩИХ ИТОГОВ СТРОКИ -->
                    <td class="cell-count" style="background: #1e1e2d; font-weight: bold; color: #fff;">
                        <?= $rowTotalCount ?>
                    </td>
                    <td class="cell-amount" style="background: #1e1e2d; font-weight: bold; color: #f6ad55;">
                        <?= number_format($rowTotalSum, 2, '.', ' ') ?>
                    </td>
                </tr>
                <?php 
                    $grandTotalCount += $rowTotalCount;
                    $grandTotalSum += $rowTotalSum;
                endforeach; 
                ?>
            </tbody>
            
            <tfoot>
                <!-- СТРОКА: ИТОГО (BYN) -->
                <tr class="total-row">
                    <td style="color: #fff; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px;">
                        <i class="fa-solid fa-wallet" style="margin-right: 5px; color:#4f46e5;"></i> Итого (BYN):
                    </td>
                    <?php foreach ($managersList as $m): ?>
                        <td style="text-align: center; color: #fff; font-size: 14px;">
                            <?= (int)($colTotals[$m['id']]['count'] ?? 0) ?>
                        </td>
                        <td style="text-align: right; color: #10b981; font-size: 14px;">
                            <?= number_format((float)($colTotals[$m['id']]['sum'] ?? 0), 2, '.', ' ') ?>
                        </td>
                    <?php endforeach; ?>
                    <!-- ГЛОБАЛЬНЫЙ ИТОГ -->
                    <td style="text-align: center; background: #242434; color: #fff; font-size: 14px;">
                        <?= $grandTotalCount ?>
                    </td>
                    <td style="text-align: right; background: #242434; color: #f6ad55; font-size: 15px;">
                        <?= number_format($grandTotalSum, 2, '.', ' ') ?>
                    </td>
                </tr>
                
                <!-- СТРОКА: КОНВЕРТАЦИЯ В РОССИЙСКИЕ РУБЛИ -->
                <tr class="rub-row">
                    <td style="color: #92929f; font-size: 11px; letter-spacing: 0.5px;">
                        <i class="fa-solid fa-money-bill-trend-up" style="margin-right: 5px; color:#a855f7;"></i> В пересчете на RUB:
                    </td>
                    <?php foreach ($managersList as $m): 
                        $mByn = (float)($colTotals[$m['id']]['sum'] ?? 0);
                        $mRub = $rate > 0 ? ($mByn / $rate) : 0;
                    ?>
                        <td colspan="2" style="text-align: right; color: #a855f7; font-size: 13px; padding-right: 12px;">
                            <?= number_format($mRub, 2, '.', ' ') ?> <span style="font-size:10px; color:#64748b; font-weight:normal;">RUB</span>
                        </td>
                    <?php endforeach; ?>
                    <!-- ОБЩИЙ РУБЛЕВЫЙ ИТОГ ДЛЯ РУКОВОДИТЕЛЯ -->
                    <td colspan="2" style="text-align: right; background: #242434; color: #a855f7; font-size: 14px; padding-right: 12px;">
                        <?= number_format(($rate > 0 ? $grandTotalSum / $rate : 0), 2, '.', ' ') ?> <span style="font-size:10px; color:#64748b; font-weight:normal;">RUB</span>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

</body>
</html>