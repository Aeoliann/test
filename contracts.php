<?php
// contracts.php — Главный интерфейс и контроллер управления договорами
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';
require_once 'logger.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php"); 
    exit;
}

// =========================================================================
// АВТОНОМНОЕ СОХРАНЕНИЕ ДАННЫХ ВНУТРИ CONTRACTS.PHP (POST-КОНТРОЛЛЕР)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action_mode']) && $_POST['action_mode'] === 'update_contract_number_fast') {
            $project_id      = (int)($_POST['project_id'] ?? 0);
            $contract_number = trim($_POST['contract_number'] ?? '');
            
            if ($project_id > 0 && !empty($contract_number)) {
                $stmt = $pdo->prepare("UPDATE projects SET contract_number = ? WHERE id = ?");
                $stmt->execute([$contract_number, $project_id]);
                
                if (function_exists('logAction')) {
                    logAction('UPDATE', 'projects', "Быстрое инлайн-изменение номера договора на №{$contract_number}");
                }
            }
            echo json_encode(['status' => 'success']);
            exit;
        }

        if (isset($_POST['contract_number'])) {
            $client_id       = (int)($_POST['client_id'] ?? 0);
            $contract_number = trim($_POST['contract_number'] ?? '');
            $contract_date   = !empty($_POST['contract_date']) ? $_POST['contract_date'] : date('Y-m-d');
            $product_type    = isset($_POST['product_type']) ? trim($_POST['product_type']) : '';
            $currency        = trim($_POST['currency'] ?? 'BYN');
            
            if (empty($product_type) && $client_id > 0) {
                $getProdStmt = $pdo->prepare("SELECT product_type FROM clients WHERE id = ?");
                $getProdStmt->execute([$client_id]);
                $product_type = trim($getProdStmt->fetchColumn() ?: '');
            }
            if (empty($product_type)) {
                $product_type = 'Сантехника';
            }

            if ($client_id > 0 && !empty($contract_number)) {
                $pdo->beginTransaction();
                $sql = "INSERT INTO projects (client_id, contract_number, contract_date, product_type, currency) VALUES (?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$client_id, $contract_number, $contract_date, $product_type, $currency]);
                $new_project_id = (int)$pdo->lastInsertId();
                
                $uClient = $pdo->prepare("UPDATE clients SET is_contract_signed = 1 WHERE id = ?");
                $uClient->execute([$client_id]);

                if (function_exists('logAction')) {
                    logAction('INSERT', 'projects', "Создан договор №{$contract_number} (Валюта: {$currency}, Продукция: {$product_type}) для клиента ID {$client_id}");
                }
                $pdo->commit();
            }
            
            $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') 
                      || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => 'Договор успешно зафиксирован в СУБД']);
                exit;
            } else {
                header("Location: contracts.php");
                exit;
            }
        }

        if (isset($_POST['action_mode']) && $_POST['action_mode'] === 'update_contract_date_live') {
            header('Content-Type: application/json');
            $project_id    = (int)($_POST['project_id'] ?? 0);
            $contract_date = trim($_POST['contract_date'] ?? '');
            if ($project_id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Некорректный системный ID проекта']);
                exit;
            }
            $final_date = !empty($contract_date) ? $contract_date : null;
            $stmt = $pdo->prepare("UPDATE projects SET contract_date = ? WHERE id = ?");
            $stmt->execute([$final_date, $project_id]);
            echo json_encode(['status' => 'success']);
            exit;
        }

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// =========================================================================
// ВЫБОРКА ДАННЫХ
// =========================================================================
$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'manager';

if ($userRole === 'admin') {
    $sql = "SELECT c.id as cid, c.client_name,
                   p.id as pid, p.contract_number, p.contract_date, p.product_type, p.scan_path,
                   IFNULL(NULLIF(TRIM(p.currency), ''), 'BYN') as main_contract_currency,
                   IFNULL((
                       SELECT GROUP_CONCAT(CONCAT(FORMAT(sum_amt, 2, 'ru_RU'), ' ', currency) SEPARATOR ' / ')
                       FROM (
                           SELECT project_id, currency, SUM(amount) as sum_amt
                           FROM project_ttns
                           GROUP BY project_id, currency
                       ) as ttn_sub
                       WHERE ttn_sub.project_id = p.id
                   ), '0.00 BYN') as ttn_currency_totals
            FROM clients c
            LEFT JOIN projects p ON c.id = p.client_id
            WHERE c.is_contract_signed = 1
            ORDER BY c.client_name ASC, p.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
} else {
    $sql = "SELECT c.id as cid, c.client_name,
                   p.id as pid, p.contract_number, p.contract_date, p.product_type, p.scan_path,
                   IFNULL(NULLIF(TRIM(p.currency), ''), 'BYN') as main_contract_currency,
                   IFNULL((
                       SELECT GROUP_CONCAT(CONCAT(FORMAT(sum_amt, 2, 'ru_RU'), ' ', currency) SEPARATOR ' / ')
                       FROM (
                           SELECT project_id, currency, SUM(amount) as sum_amt
                           FROM project_ttns
                           GROUP BY project_id, currency
                       ) as ttn_sub
                       WHERE ttn_sub.project_id = p.id
                   ), '0.00 BYN') as ttn_currency_totals
            FROM clients c
            LEFT JOIN projects p ON c.id = p.client_id
            WHERE c.is_contract_signed = 1 AND c.manager_id = ?
            ORDER BY c.client_name ASC, p.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
}

$rows = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Контракты и отгрузки</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* ============================================================
           УЛУЧШЕННЫЙ ДИЗАЙН ДЛЯ CONTRACTS.PHP
           ============================================================ */
        body {
            background: #0f0f1a;
            color: #fff;
            font-family: 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 0;
            display: flex;
            min-height: 100vh;
        }

        /* САЙДБАР */
        aside {
            width: 260px;
            flex-shrink: 0;
            background: #1e1e2d;
            border-right: 1px solid #323248;
        }

        /* ОСНОВНОЙ КОНТЕНТ */
        main {
            flex: 1;
            padding: 30px 35px;
            min-width: 0;
            box-sizing: border-box;
        }

        /* ТОПБАР */
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 28px;
            background: #1e1e2d;
            border: 1px solid #323248;
            border-radius: 14px;
            margin-bottom: 25px;
            box-shadow: 0 4px 25px rgba(0,0,0,0.3);
            flex-wrap: wrap;
            gap: 15px;
        }

        .topbar h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            color: #fff;
            letter-spacing: -0.3px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .topbar h1 span {
            font-size: 24px;
        }

        .topbar .user-badge {
            background: rgba(168, 85, 247, 0.12);
            color: #a855f7;
            border: 1px solid rgba(168, 85, 247, 0.25);
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .topbar .btn-excel {
            background: #10b981;
            color: #fff;
            text-decoration: none;
            padding: 10px 22px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .topbar .btn-excel:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(16, 185, 129, 0.3);
        }

        /* ПОИСК */
        .search-box {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #1e1e2d;
            border: 1px solid #323248;
            border-radius: 10px;
            padding: 8px 18px;
            margin-bottom: 20px;
            max-width: 400px;
        }
        .search-box label {
            font-size: 11px;
            color: #92929f;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }
        .search-box input {
            background: transparent;
            border: none;
            color: #fff;
            padding: 8px 0;
            font-size: 14px;
            outline: none;
            width: 100%;
        }
        .search-box input::placeholder {
            color: #4b4b5e;
        }

        /* КОНТЕЙНЕР ТАБЛИЦЫ */
        .table-wrapper {
            background: #1a1a28;
            border: 1px solid #323248;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 8px 35px rgba(0,0,0,0.4);
        }

        /* ТАБЛИЦА - ТЕПЕРЬ ШИРЕ! */
        .contract-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            min-width: 1300px;
            background: #1a1a28;
        }

        .contract-table thead {
            background: #242438;
        }
        .contract-table th {
            padding: 16px 14px;
            color: #9ca3af;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            text-align: left;
            border-bottom: 2px solid #323248;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
            background: #242438;
        }
        .contract-table th:first-child { padding-left: 20px; }
        .contract-table th:last-child { padding-right: 20px; }

        .contract-table td {
            padding: 14px 14px;
            border-bottom: 1px solid #26263a;
            color: #e2e8f0;
            vertical-align: middle;
            background: #1a1a28;
        }
        .contract-table td:first-child { padding-left: 20px; }
        .contract-table td:last-child { padding-right: 20px; }

        .contract-table tbody tr {
            transition: all 0.2s ease;
        }
        .contract-table tbody tr:hover td {
            background: #22223a;
        }
        .contract-table tbody tr:last-child td {
            border-bottom: none;
        }

        /* КЛИЕНТСКИЙ ЗАГОЛОВОК */
        .client-header td {
            background: #1e1e32 !important;
            padding: 14px 20px !important;
            border-top: 2px solid rgba(129, 140, 248, 0.25) !important;
            border-bottom: 2px solid #323248 !important;
        }
        .client-header .client-name {
            font-size: 15px;
            font-weight: 700;
            color: #818cf8;
            letter-spacing: 0.2px;
        }
        .client-header .client-badge {
            background: rgba(16, 185, 129, 0.08);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        /* КОНТРАКТНАЯ СТРОКА */
        .contract-row td {
            background: #1a1a28;
        }

        /* БЕЙДЖИ ПРОДУКЦИИ */
        .prod-badge {
            background: rgba(129, 140, 248, 0.06);
            color: #818cf8;
            border: 1px solid rgba(129, 140, 248, 0.12);
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
        }

        /* КНОПКА ТТН */
        .btn-ttn {
            background: #4f46e5;
            color: #fff;
            border: none;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-ttn:hover {
            background: #6366f1;
            transform: scale(1.03);
            box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3);
        }

        /* СУММЫ */
        .amount-total {
            font-weight: 700;
            font-family: monospace;
            font-size: 14px;
            color: #10b981;
        }
        .amount-currency {
            font-size: 10px;
            font-weight: 600;
            color: #4b5563;
            margin-left: 2px;
        }

        /* СКАН-КНОПКИ */
        .btn-scan {
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }
        .btn-scan-pdf {
            background: rgba(239, 68, 68, 0.08);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        .btn-scan-pdf:hover {
            background: rgba(239, 68, 68, 0.15);
        }
        .btn-scan-img {
            background: rgba(99, 102, 241, 0.08);
            color: #6366f1;
            border: 1px solid rgba(99, 102, 241, 0.2);
        }
        .btn-scan-img:hover {
            background: rgba(99, 102, 241, 0.15);
        }
        .btn-scan-upload {
            background: rgba(129, 140, 248, 0.05);
            color: #818cf8;
            border: 1px solid #323248;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-block;
        }
        .btn-scan-upload:hover {
            border-color: #4f46e5;
            background: rgba(79, 70, 229, 0.05);
        }

        /* ФУТЕР С ИТОГАМИ */
        .footer-total {
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
        .footer-total .label {
            font-size: 11px;
            color: #92929f;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .footer-total .value {
            font-size: 20px;
            font-weight: 800;
            color: #10b981;
            font-family: monospace;
            background: #0f0f1a;
            padding: 8px 20px;
            border-radius: 10px;
            border: 1px solid #26263a;
        }
        .footer-total .value span {
            font-size: 13px;
            color: #4b5563;
        }

        /* ДОБАВИТЬ КОНТРАКТ */
        .btn-add-contract {
            background: #4f46e5;
            color: #fff;
            border: none;
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-add-contract:hover {
            background: #6366f1;
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(79, 70, 229, 0.3);
        }

        /* ДАТА ИНПУТ В ТАБЛИЦЕ */
        .date-inline {
            background: transparent;
            border: 1px solid #323248;
            border-radius: 6px;
            color: #fff;
            padding: 4px 8px;
            font-size: 12px;
            font-family: monospace;
            outline: none;
            cursor: pointer;
            transition: all 0.2s;
            color-scheme: dark;
            width: 100%;
            max-width: 140px;
            box-sizing: border-box;
        }
        .date-inline:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 10px rgba(79, 70, 229, 0.15);
        }

        /* АДАПТИВ */
        @media (max-width: 768px) {
            main { padding: 15px; }
            .topbar { flex-direction: column; align-items: stretch; }
            .contract-table { min-width: 1000px; }
        }

        /* СКРОЛЛБАР */
        .table-wrapper::-webkit-scrollbar {
            height: 8px;
            width: 8px;
        }
        .table-wrapper::-webkit-scrollbar-track {
            background: #1a1a28;
        }
        .table-wrapper::-webkit-scrollbar-thumb {
            background: #323248;
            border-radius: 10px;
        }
        .table-wrapper::-webkit-scrollbar-thumb:hover {
            background: #4f46e5;
        }

        /* АНИМАЦИЯ ЗАГРУЗКИ */
        .contract-row {
            transition: all 0.2s ease;
        }

        /* ССЫЛКИ СОРТИРОВКИ */
        .sort-link {
            color: #9ca3af;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: color 0.2s;
            cursor: pointer;
        }
        .sort-link:hover {
            color: #ffffff;
        }
        .sort-link .arrow {
            color: #4f46e5;
            font-size: 10px;
        }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <main>

        <!-- ============================================================
        ТОПБАР
        ============================================================ -->
        <div class="topbar">
            <h1>
                <span>📄</span>
                Договоры и отгрузки
                <span class="user-badge">👤 <?= htmlspecialchars($_SESSION['login'] ?? 'admin') ?></span>
            </h1>
            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                <a href="export_contracts_excel.php" class="btn-excel">
                    📊 Скачать отчет в Excel
                </a>
                <button class="btn-add-contract" onclick="openAddContractModal()">
                    ➕ Новый договор
                </button>
            </div>
        </div>

        <!-- ============================================================
        ПОИСК
        ============================================================ -->
        <div class="search-box">
            <label>🔍 Поиск</label>
            <input type="text" id="contract_live_search" placeholder="Имя клиента, № договора, продукция..." oninput="runLiveContractFilter(this.value)">
        </div>

        <!-- ============================================================
        ТАБЛИЦА
        ============================================================ -->
        <div class="table-wrapper" style="overflow-x: auto; max-height: 600px; overflow-y: auto;">

            <table class="contract-table">
                <thead>
                    <tr>
                        <th style="min-width: 240px;">Клиент / Договор</th>
                        <th style="min-width: 130px; text-align: center;">№ Договора</th>
                        <th style="min-width: 120px; text-align: center;">Дата дог.</th>
                        <th style="min-width: 140px; text-align: center;">Продукция</th>
                        <th style="min-width: 100px; text-align: center;">Отгрузки</th>
                        <th style="min-width: 130px; text-align: center;">Посл. отгрузка</th>
                        <th style="min-width: 200px; text-align: right;">Сумма отгрузок</th>
                        <th style="min-width: 100px; text-align: center;">Скан</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $lastClient = "";
                $totalByn = 0;
                $rates = ['BYN' => 1.0, 'USD' => 3.25, 'EUR' => 3.55, 'RUB' => 0.035, 'CNY' => 0.45];
                                                
                                        $clientContractCounts = [];
foreach ($rows as $r) {
    $name = $r['client_name'];
    if (!isset($clientContractCounts[$name])) {
        $clientContractCounts[$name] = 0;
    }
    // считаем только реальные договоры (исключая черновики, если нужно)
    // но если мы показываем все договоры, включая черновики, то считаем все
    $clientContractCounts[$name]++;
}

                foreach ($rows as $r):
                    $isNewGroup = ($r['client_name'] !== $lastClient);
                    $projectId = (int)($r['pid'] ?? 0);
                    $contractNum = trim($r['contract_number'] ?? '');
                    
                    if ($projectId === 0 || empty($contractNum) || $contractNum === 'Б/Н' || $contractNum === 'Пустой черновик') {
                        $lastClient = $r['client_name'];
                    }
                ?>
                    <?php if ($isNewGroup): ?>
                        <!-- ГРУППИРОВОЧНЫЙ ЗАГОЛОВОК КЛИЕНТА -->
                        <tr class="client-header">
                            <td colspan="8">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div style="display: flex; align-items: center; gap: 14px;">
                                        <span class="client-name">🏢 <?= htmlspecialchars($r['client_name']) ?></span>
                                       <span class="client-badge">Договоров: <?= $clientContractCounts[$r['client_name']] ?? 0 ?></span>
                                    </div>
                                    <button type="button" 
                                            data-client-id="<?= (int)($r['cid'] ?? 0) ?>"
                                            data-client-name="<?= htmlspecialchars($r['client_name'] ?? 'Контрагент', ENT_QUOTES, 'UTF-8') ?>"
                                            onclick="openContractModalFromRow(this)"
                                            class="btn-add-contract" style="font-size: 12px; padding: 6px 14px;">
                                        + Добавить договор
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php $lastClient = $r['client_name']; ?>
                    <?php endif; ?>

                    <!-- СТРОКА ДОГОВОРА -->
                    <tr class="contract-row">
                        <!-- 1. Договор -->
                        <td>
                            <div style="display: flex; flex-direction: column; gap: 2px;">
                                <span style="font-weight: 600; color: #fff;">
                                    Договор №<?= htmlspecialchars($contractNum) ?>
                                </span>
                                <span style="font-size: 11px; color: #6b6b85;">
                                    ID: #<?= $projectId ?>
                                </span>
                            </div>
                        </td>

                      <td style="text-align: center;">
    <div class="editable" 
         contenteditable="true" 
         data-f="contract_number" 
         data-id="<?= $projectId ?>"
         onblur="saveInlineContractNumber(this)"
         style="background: #0f0f1a; border: 1px solid #2a2a3f; border-radius: 6px; padding: 4px 10px; color: <?= ($contractNum === 'Черновик') ? '#f59e0b' : '#fff' ?>; font-weight: 600; font-size: 13px; outline: none; display: inline-block; min-width: 80px; text-align: center; cursor: text; transition: all 0.2s;">
        <?= htmlspecialchars($contractNum) ?>
    </div>
    <?php if ($contractNum === 'Черновик'): ?>
        <span style="display: inline-block; background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; margin-left: 6px;">черновик</span>
    <?php endif; ?>
</td>

                        <!-- 3. Дата договора -->
                        <td style="text-align: center;">
                            <input type="date" 
                                   value="<?= !empty($r['contract_date']) ? date('Y-m-d', strtotime($r['contract_date'])) : '' ?>"
                                   onchange="updateContractDateInline(<?= $projectId ?>, this.value)"
                                   class="date-inline">
                        </td>

                        <!-- 4. Продукция -->
                        <td style="text-align: center;">
                            <?php
                            $product = trim($r['product_type'] ?? '');
                            if (empty($product) || $product === 'NULL') $product = 'Сантехника';
                            ?>
                            <span class="prod-badge"><?= htmlspecialchars($product) ?></span>
                        </td>

                        <!-- 5. Кнопка ТТН -->
                        <td style="text-align: center;">
                            <button type="button" 
                                    data-id="<?= $projectId ?>"
                                    data-currency="<?= trim($r['main_contract_currency'] ?? 'BYN') ?>"
                                    onclick="openTtnManagerFromButton(this)"
                                    class="btn-ttn">
                                📦 ТТН
                            </button>
                        </td>

                        <!-- 6. Последняя отгрузка -->
                        <td style="text-align: center; color: #6b6b85; font-family: monospace; font-size: 12px;">
                            <?php 
                            $ld = $pdo->prepare("SELECT MAX(ttn_date) FROM project_ttns WHERE project_id = ?"); 
                            $ld->execute([$projectId]);
                            $d = $ld->fetchColumn(); 
                            echo $d ? date('d.m.Y', strtotime($d)) : '—';
                            ?>
                        </td>

                        <!-- 7. Сумма отгрузок -->
                        <td style="text-align: right;">
                            <?php 
                            $rawTotals = trim($r['ttn_currency_totals'] ?? '');
                            if (empty($rawTotals) || $rawTotals === '0.00' || $rawTotals === '0.00 BYN') {
                                echo '<span style="color: #6b6b85;">0.00</span> <span class="amount-currency">BYN</span>';
                            } else {
                                $parts = explode(' / ', $rawTotals);
                                $formattedParts = [];
                                
                                foreach ($parts as $part) {
                                    $part = trim($part);
                                    $lastSpacePos = strrpos($part, ' ');
                                    if ($lastSpacePos !== false) {
                                        $valNumeric = substr($part, 0, $lastSpacePos);
                                        $curCode = strtoupper(substr($part, $lastSpacePos + 1));
                                        
                                        $cColor = '#10b981';
                                        if ($curCode === 'RUB') $cColor = '#f59e0b';
                                        elseif ($curCode === 'USD') $cColor = '#6366f1';
                                        elseif ($curCode === 'EUR') $cColor = '#ec4899';
                                        elseif ($curCode === 'CNY') $cColor = '#a855f7';
                                        
                                        $formattedParts[] = "<span style='color: #fff;'>{$valNumeric}</span> <span style='color: {$cColor}; font-size: 10px; font-weight: 600;'>{$curCode}</span>";
                                    }
                                }
                                echo implode(' <span style="color: #323248; margin: 0 6px;">/</span> ', $formattedParts);
                            }
                            ?>
                        </td>

                        <!-- 8. Скан -->
                        <td style="text-align: center;">
                            <?php 
                            $scanUrl = trim($r['scan_path'] ?? '');
                            if (!empty($scanUrl) && $scanUrl !== 'NULL' && $scanUrl !== '0'):
                                $ext = strtolower(pathinfo($scanUrl, PATHINFO_EXTENSION));
                                $isPdf = ($ext === 'pdf');
                            ?>
                                <a href="<?= htmlspecialchars($scanUrl) ?>" target="_blank" 
                                   class="btn-scan <?= $isPdf ? 'btn-scan-pdf' : 'btn-scan-img' ?>">
                                    <?= $isPdf ? '👁 PDF' : '👁 ФОТО' ?>
                                </a>
                            <?php else: ?>
                                <label for="contract_file_input_<?= $projectId ?>" class="btn-scan-upload">
                                    📎 Скан
                                </label>
                                <input type="file" 
                                       id="contract_file_input_<?= $projectId ?>" 
                                       accept=".pdf,.jpg,.jpeg,.png" 
                                       style="display: none;" 
                                       onchange="uploadContractScanFast(<?= $projectId ?>, this)">
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: #4b4b5e; font-size: 14px;">
                            📭 Активных договоров не найдено
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>

        </div>

        <!-- ============================================================
        ФУТЕР С ИТОГАМИ
        ============================================================ -->
        
    </main>

    <!-- ============================================================
    МОДАЛЬНОЕ ОКНО ДОГОВОРА
    ============================================================ -->
    <div id="contractModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.75); justify-content: center; align-items: center; z-index: 99999; padding: 20px; backdrop-filter: blur(4px);">
        <div style="background: #1e1e2d; border: 1px solid #323248; border-radius: 16px; padding: 30px; width: 500px; max-width: 100%; box-shadow: 0 20px 50px rgba(0,0,0,0.5);">
            <h3 style="margin: 0 0 20px 0; color: #fff; font-size: 18px;">📋 Новый договор</h3>
            <form id="contractForm" method="POST" action="save.php" enctype="multipart/form-data">
                <input type="hidden" name="client_id" id="modal_client_id" value="">
                <input type="hidden" name="action" value="add_contract">
                
                <div style="margin-bottom: 15px;">
                    <label style="font-size: 11px; color: #92929f; font-weight: 700; text-transform: uppercase; display: block; margin-bottom: 6px;">Клиент</label>
                    <span id="modalClientName" style="color: #818cf8; font-weight: 600; font-size: 15px;">Загрузка...</span>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="font-size: 11px; color: #92929f; font-weight: 700; text-transform: uppercase; display: block; margin-bottom: 6px;">№ Договора *</label>
                    <input type="text" name="contract_number" id="edit_contract_number" required placeholder="Введите номер договора" style="width: 100%; height: 42px; padding: 0 14px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; font-size: 14px; box-sizing: border-box;">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="font-size: 11px; color: #92929f; font-weight: 700; text-transform: uppercase; display: block; margin-bottom: 6px;">Дата договора *</label>
                    <input type="date" name="contract_date" value="<?= date('Y-m-d') ?>" required style="width: 100%; height: 42px; padding: 0 14px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; font-size: 14px; color-scheme: dark; box-sizing: border-box;">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="font-size: 11px; color: #92929f; font-weight: 700; text-transform: uppercase; display: block; margin-bottom: 6px;">Валюта</label>
                    <select name="currency" style="width: 100%; height: 42px; padding: 0 14px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; font-size: 14px; box-sizing: border-box;">
                        <option value="BYN">BYN</option>
                        <option value="USD">USD</option>
                        <option value="EUR">EUR</option>
                        <option value="RUB">RUB</option>
                    </select>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="font-size: 11px; color: #92929f; font-weight: 700; text-transform: uppercase; display: block; margin-bottom: 6px;">Вид продукции</label>
                    <select name="product_type" required style="width: 100%; height: 42px; padding: 0 14px; background: #151521; border: 1px solid #323248; color: #818cf8; border-radius: 8px; outline: none; font-size: 14px; font-weight: 600; box-sizing: border-box;">
                        <option value="Посуда">Посуда</option>
                        <option value="Сантехника" selected>Сантехника</option>
                        <option value="Резервуары">Резервуары</option>
                        <option value="ЕКМ">ЕКМ</option>
                        <option value="МПДУ">МПДУ</option>
                        <option value="УОКТ">УОКТ</option>
                        <option value="другое">другое</option>
                    </select>
                </div>
                
                <div style="display: flex; justify-content: flex-end; gap: 12px; border-top: 1px solid #323248; padding-top: 15px;">
                    <button type="button" onclick="closeContractModal()" style="height: 40px; padding: 0 20px; background: transparent; border: 1px solid #323248; color: #92929f; border-radius: 8px; cursor: pointer; font-weight: 600;">Отмена</button>
                    <button type="submit" style="height: 40px; padding: 0 24px; background: #4f46e5; border: none; color: #fff; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 13px; transition: all 0.2s;" onmouseover="this.style.background='#6366f1'; this.style.boxShadow='0 4px 15px rgba(79,70,229,0.3)';" onmouseout="this.style.background='#4f46e5'; this.style.boxShadow='none';">Создать договор</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================
    СКРИПТЫ
    ============================================================ -->
    <script>
    // ============================================================
    // ОТКРЫТИЕ МОДАЛКИ ДОГОВОРА
    // ============================================================
    function openContractModalFromRow(button) {
        const clientId = parseInt(button.getAttribute('data-client-id'), 10);
        const clientName = button.getAttribute('data-client-name') || 'Контрагент';
        
        document.getElementById('modal_client_id').value = clientId;
        document.getElementById('modalClientName').innerText = clientName;
        document.getElementById('contractModal').style.display = 'flex';
        
        // Очищаем поля
        document.getElementById('edit_contract_number').value = '';
        document.getElementById('edit_contract_number').focus();
    }

    function openAddContractModal() {
        // Сначала показываем список клиентов для выбора
        // Упрощенная версия - открываем модалку и просим выбрать клиента
        const clientName = prompt('Введите название клиента для поиска:');
        if (!clientName) return;
        
        // Ищем клиента по названию через AJAX
        fetch('search_client.php?q=' + encodeURIComponent(clientName))
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success' && data.clients && data.clients.length > 0) {
                    // Если найден один клиент - открываем форму
                    if (data.clients.length === 1) {
                        const client = data.clients[0];
                        openContractModalFromRow({
                            getAttribute: (attr) => {
                                if (attr === 'data-client-id') return client.id;
                                if (attr === 'data-client-name') return client.client_name;
                                return null;
                            }
                        });
                    } else {
                        // Если несколько - показываем список
                        let list = data.clients.map(c => c.id + '. ' + c.client_name).join('\n');
                        const choice = prompt('Найдено несколько клиентов:\n' + list + '\n\nВведите ID нужного клиента:');
                        if (choice) {
                            const selected = data.clients.find(c => c.id == choice);
                            if (selected) {
                                openContractModalFromRow({
                                    getAttribute: (attr) => {
                                        if (attr === 'data-client-id') return selected.id;
                                        if (attr === 'data-client-name') return selected.client_name;
                                        return null;
                                    }
                                });
                            }
                        }
                    }
                } else {
                    alert('Клиент не найден. Сначала добавьте клиента в главной таблице.');
                }
            })
            .catch(err => {
                console.error('Ошибка поиска:', err);
                alert('Ошибка поиска клиента');
            });
    }

    // ============================================================
    // ЗАКРЫТИЕ МОДАЛКИ
    // ============================================================
    function closeContractModal() {
        document.getElementById('contractModal').style.display = 'none';
    }

    // ============================================================
    // ЖИВОЙ ПОИСК
    // ============================================================
function runLiveContractFilter(searchQuery) {
    const query = searchQuery.toLowerCase().trim();
    const table = document.querySelector('.contract-table');
    if (!table) return;

    // Получаем все строки таблицы
    const allRows = Array.from(table.querySelectorAll('tr'));
    
    // Строим группы: каждый заголовок и принадлежащие ему строки договоров
    const groups = [];
    let currentGroup = null;
    
    allRows.forEach(row => {
        // Проверяем, является ли строка заголовком клиента
        // используем класс или наличие иконки 🏢
        if (row.classList.contains('client-header') || row.innerText.includes('🏢')) {
            currentGroup = {
                header: row,
                rows: []
            };
            groups.push(currentGroup);
        } else if (currentGroup) {
            // Если это строка договора (обычно имеет класс contract-row или просто td)
            // Добавляем её в текущую группу
            currentGroup.rows.push(row);
        }
    });

    if (query === '') {
        groups.forEach(g => {
            g.header.style.display = '';
            g.rows.forEach(row => row.style.display = '');
        });
        return;
    }

    let anyFound = false;

    groups.forEach(g => {
        const headerText = g.header.innerText.toLowerCase();
        // Проверяем, содержит ли заголовок запрос
        let headerMatches = headerText.includes(query);
        // Проверяем, содержит ли какая-либо строка договора запрос
        let anyRowMatches = g.rows.some(row => row.innerText.toLowerCase().includes(query));
        
        if (headerMatches || anyRowMatches) {
            anyFound = true;
            g.header.style.display = '';
            g.rows.forEach(row => row.style.display = '');
        } else {
            g.header.style.display = 'none';
            g.rows.forEach(row => row.style.display = 'none');
        }
    });

    // Если ничего не найдено, показываем всё
    if (!anyFound) {
        groups.forEach(g => {
            g.header.style.display = '';
            g.rows.forEach(row => row.style.display = '');
        });
    }
}
    // ============================================================
    // СОХРАНЕНИЕ НОМЕРА ДОГОВОРА (INLINE)
    // ============================================================
    async function saveInlineContractNumber(element) {
        const pid = element.getAttribute('data-id');
        const newNumber = element.innerText.trim();
        
        if (!pid || pid === '0') return;
        if (newNumber === '' || newNumber === '—') {
            element.innerText = '—';
            return;
        }
        
        try {
            const res = await fetch('contracts.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action_mode=update_contract_number_fast&project_id=' + pid + '&contract_number=' + encodeURIComponent(newNumber)
            });
            const result = await res.json();
            
            if (result.status === 'success') {
                element.style.borderColor = '#10b981';
                setTimeout(() => {
                    element.style.borderColor = '#2a2a3f';
                }, 1000);
            }
        } catch (err) {
            console.error('Ошибка сохранения:', err);
        }
    }

    // ============================================================
    // ОБНОВЛЕНИЕ ДАТЫ ДОГОВОРА
    // ============================================================
    async function updateContractDateInline(projectId, newDateValue) {
        if (!projectId) return;
        
        try {
            const res = await fetch('contracts.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action_mode=update_contract_date_live&project_id=' + projectId + '&contract_date=' + newDateValue
            });
            const result = await res.json();
            
            if (result.status === 'success') {
                const input = event?.target;
                if (input) {
                    input.style.borderColor = '#10b981';
                    setTimeout(() => {
                        input.style.borderColor = '#323248';
                    }, 1000);
                }
            }
        } catch (err) {
            console.error('Ошибка обновления даты:', err);
        }
    }

    // ============================================================
    // ЗАГРУЗКА СКАНА
    // ============================================================
    async function uploadContractScanFast(pid, inputElement) {
        if (!inputElement.files || inputElement.files.length === 0) return;
        
        const file = inputElement.files[0];
        const fd = new FormData();
        fd.append('project_id', pid);
        fd.append('contract_scan', file);
        
        try {
            const res = await fetch('upload_scan.php', { method: 'POST', body: fd });
            const result = await res.json();
            
            if (result.status === 'success') {
                window.location.reload();
            } else {
                alert('Ошибка: ' + result.message);
            }
        } catch (err) {
            console.error('Ошибка загрузки:', err);
            alert('Ошибка загрузки файла');
        }
    }

    // ============================================================
    // ОТКРЫТИЕ ТТН
    // ============================================================
    // ============================================================
// ОТКРЫТИЕ ТТН - МОДАЛЬНОЕ ОКНО (НЕ РЕДИРЕКТ!)
// ============================================================
function openTtnManagerFromButton(button) {
    const pid = parseInt(button.getAttribute('data-id'), 10);
    const currency = button.getAttribute('data-currency') || 'BYN';
    
    if (!pid || pid <= 0) {
        alert('Ошибка: ID договора не найден');
        return;
    }
    
    console.log('📦 Открытие ТТН для договора ID:', pid);
    
    // Находим модалку
    const modal = document.getElementById('ttnManagerModal');
    if (!modal) {
        alert('Ошибка: модальное окно ТТН не найдено!');
        return;
    }
    
    // Заполняем данные
    document.getElementById('ttn_pid_storage').value = pid;
    document.getElementById('edit_ttn_id_storage').value = '';
    document.getElementById('ttn_currency_hidden').value = currency;
    
    // Очищаем форму
    document.getElementById('new_ttn_num').value = '';
    document.getElementById('new_ttn_quantity').value = '';
    document.getElementById('new_ttn_amount').value = '';
    document.getElementById('ttnFormTitle').innerText = 'Добавить новую отгрузку в рамках контракта:';
    document.getElementById('ttnSubmitBtn').innerText = '➕ Добавить в рамках контракта';
    
    // Обновляем валютный бейдж
    const badge = document.getElementById('js-ttn-currency-badge');
    if (badge) {
        badge.innerText = currency;
        let bColor = '#10b981';
        if (currency === 'RUB') bColor = '#f59e0b';
        if (currency === 'USD') bColor = '#6366f1';
        if (currency === 'EUR') bColor = '#ec4899';
        if (currency === 'CNY') bColor = '#a855f7';
        badge.style.color = bColor;
        badge.style.background = bColor + '20';
        badge.style.borderColor = bColor + '40';
    }
    
    const label = document.getElementById('ttnContractLabel');
    if (label) label.innerText = 'Договор ID: #' + pid;
    
    // ПОКАЗЫВАЕМ МОДАЛКУ
    modal.style.display = 'flex';
    
    // Загружаем список ТТН
    loadProjectTtnsPremium(pid);
}

    // ============================================================
    // ПОДСЧЕТ ИТОГОВ
    // ============================================================
    function calculateGrandTotal() {
        const totals = document.querySelectorAll('.contract-row td:nth-child(7)');
        let total = 0;
        
        totals.forEach(td => {
            const text = td.innerText.replace(/\s/g, '').replace(/,/g, '.');
            const match = text.match(/^([\d.]+)/);
            if (match) {
                total += parseFloat(match[1]) || 0;
            }
        });
        
        const target = document.getElementById('js-page-grand-total');
        if (target) {
            target.innerHTML = total.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' <span>BYN</span>';
        }
    }

    document.addEventListener('DOMContentLoaded', calculateGrandTotal);

    // ============================================================
    // ЗАКРЫТИЕ ПО ESC
    // ============================================================
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('contractModal');
            if (modal && modal.style.display === 'flex') {
                modal.style.display = 'none';
            }
        }
    });

    // ============================================================
    // ПОДСВЕТКА ДАТ
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        // Ничего не делаем, просто подсветка
    });
    </script>
<!-- ============================================================ -->
<!-- МОДАЛЬНОЕ ОКНО ТТН -->
<!-- ============================================================ -->
<div id="ttnManagerModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.75); justify-content: center; align-items: center; z-index: 99999; box-sizing: border-box; backdrop-filter: blur(5px);">
    <div style="background: #1e1e2d; padding: 25px 30px; border-radius: 16px; width: 550px; max-width: 95%; border: 1px solid #323248; box-shadow: 0 25px 50px rgba(0,0,0,0.6); color: #fff; display: flex; flex-direction: column; box-sizing: border-box; max-height: 90vh; overflow-y: auto;">

        <!-- Шапка -->
        <div style="display: flex; justify-content: space-between; align-items: center; flex-shrink: 0;">
            <h3 id="ttnContractLabel" style="margin: 0; font-size: 15px; font-weight: 700; color: #818cf8;">Договор ID: #--</h3>
            <button type="button" onclick="closeTtnManager()" style="background: none; border: none; color: #71717a; font-size: 22px; cursor: pointer; line-height: 1;">&times;</button>
        </div>

        <!-- Скрытые поля -->
        <input type="hidden" id="ttn_pid_storage" value="0">
        <input type="hidden" id="edit_ttn_id_storage" value="">
        <input type="hidden" id="ttn_currency_hidden" value="BYN">

        <!-- Список ТТН с ограничением высоты и скроллом -->
        <div style="text-align: left; width: 100%; flex-shrink: 0; margin-top: 12px;">
            <label style="font-size: 11px; color: #92929f; font-weight: 700; text-transform: uppercase; display: block; margin-bottom: 6px;">📋 Список накладных</label>
            <div id="projectTtnsListContainer" style="max-height: 180px; overflow-y: auto; background: #151521; border-radius: 8px; padding: 10px; border: 1px solid #323248; display: flex; flex-direction: column; gap: 6px;">
                <!-- Данные подгружаются через JS -->
            </div>
        </div>

        <!-- Форма добавления ТТН (с фиксированной кнопкой внизу) -->
        <div style="background: #242434; padding: 16px; border-radius: 12px; display: flex; flex-direction: column; gap: 10px; border: 1px solid #323248; margin-top: 14px; flex-shrink: 0;">
            <h4 id="ttnFormTitle" style="margin: 0; font-size: 12px; color: #818cf8; font-weight: 700; text-transform: uppercase;">➕ Добавить новую отгрузку</h4>

            <div style="display: flex; gap: 10px;">
                <input type="text" id="new_ttn_num" placeholder="№ ТТН" style="flex: 2; padding: 10px 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; font-size: 13px; box-sizing: border-box;">
                <input type="date" id="new_ttn_date" value="<?= date('Y-m-d') ?>" style="flex: 1; padding: 10px 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; font-size: 13px; color-scheme: dark; box-sizing: border-box;">
            </div>

            <div>
                <input type="number" id="new_ttn_quantity" placeholder="Кол-во (шт)" style="width: 100%; padding: 10px 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; font-size: 13px; box-sizing: border-box;">
            </div>

            <div style="display: flex; gap: 10px;">
                <input type="number" id="new_ttn_amount" step="0.01" placeholder="Сумма" style="flex: 2; padding: 10px 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; font-size: 13px; box-sizing: border-box;">
                <select id="ttn_currency_select" style="flex: 1; padding: 10px 12px; background: #151521; border: 1px solid #323248; color: #10b981; border-radius: 8px; outline: none; font-size: 13px; font-weight: 700; cursor: pointer; box-sizing: border-box;">
                    <option value="BYN">BYN</option>
                    <option value="RUB">RUB</option>
                    <option value="USD">USD</option>
                    <option value="EUR">EUR</option>
                    <option value="CNY">CNY</option>
                </select>
            </div>

            <div>
                <input type="text" id="new_ttn_prod" value="Сантехника" placeholder="Спецификация" style="width: 100%; padding: 10px 12px; background: #151521; border: 1px solid #323248; color: #818cf8; border-radius: 8px; outline: none; font-size: 13px; font-weight: 600; box-sizing: border-box;">
            </div>

            <!-- ✅ КНОПКА ДОБАВЛЕНИЯ (всегда видна) -->
            <button type="button" id="ttnSubmitBtn" onclick="submitTtnFormPremium()" style="width: 100%; background: #10b981; color: white; border: none; padding: 12px; border-radius: 8px; font-weight: bold; font-size: 13px; text-transform: uppercase; cursor: pointer; transition: all 0.2s;">
                ➕ Добавить отгрузку
            </button>
        </div>

        <!-- Закрыть -->
        <div style="display: flex; justify-content: flex-end; margin-top: 12px; flex-shrink: 0;">
            <button type="button" onclick="closeTtnManager()" style="background: #27273a; color: #a1a1aa; border: 1px solid #323248; padding: 8px 18px; border-radius: 8px; cursor: pointer;">Закрыть</button>
        </div>
    </div>
</div>
<script>
    // ============================================================
// ЗАГРУЗКА СПИСКА ТТН
// ============================================================

async function uploadTtnPdf(ttnId, projectId, input) {
    if (!input.files || !input.files.length) return;
    const fd = new FormData();
    fd.append('ttn_id', ttnId);
    fd.append('ttn_pdf', input.files[0]);

    try {
        const res = await fetch('upload_ttn_pdf.php', { method: 'POST', body: fd });
        const result = await res.json();
        if (result.status === 'success') {
            loadProjectTtnsPremium(projectId);
        } else {
            alert('Ошибка загрузки: ' + result.message);
        }
    } catch (err) {
        alert('Ошибка сети');
    }
}

async function deleteTtnPdf(ttnId, projectId) {
    // Проверяем, что ID передан и это число
    if (!ttnId || isNaN(ttnId) || ttnId <= 0) {
        alert('Ошибка: неверный ID накладной (получено: ' + ttnId + ')');
        console.error('deleteTtnPdf вызван с некорректным ID:', ttnId);
        return;
    }

    if (!confirm('Удалить прикреплённый PDF-файл?')) return;

    try {
        const res = await fetch('delete_ttn_pdf.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ttn_id: ttnId })
        });
        const result = await res.json();
        if (result.status === 'success') {
            loadProjectTtnsPremium(projectId);
        } else {
            alert('Ошибка удаления: ' + result.message);
        }
    } catch (err) {
        console.error('Ошибка сети при удалении:', err);
        alert('Ошибка сети');
    }
}
// ============================================================
// ОТПРАВКА НОВОЙ ТТН
// ============================================================
async function submitTtnFormPremium() {
    const pid = document.getElementById('ttn_pid_storage').value;
    const ttnNum = document.getElementById('new_ttn_num').value.trim();
    const ttnDate = document.getElementById('new_ttn_date').value;
    const ttnQty = document.getElementById('new_ttn_quantity').value;
    const ttnAmount = document.getElementById('new_ttn_amount').value;
    const ttnProd = document.getElementById('new_ttn_prod').value.trim();
    const ttnCurrency = document.getElementById('ttn_currency_select').value;
    
    if (!ttnNum || !ttnAmount) {
        alert('Заполните номер накладной и сумму!');
        return;
    }
    
    const fd = new FormData();
    fd.append('project_id', pid);
    fd.append('ttn_number', ttnNum);
    fd.append('ttn_date', ttnDate);
    fd.append('product_quantity', ttnQty || 0);
    fd.append('new_ttn_amount', ttnAmount);
    fd.append('product_info', ttnProd || 'Сантехника');
    fd.append('ttn_currency_select', ttnCurrency);
    
    try {
        const res = await fetch('save_ttn.php', { method: 'POST', body: fd });
        const result = await res.json();
        
        if (result.status === 'success') {
            document.getElementById('new_ttn_num').value = '';
            document.getElementById('new_ttn_amount').value = '';
            document.getElementById('new_ttn_quantity').value = '';
            loadProjectTtnsPremium(pid);
        } else {
            alert('Ошибка: ' + result.message);
        }
    } catch (err) {
        alert('Ошибка сети!');
    }
}
// ============================================================
// ЗАКРЫТИЕ МОДАЛКИ ТТН
// ============================================================
function closeTtnManager() {
    document.getElementById('ttnManagerModal').style.display = 'none';
}

// ============================================================
// ЗАГРУЗКА СПИСКА ТТН
// ============================================================
async function loadProjectTtnsPremium(pid) {
    const container = document.getElementById('projectTtnsListContainer');
    if (!container) return;

    container.innerHTML = '<span style="color:#818cf8; font-size:13px; padding:15px; text-align:center; display:block;">⏳ Загрузка...</span>';

    try {
        const res = await fetch('get_ttns.php?project_id=' + parseInt(pid, 10));
        const ttns = await res.json();

        if (!ttns || ttns.length === 0) {
            container.innerHTML = '<span style="color:#4b5563; font-size:13px; padding:15px; text-align:center; display:block;">📭 Отгрузок пока нет</span>';
            return;
        }

        let html = '';
        ttns.forEach(t => {
            const safeAmt = parseFloat(t.amount || 0).toFixed(2);
            const safeDate = t.ttn_date || '—';
            const currency = (t.currency || 'BYN').toUpperCase();

            let curColor = '#10b981';
            let curSymbol = 'BYN';
            if (currency === 'RUB') { curColor = '#f59e0b'; curSymbol = '₽'; }
            if (currency === 'USD') { curColor = '#6366f1'; curSymbol = '$'; }
            if (currency === 'EUR') { curColor = '#ec4899'; curSymbol = '€'; }
            if (currency === 'CNY') { curColor = '#a855f7'; curSymbol = '¥'; }

            // Управление сканом (как в таблице договоров)
            let scanHtml = '';
            if (t.scan_path && t.scan_path !== 'NULL' && t.scan_path !== '') {
                scanHtml = `
                    <a href="${t.scan_path}" target="_blank" style="color:#10b981; text-decoration:none; font-size:11px; font-weight:bold; background:#1a2e26; padding:4px 8px; border-radius:4px; margin-right:5px;">👁 PDF</a>
                    <button type="button" onclick="deleteTtnPdf(${t.id}, ${pid})" style="background:none; border:none; color:#f56565; cursor:pointer; font-size:12px; font-weight:bold;">❌</button>
                `;
            } else {
                scanHtml = `
                    <label for="ttn_file_input_${t.id}" style="cursor:pointer; color:#4f46e5; font-size:13px; padding:4px 8px; background:#1e1e2d; border:1px solid #323248; border-radius:4px; display:inline-block;">📎</label>
                    <input type="file" id="ttn_file_input_${t.id}" accept=".pdf" style="display:none;" onchange="uploadTtnPdf(${t.id}, ${pid}, this)">
                `;
            }

            html += `
                <div style="background: #151521; padding: 10px 14px; border-radius: 8px; border: 1px solid #323248; display: flex; justify-content: space-between; align-items: center;">
                    <div style="flex:1;">
                        <div style="font-weight: 600; color: #fff; font-size: 13px;">📄 ТТН №${t.ttn_number || '—'}</div>
                        <div style="color: #6b6b85; font-size: 11px;">📅 ${safeDate}</div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="font-weight: 700; color: ${curColor}; font-size: 14px; font-family: monospace;">${safeAmt} ${curSymbol}</div>
                        <div style="display: flex; align-items: center; gap: 4px;">${scanHtml}</div>
                    </div>
                </div>
            `;
        });
        container.innerHTML = html;
    } catch (err) {
        console.error('Ошибка загрузки ТТН:', err);
        container.innerHTML = '<span style="color:#ef4444; font-size:13px; padding:15px; text-align:center; display:block;">❌ Ошибка загрузки</span>';
    }
}
// ============================================================
// СОХРАНЕНИЕ НОВОЙ ТТН
// ============================================================
async function submitTtnFormPremium() {
    const pid = document.getElementById('ttn_pid_storage').value;
    const ttnNum = document.getElementById('new_ttn_num').value.trim();
    const ttnDate = document.getElementById('new_ttn_date').value;
    const ttnQty = document.getElementById('new_ttn_quantity').value || 0;
    const ttnAmount = document.getElementById('new_ttn_amount').value;
    const ttnProd = document.getElementById('new_ttn_prod').value.trim() || 'Сантехника';
    const ttnCurrency = document.getElementById('ttn_currency_select').value;
    
    if (!ttnNum) {
        alert('⚠️ Введите номер ТТН!');
        document.getElementById('new_ttn_num').focus();
        return;
    }
    if (!ttnAmount || parseFloat(ttnAmount) <= 0) {
        alert('⚠️ Введите сумму отгрузки!');
        document.getElementById('new_ttn_amount').focus();
        return;
    }
    
    const fd = new FormData();
    fd.append('project_id', pid);
    fd.append('ttn_number', ttnNum);
    fd.append('ttn_date', ttnDate || new Date().toISOString().split('T')[0]);
    fd.append('product_quantity', ttnQty);
    fd.append('new_ttn_amount', ttnAmount);
    fd.append('product_info', ttnProd);
    fd.append('ttn_currency_select', ttnCurrency);
    
    // Блокируем кнопку
    const btn = document.getElementById('ttnSubmitBtn');
    const originalText = btn.innerText;
    btn.innerText = '⏳ Сохранение...';
    btn.disabled = true;
    
    try {
        const res = await fetch('save_ttn.php', { method: 'POST', body: fd });
        const result = await res.json();
        
        if (result.status === 'success') {
            // Очищаем форму
            document.getElementById('new_ttn_num').value = '';
            document.getElementById('new_ttn_amount').value = '';
            document.getElementById('new_ttn_quantity').value = '';
            document.getElementById('new_ttn_prod').value = 'Сантехника';
            
            // Обновляем список
            await loadProjectTtnsPremium(pid);
            
            // Показываем успех
            btn.innerText = '✅ Добавлено!';
            setTimeout(() => {
                btn.innerText = originalText;
                btn.disabled = false;
            }, 1500);
        } else {
            alert('❌ Ошибка: ' + result.message);
            btn.innerText = originalText;
            btn.disabled = false;
        }
    } catch (err) {
        console.error('Ошибка:', err);
        alert('❌ Ошибка соединения с сервером!');
        btn.innerText = originalText;
        btn.disabled = false;
    }
}
</script>
</body>
</html>