<?php
// directory.php — Улучшенный справочник с мощным поиском и фильтрами
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    die("Доступ запрещен. Авторизуйтесь.");
}

// Получаем параметры фильтрации
$search = isset($_GET['query']) ? trim($_GET['query']) : '';
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$filter_product = isset($_GET['product']) ? trim($_GET['product']) : '';

try {
    // Базовый запрос
    $sql = "SELECT c.*, u.login AS manager_name 
            FROM clients c 
            LEFT JOIN users u ON c.manager_id = u.id 
            WHERE 1=1";
    $params = [];

    // Поиск по тексту (название, УНП, контакт, телефон, email, комментарий)
    if ($search !== '') {
        $sql .= " AND (c.client_name LIKE ? 
                      OR c.unp LIKE ? 
                      OR c.contact_person LIKE ? 
                      OR c.phone LIKE ? 
                      OR c.email LIKE ? 
                      OR c.comment LIKE ?)";
        $like = "%$search%";
        $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
    }

    // Фильтр по статусу
    if ($filter_status !== '') {
        $sql .= " AND c.status = ?";
        $params[] = $filter_status;
    }

    // Фильтр по продукции
    if ($filter_product !== '') {
        $sql .= " AND c.product_type LIKE ?";
        $params[] = "%$filter_product%";
    }

    $sql .= " ORDER BY c.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allClients = $stmt->fetchAll() ?: [];

    // Получаем уникальные статусы и виды продукции для фильтров
    $statuses = $pdo->query("SELECT DISTINCT status FROM clients WHERE status IS NOT NULL AND status != '' ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);
    $products = $pdo->query("SELECT DISTINCT product_type FROM clients WHERE product_type IS NOT NULL AND product_type != '' ORDER BY product_type")->fetchAll(PDO::FETCH_COLUMN);

} catch (Exception $e) {
    die("Критический сбой СУБД в справочнике: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Единый справочник контрагентов — Santeks</title>
    <style>
        * { box-sizing: border-box; }
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
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .topbar h1 span { font-size: 24px; }

        .filter-bar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            background: #1a1a28;
            padding: 12px 20px;
            border-radius: 12px;
            border: 1px solid #323248;
            margin-bottom: 20px;
        }
        .filter-bar label {
            font-size: 11px;
            color: #92929f;
            font-weight: 700;
            text-transform: uppercase;
        }
        .filter-bar input, .filter-bar select {
            background: #151521;
            border: 1px solid #323248;
            border-radius: 8px;
            padding: 8px 12px;
            color: #fff;
            font-size: 13px;
            outline: none;
            height: 38px;
            min-width: 150px;
        }
        .filter-bar input:focus, .filter-bar select:focus {
            border-color: #4f46e5;
        }
        .filter-bar .btn-reset {
            background: transparent;
            border: 1px solid #323248;
            color: #92929f;
            padding: 6px 16px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            height: 38px;
            line-height: 24px;
        }
        .filter-bar .btn-reset:hover {
            background: #2a2a3f;
            color: #fff;
        }

        .table-wrapper {
            background: #1a1a28;
            border: 1px solid #323248;
            border-radius: 14px;
            overflow-x: auto;
            box-shadow: 0 8px 35px rgba(0,0,0,0.4);
            max-height: 650px;
            overflow-y: auto;
        }
        .directory-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            min-width: 900px;
            background: #1a1a28;
        }
        .directory-table th {
            background: #242438;
            padding: 14px 12px;
            color: #9ca3af;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: left;
            border-bottom: 2px solid #323248;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .directory-table td {
            padding: 12px;
            border-bottom: 1px solid #26263a;
            color: #e2e8f0;
            vertical-align: middle;
        }
        .directory-table tbody tr:hover td {
            background: #1e1e32;
        }
        .directory-table .highlight {
            background: rgba(79, 70, 229, 0.15) !important;
        }
        .badge-status {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        .status-new { background: rgba(129, 140, 248, 0.12); color: #818cf8; border: 1px solid rgba(129, 140, 248, 0.2); }
        .status-work { background: rgba(245, 158, 11, 0.12); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.2); }
        .status-refusal { background: rgba(239, 68, 68, 0.12); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2); }
        .status-done { background: rgba(16, 185, 129, 0.12); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.2); }

        /* Счётчик результатов */
        .result-count {
            font-size: 13px;
            color: #6b6b85;
            margin-left: 10px;
        }
        .result-count strong {
            color: #fff;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <main>
        <div class="topbar">
            <h1><span>🔍</span> Общий справочник клиентов</h1>
            <div>
                <span class="result-count">Найдено: <strong id="resultCount"><?= count($allClients) ?></strong></span>
            </div>
        </div>

        <!-- Панель фильтров -->
        <div class="filter-bar">
            <label>Поиск:</label>
            <input type="text" id="liveSearch" placeholder="Название, УНП, телефон, email..." value="<?= htmlspecialchars($search) ?>" oninput="applyFilters()">

            <label>Статус:</label>
            <select id="filterStatus" onchange="applyFilters()">
                <option value="">Все</option>
                <?php foreach ($statuses as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>" <?= $filter_status === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                <?php endforeach; ?>
            </select>

            <label>Продукция:</label>
            <select id="filterProduct" onchange="applyFilters()">
                <option value="">Все</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>" <?= $filter_product === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                <?php endforeach; ?>
            </select>

            <a href="directory.php" class="btn-reset">Сбросить</a>
        </div>

        <!-- Таблица -->
        <div class="table-wrapper">
            <table class="directory-table">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th style="min-width:200px;">Название организации</th>
                        <th style="width:120px;">УНП</th>
                        <th style="min-width:150px;">Продукция</th>
                        <th style="width:130px;">Статус</th>
                        <th style="min-width:150px;">Ответственный менеджер</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <?php if (empty($allClients)): ?>
                        <tr><td colspan="6" style="text-align:center; padding:40px; color:#4b4b5e;">Клиенты не найдены</td></tr>
                    <?php else: ?>
                        <?php $i = 1; foreach ($allClients as $cl): ?>
                        <tr data-id="<?= $cl['id'] ?>">
                            <td style="text-align:center; color:#6b6b85;"><?= $i++ ?></td>
                            <td style="font-weight:600; color:#fff;"><?= htmlspecialchars($cl['client_name'] ?? '') ?></td>
                            <td style="font-family:monospace; color:#cbd5e1;"><?= htmlspecialchars($cl['unp'] ?: '—') ?></td>
                            <td><?= htmlspecialchars($cl['product_type'] ?: '—') ?></td>
                            <td>
                                <?php
                                $status = $cl['status'] ?? 'Новый';
                                $statusClass = 'status-new';
                                if ($status === 'Текущий') $statusClass = 'status-work';
                                elseif ($status === 'Отказ') $statusClass = 'status-refusal';
                                elseif ($status === 'Завершен') $statusClass = 'status-done';
                                ?>
                                <span class="badge-status <?= $statusClass ?>"><?= htmlspecialchars($status) ?></span>
                            </td>
                            <td style="color:#a855f7; font-weight:600;">👤 <?= htmlspecialchars($cl['manager_name'] ?? 'Не назначен') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <script>
        // ================================================================
        // УНИВЕРСАЛЬНЫЙ ЖИВОЙ ПОИСК И ФИЛЬТРАЦИЯ
        // ================================================================
        function applyFilters() {
            const searchQuery = document.getElementById('liveSearch').value.toLowerCase().trim();
            const statusFilter = document.getElementById('filterStatus').value;
            const productFilter = document.getElementById('filterProduct').value.toLowerCase().trim();
            const rows = document.querySelectorAll('#tableBody tr');
            let visibleCount = 0;

            rows.forEach(row => {
                // Получаем текст всей строки (для поиска)
                const rowText = row.innerText.toLowerCase();
                // Получаем отдельные ячейки для фильтров
                const statusCell = row.querySelector('td:nth-child(5) .badge-status');
                const statusText = statusCell ? statusCell.innerText.toLowerCase() : '';
                const productCell = row.querySelector('td:nth-child(4)');
                const productText = productCell ? productCell.innerText.toLowerCase() : '';

                let show = true;

                // Поиск по тексту
                if (searchQuery !== '') {
                    if (!rowText.includes(searchQuery)) {
                        show = false;
                    }
                }

                // Фильтр по статусу
                if (show && statusFilter !== '') {
                    if (statusText !== statusFilter.toLowerCase()) {
                        show = false;
                    }
                }

                // Фильтр по продукции
                if (show && productFilter !== '') {
                    if (!productText.includes(productFilter)) {
                        show = false;
                    }
                }

                if (show) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            // Обновляем счётчик
            document.getElementById('resultCount').innerText = visibleCount;
        }

        // При загрузке страницы применяем фильтры (на случай, если параметры были переданы через GET)
        document.addEventListener('DOMContentLoaded', function() {
            applyFilters();
        });
    </script>
</body>
</html>