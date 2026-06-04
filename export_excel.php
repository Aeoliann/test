<?php
// export_excel.php — Сквозной экспортер вкладок и фильтров по структуре Santeks CRM
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    die("Доступ запрещен. Авторизуйтесь.");
}

$userId = (int)$_SESSION['user_id'];
$u_role = $_SESSION['role'] ?? 'manager';

// Перехватываем ВСЕ переменные, которые шлет твоя кнопка
$current_tab   = isset($_GET['tab']) ? trim($_GET['tab']) : 'active'; // Твоя вкладка ('active' или 'archive'/'refusals')
$filterManager = isset($_GET['manager_id']) ? (int)$_GET['manager_id'] : 0;
$sourceFilter  = isset($_GET['source']) ? trim($_GET['source']) : '';
$statusFilter  = isset($_GET['status']) ? trim($_GET['status']) : '';
$productFilter = isset($_GET['product_type']) ? trim($_GET['product_type']) : '';
$searchQuery   = isset($_GET['query']) ? trim($_GET['query']) : '';

$conditions = [];
$params = [];

// =========================================================================
// КОРРЕКТНОЕ РАЗДЕЛЕНИЕ ВКЛАДОК И СЕЛЕКТОВ
// =========================================================================
if ($current_tab === 'archive' || $current_tab === 'refusals' || $statusFilter === 'Отказ') {
    // Если пользователь на вкладке Архива — жестко выгружаем только Отказы
    $conditions[] = "c.status = 'Отказ'";
} else {
    // Если на вкладке Рабочей базы — исключаем отказы и смотрим на селекты
    if (!empty($statusFilter) && $statusFilter !== 'Все') {
        $conditions[] = "c.status = ?";
        $params[] = $statusFilter;
    } else {
        $conditions[] = "c.status != 'Отказ'";
    }
}

// Применяем фильтр по источнику привлечения
if (!empty($sourceFilter) && $sourceFilter !== 'Все') {
    $conditions[] = "c.source = ?";
    $params[] = $sourceFilter;
}

// Применяем фильтр по типу продукции
if (!empty($productFilter) && $productFilter !== 'Все') {
    $conditions[] = "c.product_type = ?";
    $params[] = $productFilter;
}

// Применяем фильтр по конкретному менеджеру (если выбрано в панели)
if ($filterManager > 0) {
    $conditions[] = "c.manager_id = ?";
    $params[] = $filterManager;
}

// Учитываем живой поиск из инпута
if (!empty($searchQuery)) {
    $conditions[] = "(c.client_name LIKE ? OR c.unp LIKE ?)";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
}

// Ролевое ограничение: обычный менеджер скачивает ТОЛЬКО своих клиентов
if ($u_role !== 'admin') {
    $conditions[] = "c.manager_id = ?";
    $params[] = $userId;
}

$whereSql = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

try {
    $sql = "SELECT c.*, u.login AS manager_name 
            FROM clients c 
            LEFT JOIN users u ON c.manager_id = u.id 
            $whereSql 
            ORDER BY c.id DESC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    die("Ошибка СУБД при выгрузке: " . $e->getMessage());
}

// Формируем имя файла
$filename = (($current_tab === 'archive' || $statusFilter === 'Отказ') ? "Santeks_Archive_Refusals_" : "Santeks_Client_Base_") . date('Y-m-d_H-i') . ".xls";

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Cache-Control: max-age=0");
echo "\xEF\xBB\xBF"; // UTF-8 BOM против кракозябр
?>
<!-- Здесь продолжается твой стандартный HTML-код вывода таблицы Excel (из Шага 75) -->
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://w3.org">
<head>
    <meta http-equiv="content-type" content="text/html; charset=utf-8">
    <style>
        table { border-collapse: collapse; font-family: sans-serif; }
        th { background-color: #242434; color: #ffffff; font-weight: bold; border: 1px solid #323248; text-align: center; height: 35px; font-size: 12px; }
        td { border: 1px solid #2b2b40; text-align: left; height: 30px; font-size: 13px; padding: 4px; }
        .num-cell { text-align: center; color: #64748b; }
    </style>
</head>
<body>
    <table>
        <thead>
            <tr>
                <th>П/П</th>
                <th>Наименование организации / Клиент</th>
                <th>УНП</th>
                <th>Контактное лицо</th>
                <th>Телефон</th>
                <th>Email</th>
                <th>Текущий Статус</th>
                <th>Тип продукции</th>
                <th>Следующий контакт</th>
                <th>Источник</th>
                <th>Ответственный менеджер</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($rows)): $pp = 1; foreach ($rows as $r): ?>
                <tr>
                    <td class="num-cell"><?= $pp++ ?></td>
                    <td style="font-weight: bold;"><?= htmlspecialchars($r['client_name'] ?? '') ?></td>
                    <td style="vnd.ms-excel.numberformat:@; text-align: center;"><?= htmlspecialchars($r['unp'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['contact_person'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['phone'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['email'] ?? '') ?></td>
                    <td style="text-align: center; font-weight: bold;"><?= htmlspecialchars($r['status'] ?? '') ?></td>
                    <td style="text-align: center;"><?= htmlspecialchars($r['product_type'] ?? '—') ?></td>
                    <td style="text-align: center;"><?= !empty($r['next_contact_date']) ? date('d.m.Y', strtotime($r['next_contact_date'])) : '—' ?></td>
                    <td><?= htmlspecialchars($r['source'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['manager_name'] ?? 'Не назначен') ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="11" style="text-align: center; padding: 20px; color: #64748b; font-weight: bold;">Нет отфильтрованных данных для выгрузки в Excel.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
