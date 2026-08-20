
<?php
// Выборка уведомлений
$remindList = [];
try {
    $remindSql = "SELECT id, client_name, next_contact_date 
                  FROM clients 
                  WHERE status != 'Отказ' 
                    AND is_contract_signed = 0
                    AND next_contact_date IS NOT NULL 
                    AND next_contact_date <= DATE_ADD(CURDATE(), INTERVAL 4 DAY)";
    if (isset($_SESSION['role']) && $_SESSION['role'] !== 'admin') {
        $remindSql .= " AND manager_id = ?";
        $remindStmt = $pdo->prepare($remindSql);
        $remindStmt->execute([$_SESSION['user_id']]);
    } else {
        $remindStmt = $pdo->query($remindSql);
    }
    $remindList = $remindStmt->fetchAll();
} catch (Exception $e) {
    $remindList = [];
}
$remindCount = count($remindList);
?>

<div class="reminder-widget" style="margin-top: 20px; border-top: 1px solid #323248; padding-top: 15px;">
    <div style="display: flex; align-items: center; gap: 10px; cursor: pointer;" onclick="toggleReminder()">
        <span style="font-size: 18px;">🔔</span>
        <span style="color: #92929f; font-size: 13px; font-weight: 600;">Уведомления</span>
        <?php if ($remindCount > 0): ?>
            <span style="background: #ef4444; color: #fff; font-size: 11px; padding: 0 8px; border-radius: 10px; font-weight: 700;"><?= $remindCount ?></span>
        <?php endif; ?>
    </div>
   <div id="sidebarReminderBox" style="display: <?= $remindCount > 0 ? 'block' : 'none' ?>; margin-top: 8px; max-height: 150px; overflow-y: auto; background: #151521; border-radius: 6px; padding: 4px 0;">
    <?php if ($remindCount > 0): ?>
        <?php foreach ($remindList as $item): ?>
            <div style="padding: 4px 8px; margin-bottom: 2px; background: #1e1e2d; border-radius: 4px; border-left: 3px solid <?= (strtotime($item['next_contact_date']) < strtotime(date('Y-m-d'))) ? '#f56565' : '#f6ad55' ?>;">
                <div style="font-size: 11px; font-weight: 600; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    <?= htmlspecialchars($item['client_name']) ?>
                </div>
                <div style="font-size: 10px; color: #92929f;">
                    <?= date('d.m.Y', strtotime($item['next_contact_date'])) ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div style="color: #4b4b5e; font-size: 11px; padding: 6px 8px; text-align: center;">Все контакты обработаны</div>
    <?php endif; ?>
</div>
</div>
<script>
function toggleReminder() {
    const box = document.getElementById('sidebarReminderBox');
    if (box) box.style.display = box.style.display === 'none' ? 'block' : 'none';
}
</script>