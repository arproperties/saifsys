<?php
/**
 * Debug User Access - Check user's company and module access
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/company_helper.php';
require_once __DIR__ . '/includes/module_access.php';

require_login();
require_role(['Owner', 'Admin'], $conn);

$userId = !empty($_GET['user_id']) ? (int)$_GET['user_id'] : current_user_id();

// Get user info
$stmt = $conn->prepare("SELECT id, username, fullname FROM user WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("User not found");
}

// Get user companies
$userCompanies = get_user_companies($conn, $userId);

// Get user modules
$userModules = get_user_modules($conn, $userId);

// Get user roles
$roles = current_user_roles($conn);

// Get current company
$currentCompanyId = current_company_id($conn);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug User Access</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <h1>User Access Debug - <?= h($user['username']) ?></h1>
        
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5>User Information</h5>
                    </div>
                    <div class="card-body">
                        <p><strong>ID:</strong> <?= $user['id'] ?></p>
                        <p><strong>Username:</strong> <?= h($user['username']) ?></p>
                        <p><strong>Full Name:</strong> <?= h($user['fullname'] ?: 'N/A') ?></p>
                        <p><strong>Current Company ID:</strong> <?= $currentCompanyId ?: 'Not set' ?></p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5>User Roles</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($roles)): ?>
                            <p class="text-muted">No roles assigned</p>
                        <?php else: ?>
                            <ul>
                                <?php foreach ($roles as $role): ?>
                                    <li><?= h($role) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if (in_array('Owner', $roles, true) || in_array('Admin', $roles, true)): ?>
                                <div class="alert alert-warning mt-2">
                                    <strong>Note:</strong> Owner/Admin roles have access to ALL modules automatically.
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5>User Companies (<?= count($userCompanies) ?>)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($userCompanies)): ?>
                            <p class="text-danger"><strong>No companies assigned!</strong></p>
                        <?php else: ?>
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Primary</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($userCompanies as $company): ?>
                                        <tr class="<?= $company['is_primary'] ? 'table-warning' : '' ?>">
                                            <td><?= $company['id'] ?></td>
                                            <td><?= h($company['name']) ?></td>
                                            <td><?= h($company['business_type']) ?></td>
                                            <td>
                                                <?php if ($company['is_primary']): ?>
                                                    <span class="badge bg-warning">Yes</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">No</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h5>User Modules (<?= count($userModules) ?>)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($userModules)): ?>
                            <p class="text-danger"><strong>No modules accessible!</strong></p>
                        <?php else: ?>
                            <ul>
                                <?php foreach ($userModules as $module): ?>
                                    <li>
                                        <strong><?= h($module['module']) ?></strong>
                                        <?php if (!empty($module['roles'])): ?>
                                            <br><small class="text-muted">Roles: <?= h(implode(', ', $module['roles'])) ?></small>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card mt-4">
            <div class="card-header bg-danger text-white">
                <h5>Auto-Redirect Logic Analysis</h5>
            </div>
            <div class="card-body">
                <?php
                $companyCount = count($userCompanies);
                $moduleCount = count($userModules);
                $isOwnerAdmin = in_array('Owner', $roles, true) || in_array('Admin', $roles, true);
                
                echo "<p><strong>Company Count:</strong> {$companyCount}</p>";
                echo "<p><strong>Module Count:</strong> {$moduleCount}</p>";
                echo "<p><strong>Is Owner/Admin:</strong> " . ($isOwnerAdmin ? 'Yes' : 'No') . "</p>";
                echo "<hr>";
                
                if ($isOwnerAdmin && $companyCount > 1) {
                    echo "<div class='alert alert-info'><strong>Result:</strong> Should show index (group overview)</div>";
                } elseif ($companyCount === 1 && $moduleCount === 1) {
                    $company = $userCompanies[0];
                    $module = $userModules[0];
                    $route = get_module_route($module['module']);
                    echo "<div class='alert alert-success'><strong>Result:</strong> Auto-redirect to: <code>{$route}</code></div>";
                    echo "<p><strong>Company:</strong> {$company['name']} ({$company['business_type']})</p>";
                    echo "<p><strong>Module:</strong> {$module['module']}</p>";
                } elseif ($companyCount > 1 || $moduleCount > 1) {
                    echo "<div class='alert alert-warning'><strong>Result:</strong> Should show module selector (select-module)</div>";
                } else {
                    echo "<div class='alert alert-danger'><strong>Result:</strong> No companies or modules - user cannot access system!</div>";
                }
                ?>
            </div>
        </div>
        
        <div class="card mt-4">
            <div class="card-header">
                <h5>Quick Fixes</h5>
            </div>
            <div class="card-body">
                <h6>If user should see module selector but doesn't:</h6>
                <ol>
                    <li>Make sure user has access to multiple companies OR multiple modules</li>
                    <li>If user is Owner/Admin with multiple companies, they should see index</li>
                    <li>If user has only one company but multiple modules, they should see selector</li>
                </ol>
                
                <h6 class="mt-3">To set Real Estate company as primary:</h6>
                <pre class="bg-light p-3 rounded"><code>-- Replace USER_ID and COMPANY_ID
UPDATE user_companies 
SET is_primary = 0 
WHERE user_id = USER_ID;

UPDATE user_companies 
SET is_primary = 1 
WHERE user_id = USER_ID AND company_id = COMPANY_ID;</code></pre>
            </div>
        </div>
        
        <div class="mt-4">
            <a href="setup_realestate_company.php" class="btn btn-primary">Back to Setup</a>
            <a href="index.php" class="btn btn-secondary">Go to Dashboard</a>
        </div>
    </div>
</body>
</html>

