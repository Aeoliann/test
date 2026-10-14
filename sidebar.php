<?php
// Определяем роль пользователя, если она не передана
if (!isset($userRole) && isset($_SESSION['role'])) {
    $userRole = $_SESSION['role'];
}
if (!isset($userRole)) {
    $userRole = 'guest';
}

// Текущий файл для подсветки активного пункта
$currentFile = basename($_SERVER['PHP_SELF']);

// =========================================================================
// СТРАНИЦЫ ГРУПП ДЛЯ АВТОРАСКРЫТИЯ
// =========================================================================
$crmPages = ['index.php', 'contracts.php', 'directory.php', 'top_clients.php'];
if ($userRole === 'admin' || $userRole === 'semi-admin') {
    $crmPages[] = 'report.php';
}

$servicePages = ['bug_reports.php', 'help.php'];
if ($userRole === 'admin' || $userRole === 'semi-admin') {
    $servicePages[] = 'activity_logs.php';
}
$servicePages[] = 'logout.php';

// Страницы группы «Резервуары» (РВС)
$rvsPages = ['tank_cards.php', 'tank_card_view.php', 'tank_archive.php', 'tank_sz.php', 'tank_sz_view.php', 'tank_materials.php', 'tank_production.php', 'tank_installation.php', 'tank_tasks.php', 'tank_calculator.php', 'tank_analytics.php'];

// =========================================================================
// СИСТЕМА УВЕДОМЛЕНИЙ (для бейджа на CRM)
// =========================================================================
$notificationCount = 0;
if (isset($pdo) && ($userRole === 'admin' || $userRole === 'manager')) {
    try {
        if ($userRole === 'admin') {
            $sql = "SELECT COUNT(*) 
                    FROM clients c
                    WHERE c.status != 'Отказ' 
                      AND c.next_contact_date IS NOT NULL 
                      AND c.next_contact_date != '0000-00-00'
                      AND c.next_contact_date <= CURDATE() + INTERVAL 2 DAY
                      AND NOT EXISTS (
                          SELECT 1 FROM projects p 
                          WHERE p.client_id = c.id 
                            AND p.contract_number IS NOT NULL 
                            AND p.contract_number != '' 
                            AND p.contract_number != 'Б/Н'
                      )";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
        } else {
            $sql = "SELECT COUNT(*) 
                    FROM clients c
                    WHERE c.manager_id = ? 
                      AND c.status != 'Отказ' 
                      AND c.next_contact_date IS NOT NULL 
                      AND c.next_contact_date != '0000-00-00'
                      AND c.next_contact_date <= CURDATE() + INTERVAL 2 DAY
                      AND NOT EXISTS (
                          SELECT 1 FROM projects p 
                          WHERE p.client_id = c.id 
                            AND p.contract_number IS NOT NULL 
                            AND p.contract_number != '' 
                            AND p.contract_number != 'Б/Н'
                      )";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$_SESSION['user_id']]);
        }
        $notificationCount = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $notificationCount = 0;
    }
}

// =========================================================================
// СПИСОК УВЕДОМЛЕНИЙ ДЛЯ ВЫПАДАЮЩЕГО СПИСКА
// =========================================================================
$notifications = [];
if (isset($pdo) && ($userRole === 'admin' || $userRole === 'manager')) {
    try {
        if ($userRole === 'admin') {
            $sql = "SELECT c.id, c.client_name, c.next_contact_date, c.status 
                    FROM clients c
                    WHERE c.status != 'Отказ' 
                      AND c.next_contact_date IS NOT NULL 
                      AND c.next_contact_date != '0000-00-00'
                      AND c.next_contact_date <= CURDATE() + INTERVAL 2 DAY
                      AND NOT EXISTS (
                          SELECT 1 FROM projects p 
                          WHERE p.client_id = c.id 
                            AND p.contract_number IS NOT NULL 
                            AND p.contract_number != '' 
                            AND p.contract_number != 'Б/Н'
                      )
                    ORDER BY c.next_contact_date ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
        } else {
            $sql = "SELECT c.id, c.client_name, c.next_contact_date, c.status 
                    FROM clients c
                    WHERE c.manager_id = ? 
                      AND c.status != 'Отказ' 
                      AND c.next_contact_date IS NOT NULL 
                      AND c.next_contact_date != '0000-00-00'
                      AND c.next_contact_date <= CURDATE() + INTERVAL 2 DAY
                      AND NOT EXISTS (
                          SELECT 1 FROM projects p 
                          WHERE p.client_id = c.id 
                            AND p.contract_number IS NOT NULL 
                            AND p.contract_number != '' 
                            AND p.contract_number != 'Б/Н'
                      )
                    ORDER BY c.next_contact_date ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$_SESSION['user_id']]);
        }
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $notifications = [];
    }
}
if (!isset($notifications)) $notifications = [];
$notificationsCount = count($notifications);

// Роли, которые видят архив КД
$canSeeArchive = in_array($userRole, ['admin', 'semi-admin', 'designer'], true);
?>
<aside class="sidebar">
    <div class="sidebar-logo">Santeks CRM</div>
    
    <nav class="sidebar-nav">
        <?php if ($userRole !== 'executor'): ?>
        <!-- ================= CRM ================= -->
        <div class="nav-group <?= in_array($currentFile, $crmPages) ? 'open' : '' ?>">
            <a href="#" class="nav-link has-dropdown" data-toggle="dropdown">
                <span class="nav-icon">💼</span>
                <span class="nav-text">CRM</span>
                <span class="dropdown-arrow">▾</span>
            </a>
            <div class="dropdown-menu">
                <a href="index.php" class="dropdown-item <?= $currentFile == 'index.php' ? 'active' : '' ?>">
                    <span class="nav-icon">🏠</span> Главная
                </a>
                <a href="contracts.php" class="dropdown-item <?= $currentFile == 'contracts.php' ? 'active' : '' ?>">
                    <span class="nav-icon">📄</span> Контракты и договоры
                </a>
                <a href="directory.php" class="dropdown-item <?= $currentFile == 'directory.php' ? 'active' : '' ?>">
                    <span class="nav-icon">📚</span> Общий справочник базы
                </a>
                <?php if ($userRole === 'admin' || $userRole === 'semi-admin'): ?>
                    <a href="report.php" class="dropdown-item <?= $currentFile == 'report.php' ? 'active' : '' ?>">
                        <span class="nav-icon">📊</span> Сводный отчёт
                    </a>
                <?php endif; ?>
                <a href="top_clients.php" class="dropdown-item <?= $currentFile == 'top_clients.php' ? 'active' : '' ?>">
                    <span class="nav-icon">⭐</span> Топ клиентов
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- ================= ЗАДАЧИ (отдельный пункт) ================= -->
        <a href="tasks.php" class="nav-link <?= $currentFile == 'tasks.php' ? 'active' : '' ?>">
            <span class="nav-icon">📋</span>
            <span class="nav-text">Задачи</span>
        </a>

        <!-- ================= РЕЗЕРВУАРЫ (раскрывающийся) ================= -->
        <?php if (in_array($userRole, ['admin', 'semi-admin', 'manager', 'sales_manager', 'designer', 'tech_dept', 'warehouse', 'supply', 'production_chief', 'production_master', 'installation_chief', 'installation_master', 'director'], true)): ?>
        <div class="nav-group <?= in_array($currentFile, $rvsPages) ? 'open' : '' ?>">
            <a href="#" class="nav-link has-dropdown" data-toggle="dropdown">
                <span class="nav-icon">🛢️</span>
                <span class="nav-text">Резервуары</span>
                <span class="dropdown-arrow">▾</span>
            </a>
            <div class="dropdown-menu">
                <a href="tank_cards.php" class="dropdown-item <?= $currentFile == 'tank_cards.php' ? 'active' : '' ?>">
                    <span class="nav-icon">📋</span> Реестр карточек
                </a>
                <?php if ($canSeeArchive): ?>
                    <a href="tank_archive.php" class="dropdown-item <?= $currentFile == 'tank_archive.php' ? 'active' : '' ?>">
                        <span class="nav-icon">📦</span> Архив КД
                    </a>
                <?php endif; ?>
                <a href="tank_sz.php" class="dropdown-item <?= $currentFile == 'tank_sz.php' ? 'active' : '' ?>">
    <span class="nav-icon">📋</span> Служебные записки
</a>
<a href="tank_materials_base.php" class="dropdown-item <?= $currentFile == 'tank_materials_base.php' ? 'active' : '' ?>">
    <span class="nav-icon">📚</span> Справочник материалов
</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- ================= СЛУЖЕБНЫЕ (раскрывающийся) ================= -->
        <div class="nav-group <?= in_array($currentFile, $servicePages) ? 'open' : '' ?>">
            <a href="#" class="nav-link has-dropdown" data-toggle="dropdown">
                <span class="nav-icon">⚙️</span>
                <span class="nav-text">Служебные</span>
                <span class="dropdown-arrow">▾</span>
            </a>
            <div class="dropdown-menu">
                <a href="bug_reports.php" class="dropdown-item <?= $currentFile == 'bug_reports.php' ? 'active' : '' ?>">
                    <span class="nav-icon">🐞</span> Журнал багов
                </a>
                <?php if ($userRole === 'admin' || $userRole === 'semi-admin'): ?>
                    <a href="activity_logs.php" class="dropdown-item <?= $currentFile == 'activity_logs.php' ? 'active' : '' ?>">
                        <span class="nav-icon">📜</span> Журнал логов
                    </a>
                <?php endif; ?>
                <a href="help.php" class="dropdown-item <?= $currentFile == 'help.php' ? 'active' : '' ?>">
                    <span class="nav-icon">📖</span> Инструкция
                </a>
                <a href="logout.php" class="dropdown-item logout">
                    <span class="nav-icon">🚪</span> Выход из системы
                </a>
            </div>
        </div>

        <!-- ================= УВЕДОМЛЕНИЯ (выпадающий внизу) ================= -->
        <?php if ($userRole !== 'executor' && $notificationsCount > 0): ?>
        <div class="nav-group notification-group <?= (in_array($currentFile, ['index.php']) && isset($_GET['client_id'])) ? 'open' : '' ?>">
            <a href="#" class="nav-link has-dropdown" data-toggle="dropdown">
                <span class="nav-icon">🔔</span>
                <span class="nav-text">Уведомления</span>
                <span class="notification-badge"><?= $notificationsCount ?></span>
                <span class="dropdown-arrow">▾</span>
            </a>
            <div class="dropdown-menu notification-list">
                <?php foreach ($notifications as $n): 
                    $contactDate = date('d.m.Y', strtotime($n['next_contact_date']));
                    $overdueClass = (strtotime($n['next_contact_date']) < strtotime(date('Y-m-d'))) ? 'overdue' : '';
                ?>
                    <a href="index.php?client_id=<?= (int)$n['id'] ?>" 
                       class="dropdown-item notification-item <?= $overdueClass ?>"
                       title="Открыть карточку клиента">
                        <span class="nav-icon">📞</span>
                        <span class="notification-text">
                            <span class="notification-client"><?= htmlspecialchars($n['client_name']) ?></span>
                            <span class="notification-date"><?= $contactDate ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </nav>
</aside>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const dropdownToggles = document.querySelectorAll('.nav-link.has-dropdown');

    dropdownToggles.forEach(toggle => {
        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            const parent = this.closest('.nav-group');
            const isOpen = parent.classList.contains('open');

            // Закрываем все открытые группы (кроме текущей)
            document.querySelectorAll('.nav-group.open').forEach(group => {
                if (group !== parent) {
                    group.classList.remove('open');
                    group.classList.remove('closing');
                }
            });

            if (isOpen) {
                parent.classList.add('closing');
                setTimeout(() => {
                    parent.classList.remove('open');
                    parent.classList.remove('closing');
                }, 200);
            } else {
                parent.classList.remove('closing');
                parent.classList.add('open');
            }
        });
    });
});
</script>

<style>
/* ============================================================
   САЙДБАР
   ============================================================ */
.sidebar {
    width: 200px;
    background: #1e1e2d;
    border-right: 1px solid #323248;
    display: flex;
    flex-direction: column;
    align-self: flex-start;
    position: sticky;
    top: 0;
    height: fit-content;
    max-height: 100vh;
    overflow-y: auto;
    padding: 12px 0;
    box-sizing: border-box;
    transition: width 0.3s ease;
}
.sidebar::-webkit-scrollbar { width: 6px; }
.sidebar::-webkit-scrollbar-thumb { background: #323248; border-radius: 6px; }
.sidebar::-webkit-scrollbar-thumb:hover { background: #4f46e5; }

.sidebar-logo {
    font-size: 16px;
    font-weight: 700;
    color: #fff;
    padding: 0 16px 10px;
    letter-spacing: 0.3px;
    border-bottom: 1px solid #2a2a3a;
    margin-bottom: 8px;
    flex-shrink: 0;
}

.sidebar-nav {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex: 1;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    color: #92929f;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    border-left: 2px solid transparent;
    transition: all 0.2s ease;
    cursor: pointer;
    background: none;
    border-right: none;
    border-top: none;
    border-bottom: none;
    width: 100%;
    box-sizing: border-box;
    text-align: left;
}

.nav-link:hover { background: #2a2a3a; color: #fff; }
.nav-link.active { color: #fff; background: rgba(79, 70, 229, 0.12); border-left-color: #4f46e5; }

.nav-icon {
    font-size: 16px;
    width: 20px;
    text-align: center;
    flex-shrink: 0;
}

.nav-text {
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-size: 13px;
}

.dropdown-arrow {
    font-size: 10px;
    transition: transform 0.2s ease;
}

.nav-group.open .dropdown-arrow { transform: rotate(180deg); }

.dropdown-menu {
    display: none;
    flex-direction: column;
    background: #151521;
    border-radius: 0;
    overflow: hidden;
    border-bottom: 1px solid #26263a;
}

.nav-group.open .dropdown-menu { display: flex; }

.dropdown-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 16px 7px 28px;
    color: #92929f;
    text-decoration: none;
    font-size: 12.5px;
    transition: all 0.2s ease;
    border-left: 2px solid transparent;
    box-sizing: border-box;
}

.dropdown-item:hover { background: #2a2a3a; color: #fff; }
.dropdown-item.active { color: #fff; background: rgba(79, 70, 229, 0.15); border-left-color: #4f46e5; }
.dropdown-item .nav-icon { font-size: 14px; }

.dropdown-item.logout { color: #ef4444; }
.dropdown-item.logout:hover { background: rgba(239, 68, 68, 0.1); color: #ef4444; }

/* Анимация появления */
.nav-group.open .dropdown-menu .dropdown-item {
    animation: dropdownItemIn 0.25s ease forwards;
    opacity: 0;
    transform: translateY(-6px);
}
.nav-group.open .dropdown-menu .dropdown-item:nth-child(1) { animation-delay: 0.02s; }
.nav-group.open .dropdown-menu .dropdown-item:nth-child(2) { animation-delay: 0.04s; }
.nav-group.open .dropdown-menu .dropdown-item:nth-child(3) { animation-delay: 0.06s; }
.nav-group.open .dropdown-menu .dropdown-item:nth-child(4) { animation-delay: 0.08s; }
.nav-group.open .dropdown-menu .dropdown-item:nth-child(5) { animation-delay: 0.10s; }
.nav-group.open .dropdown-menu .dropdown-item:nth-child(6) { animation-delay: 0.12s; }

.nav-group.closing .dropdown-menu .dropdown-item {
    animation: dropdownItemOut 0.15s ease forwards;
}
.nav-group.closing .dropdown-menu .dropdown-item:nth-child(1) { animation-delay: 0.00s; }
.nav-group.closing .dropdown-menu .dropdown-item:nth-child(2) { animation-delay: 0.02s; }
.nav-group.closing .dropdown-menu .dropdown-item:nth-child(3) { animation-delay: 0.04s; }
.nav-group.closing .dropdown-menu .dropdown-item:nth-child(4) { animation-delay: 0.06s; }
.nav-group.closing .dropdown-menu .dropdown-item:nth-child(5) { animation-delay: 0.08s; }
.nav-group.closing .dropdown-menu .dropdown-item:nth-child(6) { animation-delay: 0.10s; }

@keyframes dropdownItemOut {
    to { opacity: 0; transform: translateY(-6px); }
}
@keyframes dropdownItemIn {
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================
   УВЕДОМЛЕНИЯ
   ============================================================ */
.notification-group {
    margin-top: auto;
    border-top: 1px solid #2a2a3a;
    padding-top: 6px;
    position: relative;
    z-index: 10;
}

.notification-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #ef4444;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    border-radius: 10px;
    padding: 1px 6px;
    min-width: 18px;
    height: 18px;
    box-sizing: border-box;
    margin-left: auto;
    margin-right: 6px;
    line-height: 1;
}

.notification-list {
    display: flex;
    flex-direction: column;
    max-height: 420px;
    overflow-y: auto;
    overscroll-behavior: contain;
    padding: 4px 0;
    margin: 0;
    border-bottom: none;
    background: #151521;
    scrollbar-width: thin;
    scrollbar-color: #323248 transparent;
    box-sizing: border-box;
}

.notification-list::-webkit-scrollbar { width: 6px; }
.notification-list::-webkit-scrollbar-track { background: transparent; }
.notification-list::-webkit-scrollbar-thumb { background: #323248; border-radius: 6px; }
.notification-list::-webkit-scrollbar-thumb:hover { background: #4f46e5; }

.notification-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px 8px 16px;
    border-left: 2px solid transparent;
    color: #92929f;
    text-decoration: none;
    font-size: 12px;
    transition: all 0.15s ease;
    box-sizing: border-box;
    min-width: 0;
    width: 100%;
    line-height: 1.3;
}

.notification-item:hover { background: #2a2a3a; color: #fff; border-left-color: #f59e0b; }
.notification-item.overdue { background: rgba(239, 68, 68, 0.08); }
.notification-item.overdue:hover { background: rgba(239, 68, 68, 0.15); }

.notification-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
    flex: 1;
    overflow: hidden;
}

.notification-client {
    font-size: 12px;
    font-weight: 600;
    color: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: block;
    line-height: 1.2;
}

.notification-date {
    font-size: 10px;
    color: #f59e0b;
    font-family: monospace;
    white-space: nowrap;
    line-height: 1.2;
}

.notification-item.overdue .notification-date { color: #ef4444; }

/* ============================================================
   АДАПТИВНОСТЬ
   ============================================================ */
@media (max-width: 768px) {
    .sidebar { width: 52px; padding: 12px 0; }
    .sidebar-logo,
    .nav-text,
    .dropdown-arrow { display: none; }
    .nav-link,
    .dropdown-item { justify-content: center; padding: 8px 0; }
    .dropdown-item .nav-icon { margin-right: 0; }
    .dropdown-menu { padding-left: 0; }
}
</style>