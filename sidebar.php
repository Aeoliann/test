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

    <!-- Кнопка: Логи действий (Только для Admin) -->
    <?php if ($menuRole === 'admin'): ?>
        <a href="activity_logs.php" style="display: flex; align-items: center; gap: 10px; height: 42px; padding: 0 15px; background: #b91c1c; color: #fff; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: bold; box-sizing: border-box; transition: all 0.15s;">
            📋 <span style="white-space: nowrap;">Журнал аудита (Логи)</span>
        </a>
    <?php endif; ?>

    <!-- Кнопка: Журнал предложений / Рассылка обновлений (Только для Admin) -->
    <?php if ($menuRole === 'admin'): ?>
        <a href="suggestions.php" style="display: flex; align-items: center; gap: 10px; height: 42px; padding: 0 15px; background: #10b981; color: #fff; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: bold; box-sizing: border-box; transition: all 0.15s;">
            💡 <span style="white-space: nowrap;">Журнал предложений</span>
        </a>
    <?php endif; ?>

    <!-- Кнопка: Поручения и Задачи (Доступна всем) -->
    <a href="tasks.php" style="display: flex; align-items: center; gap: 10px; height: 42px; padding: 0 15px; background: #e11d48; color: #fff; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: bold; box-sizing: border-box; transition: all 0.15s;">
        📆 <span style="white-space: nowrap;">Поручения и Задачи</span>
    </a>
  
    <!-- Разделительная черта перед административными утилитами -->
    <div style="height: 1px; background: #323248; margin: 5px 0; width: 100%;"></div>

    <!-- Интеграция журнала багов в боковое меню системы -->
    <a href="bug_reports.php" style="display: flex; align-items: center; gap: 12px; padding: 12px 20px; color: #92929f; text-decoration: none; font-size: 14px; font-weight: bold; border-radius: 6px; margin-bottom: 5px; transition: 0.15s;" onmouseover="this.style.color='#fff'; this.style.background='#1a1a24';" onmouseout="this.style.color='#92929f'; this.style.background='none';">
        <span>🪲 Журнал багов</span>
    </a>

    <!-- Кнопка: Добавить сотрудника (Только для Админа) -->
    <?php if ($menuRole === 'admin'): ?>
        <a href="register_user.php" style="display: flex; align-items: center; gap: 10px; height: 42px; padding: 0 15px; background: #ec4899; color: #fff; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: bold; box-sizing: border-box; transition: all 0.15s; margin-bottom: 5px;">
            ➕ <span style="white-space: nowrap;">Добавить сотрудника</span>
        </a>
    <?php endif; ?>

    <!-- Кнопка: Выйти -->
    <a href="logout.php" style="display: flex; align-items: center; gap: 10px; height: 42px; padding: 0 15px; background: #3f3f46; color: #fff; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: bold; box-sizing: border-box; transition: all 0.15s;"
       onmouseover="this.style.background='#52525b'" onmouseout="this.style.background='#3f3f46'">
        🚪 <span style="white-space: nowrap;">Выйти из системы</span>
    </a>
</div>

<!-- ========================================== -->
<!-- МОДУЛЬ СИСТЕМНЫХ УВЕДОМЛЕНИЙ ОБ ОБНОВЛЕНИЯХ -->
<!-- ========================================== -->
<?php
// Автоматически проверяем наличие свежих системных апдейтов
try {
    $updateStmt = $pdo->query("SELECT * FROM system_updates ORDER BY id DESC LIMIT 1");
    $lastUpdate = $updateStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $lastUpdate = null;
}

if ($lastUpdate): 
?>
<script>
(function() {
    // Функция отправки сигнала "Я онлайн"
    async function sendPing() {
        try {
            // Тихо вызываем ping.php в фоновом режиме
            await fetch('ping.php', { method: 'POST', cache: 'no-store' });
        } catch (e) {
            // Если интернет на секунду пропал — не ломаем интерфейс, просто пропустим тик
            console.log('Сбой пинга присутствия');
        }
    }

    // Запускаем первый пинг сразу при загрузке любой страницы CRM
    sendPing();

    // Затем каждые 60 секунд (60000 миллисекунд) повторяем отправку сигнала
    setInterval(sendPing, 60000);
})();
</script>
<div id="systemUpdateToast" style="display: none; position: fixed; bottom: 20px; right: 20px; width: 320px; background: #1e1e2d; border: 1px solid #4f46e5; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.6); z-index: 100000; box-sizing: border-box; animation: slideInUpdate 0.4s ease-out;">
    <!-- Шапка сообщения -->
    <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 15px; border-bottom: 1px solid #323248; background: rgba(79, 70, 229, 0.1); border-top-left-radius: 11px; border-top-right-radius: 11px;">
        <span style="font-size: 12px; font-weight: bold; color: #818cf8; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 6px;">
            🚀 Обновление системы
        </span>
        <button onclick="closeUpdateToast()" style="background: none; border: none; color: #92929f; cursor: pointer; font-size: 16px; padding: 0; line-height: 1;">&times;</button>
    </div>
    <!-- Тело сообщения -->
    <div style="padding: 15px; box-sizing: border-box;">
        <h4 style="margin: 0 0 8px 0; color: #fff; font-size: 14px; font-weight: bold;"><?= htmlspecialchars($lastUpdate['title']) ?></h4>
        <p style="margin: 0; color: #cbd5e1; font-size: 12px; line-height: 1.5; white-space: pre-line;"><?= htmlspecialchars($lastUpdate['text']) ?></p>
    </div>
</div>

<style>
@keyframes slideInUpdate {
    from { transform: translateY(100px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
</style>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const updateId = "<?= $lastUpdate['id'] ?>";
    const toast = document.getElementById('systemUpdateToast');
    
    // Проверяем в браузере менеджера, закрывал ли он уже именно этот апдейт
    if (localStorage.getItem('crm_last_seen_update') !== updateId) {
        if (toast) {
            toast.style.display = 'block';
            // Автоматическое скрытие через 15 секунд для эргономики
            setTimeout(() => { closeUpdateToast(); }, 15000);
        }
    }
});

function closeUpdateToast() {
    const toast = document.getElementById('systemUpdateToast');
    if (toast) {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s ease';
        setTimeout(() => { toast.style.display = 'none'; }, 300);
    }
    localStorage.setItem('crm_last_seen_update', "<?= $lastUpdate['id'] ?>");
}
</script>
<?php endif; ?>

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