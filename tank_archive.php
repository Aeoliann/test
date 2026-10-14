<?php
/**
 * tank_archive.php — Управление архивом конструкторской документации
 * Доступ: admin, semi-admin, designer
 * 
 * @version 1.0
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'db.php';
require_once 'tank_helpers.php';
require_once 'tank_roles.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'manager';

if (!can('kd', 'archive_manage')) {
    die('Доступ только для конструкторов и администраторов');
}

// Загрузка списка архива
$archiveList = [];
try {
    $stmt = $pdo->query("
        SELECT ak.*, u.login AS uploaded_by_name,
               (SELECT COUNT(*) FROM tank_kd kd WHERE kd.archive_kd_id = ak.id) AS usage_count
        FROM tank_archive_kd ak
        LEFT JOIN users u ON ak.uploaded_by = u.id
        ORDER BY ak.uploaded_at DESC
    ");
    $archiveList = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Архив КД — Santeks CRM</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #0f0f1a; color: #fff; font-family: 'Segoe UI', Roboto, sans-serif; display: flex; min-height: 100vh; margin: 0; }
        main { flex: 1; padding: 25px 30px; min-width: 0; overflow-y: auto; display: flex; flex-direction: column; gap: 18px; max-height: 100vh; }

        .topbar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 24px; background: #1e1e2d;
            border: 1px solid #323248; border-radius: 12px;
            flex-wrap: wrap; gap: 12px;
        }
        .topbar h1 { margin: 0; font-size: 19px; font-weight: 700; display: flex; align-items: center; gap: 10px; }

        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 18px; border-radius: 8px; font-weight: 600;
            font-size: 13px; cursor: pointer; border: none;
            text-decoration: none; white-space: nowrap;
            transition: all 0.25s;
        }
        .btn-primary { background: #4f46e5; color: #fff; }
        .btn-primary:hover { background: #6366f1; }
        .btn-outline { background: transparent; border: 1px solid #323248; color: #92929f; }
        .btn-outline:hover { border-color: #4f46e5; color: #fff; }
        .btn-sm { padding: 5px 12px; font-size: 12px; }

        .table-wrapper {
            background: #1a1a28; border: 1px solid #323248;
            border-radius: 14px; overflow-x: auto;
        }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        thead th {
            background: #242438; color: #92929f;
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
            padding: 14px 12px; text-align: left;
            border-bottom: 2px solid #323248; white-space: nowrap;
        }
        tbody td { padding: 12px; border-bottom: 1px solid #26263a; color: #cbd5e1; vertical-align: middle; }
        tbody tr:hover td { background: #1e1e32; }

        .empty-state { text-align: center; padding: 60px 20px; color: #4b4b5e; font-size: 14px; }
        .empty-state .big-icon { font-size: 56px; margin-bottom: 12px; opacity: 0.3; }

        .modal-overlay {
            display: none; position: fixed; top: 0; left: 0;
            width: 100%; height: 100%; background: rgba(0,0,0,0.75);
            backdrop-filter: blur(4px); z-index: 99999;
            justify-content: center; align-items: center; padding: 20px;
        }
        .modal-overlay.active { display: flex; }
        .modal-content {
            background: #1e1e2d; border: 1px solid #323248;
            border-radius: 16px; padding: 28px;
            max-width: 560px; width: 100%; max-height: 90vh; overflow-y: auto;
            box-shadow: 0 25px 60px rgba(0,0,0,0.5);
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            border-bottom: 1px solid #323248; padding-bottom: 14px; margin-bottom: 20px;
        }
        .modal-header h2 { margin: 0; font-size: 19px; }
        .modal-close { background: none; border: none; color: #92929f; font-size: 26px; cursor: pointer; }
        .modal-close:hover { color: #ef4444; }
        .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
        .form-group label {
            font-size: 11px; font-weight: 700; color: #92929f;
            text-transform: uppercase; letter-spacing: 0.3px;
        }
        .form-group input { width: 100%; padding: 10px 14px; background: #151521; border: 1px solid #323248; border-radius: 8px; color: #fff; font-size: 13px; outline: none; }
        .form-group input:focus { border-color: #4f46e5; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 18px; border-top: 1px solid #323248; padding-top: 16px; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <main>
        <div class="topbar">
            <h1>📦 Архив конструкторской документации</h1>
            <button class="btn btn-primary" onclick="openArchiveModal()">➕ Добавить в архив</button>
        </div>

        <div class="table-wrapper">
            <?php if (empty($archiveList)): ?>
                <div class="empty-state">
                    <div class="big-icon">📦</div>
                    <div>В архиве пока нет документов</div>
                    <div style="margin-top:16px;">
                        <button class="btn btn-primary" onclick="openArchiveModal()">➕ Добавить первую КД</button>
                    </div>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th>Название</th>
                            <th>Тип резервуара</th>
                            <th>Файл</th>
                            <th style="width:130px;">Загружено</th>
                            <th style="width:140px;">Автор</th>
                            <th style="width:100px; text-align:center;">Использований</th>
                            <th style="width:120px;">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($archiveList as $a): ?>
                        <tr>
                            <td style="color:#6b6b85; font-family:monospace;"><?= $i++ ?></td>
                            <td style="color:#fff; font-weight:600;"><?= rvsEscape($a['name']) ?></td>
                            <td><?= $a['tank_type'] ? rvsEscape($a['tank_type']) : '<span style="color:#4b4b5e;">—</span>' ?></td>
                            <td style="font-size:12px; color:#92929f;"><?= rvsEscape($a['original_name'] ?? '—') ?></td>
                            <td style="font-size:12px; color:#92929f;"><?= rvsFormatDateTime($a['uploaded_at']) ?></td>
                            <td><?= rvsEscape($a['uploaded_by_name'] ?? '—') ?></td>
                            <td style="text-align:center;">
                                <span style="background:#26263a; padding:2px 10px; border-radius:10px; font-family:monospace; font-size:12px;">
                                    <?= (int)$a['usage_count'] ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?= rvsEscape($a['file_path']) ?>" target="_blank" class="btn btn-outline btn-sm" style="text-decoration:none;">📄</a>
                                <button class="btn btn-outline btn-sm" style="color:#ef4444; border-color:#ef444455;"
                                        onclick="deleteArchive(<?= (int)$a['id'] ?>, '<?= addslashes(rvsEscape($a['name'])) ?>', <?= (int)$a['usage_count'] ?>)">✖</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>

    <!-- МОДАЛКА ЗАГРУЗКИ В АРХИВ -->
    <div id="archiveModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h2>📦 Добавить КД в архив</h2>
                <button class="modal-close" onclick="closeArchiveModal()">&times;</button>
            </div>
            <form id="archiveForm" onsubmit="uploadArchive(event)">
                <input type="hidden" name="action" value="upload_archive">
                <div class="form-group">
                    <label>Название КД *</label>
                    <input type="text" name="name" required placeholder="РВС-550 (типовая конструкция)">
                </div>
                <div class="form-group">
                    <label>Тип резервуара (необязательно)</label>
                    <input type="text" name="tank_type" placeholder="РВС-550, РВС-1000 и т.д.">
                </div>
                <div class="form-group">
                    <label>Файл *</label>
                    <input type="file" name="file" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.zip" style="padding:8px; background:#151521; border:1px solid #323248; border-radius:8px; color:#cbd5e1;">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeArchiveModal()">Отмена</button>
                    <button type="submit" class="btn btn-primary" id="archiveBtn">💾 Загрузить</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openArchiveModal() {
            document.getElementById('archiveModal').classList.add('active');
            document.getElementById('archiveForm').reset();
        }
        function closeArchiveModal() {
            document.getElementById('archiveModal').classList.remove('active');
        }
        async function uploadArchive(e) {
            e.preventDefault();
            const fd = new FormData(e.target);
            const btn = document.getElementById('archiveBtn');
            btn.disabled = true;
            const old = btn.innerText;
            btn.innerText = '⏳ Загрузка...';
            try {
                const res = await fetch('tank_kd.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    closeArchiveModal();
                    location.reload();
                } else alert('Ошибка: ' + data.message);
            } catch (err) { alert('Ошибка сети'); }
            finally { btn.disabled = false; btn.innerText = old; }
        }
        async function deleteArchive(id, name, usageCount) {
            if (usageCount > 0) {
                alert('Нельзя удалить: КД используется в ' + usageCount + ' карточках. Сначала отвяжите её.');
                return;
            }
            if (!confirm('Удалить «' + name + '» из архива?')) return;
            const fd = new FormData();
            fd.append('action', 'delete_archive');
            fd.append('id', id);
            try {
                const res = await fetch('tank_kd.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') location.reload();
                else alert('Ошибка: ' + data.message);
            } catch (err) { alert('Ошибка сети'); }
        }
        document.getElementById('archiveModal').addEventListener('click', function(e) {
            if (e.target === this) closeArchiveModal();
        });
    </script>
</body>
</html>