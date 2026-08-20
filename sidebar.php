<?php
// Определяем роль пользователя, если она не передана
if (!isset($userRole) && isset($_SESSION['role'])) {
    $userRole = $_SESSION['role'];
}
// Текущий файл для подсветки активного пункта
$currentFile = basename($_SERVER['PHP_SELF']);

// Страницы, при которых группа CRM должна быть открыта по умолчанию
$crmPages = ['index.php', 'contracts.php', 'directory.php', 'top_clients.php'];
if ($userRole === 'admin') {
    $crmPages[] = 'report.php';
}

// Страницы, при которых группа "Служебные" должна быть открыта
$servicePages = ['bug_reports.php', 'help.php'];
if ($userRole === 'admin') {
    $servicePages[] = 'activity_logs.php';
}
$servicePages[] = 'logout.php'; // на logout тоже можно подсветить группу
?>
<aside class="sidebar">
    <div class="sidebar-logo">Santeks CRM</div>
    
    <nav class="sidebar-nav">
        <?php if ($userRole !== 'executor'): ?>
        <!-- CRM с выпадающим списком -->
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
                <?php if ($userRole === 'admin'): ?>
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

        <!-- Задачи (отдельный пункт) -->
        <a href="tasks.php" class="nav-link <?= $currentFile == 'tasks.php' ? 'active' : '' ?>">
            <span class="nav-icon">📋</span>
            <span class="nav-text">Задачи</span>
        </a>

        <!-- Служебные с выпадающим списком -->
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
                <?php if ($userRole === 'admin'): ?>
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
                // Анимация закрытия
                parent.classList.add('closing');
                setTimeout(() => {
                    parent.classList.remove('open');
                    parent.classList.remove('closing');
                }, 200);
            } else {
                // Открываем меню
                parent.classList.remove('closing');
                parent.classList.add('open');
            }
        });
    });
});
</script>

<style>
/* ============================================================
   ОБНОВЛЁННЫЙ САЙДБАР (компактный и монолитный)
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
    padding: 12px 0;
    box-sizing: border-box;
    transition: width 0.3s ease;
}

.sidebar-logo {
    font-size: 16px;
    font-weight: 700;
    color: #fff;
    padding: 0 16px 10px;
    letter-spacing: 0.3px;
    border-bottom: 1px solid #2a2a3a;
    margin-bottom: 8px;
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

.nav-link:hover {
    background: #2a2a3a;
    color: #fff;
}

.nav-link.active {
    color: #fff;
    background: rgba(79, 70, 229, 0.12);
    border-left-color: #4f46e5;
}

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

.nav-group.open .dropdown-arrow {
    transform: rotate(180deg);
}

.dropdown-menu {
    display: none;
    flex-direction: column;
    background: #151521;
    border-radius: 0;
    overflow: hidden;
    border-bottom: 1px solid #26263a;
}

.nav-group.open .dropdown-menu {
    display: flex;
}

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

.dropdown-item:hover {
    background: #2a2a3a;
    color: #fff;
}

.dropdown-item.active {
    color: #fff;
    background: rgba(79, 70, 229, 0.15);
    border-left-color: #4f46e5;
}

.dropdown-item .nav-icon {
    font-size: 14px;
}

.dropdown-item.logout {
    color: #ef4444;
}

.dropdown-item.logout:hover {
    background: rgba(239, 68, 68, 0.1);
    color: #ef4444;
}

/* Анимация появления пунктов выпадающего меню */
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

/* Анимация закрытия пунктов выпадающего меню */
.nav-group.closing .dropdown-menu .dropdown-item {
    animation: dropdownItemOut 0.15s ease forwards;
}
.nav-group.closing .dropdown-menu .dropdown-item:nth-child(1) { animation-delay: 0.00s; }
.nav-group.closing .dropdown-menu .dropdown-item:nth-child(2) { animation-delay: 0.02s; }
.nav-group.closing .dropdown-menu .dropdown-item:nth-child(3) { animation-delay: 0.04s; }
.nav-group.closing .dropdown-menu .dropdown-item:nth-child(4) { animation-delay: 0.06s; }
.nav-group.closing .dropdown-menu .dropdown-item:nth-child(5) { animation-delay: 0.08s; }

@keyframes dropdownItemOut {
    to {
        opacity: 0;
        transform: translateY(-6px);
    }
}

@keyframes dropdownItemIn {
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Адаптивность: на узких экранах сайдбар сворачивается в иконки */
@media (max-width: 768px) {
    .sidebar {
        width: 52px;
        padding: 12px 0;
    }
    .sidebar-logo,
    .nav-text,
    .dropdown-arrow {
        display: none;
    }
    .nav-link,
    .dropdown-item {
        justify-content: center;
        padding: 8px 0;
    }
    .dropdown-item .nav-icon {
        margin-right: 0;
    }
    .dropdown-menu {
        padding-left: 0;
    }
}