<?php
// directory.php — Исправленный бэкенд справочника с родными переменными $allClients и $search
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';

if (!isset($_SESSION['user_id'])) {
    die("Доступ запрещен. Авторизуйтесь.");
}

// ВОЗВРАЩАЕМ РОДНОЕ ИМЯ ПЕРЕМЕННОЙ ПОИСКА
$search = isset($_GET['query']) ? trim($_GET['query']) : '';

try {
    if ($search !== '') {
        // Частичный поиск по названию ИЛИ по УНП с использованием оператора LIKE
        $sql = "SELECT c.*, u.login AS manager_name 
                FROM clients c 
                LEFT JOIN users u ON c.manager_id = u.id 
                WHERE c.client_name LIKE ? OR c.unp LIKE ?
                ORDER BY c.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(["%$search%", "%$search%"]);
    } else {
        // Если поиск пустой — выгружаем весь справочник по умолчанию
        $sql = "SELECT c.*, u.login AS manager_name 
                FROM clients c 
                LEFT JOIN users u ON c.manager_id = u.id 
                ORDER BY c.id DESC";
        $stmt = $pdo->query($sql);
    }
    
    // ВОЗВРАЩАЕМ РОДНОЕ ИМЯ МАССИВА СТРОК ДЛЯ ТВОЕГО ЦИКЛА FOREACH
    $allClients = $stmt->fetchAll() ?: [];

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
        body { background: #151521; color: #fff; font-family: sans-serif; padding: 30px; margin: 0; box-sizing: border-box; }
        .directory-container { max-width: 1600px; margin: 0 auto; display: flex; flex-direction: column; gap: 20px; width: 100%; }
        
        /* КОМПАКТНАЯ ГОРИЗОНТАЛЬНАЯ ПАНЕЛЬ ПОИСКА НАВЕРХУ */
        .search-panel { 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            flex-wrap: wrap; 
            gap: 20px; 
            background: #1e1e2d; 
            padding: 15px 25px; 
            border-radius: 8px; 
            border: 1px solid #323248; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            width: 100%;
            box-sizing: border-box;
        }
        
        /* НЕЗАВИСИМЫЙ СКРОЛЛ-КОНТЕЙНЕР ДЛЯ ТАБЛИЦЫ СПРАВОЧНИКА */
        .table-scroll-box { 
            max-height: 650px; 
            overflow-y: auto; 
            overflow-x: auto; 
            width: 100%; 
            border: 1px solid #323248; 
            border-radius: 8px; 
            background: #1e1e2d; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            box-sizing: border-box;
        }
        
        table { width: 100%; border-collapse: collapse; margin: 0; table-layout: auto !important; }
        th { background: #242434; padding: 14px 10px; color: #92929f; text-transform: uppercase; font-size: 11px; font-weight: bold; text-align: center; border-bottom: 2px solid #323248; position: sticky; top: 0; z-index: 10; white-space: nowrap; }
        td { padding: 12px 10px; border-bottom: 1px solid #2b2b40; font-size: 13px; text-align: center; background: #1e1e2d; color: #fff; }
    </style>
</head>
<!-- ИСПРАВЛЕНО: Превратили страницу в двухколоночный Flex-контейнер, как во всей CRM -->
<body style="background: #151521; color: #fff; font-family: sans-serif; padding: 0; margin: 0; display: flex; min-height: 100vh;">

    <!-- ЛЕВАЯ КОЛОНКА: Боковая панель меню sidebar -->
    <aside style="width: 240px; background: #1e1e2d; border-right: 1px solid #323248; flex-shrink: 0; box-sizing: border-box;">
        <?php include 'sidebar.php'; ?>
    </aside>

    <!-- ПРАВАЯ КОЛОНКА: Справочник контента (весь твой код теперь живет внутри этого main) -->
    <main style="flex: 1; min-width: 0; padding: 30px; box-sizing: border-box; overflow-y: auto; display: flex; flex-direction: column; gap: 20px;">

        <!-- ВЕРХНЯЯ ГОРИЗОНТАЛЬНАЯ ПАНЕЛЬ ПОИСКА -->
      
    
    <!-- Форма шлет GET-запрос на саму себя при клике на Найти или Enter -->
   


            
            <!-- Форма мгновенного сканирования -->
           

            <a href="index.php" style="background: #242434; border: 1px solid #323248; color: #92929f; text-decoration: none; padding: 10px 15px; border-radius: 6px; font-size: 13px; font-weight: bold; transition: 0.15s;" onmouseover="this.style.color='#fff'; this.style.background='#2b2b3d';" onmouseout="this.style.color='#92929f'; this.style.background='#242434';">← В CRM</a>
              <div class="table-scroll-box" style="display:flex;">
            <input type="text" 
       id="directory_live_search" 
       placeholder="Быстрый фильтр по названию или УНП..." 
       oninput="runLiveDirectoryFilter(this.value)"
       style="height: 38px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; width: 100%; box-sizing: border-box;">
   <?php if ($search !== ''): ?>
    <a href="directory.php" style="color: #ef4444; text-decoration: none; font-size: 13px; padding-left: 8px; font-weight: bold;">Сбросить</a>
<?php endif; ?> 
        </div>
        
     <!-- ТАБЛИЦА СПРАВОЧНИКА В НЕЗАВИСИМОМ СКРОЛЛ-КОНТЕЙНЕРЕ -->
    
            <table>
                <thead>
                    <tr>
                        <th>п/п</th>
                        <th style="text-align: left;">Название организации</th>
                        <th>УНП</th>
                        <th>Вид продукции</th>
                        <th>Текущий статус</th>
                        <th>Ответственный менеджер</th>
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
<script>// ИСПРАВЛЕНО НАМЕРТВО: Живой сканирующий поиск, изолированный от инпута и шапки таблицы
function runLiveDirectoryFilter(searchQuery) {
    // Переводим запрос в нижний регистр и чистим случайные пробелы по краям
    const query = searchQuery.toLowerCase().trim();
    
    // ЖЕСТКАЯ СЕГМЕНТАЦИЯ: Ищем строки строго внутри tbody, чтобы не зацепить инпут в шапке или контейнере!
    const tableRows = document.querySelectorAll("table tbody tr");

    tableRows.forEach(row => {
        // Защита: если это служебная строка "Ничего не найдено", пропускаем её
        if (row.cells.length <= 1 && row.textContent.includes("не найдено")) {
            return;
        }

        // Забираем текстовый монолит строго из ячеек данных (Название, УНП, Продукция, Статус)
        const rowText = row.textContent.toLowerCase();

        if (query === "") {
            // Если поле пустое — мгновенно возвращаем видимость всем контрагентам
            row.style.display = "";
        } else if (rowText.includes(query)) {
            // Если есть совпадение по буквам или цифрам УНП — оставляем строку видимой
            row.style.display = "";
        } else {
            // Если совпадений нет — бесшумно скрываем строку с экрана
            row.style.display = "none";
        }
    });
}
</script>
</body>
</html>