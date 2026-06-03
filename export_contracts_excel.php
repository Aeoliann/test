<?php
// export_contracts_excel.php — Изолированный экспортер реестра контрактов Santeks CRM
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    die("Доступ запрещен. Авторизуйтесь.");
}

$userId = (int)$_SESSION['user_id'];
$u_role = $_SESSION['role'] ?? 'manager';

$searchQuery = isset($_GET['query']) ? trim($_GET['query']) : '';

try {
    $conditions = [];
    $params = [];
    
    // Фильтрация по поисковому слову (клиент, номер или продукция)
    if (!empty($searchQuery)) {
        $conditions[] = "(c.client_name LIKE ? OR p.contract_number LIKE ? OR p.product_type LIKE ?)";
        $params[] = "%$searchQuery%";
        $params[] = "%$searchQuery%";
        $params[] = "%$searchQuery%";
    }
    
    // ЖЕСТКИЙ ФИКС: Менеджер видит только свои договора, привязываясь к РЕАЛЬНОМУ полю c.manager_id таблицы клиентов!
    if ($u_role !== 'admin') {
        $conditions[] = "c.manager_id = ?";
        $params[] = $userId;
    }
    
    $whereSql = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    
    // БРОНЕБОЙНЫЙ ЗАПРОС: Считаем суммы ТТН из таблицы project_ttns строго по твоим реальным колонкам!
    $sql = "SELECT 
                p.id,
                p.contract_number,
                p.contract_date,
                p.product_type,
                c.client_name, 
                c.unp, 
                u.login AS manager_name,
                (SELECT COALESCE(SUM(t.amount), 0) 
                 FROM project_ttns t 
                 WHERE t.project_id = p.id) AS total_shipped_amount
            FROM projects p
            LEFT JOIN clients c ON p.client_id = c.id
            LEFT JOIN users u ON c.manager_id = u.id
            $whereSql
            ORDER BY p.id DESC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    die("Ошибка СУБД при экспорте контрактов: " . $e->getMessage());
}

// Формируем MIME-заголовки скачивания
$filename = "Santeks_Contracts_Report_" . date('Y-m-d_H-i') . ".xls";

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Cache-Control: max-age=0");
echo "\xEF\xBB\xBF"; // UTF-8 BOM
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://w3.org">
<head>
    <meta http-equiv="content-type" content="text/html; charset=utf-8">
</head>
<body>
    <table border="1">
        <thead>
            <tr style="background-color: #242434; color: #ffffff; font-weight: bold; text-align: center;">
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
                $sumRub = $sumByn * 28.5;
            ?>
                <tr>
                    <td style="text-align:center;"><?= $pp++ ?></td>
                    <td><?= htmlspecialchars($r['client_name'] ?? '') ?></td>
                    <td style="vnd.ms-excel.numberformat:@"><?= htmlspecialchars($r['unp'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['contract_number'] ?? '') ?></td>
                    <td style="text-align:center;"><?= !empty($r['contract_date']) ? date('d.m.Y', strtotime($r['contract_date'])) : '—' ?></td>
                    <td><?= htmlspecialchars($r['product_type'] ?? '') ?></td>
                    <td style="text-align:right; font-weight:bold;"><?= number_format($sumByn, 2, '.', '') ?></td>
                    <td style="text-align:right; color:#f59e0b;"><?= number_format($sumRub, 2, '.', '') ?></td>
                    <td><?= htmlspecialchars($r['manager_name'] ?? 'Не назначен') ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="9" style="text-align: center;">Контракты не найдены.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
