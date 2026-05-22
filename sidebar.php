<?php
// sidebar.php — Единый навигационный модуль Santeks CRM
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Защита: если пользователь потерял сессию, меню не рендерится
if (isset($_SESSION['user_id'])):
    $menuRole = $_SESSION['role'] ?? 'manager';
?>
<!-- ИДЕАЛЬНОЕ ВЕРТИКАЛЬНОЕ МЕНЮ СИСТЕМЫ -->
<div class="crm-sidebar-menu" style="display: flex; flex-direction: column; gap: 8px; width: 100%; max-width: 260px; background: #1e1e2d; padding: 15px; border-radius: 12px; border: 1px solid #323248; box-sizing: border-box; margin-bottom: 20px;">
    
    <!-- Главная страница (База клиентов) -->
    <a href="index.php" style="display: flex; align-items: center; gap: 10px; height: 42px; padding: 0 15px; background: #3b82f6; color: #fff; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: bold; box-sizing: border-box; transition: all 0.15s;">
        🏢 <span style="white-space: nowrap;">База клиентов (Главная)</span>
    </a>

    <!-- Кнопка: Контракты (Доступна всем) -->
    <a href="contracts.php" style="display: flex; align-items: center; gap: 10px; height: 42px; padding: 0 15px; background: #4f46e5; color: #fff; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: bold; box-sizing: border-box; transition: all 0.15s;">
        📦 <span style="white-space: nowrap;">Раздел контрактов (ТТН)</span>
    </a>

    <!-- Кнопка: Сводный отчёт (Только для Админа) -->
    <?php if ($menuRole === 'admin'): ?>
        <a href="report.php" style="display: flex; align-items: center; gap: 10px; height: 42px; padding: 0 15px; background: #a855f7; color: #fff; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: bold; box-sizing: border-box; transition: all 0.15s;">
            📊 <span style="white-space: nowrap;">Сводный отчёт (Матрица)</span>
        </a>
    <?php endif; ?>

    <!-- Кнопка: Общий справочник (Доступна всем) -->
    <a href="directory.php" style="display: flex; align-items: center; gap: 10px; height: 42px; padding: 0 15px; background: #0284c7; color: #fff; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: bold; box-sizing: border-box; transition: all 0.15s;">
        🔍 <span style="white-space: nowrap;">Общий справочник базы</span>
    </a>

    <!-- Кнопка: Логи действий (Только для Админа) -->
    <?php if ($menuRole === 'admin'): ?>
        <a href="activity_logs.php" style="display: flex; align-items: center; gap: 10px; height: 42px; padding: 0 15px; background: #b91c1c; color: #fff; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: bold; box-sizing: border-box; transition: all 0.15s;">
            📋 <span style="white-space: nowrap;">Журнал аудита (Логи)</span>
        </a>
    <?php endif; ?>

    <!-- Кнопка: Поручения и Задачи (Доступна всем) -->
    <a href="tasks.php" style="display: flex; align-items: center; gap: 10px; height: 42px; padding: 0 15px; background: #e11d48; color: #fff; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: bold; box-sizing: border-box; transition: all 0.15s;">
        📆 <span style="white-space: nowrap;">Поручения и Задачи</span>
    </a>

    <!-- Разделительная черта перед выходом -->
    <div style="height: 1px; background: #323248; margin: 5px 0; width: 100%;"></div>

    <!-- Кнопка: Выйти -->
    <a href="logout.php" style="display: flex; align-items: center; gap: 10px; height: 42px; padding: 0 15px; background: #3f3f46; color: #fff; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: bold; box-sizing: border-box; transition: all 0.15s;"
       onmouseover="this.style.background='#52525b'" onmouseout="this.style.background='#3f3f46'">
        🚪 <span style="white-space: nowrap;">Выйти из системы</span>
    </a>
    <?php if ($menuRole === 'admin'): ?>
    <a href="register_user.php" style="display: flex; align-items: center; gap: 10px; height: 42px; padding: 0 15px; background: #ec4899; color: #fff; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: bold; box-sizing: border-box; transition: all 0.15s;">
        ➕ <span style="white-space: nowrap;">Добавить сотрудника</span>
    </a>
<?php endif; ?>
</div>

<style>
    .crm-sidebar-menu a {
        transition: transform 0.15s ease, filter 0.15s ease;
    }
    .crm-sidebar-menu a:hover {
        filter: brightness(1.15);
        transform: translateX(3px);
    }
</style>
<?php endif; ?>
