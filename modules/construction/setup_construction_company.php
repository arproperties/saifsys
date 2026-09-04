<?php
/**
 * Construction Module — Setup Construction Company
 * Creates a company with business_type = 'construction' for Madar Alwadi Building Contracting
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';

require_login();
// Owner/Admin only
$roles = current_user_roles($conn);
if (!in_array('Owner', $roles, true) && !in_array('Admin', $roles, true)) {
    die('Access denied. Owner or Admin only.');
}

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? 'Madar Alwadi Building Contracting');
    $code = trim($_POST['code'] ?? 'MABC');
    
    if (!$name || !$code) {
        $err = 'Company name and code are required.';
    } else {
        $chk = $conn->prepare("SELECT 1 FROM companies WHERE code = ?");
        $chk->execute([$code]);
        if ($chk->fetch()) {
            $err = 'Company code already exists.';
        } else {
            $stmt = $conn->prepare("INSERT INTO companies (name, code, business_type, is_active) VALUES (?, ?, 'construction', 1)");
            $stmt->execute([$name, $code]);
            $msg = 'Construction company created successfully. Go to Settings → Companies to assign users.';
        }
    }
}

$constructionCompanies = $conn->query("SELECT id, name, code FROM companies WHERE business_type = 'construction' AND is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Construction Company</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <h1 class="h3 mb-4">Setup Construction Company</h1>
    
    <?php if ($msg): ?>
    <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if ($err): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <?php if (!empty($constructionCompanies)): ?>
    <div class="alert alert-info">
        <strong>Existing Construction Companies:</strong>
        <?php foreach ($constructionCompanies as $c): ?>
        <span class="badge bg-primary me-1"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['code']) ?>)</span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="post" class="card">
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Company Name *</label>
                <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($_POST['name'] ?? 'Madar Alwadi Building Contracting') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Company Code *</label>
                <input type="text" name="code" class="form-control" required value="<?= htmlspecialchars($_POST['code'] ?? 'MABC') ?>">
            </div>
            <button type="submit" class="btn btn-primary">Create Construction Company</button>
        </div>
    </form>

    <p class="mt-4 text-muted">
        After creating the company, go to <a href="<?= (strpos($_SERVER['PHP_SELF'], 'herosysgro') !== false ? '/herosysgro' : '') ?>/settings?tab=companies">Settings → Companies</a> 
        to assign users. Assign construction departments to roles in Settings → Departments.
    </p>
</div>
</body>
</html>
