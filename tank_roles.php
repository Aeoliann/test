<?php
/**
 * tank_roles.php — Матрица прав для системы РВС
 * 
 * Использование:
 *   require_once 'tank_roles.php';
 *   if (!can('sz', 'create')) { die('Нет прав'); }
 */

if (!function_exists('can')) {

    /**
     * Матрица прав: module => action => [roles]
     */
    function getRvsPermissions(): array {
        return [
            'card' => [
                'view'   => ['admin', 'semi-admin', 'manager', 'sales_manager', 'designer', 'tech_dept', 'warehouse', 'supply', 'production_chief', 'production_master', 'installation_chief', 'installation_master', 'director'],
                'create' => ['admin', 'semi-admin', 'manager', 'sales_manager'],
                'edit'   => ['admin', 'semi-admin', 'manager', 'sales_manager'],
                'delete' => ['admin'],
            ],
            'kd' => [
                'view'   => ['admin', 'semi-admin', 'manager', 'sales_manager', 'designer', 'tech_dept', 'production_chief', 'director'],
                'create' => ['admin', 'semi-admin', 'designer'],
                'edit'   => ['admin', 'semi-admin', 'designer'],
                'delete' => ['admin'],
                'archive_manage' => ['admin', 'semi-admin', 'designer'],
            ],
            'sz_materials' => [
                'view'   => ['admin', 'semi-admin', 'manager', 'tech_dept', 'warehouse', 'supply', 'director'],
                'create' => ['admin', 'semi-admin', 'tech_dept'],
                'edit'   => ['admin', 'semi-admin', 'tech_dept'],
                'export' => ['admin', 'semi-admin', 'tech_dept', 'director'],
                'warehouse_check' => ['admin', 'semi-admin', 'warehouse'],
                'supply_fill' => ['admin', 'semi-admin', 'supply'],
            ],
            'sz_production' => [
                'view'   => ['admin', 'semi-admin', 'manager', 'tech_dept', 'production_chief', 'production_master', 'director'],
                'create' => ['admin', 'semi-admin', 'tech_dept'],
                'edit'   => ['admin', 'semi-admin', 'tech_dept'],
            ],
            'materials_table' => [
                'view'   => ['admin', 'semi-admin', 'manager', 'tech_dept', 'warehouse', 'supply', 'director'],
                'edit_price' => ['admin', 'semi-admin', 'supply'],
            ],
            'production' => [
                'view'   => ['admin', 'semi-admin', 'manager', 'tech_dept', 'production_chief', 'production_master', 'director'],
                'edit'   => ['admin', 'semi-admin', 'production_chief', 'production_master'],
                'hours'  => ['admin', 'semi-admin', 'production_chief', 'production_master'],
            ],
            'installation' => [
                'view'   => ['admin', 'semi-admin', 'manager', 'installation_chief', 'installation_master', 'director'],
                'edit'   => ['admin', 'semi-admin', 'installation_chief', 'installation_master'],
                'hours'  => ['admin', 'semi-admin', 'installation_chief', 'installation_master'],
                'contractors' => ['admin', 'semi-admin', 'installation_chief'],
            ],
            'tasks' => [
                'view'   => ['admin', 'semi-admin', 'manager', 'production_chief', 'production_master', 'installation_chief', 'installation_master', 'director'],
                'create' => ['admin', 'semi-admin', 'production_chief', 'production_master', 'installation_chief', 'installation_master', 'director'],
            ],
            'calculator' => [
                'view'   => ['admin', 'semi-admin', 'manager', 'director'],
                'edit'   => ['admin', 'semi-admin', 'director'],
            ],
            'analytics' => [
                'view'   => ['admin', 'semi-admin', 'manager', 'director'],
                'export' => ['admin', 'semi-admin', 'manager', 'director'],
            ],
            'settings' => [
                'view'   => ['admin', 'semi-admin', 'director'],
                'edit'   => ['admin', 'director'],
            ],
        ];
    }

    /**
     * Проверка прав: can('sz_materials', 'create')
     */
    function can(string $module, string $action): bool {
        $role = $_SESSION['role'] ?? 'guest';
        $matrix = getRvsPermissions();
        if (!isset($matrix[$module][$action])) return false;
        return in_array($role, $matrix[$module][$action], true);
    }

    /**
     * Проверка прав с выбросом ошибки (для API)
     */
    function requirePermission(string $module, string $action): void {
        if (!can($module, $action)) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Недостаточно прав']);
            exit;
        }
    }

    /**
     * Вывод кнопок в интерфейсе: if (role_in(['admin','supply'])) ...
     */
    function role_in(array $roles): bool {
        return in_array($_SESSION['role'] ?? '', $roles, true);
    }

    /**
     * Записать событие в журнал (для аналитики T1–T8)
     */
    function logTankEvent(PDO $pdo, int $tankCardId, string $eventType, ?int $userId = null, ?string $meta = null): void {
        try {
            $stmt = $pdo->prepare("INSERT INTO tank_events (tank_card_id, event_type, event_at, user_id, meta) VALUES (?, ?, NOW(), ?, ?)");
            $stmt->execute([$tankCardId, $eventType, $userId ?? ($_SESSION['user_id'] ?? null), $meta]);
        } catch (Exception $e) {
            // Тихо логируем, не рушим основной поток
            error_log('logTankEvent error: ' . $e->getMessage());
        }
    }
}