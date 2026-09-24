<?php
/**
 * Module/Company Selector
 * Allows users to select which company and module to access
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/branding.php';
require_once __DIR__ . '/includes/company_helper.php';
require_once __DIR__ . '/includes/module_access.php';
require_once __DIR__ . '/includes/url_helper.php';

require_login();

$brand = getBrandSettings($conn);
$userId = current_user_id();

if (isset($_GET['goto']) && $_GET['goto'] === 'pos_dashboard') {
    $cid = (int)($_GET['company_id'] ?? 0);
    if ($cid && $userId && user_has_company_access($conn, $userId, $cid) && user_has_company_module_access($conn, $userId, $cid, MODULE_GROCERY)) {
        set_current_company($cid);
        $root = get_application_web_root();
        header('Location: ' . $root . '/modules/grocery/pos_dashboard.php');
        exit;
    }
}
$userCompanies = get_user_companies($conn, $userId);
$userModules = get_user_modules($conn, $userId);

$defaultCompanyId = 0;
foreach ($userCompanies as $co) {
    if (!empty($co['is_primary'])) {
        $defaultCompanyId = (int)$co['id'];
        break;
    }
}
if ($defaultCompanyId <= 0 && !empty($userCompanies)) {
    $defaultCompanyId = (int)$userCompanies[0]['id'];
}

/** @var array<string, list<int>> For each module key, company IDs where the user may use that module */
$moduleEligibleCompanies = [];
foreach ($userCompanies as $co) {
    $cid = (int)$co['id'];
    foreach (get_user_company_modules($conn, $userId, $cid) as $entry) {
        $mn = $entry['module'];
        if (!isset($moduleEligibleCompanies[$mn])) {
            $moduleEligibleCompanies[$mn] = [];
        }
        if (!in_array($cid, $moduleEligibleCompanies[$mn], true)) {
            $moduleEligibleCompanies[$mn][] = $cid;
        }
    }
}

// Get user's departments and filter modules
require_once __DIR__ . '/includes/rbac_department.php';
require_once __DIR__ . '/includes/module_access.php';
$userDepartments = get_user_departments($userId, $conn);
$modulesWithDepts = [];
foreach ($userModules as $module) {
    $moduleName = is_array($module) ? $module['module'] : $module;
    $moduleDepts = $userDepartments[$moduleName] ?? [];
    if (!empty($moduleDepts)) {
        $modulesWithDepts[] = [
            'module' => $moduleName,
            'departments' => $moduleDepts
        ];
    }
}

// AUTO-REDIRECT: If only one module with departments, redirect immediately
$companiesCount = count($userCompanies);
$modulesWithDeptsCount = count($modulesWithDepts);

if ($modulesWithDeptsCount === 1) {
    $moduleData = $modulesWithDepts[0];
    $moduleName = $moduleData['module'];
    $moduleDepts = $moduleData['departments'];
    
    $selectedCompany = null;
    
    foreach ($userCompanies as $company) {
        if (!empty($company['is_primary'])) {
            $selectedCompany = $company;
            break;
        }
    }
    
    if (!$selectedCompany && !empty($userCompanies)) {
        $selectedCompany = $userCompanies[0];
    }
    
    if ($selectedCompany && $moduleName) {
        set_current_company($selectedCompany['id']);
        unset($_SESSION['needs_module_selection']);
        
        // For cleaning module, always go to dashboard (index.php) first
        if ($moduleName === 'cleaning') {
            $route = get_module_route($moduleName, $userId);
            header('Location: ' . redirect_url($route));
            exit;
        }
        
        if (!empty($moduleDepts)) {
            if ($moduleName === MODULE_OPERATIONS) {
                $route = resolve_operations_entry_route($moduleDepts);
            } elseif ($moduleName === MODULE_GROCERY || $moduleName === MODULE_BARBER) {
                $route = resolve_grocery_or_barber_entry_route($moduleName, $moduleDepts);
            } else {
                $dept = $moduleDepts[0];
                $route = get_department_route($moduleName, $dept);
            }
            if ($route) {
                header('Location: ' . redirect_url($route));
                exit;
            }
        }
        
        $route = get_module_route($moduleName, $userId);
        header('Location: ' . redirect_url($route));
        exit;
    }
}

// Handle module/company selection (POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['company_id']) && isset($_POST['module'])) {
    csrf_verify();
    
    $companyId = (int)$_POST['company_id'];
    $module = $_POST['module'];
    
    if (user_has_company_access($conn, $userId, $companyId) && 
        user_has_company_module_access($conn, $userId, $companyId, $module)) {
        
        set_current_company($companyId);
        unset($_SESSION['needs_module_selection']);
        
        // For cleaning module, always go to dashboard (index.php) first
        if ($module === 'cleaning') {
            $route = get_module_route($module, $userId);
            header('Location: ' . redirect_url($route));
            exit;
        }
        
        require_once __DIR__ . '/includes/rbac_department.php';
        $userDepartments = get_user_departments($userId, $conn);
        $moduleDepts = $userDepartments[$module] ?? [];
        
        if (!empty($moduleDepts)) {
            if ($module === MODULE_OPERATIONS) {
                $route = resolve_operations_entry_route($moduleDepts);
            } elseif ($module === MODULE_GROCERY || $module === MODULE_BARBER) {
                $route = resolve_grocery_or_barber_entry_route($module, $moduleDepts);
            } else {
                $dept = $moduleDepts[0];
                $route = get_department_route($module, $dept);
            }
            if ($route) {
                header('Location: ' . redirect_url($route));
                exit;
            }
        }
        
        $route = get_module_route($module, $userId);
        header('Location: ' . redirect_url($route));
        exit;
    } else {
        $error = 'Access denied to selected module/company';
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Module - <?= h($brand['system_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <?php
    $appRoot = function_exists('get_application_web_root') ? get_application_web_root() : '';
    $smCss = ($appRoot !== '' ? $appRoot : '') . '/assets/select-module/select-module-v2.css';
    $logoSrc = function_exists('brand_logo_src') ? brand_logo_src($brand) : '';
    $userLabel = trim((string)($_SESSION['user']['fullname'] ?? $_SESSION['user']['username'] ?? 'User'));
    $roleLabel = '';
    if (!empty($_SESSION['role_names']) && is_array($_SESSION['role_names'])) {
        $roleLabel = (string)$_SESSION['role_names'][0];
    }
    ?>
    <link rel="stylesheet" href="<?= h($smCss) ?>?v=20260717b">
    <style>
      body.sm-v2 {
        --sm-primary: <?= h($brand['primary_color']) ?>;
        --sm-primary-light: <?= h($brand['primary_light']) ?>;
        --sm-primary-dark: <?= h($brand['primary_dark']) ?>;
        --sm-accent: <?= h($brand['accent_color']) ?>;
      }
    </style>
</head>
<body class="sm-v2">
    <?php require __DIR__ . '/includes/attendance_self_widget.php'; ?>
    <div class="sm-atmosphere" aria-hidden="true"></div>
    <div class="sm-shell">
        <header class="sm-topbar">
            <div class="sm-brand">
                <div class="sm-brand-mark">
                    <?php if ($logoSrc !== ''): ?>
                        <img src="<?= h($logoSrc) ?>" alt="<?= h($brand['system_name']) ?>">
                    <?php else: ?>
                        <?= h($brand['system_name_short'] ?: 'HS') ?>
                    <?php endif; ?>
                </div>
                <div class="sm-brand-text">
                    <div class="sm-brand-name"><?= h($brand['system_name']) ?></div>
                    <div class="sm-brand-tag">Workspace launcher</div>
                </div>
            </div>
            <div class="sm-user-chip">
                <i class="bi bi-person-circle"></i>
                <strong><?= h($userLabel) ?></strong>
                <?php if ($roleLabel !== ''): ?>
                    <span class="sm-user-role">· <?= h($roleLabel) ?></span>
                <?php endif; ?>
            </div>
        </header>

        <section class="sm-hero">
            <div class="sm-hero-kicker"><i class="bi bi-grid-1x2-fill"></i> Select module</div>
            <h1>Where do you want to work?</h1>
            <p>Tap a workspace to open it. Company is chosen automatically — adjust it below only if you manage more than one.</p>
        </section>

        <?php if (!empty($error)): ?>
            <div class="sm-alert" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= h($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="moduleForm">
            <?php csrf_field(); ?>
            <?php
            $isOwnerRole = in_array('Owner', $_SESSION['role_names'] ?? [], true);
            $displayModules = array_values(array_filter($userModules, static function ($m) use ($isOwnerRole) {
                $mn = is_array($m) ? ($m['module'] ?? '') : $m;
                if ($mn === 'legal' && !$isOwnerRole) {
                    return false;
                }
                // Inventory is retired from the launcher — day-to-day stock lives in Operations.
                // Files stay in place: Grocery POS, purchasing and cross-module material
                // requests still reach /modules/inventory/ through their own links.
                if ($mn === MODULE_INVENTORY) {
                    return false;
                }
                return true;
            }));

            // Operations leads the list; everything else keeps its existing order.
            $opsCard = [];
            $otherCards = [];
            foreach ($displayModules as $m) {
                $mn = is_array($m) ? ($m['module'] ?? '') : $m;
                if ($mn === MODULE_OPERATIONS) {
                    $opsCard[] = $m;
                } else {
                    $otherCards[] = $m;
                }
            }
            $displayModules = array_merge($opsCard, $otherCards);
            ?>

            <div class="sm-section-head">
                <h2 class="sm-section-title">Your workspaces</h2>
                <div class="sm-section-meta"><?= count($displayModules) ?> available</div>
            </div>

            <div class="sm-modules" id="modulesContainer">
                <?php if (!$displayModules): ?>
                    <div class="sm-empty">No modules are assigned to your account. Contact an administrator.</div>
                <?php endif; ?>
                <?php foreach ($displayModules as $module): ?>
                    <?php
                    $moduleName = is_array($module) ? $module['module'] : $module;
                    // Icons must exist in Bootstrap Icons 1.11 (bi-broom does not).
                    $icons = [
                        'cleaning' => 'bi-droplet-fill',
                        'realestate' => 'bi-buildings',
                        'legal' => 'bi-bank2',
                        'construction' => 'bi-hammer',
                        'hr' => 'bi-people-fill',
                        'finance' => 'bi-calculator-fill',
                        'inventory' => 'bi-box-seam-fill',
                        'grocery' => 'bi-cart3',
                        'barber' => 'bi-scissors',
                        'ars' => 'bi-house-heart-fill',
                        'operations' => 'bi-clipboard-check-fill',
                    ];
                    $icon = $icons[$moduleName] ?? 'bi-grid-fill';
                    $deptNames = get_module_selector_summary_labels($moduleName, is_array($module) ? $module : ['module' => $moduleName]);
                    $detail = !empty($deptNames)
                        ? implode(' · ', array_slice($deptNames, 0, 3))
                        : 'Open workspace';
                    $extra = !empty($deptNames) && count($deptNames) > 3 ? (count($deptNames) - 3) : 0;
                    ?>
                    <button type="button" class="sm-module module-card" data-module="<?= h($moduleName) ?>">
                        <div class="sm-module-icon" aria-hidden="true">
                            <i class="bi <?= h($icon) ?>"></i>
                        </div>
                        <div class="sm-module-body">
                            <h3 class="sm-module-title"><?= h(get_module_display_name($moduleName)) ?></h3>
                            <p class="sm-module-details">
                                <?= h($detail) ?>
                                <?php if ($extra > 0): ?>
                                    <span class="sm-module-badge">+<?= (int)$extra ?></span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <span class="sm-module-chevron" aria-hidden="true"><i class="bi bi-arrow-right"></i></span>
                    </button>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="module" id="selectedModule" value="">

            <div class="sm-company">
                <div>
                    <p class="sm-company-label"><i class="bi bi-building me-1"></i> Company context</p>
                    <p class="sm-company-help">Optional — only if you need a specific company before opening a module.</p>
                </div>
                <div class="sm-company-select">
                    <select name="company_id" class="form-select" id="companySelect">
                        <?php if (empty($userCompanies)): ?>
                            <option value="">— No companies assigned —</option>
                        <?php else: ?>
                            <?php foreach ($userCompanies as $company): ?>
                                <option value="<?= (int)$company['id'] ?>" <?= ((int)$company['id'] === $defaultCompanyId) ? 'selected' : '' ?>>
                                    <?= h($company['name']) ?> (<?= h($company['business_type']) ?>)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
        </form>
    </div>

    <script>
        const defaultCompanyId = <?= (int)$defaultCompanyId ?>;
        const moduleEligibleCompanies = <?= json_encode($moduleEligibleCompanies, JSON_UNESCAPED_UNICODE) ?>;

        const companySelect = document.getElementById('companySelect');
        const modulesContainer = document.getElementById('modulesContainer');
        const moduleCards = modulesContainer.querySelectorAll('.module-card');
        const selectedModuleInput = document.getElementById('selectedModule');
        const form = document.getElementById('moduleForm');

        function resolveCompanyIdForModule(moduleName) {
            const eligible = moduleEligibleCompanies[moduleName] || [];
            if (eligible.length === 0) {
                return 0;
            }
            let cid = parseInt(companySelect.value, 10) || 0;
            if (eligible.indexOf(cid) !== -1) {
                return cid;
            }
            if (defaultCompanyId && eligible.indexOf(defaultCompanyId) !== -1) {
                return defaultCompanyId;
            }
            return eligible[0];
        }

        moduleCards.forEach(function (card) {
            card.addEventListener('click', function () {
                const mod = this.dataset.module;
                if (!mod) {
                    return;
                }
                const cid = resolveCompanyIdForModule(mod);
                if (!cid) {
                    alert('You do not have a company that can use this module.');
                    return;
                }
                companySelect.value = String(cid);
                selectedModuleInput.value = mod;
                moduleCards.forEach(function (c) { c.classList.remove('selected'); });
                this.classList.add('selected');
                form.submit();
            });
        });
    </script>
</body>
</html>
