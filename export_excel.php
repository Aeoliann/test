<?php
// export_excel.php — Отказоустойчивый экспортер базы клиентов в Excel под Windows XAMPP
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    die("Доступ запрещен. Авторизуйтесь.");
}

$userRole  = $_SESSION['role'] ?? 'manager';
$userId    = (int)$_SESSION['user_id'];

// 1. ПЕРЕХВАТ ТЕКУЩИХ АКТИВНЫХ ФИЛЬТРОВ ИЗ ССЫЛКИ
$tab           = isset($_GET['tab']) ? trim($_GET['tab']) : 'active';
$filterManager = isset($_GET['manager_id']) ? (int)$_GET['manager_id'] : 0;
$sourceFilter  = isset($_GET['source']) ? trim($_GET['source']) : '';
$statusFilter  = isset($_GET['status']) ? trim($_GET['status']) : '';
$productFilter = isset($_GET['product_type']) ? trim($_GET['product_type']) : '';

if ($sourceFilter === 'Все источники') $sourceFilter = '';
if ($statusFilter === 'Все статусы')   $statusFilter = '';
if ($productFilter === 'Все виды')     $productFilter = '';

// 2. СБОРКА ДИНАМИЧЕСКОГО SQL-ЗАПРОСА
if ($userRole === 'admin') {
    if ($filterManager > 0) {
        $sql = ($tab === 'refused') 
            ? "SELECT * FROM clients WHERE manager_id = ? AND status = 'Отказ'" 
            : "SELECT * FROM clients WHERE manager_id = ? AND status != 'Отказ'";
        $params = [$filterManager];
    } else {
        $sql = ($tab === 'refused') 
            ? "SELECT * FROM clients WHERE status = 'Отказ'" 
            : "SELECT * FROM clients WHERE status != 'Отказ'";
        $params = [];
    }
} else {
    $sql = ($tab === 'refused') 
        ? "SELECT * FROM clients WHERE manager_id = ? AND status = 'Отказ'" 
        : "SELECT * FROM clients WHERE manager_id = ? AND status != 'Отказ'";
    $params = [$userId];
}

if (!empty($sourceFilter)) { $sql .= " AND source = ?"; $params[] = $sourceFilter; }
if (!empty($statusFilter) && $tab !== 'refused') { $sql .= " AND status = ?"; $params[] = $statusFilter; }
if (!empty($productFilter)) { $sql .= " AND product_type = ?"; $params[] = $productFilter; }

$sql .= " ORDER BY id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$clients = $stmt->fetchAll() ?: [];

// 3. ФОРМИРОВАНИЕ СИСТЕМНЫХ ЗАГОЛОВКОВ WINDOWS ДЛЯ СКАЧИВАНИЯ
$filename = "Santeks_CRM_Report_" . date('Y-m-d_H-i') . ".xls";
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Cache-Control: max-age=0");

// Выводим XLS-структуру с поддержкой UTF-8
echo "<html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:x='urn:schemas-microsoft-com:office:excel' xmlns='http://w3.org'>";
echo "<head><meta charset='utf-8'></head><body>";
echo "<table border='1'>";
echo "<tr style='background: #4f46e5; color: #fff; font-weight: bold;'>
        <th>ID</th>
        <th>Дата создания</th>
        <th>Наименование организации</th>
        <th>УНП</th>
        <th>Контактное лицо</th>
        <th>Телефон</th>
        <th>Email</th>
        <th>Статус</th>
        <th>Вид продукции</th>
        <th>Источник</th>
        <th>Последний комментарий</th>
      </tr>";

foreach ($clients as $c) {
    echo "<tr>";
    echo "<td>" . (int)$c['id'] . "</td>";
    echo "<td>" . htmlspecialchars($c['first_contact_date']) . "</td>";
    echo "<td>" . htmlspecialchars($c['client_name']) . "</td>";
    echo "<td>" . htmlspecialchars($c['unp']) . "</td>";
    echo "<td>" . htmlspecialchars($c['contact_person']) . "</td>";
    echo "<td>" . htmlspecialchars($c['phone']) . "</td>";
    echo "<td>" . htmlspecialchars($c['email']) . "</td>";
    echo "<td>" . htmlspecialchars($c['status']) . "</td>";
    echo "<td>" . htmlspecialchars($c['product_type']) . "</td>";
    echo "<td>" . htmlspecialchars($c['source'] ?? '—') . "</td>";
    echo "<td>" . htmlspecialchars($c['comment'] ?? '—') . "</td>";
    echo "</tr>";
}

echo "</table></body></html>";
exit;
