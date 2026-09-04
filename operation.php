<?php
// operation.php — Operation hub with upgraded sidebar + login guard
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/branding.php';
require_once __DIR__ . '/includes/url_helper.php';
require_once __DIR__ . '/includes/company_helper.php';
require_once __DIR__ . '/includes/module_access.php';
require_once __DIR__ . '/includes/rbac_department.php';

require_login();
// Check department access (backward compatible: fallback to module access)
if (!has_department_access(MODULE_CLEANING, DEPT_CLEANING_OPERATIONS, $conn)) {
    require_module_access($conn, MODULE_CLEANING);
}

// Get current company context
$currentCompanyId = current_company_id($conn);
if (!$currentCompanyId) {
    // Fallback to user's default company
    $userCompanies = get_user_companies($conn, current_user_id());
    if (!empty($userCompanies)) {
        // For operations module, prefer cleaning company
        $cleaningCompany = null;
        $primaryCompany = null;
        
        foreach ($userCompanies as $company) {
            if ($company['business_type'] === 'cleaning' && !$cleaningCompany) {
                $cleaningCompany = $company;
            }
            if (!empty($company['is_primary']) && !$primaryCompany) {
                $primaryCompany = $company;
            }
        }
        
        // Prefer cleaning company for operations module
        if ($cleaningCompany) {
            $currentCompanyId = $cleaningCompany['id'];
        } elseif ($primaryCompany) {
            $currentCompanyId = $primaryCompany['id'];
        } else {
            $currentCompanyId = $userCompanies[0]['id'];
        }
        
        set_current_company($currentCompanyId);
    } else {
        die('No company access available');
    }
} else {
    // Verify the company is a cleaning company (for operations module)
    $company = get_company($conn, $currentCompanyId);
    if ($company && $company['business_type'] !== 'cleaning') {
        // Wrong company type, find cleaning company
        $userCompanies = get_user_companies($conn, current_user_id());
        foreach ($userCompanies as $comp) {
            if ($comp['business_type'] === 'cleaning') {
                $currentCompanyId = $comp['id'];
                set_current_company($currentCompanyId);
                break;
            }
        }
    }
}

// Get branding settings
$brand = getBrandSettings($conn);

/* ---- LOGIN GUARD ---- */
$hasUserObject = !empty($_SESSION['user']) && is_array($_SESSION['user']);
$hasLegacy     = !empty($_SESSION['username']) || !empty($_SESSION['fullname']);
if (!$hasUserObject && !$hasLegacy) {
  // Store next URL in session for clean URLs
  $_SESSION['login_next'] = preg_replace('/\.php$/', '', $_SERVER['REQUEST_URI'] ?? '/');
  header('Location: login');
  exit;
}

/* ---- CURRENT USER (normalize fields) ---- */
$U = $hasUserObject ? $_SESSION['user'] : [];
$fullName = $U['full_name'] ?? $U['fullname'] ?? ($_SESSION['fullname'] ?? 'User');
$userName = $U['username']  ?? ($_SESSION['username'] ?? '');
$avatarInitial = strtoupper(substr(trim($fullName ?: $userName ?: 'U'), 0, 1));
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---- Tabs - Support both GET (backward compatibility) and session ---- */
// Handle tab selection via POST (for clean URLs) or GET (backward compatibility)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tab'])) {
    $_SESSION['operation_tab'] = $_POST['tab'];
    $tab = $_POST['tab'];
} else {
    $tab = $_GET['tab'] ?? $_SESSION['operation_tab'] ?? 'workorder';
    if (isset($_GET['tab'])) {
        $_SESSION['operation_tab'] = $tab;
    }
}
$tabs = [
  'workorder'     => 'Work Order',
  'cancellations' => 'Cancellations',
  'clients'       => 'Clients',
  'ladies'        => 'Ladies',
  //'dailyschedule' => 'Daily Schedule',
  // 'mobilebooking' => 'Mobile Booking',
];
$header = $tabs[$tab] ?? 'Work Order';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>Operation | <?= h($brand['system_name']) ?></title>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <?php require __DIR__ . '/operation/includes/cleaning_ui_styles.php'; ?>
  <style>.nav-pills .nav-link.active{ background-color:var(--primary)!important; }</style>
</head>
<body<?= $brand['dark_mode_enabled'] ? ' class="dark-mode"' : '' ?>>
<div class="d-flex">
  <?php $activeNav = 'operation'; require __DIR__ . '/operation/includes/cleaning_ui_sidebar.php'; ?>

  <!-- Main content -->
  <div class="flex-grow-1">
    <!-- Top bar -->
    <nav class="navbar navbar-expand navbar-light bg-white shadow-sm">
      <div class="container-fluid">
        <span class="navbar-brand fw-bold" style="color:var(--primary)"><?= h($brand['system_name']) ?></span>
        <div class="dropdown ms-auto">
          <a href="#" class="d-flex align-items-center text-dark text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
            <div class="avatar me-2"><?= h($avatarInitial) ?></div>
            <span class="me-2"><?= h($fullName) ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end shadow">
            <li><span class="dropdown-item-text"><strong><?= h($userName) ?></strong></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="/profile"><i class="bi bi-person-circle me-2"></i>Edit/Update</a></li>
            <li><a class="dropdown-item text-danger" href="/logout"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
          </ul>
        </div>
      </div>
    </nav>

    <!-- Tabs + content -->
    <div class="container-fluid px-3 px-lg-4 py-4">
      <div class="d-flex align-items-center mb-3">
        <div class="page-header-label flex-grow-1"><?= h($header) ?></div>
        <ul class="nav nav-pills">
          <li class="nav-item"><a class="nav-link <?= $tab==='workorder'?'active':'' ?>"     href="/operation" data-tab="workorder">Work Order</a></li>
          <li class="nav-item">
             <a class="nav-link" href="/operation/worker_availability">Worker Availability</a></li>
          <li class="nav-item"><a class="nav-link <?= $tab==='cancellations'?'active':'' ?>" href="/operation" data-tab="cancellations">Cancellations</a></li>
          <li class="nav-item"><a class="nav-link <?= $tab==='clients'?'active':'' ?>"       href="/operation" data-tab="clients">Clients</a></li>
          <li class="nav-item"><a class="nav-link <?= $tab==='ladies'?'active':'' ?>"        href="/operation" data-tab="ladies">Ladies</a></li>
        <!-- <li class="nav-item"><a class="nav-link <?= $tab==='dailyschedule'?'active':'' ?>" href="operation" data-tab="dailyschedule">Daily Schedule</a></li>
         <li class="nav-item"><a class="nav-link <?= $tab==='mobilebooking'?'active':'' ?>" href="operation" data-tab="mobilebooking">Mobile Booking</a></li> -->
        </ul>
      </div>

      <div class="card card-round p-3 p-lg-4">
        <?php
       // $tab = $_GET['tab'] ?? 'workorder';  // default to workorder if you wish
        switch ($tab) {
          case 'workorder':
            include __DIR__ . '/operation/workorder_list.php';
            break;
        
            case 'cancellations': @include __DIR__.'/operation/cancellations.php'; break;
            case 'clients':       @include __DIR__.'/operation/clients.php'; break;
            case 'ladies':        @include __DIR__.'/operation/ladies.php'; break;
            case 'dailyschedule': @include __DIR__.'/operation/dailyschedule.php'; break;
            case 'mobilebooking': @include __DIR__.'/operation/mobilebooking.php'; break;
            default: echo "<p>Unknown module.</p>";
          }
        ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Sidebar collapse toggle (same behaviour as home)
  const sb = document.getElementById('sb');
  const t  = document.getElementById('sbToggle');
  t.addEventListener('click', () => {
    sb.classList.toggle('collapsed');
    t.querySelector('i').classList.toggle('bi-chevron-right');
    t.querySelector('i').classList.toggle('bi-chevron-left');
  });

  // Update URL to clean format without parameters and .php extension (run immediately)
  (function() {
    if (window.history && window.history.replaceState) {
      let cleanUrl = window.location.pathname.replace(/\.php$/, '');
      // Remove trailing slash if present (except for root)
      if (cleanUrl.endsWith('/') && cleanUrl !== '/') {
        cleanUrl = cleanUrl.slice(0, -1);
      }
      // Always update if URL contains .php or has query parameters
      if (window.location.search || window.location.pathname.endsWith('.php') || window.location.pathname !== cleanUrl) {
        window.history.replaceState({}, document.title, cleanUrl);
      }
    }
  })();

  // Clean URL navigation for tabs - run after DOM is ready
  (function() {
    function setupTabNavigation() {
      console.log('[Operation] Setting up tab navigation');
      const tabLinks = document.querySelectorAll('a[data-tab]');
      console.log('[Operation] Found', tabLinks.length, 'tab links:', Array.from(tabLinks).map(l => l.getAttribute('data-tab')));
      
      tabLinks.forEach(link => {
        const tab = link.getAttribute('data-tab');
        console.log('[Operation] Setting up handler for tab:', tab, 'href:', link.getAttribute('href'));
        
        link.addEventListener('click', function(e) {
          console.log('[Operation] Tab clicked:', tab);
          e.preventDefault();
          e.stopImmediatePropagation();
          e.stopPropagation();
          
          // Try simple navigation first - if that doesn't work, use form
          const href = this.getAttribute('href') || '/operation';
          console.log('[Operation] Navigating to:', href + '?tab=' + tab);
          
          // Use GET parameter for simplicity - operation.php handles both GET and POST
          window.location.href = href + '?tab=' + encodeURIComponent(tab);
        }, true); // Capture phase
      });
    }
    
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', setupTabNavigation);
    } else {
      setupTabNavigation();
    }
  })();
</script>
</body>
</html>
