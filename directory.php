<?php
session_start();
require 'db.php';

// Проверка авторизации: в справочник пускаем только вошедших сотрудников
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Сбор параметров поискового запроса
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$params = [];

// Строим базовый SQL-запрос для вывода ВСЕЙ базы клиентов с именами их менеджеров
// За счет LEFT JOIN мы выведем даже тех клиентов, у которых менеджер был случайно удален
$sql = "SELECT c.client_name, c.unp, c.product_type, c.status, u.login as manager_name 
        FROM clients c
        LEFT JOIN users u ON c.manager_id = u.id 
        WHERE 1=1";

// Если менеджер вбил текст в поле поиска (ищет по названию или по УНП)
if (!empty($search)) {
    $sql .= " AND (c.client_name LIKE :search OR c.unp LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$sql .= " ORDER BY c.client_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allClients = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Общий справочник контрагентов - Santeks CRM</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { background: #151521; color: #fff; font-family: sans-serif; padding: 30px; margin: 0; }
        .directory-container { background: #1e1e2d; padding: 25px; border-radius: 16px; border: 1px solid #323248; box-shadow: 0 10px 35px rgba(0,0,0,0.3); }
        .directory-table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; }
        .directory-table th { background: #242434; color: #92929f; padding: 12px; border: 1px solid #2b2b40; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
        .directory-table td { padding: 12px; border: 1px solid #2b2b40; color: #e2e8f0; }
        .directory-table tr:hover { background: #222235; }
        .search-input { padding: 10px 15px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; width: 300px; outline: none; font-size: 13px; transition: border-color 0.2s; }
        .search-input:focus { border-color: #4f46e5; }
        .btn-search { background: #4f46e5; color: #fff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; font-size: 13px; cursor: pointer; transition: background 0.2s; }
        .btn-search:hover { background: #4338ca; }
        .badge-status { padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; display: inline-block; }
        .status-new { background: rgba(79, 70, 229, 0.15); color: #818cf8; }
        .status-work { background: rgba(246, 173, 85, 0.15); color: #f6ad55; }
        .status-refusal { background: rgba(245, 101, 101, 0.15); color: #f56565; }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>
    <!-- ВЕРХНЯЯ СЕРВИСНАЯ ПАНЕЛЬ С ПОИСКОМ -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; background: #1e1e2d; padding: 15px 25px; border-radius: 12px; border: 1px solid #323248; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
        <h2 style="margin: 0; font-size: 18px; font-weight: bold;">🔍 Единый справочник контрагентов Santeks</h2>
        
        <!-- ФОРМА ПОИСКА НА ЛЕТУ -->
        <form method="GET" style="display: flex; gap: 10px; align-items: center;">
            <input type="text" name="search" class="search-input" placeholder="Введите название фирмы или УНП..." value="<?= htmlspecialchars($search) ?>" autofocus>
            <button type="submit" class="btn-search">Найти</button>
            <?php if (!empty($search)): ?>
                <a href="directory.php" style="color: #92929f; font-size: 12px; text-decoration: none; margin-right: 5px;">Сбросить</a>
            <?php endif; ?>
            <a href="index.php" style="background: #555; color: #fff; text-decoration: none; padding: 9px 18px; border-radius: 8px; font-size: 13px; font-weight: bold; display: inline-block;">← В CRM</a>
        </form>
    </div>

    <!-- ТАБЛИЦА СПРАВОЧНИКА -->
    <div class="directory-container">
        <table class="directory-table">
            <thead>
                <tr>
                    <th style="width: 60px; text-align: center;">П/П</th>
                    <th>Название организации</th>
                    <th style="width: 150px;">УНП</th>
                    <th style="width: 180px;">Вид продукции</th>
                    <th style="width: 120px;">Текущий статус</th>
                    <th style="width: 220px;">🔒 Отвественный менеджер</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $i = 1; 
                foreach ($allClients as $cl): 
                    // Динамически подбираем цвет бейджа статуса
                    $statusClass = 'status-new';
                    if ($cl['status'] === 'В работе') $statusClass = 'status-work';
                    if ($cl['status'] === 'Отказ') $statusClass = 'status-refusal';
                ?>
                <tr>
                    <td style="text-align: center; color: #64748b; font-weight: bold;"><?= $i++ ?></td>
                    <td style="font-weight: bold; color: #fff; font-size: 14px;"><?= htmlspecialchars($cl['client_name']) ?></td>
                    <td style="color: #94a3b8; font-family: monospace; font-size: 14px;"><?= htmlspecialchars($cl['unp'] ?: '—') ?></td>
                    <td style="color: #92929f;"><?= htmlspecialchars($cl['product_type']) ?></td>
                    <td>
                        <span class="badge-status <?= $statusClass ?>"><?= htmlspecialchars($cl['status']) ?></span>
                    </td>
                    <!-- ГЛАВНАЯ ЦЕЛЬ: Сразу видно, чей это клиент -->
                    <td style="background: rgba(79, 70, 229, 0.03);">
                        <span style="color: #a855f7; font-weight: bold; font-size: 13px;">
                            👤 <?= htmlspecialchars($cl['manager_name'] ?? 'Не назначен') ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($allClients)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 30px; color: #64748b; font-size: 14px;">
                            Ничего не найдено по запросу «<strong style="color:#f56565;"><?= htmlspecialchars($search) ?></strong>». Проверьте правильность ввода названия или УНП.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</body>
</html>