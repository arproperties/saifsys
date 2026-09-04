<?php
/**
 * Department-Based RBAC Helper Functions
 * Simple, clear permission system: Role → Module → Department → Access
 */

require_once __DIR__ . '/auth.php';

// Department constants
define('DEPT_CLEANING_OPERATIONS', 'cleaning_operations');
define('DEPT_CLEANING_ACCOUNTS', 'cleaning_accounts');
define('DEPT_REALESTATE_CORE', 'realestate_core');
define('DEPT_REALESTATE_FINANCIAL', 'realestate_financial');
define('DEPT_REALESTATE_MAINTENANCE', 'realestate_maintenance');
define('DEPT_REALESTATE_OPERATIONS', 'realestate_operations');
define('DEPT_REALESTATE_COMPLIANCE', 'realestate_compliance');
/** @deprecated Use DEPT_LEGAL under MODULE_LEGAL — kept for legacy role_departments rows */
define('DEPT_REALESTATE_LEGAL', 'realestate_legal');
define('DEPT_LEGAL', 'legal');
define('DEPT_CONSTRUCTION_CORE', 'construction_core');
define('DEPT_CONSTRUCTION_PROJECTS', 'construction_projects');
define('DEPT_CONSTRUCTION_FINANCIAL', 'construction_financial');
define('DEPT_CONSTRUCTION_REPORTS', 'construction_reports');
define('DEPT_ARS_CORE', 'ars_core');
define('DEPT_ARS_OPERATIONS', 'ars_operations');
define('DEPT_HR', 'hr'); // Shared
define('DEPT_INVENTORY', 'inventory'); // Shared
/** Grocery: retail floor vs back office / reports */
define('DEPT_GROCERY_POS', 'grocery_pos');
define('DEPT_GROCERY_BACKOFFICE', 'grocery_backoffice');
/** Barber: tablet POS vs admin / reports */
define('DEPT_BARBER_POS', 'barber_pos');
define('DEPT_BARBER_BACKOFFICE', 'barber_backoffice');

// Module constants (if not already defined)
if (!defined('MODULE_CLEANING')) {
    define('MODULE_CLEANING', 'cleaning');
}
if (!defined('MODULE_REALESTATE')) {
    define('MODULE_REALESTATE', 'realestate');
}
if (!defined('MODULE_CONSTRUCTION')) {
    define('MODULE_CONSTRUCTION', 'construction');
}
if (!defined('MODULE_ARS')) {
    define('MODULE_ARS', 'ars');
}
if (!defined('MODULE_INVENTORY')) {
    define('MODULE_INVENTORY', 'inventory');
}
if (!defined('MODULE_GROCERY')) {
    define('MODULE_GROCERY', 'grocery');
}
if (!defined('MODULE_BARBER')) {
    define('MODULE_BARBER', 'barber');
}
if (!defined('MODULE_LEGAL')) {
    define('MODULE_LEGAL', 'legal');
}

/**
 * Check if current user has access to a department in a module
 * @param string $module Module name (cleaning, realestate)
 * @param string $department Department code
 * @param PDO|null $conn Database connection
 * @return bool
 */
function has_department_access(string $module, string $department, ?PDO $conn = null): bool {
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
    
    $userId = current_user_id();
    if (!$userId) {
        return false;
    }
    
    // Owner and Admin have all access
    $roles = current_user_roles($conn);
    if (in_array('Owner', $roles, true) || in_array('Admin', $roles, true)) {
        return true;
    }
    
    // Check if user's roles have access to this department
    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM user_roles ur
        JOIN role_departments rd ON rd.role_id = ur.role_id
        WHERE ur.user_id = ? 
        AND rd.module = ? 
        AND rd.department = ?
    ");
    $stmt->execute([$userId, $module, $department]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Require department access (throws 403 if not granted)
 * @param string $module Module name
 * @param string $department Department code
 * @param PDO|null $conn Database connection
 * @return void
 */
function require_department_access(string $module, string $department, ?PDO $conn = null): void {
    // Align session company for business-type modules before department gate / page queries
    if ($conn instanceof PDO && function_exists('module_requires_matching_company')
        && function_exists('ensure_current_company_supports_module')
        && module_requires_matching_company($module)) {
        ensure_current_company_supports_module($conn, $module);
    }
    if (!has_department_access($module, $department, $conn)) {
        http_response_code(403);
        echo '<div style="font-family:system-ui;padding:32px">
                <h3>403 – Forbidden</h3>
                <p>You don\'t have access to this department.</p>
                <p><small>Required: ' . htmlspecialchars($module) . ' → ' . htmlspecialchars($department) . '</small></p>
                <p><a href="javascript:history.back()">Go Back</a></p>
              </div>';
        exit;
    }
}

/**
 * Get all departments user has access to, grouped by module
 * @param int $userId User ID
 * @param PDO|null $conn Database connection
 * @return array ['module' => ['department1', 'department2'], ...]
 */
function get_user_departments(int $userId, ?PDO $conn = null): array {
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
    
    // Check if THIS user (not current logged-in user) is Owner or Admin
    $stmt = $conn->prepare("
        SELECT r.name
        FROM user_roles ur
        JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = ?
    ");
    $stmt->execute([$userId]);
    $user_roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Owner and Admin have all departments
    if (in_array('Owner', $user_roles, true) || in_array('Admin', $user_roles, true)) {
        return [
            MODULE_CLEANING => [DEPT_CLEANING_OPERATIONS, DEPT_CLEANING_ACCOUNTS, DEPT_HR],
            MODULE_REALESTATE => [
                DEPT_REALESTATE_CORE,
                DEPT_REALESTATE_FINANCIAL,
                DEPT_REALESTATE_MAINTENANCE,
                DEPT_REALESTATE_OPERATIONS,
                DEPT_REALESTATE_COMPLIANCE,
                DEPT_HR
            ],
            MODULE_LEGAL => [DEPT_LEGAL],
            MODULE_CONSTRUCTION => [
                DEPT_CONSTRUCTION_CORE,
                DEPT_CONSTRUCTION_PROJECTS,
                DEPT_CONSTRUCTION_FINANCIAL,
                DEPT_CONSTRUCTION_REPORTS,
                DEPT_HR
            ],
            MODULE_ARS => [
                DEPT_ARS_CORE,
                DEPT_ARS_OPERATIONS,
                DEPT_HR
            ],
            MODULE_INVENTORY => [DEPT_INVENTORY],
            MODULE_GROCERY => [DEPT_GROCERY_POS, DEPT_GROCERY_BACKOFFICE],
            MODULE_BARBER => [DEPT_BARBER_POS, DEPT_BARBER_BACKOFFICE]
        ];
    }
    
    // Get departments from role_departments
    $stmt = $conn->prepare("
        SELECT DISTINCT rd.module, rd.department
        FROM user_roles ur
        JOIN role_departments rd ON rd.role_id = ur.role_id
        WHERE ur.user_id = ?
        ORDER BY rd.module, rd.department
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $departments = [];
    foreach ($rows as $row) {
        $module = $row['module'];
        $dept = $row['department'];
        if (!isset($departments[$module])) {
            $departments[$module] = [];
        }
        if (!in_array($dept, $departments[$module], true)) {
            $departments[$module][] = $dept;
        }
    }
    
    return $departments;
}

/**
 * Get modules user has access to (based on departments)
 * @param PDO $conn Database connection
 * @param int $userId User ID
 * @return array Array of modules with department info
 */
function get_user_modules_v2(PDO $conn, int $userId): array {
    $departments = get_user_departments($userId, $conn);
    $modules = [];
    
    foreach ($departments as $module => $depts) {
        if (!empty($depts)) {
            $modules[] = [
                'module' => $module,
                'departments' => $depts,
                'roles' => [] // Can be populated if needed
            ];
        }
    }
    
    return $modules;
}

/**
 * Get department display name
 * @param string $department Department code
 * @return string Display name
 */
function get_department_display_name(string $department): string {
    $names = [
        DEPT_CLEANING_OPERATIONS => 'Operations',
        DEPT_CLEANING_ACCOUNTS => 'Accounts',
        DEPT_REALESTATE_CORE => 'Core Management',
        DEPT_REALESTATE_FINANCIAL => 'Financial',
        DEPT_REALESTATE_MAINTENANCE => 'Maintenance',
        DEPT_REALESTATE_OPERATIONS => 'Operations',
        DEPT_REALESTATE_COMPLIANCE => 'Compliance & Reports',
        DEPT_LEGAL => 'Legal Department',
        DEPT_CONSTRUCTION_CORE => 'Core',
        DEPT_CONSTRUCTION_PROJECTS => 'Projects',
        DEPT_CONSTRUCTION_FINANCIAL => 'Financial',
        DEPT_CONSTRUCTION_REPORTS => 'Reports',
        DEPT_ARS_CORE => 'Core Management',
        DEPT_ARS_OPERATIONS => 'Operations',
        DEPT_HR => 'HR',
        DEPT_INVENTORY => 'Inventory',
        DEPT_GROCERY_POS => 'Grocery — POS (retail)',
        DEPT_GROCERY_BACKOFFICE => 'Grocery — Back office',
        DEPT_BARBER_POS => 'Barber shop — POS',
        DEPT_BARBER_BACKOFFICE => 'Barber shop — Back office'
    ];
    return $names[$department] ?? ucfirst(str_replace('_', ' ', $department));
}

/**
 * Get all departments for a module
 * @param string $module Module name
 * @return array Array of department codes
 */
function get_module_departments(string $module): array {
    if ($module === MODULE_CLEANING) {
        return [DEPT_CLEANING_OPERATIONS, DEPT_CLEANING_ACCOUNTS, DEPT_HR];
    } elseif ($module === MODULE_REALESTATE) {
        return [
            DEPT_REALESTATE_CORE,
            DEPT_REALESTATE_FINANCIAL,
            DEPT_REALESTATE_MAINTENANCE,
            DEPT_REALESTATE_OPERATIONS,
            DEPT_REALESTATE_COMPLIANCE,
            DEPT_HR
        ];
    } elseif ($module === MODULE_LEGAL) {
        return [DEPT_LEGAL];
    } elseif ($module === MODULE_CONSTRUCTION) {
        return [
            DEPT_CONSTRUCTION_CORE,
            DEPT_CONSTRUCTION_PROJECTS,
            DEPT_CONSTRUCTION_FINANCIAL,
            DEPT_CONSTRUCTION_REPORTS,
            DEPT_HR
        ];
    } elseif ($module === MODULE_ARS) {
        return [
            DEPT_ARS_CORE,
            DEPT_ARS_OPERATIONS,
            DEPT_HR
        ];
    } elseif ($module === MODULE_INVENTORY) {
        return [DEPT_INVENTORY];
    } elseif ($module === MODULE_GROCERY) {
        return [DEPT_GROCERY_POS, DEPT_GROCERY_BACKOFFICE];
    } elseif ($module === MODULE_BARBER) {
        return [DEPT_BARBER_POS, DEPT_BARBER_BACKOFFICE];
    }
    return [];
}

/**
 * Check if user has access to any department in a module
 * @param string $module Module name
 * @param PDO|null $conn Database connection
 * @return bool
 */
function has_module_access_v2(string $module, ?PDO $conn = null): bool {
    $departments = get_user_departments(current_user_id() ?: 0, $conn);
    return isset($departments[$module]) && !empty($departments[$module]);
}

/**
 * Get role's departments
 * @param int $roleId Role ID
 * @param PDO|null $conn Database connection
 * @return array ['module' => ['department1', 'department2'], ...]
 */
function get_role_departments(int $roleId, ?PDO $conn = null): array {
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
        SELECT module, department
        FROM role_departments
        WHERE role_id = ?
        ORDER BY module, department
    ");
    $stmt->execute([$roleId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $departments = [];
    foreach ($rows as $row) {
        $module = $row['module'];
        $dept = $row['department'];
        if (!isset($departments[$module])) {
            $departments[$module] = [];
        }
        $departments[$module][] = $dept;
    }
    
    return $departments;
}

/**
 * Set role's departments
 * @param int $roleId Role ID
 * @param array $departments ['module' => ['department1', 'department2'], ...]
 * @param PDO|null $conn Database connection
 * @return bool Success
 */
function set_role_departments(int $roleId, array $departments, ?PDO $conn = null): bool {
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
        $conn->beginTransaction();
        
        // Delete existing departments for this role
        $stmt = $conn->prepare("DELETE FROM role_departments WHERE role_id = ?");
        $stmt->execute([$roleId]);
        
        // Insert new departments
        $stmt = $conn->prepare("
            INSERT INTO role_departments (role_id, module, department) 
            VALUES (?, ?, ?)
        ");
        
        foreach ($departments as $module => $depts) {
            foreach ($depts as $dept) {
                $stmt->execute([$roleId, $module, $dept]);
            }
        }
        
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Error setting role departments: " . $e->getMessage());
        return false;
    }
}

/**
 * Build module → [departments] map from flat checkbox values (System Settings → Department Assignment).
 * Handles prefixed departments (cleaning_, realestate_, …), single-code depts (inventory, grocery, barber),
 * and shared HR across modules that include DEPT_HR in get_module_departments().
 *
 * @param list<string> $selectedDeptCodes
 * @return array<string, list<string>>
 */
function rbac_department_selection_to_structure(array $selectedDeptCodes): array {
    $departmentsStructure = [];
    foreach ($selectedDeptCodes as $deptCode) {
        $deptCode = (string)$deptCode;
        if ($deptCode === DEPT_HR) {
            foreach ([MODULE_CLEANING, MODULE_REALESTATE, MODULE_CONSTRUCTION, MODULE_ARS] as $m) {
                $mds = get_module_departments($m);
                if (in_array(DEPT_HR, $mds, true)) {
                    if (!isset($departmentsStructure[$m])) {
                        $departmentsStructure[$m] = [];
                    }
                    if (!in_array(DEPT_HR, $departmentsStructure[$m], true)) {
                        $departmentsStructure[$m][] = DEPT_HR;
                    }
                }
            }
            continue;
        }
        $module = null;
        if (strpos($deptCode, 'cleaning_') === 0) {
            $module = MODULE_CLEANING;
        } elseif (strpos($deptCode, 'realestate_') === 0) {
            $module = MODULE_REALESTATE;
        } elseif (strpos($deptCode, 'construction_') === 0) {
            $module = MODULE_CONSTRUCTION;
        } elseif (strpos($deptCode, 'ars_') === 0) {
            $module = MODULE_ARS;
        } elseif ($deptCode === DEPT_GROCERY_POS || $deptCode === DEPT_GROCERY_BACKOFFICE) {
            $module = MODULE_GROCERY;
        } elseif ($deptCode === DEPT_INVENTORY) {
            $module = MODULE_INVENTORY;
        } elseif ($deptCode === DEPT_BARBER_POS || $deptCode === DEPT_BARBER_BACKOFFICE) {
            $module = MODULE_BARBER;
        } elseif ($deptCode === DEPT_LEGAL || strpos($deptCode, 'legal_') === 0) {
            $module = MODULE_LEGAL;
        }
        if ($module) {
            if (!isset($departmentsStructure[$module])) {
                $departmentsStructure[$module] = [];
            }
            if (!in_array($deptCode, $departmentsStructure[$module], true)) {
                $departmentsStructure[$module][] = $deptCode;
            }
        }
    }
    return $departmentsStructure;
}

function has_grocery_pos_department(?PDO $conn = null): bool {
    return has_department_access(MODULE_GROCERY, DEPT_GROCERY_POS, $conn);
}

function has_grocery_backoffice_department(?PDO $conn = null): bool {
    return has_department_access(MODULE_GROCERY, DEPT_GROCERY_BACKOFFICE, $conn);
}

function require_grocery_pos_department(?PDO $conn = null): void {
    require_department_access(MODULE_GROCERY, DEPT_GROCERY_POS, $conn);
}

function require_grocery_backoffice_department(?PDO $conn = null): void {
    require_department_access(MODULE_GROCERY, DEPT_GROCERY_BACKOFFICE, $conn);
}

function has_barber_pos_department(?PDO $conn = null): bool {
    return has_department_access(MODULE_BARBER, DEPT_BARBER_POS, $conn);
}

function has_barber_backoffice_department(?PDO $conn = null): bool {
    return has_department_access(MODULE_BARBER, DEPT_BARBER_BACKOFFICE, $conn);
}

function require_barber_pos_department(?PDO $conn = null): void {
    require_department_access(MODULE_BARBER, DEPT_BARBER_POS, $conn);
}

function require_barber_backoffice_department(?PDO $conn = null): void {
    require_department_access(MODULE_BARBER, DEPT_BARBER_BACKOFFICE, $conn);
}

function has_any_barber_department(?PDO $conn = null): bool {
    return has_barber_pos_department($conn) || has_barber_backoffice_department($conn);
}

function require_any_barber_department(?PDO $conn = null): void {
    if (!has_any_barber_department($conn)) {
        require_department_access(MODULE_BARBER, DEPT_BARBER_POS, $conn);
    }
}

function has_any_grocery_department(?PDO $conn = null): bool {
    return has_grocery_pos_department($conn) || has_grocery_backoffice_department($conn);
}

function require_any_grocery_department(?PDO $conn = null): void {
    if (!has_any_grocery_department($conn)) {
        require_department_access(MODULE_GROCERY, DEPT_GROCERY_POS, $conn);
    }
}
