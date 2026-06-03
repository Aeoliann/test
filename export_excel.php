<?php
// export_excel.php — Всеядный экспортер таблиц Santeks CRM в формат .xls (Excel)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    die("Доступ запрещен. Авторизуйтесь.");
}

$userId = (int)$_SESSION['user_id'];
$u_role = $_SESSION['role'] ?? 'manager';

// 1. Перехватываем режим выгрузки и текущие фильтры из URL
$mode         = isset($_GET['mode']) ? trim($_GET['mode']) : 'active'; // 'active' (рабочая база) или 'archive' (архив)
$searchQuery  = isset($_GET['query']) ? trim($_GET['query']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

// 2. Строим SQL-запрос динамически с точно такой же логикой фильтрации, как на экранах CRM
$conditions = [];
$params = [];

if ($mode === 'archive') {
    // Если выгружаем архив отказов (предполагаем статус 'Отказ' или поле is_archived = 1)
    // Адаптируй под свое имя колонки архива, если у тебя используется отдельный маркер
    $conditions[] = "c.status = 'Отказ'"; 
} else {
    // Рабочая база клиентов (исключаем отказы)
    if (!empty($statusFilter)) {
        $conditions[] = "c.status = ?";
        $params[] = $statusFilter;
    } else {
        $conditions[] = "c.status != 'Отказ'";
    }
}

// Учитываем поисковую строку по имени компании или УНП
if (!empty($searchQuery)) {
    $conditions[] = "(c.client_name LIKE ? OR c.unp LIKE ?)";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
}

// Ограничение видимости для менеджеров (видимы только свои клиенты)
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
    die("Ошибка СУБД при экспорте: " . $e->getMessage());
}

// 3. ФОРМИРУЕМ MIME-ЗАГОЛОВКИ WINDOWS ДЛЯ ДИАЛОГОВОГО ОКНА СОХРАНЕНИЯ EXCEL
$filename = ($mode === 'archive' ? "Santeks_Archive_Refusals_" : "Santeks_Client_Base_") . date('Y-m-d_H-i') . ".xls";

header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Cache-Control: max-age=0");

// Отправляем BOM-байты для корректного отображения кириллицы в Excel без кракозябр
echo "\xEF\xBB\xBF"; 
?>
<!-- Строим XML/HTML слепок структуры документа, который нативно открывается в Excel -->
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
                <th>Источник привлечения</th>
                <th>Ответственный менеджер</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($rows)): $pp = 1; foreach ($rows as $r): ?>
                <tr>
                    <td class="num-cell"><?= $pp++ ?></td>
                    <td><?= htmlspecialchars($r['client_name'] ?? '') ?></td>
                    <td style="vnd.ms-excel.numberformat:@"><?= htmlspecialchars($r['unp'] ?? '') ?></td> <!-- Предотвращает обрезание нулей в УНП -->
                    <td><?= htmlspecialchars($r['contact_person'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['phone'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['email'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['status'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['source'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['manager_name'] ?? 'Не назначен') ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="9" style="text-align: center;">Нет данных для экспорта с указанными фильтрами.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
