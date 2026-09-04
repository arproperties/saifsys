<?php
/**
 * Real Estate Company Setup Script
 * This script helps you create a Real Estate company and assign users
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/branding.php';

require_login();
require_role(['Owner', 'Admin'], $conn);

$brand = getBrandSettings($conn);
$success = '';
$error = '';
$companyId = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_company') {
        $companyName = trim($_POST['company_name'] ?? '');
        $companyCode = trim($_POST['company_code'] ?? '');
        
        if ($companyName) {
            try {
                $stmt = $conn->prepare("
                    INSERT INTO companies (name, code, business_type, is_active)
                    VALUES (?, ?, 'realestate', 1)
                ");
                $stmt->execute([$companyName, $companyCode ?: null]);
                $companyId = $conn->lastInsertId();
                $success = "Real Estate company created successfully! (ID: {$companyId})";
            } catch (Exception $e) {
                $error = "Error creating company: " . $e->getMessage();
            }
        } else {
            $error = "Company name is required";
        }
    } elseif ($action === 'assign_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $companyId = (int)($_POST['company_id'] ?? 0);
        $isPrimary = isset($_POST['is_primary']) ? 1 : 0;
        
        if ($userId && $companyId) {
            try {
                // Check if already assigned
                $stmt = $conn->prepare("SELECT id FROM user_companies WHERE user_id = ? AND company_id = ?");
                $stmt->execute([$userId, $companyId]);
                if ($stmt->fetchColumn()) {
                    $error = "User is already assigned to this company";
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO user_companies (user_id, company_id, is_primary)
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$userId, $companyId, $isPrimary]);
                    $success = "User assigned to company successfully!";
                }
            } catch (Exception $e) {
                $error = "Error assigning user: " . $e->getMessage();
            }
        } else {
            $error = "Please select both user and company";
        }
    } elseif ($action === 'create_roles') {
        try {
            // Create Real Estate roles if they don't exist
            $roles = [
                ['Property Manager', 'realestate'],
                ['Leasing Agent', 'realestate'],
                ['Property Administrator', 'realestate']
            ];
            
            foreach ($roles as $role) {
                $stmt = $conn->prepare("
                    INSERT IGNORE INTO roles (name, module, is_system)
                    VALUES (?, ?, 0)
                ");
                $stmt->execute($role);
            }
            $success = "Real Estate roles created successfully!";
        } catch (Exception $e) {
            $error = "Error creating roles: " . $e->getMessage();
        }
    } elseif ($action === 'assign_role') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $roleId = (int)($_POST['role_id'] ?? 0);
        
        if ($userId && $roleId) {
            try {
                // Check if already assigned
                $stmt = $conn->prepare("SELECT id FROM user_roles WHERE user_id = ? AND role_id = ?");
                $stmt->execute([$userId, $roleId]);
                if ($stmt->fetchColumn()) {
                    $error = "User already has this role";
                } else {
                    $stmt = $conn->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                    $stmt->execute([$userId, $roleId]);
                    $success = "Role assigned to user successfully!";
                }
            } catch (Exception $e) {
                $error = "Error assigning role: " . $e->getMessage();
            }
        } else {
            $error = "Please select both user and role";
        }
    } elseif ($action === 'set_primary') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $companyId = (int)($_POST['company_id'] ?? 0);
        
        if ($userId && $companyId) {
            try {
                $conn->beginTransaction();
                // Remove primary from all companies for this user
                $stmt = $conn->prepare("UPDATE user_companies SET is_primary = 0 WHERE user_id = ?");
                $stmt->execute([$userId]);
                // Set new primary
                $stmt = $conn->prepare("UPDATE user_companies SET is_primary = 1 WHERE user_id = ? AND company_id = ?");
                $stmt->execute([$userId, $companyId]);
                $conn->commit();
                $success = "Primary company updated successfully!";
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error setting primary company: " . $e->getMessage();
            }
        } else {
            $error = "Please select both user and company";
        }
    }
}

// Get all companies
$companies = $conn->query("SELECT id, name, code, business_type FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get all users
$users = $conn->query("SELECT id, username, fullname FROM user ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Get Real Estate companies
$reCompanies = array_filter($companies, fn($c) => $c['business_type'] === 'realestate');

// Get Real Estate roles
$reRoles = $conn->query("SELECT id, name FROM roles WHERE module = 'realestate' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get user-company assignments
$userCompanies = [];
if (!empty($users) && !empty($companies)) {
    $stmt = $conn->query("
        SELECT uc.user_id, uc.company_id, uc.is_primary,
               u.username, c.name as company_name
        FROM user_companies uc
        JOIN user u ON u.id = uc.user_id
        JOIN companies c ON c.id = uc.company_id
        ORDER BY c.name, u.username
    ");
    $userCompanies = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Real Estate Company - <?= h($brand['system_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <i class="bi bi-building"></i> Setup Real Estate Company
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="index.php">Home</a>
                <a class="nav-link" href="settings.php">Settings</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <h1 class="mb-4">Real Estate Company Setup</h1>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?= h($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?= h($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Step 1: Create Real Estate Company -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-building"></i> Step 1: Create Real Estate Company</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="create_company">
                            
                            <div class="mb-3">
                                <label class="form-label">Company Name *</label>
                                <input type="text" class="form-control" name="company_name" required placeholder="e.g., ABC Real Estate">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Company Code (Optional)</label>
                                <input type="text" class="form-control" name="company_code" placeholder="e.g., ABC-RE">
                                <small class="text-muted">Unique code for the company</small>
                            </div>
                            <button type="submit" class="btn btn-primary">Create Company</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Step 2: Create Real Estate Roles -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-person-badge"></i> Step 2: Create Roles</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($reRoles)): ?>
                            <p class="text-muted">No Real Estate roles found. Create them now:</p>
                            <form method="POST">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="create_roles">
                                <button type="submit" class="btn btn-success">Create Real Estate Roles</button>
                            </form>
                        <?php else: ?>
                            <p class="text-success"><strong>Real Estate Roles Available:</strong></p>
                            <ul>
                                <?php foreach ($reRoles as $role): ?>
                                    <li><?= h($role['name']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Step 3: Assign Users to Company -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-people"></i> Step 3: Assign Users to Company</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($reCompanies)): ?>
                            <p class="text-warning">Please create a Real Estate company first (Step 1).</p>
                        <?php else: ?>
                            <form method="POST">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="assign_user">
                                
                                <div class="mb-3">
                                    <label class="form-label">Select User *</label>
                                    <select name="user_id" class="form-select" required>
                                        <option value="">-- Select User --</option>
                                        <?php foreach ($users as $user): ?>
                                            <option value="<?= $user['id'] ?>">
                                                <?= h($user['username']) ?> (<?= h($user['fullname'] ?: 'No name') ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Select Real Estate Company *</label>
                                    <select name="company_id" class="form-select" required>
                                        <option value="">-- Select Company --</option>
                                        <?php foreach ($reCompanies as $company): ?>
                                            <option value="<?= $company['id'] ?>">
                                                <?= h($company['name']) ?> <?= $company['code'] ? '(' . h($company['code']) . ')' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_primary" id="isPrimary">
                                        <label class="form-check-label" for="isPrimary">
                                            Set as Primary Company
                                        </label>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-info">Assign User to Company</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Step 4: Assign Roles to Users -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="bi bi-shield-check"></i> Step 4: Assign Roles to Users</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($reRoles)): ?>
                            <p class="text-warning">Please create Real Estate roles first (Step 2).</p>
                        <?php else: ?>
                            <div class="alert alert-info mb-3">
                                <strong>Important:</strong> Users MUST have a Real Estate role to access the Real Estate module, even if they're assigned to a Real Estate company.
                            </div>
                            <form method="POST">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="assign_role">
                                
                                <div class="mb-3">
                                    <label class="form-label">Select User *</label>
                                    <select name="user_id" class="form-select" required>
                                        <option value="">-- Select User --</option>
                                        <?php foreach ($users as $user): ?>
                                            <option value="<?= $user['id'] ?>">
                                                <?= h($user['username']) ?> (<?= h($user['fullname'] ?: 'No name') ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Select Real Estate Role *</label>
                                    <select name="role_id" class="form-select" required>
                                        <option value="">-- Select Role --</option>
                                        <?php foreach ($reRoles as $role): ?>
                                            <option value="<?= $role['id'] ?>">
                                                <?= h($role['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-warning">Assign Role to User</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Current Assignments -->
        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-list-check"></i> Current User-Company Assignments</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($userCompanies)): ?>
                            <p class="text-muted">No user-company assignments yet.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>User</th>
                                            <th>Company</th>
                                            <th>Primary</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($userCompanies as $uc): ?>
                                            <tr>
                                                <td><?= h($uc['username']) ?></td>
                                                <td><?= h($uc['company_name']) ?></td>
                                                <td>
                                                    <?php if ($uc['is_primary']): ?>
                                                        <span class="badge bg-success">Yes</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">No</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-shield-check"></i> Current User-Role Assignments</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $stmt = $conn->query("
                            SELECT ur.user_id, u.username, r.name as role_name, r.module
                            FROM user_roles ur
                            JOIN user u ON u.id = ur.user_id
                            JOIN roles r ON r.id = ur.role_id
                            WHERE r.module = 'realestate'
                            ORDER BY u.username, r.name
                        ");
                        $userRoles = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        <?php if (empty($userRoles)): ?>
                            <p class="text-warning"><strong>No Real Estate roles assigned yet!</strong><br>
                            <small>Users need Real Estate roles to access the module.</small></p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>User</th>
                                            <th>Role</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($userRoles as $ur): ?>
                                            <tr>
                                                <td><?= h($ur['username']) ?></td>
                                                <td><?= h($ur['role_name']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Set Primary Company -->
        <div class="card mb-4">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="bi bi-star"></i> Set Primary Company</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">Set which company should be the user's primary company (used for auto-redirects).</p>
                <form method="POST">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="set_primary">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Select User *</label>
                            <select name="user_id" class="form-select" required>
                                <option value="">-- Select User --</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['id'] ?>">
                                        <?= h($user['username']) ?> (<?= h($user['fullname'] ?: 'No name') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Select Primary Company *</label>
                            <select name="company_id" class="form-select" required>
                                <option value="">-- Select Company --</option>
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?= $company['id'] ?>">
                                        <?= h($company['name']) ?> (<?= h($company['business_type']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-secondary">Set as Primary Company</button>
                </form>
            </div>
        </div>
        
        <!-- Debug Link -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="bi bi-bug"></i> Debug User Access</h5>
            </div>
            <div class="card-body">
                <p>If a user is having trouble accessing the Real Estate module, use the debug tool to check their access:</p>
                <form method="GET" action="debug_user_access.php" class="d-inline">
                    <div class="input-group">
                        <select name="user_id" class="form-select" required>
                            <option value="">-- Select User to Debug --</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['id'] ?>">
                                    <?= h($user['username']) ?> (<?= h($user['fullname'] ?: 'No name') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-info">Debug User Access</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Quick SQL Reference -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-code-square"></i> Quick SQL Reference</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">If you prefer to use SQL directly, here are the commands:</p>
                <pre class="bg-light p-3 rounded"><code>-- 1. Create Real Estate Company
INSERT INTO companies (name, code, business_type, is_active) 
VALUES ('Your Real Estate Company', 'REC', 'realestate', 1);

-- 2. Create Real Estate Roles
INSERT IGNORE INTO roles (name, module, is_system) VALUES 
('Property Manager', 'realestate', 0),
('Leasing Agent', 'realestate', 0);

-- 3. Assign User to Company (replace user_id and company_id)
INSERT INTO user_companies (user_id, company_id, is_primary) 
VALUES (1, 2, 0);

-- 4. Assign Role to User (replace user_id and role_id)
INSERT INTO user_roles (user_id, role_id) 
VALUES (1, (SELECT id FROM roles WHERE name = 'Property Manager'));</code></pre>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

