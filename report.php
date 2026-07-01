<?php
session_start();
require 'db.php'; // Подключаем СУБД и авто-логгер действий

// ЖЕСТКАЯ БЕЗОПАСНОСТЬ: Доступ только для авторизованных сотрудников
if (!isset($_SESSION['user_id'])) {
    header("Location: auth.html");
    exit;
}

// 1. ОБРАБОТКА И ФИЛЬТРАЦИЯ ПЕРИОДА ДАТ
// По умолчанию ставим текущий месяц, если даты не переданы из формы
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : date('Y-m-01');
$date_to   = isset($_GET['date_to']) ? trim($_GET['date_to']) : date('Y-m-d');

// 2. СБОР ДАННЫХ ДЛЯ МАТРИЦЫ ОТГРУЗОК
// Запрос связывает ТТН, контракты и клиентов для распределения сумм по менеджерам, договорам и валютам
try {
    $matrix_sql = "SELECT
                        t.project_id AS project_id, 
                        p.contract_number AS contract_number,
                        p.contract_date AS contract_date,
                        t.product_info AS product_name,
                        t.currency AS ttn_currency,
                        COALESCE(u.login, 'Не указан') AS manager_name,
                        COUNT(t.id) AS ttn_count,
                        SUM(t.amount) AS total_amount
                   FROM project_ttns t
                   LEFT JOIN projects p ON t.project_id = p.id
                   LEFT JOIN clients c ON p.client_id = c.id
                   LEFT JOIN users u ON c.manager_id = u.id 
                   WHERE t.ttn_date BETWEEN :date_from AND :date_to
                   GROUP BY 
                        t.project_id, 
                        p.contract_number, 
                        p.contract_date,
                        t.product_info, 
                        t.currency, 
                        COALESCE(u.login, 'Не указан')
                   ORDER BY t.product_info ASC, total_amount DESC";

    $matrix_stmt = $pdo->prepare($matrix_sql);
    $matrix_stmt->execute([
        ':date_from' => $date_from,
        ':date_to'   => $date_to
    ]);
    $raw_matrix = $matrix_stmt->fetchAll();

    // Вычисляем максимальную сумму отгрузки для построения инфографического масштаба шкал
    // (Для корректности шкалы сравниваем чистые суммы, так как вывод стал повалютным)
    $maxAmount = 1;
    foreach ($raw_matrix as $r) {
        if ((float)$r['total_amount'] > $maxAmount) {
            $maxAmount = (float)$r['total_amount'];
        }
    }
} catch (Exception $e) {
    $raw_matrix = [];
    $maxAmount = 1;
    error_log("Ошибка генерации матрицы отгрузок: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Матрица отгрузок</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* БАЗОВАЯ МОДУЛЬНАЯ СЕТКА СТРАНИЦЫ АНАЛИТИКИ */
        body { background: #151521; color: #fff; font-family: 'Segoe UI', Roboto, sans-serif; padding: 30px; margin:0; display: flex; box-sizing: border-box; min-height: 100vh; }
        aside { width: 250px; flex-shrink: 0; }
        
        .main-content { flex: 1; padding-left: 25px; box-sizing: border-box; display: flex; flex-direction: column; min-width: 0; }
        
        /* ШАПКА ФИЛЬТРАЦИИ ПЕРИОДА */
        .report-header { background: #1e1e2d; padding: 20px 30px; border-radius: 12px; border: 1px solid #2b2b40; display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .filter-form { display: flex; gap: 15px; align-items: center; }
        .filter-form label { font-size: 12px; color: #92929f; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; }
        .input-date { background: #151521; border: 1px solid #323248; color: #fff; padding: 8px 12px; border-radius: 6px; outline: none; font-size: 13px; font-weight: 600; }
        .btn-submit { background: #4f46e5; color: white; border: none; padding: 9px 18px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: bold; transition: background 0.15s; }
        .btn-submit:hover { background: #4338ca; }
        .btn-reset { color: #92929f; text-decoration: none; font-size: 13px; font-weight: 600; transition: color 0.15s; }
        .btn-reset:hover { color: #fff; }
        
        /* КОНТЕЙНЕР ТАБЛИЦЫ */
        .table-wrapper { background: #1e1e2d; border-radius: 14px; border: 1px solid #323248; padding: 25px; box-shadow: 0 15px 40px rgba(0,0,0,0.4); box-sizing: border-box; width: 100%; }
        
        /* СТИЛИЗАЦИЯ ИНТЕРАКТИВНОЙ ТАБЛИЦЫ */
        table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; text-align: left; }
        th { background: #242434; color: #92929f; padding: 14px; border-bottom: 2px solid #323248; text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 0.5px; }
        td { padding: 14px 12px; border-bottom: 1px solid #2b2b40; color: #cbd5e1; box-sizing: border-box; vertical-align: middle; }
        
        .category-header { background: #1c1c28 !important; font-weight: bold; color: #a855f7 !important; padding: 12px 15px; border-bottom: 1px solid #323248; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; }
        
        /* СТРОКИ МЕНЕДЖЕРОВ С ЭФФЕКТОМ НАВЕДЕНИЯ */
        .clickable-manager-row { cursor: pointer; }
        .clickable-manager-row:hover { background: #24243c !important; }
        .clickable-manager-row:hover td:first-child { transform: translateX(6px); color: #fff !important; }
        .clickable-manager-row td:first-child { transition: transform 0.2s ease, color 0.2s ease; }
        
        /* НАДТИВНЫЙ ИНФОГРАФИЧЕСКИЙ ПРОГРЕСС-БАР */
        .bar-outer { width: 100%; background: #151521; height: 6px; border-radius: 3px; position: relative; overflow: hidden; }
        .bar-inner { height: 100%; background: linear-gradient(90deg, #4f46e5, #818cf8); border-radius: 3px; transition: width 0.5s ease-in-out; }
        
        /* ИНДИКАТОР СТРЕЛКИ РАСКРЫТИЯ */
        .arrow-icon { display: inline-block; transition: transform 0.2s ease; font-size: 9px; color: #6366f1; margin-left: 6px; }
        
        /* КОНТЕЙНЕР ПОДТАБЛИЦЫ ДЕТАЛИЗАЦИИ ТТН */
        .details-content-box { padding: 0 30px; border-left: 4px solid #4f46e5; max-height: 0; overflow: hidden; transition: max-height 0.3s ease-out, padding 0.3s ease-out; background: #11111a; }
        .details-scroll-container { max-height: 300px; overflow-y: auto; padding-right: 5px; }

        /* ИСПРАВЛЕНО: Стилизация кликабельных ссылок на договоры/проекты */
        .project-link { color: #38bdf8; text-decoration: none; font-weight: 600; border-bottom: 1px dashed rgba(56,189,248,0.4); transition: all 0.15s ease; }
        .project-link:hover { color: #7dd3fc; border-bottom-style: solid; }

        /* ИСПРАВЛЕНО: Мультивалютные компактные бейджи */
        .badge-currency { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 800; text-transform: uppercase; margin-left: 6px; letter-spacing: 0.5px; }
        .badge-byn { background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-rub { background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-eur { background: rgba(59, 130, 246, 0.15); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.3); }
        .badge-usd { background: rgba(139, 92, 246, 0.15); color: #8b5cf6; border: 1px solid rgba(139, 92, 246, 0.3); }
     /* Стилизация внутреннего скроллбара под дизайн вашей CRM */
    .details-scroll-container::-webkit-scrollbar { width: 6px; }
    .details-scroll-container::-webkit-scrollbar-track { background: #11111a; border-radius: 4px; }
    .details-scroll-container::-webkit-scrollbar-thumb { background: #2b2b40; border-radius: 3px; }
    .details-scroll-container::-webkit-scrollbar-thumb:hover { background: #4f46e5; }
   </style>
    </head>
    <body>
        <!-- ПОДКЛЮЧЕНИЕ САЙДБАРА CRM -->
            <?php include 'sidebar.php'; ?>
        <div class="main-content">
            
            <!-- ШАПКА ФИЛЬТРАЦИИ И КАНАЛЕНДАРНЫЙ ПЕРИОД -->
            <div class="report-header">
            <h2 style="margin: 0; font-size: 18px; font-weight: bold; letter-spacing: 0.3px; display: flex; align-items: center; gap: 10px;">
        📦  Продуктовый анализ отгрузок Santeks 
        <span style="font-size: 12px; color: #818cf8; background: rgba(99,102,241,0.15); padding: 4px 8px; border-radius: 6px; font-family: monospace;">
            Ваш сессионный ID: <?= $_SESSION['user_id'] ?? 'Не авторизован' ?> (<?= $_SESSION['role'] ?? 'нет роли' ?>)
        </span>
    </h2>
            
          <form method="GET" class="filter-form">
                <label>Период отгрузок ТТН:</label>
                <!-- Принудительно задаем id для корректного считывания движком JS -->
                <input type="date" id="date_from" name="date_from" class="input-date" value="<?= htmlspecialchars($date_from) ?>">
                <label style="text-transform:lowercase; font-weight:normal;">по</label>
                <input type="date" id="date_to" name="date_to" class="input-date" value="<?= htmlspecialchars($date_to) ?>">
                
                <button type="submit" class="btn-submit">📊 Сформировать анализ номенклатуры</button>
                <a href="report.php" class="btn-reset">Сбросить период</a>
            </form>
        </div>
        
        <!-- МАТРИЦА ВСЕХ ОТГРУЖЕННЫХ ТОВАРОВ И ИХ ОБЪЕМОВ -->
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <!-- ДОБАВЛЕНО: Колонка для ID и номера договора -->
                        <th style="text-align: left; width: 140px;">Договор</th>
                        <th style="text-align: left; width: 180px;">Менеджер</th>
                        <th style="width: 120px; text-align: center;">Накладные</th>
                        <th style="text-align: left; min-width: 300px;">Объем отгрузок (Инфографический масштаб)</th>
                        <!-- ИСПРАВЛЕНО: Объединено в одну повалютную колонку -->
                        <th style="width: 180px; text-align: right; color: #a855f7;">Сумма отгрузки</th>
                    </tr>
                </thead>
                <tbody>
                <?php 
                    if (!empty($raw_matrix)): 
                        $current_product = '';
                        foreach ($raw_matrix as $row): 
                            // Сумма и валюта текущей строки отгрузки
                            $total_amount = (float)$row['total_amount'];
                            $currency     = strtoupper($row['ttn_currency'] ?? 'BYN');
                            
                            // Вычисляем процент шкалы прогрессбара на основе чистой суммы
                            $percentWidth = min(100, max(3, round(($total_amount / $maxAmount) * 100)));

                            // Подготавливаем CSS класс для валютного бейджа
                            $badge_class = 'badge-byn';
                            if ($currency === 'RUB') $badge_class = 'badge-rub';
                            if ($currency === 'EUR') $badge_class = 'badge-eur';
                            if ($currency === 'USD') $badge_class = 'badge-usd';

                            // Если пошел новый вид продукции — отрисовываем заголовок-разделитель категории
                            if ($current_product !== $row['product_name']):
                                $current_product = $row['product_name'];
                    ?>
                              <tr>
    <td colspan="5" class="category-header js-category-header-row" style="padding: 10px 14px; vertical-align: middle;">
        <div style="display: flex; justify-content: space-between; align-items: center; width: 100%; box-sizing: border-box;">
            
            <!-- Левая часть: Твоё оригинальное название категории продукции -->
            <div>
                📁 Категория продукции: <span style="color: #fff; font-size: 14px; font-weight: bold; text-transform: none;"><?= htmlspecialchars($current_product) ?></span>
            </div>

            <!-- Правая часть: Контейнер для вывода промежуточных повалютных сумм этой группы -->
            <div class="js-category-totals-holder" style="display: flex; gap: 8px; align-items: center; padding-right: 5px;">
             <script>
// РЕАКТИВНЫЙ КАЛЬКУЛЯТОР КАТЕГОРИЙ: Считает промежуточные итоги между строками заголовков
document.addEventListener("DOMContentLoaded", function() {
    // Находим все наши новые заголовочные ячейки категорий
    const categoryHeaders = document.querySelectorAll('.js-category-header-row');

    categoryHeaders.forEach((headerTd, index) => {
        const totalsHolder = headerTd.querySelector('.js-category-totals-holder');
        if (!totalsHolder) return;

        const catTotals = { 'BYN': 0, 'RUB': 0, 'USD': 0, 'EUR': 0 };
        let hasData = false;

        // Ищем родительскую строку текущего заголовка
        const currentHeaderRow = headerTd.closest('tr');
        if (!currentHeaderRow) return;

        // Запускаем перебор следующих строк таблицы, чтобы собрать суммы до следующей категории
        let nextRow = currentHeaderRow.nextElementSibling;

        while (nextRow) {
            // Если наткнулись на заголовок следующей категории — останавливаем сбор данных для текущей
            if (nextRow.querySelector('.js-category-header-row')) {
                break;
            }

            // Ищем плашку валюты в самой последней ячейке текущей строки контракта
            const badge = nextRow.querySelector('.badge-currency');
            if (badge) {
                const currencyText = badge.textContent.trim().toUpperCase();
                const td = badge.parentElement;

                if (td && ['BYN', 'RUB', 'USD', 'EUR'].includes(currencyText)) {
                    // Клонируем ячейку, убираем спан валюты и парсим чистое число
                    const tdClone = td.cloneNode(true);
                    const badgeInClone = tdClone.querySelector('.badge-currency');
                    if (badgeInClone) badgeInClone.remove();

                    // Очищаем число от разделителей тысяч (пробелов)
                    const rawAmountText = tdClone.textContent.replace(/\s/g, '').replace(/,/g, '.').trim();
                    const amountValue = parseFloat(rawAmountText) || 0;

                    if (amountValue > 0) {
                        catTotals[currencyText] += amountValue;
                        hasData = true;
                    }
                }
            }

            // Переходим к следующей строке таблицы <tr>
            nextRow = nextRow.nextElementSibling;
        }

        // Генерируем компактные микро-модули для вывода в желтую строку
        let htmlContent = '';
        for (const [curr, sum] of Object.entries(catTotals)) {
            if (sum <= 0) continue;

            let neonColor = '#10b981'; // BYN
            if (curr === 'RUB') neonColor = '#ef4444';
            if (curr === 'USD') neonColor = '#3b82f6';
            if (curr === 'EUR') neonColor = '#eab308';

            const formattedSum = sum.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            htmlContent += `
                <div style="background: rgba(255,255,255,0.04); border: 1px solid ${neonColor}30; padding: 4px 8px; border-radius: 4px; display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: normal; letter-spacing: 0px; font-family: sans-serif;">
                    <span style="font-family: monospace; font-weight: bold; color: #fff;">${formattedSum}</span>
                    <span style="color: ${neonColor}; font-weight: 800; font-size: 10px; text-transform: uppercase;">${curr}</span>
                </div>
            `;
        }

        if (hasData) {
            totalsHolder.innerHTML = htmlContent;
        } else {
            totalsHolder.innerHTML = `<span style="color: #64748b; font-size: 11px; font-weight: normal;">Нет отгрузок</span>`;
        }
    });
});
</script>
            </div>

        </div>
    </td>
</tr>
                    <?php 
                            endif; 
                    ?>
                            <tr class="clickable-manager-row">
                                <!-- 1. КОЛОНКА ДОГОВОРА: Выводим ID и Номер договора как ссылку -->
                                <td>
                                    <?php if (!empty($row['project_id'])): ?>
                                        <a href="project_card.php?id=<?= intval($row['project_id']) ?>" class="project-link" title="Перейти в карточку договора">
                                            #<?= intval($row['project_id']) ?> / <?= htmlspecialchars($row['contract_number'] ?? 'Б/Н') ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #62627a;">—</span>
                                    <?php endif; ?>
                                </td>

                                <!-- 2. МЕНЕДЖЕР -->
                                <td>
                                    👤 <?= htmlspecialchars($row['manager_name']) ?>
                                </td>

                                <!-- 3. ОФОРМЛЕНО НАКЛАДНЫХ -->
                                <td style="text-align: center; font-weight: bold; color: #a855f7;">
                                    <?= intval($row['ttn_count']) ?> шт.
                                </td>

                                <!-- 4. ИНФОГРАФИЧЕСКИЙ ПРОГРЕСС-БАР -->
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div class="bar-outer" style="flex: 1;">
                                            <div class="bar-inner" style="width: <?= $percentWidth ?>%;"></div>
                                        </div>
                                        <span style="font-size: 11px; color: #92929f; font-weight: 600; min-width: 30px; text-align: right;">
                                            <?= $percentWidth ?>%
                                        </span>
                                    </div>
                                </td>

                                <!-- 5. СУММА ОТГРУЗКИ: Повалютный вывод с цветным индикатором -->
                                <td style="text-align: right; font-weight: bold; font-family: monospace; font-size: 14px; color: #fff;">
                                    <?= number_format($total_amount, 2, '.', ' ') ?>
                                    <span class="badge-currency <?= $badge_class ?>"><?= htmlspecialchars($currency) ?></span>
                                </td>
                            </tr>
                    <?php 
                        endforeach; 
                    else: 
                    ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 40px; color: #92929f; font-size: 14px;">
                                📭 За выбранный период отгрузок по накладным не найдено.
                            </td>
                        </tr>
                

                        <tr>
                            <td colspan="5" style="padding: 40px; color: #64748b; font-weight: bold; text-align: center;">
                                Отгрузки по номенклатуре за выбранный период дат отсутствуют в СУБД.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                
            </table>
        </div>
        <?php
// НАМЕРТВО ИСПРАВЛЕНО: Массив для накопления глобальных итогов по каждой валюте
$globalCurrencyTotals = [
    'BYN' => 0.00,
    'RUB' => 0.00,
    'USD' => 0.00,
    'EUR' => 0.00
];
?>
<?php
// Автоматический перехват валюты и суммы из текущей строки контракта
$loopCurrency = strtoupper(trim($r['currency'] ?? 'BYN'));
if (empty($loopCurrency) || $loopCurrency === 'NULL') {
    $loopCurrency = 'BYN';
}

$loopAmount = (float)($r['total_amount'] ?? ($r['amount'] ?? 0));

// Плюсуем строго в глобальную область видимости
$globalCurrencyTotals[$loopCurrency] = ($globalCurrencyTotals[$loopCurrency] ?? 0.00) + $loopAmount;
?>
<?php
// НАМЕРТВО ИСПРАВЛЕНО: Сборка строки из заполненного массива
$totalsParts = [];
foreach ($globalCurrencyTotals as $curr => $sum) {
    if ($sum <= 0) continue; // Пропускаем пустые валюты

    $color = '#10b981'; // BYN
    if ($curr === 'RUB') $color = '#ef4444';
    if ($curr === 'USD') $color = '#3b82f6';
    if ($curr === 'EUR') $color = '#eab308';

    $formattedSum = number_format($sum, 2, '.', ' ');
    $totalsParts[] = "<span style='font-family: monospace; font-weight: bold; color: #fff;'>{$formattedSum}</span> <span style='color: {$color}; font-weight: bold; margin-right: 12px;'>{$curr}</span>";
}

$globalTotalsString = !empty($totalsParts) ? implode(' / ', $totalsParts) : "0.00 BYN";
?>
<!-- ПАРЯЩИЙ МУЛЬТИВАЛЮТНЫЙ ФУТЕР СТРАНИЦЫ (БЕЗ КОНВЕРТЕРОВ И JAVASCRIPT) -->

<div style="background: #1e1e2d; border: 1px solid #323248; border-radius: 12px; padding: 24px; margin-top: 30px; box-sizing: border-box; width: 100%; clear: both;">
    
    <!-- Заголовок блока аналитики -->
    <div style="text-align: left; margin-bottom: 20px; border-bottom: 1px dashed #323248; padding-bottom: 15px;">
        <div style="font-size: 11px; color: #64748b; font-weight: bold; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 4px;">Финансовая аналитика</div>
        <h3 style="margin: 0; font-size: 18px; color: #fff; font-weight: 600; letter-spacing: -0.3px;">Глобальный итог отгрузок</h3>
    </div>

    <!-- КОНТЕНТНЫЙ ГРИД: Автоматическая адаптивная сетка карточек валют -->
    <div id="js-premium-currency-dashboard" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; width: 100%; box-sizing: border-box;">
        <div style="color: #64748b; font-size: 13px; font-family: sans-serif; grid-column: 1/-1; text-align: center; padding: 20px;">⌛ Вычисление финансовых итогов...</div>
    </div>

</div>
<script>
// ВСЕЯДНЫЙ ДАШБОРД: Парсит отформатированные ячейки со 100% точностью
document.addEventListener("DOMContentLoaded", function() {
    const dashboard = document.getElementById('js-premium-currency-dashboard');
    if (!dashboard) return;

    // 1. Инициализируем объект накопителя для валют
    const currencyTotals = { 'BYN': 0, 'RUB': 0, 'USD': 0, 'EUR': 0 };
    let hasData = false;

    // 2. Находим ячейки по селектору твоего класса спана валюты
    const badges = document.querySelectorAll('.badge-currency');
    
    if (badges.length > 0) {
        badges.forEach(badge => {
            const currencyText = badge.textContent.trim().toUpperCase(); // Получаем USD, BYN и т.д.
            const td = badge.parentElement; // Поднимаемся к ячейке <td> [1]
            
            if (td && ['BYN', 'RUB', 'USD', 'EUR'].includes(currencyText)) {
                // Клонируем ячейку, чтобы временно удалить спан и забрать только число
                const tdClone = td.cloneNode(true);
                const badgeInClone = tdClone.querySelector('.badge-currency');
                if (badgeInClone) badgeInClone.remove();
                
                // Очищаем строку от ЛЮБЫХ пробелов (разделителей тысяч) и переносов строк
                // Заменяем запятые на точки на случай дробных сумм
                const rawAmountText = tdClone.textContent.replace(/\s/g, '').replace(/,/g, '.').trim();
                const amountValue = parseFloat(rawAmountText) || 0;
                
                if (amountValue > 0) {
                    currencyTotals[currencyText] += amountValue;
                    hasData = true;
                }
            }
        });
    }

    // 3. Генерируем новое модульное представление
    let htmlContent = '';

    for (const [curr, sum] of Object.entries(currencyTotals)) {
        if (sum <= 0) continue; // Пропускаем нулевые валюты

        // Настройка неоновых градиентов под стиль CRM [1]
        let neonColor = '#10b981'; // BYN
        let bgGlow = 'rgba(16, 185, 129, 0.04)';
        if (curr === 'RUB') { neonColor = '#ef4444'; bgGlow = 'rgba(239, 68, 68, 0.04)'; }
        if (curr === 'USD') { neonColor = '#3b82f6'; bgGlow = 'rgba(59, 130, 246, 0.04)'; }
        if (curr === 'EUR') { neonColor = '#eab308'; bgGlow = 'rgba(234, 179, 8, 0.04)'; }

        // Форматируем сумму по стандартам бухучета (с разделением тысяч пробелами)
        const formattedSum = sum.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        htmlContent += `
            <div style="background: ${bgGlow}; border: 1px solid ${neonColor}40; padding: 10px 16px; border-radius: 8px; display: flex; align-items: center; gap: 12px; min-width: 150px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); transition: transform 0.2s; box-sizing: border-box;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                <div style="text-align: left;">
                    <span style="font-size: 10px; color: #64748b; font-weight: bold; display: block; margin-bottom: 2px; text-transform: uppercase;">ИТОГО ${curr}</span>
                    <span style="font-family: 'Courier New', monospace; font-size: 15px; font-weight: bold; color: #ffffff; letter-spacing: -0.5px;">${formattedSum}</span>
                </div>
                <div style="background: ${neonColor}; color: #1e1e2d; font-size: 10px; font-weight: 900; padding: 3px 6px; border-radius: 4px; line-height: 1; height: fit-content; text-transform: uppercase;">
                    ${curr}
                </div>
            </div>
        `;
    }

    dashboard.innerHTML = hasData ? htmlContent : `<div style="color: #64748b; font-size: 13px; padding: 5px;">Пока нет данных для расчета итогов</div>`;
});
</script>

</div>

<style>
    /* Контейнер таблицы с фиксированной высотой и внутренним скроллом */
.table-wrapper { 
    background: #1e1e2d; 
    border-radius: 14px; 
    border: 1px solid #323248; 
    padding: 25px; 
    box-shadow: 0 15px 40px rgba(0,0,0,0.4); 
    box-sizing: border-box; 
    width: 100%; 
    max-height: 700px;    /* ИСПРАВЛЕНО: Ограничиваем высоту всего отчета */
    overflow-y: auto;     /* ИСПРАВЛЕНО: Включаем скролл внутри wrapper */
}

/* Кастомизация скроллбара самого отчета */
.table-wrapper::-webkit-scrollbar { width: 8px; }
.table-wrapper::-webkit-scrollbar-track { background: #1e1e2d; }
.table-wrapper::-webkit-scrollbar-thumb { background: #2b2b40; border-radius: 4px; }
.table-wrapper::-webkit-scrollbar-thumb:hover { background: #4f46e5; }

/* ИСПРАВЛЕНО: Новый стиль для интерактивного заголовка категории */
.category-header-row {
    cursor: pointer;
    background: #1c1c28 !important;
    transition: background 0.2s ease;
}
.category-header-row:hover {
    background: #242434 !important;
}
.category-header { 
    font-weight: bold; 
    color: #a855f7 !important; 
    padding: 14px 15px; 
    border-bottom: 1px solid #323248; 
    text-transform: uppercase; 
    font-size: 13px; 
    letter-spacing: 0.5px; 
}

/* Стрелочка для раскрытия самой категории */
.category-arrow {
    display: inline-block;
    transition: transform 0.2s ease;
    font-size: 11px;
    color: #a855f7;
    margin-left: 8px;
}

/* Скрытые по умолчанию строки договоров внутри категории */
.manager-data-row {
    display: none; /* ИСПРАВЛЕНО: По умолчанию всё свернуто */
}
</style>
<!-- АВТОНОМНЫЙ JS СКРИПТ ПОТОКОВОЙ ВЫГРУЗКИ ПОДРОБНОСТЕЙ -->
<script>

// ИСПРАВЛЕНО: Функция теперь принимает project_id и валюту из строки таблицы
async function toggleTtnDetails(rowElement, managerName, productName, projectId, currency) {
    const detailsRow = rowElement.nextElementSibling;
    const contentBox = detailsRow.querySelector('.details-content-box');
    const arrow = rowElement.querySelector('.arrow-icon');
    
    // Переключатель сворачивания/разворачивания
    if (detailsRow.style.display === 'table-row') {
        contentBox.style.maxHeight = '0px';
        contentBox.style.padding = '0px 30px';
        if (arrow) arrow.style.transform = 'rotate(0deg)';
        setTimeout(() => { detailsRow.style.display = 'none'; }, 300);
        return;
    }
    
    // Анимированно открываем ячейку
    detailsRow.style.display = 'table-row';
    contentBox.style.padding = '15px 30px';
    contentBox.style.maxHeight = '600px'; 
    if (arrow) {
        arrow.style.transform = 'rotate(180deg)';
        arrow.style.transition = 'transform 0.2s ease';
    }
    
    // Блокируем повторный AJAX-запрос, если массив ТТН уже отрисован в этой строке
    if (contentBox.dataset.loaded === 'true') return;
    
    try {
        // Забираем текущие значения дат из календарей шапки страницы
        const dateFromInput = document.getElementById('date_from');
        const dateToInput = document.getElementById('date_to');
        
        const dateFrom = dateFromInput ? dateFromInput.value : '';
        const dateTo = dateToInput ? dateToInput.value : '';

        // ИСПРАВЛЕНО: Отправляем асинхронный POST-пакет с точными параметрами договора и валюты
        const response = await fetch('get_matrix_details.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ 
                manager: managerName, 
                category: productName,
                project_id: projectId,   // Передаем ID договора
                currency: currency,       // Передаем конкретную валюту
                date_from: dateFrom,
                date_to: dateTo
            })
        });
        
        const data = await response.json();
        
       data.ttns.forEach(ttn => {
                // ИСПРАВЛЕНО: Приводим валюту к верхнему регистру для точного совпадения с CSS-классами
                const curr = (ttn.currency || 'BYN').toUpperCase();
                let badgeClass = 'badge-byn';
                if (curr === 'RUB') badgeClass = 'badge-rub';
                if (curr === 'EUR') badgeClass = 'badge-eur';
                if (curr === 'USD') badgeClass = 'badge-usd';

                // ИСПРАВЛЕНО: Красиво форматируем сумму на лету с разделением тысяч
                const formattedAmount = parseFloat(ttn.amount).toLocaleString('ru-RU', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });

              
            html += `</tbody></table>`;
            contentBox.innerHTML = html;
            contentBox.dataset.loaded = 'true';
        } else if (data.status === 'error') {
            contentBox.innerHTML = `<div style="color: #ef4444; font-size: 12px; font-weight: bold; padding: 10px 0;">${data.message}</div>`;
        } else {
            contentBox.innerHTML = `<div style="color: #707084; font-size: 12px; font-style: italic; padding: 10px 0;">Подробные накладные по выбранным критериям за данный период дат отсутствуют в СУБД.</div>`;
        }
    } catch (error) {
        contentBox.innerHTML = `<div style="color: #ef4444; font-size: 12px; padding: 10px 0;">Критический сбой ответа сервера. Убедитесь в стабильности подключения СУБД.</div>`;
    }
}
</script>
</body>
</html>