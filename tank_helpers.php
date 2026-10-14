<?php
/**
 * tank_helpers.php — Общие функции системы РВС
 * 
 * Использование: require_once 'tank_helpers.php';
 */

// =========================================================================
// ЭТАПЫ ЖИЗНЕННОГО ЦИКЛА РЕЗЕРВУАРА
// =========================================================================
if (!function_exists('rvsStages')) {
    function rvsStages(): array {
        return [
            'card_created'         => ['icon' => '📝', 'title' => 'Карточка создана',    'color' => '#818cf8'],
            'kd_loaded'            => ['icon' => '📐', 'title' => 'КД загружена',       'color' => '#a78bfa'],
            'sz_created'           => ['icon' => '📋', 'title' => 'СЗ сформирована',    'color' => '#c084fc'],
            'warehouse_checked'    => ['icon' => '🏪', 'title' => 'Склад проверен',     'color' => '#f59e0b'],
            'materials_purchased'  => ['icon' => '🛒', 'title' => 'Материалы закуплены','color' => '#f97316'],
            'in_production'        => ['icon' => '🏭', 'title' => 'В производстве',     'color' => '#6366f1'],
            'in_installation'      => ['icon' => '🔧', 'title' => 'На монтаже',         'color' => '#3b82f6'],
            'completed'            => ['icon' => '🏁', 'title' => 'Закончен проект',    'color' => '#10b981'],
        ];
    }
}

if (!function_exists('rvsStageIndex')) {
    function rvsStageIndex(string $status): int {
        $keys = array_keys(rvsStages());
        $idx = array_search($status, $keys, true);
        return $idx === false ? 0 : $idx;
    }
}

if (!function_exists('rvsProgressBar')) {
    /**
     * Рисует миниатюрную шкалу прогресса
     */
    function rvsProgressBar(string $status, bool $mini = true): string {
        $stages = rvsStages();
        $currentIdx = rvsStageIndex($status);
        $total = count($stages);
        $html = '<div class="rvs-progress' . ($mini ? ' rvs-progress-mini' : '') . '" title="' . htmlspecialchars($stages[$status]['title'] ?? '') . '">';
        $i = 0;
        foreach ($stages as $key => $stage) {
            $cls = $i <= $currentIdx ? 'done' : 'pending';
            if ($i === $currentIdx) $cls .= ' current';
            $html .= '<span class="rvs-step ' . $cls . '" style="--step-color:' . $stage['color'] . '"></span>';
            $i++;
        }
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('rvsStatusBadge')) {
    function rvsStatusBadge(string $status): string {
        $stages = rvsStages();
        $info = $stages[$status] ?? ['title' => $status, 'color' => '#6b6b85', 'icon' => '•'];
        return '<span class="rvs-status-badge" style="background: ' . $info['color'] . '22; color: ' . $info['color'] . '; border:1px solid ' . $info['color'] . '55;">'
            . $info['icon'] . ' ' . htmlspecialchars($info['title'])
            . '</span>';
    }
}

// =========================================================================
// ФОРМАТИРОВАНИЕ
// =========================================================================
if (!function_exists('rvsFormatDate')) {
    function rvsFormatDate($date): string {
        return !empty($date) ? date('d.m.Y', strtotime($date)) : '—';
    }
}

if (!function_exists('rvsFormatDateTime')) {
    function rvsFormatDateTime($date): string {
        return !empty($date) ? date('d.m.Y H:i', strtotime($date)) : '—';
    }
}

if (!function_exists('rvsFormatCost')) {
    function rvsFormatCost($amount, string $currency = 'BYN'): string {
        return number_format((float)$amount, 2, ',', ' ') . ' ' . $currency;
    }
}

// =========================================================================
// ФАЙЛЫ
// =========================================================================
if (!function_exists('rvsUploadFile')) {
    /**
     * Безопасная загрузка файла
     * Возвращает относительный путь или null при ошибке
     */
    function rvsUploadFile(array $file, string $subdir, string $prefix = 'file'): ?string {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return null;

        $allowedExt = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'zip', 'xls', 'xlsx', 'txt'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt)) return null;

        $uploadDir = __DIR__ . '/uploads/' . $subdir . '/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $newName = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $uploadDir . $newName;

        if (move_uploaded_file($file['tmp_name'], $dest)) {
            return 'uploads/' . $subdir . '/' . $newName;
        }
        return null;
    }
}

// =========================================================================
// ГЕНЕРАЦИЯ СЕЙФОВЫХ СТРОК
// =========================================================================
if (!function_exists('rvsEscape')) {
    function rvsEscape($text): string {
        return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    }
}

// =========================================================================
// СТАТУСЫ СЗ (для модуля служебных записок)
// =========================================================================
if (!function_exists('szStatuses')) {
    function szStatuses(): array {
        return [
            'created'           => ['title' => 'Создана',           'color' => '#818cf8'],
            'warehouse_check'   => ['title' => 'Проверка склада',   'color' => '#f59e0b'],
            'supply_pending'    => ['title' => 'В снабжении',       'color' => '#f97316'],
            'supplied'          => ['title' => 'Закуплено',         'color' => '#10b981'],
            'in_production'     => ['title' => 'В производстве',    'color' => '#6366f1'],
            'closed'            => ['title' => 'Закрыта',           'color' => '#6b6b85'],
        ];
    }
}