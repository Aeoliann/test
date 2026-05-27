<?php
// export_contracts_excel.php — Экспорт реестра договоров в Excel под Windows XAMPP
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    die("Доступ запрещен. Авторизуйтесь.");
}

$userRole = $_SESSION['role'] ?? 'manager';
$userId   = (int)$_SESSION['user_id'];

// 1. СБОР ДАННЫХ ИЗ БАЗЫ С УЧЕТОМ РОЛИ
try {
    if ($userRole === 'admin') {
        $sql = "SELECT p.*, c.client_name, 
                (SELECT COUNT(*) FROM project_ttns WHERE project_id = p.id) as ttn_count,
                (SELECT MAX(ttn_date) FROM project_ttns WHERE project_id = p.id) as last_ttn_date,
                (SELECT SUM(amount) FROM project_ttns WHERE project_id = p.id) as total_amount
                FROM projects p 
                INNER JOIN clients c ON p.client_id = c.id 
                ORDER BY c.client_name ASC, p.id DESC";
        $stmt = $pdo->query($sql);
    } else {
        $sql = "SELECT p.*, c.client_name, 
                (SELECT COUNT(*) FROM project_ttns WHERE project_id = p.id) as ttn_count,
                (SELECT MAX(ttn_date) FROM project_ttns WHERE project_id = p.id) as last_ttn_date,
                (SELECT SUM(amount) FROM project_ttns WHERE project_id = p.id) as total_amount
                FROM projects p 
                INNER JOIN clients c ON p.client_id = c.id 
                WHERE c.manager_id = ?
                ORDER BY c.client_name ASC, p.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
    }
    $projects = $stmt->fetchAll() ?: [];
} catch (Exception $e) {
    die("Ошибка СУБД при формировании отчета: " . $e->getMessage());
}

// 2. СИСТЕМНЫЕ ЗАГОЛОВКИ WINDOWS ДЛЯ СКАЧИВАНИЯ FILE
$filename = "Santeks_Contracts_Report_" . date('Y-m-d_H-i') . ".xls";
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Cache-Control: max-age=0");

// Выводим XLS-структуру
echo "<html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:x='urn:schemas-microsoft-com:office:excel' xmlns='http://w3.org'>";
echo "<head><meta charset='utf-8'></head><body>";
echo "<table border='1'>";
echo "<tr style='background: #4f46e5; color: #fff; font-weight: bold;'>
        <th>Дата создания</th>
        <th>Наименование организации (Клиент)</th>
        <th>№ Договора</th>
        <th>Тип продукции</th>
        <th>Дата договора</th>
        <th>Кол-во ТТН</th>
        <th>Дата последней отгрузки</th>
        <th>Сумма отгрузок (BYN)</th>
        <th>Пересчет (RUB)</th>
      </tr>";

$totalSumAll = 0;
foreach ($projects as $r) {
    $amt = (float)($r['total_amount'] ?? 0.00);
    $rub = $amt * 28.5;
    $totalSumAll += $amt;

    echo "<tr>";
    echo "<td>" . htmlspecialchars($r['created_at'] ?? '—') . "</td>";
    echo "<td>" . htmlspecialchars($r['client_name']) . "</td>";
    echo "<td>" . htmlspecialchars($r['contract_number'] ?? '—') . "</td>";
    echo "<td>" . htmlspecialchars($r['product_type'] ?? 'Прочее') . "</td>";
    echo "<td>" . htmlspecialchars($r['contract_date'] ?? '—') . "</td>";
    echo "<td style='text-align:center;'>" . (int)$r['ttn_count'] . "</td>";
    echo "<td style='text-align:center;'>trim" . htmlspecialchars($r['last_ttn_date'] ?? '—') . "</td>";
    echo "<td style='text-align:right;'>" . number_format($amt, 2, '.', '') . "</td>";
    echo "<td style='text-align:right;'>" . number_format($rub, 2, '.', '') . "</td>";
    echo "</tr>";
}

// Добавляем строку ИТОГО в конец таблицы файла
echo "<tr style='font-weight:bold; background:#f3f4f6;'>
        <td colspan='7' style='text-align:right;'>ИТОГО ПО ВСЕМ КЛИЕНТАМ:</td>
        <td style='text-align:right;'>" . number_format($totalSumAll, 2, '.', '') . " BYN</td>
        <td></td>
      </tr>";

echo "</table></body></html>";
exit;
