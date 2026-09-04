<?php
/**
 * Unified Permissions System
 * Granular permission control across all modules
 * 
 * This file provides permission checking functions that work with the
 * existing role_modules.permissions JSON field.
 * 
 * Backward Compatible: Does not modify existing require_role() or require_module_access()
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/module_access.php';

// Ensure we have access to $conn if needed
if (!isset($GLOBALS['conn']) && function_exists('get_db_connection')) {
    $GLOBALS['conn'] = get_db_connection();
}

/**
 * Detect module from permission key
 * @param string $category Permission category (e.g., "buildings", "invoices")
 * @return string|null Module name or null if cannot detect
 */
function detect_module_from_permission(string $category): ?string {
    // Real Estate categories
    $reCategories = ['buildings', 'units', 'tenants', 'leases', 'payments', 'billing', 
                     'collections', 'cheques', 'maintenance', 'vendors', 'move_in', 
                     'move_out', 'tasks', 'compliance', 'documents'];
    
    // Finance (Cleaning) categories
    $financeCategories = ['invoices', 'payments', 'expenses', 'accounts', 'journal', 
                          'reports', 'reconciliation', 'settings'];
    
    // HR categories
    $hrCategories = ['employees', 'attendance', 'leave', 'payroll', 'documents', 
                     'performance', 'recruitment'];
    
    // Cleaning categories
    $cleaningCategories = ['clients', 'orders', 'workorders', 'services', 'scheduling',
                          'invoicing', 'sm'];
    
    // Core categories
    $coreCategories = ['users', 'companies', 'settings', 'audit', 'roles'];

    // Inventory categories (shared)
    $inventoryCategories = [
        'inventory_items',
        'inventory_locations',
        'inventory_docs',
        'inventory_reports',
        'inventory_adjustments',
        'inventory_settings',
        'inventory_purchasing',
        'inventory_requests'
    ];

    $groceryCategories = ['grocery_pos', 'grocery_reports'];
    $barberCategories = ['barber_pos', 'barber_backoffice'];
    $constructionCategories = ['construction'];

    if (in_array($category, $constructionCategories, true)) {
        return defined('MODULE_CONSTRUCTION') ? MODULE_CONSTRUCTION : 'construction';
    }
    if (in_array($category, $reCategories, true)) {
        return MODULE_REALESTATE;
    }
    if (in_array($category, $financeCategories, true)) {
        return MODULE_FINANCE;
    }
    if (in_array($category, $hrCategories, true)) {
        return MODULE_HR;
    }
    if (in_array($category, $cleaningCategories, true)) {
        return MODULE_CLEANING;
    }
    if (in_array($category, $coreCategories, true)) {
        return MODULE_CORE;
    }
    if (in_array($category, $inventoryCategories, true)) {
        return defined('MODULE_INVENTORY') ? MODULE_INVENTORY : 'inventory';
    }
    if (in_array($category, $groceryCategories, true)) {
        return defined('MODULE_GROCERY') ? MODULE_GROCERY : 'grocery';
    }
    if (in_array($category, $barberCategories, true)) {
        return defined('MODULE_BARBER') ? MODULE_BARBER : 'barber';
    }

    return null;
}

/**
 * Check if a permission exists in JSON permissions structure
 * @param array $permissions JSON decoded permissions
 * @param string $permission_key Permission key (e.g., "buildings.view")
 * @return bool
 */
function check_permission_in_json(array $permissions, string $permission_key): bool {
    $parts = explode('.', $permission_key, 2);
    if (count($parts) !== 2) {
        return false;
    }
    
    [$category, $permission] = $parts;
    
    // Check if category exists and permission is in the array
    if (isset($permissions[$category]) && is_array($permissions[$category])) {
        return in_array($permission, $permissions[$category], true);
    }
    
    return false;
}

/**
 * Extract all permissions from JSON structure
 * @param array $permissions JSON decoded permissions
 * @return array Flat array of permission keys (e.g., ["buildings.view", "units.create"])
 */
function extract_permissions_from_json(array $permissions): array {
    $flat = [];
    foreach ($permissions as $category => $perms) {
        if (is_array($perms)) {
            foreach ($perms as $perm) {
                $flat[] = $category . '.' . $perm;
            }
        }
    }
    return $flat;
}

/**
 * Check if current user has a specific permission
 * @param string $permission_key Permission key (e.g., "buildings.view")
 * @param string|null $module Module name (optional, auto-detect if null)
 * @param PDO|null $conn Database connection (optional, uses global if available)
 * @return bool
 */
function has_permission(string $permission_key, ?string $module = null, ?PDO $conn = null): bool {
    // Get database connection
    if (!$conn) {
        $conn = $GLOBALS['conn'] ?? null;
        if (!$conn) {
            // Try to get connection from db_connect
            if (function_exists('get_db_connection')) {
                $conn = get_db_connection();
            } else {
                return false; // Cannot check without DB
            }
        }
    }
    
    $userId = current_user_id();
    if (!$userId) {
        return false;
    }
    
    // Owner and Admin have all permissions (backward compatible)
    $roles = current_user_roles($conn);
    if (in_array('Owner', $roles, true) || in_array('Admin', $roles, true)) {
        return true;
    }

    require_once __DIR__ . '/rbac_department.php';
    $permParts = explode('.', $permission_key, 2);
    if (count($permParts) === 2) {
        [$permCat, $permAct] = $permParts;
        // Department is the gate for barber / grocery: JSON alone must not grant POS or back office without the matching department.
        if ($permCat === 'barber_backoffice' && !has_barber_backoffice_department($conn)) {
            return false;
        }
        if ($permCat === 'barber_pos' && !has_barber_pos_department($conn)) {
            return false;
        }
        if ($permCat === 'grocery_reports' && !has_grocery_backoffice_department($conn)) {
            return false;
        }
        if ($permCat === 'grocery_pos' && !has_grocery_pos_department($conn)) {
            return false;
        }
        if ($permCat === 'barber_pos' && has_barber_pos_department($conn) && in_array($permAct, ['view', 'post'], true)) {
            return true;
        }
        if ($permCat === 'barber_backoffice' && has_barber_backoffice_department($conn)
            && in_array($permAct, ['view', 'manage_services', 'manage_team', 'export'], true)) {
            return true;
        }
        if ($permCat === 'grocery_pos' && has_grocery_pos_department($conn) && in_array($permAct, ['view', 'post'], true)) {
            return true;
        }
        if ($permCat === 'grocery_reports' && has_grocery_backoffice_department($conn)
            && in_array($permAct, ['view', 'export'], true)) {
            return true;
        }
    }
    
    // Auto-detect module from permission key if not provided
    if (!$module) {
        $parts = explode('.', $permission_key, 2);
        $module = detect_module_from_permission($parts[0]);
        if (!$module) {
            return false; // Cannot determine module
        }
    }
    
    // Get user's roles and their permissions for this module
    $stmt = $conn->prepare("
        SELECT rm.permissions
        FROM user_roles ur
        JOIN role_modules rm ON rm.role_id = ur.role_id
        WHERE ur.user_id = ? AND rm.module = ?
    ");
    $stmt->execute([$userId, $module]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check each role's permissions
    foreach ($rows as $row) {
        $permissionsJson = $row['permissions'] ?? '{}';
        $permissions = json_decode($permissionsJson, true);
        
        if (is_array($permissions) && check_permission_in_json($permissions, $permission_key)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Require permission (throws 403 if not granted)
 * @param string $permission_key Permission key
 * @param string|null $module Module name (optional)
 * @param PDO|null $conn Database connection (optional)
 * @return void
 */
function require_permission(string $permission_key, ?string $module = null, ?PDO $conn = null): void {
    if (!has_permission($permission_key, $module, $conn)) {
        http_response_code(403);
        echo '<div style="font-family:system-ui;padding:32px">
                <h3>403 – Forbidden</h3>
                <p>You don\'t have permission to access this resource.</p>
                <p><small>Required permission: ' . htmlspecialchars($permission_key) . '</small></p>
                <p><a href="javascript:history.back()">Go Back</a></p>
              </div>';
        exit;
    }
}

/**
 * Get all permissions for a user (across all roles and modules)
 * @param int $user_id User ID
 * @param string|null $module Module name (optional, returns all if null)
 * @param PDO|null $conn Database connection (optional)
 * @return array Array of permissions (flat list or grouped by module)
 */
function get_user_permissions(int $user_id, ?string $module = null, ?PDO $conn = null): array {
    // Get database connection
    if (!$conn) {
        $conn = $GLOBALS['conn'] ?? null;
        if (!$conn && function_exists('get_db_connection')) {
            $conn = get_db_connection();
        }
        if (!$conn) {
            return [];
        }
    }
    
    // Get user's roles and their permissions
    $sql = "
        SELECT rm.module, rm.permissions
        FROM user_roles ur
        JOIN role_modules rm ON rm.role_id = ur.role_id
        WHERE ur.user_id = ?
    ";
    $params = [$user_id];
    
    if ($module) {
        $sql .= " AND rm.module = ?";
        $params[] = $module;
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $allPermissions = [];
    foreach ($rows as $row) {
        $permissionsJson = $row['permissions'] ?? '{}';
        $permissions = json_decode($permissionsJson, true);
        $mod = $row['module'];
        
        if (!is_array($permissions)) {
            continue;
        }
        
        if (!isset($allPermissions[$mod])) {
            $allPermissions[$mod] = [];
        }
        
        $flat = extract_permissions_from_json($permissions);
        $allPermissions[$mod] = array_merge($allPermissions[$mod], $flat);
    }
    
    // Remove duplicates
    foreach ($allPermissions as $mod => $perms) {
        $allPermissions[$mod] = array_unique($perms);
    }
    
    return $module ? ($allPermissions[$module] ?? []) : $allPermissions;
}

/**
 * Get role permissions for a specific role and module
 * @param int $role_id Role ID
 * @param string $module Module name
 * @param PDO|null $conn Database connection (optional)
 * @return array Permission structure (category => [permissions])
 */
function get_role_permissions(int $role_id, string $module, ?PDO $conn = null): array {
    // Get database connection
    if (!$conn) {
        $conn = $GLOBALS['conn'] ?? null;
        if (!$conn && function_exists('get_db_connection')) {
            $conn = get_db_connection();
        }
        if (!$conn) {
            return [];
        }
    }
    
    $stmt = $conn->prepare("
        SELECT permissions
        FROM role_modules
        WHERE role_id = ? AND module = ?
    ");
    $stmt->execute([$role_id, $module]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row || !$row['permissions']) {
        return [];
    }
    
    $permissions = json_decode($row['permissions'], true);
    return is_array($permissions) ? $permissions : [];
}

/**
 * Set role permissions for a specific role and module
 * @param int $role_id Role ID
 * @param string $module Module name
 * @param array $permissions Permission structure (category => [permissions])
 * @param PDO|null $conn Database connection (optional)
 * @return bool Success
 */
/**
 * Keep role_modules JSON in sync with grocery / barber department checkboxes (System Settings → Departments).
 */
function sync_grocery_barber_role_modules_for_role(int $role_id, array $departmentsStructure, ?PDO $conn = null): void {
    if (!$conn) {
        $conn = $GLOBALS['conn'] ?? null;
        if (!$conn && function_exists('get_db_connection')) {
            $conn = get_db_connection();
        }
        if (!$conn) {
            return;
        }
    }
    require_once __DIR__ . '/rbac_department.php';

    if (isset($departmentsStructure[MODULE_GROCERY]) && is_array($departmentsStructure[MODULE_GROCERY])
        && count($departmentsStructure[MODULE_GROCERY]) > 0) {
        $g = $departmentsStructure[MODULE_GROCERY];
        $perms = [];
        if (in_array(DEPT_GROCERY_POS, $g, true)) {
            $perms['grocery_pos'] = ['view', 'post'];
        }
        if (in_array(DEPT_GROCERY_BACKOFFICE, $g, true)) {
            $perms['grocery_reports'] = ['view', 'export'];
        }
        if ($perms !== []) {
            set_role_permissions($role_id, MODULE_GROCERY, $perms, $conn);
        } else {
            $conn->prepare('DELETE FROM role_modules WHERE role_id = ? AND module = ?')->execute([$role_id, MODULE_GROCERY]);
        }
    } else {
        $conn->prepare('DELETE FROM role_modules WHERE role_id = ? AND module = ?')->execute([$role_id, MODULE_GROCERY]);
    }

    if (isset($departmentsStructure[MODULE_BARBER]) && is_array($departmentsStructure[MODULE_BARBER])
        && count($departmentsStructure[MODULE_BARBER]) > 0) {
        $b = $departmentsStructure[MODULE_BARBER];
        $perms = [];
        if (in_array(DEPT_BARBER_POS, $b, true)) {
            $perms['barber_pos'] = ['view', 'post'];
        }
        if (in_array(DEPT_BARBER_BACKOFFICE, $b, true)) {
            $perms['barber_backoffice'] = ['view', 'manage_services', 'manage_team', 'export'];
        }
        if ($perms !== []) {
            set_role_permissions($role_id, MODULE_BARBER, $perms, $conn);
        } else {
            $conn->prepare('DELETE FROM role_modules WHERE role_id = ? AND module = ?')->execute([$role_id, MODULE_BARBER]);
        }
    } else {
        $conn->prepare('DELETE FROM role_modules WHERE role_id = ? AND module = ?')->execute([$role_id, MODULE_BARBER]);
    }
}

function set_role_permissions(int $role_id, string $module, array $permissions, ?PDO $conn = null): bool {
    // Get database connection
    if (!$conn) {
        $conn = $GLOBALS['conn'] ?? null;
        if (!$conn && function_exists('get_db_connection')) {
            $conn = get_db_connection();
        }
        if (!$conn) {
            return false;
        }
    }
    
    try {
        $permissionsJson = json_encode($permissions, JSON_UNESCAPED_UNICODE);
        
        // Check if record exists
        $stmt = $conn->prepare("SELECT id FROM role_modules WHERE role_id = ? AND module = ?");
        $stmt->execute([$role_id, $module]);
        $exists = $stmt->fetch();
        
        if ($exists) {
            // Update existing
            $stmt = $conn->prepare("
                UPDATE role_modules 
                SET permissions = ? 
                WHERE role_id = ? AND module = ?
            ");
            $stmt->execute([$permissionsJson, $role_id, $module]);
        } else {
            // Insert new
            $stmt = $conn->prepare("
                INSERT INTO role_modules (role_id, module, permissions) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$role_id, $module, $permissionsJson]);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error setting role permissions: " . $e->getMessage());
        return false;
    }
}

/**
 * Whether a role has HR payroll validation override permission.
 */
function role_has_payroll_override_validation(int $role_id, ?PDO $conn = null): bool
{
    $module = defined('MODULE_HR') ? MODULE_HR : 'hr';
    $perms = get_role_permissions($role_id, $module, $conn);
    $payroll = $perms['payroll'] ?? [];
    return is_array($payroll) && in_array('override_validation', $payroll, true);
}

/**
 * Enable/disable payroll.override_validation on a role (Settings → Roles UI).
 * Merges into existing hr role_modules JSON; does not wipe other permissions.
 */
function set_role_payroll_override_validation(int $role_id, bool $enabled, ?PDO $conn = null): bool
{
    if (!$conn) {
        $conn = $GLOBALS['conn'] ?? null;
        if (!$conn && function_exists('get_db_connection')) {
            $conn = get_db_connection();
        }
        if (!$conn) {
            return false;
        }
    }

    $module = defined('MODULE_HR') ? MODULE_HR : 'hr';
    $perms = get_role_permissions($role_id, $module, $conn);
    $payroll = $perms['payroll'] ?? [];
    if (!is_array($payroll)) {
        $payroll = [];
    }

    if ($enabled) {
        if (!in_array('override_validation', $payroll, true)) {
            $payroll[] = 'override_validation';
        }
    } else {
        $payroll = array_values(array_filter(
            $payroll,
            static fn($p): bool => (string)$p !== 'override_validation'
        ));
    }

    if ($payroll) {
        $perms['payroll'] = $payroll;
    } else {
        unset($perms['payroll']);
    }

    if (!$perms) {
        try {
            $conn->prepare('DELETE FROM role_modules WHERE role_id = ? AND module = ?')
                ->execute([$role_id, $module]);
            return true;
        } catch (Exception $e) {
            error_log('Error clearing role payroll override permission: ' . $e->getMessage());
            return false;
        }
    }

    return set_role_permissions($role_id, $module, $perms, $conn);
}

/**
 * Get permission definitions for a module
 * Loads from permission definition files
 * @param string $module Module name
 * @return array Permission definitions
 */
function get_module_permission_definitions(string $module): array {
    $defFile = __DIR__ . '/permissions/' . $module . '_permissions.php';
    
    if (file_exists($defFile)) {
        return require $defFile;
    }
    
    return [];
}

/**
 * Check if user has any of the specified permissions
 * @param array $permission_keys Array of permission keys
 * @param string|null $module Module name (optional)
 * @param PDO|null $conn Database connection (optional)
 * @return bool
 */
function has_any_permission(array $permission_keys, ?string $module = null, ?PDO $conn = null): bool {
    foreach ($permission_keys as $key) {
        if (has_permission($key, $module, $conn)) {
            return true;
        }
    }
    return false;
}

/**
 * Check if user has all of the specified permissions
 * @param array $permission_keys Array of permission keys
 * @param string|null $module Module name (optional)
 * @param PDO|null $conn Database connection (optional)
 * @return bool
 */
function has_all_permissions(array $permission_keys, ?string $module = null, ?PDO $conn = null): bool {
    foreach ($permission_keys as $key) {
        if (!has_permission($key, $module, $conn)) {
            return false;
        }
    }
    return true;
}
