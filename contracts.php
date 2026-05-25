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
    
    $client_id       = (int)($_POST['client_id'] ?? 0);
    $contract_number = trim($_POST['contract_number'] ?? '');
    $contract_date   = !empty($_POST['contract_date']) ? $_POST['contract_date'] : date('Y-m-d');
    
    // СЧИТЫВАЕМ тип продукции, выбранный именно в модалке добавления договора
    // Если менеджер ничего не выбрал, принудительно наследуем базовый тип из карточки клиента clients
    $product_type = isset($_POST['product_type']) ? trim($_POST['product_type']) : '';
    
    if (empty($product_type) && $client_id > 0) {
        $getProdStmt = $pdo->prepare("SELECT product_type FROM clients WHERE id = ?");
        $getProdStmt->execute([$client_id]);
        $product_type = $getProdStmt->fetchColumn() ?: 'Прочее';
    }

    if ($client_id > 0 && !empty($contract_number)) {
        // Жестко пишем product_type в таблицу projects, обеспечивая автономность строки
        $sql = "INSERT INTO projects (client_id, contract_number, contract_date, product_type) VALUES (?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$client_id, $contract_number, $contract_date, $product_type]);
        
        // Переключаем у клиента флаг подписания договора
        $uClient = $pdo->prepare("UPDATE clients SET is_contract_signed = 1 WHERE id = ?");
        $uClient->execute([$client_id]);
        
        // Мягко перезагружаем страницу, чтобы исключить дублирование при F5
        header("Location: contracts.php");
        exit;
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
        <header>
            <h2>Учет договоров и проектов</h2>
            <div class="user-info">Вы: <?= $_SESSION['role'] ?></div>
        <button onclick="exportToExcel()" class="btn-primary" style="background:#10b981; border:none; padding:10px 20px; border-radius:8px; color:white; cursor:pointer; font-weight:bold;">
    <i class="fa-solid fa-file-excel"></i> СКАЧАТЬ ОТЧЕТ В EXCEL
</button>
        </header>

        <div class="table-container">

        <div id="contract-reminder-box" style="display:none; background:#fff1f2; border:2px solid #fb7185; padding:15px; margin:20px; border-radius:12px;">
        <h3 style="color:#e11d48; margin:0;">🔥 ГОРЯЩИЕ СРОКИ:</h3>
        <ul id="contract-reminder-list"></ul>
            </div>
            <table>
                <thead>
    <tr style="background: #2b2b40; text-align: left; color: #92929f;">
        <th style="padding: 12px;">Клиент</th>
        <th style="padding: 12px;">№ Договора</th>
        <th style="padding: 12px;">Тип продукции</th>
        <th style="padding: 12px;">Дата договора</th>
        <th style="padding: 12px; text-align: center;">Управление ТТН</th>
        <th style="padding: 12px; text-align: center;">Последняя отгрузка</th>
        <th style="padding: 12px; text-align: right;">Сумма (BYN)</th>
        <!-- НОВАЯ КОЛОНКА -->
        <th style="padding: 12px; text-align: right;">Мультивалютный пересчет</th>
        <th stle="padding: 12px; text-align: right;">Скан договора</th>
    </tr>
</thead>
              <tbody>
                
    <?php 
    $lastClient = ""; 
    $totalByn = 0;
    
    // Защита: если массив курсов пуст, создаем базовый дефолт
    $rates = (isset($globalRates) && is_array($globalRates)) ? $globalRates : ['BYN' => 1.0, 'USD' => 3.25, 'EUR' => 3.55, 'RUB' => 0.035, 'CNY' => 0.45];

    foreach ($rows as $r): 
        $isNewGroup = ($r['client_name'] !== $lastClient);
        
        // 1. Считаем чистую сумму всех ТТН (в базовых BYN) для текущего договора
        $sumQuery = $pdo->prepare("SELECT SUM(amount) FROM project_ttns WHERE project_id = ?");
        $sumQuery->execute([$r['pid']]);
        $totalBynSum = (float)$sumQuery->fetchColumn();
        
        $totalByn += $totalBynSum;

        // 2. Подгружаем сохраненную валюту конвертации для этой строки (по дефолту RUB)
        $savedCurrency = !empty($r['currency']) ? $r['currency'] : 'RUB';

        // 3. Расчет для мультивалютной ячейки
        $rateValue = isset($rates[$savedCurrency]) ? (float)$rates[$savedCurrency] : 1.0;
        $convertedSum = ($rateValue > 0) ? ($totalBynSum / $rateValue) : 0;
    ?>
    
    <?php if ($isNewGroup): ?>
        <!-- Заголовок группы клиента (Растягиваем строго на 8 колонок) -->
        <tr style="background: #1b1b28; font-weight: bold; border-left: 4px solid #4f46e5;">
            <td colspan="8" style="padding: 12px 20px; color: #fff; font-size: 14px; text-align: left;">
                <span style="color:#fff;">🏢 КЛИЕНТ: <?= htmlspecialchars($r['client_name']) ?></span>
                <span style="color: #64748b; font-size: 11px; margin-left: 10px; font-weight: normal;">(Все договоры клиента)</span>
                <button type="button" 
        onclick="openAddContractModal(<?= (int)$r['cid'] ?>, '<?= htmlspecialchars($r['client_name'], ENT_QUOTES, 'UTF-8') ?>')" 
        style="background: #4f46e5; color: #fff; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 11px; font-weight: bold;">
    + Добавить договор
</button>

            </td>
        </tr>
        <?php $lastClient = $r['client_name']; ?>
    <?php endif; ?>

    <tr style="border-bottom: 1px solid #2b2b40;" data-id="<?= $r['pid'] ?>">
        <!-- 1. Колонна КЛИЕНТ (пустая для структуры групп) -->
        <td style="padding: 12px; border-right: 1px solid #2b2b40;"></td>
        
        <!-- 2. № Договора (Редактируемый) -->
        <td style="padding: 12px;">
            <div class="editable" contenteditable="true" data-f="contract_number" data-id="<?= $r['pid'] ?>" style="min-height:20px; color:#fff;">
                <?= htmlspecialchars($r['contract_number'] ?: '—') ?>
            </div>
        </td>
        
        <!-- 3. Тип продукции -->
        <td style="padding: 12px; text-align: left; color: #fff; border: 1px solid #2b2b40;">
    <?php
    // Автоподбор переменной строки (на случай разной архитектуры)
    $currentRecord = isset($r) ? $r : (isset($row) ? $row : (isset($project) ? $project : []));
    
    // Считываем индивидуальный тип продукции из таблицы контрактов projects
    $individualProduct = !empty($currentRecord['product_type']) ? trim($currentRecord['product_type']) : '';
    
    // Подстраховка: если поле в projects пустое, выводим прочерк
    if (!empty($individualProduct) && $individualProduct !== 'NULL') {
        echo htmlspecialchars($individualProduct);
    } else {
        echo '<span style="color: #64748b;">—</span>';
    }
    ?>
</td>
        
        <!-- 4. Дата договора -->
        <td style="padding: 12px; color: #92929f;"><?= htmlspecialchars($r['contract_date'] ?? '—') ?></td>
        
        <!-- 5. Управление ТТН -->
     <!-- КНОПКА ТТН БЕЗ ЛИШНИХ ПАРАМЕТРОВ -->
<td style="padding: 12px; text-align: center;">
    <button type="button" 
            class="js-open-ttn-window-btn"
            data-pid="<?= (int)$r['pid'] ?>" 
            data-name="<?= htmlspecialchars($r['client_name'], ENT_QUOTES, 'UTF-8') ?>"
            style="background: #4f46e5; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: bold;">
        📦 ТТН (<?php 
            $c = $pdo->prepare("SELECT COUNT(*) FROM project_ttns WHERE project_id = ?"); 
            $c->execute([$r['pid']]); 
            echo $c->fetchColumn(); 
        ?>)
    </button>
</td>


        <!-- 6. Последняя отгрузка -->
        <td style="padding: 12px; text-align: center; font-size: 12px; color: #92929f;">
            <?php 
                $ld = $pdo->prepare("SELECT MAX(ttn_date) FROM project_ttns WHERE project_id = ?"); 
                $ld->execute([$r['pid']]);
                $d = $ld->fetchColumn(); echo $d ? date('d.m.Y', strtotime($d)) : '—';
            ?>
        </td>

        <!-- 7. Сумма (BYN) — Жесткая и неизменяемая на основе ТТН -->
        <td style="padding: 12px; text-align: right; font-weight: bold; color: #fff; padding-right: 15px;">
            <span class="js-byn-base" data-id="<?= $r['pid'] ?>"><?= number_format($totalBynSum, 2, '.', ' ') ?></span>
            <span style="color: #55556d; font-size: 11px; font-weight: normal; margin-left: 3px;">BYN</span>
        </td>

        <!-- 8. Мультивалютный пересчет -->
        <td style="padding: 12px; text-align: right; white-space: nowrap; padding-right: 20px;">
            <strong class="js-converted-value" data-id="<?= $r['pid'] ?>" style="color: #10b981; font-size: 14px; margin-right: 5px;">
                <?= number_format($convertedSum, 2, '.', ' ') ?>
            </strong>
            <select class="js-target-currency" data-id="<?= $r['pid'] ?>" style="padding: 4px; background: #151521; border: 1px solid #323248; color: #92929f; border-radius: 4px; font-size: 12px; cursor: pointer; outline: none;">
                <option value="RUB" <?= $savedCurrency === 'RUB' ? 'selected' : '' ?>>RUB</option>
                <option value="USD" <?= $savedCurrency === 'USD' ? 'selected' : '' ?>>USD</option>
                <option value="EUR" <?= $savedCurrency === 'EUR' ? 'selected' : '' ?>>EUR</option>
                <option value="CNY" <?= $savedCurrency === 'CNY' ? 'selected' : '' ?>>CNY</option>
                <option value="BYN" <?= $savedCurrency === 'BYN' ? 'selected' : '' ?>>BYN</option>
            </select>
        </td>
   
<!-- ЯЧЕЙКА С НОМЕРОМ ДОГОВОРА И СКРЕПКОЙ (№32) -->
<!-- КОЛОНКА «СКАН ДОГОВОРА» — ЧИСТЫЙ АСИНХРОННЫЙ ВАРИАНТ (№32) -->
<!-- КОЛОНКА «СКАН ДОГОВОРА» — СТРОГО НА СВОЕМ МЕСТЕ (№32 + №37) -->
<!-- АВТОНОМНЫЙ ВЫВОД СКАНА ДОГОВОРА ЧЕРЕЗ ПРЯМОЙ ОПРОС БД (ЗАДАЧА №32) -->
<!-- ОКОНЧАТЕЛЬНЫЙ ВЫВОД СКАНА ДОГОВОРА СТРОГО ПО КЛЮЧАМ PID И CONTRACT_FILE (№32) -->
<td style="padding: 12px; text-align: center; border: 1px solid #2b2b40;">
    <div style="display: inline-flex; align-items: center; gap: 8px; justify-content: center;">
        <?php
        $currentRecord = isset($r) ? $r : (isset($row) ? $row : []);
        $projectId    = isset($currentRecord['pid']) ? (int)$currentRecord['pid'] : 0;
        
        // ИСПРАВЛЕНО: Читаем новое Windows-поле пути к скану scan_path взамен contract_file
        $contractPath = isset($currentRecord['scan_path']) ? trim($currentRecord['scan_path']) : '';

        if (!empty($contractPath) && $contractPath !== 'NULL' && $contractPath !== '0'): 
        ?>
            <!-- Кнопка просмотра PDF (ИСПРАВЛЕНО: Ссылка сразу ведет на полный путь из базы) -->
            <a href="<?= htmlspecialchars($contractPath) ?>" 
               target="_blank" 
               style="color: #10b981; text-decoration: none; font-size: 11px; font-weight: bold; background: #1a2e26; padding: 4px 8px; border-radius: 4px; display: inline-block; white-space: nowrap;">👁 PDF</a>
            
            <!-- Крестик удаления файла -->
            <button type="button" 
                    onclick="if(confirm('Вы уверены, что хотите безвозвратно удалить скан договора?')){ window.location.href='delete_contract_file.php?pid=<?= $projectId ?>'; } return false;" 
                    style="background: none; border: none; color: #f56565; cursor: pointer; font-size: 12px; padding: 4px; display: inline-block; line-height: 1;">❌</button>
        <?php else: ?>
            <!-- Синка иконка скрепки со встроенным инлайн-движком отправки fetch -->
            <label for="contract_file_<?= $projectId ?>" 
                   style="cursor: pointer; color: #4f46e5; font-size: 14px; padding: 4px 8px; background: #1e1e2d; border: 1px solid #323248; border-radius: 4px; display: inline-block; user-select: none;">📎</label>
            
            <input type="file" 
                   id="contract_file_<?= $projectId ?>" 
                   accept=".pdf" 
                   style="display: none;" 
                   onchange="if(!this.files||!this.files.length)return; const fd=new FormData(); fd.append('pid',<?= $projectId ?>); fd.append('contract_pdf',this.files[0]); const path=window.location.pathname; const url=path.substring(0,path.lastIndexOf('/'))+'/upload_scan.php'; fetch(url,{method:'POST',body:fd}).then(r=>r.json()).then(res=>{ if(res.status==='success'){ window.location.reload(); }else{ alert('Ответ сервера:\n'+res.message); window.location.reload(); } }).catch(err=>alert('Ошибка сети или размера файла'));return false;">
        <?php endif; ?>
    </div>
    <div style="color: #92929f; font-size: 11px; margin-top: 2px;">от <?= !empty($r['contract_date']) ? date('d.m.Y', strtotime($r['contract_date'])) : date('d.m.Y') ?></div>
</td>

    </div>
        </div>
    </div>
    <div style="color: #92929f; font-size: 11px; margin-top: 2px;">от <?= date('d.m.Y', strtotime($r['contract_date'])) ?></div>
</td>

    <?php endforeach; ?>
</tbody>
<tfoot style="background: #1a1a26; font-weight: bold; border-top: 2px solid #4f46e5;">
    <tr>
        <!-- Пропускаем первые 6 колонок, чтобы надпись встала перед суммами -->
        <td colspan="6" style="text-align: right; padding: 15px; color: #92929f; font-size: 13px;">
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
 

    

<div id="contractModal" class="modal-overlay" style="display:none;">
    <div class="modal-content stylish-modal" style="width: 500px;">
        <div class="modal-header">
            <h2>Новый договор: <span id="modalClientName" style="color:#4f46e5;"></span></h2>
        </div>
        <form id="contractForm" class="p-24">
            <input type="hidden" id="modal_client_id" name="client_id">
            
            <div class="form-row">
                <div class="form-group">
                    <label>№ Договора</label>
                    <input type="text" name="contract_number" placeholder="Напр: 125/А" required>
                </div>
                <div class="form-group">
                    <label>Дата договора</label>
                    <input type="date" name="contract_date" value="<?= date('Y-m-d') ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Сумма (BYN)</label>
                    <input type="number" step="0.01" name="amount" placeholder="0.00" required>
                </div>
                <div class="form-group">
                    <label>Продукция</label>
                   <select id="modal_contract_product_type" name="product_type" required style="width: 100%; padding: 8px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; cursor: pointer;">
                <option value="Посуда">Посуда</option>
        <option value="Сантехника">Сантехника</option>
        <option value="ЕКМ">ЕКМ</option>
        <option value="Резервуары">Резервуары</option>
        <option value="МПДУ">МПДУ</option>
        <option value="УОКТ">УОКТ</option>
        <option value="Прочее">Прочее</option>
    </select>
           
    </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeContractModal()">Отмена</button>
                <button type="submit" class="btn-submit">Создать договор</button>
            </div>
            
        </form>
    </div>
</div>
</main>
</body>
<div id="ttnManagerModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); justify-content: center; align-items: center; z-index: 99999;">
    <div style="background: #1e1e2d; padding: 25px; border-radius: 12px; width: 500px; border: 1px solid #323248; box-shadow: 0 10px 25px rgba(0,0,0,0.5); font-family: sans-serif; color: #fff; display: flex; flex-direction: column; gap: 15px; box-sizing: border-box;">
        
        <!-- Шапка окна -->
        <div style="text-align: left;">
            <h3 style="margin: 0; color: #fff; font-size: 16px; font-weight: bold;">📦 Управление отгрузками ТТН / CMR</h3>
            <p id="ttnContractLabel" style="color: #4f46e5; margin-top: 5px; font-size: 13px; margin-bottom: 0; font-weight: bold;"></p>
        </div>
        
        <!-- Скрытые технические хранилища ID -->
        <input type="hidden" id="ttn_pid_storage" value="<?= isset($_GET['project_id']) ? (int)$_GET['project_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0) ?>">
        <input type="hidden" id="edit_ttn_id_storage" value="">
        
        <!-- КОНТЕЙНЕР ДЛЯ ВЫВОДА СПИСКА НАКЛАДНЫХ -->
        <div id="ttnListContainer" style="max-height: 180px; min-height: 80px; overflow-y: auto; background: #151521; border-radius: 8px; padding: 10px; border: 1px solid #2b2b40; display: flex; flex-direction: column; gap: 8px; box-sizing: border-box;">
            <!-- Данные подгружаются асинхронно через JS -->
        </div>

        <!-- ИЗОЛИРОВАННЫЙ БЛОК ПОЛЕЙ ВВОДА (БЕЗ ТЕГА FORM) -->
        <div style="background: #242434; padding: 15px; border-radius: 8px; display: flex; flex-direction: column; gap: 10px; text-align: left; box-sizing: border-box;">
            <h4 id="ttnFormTitle" style="margin: 0; font-size: 13px; color: #92929f; font-weight: normal;">Добавить новую отгрузку в рамках контракта:</h4>
            
            <div style="display: flex; gap: 10px; width: 100%; box-sizing: border-box;">
                <input type="text" id="new_ttn_num" placeholder="№ ТТН" style="flex: 2; padding: 8px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px;">
                <input type="date" id="new_ttn_date" value="<?= date('Y-m-d') ?>" style="flex: 1; padding: 8px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px;">
            </div>
            
            <input type="number" id="new_ttn_amount" step="0.01" placeholder="Сумма по ТТН (в BYN)" style="width: 100%; padding: 8px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; box-sizing: border-box;">
            <input type="number" id="new_ttn_quantity" placeholder="Количество продукции (шт)" style="width: 100%; padding: 8px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; box-sizing: border-box;">
            <input type="text" id="new_ttn_prod" placeholder="Спецификация (что отгружаем)" style="width: 100%; padding: 8px; background: #151521; border: 1px solid #323248; color: #fff; border-radius: 6px; outline: none; font-size: 13px; box-sizing: border-box;">
            
            <!-- Чистая кнопка отправки с вызовом защищенной функции -->
            <button type="button" id="ttnActionBtn" onclick="saveTtnRecord();" style="background: #10b981; color: white; border: none; padding: 10px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px; margin-top: 5px; transition: background 0.15s; width: 100%;">
                Добавить в рамках контракта
            </button>
        </div>

        <!-- Кнопка закрытия окна -->
        <div style="text-align: right; margin-top: 5px;">
            <button type="button" onclick="closeTtnManager();" style="background: #555; color: #fff; border: none; padding: 8px 18px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px;">
                Закрыть
            </button>
        </div>

    </div>
</div>

<script>



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

// 1. АСИНХРОННАЯ ПОДГРУЗКА И КРАСИВЫЙ РЕНДЕРИНГ СПИСКА ТТН
async function renderProjectTtnsList(pid) {
    const container = document.getElementById('ttnListContainer');
    if (!container) return;
    
    container.innerHTML = '<span style="color:#92929f; font-size:12px; padding:10px; display:block; text-align:left;">Загрузка списка отгрузок...</span>';

    try {
        const res = await fetch('get_ttns.php?pid=' + parseInt(pid));
        const data = await res.json();
        
        let html = '<div style="display: flex; flex-direction: column; gap: 8px; width: 100%;">';
        
        if (data && data.length > 0) {
            data.forEach(function(t) {
                // Безопасное экранирование строк
                const safeProd = (t.product_info || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                const safeQty  = parseInt(t.product_quantity || 0);
                const safeNum  = (t.ttn_number || '').replace(/"/g, '&quot;');
                const safeDate = (t.ttn_date || '');
                const safeAmt  = parseFloat(t.amount || 0).toFixed(2);
                
                // Блок управления файлом (PDF / Скрепка / Крестик)
                let fileControls = '';
                if (t.ttn_file) {
                    fileControls += '<a href="uploads/ttn/' + t.ttn_file + '" target="_blank" style="color:#10b981; text-decoration:none; font-size:11px; font-weight:bold; background:#1a2e26; padding:4px 8px; border-radius:4px; margin-right:5px; display:inline-block;">👁 PDF</a>';
                    fileControls += '<button type="button" onclick="removeTtnFile(' + t.id + ', ' + pid + ')" style="background:none; border:none; color:#f56565; cursor:pointer; font-size:12px; font-weight:bold; padding:4px; margin-left:2px;">❌</button>';
                } else {
                    fileControls += '<label for="ttn_file_input_' + t.id + '" style="cursor:pointer; color:#4f46e5; font-size:13px; padding:4px 8px; background:#1e1e2d; border:1px solid #323248; border-radius:4px; display:inline-block;">📎</label>';
                    fileControls += '<input type="file" id="ttn_file_input_' + t.id + '" accept=".pdf" style="display:none;" onchange="uploadTtnFile(' + t.id + ', ' + pid + ', this)">';
                }

                // Сборка карточки ТТН
                html += '<div style="background: #242434; padding: 10px; border-radius: 8px; border: 1px solid #2b2b40; display: flex; justify-content: space-between; align-items: center; width: 100%; box-sizing: border-box;">';
                html +=   '<div style="flex: 1; min-width: 0; padding-right: 10px; text-align:left;">';
                html +=     '<div style="font-weight: bold; color: #fff; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">ТТН № ' + safeNum + '</div>';
                html +=     '<div style="color: #92929f; font-size: 11px; margin-top: 2px;">Дата: ' + safeDate + ' | Кол-во: <strong style="color:#f6ad55;">' + safeQty + ' шт.</strong></div>';
                html +=     '<div style="color: #64748b; font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 1px;">' + (t.product_info || 'Без спецификации') + '</div>';
                html +=   '</div>';
                html +=   '<div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">';
                html +=     '<div style="font-weight: bold; color: #10b981; font-size: 13px;">' + safeAmt + ' BYN</div>';
                html +=     '<div style="display: flex; align-items: center;">' + fileControls + '</div>';
                html +=     '<button type="button" onclick="prepareTtnToEdit(' + t.id + ', \'' + safeNum + '\', \'' + safeDate + '\', ' + t.amount + ', ' + safeQty + ', \'' + safeProd + '\')" style="background:none; border:none; color:#f6ad55; cursor:pointer; font-size:13px; padding:4px; margin-left:3px;">✏️</button>';
                html +=   '</div>';
                html += '</div>';
            });
        } else {
            html += '<span style="color:#666; font-size:12px; padding:15px; display:block; text-align:center;">Отгрузок по контракту пока нет</span>';
        }
        
        html += '</div>';
        container.innerHTML = html;

    } catch (err) {
        console.error("Сбой отображения списка ТТН:", err);
    }
}

// 2. СВЕРХЗАЩИЩЕННОЕ АСИНХРОННОЕ СОХРАНЕНИЕ (ОТПРАВЛЯЕТ СТРОГО 1 ЗАПРОС НА КЛИК)
async function saveTtnRecord() {
    console.log("Попытка отправки запроса ТТН...");
    
    // Блокируем повторный вызов, если кликнули дважды
    if (isTtnSendingLock) {
        console.warn("Повторный клик заблокирован! Дождитесь ответа базы.");
        return;
    }

    const pid = document.getElementById('ttn_pid_storage').value;
    const ttnId = document.getElementById('edit_ttn_id_storage').value;
    const num = document.getElementById('new_ttn_num').value.trim();
    const date = document.getElementById('new_ttn_date').value;
    const amt = document.getElementById('new_ttn_amount').value.trim();
    const qty = document.getElementById('new_ttn_quantity').value.trim();
    const prod = document.getElementById('new_ttn_prod').value.trim();

    if (!num || !amt) {
        alert("Заполните обязательные поля: Номер ТТН и Сумму отгрузки!");
        return;
    }

    try {
        // АКТИВИРУЕМ БЛОКИРОВЩИК ЗАПРОСА
        isTtnSendingLock = true;
        
        const btn = document.getElementById('ttnActionBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerText = "Запись в базу...";
        }

        const res = await fetch('save_ttn.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ ttn_id: ttnId, project_id: pid, ttn_number: num, ttn_date: date, amount: amt, product_quantity: qty, product_info: prod })
        });
        
        const result = await res.json();
        
        // СБРАСЫВАЕМ БЛОКИРОВЩИК после получения ответа
        isTtnSendingLock = false;
        if (btn) btn.disabled = false;

        if (result.status === 'success') {
            // Очищаем инпуты
            document.getElementById('edit_ttn_id_storage').value = '';
            document.getElementById('new_ttn_num').value = '';
            document.getElementById('new_ttn_amount').value = '';
            document.getElementById('new_ttn_quantity').value = '';
            document.getElementById('new_ttn_prod').value = '';
            
            document.getElementById('ttnFormTitle').innerText = 'Добавить новую отгрузку в рамках контракта:';
            if (btn) {
                btn.innerText = 'Добавить в рамках контракта';
                btn.style.background = '#10b981';
            }
            
            // Перерисовываем список накладных в окне
            renderProjectTtnsList(pid);
        } else {
            alert("Ошибка базы: " + result.message);
        }
    } catch (err) {
        console.error("Сбой сети:", err);
        isTtnSendingLock = false;
        const btn = document.getElementById('ttnActionBtn');
        if (btn) btn.disabled = false;
    }
}

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
async function removeTtnFile(ttnId, pid) {
    if (!confirm("Вы уверены, что хотите БЕЗВОЗВРАТНО удалить прикрепленный PDF-файл?")) return;
    try {
        const res = await fetch('delete_ttn_pdf.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ttn_id: parseInt(ttnId) })
        });
        if ((await res.json()).status === 'success') renderProjectTtnsList(pid);
    } catch (err) { alert("Ошибка соединения с сервером"); }
}

// 5. ПОТОКОВАЯ ЗАГРУЗКА PDF (СКРЕПКА)
async function uploadTtnFile(ttnId, pid, input) {
    if (!input.files || !input.files.length) return;
    const fd = new FormData();
    fd.append('ttn_id', ttnId);
    fd.append('ttn_pdf', input.files[0]);

    try {
        const res = await fetch('upload_ttn_pdf.php', { method: 'POST', body: fd });
        if ((await res.json()).status === 'success') renderProjectTtnsList(pid);
    } catch (err) { alert("Ошибка загрузки скана"); }
}

// 6. ЗАКРЫТИЕ ОКНА
function closeTtnManager() {
    document.getElementById('ttnManagerModal').style.display = 'none';
    window.location.reload(); // Перезагрузка страницы для обновления общих сумм отгрузок в таблице
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
        loadProjectTtns(pid);
    }
});

   function openTtnManager(pid, label) {
    console.log("Открываю менеджер ТТН для договора ID:", pid);
    
    const modal = document.getElementById('ttnManagerModal');
    const storage = document.getElementById('ttn_pid_storage');
    const labelEl = document.getElementById('ttnContractLabel');

    if (!modal || !storage) {
        alert("Критическая ошибка: Элементы модального окна ТТН не найдены!");
        return;
    }

    storage.value = pid;
    if (labelEl) labelEl.innerText = "Клиент: " + label;
    
    modal.style.display = 'flex';
    loadProjectTtns(pid);
}
async function executeSingleTtnSave() {
    console.log("Запущен уникальный движок сохранения ТТН...");
    

let isTtnSendingNow = false;

async function executeSingleTtnSave() {
    console.log("Попытка запуска движка сохранения ТТН...");
    
    // Если запрос уже летит прямо сейчас — намертво блокируем повторный запуск!
    if (isTtnSendingNow) {
        console.warn("Запрос уже отправляется на сервер! Повторный клик заблокирован.");
        return;
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
            loadProjectTtns(pid);
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
            loadProjectTtns(pid);
        } else {
            alert("Ошибка базы данных: " + result.message);
        }
    } catch (err) {
        console.error("Сбой сети при отправке ТТН:", err);
        const btn = document.getElementById('ttnActionBtn');
        if (btn) btn.disabled = false;
    }
}
async function closeTtnManager() {
    document.getElementById('ttnManagerModal').style.display = 'none';
    location.reload(); // Обновляем страницу, чтобы пересчитать суммы ТТН в таблице
}   
        // 1. ПОДГРУЗКА СПИСКА ТТН (Крестик удаления файла теперь виден ВСЕМ)
async function loadProjectTtns(pid) {
    const container = document.getElementById('ttnListContainer');
    if (!container) return;
    
    container.innerHTML = '<span style="color:#92929f; font-size:12px; padding:10px; display:block; text-align:left;">Загрузка списка отгрузок...</span>';

    try {
        const res = await fetch('get_ttns.php?pid=' + parseInt(pid));
        const data = await res.json();
        
        let html = '<div style="display: flex; flex-direction: column; gap: 8px; width: 100%;">';
        
        if (data && data.length > 0) {
            data.forEach(function(t) {
                // Экранируем кавычки в спецификации, чтобы они не ломали атрибуты карандаша
                const safeProd = (t.product_info || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                const safeQty  = parseInt(t.product_quantity || 0);
                const safeNum  = (t.ttn_number || '').replace(/"/g, '&quot;');
                const safeDate = (t.ttn_date || '');
                const safeAmt  = parseFloat(t.amount || 0).toFixed(2);
                
                // Формируем блок управления файлом ТТН (PDF)
                let fileControls = '';
                if (t.ttn_file) {
                    fileControls += '<a href="uploads/ttn/' + t.ttn_file + '" target="_blank" style="color:#10b981; text-decoration:none; font-size:11px; font-weight:bold; background:#1a2e26; padding:4px 8px; border-radius:4px; margin-right:5px; display:inline-block;">👁 PDF</a>';
                    fileControls += '<button type="button" onclick="deleteTtnPdf(' + t.id + ', ' + pid + ')" style="background:none; border:none; color:#f56565; cursor:pointer; font-size:12px; font-weight:bold; padding:4px; margin-left:2px;">❌</button>';
                } else {
                    fileControls += '<label for="ttn_file_input_' + t.id + '" style="cursor:pointer; color:#4f46e5; font-size:13px; padding:4px 8px; background:#1e1e2d; border:1px solid #323248; border-radius:4px; display:inline-block;">📎</label>';
                    fileControls += '<input type="file" id="ttn_file_input_' + t.id + '" accept=".pdf" style="display:none;" onchange="uploadTtnPdf(' + t.id + ', ' + pid + ', this)">';
                }

                // Шаблон строки ТТН (Чистое строковое сложение)
                html += '<div style="background: #242434; padding: 10px; border-radius: 8px; border: 1px solid #2b2b40; display: flex; justify-content: space-between; align-items: center; width: 100%; box-sizing: border-box;">';
                html +=   '<div style="flex: 1; min-width: 0; padding-right: 10px; text-align:left;">';
                html +=     '<div style="font-weight: bold; color: #fff; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">ТТН № ' + safeNum + '</div>';
                html +=     '<div style="color: #92929f; font-size: 11px; margin-top: 2px;">Дата: ' + safeDate + ' | Кол-во: <strong style="color:#f6ad55;">' + safeQty + ' шт.</strong></div>';
                html +=     '<div style="color: #64748b; font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 1px;">' + (t.product_info || 'Без спецификации') + '</div>';
                html +=   '</div>';
                html +=   '<div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">';
                html +=     '<div style="font-weight: bold; color: #10b981; font-size: 13px;">' + safeAmt + ' BYN</div>';
                html +=     '<div style="display: flex; align-items: center;">' + fileControls + '</div>';
                // Кнопка карандаша (вызывает функцию editTtn)
                html +=     '<button type="button" onclick="editTtn(' + t.id + ', \'' + safeNum + '\', \'' + safeDate + '\', ' + t.amount + ', ' + safeQty + ', \'' + safeProd + '\')" style="background:none; border:none; color:#f6ad55; cursor:pointer; font-size:13px; padding:4px; margin-left:3px;">✏️</button>';
                html +=   '</div>';
                html += '</div>';
            });
        } else {
            html += '<span style="color:#666; font-size:12px; padding:15px; display:block; text-align:center;">Отгрузок в рамках контракта пока нет</span>';
        }
        
        html += '</div>';
        container.innerHTML = html;

    } catch (err) {
        console.error("Сбой loadProjectTtns:", err);
    }
}
// 2. ДВИЖОК ЭКСТРЕННОГО СОХРАНЕНИЯ ТТН (INSERT / UPDATE)
async function executeTtnSaving() {
    console.log("Запущено сохранение ТТН...");
    
    const pid = document.getElementById('ttn_pid_storage').value;
    const ttnId = document.getElementById('edit_ttn_id_storage') ? document.getElementById('edit_ttn_id_storage').value : '';
    const num = document.getElementById('new_ttn_num').value.trim();
    const date = document.getElementById('new_ttn_date').value;
    const amt = document.getElementById('new_ttn_amount').value.trim();
    const prod = document.getElementById('new_ttn_prod').value.trim();

    if (!num || !amt) {
        alert("Пожалуйста, заполните Номер ТТН и Сумму отгрузки!");
        return;
    }

    try {
        const response = await fetch('save_ttn.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ ttn_id: ttnId, project_id: pid, ttn_number: num, ttn_date: date, amount: amt, product_info: prod })
        });
        
        const result = await response.json();
        
        if (result.status === 'success') {
            document.getElementById('edit_ttn_id_storage').value = '';
            document.getElementById('new_ttn_num').value = '';
            document.getElementById('new_ttn_amount').value = '';
            document.getElementById('new_ttn_prod').value = '';
            
            if(document.getElementById('ttnFormTitle')) {
                document.getElementById('ttnFormTitle').innerText = 'Добавить новую отгрузку в рамках контракта:';
            }
            const btn = document.getElementById('ttnActionBtn');
            if (btn) {
                btn.innerText = 'Добавить в рамках контракта';
                btn.style.background = '#10b981';
            }
            
            loadProjectTtns(pid); // Перерисовываем список ТТН
        } else {
            alert("База данных вернула отказ: " + result.message);
        }
    } catch (err) {
        alert("Не удалось сохранить накладную. Проверьте обработчик save_ttn.php");
    }
}

// 3. ПЕРЕНОС ДАННЫХ В ПОЛЯ ПРИ КЛИКЕ НА КАРАНДАШ
function editTtn(id, num, date, amount, prod) {
    document.getElementById('edit_ttn_id_storage').value = id;
    document.getElementById('new_ttn_num').value = num;
    document.getElementById('new_ttn_date').value = date;
    document.getElementById('new_ttn_amount').value = amount;
    document.getElementById('new_ttn_prod').value = prod;

    if(document.getElementById('ttnFormTitle')) {
        document.getElementById('ttnFormTitle').innerText = 'Редактировать отгрузку №' + num + ':';
    }
    const btn = document.getElementById('ttnActionBtn');
    if (btn) {
        btn.innerText = 'Сохранить изменения';
        btn.style.background = '#f6ad55';
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
        if (result.status === 'success') loadProjectTtns(pid);
        else alert(result.message);
    } catch (err) { alert("Ошибка связи с сервером"); }
}

// 5. ПОТОКОВАЯ ЗАГРУЗКА PDF
async function uploadTtnPdf(ttnId, pid, input) {
    if (!input.files || !input.files.length) return;
    const file = input.files[0];
    const fd = new FormData();
    fd.append('ttn_id', ttnId);
    fd.append('ttn_pdf', file);

    try {
        const res = await fetch('upload_ttn_pdf.php', { method: 'POST', body: fd });
        if ((await res.json()).status === 'success') loadProjectTtns(pid);
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
 function openAddContractModal(clientId, clientName, projectId = '') {
    const modal = document.getElementById('contractModal');
    if (!modal) return;

    document.getElementById('contractForm').reset();
    document.getElementById('modal_client_id').value = clientId;
    document.getElementById('modalClientName').innerText = clientName;
    
    // Если передали ID проекта (черновика), сохраняем его в форму
    if (projectId) {
        modal.dataset.pid = projectId;
    }

    modal.style.display = 'flex';
}


// 2. ЗАКРЫТИЕ МОДАЛКИ
async function closeContractModal() {
    const modal = document.getElementById('contractModal');
    const clientId = document.getElementById('modal_client_id').value;
    const projectId = modal.dataset.pid;

    if (modal) modal.style.display = 'none';

    // Если договор НЕ был заполнен и сохранен, а менеджер нажал "Отмена"
    if (clientId) {
        try {
            console.log("Ошибочный клик. Удаляем пустой контракт и снимаем галку...");
            
            // 1. Отправляем запрос на удаление пустого черновика и снятие галки
            const res = await fetch('cancel_contract.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ client_id: clientId, project_id: projectId })
            });
            
            const result = await res.json();
            if (result.status === 'success') {
                // 2. Перенаправляем обратно на главную со снятой галкой
                window.location.href = 'index.php';
                return;
            }
        } catch (err) {
            console.error("Ошибка при отмене контракта:", err);
        }
    }
    window.location.href = 'index.php';
}
async function openTtnManager(pid, label) {
    console.log('открываем ТТН менеджер для договора с ID:', pid);
    
    const modal = document.getElementById('ttnManagerModal');
    const storage = document.getElementById('ttn_pid_storage');
    
    if (!modal || !storage) {
        alert("Ошибка: элементы модального окна ТТН не найдены в HTML!");
        return;
    }
    storage.value = pid; 
    loadProjectTtns(pid);
    modal.style.display ="flex";


}

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

    const res = await fetch('save_new_contract.php', { method: 'POST', body: fd });
    if ((await res.json()).status === 'success') {
        window.location.href = 'contracts.php'; // Перезагружаем страницу без параметров
    }
};


   
function checkReminders() {
    console.log("Проверка напоминаний запущена");
}

// Запуск при загрузке
document.addEventListener('DOMContentLoaded', () => {
    const cForm = document.getElementById('contractForm');
    
    if (cForm) {
        cForm.onsubmit = async function(e) {
            e.preventDefault(); // Теперь это точно остановит перезагрузку
            console.log("Форма найдена, отправляю данные...");

            const fd = new FormData(this);
            try {
                const res = await fetch('save_new_contract.php', {
                    method: 'POST',
                    body: fd
                });
                const result = await res.json();
                if (result.status === 'success') {
                    location.href = 'contracts.php'; // Чистая перезагрузка без параметров в URL
                } else {
                    alert("Ошибка: " + result.message);
                }
            } catch (err) {
                console.error("Ошибка:", err);
            }
        };
    } else {
        console.error("КРИТИЧЕСКАЯ ОШИБКА: Форма с id='contractForm' не найдена в HTML!");
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
                loadProjectTtns(pid);
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