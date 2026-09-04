<?php
/**
 * Mobile App Admin Dashboard
 * Manage categories, services, banners, and pricing for the mobile booking app
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';

// Check if user is logged in
require_login('login.php');

// Check if user has admin role (adjust role name if needed)
// If your system uses different role names, update this line
$currentUser = current_user();
if (!$currentUser) {
    header('Location: login');
    exit;
}

// Optional: Uncomment if you want to restrict to specific roles
// require_role(['admin', 'manager'], $conn);

$page = $_GET['page'] ?? 'categories';
$action = $_GET['action'] ?? 'list';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mobile App Admin - HeroSys</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 12px 20px;
            border-radius: 8px;
            margin: 5px 0;
            transition: all 0.3s;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background-color: rgba(255, 255, 255, 0.2);
            color: white;
        }
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px 12px 0 0 !important;
            padding: 20px;
        }
        .btn-gradient {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
        }
        .btn-gradient:hover {
            opacity: 0.9;
            color: white;
        }
        .badge-category {
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 500;
        }
        .stats-card {
            border-left: 4px solid #667eea;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 sidebar p-3">
                <div class="text-center mb-4">
                    <h4 class="mt-3"><i class="fas fa-mobile-alt"></i> Mobile App</h4>
                    <small>Admin Dashboard</small>
                </div>
                
                <nav class="nav flex-column">
                    <a class="nav-link <?= $page === 'users' ? 'active' : '' ?>" href="?page=users">
                        <i class="fas fa-users"></i> Mobile Users
                    </a>
                    <a class="nav-link <?= $page === 'wallet' ? 'active' : '' ?>" href="?page=wallet">
                        <i class="fas fa-wallet"></i> Wallet Management
                    </a>
                    <a class="nav-link <?= $page === 'categories' ? 'active' : '' ?>" href="?page=categories">
                        <i class="fas fa-folder"></i> Categories
                    </a>
                    <a class="nav-link <?= $page === 'services' ? 'active' : '' ?>" href="?page=services">
                        <i class="fas fa-concierge-bell"></i> Services
                    </a>
                    <a class="nav-link <?= $page === 'banners' ? 'active' : '' ?>" href="?page=banners">
                        <i class="fas fa-image"></i> Banners
                    </a>
                    <a class="nav-link <?= $page === 'pricing' ? 'active' : '' ?>" href="?page=pricing">
                        <i class="fas fa-dollar-sign"></i> Pricing Rules
                    </a>
                    <a class="nav-link <?= $page === 'frequency' ? 'active' : '' ?>" href="?page=frequency">
                        <i class="fas fa-redo"></i> Frequency Discounts
                    </a>
                    <a class="nav-link <?= $page === 'discounts' ? 'active' : '' ?>" href="?page=discounts">
                        <i class="fas fa-percent"></i> Discounts
                    </a>
                    <a class="nav-link <?= $page === 'coupons' ? 'active' : '' ?>" href="?page=coupons">
                        <i class="fas fa-ticket-alt"></i> Coupons
                    </a>
                    <a class="nav-link <?= $page === 'settings' ? 'active' : '' ?>" href="?page=settings">
                        <i class="fas fa-cog"></i> Settings
                    </a>
                    <hr class="my-3" style="opacity: 0.3;">
                    <a class="nav-link" href="account.php">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </nav>
            </div>

            <!-- Main Content -->
            <div class="col-md-10 p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2><i class="fas fa-cog"></i> Mobile App Management</h2>
                        <small class="text-muted">
                            Logged in as: <strong><?= htmlspecialchars($currentUser['username'] ?? $currentUser['email'] ?? 'User') ?></strong>
                        </small>
                    </div>
                    <a href="account.php" class="btn btn-outline-secondary">
                        <i class="fas fa-times"></i> Close
                    </a>
                </div>

                <?php
                // Include the appropriate page content
                switch ($page) {
                    case 'users':
                        include 'includes/mobile_admin/users.php';
                        break;
                    case 'wallet':
                        include 'includes/mobile_admin/wallet.php';
                        break;
                    case 'categories':
                        include 'includes/mobile_admin/categories.php';
                        break;
                    case 'services':
                        include 'includes/mobile_admin/services.php';
                        break;
                    case 'banners':
                        include 'includes/mobile_admin/banners.php';
                        break;
                    case 'pricing':
                        include 'includes/mobile_admin/pricing.php';
                        break;
                    case 'frequency':
                        include 'includes/mobile_admin/frequency_discounts.php';
                        break;
                    case 'discounts':
                        include 'includes/mobile_admin/discounts.php';
                        break;
                    case 'coupons':
                        include 'includes/mobile_admin/coupons.php';
                        break;
                    case 'settings':
                        include 'includes/mobile_admin/settings.php';
                        break;
                    default:
                        echo '<div class="alert alert-warning">Page not found</div>';
                }
                ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize Bootstrap tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            var alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(function(alert) {
                var bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>
</body>
</html>

