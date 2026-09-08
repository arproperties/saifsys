<?php
/**
 * Module Access Control Helper
 * Provides utilities for module-based access control
 */

require_once __DIR__ . '/company_helper.php';

/**
 * Available modules in the system
 */
define('MODULE_CLEANING', 'cleaning');
define('MODULE_REALESTATE', 'realestate');
define('MODULE_CONSTRUCTION', 'construction');
define('MODULE_HR', 'hr');
define('MODULE_FINANCE', 'finance');
define('MODULE_INVENTORY', 'inventory');
define('MODULE_CORE', 'core');
define('MODULE_ARS', 'ars');
define('MODULE_GROCERY', 'grocery');
define('MODULE_BARBER', 'barber');
define('MODULE_LEGAL', 'legal');
define('MODULE_OPERATIONS', 'operations');

/**
 * Get user's accessible modules based on departments
 * Uses new department-based RBAC system
 */
function get_user_modules(PDO $conn, int $userId): array {
    require_once __DIR__ . '/rbac_department.php';
    
    // Use new department-based function
    $modules = get_user_modules_v2($conn, $userId);
    
    // If no departments found, fallback to old method for backward compatibility
    if (empty($modules)) {
        $modules = [];
        
        // Get user's roles
        $stmt = $conn->prepare("
            SELECT DISTINCT r.module, r.name as role_name, r.id as role_id
            FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = ? AND r.module IS NOT NULL
        ");
        $stmt->execute([$userId]);
        $roleModules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Combine modules from roles
        foreach ($roleModules as $row) {
            if ($row['module']) {
                $mod = $row['module'];
                if (!isset($modules[$mod])) {
                    // Get departments for this module from role_departments
                    $userDepts = get_user_departments($userId, $conn);
                    $moduleDepts = $userDepts[$mod] ?? [];
                    
                    $modules[$mod] = [
                        'module' => $mod,
                        'departments' => $moduleDepts,
                        'roles' => []
                    ];
                }
                if (!in_array($row['role_name'], $modules[$mod]['roles'], true)) {
                    $modules[$mod]['roles'][] = $row['role_name'];
                }
            }
        }
        
        $modules = array_values($modules);
    }
    
    // Owner and Admin have access to all modules
    // BUT: Only add modules if they actually have departments assigned
    // Don't add empty modules for non-Owner/Admin users
    $roles = current_user_roles($conn);
    if (in_array('Owner', $roles, true) || in_array('Admin', $roles, true)) {
        $allModules = [MODULE_CLEANING, MODULE_REALESTATE, MODULE_CONSTRUCTION, MODULE_HR, MODULE_FINANCE, MODULE_INVENTORY, MODULE_ARS, MODULE_GROCERY, MODULE_BARBER, MODULE_LEGAL, MODULE_OPERATIONS];
        $existingModules = array_column($modules, 'module');
        foreach ($allModules as $module) {
            if (!in_array($module, $existingModules, true)) {
                // For Owner/Admin, they have all departments, so add all modules
                $modules[] = ['module' => $module, 'departments' => [], 'roles' => $roles];
            }
        }
    }
    
    return $modules;
}

/**
 * Get user's accessible modules for specific company
 */
function get_user_company_modules(PDO $conn, int $userId, int $companyId): array {
    // Check if user has access to company
    if (!user_has_company_access($conn, $userId, $companyId)) {
        return [];
    }
    
    // Get company business type
    $company = get_company($conn, $companyId);
    if (!$company) {
        return [];
    }
    
    $allModules = get_user_modules($conn, $userId);
    $companyModules = [];
    
    // Filter modules based on company business type
    foreach ($allModules as $module) {
        $moduleName = $module['module'];
        
        // Shared modules are always available (HR, Finance, Inventory, Legal for RE companies)
        if ($moduleName === MODULE_HR || $moduleName === MODULE_FINANCE || $moduleName === MODULE_INVENTORY || $moduleName === MODULE_OPERATIONS) {
            $companyModules[] = $module;
            continue;
        }
        if ($moduleName === MODULE_LEGAL && $company['business_type'] === 'realestate') {
            $companyModules[] = $module;
            continue;
        }
        
        // Business-specific modules
        if ($moduleName === MODULE_CLEANING && $company['business_type'] === 'cleaning') {
            $companyModules[] = $module;
        } elseif ($moduleName === MODULE_REALESTATE && $company['business_type'] === 'realestate') {
            $companyModules[] = $module;
        } elseif ($moduleName === MODULE_CONSTRUCTION && $company['business_type'] === 'construction') {
            $companyModules[] = $module;
        } elseif ($moduleName === MODULE_ARS && $company['business_type'] === 'short_term_rental') {
            $companyModules[] = $module;
        } elseif ($moduleName === MODULE_GROCERY && $company['business_type'] === 'supermarket') {
            $companyModules[] = $module;
        } elseif ($moduleName === MODULE_BARBER && $company['business_type'] === 'barbershop') {
            $companyModules[] = $module;
        }
    }
    
    return $companyModules;
}

/**
 * Check if user has access to module
 */
function user_has_module_access(PDO $conn, int $userId, string $module): bool {
    $modules = get_user_modules($conn, $userId);
    foreach ($modules as $m) {
        if ($m['module'] === $module) {
            return true;
        }
    }
    return false;
}

/**
 * Check if user has access to module for specific company
 */
function user_has_company_module_access(PDO $conn, int $userId, int $companyId, string $module): bool {
    $modules = get_user_company_modules($conn, $userId, $companyId);
    foreach ($modules as $m) {
        if ($m['module'] === $module) {
            return true;
        }
    }
    return false;
}

/**
 * Business-type–bound modules: session company must match that module.
 * Shared modules (HR, Finance, Inventory) can stay on whatever company is selected.
 */
function module_requires_matching_company(string $module): bool {
    return in_array($module, [
        MODULE_CLEANING,
        MODULE_REALESTATE,
        MODULE_CONSTRUCTION,
        MODULE_ARS,
        MODULE_GROCERY,
        MODULE_BARBER,
    ], true);
}

/**
 * If the session company does not support $module for this user, set current company to the
 * first accessible company that does (primary preferred via get_user_companies order).
 *
 * Fixes wrong GL/POS data when the user last worked in another business type and then opens
 * another module in the same browser session without going through select-module
 * (e.g. session still on Construction while viewing Real Estate reports).
 */
function ensure_current_company_supports_module(PDO $conn, string $module): void {
    $userId = current_user_id();
    if (!$userId) {
        return;
    }
    $cid = current_company_id($conn) ?: 0;
    if ($cid > 0 && user_has_company_module_access($conn, $userId, $cid, $module)) {
        return;
    }
    foreach (get_user_companies($conn, $userId) as $co) {
        $id = (int)$co['id'];
        if ($id > 0 && user_has_company_module_access($conn, $userId, $id, $module)) {
            set_current_company($id);
            return;
        }
    }
}

/**
 * Require module access (throws 403 if no access)
 */
function require_module_access(PDO $conn, string $module, int $companyId = null): void {
    require_login();
    
    $userId = current_user_id();
    if (!$userId) {
        http_response_code(403);
        die('Access denied');
    }
    
    // If company specified, check company-specific access
    if ($companyId) {
        if (!user_has_company_module_access($conn, $userId, $companyId, $module)) {
            http_response_code(403);
            die('Access denied to this module for this company');
        }
    } else {
        // Check general module access
        if (!user_has_module_access($conn, $userId, $module)) {
            http_response_code(403);
            die('Access denied to this module');
        }
        // Align session company before any page queries (RE / Construction / Grocery / …)
        if (module_requires_matching_company($module)) {
            ensure_current_company_supports_module($conn, $module);
        }
    }
}

/**
 * Get module display name
 */
function get_module_display_name(string $module): string {
    $names = [
        MODULE_CLEANING => 'Cleaning',
        MODULE_REALESTATE => 'Real Estate',
        MODULE_CONSTRUCTION => 'Construction',
        MODULE_HR => 'HR',
        MODULE_FINANCE => 'Finance',
        MODULE_INVENTORY => 'Inventory',
        MODULE_CORE => 'Core',
        MODULE_ARS => 'ARS Home Rentals',
        MODULE_GROCERY => 'Grocery',
        MODULE_BARBER => 'Barber shop',
        MODULE_LEGAL => 'Legal Department',
        MODULE_OPERATIONS => 'Operations'
    ];
    return $names[$module] ?? ucfirst($module);
}

/**
 * Short labels for module cards on select-module (UI only).
 * HR is a standalone module — do not show "HR" under Cleaning/RE/etc.
 */
function get_module_selector_summary_labels(string $moduleName, array $moduleEntry): array {
    require_once __DIR__ . '/rbac_department.php';

    $staticSummaries = [
        MODULE_CLEANING => ['Operations', 'Accounts'],
        MODULE_HR => ['Employees', 'Payroll', 'Attendance'],
        MODULE_REALESTATE => ['Core Management', 'Financial', 'Maintenance'],
        MODULE_CONSTRUCTION => ['Core', 'Projects', 'Financial'],
        MODULE_ARS => ['Core Management', 'Operations'],
        MODULE_INVENTORY => ['Inventory'],
        MODULE_GROCERY => ['POS (retail)', 'Back office'],
        MODULE_BARBER => ['POS', 'Back office'],
        MODULE_LEGAL => ['Legal Department'],
        MODULE_OPERATIONS => ['Cleaning', 'Maintenance'],
    ];

    $departments = $moduleEntry['departments'] ?? [];
    if (!empty($departments)) {
        if ($moduleName !== MODULE_HR) {
            $departments = array_values(array_filter(
                $departments,
                static fn($dept) => $dept !== DEPT_HR
            ));
        }
        if (!empty($departments)) {
            return array_map('get_department_display_name', $departments);
        }
    }

    if (isset($staticSummaries[$moduleName])) {
        return $staticSummaries[$moduleName];
    }

    $roles = $moduleEntry['roles'] ?? [];
    if (!empty($roles)) {
        return array_slice($roles, 0, 3);
    }

    return [];
}

/**
 * Get department route
 */
function get_department_route(string $module, string $department): ?string {
    require_once __DIR__ . '/rbac_department.php';
    
    // Get base path dynamically from current script location
    $base = '/'; // Default fallback (root)
    
    if (isset($_SERVER['SCRIPT_NAME'])) {
        $scriptName = $_SERVER['SCRIPT_NAME'];
        $scriptDir = dirname($scriptName);
        $scriptDir = rtrim(str_replace('\\', '/', $scriptDir), '/');
        
        // Extract the project base path (e.g., /)
        if ($scriptDir && $scriptDir !== '/' && $scriptDir !== '.') {
            $base = $scriptDir;
        }
    }
    
    // Department-specific routes
    $deptRoutes = [
        DEPT_CLEANING_OPERATIONS => '/operation',
        DEPT_CLEANING_ACCOUNTS => '/account',
        DEPT_REALESTATE_CORE => '/modules/realestate',
        DEPT_REALESTATE_FINANCIAL => '/modules/realestate/payments',
        DEPT_REALESTATE_MAINTENANCE => '/modules/realestate/maintenance',
        DEPT_REALESTATE_OPERATIONS => '/modules/realestate/move_in',
        DEPT_REALESTATE_COMPLIANCE => '/modules/realestate/compliance',
        DEPT_LEGAL => '/modules/legal/legal_dashboard.php',
        DEPT_CONSTRUCTION_CORE => '/modules/construction',
        DEPT_CONSTRUCTION_PROJECTS => '/modules/construction/projects',
        DEPT_CONSTRUCTION_FINANCIAL => '/modules/construction/supplier_payments',
        DEPT_CONSTRUCTION_REPORTS => '/modules/construction/reports/project_cost_summary',
        DEPT_HR => '/hr/dashboard',
        DEPT_ARS_CORE => '/modules/ars',
        DEPT_ARS_OPERATIONS => '/modules/ars',
        DEPT_GROCERY_POS => '/modules/grocery/pos_retail.php',
        DEPT_GROCERY_BACKOFFICE => '/modules/grocery/pos_dashboard.php',
        DEPT_BARBER_POS => '/modules/barber/pos.php',
        DEPT_BARBER_BACKOFFICE => '/modules/barber/dashboard.php',
        DEPT_OPERATIONS_SUPERVISOR => '/modules/operations/index.php'
    ];
    
    if (isset($deptRoutes[$department])) {
        $route = $deptRoutes[$department];
        // Ensure route starts with /
        $route = '/' . ltrim($route, '/');
        // Combine base and route, avoiding double slashes
        if ($base === '/') {
            return $route;
        }
        return rtrim($base, '/') . $route;
    }
    
    return null;
}

/**
 * Landing route for Grocery / Barber when a user may have POS only, back office only, or both.
 * Both → back office home (admins); POS-only → retail / barber POS; back-office-only → dashboard.
 */
function resolve_grocery_or_barber_entry_route(string $module, array $deptsForModule): ?string {
    require_once __DIR__ . '/rbac_department.php';
    if ($module === MODULE_GROCERY) {
        $p = in_array(DEPT_GROCERY_POS, $deptsForModule, true);
        $b = in_array(DEPT_GROCERY_BACKOFFICE, $deptsForModule, true);
        if ($b && $p) {
            return get_department_route($module, DEPT_GROCERY_BACKOFFICE);
        }
        if ($b) {
            return get_department_route($module, DEPT_GROCERY_BACKOFFICE);
        }
        if ($p) {
            return get_department_route($module, DEPT_GROCERY_POS);
        }
        return null;
    }
    if ($module === MODULE_BARBER) {
        $p = in_array(DEPT_BARBER_POS, $deptsForModule, true);
        $bo = in_array(DEPT_BARBER_BACKOFFICE, $deptsForModule, true);
        if ($bo && $p) {
            return get_department_route($module, DEPT_BARBER_BACKOFFICE);
        }
        if ($bo) {
            return get_department_route($module, DEPT_BARBER_BACKOFFICE);
        }
        if ($p) {
            return get_department_route($module, DEPT_BARBER_POS);
        }
        return null;
    }
    return null;
}

/**
 * Landing route for Operations: the jobs dashboard.
 */
function resolve_operations_entry_route(array $deptsForModule): ?string {
    require_once __DIR__ . '/rbac_department.php';
    if (in_array(DEPT_OPERATIONS_SUPERVISOR, $deptsForModule, true)) {
        return get_department_route(MODULE_OPERATIONS, DEPT_OPERATIONS_SUPERVISOR);
    }
    return null;
}

/**
 * Get module route (checks user's departments and redirects to first available)
 */
function get_module_route(string $module, ?int $userId = null): string {
    // Get base path dynamically from current script location
    $base = '/'; // Default fallback (root)
    
    if (isset($_SERVER['SCRIPT_NAME'])) {
        $scriptName = $_SERVER['SCRIPT_NAME'];
        $scriptDir = dirname($scriptName);
        $scriptDir = rtrim(str_replace('\\', '/', $scriptDir), '/');
        
        // Extract the project base path (e.g., /)
        if ($scriptDir && $scriptDir !== '/' && $scriptDir !== '.') {
            $base = $scriptDir;
        }
    }
    
    // For cleaning module, always return dashboard route (skip department check)
    if ($module === MODULE_CLEANING) {
        $route = '/';
        // Ensure route starts with /
        $route = '/' . ltrim($route, '/');
        // Combine base and route, avoiding double slashes
        if ($base === '/') {
            return $route;
        }
        return rtrim($base, '/') . $route;
    }
    
    // If userId provided, check departments and redirect to first available
    if ($userId) {
        require_once __DIR__ . '/rbac_department.php';
        $userDepartments = get_user_departments($userId);
        $moduleDepts = $userDepartments[$module] ?? [];
        
        if (!empty($moduleDepts)) {
            if ($module === MODULE_GROCERY || $module === MODULE_BARBER) {
                $route = resolve_grocery_or_barber_entry_route($module, $moduleDepts);
            } elseif ($module === MODULE_OPERATIONS) {
                $route = resolve_operations_entry_route($moduleDepts);
            } else {
                $dept = $moduleDepts[0];
                $route = get_department_route($module, $dept);
            }
            if ($route) {
                return $route;
            }
        }
    }
    
    // Default module routes
    $routes = [
        MODULE_CLEANING => '/',  // Cleaning dashboard (index.php)
        MODULE_REALESTATE => '/modules/realestate',
        MODULE_CONSTRUCTION => '/modules/construction',
        MODULE_HR => '/hr/dashboard',
        MODULE_FINANCE => '/accounts/invoices',
        MODULE_INVENTORY => '/modules/inventory',
        MODULE_ARS => '/modules/ars',
        MODULE_GROCERY => '/modules/grocery',
        MODULE_BARBER => '/modules/barber',
        MODULE_LEGAL => '/modules/legal/legal_dashboard.php',
        MODULE_OPERATIONS => '/modules/operations'
    ];
    
    $route = $routes[$module] ?? '/';
    // Ensure route starts with /
    $route = '/' . ltrim($route, '/');
    // Combine base and route, avoiding double slashes
    if ($base === '/') {
        return $route;
    }
    return rtrim($base, '/') . $route;
}

/**
 * Users without any cleaning department should not use index.php as home (it is the cleaning/BM KPI dashboard).
 * Returns a relative path (leading /) for redirect, or null to allow index.
 */
function get_non_cleaning_home_redirect_route(PDO $conn, int $userId): ?string {
    require_once __DIR__ . '/rbac_department.php';
    $roles = current_user_roles($conn);
    if (in_array('Owner', $roles, true) || in_array('Admin', $roles, true)) {
        return null;
    }
    $depts = get_user_departments($userId, $conn);
    $cleaning = $depts[MODULE_CLEANING] ?? [];
    if (!empty($cleaning)) {
        return null;
    }
    $userCompanies = get_user_companies($conn, $userId);
    if (empty($userCompanies)) {
        return null;
    }
    $userModules = get_user_modules($conn, $userId);
    $modulesWithDepts = [];
    foreach ($userModules as $module) {
        $moduleName = is_array($module) ? $module['module'] : $module;
        $moduleDepts = $depts[$moduleName] ?? [];
        if (!empty($moduleDepts)) {
            $modulesWithDepts[] = [
                'module' => $moduleName,
                'departments' => $moduleDepts
            ];
        }
    }
    if (empty($modulesWithDepts)) {
        return null;
    }
    if (count($userCompanies) > 1 || count($modulesWithDepts) > 1) {
        return '/select-module.php';
    }
    $moduleName = $modulesWithDepts[0]['module'];
    $moduleDepts = $modulesWithDepts[0]['departments'];
    if ($moduleName === MODULE_GROCERY || $moduleName === MODULE_BARBER) {
        $route = resolve_grocery_or_barber_entry_route($moduleName, $moduleDepts);
    } elseif ($moduleName === MODULE_OPERATIONS) {
        $route = resolve_operations_entry_route($moduleDepts);
    } else {
        $route = get_department_route($moduleName, $moduleDepts[0]);
    }
    if ($route) {
        return $route;
    }
    return get_module_route($moduleName, $userId);
}

