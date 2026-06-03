<?php
// export_contracts_excel.php — Изолированный экспортер реестра контрактов и отгрузок ТТН в Excel
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    die("Доступ запрещен. Авторизуйтесь.");
}

$userId = (int)$_SESSION['user_id'];
$u_role = $_SESSION['role'] ?? 'manager';

// Перехватываем строку живого поиска со страницы контрактов
$searchQuery = isset($_GET['query']) ? trim($_GET['query']) : '';

try {
    $conditions = [];
    $params = [];
    
    // Ограничение прав: менеджер выгружает только свои контракты (твое поле user_id)
    if ($u_role !== 'admin') {
        $conditions[] = "p.user_id = ?";
        $params[] = $userId;
    }
    
    // Фильтрация по поисковому слову (клиент, номер или продукция)
    if (!empty($searchQuery)) {
        $conditions[] = "(c.client_name LIKE ? OR p.contract_number LIKE ? OR p.product_type LIKE ?)";
        $params[] = "%$searchQuery%";
        $params[] = "%$searchQuery%";
        $params[] = "%$searchQuery%";
    }
    
    $whereSql = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    
    // ИСПРАВЛЕНО НАМЕРТВО: Выравнивание JOIN и GROUP BY строго по структуре твоей базы!
    $sql = "SELECT 
                p.id,
                p.contract_number,
                p.contract_date,
                p.product_type,
                c.client_name, 
                c.unp, 
                u.login AS manager_name,
                COALESCE(SUM(t.amount), 0) AS total_shipped_amount
            FROM projects p
            LEFT JOIN clients c ON p.client_id = c.id
            LEFT JOIN users u ON p.user_id = u.id
            LEFT JOIN project_ttns t ON p.id = t.project_id
            $whereSql
            GROUP BY 
                p.id, 
                p.contract_number, 
                p.contract_date, 
                p.product_type, 
                c.client_name, 
                c.unp, 
                u.login
            ORDER BY p.id DESC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    die("Ошибка СУБД при экспорте контрактов: " . $e->getMessage());
}
    

// Формируем имя файла и MIME-заголовки скачивания
$filename = "Santeks_Contracts_Report_" . date('Y-m-d_H-i') . ".xls";

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Cache-Control: max-age=0");
echo "\xEF\xBB\xBF"; // UTF-8 BOM для корректного отображения кириллицы в Excel
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://w3.org">
<head>
    <meta http-equiv="content-type" content="text/html; charset=utf-8">
    <style>
        table { border-collapse: collapse; }
        th { background-color: #242434; color: #ffffff; font-weight: bold; border: 1px solid #323248; text-align: center; height: 35px; }
        td { border: 1px solid #2b2b40; text-align: left; height: 30px; }
        .num-cell { text-align: center; }
    </style>
</head>
<body>
    <table border="1">
        <thead>
            <tr>
                <th>П/П</th>
                <th>Наименование организации / Клиент</th>
                <th>УНП</th>
                <th>№ Договора</th>
                <th>Дата договора</th>
                <th>Вид продукции</th>
                <th>Сумма отгрузок (BYN)</th>
                <th>Сумма отгрузок (RUB)</th>
                <th>Ответственный менеджер</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($rows)): $pp = 1; foreach ($rows as $r): 
                $sumByn = (float)$r['total_shipped_amount'];
                $sumRub = $sumByn * 28.5; // Наш внутренний мультивалютный коэффициент
            ?>
                <tr>
                    <td class="num-cell"><?= $pp++ ?></td>
                    <td><?= htmlspecialchars($r['client_name'] ?? '') ?></td>
                    <td style="vnd.ms-excel.numberformat:@"><?= htmlspecialchars($r['unp'] ?? '') ?></td> <!-- Защита от усечения нулей в УНП -->
                    <td><?= htmlspecialchars($r['contract_number'] ?? '') ?></td>
                    <td style="text-align:center;"><?= !empty($r['contract_date']) ? date('d.m.Y', strtotime($r['contract_date'])) : '—' ?></td>
                    <td><?= htmlspecialchars($r['product_type'] ?? '') ?></td>
                    <td style="text-align:right; font-weight:bold;"><?= number_format($sumByn, 2, '.', '') ?></td>
                    <td style="text-align:right; color:#f59e0b;"><?= number_format($sumRub, 2, '.', '') ?></td>
                    <td><?= htmlspecialchars($r['manager_name'] ?? '') ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="9" style="text-align: center;">Контракты не найдены.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
