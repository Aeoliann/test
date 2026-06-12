<?php
session_start();
require 'db.php';
require 'rates.php';


if (!isset($_SESSION['user_id'])) {
    header("Location: login.php"); exit;
}
// =========================================================================
// АВТОНОМНОЕ СОХРАНЕНИЕ ПРОДУКЦИИ ДЛЯ ДОГОВОРА ВНУТРИ CONTRACTS.PHP
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contract_number'])) {
    try {
        $client_id       = (int)($_POST['client_id'] ?? 0);
        $contract_number = trim($_POST['contract_number'] ?? '');
        $contract_date   = !empty($_POST['contract_date']) ? $_POST['contract_date'] : date('Y-m-d');
        $product_type    = isset($_POST['product_type']) ? trim($_POST['product_type']) : '';
        
        // ---- НАШ ВАЛЮТНЫЙ ХОТФИКС ----
        $currency        = trim($_POST['currency'] ?? 'BYN');
        // ------------------------------
        
        // Твоя оригинальная проверка вида продукции по карточке клиента
        if (empty($product_type) && $client_id > 0) {
            $getProdStmt = $pdo->prepare("SELECT product_type FROM clients WHERE id = ?");
            $getProdStmt->execute([$client_id]);
            $product_type = $getProdStmt->fetchColumn() ?: 'Прочее';
        }

        if ($client_id > 0 && !empty($contract_number)) {
            // Активируем транзакцию, чтобы данные не зависли на полпути
            $pdo->beginTransaction();

            // 1. ИСПРАВЛЕНО: Записываем валюту в новую колонку projects
            $sql = "INSERT INTO projects (client_id, contract_number, contract_date, product_type, currency) VALUES (?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$client_id, $contract_number, $contract_date, $product_type, $currency]);
            $new_project_id = $pdo->lastInsertId();
            
            // 2. Твое оригинальное обновление флага контракта у клиента
            $uClient = $pdo->prepare("UPDATE clients SET is_contract_signed = 1 WHERE id = ?");
            $uClient->execute([$client_id]);

            // 3. ИСПРАВЛЕНО: Пишем операцию в логгер на 5 параметров (logger.php)
            if (function_exists('logAction')) {
                // Параметры: $pdo, $actionType, $targetTable, $recordId, $description
                logAction($pdo, 'INSERT', 'projects', $new_project_id, "Создан договор №{$contract_number} (Валюта: {$currency}, Продукция: {$product_type}) для клиента ID {$client_id}");
            }

            $pdo->commit();
        }
        
        // ЧИСТЫЙ ПЕРЕЗАПУСК СТРАНИЦЫ: Браузер сам закроет модалку и обновит таблицу
        header("Location: contracts.php");
        exit;

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack(); // Откатываем базу, если что-то сломалось
        }
        die("Критический сбой СУБД при создании договора: " . $e->getMessage());
    }
}


$userId = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'manager';

if ($userRole === 'admin') {
    $sql = "SELECT c.id as cid, c.client_name, p.product_type, 
                   p.id as pid, p.contract_number, p.contract_date, p.scan_path 
            FROM clients c 
            LEFT JOIN projects p ON c.id = p.client_id 
            WHERE c.is_contract_signed = 1 
            ORDER BY c.client_name ASC, p.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
} else {
    $sql = "SELECT c.id as cid, c.client_name, p.product_type, 
                   p.id as pid, p.contract_number, p.contract_date, p.scan_path 
            FROM clients c 
            LEFT JOIN projects p ON c.id = p.client_id 
            WHERE c.is_contract_signed = 1 AND c.manager_id = ?
            ORDER BY c.client_name ASC, p.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
}

$rows = $stmt->fetchAll();
$savedCurrency = 'RUB';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Контракты и отгрузки</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cloudflare.com">
    
    
    <style>
        .type-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; border: none; cursor: pointer; }
        .type-new { background: #dbeafe; color: #1e40af; }
        .type-old { background: #fef9c3; color: #854d0e; }
        .reminder-alert { border: 2px solid #ef4444 !important; background-color: #fee2e2 !important; animation: pulse 2s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.7; } 100% { opacity: 1; } }
 .date-input-table {
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    padding: 4px 8px;
    font-family: inherit;
    font-size: 13px;
    color: #334155;
    background-color: #f8fafc;
    width: 100%;
    box-sizing: border-box;
    cursor: pointer;
}

.date-input-table:focus {
    outline: none;
    border-color: #4f46e5;
    background-color: white;
    box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.1);
}   
 </style>

</head>
<body>
    <aside>
        <?php include 'sidebar.php'; ?>
        <div class="logo">WebCRM</div>
    </aside>

    <main>
       <!-- ИСПРАВЛЕНО: Премиальный, синтаксически чистый и изолированный топбар без лишних тегов -->
<header style="width: 100%; display: flex; align-items: center; justify-content: space-between; padding: 16px 24px; background: #1e1e2d; border-bottom: 1px solid #323248; border-radius: 12px; margin-bottom: 25px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); box-sizing: border-box;">
    
    <!-- Левая часть: Заголовок страницы -->
    <div style="display: flex; align-items: center; gap: 12px;">
        <span style="font-size: 20px;">💼</span>
        <h1 style="margin: 0; font-size: 18px; font-weight: 700; color: #ffffff; letter-spacing: 0.3px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
            Учет договоров и проектов
        </h1>
    </div>

    <!-- Центральная часть: Статус авторизованного пользователя -->
    <div style="display: flex; align-items: center; gap: 8px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
        <span style="font-size: 13px; color: #92929f; font-weight: 500;">Вы:</span>
        <span style="background: rgba(168, 85, 247, 0.12); color: #a855f7; border: 1px solid rgba(168, 85, 247, 0.25); padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase;">
            👤 <?= htmlspecialchars($_SESSION['login'] ?? 'admin') ?>
        </span>
    </div>

    <!-- Правая часть: Кнопка экспорта в Excel -->
    <div>
        <a href="export_contracts_excel.php" style="display: inline-flex; align-items: center; gap: 8px; height: 38px; padding: 0 16px; background: #10b981; color: #ffffff; text-decoration: none; border-radius: 8px; font-size: 12px; font-weight: 700; letter-spacing: 0.3px; text-transform: uppercase; border: none; cursor: pointer; box-sizing: border-box; transition: all 0.15s; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;"
           onmouseover="this.style.background='#059669'; this.style.transform='translateY(-1px)';"
           onmouseout="this.style.background='#10b981'; this.style.transform='none';">
            📊 <span>Скачать отчет в Excel</span>
        </a>
    </div>

</header>


   <div style="display: flex; flex-direction: column; gap: 4px; width: 300 px;">
    
  <!-- ИСПРАВЛЕНО: Аккуратные премиум-отступы для блока быстрого поиска контракта -->
<div style="display: flex; flex-direction: column; gap: 6px; width: 300px; margin-top: 20px; margin-bottom: 20px; box-sizing: border-box; background: transparent;">
    <div style="display: flex; flex-direction: column; gap: 4px; width: 280px; box-sizing: border-box;">
        <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">Быстрый поиск контракта:</label>
        <input type="text" 
               id="contract_live_search" 
               placeholder="Имя, № договора, продукция..." 
               oninput="runLiveContractFilter(this.value)"
               style="height: 42px; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; box-sizing: border-box; font-size: 13px; width: 100%; transition: border-color 0.15s;"
               onfocus="this.style.borderColor='#4f46e5';"
               onblur="this.style.borderColor='#323248';">
    </div>
</div>
<script>
// ИСПРАВЛЕНО: Адаптивный сквозной поиск по Клиенту, Номеру договора и Виду продукции
function runLiveContractFilter(searchQuery) {
    const query = searchQuery.toLowerCase().trim();
    const tableRows = Array.from(document.querySelectorAll("table tbody tr"));

    if (query === "") {
        // Если инпут пустой — мгновенно возвращаем стопроцентную видимость всем строкам
        tableRows.forEach(row => row.style.display = "");
        return;
    }

    // Шаг 1: Сначала разбиваем всю таблицу на изолированные группы (Клиент + его контракты)
    let groups = [];
    let currentGroup = null;

    tableRows.forEach(row => {
        // Проверяем, является ли строка заголовком клиента.
        // Обычно у тебя строка клиента имеет отличительный фон (например, #242434) или жирный шрифт,
        // либо в ней ячейка td имеет атрибут colspan. Самый надежный способ — проверить наличие colspan.
        const isClientHeader = row.querySelector('td[colspan]') !== null || row.style.fontWeight === 'bold' || row.textContent.includes('📦') === false;

        if (isClientHeader) {
            // Началась новая группа компании
            if (currentGroup) groups.push(currentGroup);
            currentGroup = { header: row, childs: [] };
        } else {
            // Это строка контракта — пришиваем её к текущему родителю-клиенту
            if (currentGroup) {
                currentGroup.childs.push(row);
            }
        }
    });
    // Не забываем положить в массив последнюю обработанную группу
    if (currentGroup) groups.push(currentGroup);

    // Шаг 2: Сканируем каждую группу на совпадение букв из поиска
    groups.forEach(group => {
        // Проверяем текст в самом заголовке клиента
        let hasMatch = group.header.textContent.toLowerCase().includes(query);

        // Проверяем текст в каждом контракте этого клиента
        group.childs.forEach(child => {
            if (child.textContent.toLowerCase().includes(query)) {
                hasMatch = true; // Совпадение найдено в контракте!
            }
        });

        // Шаг 3: Управляем видимостью всей группы на экране
        if (hasMatch) {
            group.header.style.display = ""; // Показываем клиента
            group.childs.forEach(child => child.style.display = ""); // Показываем ВСЕ его контракты
        } else {
            group.header.style.display = "none"; // Прячем всю группу
            group.childs.forEach(child => child.style.display = "none");
        }
    });
}
</script>
<!-- ИСПРАВЛЕНО НАМЕРТВО: Запечатали таблицу договоров в рамки экрана с адаптивным горизонтальным скроллом -->
<!-- Контейнер таблицы с мягким скруглением и аккуратной тенью -->
<!-- ПРЕМИАЛЬНЫЙ КОНТЕЙНЕР ТАБЛИЦЫ С ЭФФЕКТОМ ГЛУБОКОЙ ТЕНИ -->
<!-- ИСПРАВЛЕНО: Сняли любые ограничения по ширине с внешнего контейнера коробки -->

<div style="display: flex; flex-direction: column; gap: 4px; width: 300px; margin-bottom: 20px; box-sizing: border-box;">
    
</div> <!-- ЗАКРЫВАЕМ ВНЕШНИЙ БЛОК, ОСВОБОЖДАЯ ТАБЛИЦУ -->   
    <!-- Кастомный премиум-скроллбар (Webkit) -->
    <style>
        div::-webkit-scrollbar { width: 8px; height: 8px; }
        div::-webkit-scrollbar-track { background: #13131a; border-radius: 16px; }
        div::-webkit-scrollbar-thumb { background: #2e2a47; border-radius: 16px; transition: 0.2s; }
        div::-webkit-scrollbar-thumb:hover { background: #4f46e5; }
    
    </style>
<!-- ИСПРАВЛЕНО: Полная двухкоординатная прокрутка масштабной таблицы -->
<div style="width: 100% !important; max-width: 100% !important; height: calc(100vh - 200px); max-height: 1000px; overflow-y: auto !important; overflow-x: auto !important; border: 1px solid #2e2a47; border-radius: 16px; background: #13131a; box-shadow: 0 20px 50px rgba(0,0,0,0.6); box-sizing: border-box; position: relative;">

  <table>
<style>* Принудительно заставляем каждую ячейку в теле таблицы наследоваться от ширины заголовка th */
table {
    table-layout: fixed !important;
    width: 100% !important;
    border-collapse: collapse !important;
}

/* Наследуем процентную ширину шапки во все нижние ячейки автоматически */
table tbody tr td {
    width: auto !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
}

/* Защита от переполнения ячеек: если текст шире колонки, он красиво уйдет в троеточие, не ломая верстку */
th, td {
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
}</style>
        
        <!-- ЖЕСТКАЯ СЕТКА КОЛОНОК (ЛИНИИ ШАПКИ И ТЕЛА СТАНУТ ИДЕАЛЬНО ПРЯМЫМИ) -->
      <!-- ЖЕСТКАЯ СЕТКА КОЛОНОК (ФИКС СДВИГА) -->
       <colgroup>
    <col style="width: 420px;">   <!-- 1. Клиент / Договор -->
    <col style="width: 130px;">   <!-- 2. № Договора -->
    <col style="width: 110px;">   <!-- 3. Дата дог. -->
    <col style="width: 130px;">   <!-- 4. Продукция -->
    <col style="width: 100px;">   <!-- 5. Отгрузки -->
    <col style="width: 130px;">   <!-- 6. Посл. отгрузка -->
    <col style="width: 150px;">   <!-- 7. Сумма (BYN) -->
    <col style="width: 180px;">   <!-- 8. Перерасчёт -->
    <col style="width: 90px;">    <!-- 9. Скан -->
</colgroup>

        <!-- РОСКОШНАЯ ЛИПКАЯ ШАПКА ТАБЛИЦЫ -->
                <!-- РОСКОШНАЯ ЛИПКАЯ ШАПКА ТАБЛИЦЫ С ЖЕСТКИМ ПОПИКСЕЛЬНЫМ ПОЗИЦИОНИРОВАНИЕМ -->
                <!-- АДАПТИВНАЯ ЛИПКАЯ ШАПКА ТАБЛИЦЫ (ПОДСТРАИВАЕТСЯ ПОД ЛЮБОЙ МОНИТОР И МАСШТАБ) -->
                <thead style="position: sticky; top: 0; z-index: 10; background: #161624;">
            <tr style="background: #161624; border-bottom: 2px solid #323248;">
                <!-- 1. : -->
                <th style="padding: 18px 16px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 1px; border: none !important; border-bottom: 2px solid #323248 !important; text-align: left; background: #161624; width: 30% !important; min-width: 250px; box-sizing: border-box;">Клиент / Договор</th>
                
                <!-- 2. № -->
                <th style="padding: 18px 12px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 1px; border: none !important; border-bottom: 2px solid #323248 !important; text-align: center; background: #161624; width: 9% !important; min-width: 90px; box-sizing: border-box;">№ Договора</th>
                
                <!-- 3. -->
                <th style="padding: 18px 12px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 1px; border: none !important; border-bottom: 2px solid #323248 !important; text-align: center; background: #161624; width: 8% !important; min-width: 80px; box-sizing: border-box;">Дата дог.</th>
                
                <!-- 4. -->
                <th style="padding: 18px 12px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 1px; border: none !important; border-bottom: 2px solid #323248 !important; text-align: left; background: #161624; width: 9% !important; min-width: 100px; box-sizing: border-box;">Продукция</th>
                
                <!-- 5. -->
                <th style="padding: 18px 12px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 1px; border: none !important; border-bottom: 2px solid #323248 !important; text-align: center; background: #161624; width: 7% !important; min-width: 75px; box-sizing: border-box;">Отгрузки</th>
                
                <!-- 6. . -->
                <th style="padding: 18px 12px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 1px; border: none !important; border-bottom: 2px solid #323248 !important; text-align: center; background: #161624; width: 9% !important; min-width: 95px; box-sizing: border-box;">Посл. отгрузка</th>
                
                <!-- 7. (BYN) -->
                <th style="padding: 18px 16px; color: #10b981; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 1px; border: none !important; border-bottom: 2px solid #323248 !important; text-align: right; background: #161624; width: 11% !important; min-width: 110px; box-sizing: border-box;">Сумма (BYN)</th>
                
                <!-- 8. -->
                <th style="padding: 18px 16px; color: #f59e0b; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 1px; border: none !important; border-bottom: 2px solid #323248 !important; text-align: right; background: #161624; width: 11% !important; min-width: 120px; box-sizing: border-box;">Перерасчёт</th>
                
                <!-- 9. -->
                <th style="padding: 18px 12px; color: #7f7f9c; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 1px; border: none !important; border-bottom: 2px solid #323248 !important; text-align: center; background: #161624; width: 6% !important; min-width: 70px; box-sizing: border-box;">Скан</th>
            </tr>
        </thead>




        <!-- БУФЕР КЛИЕНТСКИХ СТРОК С ЭФФЕКТАМИ ПОДСВЕТКИ -->
        <tbody>
            <?php 
            $lastClient = ""; 
            $totalByn = 0;
            $rates = (isset($globalRates) && is_array($globalRates)) ? $globalRates : ['BYN' => 1.0, 'USD' => 3.25, 'EUR' => 3.55, 'RUB' => 0.035, 'CNY' => 0.45];
            
            foreach ($rows as $r): 
                $isNewGroup = ($r['client_name'] !== $lastClient);
                
                // Финансовая агрегация
                $sumQuery = $pdo->prepare("SELECT SUM(amount) FROM project_ttns WHERE project_id = ?");
                $sumQuery->execute([$r['pid']]);
                $totalBynSum = (float)$sumQuery->fetchColumn();
                $totalByn += $totalBynSum;
                
                $savedCurrency = !empty($r['currency']) ? $r['currency'] : 'RUB';
                $rateValue = isset($rates[$savedCurrency]) ? (float)$rates[$savedCurrency] : 1.0;
                $convertedSum = ($rateValue > 0) ? ($totalBynSum / $rateValue) : 0;
                
                $projectId = (int)($r['pid'] ?? 0);
            ?>

            <?php if ($isNewGroup): ?>
                <!-- ГРУППИРОВОЧНЫЙ ЗАГРУЗОЧНЫЙ ДАШБОРД КОНТРАГЕНТА -->
                <tr style="background: rgba(99, 102, 241, 0.03); border-left: 4px solid #4f46e5;">
                    <td colspan="9" style="padding: 16px 20px; color: #fff; font-size: 14px; text-align: left; border: none !important; border-bottom: 2px solid #323248 !important; background: rgba(99, 102, 241, 0.02); box-sizing: border-box;">
                        <div style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <span style="color: #818cf8; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;">🏢 Клиент:</span>
                                <span style="color: #ffffff; font-size: 14px; font-weight: 700; letter-spacing: 0.3px;"><?= htmlspecialchars($r['client_name']) ?></span>
                                <span style="color: #4b5a75; font-size: 11px; font-weight: normal; margin-left: 4px;">(Все активные договора компании)</span>
                            </div>
                            
                            <button type="button" 
                                    onclick="openNewContractModal(<?= (int)$r['cid'] ?>); return false;" 
                                    style="background: rgba(79, 70, 229, 0.12); color: #818cf8; border: 1px solid rgba(129, 140, 248, 0.2); padding: 7px 16px; border-radius: 8px; font-weight: bold; font-size: 12px; cursor: pointer; transition: all 0.15s; font-family: sans-serif;"
                                    onmouseover="this.style.background='#4f46e5'; this.style.color='#fff'; this.style.borderColor='#4f46e5';"
                                    onmouseout="this.style.background='rgba(79, 70, 229, 0.12)'; this.style.color='#818cf8'; this.style.borderColor='rgba(129, 140, 248, 0.2)';">
                                ➕ Добавить договор
                            </button>
                        </div>
                    </td>
                </tr> <!-- ИСПРАВЛЕНО: Тег закрыт корректно, лишний текстовый скрипт полностью удален -->
                
                <?php $lastClient = $r['client_name']; ?>
<?php endif; ?>

            <!-- СТРОКА ДОГОВОРА С ВЫЛИЗАННЫМИ ПРЕМИУМ-ОТСТУПАМИ И ИНТЕРАКТИВНЫМ МАРКЕРОМ ХОВЕРА -->
            <tr style="border: none !important; border-bottom: 1px solid #1c1c28 !important; transition: all 0.15s ease;" onmouseover="this.style.background='#171725'; this.style.boxShadow='inset 4px 0 0 #4f46e5';" onmouseout="this.style.background='transparent'; this.style.boxShadow='none';">
                
                <!-- 1. Контрактный маркер -->
                <td style="padding: 14px 16px; text-align: left; color: #52526b; font-size: 13px; font-weight: 500; border: none !important; box-sizing: border-box;">
                    <span style="color: #32324d; margin-right: 6px;">↳</span> <?= !empty($r['contract_number']) ? 'Контрактный проект' : '<span style="color: #4b5563; font-style: italic;">Пустой черновик</span>' ?>
                </td>
                
                <!-- 2. № Договора инлайн -->
                <td style="padding: 14px 12px; text-align: center; border: none !important; box-sizing: border-box;">
                    <div class="editable" 
                         contenteditable="<?= (!empty($r['contract_number']) && $r['contract_number'] !== '—') ? 'false' : 'true' ?>" 
                         data-f="contract_number" 
                         data-id="<?= $projectId ?>" 
                         style="min-height: 22px; color: #ffffff; font-weight: 700; background: #13131a; padding: 5px 10px; border-radius: 6px; border: 1px solid #232334; outline: none; display: inline-block; min-width: 90px; box-sizing: border-box; font-size: 13px;"
                         onfocus="this.style.background='#fff'; this.style.color='#000'; this.style.borderColor='#4f46e5';"
                         onblur="this.style.background='#13131a'; this.style.color='#fff'; this.style.borderColor='#232334';">
                        <?= htmlspecialchars($r['contract_number'] ?: '—') ?>
                    </div>
                </td>
                
                <!-- 3. Дата договора -->
                <td style="padding: 14px 12px; color: #a1a1aa; font-size: 13px; text-align: center; font-family: monospace; font-weight: 500; border: none !important; box-sizing: border-box;">
                    <?= htmlspecialchars($r['contract_date'] ?? '—') ?>
                </td>
                
                <!-- 4. Вид продукции (Премиальный полупрозрачный бейдж) -->
                <td style="padding: 14px 12px; text-align: left; border: none !important; box-sizing: border-box;">
                    <?php
                    $currentRecord = isset($r) ? $r : [];
                    $individualProduct = !empty($currentRecord['product_type']) ? trim($currentRecord['product_type']) : '';
                    if (!empty($individualProduct) && $individualProduct !== 'NULL' && $individualProduct !== '—') {
                        echo '<span style="background: rgba(129, 140, 248, 0.08); color: #818cf8; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: bold; border: 1px solid rgba(129, 140, 248, 0.15); display: inline-block;">' . htmlspecialchars($individualProduct) . '</span>';
                    } else {
                        echo '<span style="color: #4b5563; font-weight: 500;">—</span>';
                    }
                    ?>
                </td>
                <!-- 5. Кнопка отгрузок ТТН (В тон общего плоского дизайна) -->
               <!-- 5. Кнопка отгрузок ТТН -->
            <!-- 5. Кнопка отгрузок ТТН (ИСПРАВЛЕНО: Убран конфликтующий вызов, синтаксис чист) -->
                <td style="padding: 14px 12px; text-align: center; border: none !important; box-sizing: border-box;">
                    <?php $line324_pid = (int)($r['pid'] ?? 0); ?>
                    <button type="button" 
                            data-id="<?= $line324_pid ?>"
                            onclick="window.currentTtnProjectId = <?= $line324_pid ?>; const modal = document.getElementById('ttnManagerModal'); if(modal){ modal.style.display = 'flex'; } const label = document.getElementById('ttnContractLabel'); if(label){ label.innerText = 'Системный ID договора: №' + <?= $line324_pid ?>; label.setAttribute('data-pid', <?= $line324_pid ?>); } loadProjectTtnsPremium(<?= $line324_pid ?>); return false;" 
                            style="background: #4f46e5; color: white; border: none; padding: 6px 14px; border-radius: 6px; font-weight: bold; font-size: 11px; cursor: pointer; transition: background 0.15s; font-family: sans-serif;">
                        📦 ТТН
                    </button>
                </td>
                
                <!-- 6. Дата последней отгрузки -->
                <td style="padding: 14px 12px; text-align: center; font-size: 13px; color: #a1a1aa; font-family: monospace; border: none !important; box-sizing: border-box;">
                    <?php 
                        $ld = $pdo->prepare("SELECT MAX(ttn_date) FROM project_ttns WHERE project_id = ?"); 
                        $ld->execute([$r['pid']]);
                        $d = $ld->fetchColumn(); 
                        echo $d ? date('d.m.Y', strtotime($d)) : '—';
                    ?>
                </td>
                
                <!-- 7. Базовая сумма в BYN -->
                <td style="padding: 14px 16px; text-align: right; font-size: 14px; font-family: monospace; font-weight: bold; border: none !important; box-sizing: border-box;">
                    <span class="js-byn-base" data-id="<?= $projectId ?>" style="color: #e4e4e7;"><?= number_format($totalBynSum, 2, '.', ' ') ?></span>
                    <span style="color: #4b5563; font-size: 11px; font-weight: normal; margin-left: 2px;">BYN</span>
                </td>
                
                <!-- 8. Мультивалютный перерасчет -->
                <td style="padding: 14px 16px; text-align: right; white-space: nowrap; border: none !important; box-sizing: border-box;">
                    <strong class="js-converted-value" data-id="<?= $projectId ?>" style="color: #10b981; font-size: 14px; margin-right: 6px; font-family: monospace;">
                        <?= number_format($convertedSum, 2, '.', ' ') ?>
                    </strong>
                    <select class="js-target-currency" data-id="<?= $projectId ?>" style="padding: 4px 6px; background: #13131a; border: 1px solid #232334; color: #10b981; border-radius: 6px; font-size: 12px; cursor: pointer; outline: none; font-weight: bold; font-family: sans-serif;">
                        <option value="RUB" <?= $savedCurrency === 'RUB' ? 'selected' : '' ?>>RUB</option>
                        <option value="USD" <?= $savedCurrency === 'USD' ? 'selected' : '' ?>>USD</option>
                        <option value="EUR" <?= $savedCurrency === 'EUR' ? 'selected' : '' ?>>EUR</option>
                        <option value="CNY" <?= $savedCurrency === 'CNY' ? 'selected' : '' ?>>CNY</option>
                        <option value="BYN" <?= $savedCurrency === 'BYN' ? 'selected' : '' ?>>BYN</option>
                    </select>
                </td>
                
              <!-- 9. Просмотр и загрузка PDF сканов (ФИНАЛЬНЫЙ ХОТФИКС СКРЕПКИ) -->
                <td style="padding: 14px 12px; text-align: center; border: none !important; box-sizing: border-box; background: transparent;">
                    <div style="display: inline-flex; align-items: center; gap: 6px; justify-content: center; width: 100%; background: transparent;">
                        <?php
                        $contractPath = isset($r['scan_path']) ? trim($r['scan_path']) : '';
                        
                        // Жесткая проверка: если путь не пустой, не NULL и не равен техническим нулям
                        if (!empty($contractPath) && $contractPath !== 'NULL' && $contractPath !== '0' && $contractPath !== 0): 
                        ?>
                            <!-- Если файл ЕСТЬ — выводим кнопку PDF -->
                            <a href="<?= htmlspecialchars($contractPath) ?>" 
                               target="_blank" 
                               style="color: #10b981; text-decoration: none; font-size: 11px; font-weight: bold; background: rgba(16, 185, 129, 0.1); padding: 5px 10px; border-radius: 6px; border: 1px solid rgba(16, 185, 129, 0.25); display: inline-block; white-space: nowrap; transition: 0.2s;"
                               onmouseover="this.style.background='rgba(16, 185, 129, 0.2)';"
                               onmouseout="this.style.background='rgba(16, 185, 129, 0.1)';">👁 PDF</a>
                            <button type="button" 
                                    onclick="if(confirm('⚠️ Вы уверены, что хотите БЕЗВОЗВРАТНО удалить скан договора?')){ window.location.href='delete_contract_file.php?pid=<?= $projectId ?>'; } return false;" 
                                    style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 14px; padding: 4px; display: inline-block; line-height: 1; transition: transform 0.15s;"
                                    onmouseover="this.style.transform='scale(1.2)';"
                                    onmouseout="this.style.transform='scale(1)';">❌</button>
                        <?php else: ?>
                            <!-- Если файла НЕТ — выводим красивую кнопку-скрепку загрузки -->
                            <label for="contract_file_<?= $projectId ?>" 
                                   style="cursor: pointer; color: #818cf8; font-size: 13px; padding: 5px 12px; background: #161624; border: 1px solid #232334; border-radius: 6px; display: inline-block; user-select: none; transition: all 0.15s; box-sizing: border-box;"
                                   onmouseover="this.style.background='#232334'; this.style.borderColor='#4f46e5';"
                                   onmouseout="this.style.background='#161624'; this.style.borderColor='#232334';">📎</label>
                            
                            <!-- ИСПРАВЛЕНО: возвращен точный индекс первого файла [0] в FormData пакете -->
                            <input type="file" 
                                   id="contract_file_<?= $projectId ?>" 
                                   accept=".pdf" 
                                   style="display: none;" 
                                   onchange="if(!this.files||!this.files.length)return; const fd=new FormData(); fd.append('pid',<?= $projectId ?>); fd.append('contract_pdf',this.files[0]); const path=window.location.pathname; const url=path.substring(0,path.lastIndexOf('/'))+'/upload_scan.php'; fetch(url,{method:'POST',body:fd}).then(r=>r.json()).then(res=>{ if(res.status==='success'){ window.location.reload(); }else{ alert('Ответ сервера:\n'+res.message); window.location.reload(); } }).catch(err=>alert('Ошибка сети или размера файла'));return false;">
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <script>
// Глобальный инициализатор модального окна контракта
function openNewContractModal(clientId) {
    console.log("Открытие формы договора для клиента ID:", clientId);
    
    const modal = document.getElementById('contractModal') || document.getElementById('newContractModal');
    const clientIdInput = document.getElementById('contract_client_id_storage') || document.getElementById('modal_client_id');
    const form = document.getElementById('contractForm');
    
    // Сбрасываем форму в дефолтный BYN только внутри функции при клике
    if (form) { 
        form.reset(); 
    }
    
    if (clientIdInput) { 
        clientIdInput.value = parseInt(clientId, 10); 
    }
    
    if (modal) { 
        modal.style.display = 'flex'; // Показываем окно только тут!
    }
}

// ХОТФИКС БЕЗОПАСНОСТИ: Принудительно прячем модалку при первой загрузке страницы, чтобы она не вылезала сама
document.addEventListener("DOMContentLoaded", function() {
    const modal = document.getElementById('contractModal') || document.getElementById('newContractModal');
    if (modal) {
        modal.style.display = 'none';
    }
});
</script>       
        </tbody>

<tfoot style="background: #1a1a26; font-weight: bold; border-top: 2px solid #4f46e5;">
    <tr>
        <!-- Пропускаем первые 6 колонок, чтобы надпись встала перед суммами -->
        <td colspan="10" style="text-align: right; padding: 15px; color: #92929f; font-size: 13px;">
            ИТОГО ПО ВСЕМ КЛИЕНТАМ:
        </td>
        
        <!-- КОЛОНКА ИТОГОВОЙ СУММЫ (Сюда JS запишет точный живой расчет) -->
        <td id="js-contracts-grand-total" style="text-align: right; color: #fff; padding-right: 15px; font-size: 15px; font-weight: bold;">
            0.00 BYN
        </td>
        
        <!-- Оставляем пустую ячейку под колонкой мультивалютного пересчета -->
        <td></td>
    </tr>
</tfoot>
            </table>
        </div>
 

    

<!-- ИСПРАВЛЕНО: Полный редизайн модалки договора и новые виды продукции -->
<!-- ИСПРАВЛЕНО UI/UX: Идеальное центрирование окна ровно по центру экрана менеджера -->
<div id="contractModal" 
     style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0, 0, 0, 0.7); z-index: 99999; display: flex; justify-content: center; align-items: center; box-sizing: border-box; backdrop-filter: blur(4px);">

    <!-- ВНУТРЕННИЙ БЛОК ОКНА (ИСПРАВЛЕНО: убраны проценты, центрируется автоматически) -->
    <div class="modal-content stylish-modal" style="background: #1e1e2d; border-radius: 16px; border: 1px solid #323248; padding: 30px; width: 480px; box-sizing: border-box; box-shadow: 0 25px 50px rgba(0,0,0,0.6); position: relative;">
        
        <!-- ХЕДЕР ОКНА -->
        <div class="modal-header" style="margin-bottom: 25px; text-align: left;">
            <h2 style="margin: 0; color: #ffffff; font-size: 16px; font-weight: 700; letter-spacing: 0.3px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
                📋 Новый договор: <span id="modalClientName" style="color: #818cf8; font-weight: 800;"></span>
            </h2>
        </div>

        <!-- ИСПРАВЛЕНО: Прямая, безотказная отправка данных на сервер в обход ломающегося JS -->
<!-- ИСПРАВЛЕНО: Форма отправляет данные напрямую силами браузера, минуя ломающийся JS -->
    <form id="contractForm" method="POST" action="contracts.php" style="margin: 0; padding: 0;">

            <!-- Скрытый маркер ID клиента, заполняемый силами JavaScript -->
            <input type="hidden" id="modal_client_id" name="client_id">
            
            <!-- Сетка: Номер и Дата (Выровнены в один ряд) -->
            <div class="form-row" style="display: flex; gap: 15px; margin-bottom: 16px; width: 100%; box-sizing: border-box;">
                <div class="form-group" style="flex: 1; display: flex; flex-direction: column; gap: 6px;">
                    <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">№ Договора *</label>
                    <input type="text" name="contract_number" placeholder="Напр: 125/А" required style="width: 100%; height: 42px; padding: 0 14px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; box-sizing: border-box; font-size: 13px; transition: all 0.15s ease;" onfocus="this.style.borderColor='#4f46e5'; this.style.background='#191926';" onblur="this.style.borderColor='#323248'; this.style.background='#151521';">
                </div>
                <div class="form-group" style="flex: 1; display: flex; flex-direction: column; gap: 6px;">
                    <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">Дата договора *</label>
                    <input type="date" name="contract_date" value="<?= date('Y-m-d') ?>" style="width: 100%; height: 42px; padding: 0 14px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; box-sizing: border-box; font-size: 13px; color-scheme: dark; font-weight: bold; transition: all 0.15s ease;" onfocus="this.style.borderColor='#4f46e5'; this.style.background='#191926';" onblur="this.style.borderColor='#323248'; this.style.background='#151521';">
                </div>
            </div>

            <!-- ---- ДИНАМИЧЕСКИЙ ХОТФИКС: ВЫБОР ВАЛЮТЫ ЗАКЛЮЧЕНИЯ КОНТРАКТА ---- -->
            <div class="form-group" style="display: flex; flex-direction: column; gap: 6px; margin-bottom: 20px; width: 100%; box-sizing: border-box;">
                <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">Валюта договора *</label>
                <select name="currency" required style="width: 100%; height: 42px; padding: 0 14px; background: #151521; border: 1px solid #323248; color: #10b981; border-radius: 8px; outline: none; box-sizing: border-box; font-size: 13px; font-weight: bold; cursor: pointer; transition: all 0.15s ease;" onfocus="this.style.borderColor='#4f46e5'; this.style.background='#191926';" onblur="this.style.borderColor='#323248'; this.style.background='#151521';">
                    <option value="BYN" selected>BYN (Белорусский рубль)</option>
                    <option value="RUB">RUB (Российский рубль)</option>
                    <option value="USD">USD (Доллар США)</option>
                    <option value="EUR">EUR (Евро)</option>
                    <option value="CNY">CNY (Китайский юань)</option>
                </select>
            </div>

                       <!-- Вид продукции (Новый актуальный список в VIP-стиле) -->
            <div class="form-group" style="display: flex; flex-direction: column; gap: 6px; margin-bottom: 25px; width: 100%; box-sizing: border-box;">
                <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">Вид продукции *</label>
                <select id="modal_contract_product_type" name="product_type" required style="width: 100%; height: 42px; padding: 0 14px; background: #151521; border: 1px solid #323248; color: #818cf8; border-radius: 8px; outline: none; cursor: pointer; font-size: 13px; font-weight: bold; box-sizing: border-box; transition: all 0.15s ease;" onfocus="this.style.borderColor='#4f46e5'; this.style.background='#191926';" onblur="this.style.borderColor='#323248'; this.style.background='#151521';">
                    <option value="Посуда">Посуда</option>
                    <option value="Сантехника" selected>Сантехника</option>
                    <option value="Резервуары">Резервуары</option>
                    <option value="ЕКМ">ЕКМ</option>
                    <option value="МПДУ">МПДУ</option>
                    <option value="Эмалированные таблички">Эмалированные таблички</option>
                    <option value="УОКТ">УОКТ</option>
                    <option value="другое">другое</option>
                </select>
            </div>

         <!-- Подвал: Кнопки (Разнесены по правому краю) -->
            <div class="modal-footer" style="display: flex !important; justify-content: flex-end !important; gap: 12px !important; margin-top: 25px !important; padding: 0 !important; background: transparent !important; background-color: transparent !important; border: none !important; border-top: none !important; box-shadow: none !important;">
                <button type="button" 
                        onclick="closeContractModal(); return false;" 
                        style="height: 42px; padding: 0 20px; background: #242434; border: 1px solid #323248; color: #92929f; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 13px; transition: all 0.15s ease;"
                        onmouseover="this.style.color='#fff'; this.style.borderColor='#4f46e5';"
                        onmouseout="this.style.color='#92929f'; this.style.borderColor='#323248';">
                    Отмена
                </button>
                
                <button type="submit" 
                        class="btn-contract-save" 
                        onclick="this.form.submit();"
                        style="height: 42px; padding: 0 24px; background: #4f46e5; border: none; color: #ffffff; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 13px; transition: all 0.15s ease; box-sizing: border-box;" 
                        onmouseover="this.style.background='#4338ca'; this.style.boxShadow='0 5px 15px rgba(79, 70, 229, 0.3)';" 
                        onmouseout="this.style.background='#4f46e5'; this.style.boxShadow='none';">
                    Создать договор
                </button>
            </div>

        </form>
    </div>
</div>

            
        </form>
    </div>
</div>

</main>
</body>
<!-- ИСПРАВЛЕНО: Полностью рабочий монолит формы управления ТТН/CMR Santeks CRM -->
<div id="ttnManagerModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0, 0, 0, 0.7); justify-content: center; align-items: center; z-index: 99999; box-sizing: border-box; backdrop-filter: blur(4px);">
     <div style="background: #1e1e2d; padding: 30px; border-radius: 16px; width: 500px; border: 1px solid #323248; box-shadow: 0 25px 50px rgba(0,0,0,0.6); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #fff; display: flex; flex-direction: column; gap: 16px; box-sizing: border-box; position: relative;">
        
        <!-- Шапка окна -->
     
        
        <!-- Скрытые технические хранилища ID (Заполняются силами JS) -->
        <input type="hidden" id="ttn_pid_storage" value="0">
        <input type="hidden" id="edit_ttn_id_storage" value="">
        
        <!-- КОНТЕЙНЕР ДЛЯ ВЫВОДА СПИСКА НАКЛАДНЫХ -->
        <label style="font-size: 11px; color: #92929f; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: -6px;">Список накладных по проекту:</label>
        <div id="ttnListContainer" style="max-height: 180px; min-height: 80px; overflow-y: auto; background: #151521; border-radius: 8px; padding: 12px; border: 1px solid #323248; display: flex; flex-direction: column; gap: 8px; box-sizing: border-box;">
            <!-- Данные подгружаются асинхронно через JS -->
        </div>

               <!-- ЧАСТЬ 2 МОДАЛКИ ТТН: ИЗОЛИРОВАННЫЙ БЛОК ПОЛЕЙ ВВОДА С МУЛЬТИВАЛЮТНОСТЬЮ -->
        <div style="background: #242434; padding: 18px; border-radius: 12px; display: flex; flex-direction: column; gap: 12px; text-align: left; box-sizing: border-box; border: 1px solid #323248;">
            <h4 id="ttnFormTitle" style="margin: 0; font-size: 13px; color: #818cf8; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Добавить новую отгрузку в рамках контракта:</h4>
            
            <!-- Номер ТТН и Дата -->
            <div style="display: flex; gap: 10px; width: 100%; box-sizing: border-box;">
                <input type="text" id="new_ttn_num" placeholder="№ ТТН / CMR" style="flex: 2; padding: 10px 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; font-size: 13px; transition: border-color 0.15s;" onfocus="this.style.borderColor='#4f46e5';" onblur="this.style.borderColor='#323248';">
                <input type="date" id="new_ttn_date" value="<?= date('Y-m-d') ?>" style="flex: 1; padding: 10px 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; font-size: 13px; color-scheme: dark; font-weight: bold; transition: border-color 0.15s;" onfocus="this.style.borderColor='#4f46e5';" onblur="this.style.borderColor='#323248';">
            </div>
            
            <!-- ИСПРАВЛЕНО НАМЕРТВО: Сумма по ТТН объединены с выбором валюты в одну строку -->
            <div style="display: flex; gap: 10px; width: 100%; box-sizing: border-box;">
                <input type="number" id="new_ttn_amount" step="0.01" placeholder="Сумма по накладной" oninput="calculateTtnBynLive()" style="flex: 2; padding: 10px 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; font-size: 13px; box-sizing: border-box; transition: border-color 0.15s;" onfocus="this.style.borderColor='#4f46e5';" onblur="this.style.borderColor='#323248';">
                
                <select id="ttn_currency_select" onchange="calculateTtnBynLive()" style="flex: 1; padding: 0 12px; background: #151521; border: 1px solid #323248; color: #10b981; border-radius: 8px; outline: none; font-size: 13px; font-weight: bold; cursor: pointer; height: 38px; transition: border-color 0.15s;" onfocus="this.style.borderColor='#4f46e5';" onblur="this.style.borderColor='#323248';">
                    <option value="BYN">BYN</option>
                    <option value="RUB">RUB</option>
                    <option value="USD">USD</option>
                    <option value="EUR">EUR</option>
                    <option value="CNY">CNY</option>
                </select>
            </div>

            <!-- БЛОК ПРЕВЬЮ АВТО-ПЕРЕСЧЕТА В БАЗОВУЮ BYN -->
            <div id="ttn_byn_preview_block" style="display: none; font-size: 11px; color: #a855f7; font-weight: bold; padding: 2px 4px; margin-top: -6px; letter-spacing: 0.3px;">
                В эквиваленте для системы: <span id="ttn_byn_preview_text" style="color: #10b981; font-family: monospace; font-size: 12px;">0.00 BYN</span>
            </div>
            
            <!-- Количество продукции -->
            <input type="number" id="new_ttn_quantity" placeholder="Количество продукции (шт)" style="width: 100%; padding: 10px 12px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 8px; outline: none; font-size: 13px; box-sizing: border-box; transition: border-color 0.15s;" onfocus="this.style.borderColor='#4f46e5';" onblur="this.style.borderColor='#323248';">
            
            <!-- Спецификация -->
            <div style="display: flex; flex-direction: column; gap: 4px; width: 100%; box-sizing: border-box;">
                <label style="font-size: 11px; color: #92929f; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">Спецификация (Товар):</label>
                <input type="text" 
                       id="new_ttn_prod" 
                       name="product_info" 
                       value="Сантехника" 
                       readonly 
                       style="height: 38px; padding: 0 12px; background: #161624; border: 1px solid #323248; color: #818cf8; border-radius: 8px; outline: none; font-size: 13px; font-weight: bold; box-sizing: border-box; width: 100%; cursor: not-allowed;" 
                       title="Данные автоматически подтянуты из связанного договора. Изменение запрещено.">
            </div>


            <!-- Чистая кнопка отправки с вызовом защищенной функции -->
            <button type="button" id="ttnActionBtn" onclick="saveTtnRecord(); return false;" style="background: #10b981; color: white; border: none; padding: 11px; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 13px; text-transform: uppercase; letter-spacing: 0.3px; margin-top: 4px; transition: all 0.15s ease; width: 100%; box-sizing: border-box;" onmouseover="this.style.background='#059669'; this.style.boxShadow='0 5px 15px rgba(16, 185, 129, 0.25)';" onmouseout="this.style.background='#10b981'; this.style.boxShadow='none';">
                Добавить в рамках контракта
            </button>
        </div>
   <!-- Кнопка закрытия окна (Перенесена в общий премиум-подвал) -->
        <div style="display: flex; justify-content: flex-end; margin-top: 4px; box-sizing: border-box;">
            <button type="button" onclick="closeTtnManager();" style="height: 38px; padding: 0 20px; background: #242434; border: 1px solid #323248; color: #92929f; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 13px; transition: all 0.15s ease; box-sizing: border-box;" onmouseover="this.style.color='#fff'; this.style.borderColor='#4f46e5';" onmouseout="this.style.color='#92929f'; this.style.borderColor='#323248';">
                Закрыть
            </button>
        </div>

    </div> <!-- Закрывает внутренний блок формы -->
</div> 


<script>

function calculateTtnBynLive() {
    const currencySelect = document.getElementById('ttn_currency_select');
    const amountInput = document.getElementById('new_ttn_amount');
    const previewBlock = document.getElementById('ttn_byn_preview_block');
    const previewText = document.getElementById('ttn_byn_preview_text');
    
    if (!currencySelect || !amountInput || !previewBlock || !previewText) return;

    const currency = currencySelect.value;
    const inputSum = parseFloat(amountInput.value) || 0;
    
    // Центральные зафиксированные курсы валют Santeks CRM
    const rates = {
        'BYN': 1.0,
        'USD': 3.25,
        'EUR': 3.55,
        'RUB': 0.035,
        'CNY': 0.45
    };
    
    const finalByn = (inputSum * (rates[currency] || 1.0)).toFixed(2);
    
    // Если менеджер выбирает иностранную валюту накладной, показываем подсказку пересчета в BYN
    if (currency !== 'BYN' && inputSum > 0) {
        previewBlock.style.display = 'block';
        previewText.innerText = finalByn + ' BYN';
    } else {
        previewBlock.style.display = 'none';
    }
}

// ХОТФИКС СБРОСА ФОРМЫ ТТН: Обязательно вставь этот сброс валюты в свою функцию closeTtnManager() или при открытии окна
function clearTtnCurrencyFormMemory() {
    const currencySelect = document.getElementById('ttn_currency_select');
    const previewBlock = document.getElementById('ttn_byn_preview_block');
    if (currencySelect) currencySelect.value = 'BYN';
    if (previewBlock) previewBlock.style.display = 'none';
}

async function executeContractUpload(pid, inputElement) {
    console.log("Старт движка. Отправляю договор для ID:", pid);
    
    if (!inputElement.files || !inputElement.files.length) {
        alert("Ошибка: Файл не выбран!");
        return;
    }

    const file = inputElement.files[0]; // Жестко изолируем первый бинарный файл

    // Собираем пакет FormData
    const fd = new FormData();
    fd.append('project_id', parseInt(pid));
    fd.append('contract_pdf', file);

    try {
        // ДИНАМИЧЕСКИЙ АВТОПОДБОР ТЕКУЩЕЙ ПАПКИ НА СЕРВЕРЕ
        // Вырезает из адреса "contracts.php" и подставляет имя нужного файла
        const currentPath = window.location.pathname;
        const targetUrl = currentPath.substring(0, currentPath.lastIndexOf('/')) + '/upload_scan.php';
        
        console.log("Автоматически вычисленный адрес отправки:", targetUrl);

        const response = await fetch(targetUrl, {
            method: 'POST',
            body: fd
        });

        // Считываем сырой текстовый ответ сервера
        const rawText = await response.text();
        console.log("Сырой ответ от сервера:", rawText);

        // Если сервер всё ещё отдает 404 Not Found
        if (rawText.includes('404 Not Found')) {
            alert("Критическая ошибка 404!\nБраузер обратился по адресу: " + targetUrl + "\nНо XAMPP говорит, что файла upload_scan.php там ФИЗИЧЕСКИ НЕТ.\n\nПроверь папку C:\\xampp\\htdocs\\... — лежит ли этот файл рядом с contracts.php?");
            return;
        }

        alert("Ответ сервера при загрузке договора:\n" + rawText);

        try {
            const result = JSON.parse(rawText);
            if (result.status === 'success') {
                window.location.reload();
            } else {
                alert("Ошибка сохранения: " + result.message);
            }
        } catch(e) {
            window.location.reload();
        }

    } catch (err) {
        console.error("Критический сбой сети:", err);
        alert("Критическая ошибка сети: " + err.message);
    }
}

// ИСПРАВЛЕНО НАМЕРТВО: Функция запрашивает данные у get_ttns.php и рисует список накладных
async function renderProjectTtnsList(pid) {
    console.log("Загрузка списка ТТН для договора ID:", pid);
    const container = document.getElementById('ttnListContainer');
    if (!container) return;

    container.innerHTML = '<div style="color:#64748b; font-size:12px; padding:10px; text-align:center;">Загрузка данных из СУБД...</div>';

    try {
        // Отправляем GET-запрос с явным указанием pid
        const res = await fetch('get_ttns.php?pid=' + parseInt(pid, 10));
        const data = await res.json();

        // Проверяем, не вернул ли сервер ошибку
        if (data.error) {
            container.innerHTML = '<div style="color:#f43f5e; font-size:12px; padding:10px;">Ошибка: ' + data.message + '</div>';
            return;
        }

        if (!data || data.length === 0) {
            container.innerHTML = '<div style="color:#4e4e6a; font-size:12px; padding:15px; text-align:center;">По этому договору отгрузок пока нет.</div>';
            return;
        }

        // Очищаем контейнер перед прорисовкой строк
        container.innerHTML = '';

        // Перебираем накладные и строим интерактивные строки карточек
        data.forEach(t => {
            const fileUrl = t.scan_path ? t.scan_path.trim() : '';
            let fileControls = '';

            // Логика управления файлом (PDF / Скрепка 📎)
            if (fileUrl !== '' && fileUrl !== 'NULL' && fileUrl !== '0') {
                fileControls += '<a href="' + fileUrl + '" target="_blank" style="color:#10b981; text-decoration:none; font-size:11px; font-weight:bold; background:#1a2e26; padding:4px 8px; border-radius:4px; margin-right:5px; display:inline-block;">👁 PDF</a>';
                fileControls += '<button type="button" onclick="removeTtnFile(' + t.id + ', ' + pid + ')" style="background:none; border:none; color:#f56565; cursor:pointer; font-size:12px; font-weight:bold; padding:4px;">❌</button>';
            } else {
                fileControls += '<label for="ttn_file_input_' + t.id + '" style="cursor:pointer; color:#4f46e5; font-size:13px; padding:4px 8px; background:#1e1e2d; border:1px solid #323248; border-radius:4px; display:inline-block;">📎</label>';
                fileControls += '<input type="file" id="ttn_file_input_' + t.id + '" accept=".pdf" style="display:none;" onchange="uploadTtnFile(' + t.id + ', ' + pid + ', this)">';
            }

            // Формируем саму строку накладной
            const itemHtml = `
                <div style="display:flex; justify-content:space-between; align-items:center; background:#1a1a24; padding:8px 12px; border-radius:6px; border:1px solid #2b2b40; font-size:13px;">
                    <div>
                        <strong style="color:#fff;">№ ${t.ttn_number}</strong> 
                        <span style="color:#64748b; font-size:11px; margin-left:8px;">${t.ttn_date}</span>
                        <span style="color:#10b981; font-weight:bold; margin-left:12px;">${parseFloat(t.amount).toFixed(2)} BYN</span>
                    </div>
                    <div>${fileControls}</div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', itemHtml);
        });

    } catch (err) {
        console.error("Сбой сети при получении ТТН:", err);
        container.innerHTML = '<div style="color:#f43f5e; font-size:12px; padding:10px; text-align:center;">Критический сбой подгрузки списка.</div>';
    }
}


// Глобальный маркер защиты от повторных сверхбыстрых кликов менеджеров
let isTtnSendingLock = false;

// ГЛОБАЛЬНЫЙ ПЕРЕХВАТЧИК КЛИКА: Открывает окно ТТН-менеджера по кнопке из таблицы
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.js-open-ttn-window-btn');
    if (btn) {
        e.preventDefault();
        
        const pid = btn.getAttribute('data-pid');
        const clientName = btn.getAttribute('data-name');
        
        console.log("Инициализация окна ТТН для договора ID:", pid);

        const modal = document.getElementById('ttnManagerModal');
        const pidStorage = document.getElementById('ttn_pid_storage');
        const labelEl = document.getElementById('ttnContractLabel');

        if (!modal || !pidStorage) return alert("Критическая ошибка: Элементы модалки ТТН отсутствуют на странице!");

        // Заполняем скрытые параметры
        pidStorage.value = pid;
        if (labelEl) labelEl.innerText = "Клиент: " + clientName;
        
        // Показываем окно и подгружаем список сохраненных накладных
        modal.style.display = 'flex';
        renderProjectTtnsList(pid);
    }
});

// 2. СВЕРХЗАЩИЩЕННОЕ АСИНХРОННОЕ СОХРАНЕНИЕ (ОТПРАВЛЯЕТ СТРОГО 1 ЗАПРОС НА КЛИК)
// ИСПРАВЛЕНО: Функция отправляет данные, сбрасывает форму и принудительно обновляет экран для пересчета сумм
// ИСПРАВЛЕНО: Безопасное считывание полей без падения JS из-за удаленного инпута количества
async function saveTtnRecord() {
    console.log("Старт отправки отгрузки ТТН...");

    // 1. Защита от повторного клика (Блокировка памяти)
    if (typeof isTtnSendingLock !== 'undefined' && isTtnSendingLock) {
        console.warn("Повторный клик заблокирован! Дождитесь ответа базы.");
        return;
    }

    // 2. Интеллектуальный сбор ID договора (Проверяем оперативную память, затем DOM-атрибут)
    let pid = window.currentTtnProjectId;
    if (!pid || isNaN(pid) || pid <= 0) {
        const labelElement = document.getElementById('ttnContractLabel');
        pid = labelElement ? parseInt(labelElement.getAttribute('data-pid'), 10) : 0;
    }
    
    console.log("Итоговый считанный для отправки project_id =", pid);

    if (isNaN(pid) || pid <= 0) {
        alert("Критическая ошибка: Системный ID договора потерян. Переоткройте окно ТТН.");
        return;
    }

    // 3. Извлечение базовых полей накладной с защитой от вылета скрипта
    const ttnId = document.getElementById('edit_ttn_id_storage') ? document.getElementById('edit_ttn_id_storage').value : '';
    const num = document.getElementById('new_ttn_num') ? document.getElementById('new_ttn_num').value.trim() : '';
    const date = document.getElementById('new_ttn_date') ? document.getElementById('new_ttn_date').value : '';
    const amt = document.getElementById('new_ttn_amount') ? document.getElementById('new_ttn_amount').value.trim() : '';
    
    const qtyInput = document.getElementById('new_ttn_quantity');
    const qty = qtyInput ? qtyInput.value.trim() : 0;

    const prodInput = document.getElementById('ttn_specification_fixed') || document.getElementById('new_ttn_prod');
    const prod = prodInput ? prodInput.value.trim() : 'Прочее';

    // ---- НАШ ВАЛЮТНЫЙ ПЕРЕХВАТЧИК ----
    const currencySelect = document.getElementById('ttn_currency_select');
    const currency = currencySelect ? currencySelect.value : 'BYN';
    // ----------------------------------

    if (!num || !amt) {
        alert("Заполните обязательные поля формы: Номер ТТН и Сумму отгрузки!");
        return;
    }

    try {
        // Активируем блокировку отправки и меняем текст на кнопке
        if (typeof isTtnSendingLock !== 'undefined') isTtnSendingLock = true;
        
        const btn = document.getElementById('ttnActionBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerText = "Запись в базу...";
        }

        // 4. Формируем классический POST-пакет параметров (Совместим с Apache/XAMPP)
        const params = new URLSearchParams();
        params.append('project_id', pid);
        params.append('ttn_id', ttnId);
        params.append('ttn_number', num);
        params.append('ttn_date', date);
        params.append('amount', parseFloat(amt));
        params.append('currency', currency); // Передаем валюту на бэкенд!
        params.append('product_quantity', parseInt(qty, 10) || 0);
        params.append('product_info', prod);

        const res = await fetch('save_ttn.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        });

        const result = await res.json();

        // Снимаем блокировку кнопки
        if (typeof isTtnSendingLock !== 'undefined') isTtnSendingLock = false;
        if (btn) {
            btn.disabled = false;
            btn.innerText = "Добавить в рамках контракта";
        }

        if (result.status === 'success') {
            console.log("ТТН успешно занесена в СУБД Santeks!");
            
            // Очищаем поля формы перед обновлением
            document.getElementById('edit_ttn_id_storage').value = '';
            document.getElementById('new_ttn_num').value = '';
            document.getElementById('new_ttn_amount').value = '';
            if (qtyInput) qtyInput.value = '';
            
            window.location.reload(); // Перезагрузка страницы для пересчета дашборда
        } else {
            alert("Ошибка базы данных Windows XAMPP:\n" + result.message);
        }
    } catch (err) {
        console.error("Критический сбой отправки ТТН:", err);
        if (typeof isTtnSendingLock !== 'undefined') isTtnSendingLock = false;
        
        const btn = document.getElementById('ttnActionBtn');
        if (btn) {
            btn.disabled = false;
            btn.innerText = "Добавить в рамках контракта";
        }
        alert("Ошибка соединения с сервером Apache. Проверьте логи консоли (F12).");
    }
}
// =========================================================================
// БРОНЕБОЙНЫЙ ДВИЖОК УПРАВЛЕНИЯ ТТН/CMR С ЗАЩИТОЙ ОТ ЗАНУЛЕНИЯ ДАННЫХ
// =========================================================================



// 2. Функция сохранения отгрузки ТТН в базу данных Windows XAMPP



// 3. ПОДСТАНОВКА В ФОРМУ ПРИ РЕДАКТИРОВАНИИ (КАРАНДАШ)
function prepareTtnToEdit(id, num, date, amount, qty, prod) {
    document.getElementById('edit_ttn_id_storage').value = id;
    document.getElementById('new_ttn_num').value = num;
    document.getElementById('new_ttn_date').value = date;
    document.getElementById('new_ttn_amount').value = amount;
    document.getElementById('new_ttn_quantity').value = qty;
    document.getElementById('new_ttn_prod').value = prod;

    document.getElementById('ttnFormTitle').innerText = 'Редактировать отгрузку №' + num + ':';
    const btn = document.getElementById('ttnActionBtn');
    if (btn) {
        btn.innerText = 'Сохранить изменения';
        btn.style.background = '#f6ad55';
    }
}

// 4. УДАЛЕНИЕ ФАЙЛА PDF У НАКЛАДНОЙ
// ИСПРАВЛЕНО НАМЕРТВО: Функция удаления шлет понятный для Windows XAMPP POST-пакет параметров
async function removeTtnFile(ttnId, pid) {
    if (!confirm("⚠️ Вы уверены, что хотите БЕЗВОЗВРАТНО удалить прикрепленный PDF-файл накладной?")) return;
    
    console.log("Запуск удаления файла для ТТН ID:", ttnId, "Договор PID:", pid);

    try {
        // Упаковываем параметры в стандартный формат веб-форм
        const params = new URLSearchParams();
        params.append('ttn_id', parseInt(ttnId, 10));

        const res = await fetch('delete_ttn_pdf.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded' // Классический, надежный тип передачи
            },
            body: params.toString() // Передаем в виде строки ttn_id=...
        });
        
        const result = await res.json();
        
        if (result.status === 'success') {
            console.log("Скан накладной успешно удален с сервера и очищен в СУБД!");
            
            // ЖЕСТКИЙ ПЕРЕЗАПУСК: Обновляем экран, чтобы кнопка просмотра моментально превратилась обратно в скрепку 📎
            window.location.reload();
        } else {
            alert("Ошибка удаления файла: " + result.message);
        }
    } catch (err) {
        console.error("Сбой сети при удалении файла ТТН:", err);
        alert("Ошибка соединения с сервером Windows XAMPP.");
    }
}

// 5. ПОТОКОВАЯ ЗАГРУЗКА PDF (СКРЕПКА)
async function uploadTtnFile(ttnId, pid, inputElement) {
    if (!inputElement.files || !inputElement.files.length) return;
    
    console.log("Отправка файла для ТТН ID:", ttnId, "Договор PID:", pid);
    
    const file = inputElement.files[0]; // Берем сам бинарный файл PDF
    const fd = new FormData();
    fd.append('ttn_id', parseInt(ttnId, 10)); // Передаем ID накладной ТТН
    fd.append('ttn_pdf', file); // Кладём файл в пакет под правильным именем ключа

    try {
        // Шлём AJAX-запрос на наш готовый обработчик
        const res = await fetch('upload_ttn_pdf.php', {
            method: 'POST',
            body: fd
        });
        
        const result = await res.json();
        
        if (result.status === 'success') {
            console.log("Файл успешно сохранен на сервере Windows XAMPP!");
            // Жестко перезапускаем страницу, чтобы скрепка мгновенно сменилась на кнопку просмотра PDF!
            window.location.reload();
        } else {
            alert("Ошибка СУБД: " + result.message);
        }
    } catch (err) {
        console.error("Сбой сети при загрузке скана ТТН:", err);
        alert("Критическая ошибка отправки файла на бэкенд.");
    }
}
// 6. ЗАКРЫТИЕ ОКНА
function closeTtnManager() {
    const modal = document.getElementById('ttnManagerModal');
    if (modal) { 
        modal.style.display = 'none'; 
    }
}
    // Передаем массив курсов из PHP в JS
const exchangeRates = <?= json_encode($globalRates) ?>;
document.addEventListener('change', async function(e) {
    if (e.target.classList.contains('js-target-currency') || e.target.classList.contains('js-currency-select')) {
        const select = e.target;
        const pid = select.dataset.id;
        const chosenCurrency = select.value;

        const bynEl = document.querySelector(`.js-byn-base[data-id="${pid}"]`);
        const targetEl = document.querySelector(`.js-converted-value[data-id="${pid}"]`);
        
        if (!bynEl || !targetEl) return;

        // Чистим строку от пробелов, чтобы JS не выдавал NaN
        const baseByn = parseFloat(bynEl.innerText.replace(/\s/g, '').replace(',', '.')) || 0;

        // Вызываем нашу точную функцию математики
        const finalConverted = getConvertedValue(baseByn, chosenCurrency);

        // Выводим результат в зеленую ячейку
        targetEl.innerText = finalConverted.toLocaleString('ru-RU', {
            minimumFractionDigits: 2, 
            maximumFractionDigits: 2
        });

        // Отправляем сохранение валюты в БД
        try {
            await fetch('update_contract_cell.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ id: pid, field: 'currency', value: chosenCurrency })
            });
        } catch (err) {
            console.error("Ошибка сохранения валюты:", err);
        }
    }
});
document.addEventListener('click', function(e) {
    const ttnBtn = e.target.closest('.js-btn-open-ttn-manager');
    if (ttnBtn) {
        e.preventDefault();
        
        const pid = ttnBtn.getAttribute('data-project-id');
        const clientName = ttnBtn.getAttribute('data-client-name');
        
        console.log("Открываю ТТН-менеджер для проекта ID:", pid);

        const modal = document.getElementById('ttnManagerModal');
        const storage = document.getElementById('ttn_pid_storage');
        const labelEl = document.getElementById('ttnContractLabel');

        if (!modal || !storage) {
            alert("Критическая ошибка интерфейса: Модальное окно ТТН не найдено в разметке HTML!");
            return;
        }

        // Записываем данные в заголовки и хранилище окна
        storage.value = pid;
        if (labelEl) labelEl.innerText = "Клиент: " + clientName;
        
        // Отображаем окно на экране
        modal.style.display = 'flex';
        
        // Запускаем асинхронную подгрузку списка ТТН
        loadProjectTtnsPremium(pid);
    }
});
// ИСПРАВЛЕНО: Функция открытия модалки принудительно прописывает ID договора и базовый товар
// ИСПРАВЛЕНО НАМЕРТВО: Глобальная фиксация ID договора для защиты от зануления в СУБД


async function executeSingleTtnSave() {
    console.log("Запущен уникальный движок сох  ранения ТТН...");
    

let isTtnSendingNow = false;

async function executeSingleTtnSave() {
    console.log("Попытка запуска движка сохранения ТТН...");
    
    // Если запрос уже летит прямо сейчас — намертво блокируем повторный запуск!
    if (isTtnSendingNow) {
        console.warn("Запрос уже отправляется на сервер! Повторный клик заблокирован.");
        return;
    }

    let pid = document.getElementById('ttn_pid_storage').value;
    if (!pid || parseInt(pid, 10) <= 0) {
        pid = window.currentTtnProjectId; // Берем резервную копию
    }
    const ttnId = document.getElementById('edit_ttn_id_storage') ? document.getElementById('edit_ttn_id_storage').value : '';
    const num = document.getElementById('new_ttn_num').value.trim();
    const date = document.getElementById('new_ttn_date').value;
    const amt = document.getElementById('new_ttn_amount').value.trim();
    const qty = document.getElementById('new_ttn_quantity') ? document.getElementById('new_ttn_quantity').value.trim() : '0';
    const prod = document.getElementById('new_ttn_prod').value.trim();

    if (!num || !amt) {
        alert("Пожалуйста, заполните обязательные поля: Номер ТТН и Сумму!");
        return;
    }

    try {
        // ВКЛЮЧАЕМ ЛОКЕР (Запрос пошел)
        isTtnSendingNow = true;
        
        const btn = document.getElementById('ttnActionBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerText = "Сохранение...";
        }

        const res = await fetch('save_ttn.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ ttn_id: ttnId, project_id: pid, ttn_number: num, ttn_date: date, amount: amt, product_quantity: qty, product_info: prod })
        });
        
        const result = await res.json();
        
        // ВЫКЛЮЧАЕМ ЛОКЕР после получения ответа от сервера
        isTtnSendingNow = false;
        if (btn) btn.disabled = false;

        if (result.status === 'success') {
            document.getElementById('edit_ttn_id_storage').value = '';
            document.getElementById('new_ttn_num').value = '';
            document.getElementById('new_ttn_amount').value = '';
            if (document.getElementById('new_ttn_quantity')) document.getElementById('new_ttn_quantity').value = '';
            document.getElementById('new_ttn_prod').value = '';
            
            document.getElementById('ttnFormTitle').innerText = 'Добавить новую отгрузку в рамках контракта:';
            if (btn) {
                btn.innerText = 'Добавить в рамках контракта';
                btn.style.background = '#10b981';
            }
            loadProjectTtnsPremium(pid);
        } else {
            alert("Ошибка базы данных: " + result.message);
        }
    } catch (err) {
        console.error("Сбой сети:", err);
        isTtnSendingNow = false; // Сбрасываем локер при ошибке сети
        const btn = document.getElementById('ttnActionBtn');
        if (btn) btn.disabled = false;
    }
}




    const pid = document.getElementById('ttn_pid_storage').value;
    const ttnId = document.getElementById('edit_ttn_id_storage') ? document.getElementById('edit_ttn_id_storage').value : '';
    const num = document.getElementById('new_ttn_num').value.trim();
    const date = document.getElementById('new_ttn_date').value;
    const amt = document.getElementById('new_ttn_amount').value.trim();
    const qty = document.getElementById('new_ttn_quantity') ? document.getElementById('new_ttn_quantity').value.trim() : '0';
    const prod = document.getElementById('new_ttn_prod').value.trim();

    if (!num || !amt) {
        alert("Пожалуйста, заполните обязательные поля: Номер ТТН и Сумму!");
        return;
    }

    try {
        const btn = document.getElementById('ttnActionBtn');
        if (btn) btn.disabled = true;

        const res = await fetch('save_ttn.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ 
                ttn_id: ttnId, 
                project_id: pid, 
                ttn_number: num, 
                ttn_date: date, 
                amount: amt, 
                product_quantity: qty, 
                product_info: prod 
            })
        });
        
        const result = await res.json();
        if (btn) btn.disabled = false;

        if (result.status === 'success') {
            // Очистка формы
            document.getElementById('edit_ttn_id_storage').value = '';
            document.getElementById('new_ttn_num').value = '';
            document.getElementById('new_ttn_amount').value = '';
            if (document.getElementById('new_ttn_quantity')) document.getElementById('new_ttn_quantity').value = '';
            document.getElementById('new_ttn_prod').value = '';
            
            document.getElementById('ttnFormTitle').innerText = 'Добавить новую отгрузку в рамках контракта:';
            if (btn) {
                btn.innerText = 'Добавить в рамках контракта';
                btn.style.background = '#10b981';
            }
            
            // Перерисовываем список ТТН на лету
            loadProjectTtnsPremium(pid);
        } else {
            alert("Ошибка базы данных: " + result.message);
        }
    } catch (err) {
        console.error("Сбой сети при отправке ТТН:", err);
        const btn = document.getElementById('ttnActionBtn');
        if (btn) btn.disabled = false;
    }
}
function closeTtnManager() {
    const modal = document.getElementById('ttnManagerModal');
    if (modal) {
        modal.style.display = 'none';
    }
    location.reload(); // Обновляем страницу, чтобы пересчитать суммы ТТН в таблице
}   

// 1. ПОДГРУЗКА СПИСКА ТТН (Крестик удаления файла теперь виден ВСЕМ)
// ИСПРАВЛЕНО НАМЕРТВО: Исправлен баг кавычек в блоке fileControls, список гарантированно отрендерится!
async function loadProjectTtnsPremium(pid) {
    const container = document.getElementById('ttnListContainer');
    if (!container) return;
    
    container.innerHTML = '<span style="color:#92929f; font-size:12px; padding:10px; display:block; text-align:left;">Загрузка списка отгрузок...</span>';

    try {
        const res = await fetch('get_ttns.php?pid=' + parseInt(pid, 10));
        const data = await res.json();
        
        let html = '<div style="display: flex; flex-direction: column; gap: 8px; width: 100%;">';
        
        if (data && data.length > 0) {
            data.forEach(function(t) {
                // ИСПРАВЛЕНО: Безопасное экранирование одинарных кавычек через HTML-сущность
                const safeProd = (t.product_info || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                const safeQty  = parseInt(t.product_quantity || 0, 10);
                const safeNum  = (t.ttn_number || '').replace(/"/g, '&quot;');
                const safeDate = (t.ttn_date || '');
                const safeAmt  = parseFloat(t.amount || 0).toFixed(2);
                
                // Считываем мультивалюту из твоего рабочего JSON
                const rawCurrency = (t.currency || 'BYN').toUpperCase();
                let currencyLabel = 'BYN';
                let curColor = '#10b981'; // BYN — изумрудный

                if (rawCurrency === 'RUB') {
                    currencyLabel = '₽ RUB';
                    curColor = '#f59e0b'; // RUB — янтарный
                } else if (rawCurrency === 'USD') {
                    currencyLabel = '$ USD';
                    curColor = '#6366f1'; // USD — индиго
                } else if (rawCurrency === 'EUR') {
                    currencyLabel = '€ EUR';
                    curColor = '#ec4899'; // EUR — розовый
                } else if (rawCurrency === 'CNY') {
                    currencyLabel = '¥ CNY';
                    curColor = '#a855f7'; // CNY — фиолетовый
                }
                // Управление файлами PDF (Исправлены все кавычки конкатенации шаблона)
                let fileControls = '';
                const fileUrl = t.scan_path ? t.scan_path.trim() : '';

                if (fileUrl !== '' && fileUrl !== 'NULL' && fileUrl !== '0') {
                    fileControls += `<a href="${fileUrl}" target="_blank" style="color:#10b981; text-decoration:none; font-size:11px; font-weight:bold; background:#1a2e26; padding:4px 8px; border-radius:6px; margin-right:5px; border:1px solid rgba(16,185,129,0.25); display:inline-block;">👁 PDF</a>`;
                    fileControls += `<button type="button" onclick="removeTtnFile(${t.id}, ${pid})" style="background:none; border:none; color:#ef4444; cursor:pointer; font-size:13px; font-weight:bold; padding:4px; margin-left:2px;">❌</button>`;
                } else {
                    fileControls += `<label for="ttn_file_input_${t.id}" style="cursor:pointer; color:#818cf8; font-size:13px; padding:4px 10px; background:#161624; border: 1px solid #323248; border-radius:6px; display:inline-block;">📎</label>`;
                    fileControls += `<input type="file" id="ttn_file_input_${t.id}" accept=".pdf" style="display:none;" onchange="uploadTtnFile(${t.id}, ${pid}, this)">`;
                }

                // Шаблон строки через чистые косые кавычки
                html += `
                <div style="background: #151521; padding: 12px 14px; border-radius: 8px; border: 1px solid #323248; display: flex; justify-content: space-between; align-items: center; width: 100%; box-sizing: border-box; margin-bottom: 2px;">
                    <div style="flex: 1; min-width: 0; padding-right: 10px; text-align:left;">
                        <div style="font-weight: bold; color: #fff; font-size: 13px; letter-spacing:0.2px;">ТТН № ${safeNum}</div>
                        <div style="color: #71717a; font-size: 11px; margin-top: 3px;">Дата: ${safeDate} | Кол-во: <strong style="color:#f59e0b; font-family: monospace;">${safeQty} шт.</strong></div>
                        <div style="color: #4b5a75; font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; font-weight: 500;">${t.product_info || 'Без спецификации'}</div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
                        <div style="font-weight: 700; color: ${curColor}; font-size: 13px; font-family: monospace; letter-spacing: -0.2px;">${safeAmt} ${currencyLabel}</div>
                        <div style="display: flex; align-items: center; margin-left: 4px;">${fileControls}</div>
                        <button type="button" onclick="editTtn(${t.id}, '${safeNum}', '${safeDate}', ${t.amount}, ${safeQty}, '${safeProd}', '${rawCurrency}')" style="background:none; border:none; color:#f59e0b; cursor:pointer; font-size:13px; padding:4px; margin-left:3px;">✏️</button>
                    </div>
                </div>`;
            });
        } else {
            html += '<span style="color:#4b5563; font-size:12px; padding:20px; display:block; text-align:center; font-style: italic;">Отгрузок в рамках контракта пока нет</span>';
        }
        
        html += '</div>';
        container.innerHTML = html;

    } catch (err) {
        console.error("Сбой loadProjectTtnsPremium:", err);
        container.innerHTML = '<span style="color:#ef4444; font-size:12px; padding:10px; display:block;">Критическая ошибка загрузки ТТН</span>';
    }
}

// 3. ПЕРЕНОС ДАННЫХ В ПОЛЯ ПРИ КЛИКЕ НА КАРАНДАШ
function editTtn(id, num, date, amount, qty, prod, currency) {
    document.getElementById('edit_ttn_id_storage').value = id;
    document.getElementById('new_ttn_num').value = num;
    document.getElementById('new_ttn_date').value = date;
    document.getElementById('new_ttn_amount').value = amount;
    
    // Безопасное заполнение спецификации
    const prodInput = document.getElementById('new_ttn_prod') || document.getElementById('ttn_specification_fixed');
    if (prodInput) {
        prodInput.value = prod || 'Сантехника';
    }

    // ТАКЖЕ ЛОВИМ КОЛИЧЕСТВО (Чтобы оно не сбрасывалось при клике на карандаш)
    const qtyInput = document.getElementById('new_ttn_quantity');
    if (qtyInput) {
        qtyInput.value = qty || 0;
    }

    // ---- НАШ ВАЛЮТНЫЙ ПЕРЕКЛЮЧАТЕЛЬ ДЛЯ РЕДАКТИРОВАНИЯ ----
    const currencySelect = document.getElementById('ttn_currency_select');
    if (currencySelect) {
        // Принудительно ставим селект в валюту накладной из СУБД
        currencySelect.value = currency ? currency.toUpperCase() : 'BYN';
    }
    
    // Мгновенно пересчитываем превью эквивалента в BYN на лету
    if (typeof calculateTtnBynLive === 'function') {
        calculateTtnBynLive();
    }
    // --------------------------------------------------------

    if(document.getElementById('ttnFormTitle')) {
        document.getElementById('ttnFormTitle').innerText = 'Редактировать отгрузку №' + num + ':';
    }
    const btn = document.getElementById('ttnActionBtn');
    if (btn) {
        btn.innerText = 'Сохранить изменения';
        btn.style.background = '#f59e0b'; // Роскошный янтарный цвет для режима правки
    }
}
// 4. БЕЗВОЗВРАТНОЕ УДАЛЕНИЕ PDF С СЕРВЕРА
async function deleteTtnPdf(ttnId, pid) {
    if (!confirm("Вы уверены, что хотите БЕЗВОЗВРАТНО удалить PDF-файл?")) return;
    try {
        const res = await fetch('delete_ttn_pdf.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ttn_id: parseInt(ttnId) })
        });
        const result = await res.json();
        if (result.status === 'success') loadProjectTtnsPremium(pid);
        else alert(result.message);
    } catch (err) { alert("Ошибка связи с сервером"); }
}

// 5. ПОТОКОВАЯ ЗАГРУЗКА PDF
async function uploadTtnPdf(ttnId, pid, input) {
    if (!input.files || !input.files.length) return;
    const file = input.files[0];
    const fd = new FormData();
    fd.append('ttn_id', ttnId);
  fd.append('ttn_pdf', inputElement.files[0]); 

    try {
        const res = await fetch('upload_ttn_pdf.php', { method: 'POST', body: fd });
        if ((await res.json()).status === 'success') loadProjectTtnsPremium(pid);
    } catch (err) { alert("Ошибка загрузки файла"); }
}


document.addEventListener('click', function(e) {
    const editBtn = e.target.closest('.js-ttn-edit-btn');
    
    if (editBtn) {
        e.preventDefault();
        
        // 1. Безопасно вытаскиваем данные ТТН из атрибутов кнопки
        const id = editBtn.getAttribute('data-ttn-id') || '';
        const num = editBtn.getAttribute('data-ttn-num') || '';
        const date = editBtn.getAttribute('data-ttn-date') || '';
        const amount = editBtn.getAttribute('data-ttn-amount') || '';
        const prod = editBtn.getAttribute('data-ttn-prod') || '';

        console.log("Клик по карандашу ТТН. ID:", id, "Номер:", num, "Сумма:", amount);

        // 2. ЗАЩИТА: Проверяем наличие инпутов в HTML перед записью, чтобы не ломать скрипт
        const inputId = document.getElementById('edit_ttn_id_storage');
        const inputNum = document.getElementById('new_ttn_num');
        const inputDate = document.getElementById('new_ttn_date');
        const inputAmount = document.getElementById('new_ttn_amount');
        const inputProd = document.getElementById('new_ttn_prod');

        // Набиваем только те поля, которые реально существуют в разметке
        if (inputId) inputId.value = id;
        if (inputNum) inputNum.value = num;
        if (inputDate) inputDate.value = date;
        if (inputAmount) inputAmount.value = amount;
        if (inputProd) inputProd.value = prod;

        // 3. Визуально переключаем форму в режим редактирования
        const titleEl = document.getElementById('ttnFormTitle');
        if (titleEl) {
            titleEl.innerText = 'Редактировать отгрузку №' + num + ':';
        }
        
        const actionBtn = document.getElementById('ttnActionBtn');
        if (actionBtn) {
            actionBtn.innerText = 'Сохранить изменения';
            actionBtn.style.background = '#f6ad55'; // Меняем цвет кнопки на оранжевый
        }
        
        console.log("Данные успешно подставлены в форму редактирования.");
    }
});

 document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const autoOpenId = urlParams.get('auto_open_client_id');
    
    if (autoOpenId) {
        // Ищем в таблице строку с этим cid, чтобы взять название и pid черновика
        // Нам нужно открыть модалку сразу поверх прогрузившегося черновика
        const row = document.querySelector(`tr`); // Скрипт ниже найдет точнее через data-id
        openAddContractModal(autoOpenId, "Оформление нового договора");
    }
});



// 2. ЗАКРЫТИЕ МОДАЛКИ
function closeContractModal() {
    const modal = document.getElementById('contractModal') || document.getElementById('newContractModal');
    if (modal) {
        modal.style.display = 'none';
    }

    // ИСПРАВЛЕНО: Если закрытие произошло по кнопке Отмена, принудительно гасим галочку в таблице!
    if (window.activeContractCheckbox) {
        window.activeContractCheckbox.checked = false; // Галочка принудительно снимается
        window.activeContractCheckbox = null;         // Очищаем оперативную память
        console.log("Менеджер нажал 'Отмена'. Галочка контракта успешно погашена без записи в СУБД.");
    }
}
// ИСПРАВЛЕНО: Функция считывает ID и выводит подробный лог в консоль F12
function openTtnManagerFromButton(buttonElement) {
    if (!buttonElement) return;
    
    // Считываем значение из data-id кнопки
    const rawId = buttonElement.getAttribute('data-id');
    const pid = parseInt(rawId, 10);
    
    console.log("=== ЛОГ КЛИКА КНОПКИ ТТН ===");
    console.log("Сырое значение из HTML атрибута data-id =", rawId);
    console.log("Распарсенный числовой ID договора =", pid);
    
    if (isNaN(pid) || pid <= 0) {
        alert("Критическая ошибка: Кнопка передала ID = " + rawId + ". Проверьте имя переменной ($p, $t, $row) в PHP цикле таблицы!");
        return;
    }
    
    if (typeof openTtnManager === 'function') {
        openTtnManager(pid);
    }
}


// =========================================================================
// АРХИТЕКТУРНЫЙ ФИКС: Хранение ID договора в оперативной памяти window
// =========================================================================

// Глобальное хранилище ID активного договора (застраховано от затирания в HTML)
window.currentTtnProjectId = 0;


// 2. Главная функция инициализации и открытия модального окна
function openTtnManager(pid) {
    console.log("=== ЗАПУСК ДВИЖКА ТТН ===");
    const safePid = parseInt(pid, 10);
    
    if (isNaN(safePid) || safePid <= 0) {
        alert("Критическая ошибка JS: Некорректный ID договора!");
        return;
    }

    // НАМЕРТВО ЗАПИСЫВАЕМ ID В ОПЕРАТИВНУЮ ПАМЯТЬ БРАУЗЕРА
    window.currentTtnProjectId = safePid;
    console.log("ID договора надежно зафиксирован в памяти: window.currentTtnProjectId =", window.currentTtnProjectId);

    // Визуально выводим ID в заголовок окна (для менеджера)
    const label = document.getElementById('ttnContractLabel');
    if (label) {
        label.innerText = 'Системный ID договора: №' + safePid;
    }

    // Ищем и открываем модальное окно
    let modal = document.getElementById('ttnManagerModal');
    if (!modal) {
        const headers = Array.from(document.querySelectorAll('h3'));
        const ttnHeader = headers.find(h => h.textContent.includes('Управление отгрузками ТТН'));
        if (ttnHeader) modal = ttnHeader.parentElement.parentElement;
    }

    if (modal) {
        modal.style.display = 'flex';
        console.log("Модальное окно выведено на экран.");
    } else {
        alert("Критическая ошибка UI: Не найден контейнер модального окна отгрузок!");
        return;
    }

    // Очищаем поле редактирования ТТН (так как создаем новую отгрузку)
    if (document.getElementById('edit_ttn_id_storage')) {
        document.getElementById('edit_ttn_id_storage').value = '';
    }

    // Загружаем асинхронный список уже созданных накладных из базы
    if (typeof renderProjectTtnsList === 'function') {
        renderProjectTtnsList(safePid);
    }
}

// 3. Функция сохранения отгрузки ТТН в базу данных Windows XAMPP


function exportToExcel() {
    // 1. Берем таблицу
    const table = document.querySelector("table");
    
    // 2. Генерируем книгу (Raw: true сохранит числа как числа, а не текст)
    const wb = XLSX.utils.table_to_book(table, { sheet: "Отчет Santeks", raw: true });
    
    // 3. Сохраняем с датой в названии
    const date = new Date().toISOString().slice(0,10);
    XLSX.writeFile(wb, `Santeks_CRM_Report_${date}.xlsx`);
}
// 3. СОХРАНЕНИЕ ФОРМЫ ДОГОВОРА
document.getElementById('contractForm').onsubmit = async function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    
    // Передаем ID проекта, который нужно обновить вместо создания нового
    const modal = document.getElementById('contractModal');
    if (modal.dataset.pid) {
        fd.append('project_id', modal.dataset.pid);
    }

    const res = await fetch('save_project.php', { method: 'POST', body: fd });
    if ((await res.json()).status === 'success') {
        window.location.href = 'contracts.php'; // Перезагружаем страницу без параметров
    }
};


   
function checkReminders() {
    console.log("Проверка напоминаний запущена");
}

// Запуск при загрузке
document.addEventListener("DOMContentLoaded", () => {
    // 1. Считываем параметры из URL-строки браузера
    const urlParams = new URLSearchParams(window.location.search);
    const autoClientId = urlParams.get('open_modal_for');

    if (autoClientId) {
        console.log("Пойман сквозной сигнал с главной! Автоматически раскрываем форму для клиента ID:", autoClientId);
        
        // Переиспользуем нашу родную, проверенную функцию открытия модалки, которую мы полировали на Шаге 79!
        if (typeof openNewContractModal === 'function') {
            openNewContractModal(autoClientId);
        } else {
            // Если функция называется иначе, дублируем её логику точечно:
            const modal = document.getElementById('contractModal') || document.getElementById('newContractModal');
            const clientIdInput = document.getElementById('contract_client_id_storage') || document.getElementById('modal_client_id');
            if (clientIdInput) {
                clientIdInput.value = parseInt(autoClientId, 10);
            }
            if (modal) modal.style.display = 'block';
        }
        
        // Чистим URL-строку браузера от маркера, чтобы при обычном обновлении страницы (F5) форма не вылетала повторно
        const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({ path: cleanUrl }, '', cleanUrl);
    }
});


        function processReminders() {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    let criticalCount = 0;
    const rows = document.querySelectorAll('#tableBody tr');

    rows.forEach(row => {
        const dateInput = row.querySelector('[data-f="next_contact_date"]');
            (!dateInput || !dateInput.value);

        const nextDate = new Date(dateInput.value);
        nextDate.setHours(0, 0, 0, 0);

        const diffDays = Math.ceil((nextDate - today) / (1000 * 60 * 60 * 24));

        // Очищаем старые классы
        row.classList.remove('row-danger', 'row-warning');

        if (diffDays <= 0) {
            row.classList.add('row-danger'); // Просрочено или сегодня
            criticalCount++;
        } else if (diffDays <= 3) {
            row.classList.add('row-warning'); // Осталось 1-3 дня
        }
    });

    if (criticalCount > 0) {
        showToast(`Внимание! У вас ${criticalCount} горящих задач на сегодня.`);
    }
}

function showToast(message) {
    let toast = document.getElementById('notification-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'notification-toast';
        document.body.appendChild(toast);
    }
    toast.innerText = message;
    toast.style.display = 'block';
    
    // Скрыть через 10 секунд
    setTimeout(() => { toast.style.display = 'none'; }, 10000);
}

async function saveData(id, field, value) {
    try {
        const response = await fetch('update_cell.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ id, field, value })
        });
        const result = await response.json();
        
        if (result.status === 'success') {
            console.log(`Поле ${field} успешно сохранено`);
            // Если изменили сумму — пересчитываем итоги на экране
            if (field === 'amount') recalculateEverything(); 
        } else {
            alert("Ошибка сохранения: " + result.message);
        }
    } catch (e) {
        console.error("Ошибка связи с сервером");
    }
}

const nbrbCurrentRates = <?= json_encode($globalRates ?? [
    'BYN' => 1.0, 'USD' => 3.26, 'EUR' => 3.54, 'RUB' => 0.0352, 'CNY' => 0.45
]) ?>;

console.log("Курсы успешно загружены в JS:", nbrbCurrentRates);

// Функция, которая вернет правильный пересчет
function getConvertedValue(baseByn, currency) {
   if (currency === 'BYN') return baseByn;
    
    // ПРЯМАЯ И ЧЕСТНАЯ МАТЕМАТИКА НАЦБАНКА РБ
    // Нацбанк дает курс ЗА ЕДИНИЦУ валюты к BYN (USD = 3.26, EUR = 3.54, RUB = 0.0352)
    const rate = parseFloat(nbrbCurrentRates[currency]) || 1.0;
    
    if (rate <= 0) return 0;

    // Формула: Сумма в BYN делить на курс единицы валюты
    // Для USD: 312 BYN / 3.2650 = 95.55 USD (У тебя выдавало 96 — это сходится!)
    // Для EUR: 312 BYN / 3.5420 = 88.08 EUR (У тебя выдавало 87 — это сходится!)
    // Для RUB: 312 BYN / 0.0352 = 8 863.63 RUB (Вот здесь была ошибка, теперь будет считать тысячи!)
    return baseByn / rate;
}

function updateTableTotals() {
    let sumByn = 0;
    const cells = document.querySelectorAll('.amount-byn');
    
    cells.forEach(el => {
        // 1. Чистим и считаем BYN
        let cleanText = el.innerText.replace(/\s/g, '').replace(',', '.');
        let val = parseFloat(cleanText) || 0;
        sumByn += val;

        // 2. Ищем ячейку RUB именно для ЭТОЙ строки по data-id
        const pid = el.getAttribute('data-id');
        // Ищем элемент с классом rub-column и нужным ID
        const rubCell = document.querySelector(`.rub-column[data-id="${pid}"]`);
        
        if (rubCell && typeof nbrbRate !== 'undefined') {
            let rowRub = val / (nbrbRate / 100);
            // Записываем результат
            rubCell.innerText = rowRub.toLocaleString('ru-RU', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' RUB';
        }
    });

    // 3. Обновляем ИТОГО
    document.getElementById('total-byn-cell').innerText = sumByn.toLocaleString('ru-RU', {minimumFractionDigits: 2}) + ' BYN';
    
    if (typeof nbrbRate !== 'undefined') {
        let totalRub = sumByn / (nbrbRate / 100);
        document.getElementById('total-rub-cell').innerText = totalRub.toLocaleString('ru-RU', {minimumFractionDigits: 2, maximumFractionDigits: 3}) + ' RUB';
    }
}

// Запускаем при загрузке и при каждом изменении
window.addEventListener('load', updateTableTotals);
document.addEventListener('input', (e) => {
    if (e.target.classList.contains('amount-byn')) updateTableTotals();
});
function doTheMath() {
    console.log("Запущен автоматический пересчет итоговой суммы страницы...");
    
    let totalSum = 0;

    // 1. Находим все ячейки базовых сумм BYN в таблице по их классу
    // Убедись, что у тебя в цикле foreach у ячейки с суммой BYN стоит класс js-byn-base
    const bynCells = document.querySelectorAll('.js-byn-base');
    
    bynCells.forEach(function(cell) {
        // Очищаем текст от пробелов и знаков BYN, превращая в чистое число
        const text = cell.innerText.replace(/\s/g, '').replace(',', '.');
        const value = parseFloat(text) || 0;
        totalSum += value;
    });

    // 2. Находим наш элемент итогов в tfoot
    const grandTotalElement = document.getElementById('js-contracts-grand-total');
    if (grandTotalElement) {
        // Выводим красивое число с разделением тысяч
        grandTotalElement.innerHTML = totalSum.toLocaleString('ru-RU', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }) + ' <span style="font-size:11px; color:#92929f; font-weight:normal;">BYN</span>';
        
        console.log("Итоговая сумма успешно выведена:", totalSum);
    } else {
        console.warn("Элемент js-contracts-grand-total не найден в разметке tfoot!");
    }
}

// АВТОЗАПУСК: Нам нужно, чтобы функция сама запускалась ОДИН раз при полной загрузке страницы
document.addEventListener("DOMContentLoaded", function() {
    doTheMath();
});


// Запуск при любых изменениях и при загрузке
document.addEventListener('input', (e) => {
    if (e.target.classList.contains('amount-byn')) doTheMath();
});

// Запускаем через небольшую паузу, чтобы всё прогрузилось
window.onload = () => setTimeout(doTheMath, 300);

// 1. Слушаем ввод (на лету)
document.addEventListener('input', (e) => {
    if (e.target.classList.contains('amount-byn')) doTheMath();
});

// 2. Запускаем при загрузке
window.addEventListener('DOMContentLoaded', doTheMath);

// 3. ПРИНУДИТЕЛЬНЫЙ ПЕРЕЗАПУСК (та самая кувалда)
setTimeout(doTheMath, 500); 
setTimeout(doTheMath, 2000);


// Запуск принудительно через секунду после загрузки (чтобы PHP успел всё отдать)
setTimeout(updateTableTotals, 1000);

// И при каждом вводе
document.addEventListener('input', (e) => {
    if (e.target.classList.contains('amount-byn')) updateTableTotals();
});

// Запускаем пересчет при загрузке и при каждом изменении в ячейках
window.addEventListener('load', updateTableTotals);
document.addEventListener('input', (e) => {
    if (e.target.classList.contains('amount-byn')) updateTableTotals();
});



// 3. Обработчик потери фокуса
document.addEventListener('blur', (e) => {
    if (e.target.classList.contains('editable')) {
        const row = e.target.closest('tr');
        const id = row.dataset.id; // Это наш cid (client_id)
        const field = e.target.dataset.f;
        const value = e.target.innerText.trim();
        
        saveData(id, field, value);
    }
}, true);   

// Запускаем при загрузке и после каждого изменения даты
window.addEventListener('DOMContentLoaded', processReminders);


document.addEventListener('blur', (e) => {
    if (e.target.dataset.f === 'amount') {
        recalculateTotal();
    }
}, true);



// Главная функция пересчета
function recalculateEverything() {
    let totalByn = 0;
    
    // Перебираем все строки таблицы
    const rows = document.querySelectorAll('#tableBody tr');
    
    rows.forEach(row => {
        // Находим ячейку, где вводим BYN (по атрибуту data-f)
        const bynCell = row.querySelector('[data-f="amount"]');
        // Находим ячейку, где выводим RUB (по классу)
        const rubCell = row.querySelector('.rub-cell');
        
        if (bynCell && rubCell) {
            // Очищаем текст от мусора и превращаем в число
            let valByn = parseFloat(bynCell.innerText.replace(/[^\d.,]/g, '').replace(',', '.')) || 0;
            totalByn += valByn;
            
            // Считаем и выводим RUB в строке
            let valRub = valByn / BYN_TO_RUB_RATE;
            rubCell.innerText = valRub.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) + ' RUB';
        }
    });

    // Обновляем ИТОГИ внизу (проверь, чтобы ID в tfoot совпадали!)
    const totalBynDisplay = document.getElementById('totalAmountBYN');
    const totalRubDisplay = document.getElementById('totalAmountRUB');
    
    if (totalBynDisplay) totalBynDisplay.innerText = totalByn.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) + ' BYN';
    if (totalRubDisplay) totalRubDisplay.innerText = (totalByn / BYN_TO_RUB_RATE).toLocaleString('ru-RU', { minimumFractionDigits: 2 }) + ' RUB';
}

// Сохранение и запуск пересчета
async function saveData(id, field, value) {
    await fetch('update_cell.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id, field, value })
    });
    
    if (field === 'amount') {
        recalculateEverything();
    }
}

// Следим за выходом из ячейки
document.addEventListener('blur', (e) => {
    if (e.target.classList.contains('editable')) {
        const id = e.target.closest('tr').dataset.id;
        const field = e.target.dataset.f;
        saveData(id, field, e.target.innerText);
    }
}, true);


function doCalculate() {
    let totalByn = 0;
    const rows = document.querySelectorAll('#tableBody tr');
    console.log("Найдено строк для расчета:", rows.length);

    rows.forEach((row, index) => {
        // Ищем ячейку суммы BYN внутри этой строки
        const bynDiv = row.querySelector('[data-f="amount"]');
        const rubCell = row.querySelector('.rub-value');

        if (bynDiv && rubCell) {
            let val = parseFloat(bynDiv.innerText.replace(',', '.')) || 0;
            totalByn += val;
            
            // Считаем RUB
            let rub = val / RATE;
            rubCell.innerText = rub.toLocaleString('ru-RU', {minimumFractionDigits: 2}) + " RUB";
        }
    });

    //Отсутствие дублирующих элементов 
    document.getElementById()

    // Обновляем футер
    document.getElementById('totalBYN').innerText = totalByn.toLocaleString('ru-RU', {minimumFractionDigits: 2}) + " BYN";
    document.getElementById('totalRUB').innerText = (totalByn / RATE).toLocaleString('ru-RU', {minimumFractionDigits: 2}) + " RUB";
}

document.addEventListener('click', function(e) {
    const editBtn = e.target.closest('.js-ttn-edit-btn');
    
    if (editBtn) {
        e.preventDefault();
        
        // Вытаскиваем данные ТТН из атрибутов кнопки
        const id = editBtn.dataset.ttnId;
        const num = editBtn.dataset.ttnNum;
        const date = editBtn.dataset.ttnDate;
        const amount = editBtn.dataset.ttnAmount;
        const prod = editBtn.dataset.ttnProd;

        console.log("Редактирую ТТН ID:", id, "Номер:", num);

        // Набиваем поля формы ввода данными из ТТН
        document.getElementById('edit_ttn_id_storage').value = id;
        document.getElementById('new_ttn_num').value = num;
        document.getElementById('new_ttn_date').value = date;
        document.getElementById('new_ttn_amount').value = amount;
        document.getElementById('new_ttn_prod').value = prod;

        // Визуально переключаем форму в режим редактирования
        const titleEl = document.getElementById('ttnFormTitle');
        if (titleEl) titleEl.innerText = 'Редактировать отгрузку №' + num + ':';
        
        const actionBtn = document.getElementById('ttnActionBtn');
        if (actionBtn) {
            actionBtn.innerText = 'Сохранить изменения';
            actionBtn.style.background = '#f6ad55'; // Меняем цвет кнопки на оранжевый
        }
    }
});
// Слушаем изменения
document.addEventListener('input', (e) => {
    if (e.target.dataset.f === 'amount') {
        doCalculate();
    }
});

            
// Сохранение (blur)
document.addEventListener('blur', async (e) => {
    if (e.target.classList.contains('editable')) {
        const id = e.target.closest('tr').dataset.id;
        const field = e.target.dataset.f;
        const value = e.target.innerText;
        
        await fetch('update_cell.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ id, field, value })
        });
    }
}, true);

// Запуск при старте
window.onload = doCalculate;
// Пересчитываем один раз при загрузке
window.onload = recalculateEverything;
    


document.addEventListener('blur', async function(e) {
    // Проверяем, что кликнули вне ячейки с суммой
    if (e.target.classList.contains('amount-byn')) {
        const id = e.target.dataset.id;
        const rawValue = e.target.innerText.replace(/\s/g, '').replace(',', '.');
        const finalValue = parseFloat(rawValue) || 0;

        console.log("Сохраняю в базу:", finalValue, "для ID:", id);

        try {
            const res = await fetch('update_contract_cell.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ 
                    id: id, 
                    field: 'amount', 
                    value: finalValue 
                })
            });
            const result = await res.json();
            if (result.status !== 'success') {
                console.error("Ошибка сохранения в БД:", result.message);
            }
        } catch (err) {
            console.error("Ошибка сети при сохранении:", err);
        }
    }
      }, true); // true нужен для корректного отлова события blur
   
function deleteContract(pid) {
    if (confirm("Вы уверены, что хотите БЕЗВОЗВРАТНО удалить этот договор и все связанные с ним ТТН?")) {
        fetch('delete_contract.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: pid })
        })
        .then(res => res.json())
        .then(result => {
            if (result.status === 'success') {
                location.reload();
            } else {
                alert("Ошибка при удалении: " + result.message);
            }
        });
    }
}


// АСИНХРОННОЕ УДАЛЕНИЕ PDF ФАЙЛА ТТН (СТРОГО ДЛЯ АДМИНА)
// АВТОНОМНЫЙ ПЕРЕХВАТ КЛИКА ПО КРЕСТИКУ (SPAN) БЕЗ ПЕРЕЗАГРУЗКИ СТРАНИЦЫ
document.addEventListener('click', async function(e) {
    // Ищем клик именно по нашему классу крестика
    const deleteBtn = e.target.closest('.js-pdf-delete-btn');
    if (deleteBtn) {
        // КРИТИЧНО: Останавливаем любое стандартное поведение и всплытие события в HTML
        e.preventDefault();
        e.stopPropagation();

        const ttnId = deleteBtn.getAttribute('data-ttn-id');
        const pid = deleteBtn.getAttribute('data-project-id');

        console.log("Глобальный перехват крестика. Удаляю PDF для ТТН ID:", ttnId, "Проект:", pid);

        if (!confirm("Вы уверены, что хотите БЕЗВОЗВРАТНО удалить прикрепленный PDF-файл этой ТТН?")) {
            return;
        }

        try {
            const res = await fetch('delete_ttn_pdf.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ttn_id: parseInt(ttnId) })
            });
            
            const result = await res.json();
            console.log("Результат удаления на сервере:", result);

            if (result.status === 'success') {
                // Обновляем список ТТН на лету прямо внутри открытого окна
                loadProjectTtnsPremium(pid);
            } else {
                alert("Ошибка удаления файла: " + result.message);
            }
        } catch (err) {
            console.error("Ошибка сети при удалении PDF:", err);
            alert("Ошибка связи с сервером delete_ttn_pdf.php");
        }
    }
});

    </script>

</html>