<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/company_helper.php';

// Get current company context
$currentCompanyId = current_company_id($conn);
if (!$currentCompanyId) {
    // If no company set, get user's primary company
    $userId = current_user_id();
    if ($userId) {
        $userCompanies = get_user_companies($conn, $userId);
        if (!empty($userCompanies)) {
            // Find primary company, or first company that matches cleaning business type
            $primaryCompany = null;
            $cleaningCompany = null;
            
            foreach ($userCompanies as $company) {
                // Prefer primary company
                if (!empty($company['is_primary']) && !$primaryCompany) {
                    $primaryCompany = $company;
                }
                // Also find cleaning company (for operations module)
                if ($company['business_type'] === 'cleaning' && !$cleaningCompany) {
                    $cleaningCompany = $company;
                }
            }
            
            // Use cleaning company if found, otherwise primary, otherwise first
            if ($cleaningCompany) {
                $currentCompanyId = $cleaningCompany['id'];
            } elseif ($primaryCompany) {
                $currentCompanyId = $primaryCompany['id'];
            } else {
                $currentCompanyId = $userCompanies[0]['id'];
            }
            
            set_current_company($currentCompanyId);
        }
    }
}
// Fallback to 1 if still no company (for backward compatibility)
if (!$currentCompanyId) {
    $currentCompanyId = 1;
}

// Fallback to 1 if still no company (for backward compatibility)
if (!$currentCompanyId) {
    $currentCompanyId = 1;
}

require_once __DIR__ . '/includes/clients_page_helpers.php';

function safe($s){ return clients_page_safe($s); }
function money2($n){ return clients_page_money2($n); }
function money($n){ return clients_page_money($n); }
function getHealthScoreClass($score){ return clients_page_health_class($score); }

$client_id = (int)($_GET['client_id'] ?? 0);
$from      = $_GET['from'] ?? '';
$to        = $_GET['to']   ?? '';

// Lightweight sidebar — no heavy per-client aggregates on full table scan
$clients = clients_fetch_sidebar_list($conn, (int)$currentCompanyId, ['limit' => 60]);

// Panel detail is loaded via AJAX (operation/ajax_client_panel.php)
$client = null;
$orders = $invoices = $payments = [];
$available_credit = $unapplied_receipts = $open_invoices = 0;
$total_hours = $orders_amount = $sum_total = $sum_paid = $sum_open = 0.0;
$pending_invoicing = $invoiced_balance = 0.0;
$last_payment = null;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Clients | Operation</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
  <style>
    body{background:#f6f8fb;font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}
    
    /* Modern panels and cards */
    .panel{background:#fff;border-radius:16px;box-shadow:0 8px 30px #00000012;transition:all 0.3s ease}
    .panel:hover{box-shadow:0 12px 40px #00000015}
    
    /* Enhanced sidebar */
    .sidebar .list-group-item{
      border:0;border-radius:12px;margin-bottom:8px;padding:12px 16px;
      transition:all 0.2s ease;cursor:pointer
    }
    .sidebar .list-group-item:hover{background:#f8f9fa;transform:translateX(4px)}
    .sidebar .list-group-item.active{background:linear-gradient(135deg,#0d6efd,#0b5ed7);color:#fff;box-shadow:0 4px 15px rgba(13,110,253,0.3)}
    
    /* Client status indicators */
    .client-status{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:8px}
    .client-status.active{background:#20c997}
    .client-status.inactive{background:#6c757d}
    .client-status.vip{background:#ffc107}
    .client-status.at-risk{background:#dc3545}
    
    /* Health score badge */
    .health-score{font-size:0.75rem;padding:2px 8px;border-radius:12px;font-weight:600}
    .health-score.excellent{background:#d1e7dd;color:#0f5132}
    .health-score.good{background:#d4edda;color:#0a3622}
    .health-score.fair{background:#fff3cd;color:#664d03}
    .health-score.poor{background:#f8d7da;color:#58151c}
    
    /* Modern KPI cards */
    .kpi{border-radius:16px;padding:20px;color:#fff;position:relative;overflow:hidden;transition:all 0.3s ease}
    .kpi:hover{transform:translateY(-2px);box-shadow:0 8px 25px rgba(0,0,0,0.15)}
    .kpi::before{content:'';position:absolute;top:0;right:0;width:100px;height:100px;background:rgba(255,255,255,0.1);border-radius:50%;transform:translate(30px,-30px)}
    .kpi .val{font-size:28px;font-weight:800;margin-bottom:4px}
    .kpi .subtitle{font-size:0.85rem;opacity:0.9}
    .kpi .trend{font-size:0.75rem;margin-top:8px;display:flex;align-items:center;gap:4px}
    .kpi .trend.positive{color:#20c997}
    .kpi .trend.negative{color:#dc3545}
    
    .kpi.primary{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%)}
    .kpi.success{background:linear-gradient(135deg,#20c997 0%,#17a2b8 100%)}
    .kpi.warning{background:linear-gradient(135deg,#fd7e14 0%,#e83e8c 100%)}
    .kpi.info{background:linear-gradient(135deg,#17a2b8 0%,#6f42c1 100%)}
    .kpi.danger{background:linear-gradient(135deg,#dc3545 0%,#6f42c1 100%)}
    .kpi.muted{background:linear-gradient(135deg,#6c757d 0%,#495057 100%)}
    
    /* Enhanced tags */
    .tag{padding:4px 12px;border-radius:20px;font-size:0.75rem;font-weight:500;display:inline-flex;align-items:center;gap:4px}
    .tag.status{text-transform:uppercase;letter-spacing:0.5px}
    
    /* Modern table */
    .table{background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,0.08)}
    .table>thead th{background:#f8f9fa;border:0;padding:16px 12px;font-weight:600;color:#495057;white-space:nowrap}
    .table>tbody td{padding:16px 12px;border-top:1px solid #f1f3f4;vertical-align:middle}
    .table>tbody tr:hover{background:#f8f9fa}
    
    /* Enhanced buttons */
    .btn-outline-primary-soft{border-color:#0d6efd33;color:#0d6efd;background:#0d6efd0d;border-radius:8px;padding:8px 16px;transition:all 0.2s ease}
    .btn-outline-primary-soft:hover{background:#0d6efd;color:#fff;transform:translateY(-1px)}
    
    /* Advanced filter panel */
    .filter-panel{background:#f8f9fa;border-radius:12px;padding:20px;margin-bottom:24px;border:1px solid #e9ecef}
    .filter-chip{display:inline-block;padding:6px 14px;background:#fff;border-radius:20px;margin:4px;border:1px solid #dee2e6;font-size:0.85rem;cursor:pointer;transition:all 0.2s ease;color:#000 !important;visibility:visible !important;opacity:1 !important}
    .filter-chip:hover{background:#e9ecef;transform:translateY(-1px);color:#000 !important}
    .filter-chip.active{background:#0d6efd;color:#fff !important;border-color:#0d6efd}
    
    /* Activity timeline */
    .timeline-item{position:relative;padding-left:40px;padding-bottom:24px}
    .timeline-item::before{content:'';position:absolute;left:12px;top:0;bottom:-24px;width:2px;background:#e9ecef}
    .timeline-item:last-child::before{display:none}
    .timeline-icon{position:absolute;left:0;width:24px;height:24px;border-radius:50%;color:white;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600}
    .timeline-content{background:#fff;border-radius:8px;padding:12px 16px;box-shadow:0 2px 8px rgba(0,0,0,0.08)}
    
    /* Chart containers */
    .chart-container{background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 10px rgba(0,0,0,0.08);margin-bottom:24px;position:relative;overflow:hidden}
    .chart-title{font-size:1.1rem;font-weight:600;margin-bottom:16px;color:#495057}
    
    /* Chart container sizing */
    .chart-container{min-height:300px;max-height:400px}
    .chart-container canvas{max-width:100% !important}
    
    /* Insights container - no height restrictions */
    .insights-container{background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 10px rgba(0,0,0,0.08);margin-bottom:24px;position:relative;overflow:visible;min-height:auto}
    .insights-container .chart-title{font-size:1.1rem;font-weight:600;margin-bottom:16px;color:#495057}
    
    /* Custom insight cards styling */
    .insight-card{margin-bottom:16px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);overflow:visible;min-height:auto;height:auto;padding:16px;border-left:4px solid}
    .insight-card:last-child{margin-bottom:0}
    .insight-card.insight-success{border-left-color:#20c997;background:linear-gradient(135deg,#d1e7dd 0%,#f8f9fa 100%)}
    .insight-card.insight-warning{border-left-color:#ffc107;background:linear-gradient(135deg,#fff3cd 0%,#f8f9fa 100%)}
    .insight-card.insight-danger{border-left-color:#dc3545;background:linear-gradient(135deg,#f8d7da 0%,#f8f9fa 100%)}
    .insight-card.insight-info{border-left-color:#17a2b8;background:linear-gradient(135deg,#d1ecf1 0%,#f8f9fa 100%)}
    .insight-card.insight-secondary{border-left-color:#6c757d;background:linear-gradient(135deg,#e2e3e5 0%,#f8f9fa 100%)}
    
    .insight-title{font-size:1rem;font-weight:600;margin-bottom:8px;color:#495057}
    .insight-message{margin-bottom:8px;line-height:1.5;color:#6c757d}
    .insight-action{font-size:0.875rem;color:#495057;margin-top:8px}
    .insight-card .d-flex{align-items:flex-start !important}
    .insight-card .flex-grow-1{flex:1 1 auto;min-width:0}
    
    /* Ensure tab content can expand */
    .tab-content{overflow:visible}
    .tab-pane{overflow:visible}
    
    /* Ensure main containers can expand */
    .panel{overflow:visible}
    .container-fluid{overflow:visible}
    .row{overflow:visible}
    .col-12{overflow:visible}
    
    /* Bulk actions */
    .bulk-actions{background:#fff;border-radius:12px;padding:16px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,0.08);display:none}
    .bulk-actions.show{display:block;animation:slideDown 0.3s ease}
    
    /* Mobile responsive */
    @media (max-width: 768px) {
      .sidebar{position:fixed;left:-280px;top:0;height:100vh;z-index:1050;transition:left 0.3s ease;width:280px;background:#fff;box-shadow:2px 0 10px rgba(0,0,0,0.1)}
      .sidebar.show{left:0}
      .sidebar-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1040;display:none}
      .sidebar-overlay.show{display:block}
      
      /* Mobile optimizations */
      .kpi{margin-bottom:12px;padding:15px}
      .kpi .val{font-size:20px}
      .table{font-size:0.85rem}
      .panel{border-radius:12px}
      .filter-panel{padding:12px}
      
      /* Touch-friendly buttons */
      .btn{min-height:44px;padding:0.5rem 1rem}
      .btn-sm{min-height:36px}
      
      /* Swipe indicators */
      .list-group-item{position:relative}
      .list-group-item.swipe-left{transform:translateX(-80px);transition:transform 0.3s ease}
      .list-group-item.swipe-right{transform:translateX(80px);transition:transform 0.3s ease}
      
      /* Mobile menu toggle */
      .mobile-menu-toggle{
        position:fixed;top:16px;left:16px;z-index:1060;
        background:#0d6efd;color:#fff;width:44px;height:44px;
        border-radius:50%;border:none;box-shadow:0 4px 12px rgba(0,0,0,0.15);
        display:flex;align-items:center;justify-content:center;
      }
      
      /* Responsive charts */
      canvas{max-width:100%;height:auto !important}
      .chart-container{padding:12px}
      
      /* Mobile tabs */
      .nav-tabs{flex-wrap:nowrap;overflow-x:auto;-webkit-overflow-scrolling:touch}
      .nav-tabs .nav-link{white-space:nowrap;font-size:0.85rem;padding:0.5rem 0.75rem}
    }
    
    /* Performance optimizations */
    .lazy-load{opacity:0;transition:opacity 0.3s ease}
    .lazy-load.loaded{opacity:1}
    
    /* Pull to refresh indicator */
    .ptr-indicator{
      text-align:center;padding:20px;display:none;
      color:#6c757d;font-size:0.9rem;
    }
    .ptr-indicator.active{display:block}
    
    /* Touch action optimizations */
    .list-group-item,
    .card,
    .btn{
      -webkit-tap-highlight-color:transparent;
      touch-action:manipulation;
    }
    
    /* Animations */
    @keyframes slideDown{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}
    @keyframes fadeIn{from{opacity:0}to{opacity:1}}
    .fade-in{animation:fadeIn 0.3s ease}
    
    /* Toast notifications */
    .toast.success{background:#198754;color:#fff}
    .toast.error{background:#dc3545;color:#fff}
    .toast.info{background:#0d6efd;color:#fff}
    .toast.warn{background:#ffc107;color:#212529}
    
    /* Dropdown fixes */
    .dropdown-menu{display:none;position:absolute;z-index:1000;min-width:160px;padding:0.5rem 0;margin:0;font-size:0.875rem;color:#212529;text-align:left;background-color:#fff;background-clip:padding-box;border:1px solid rgba(0,0,0,0.15);border-radius:0.375rem;box-shadow:0 0.5rem 1rem rgba(0,0,0,0.175)}
    .dropdown-menu.show{display:block}
    .dropdown-item{display:block;width:100%;padding:0.25rem 1rem;clear:both;font-weight:400;color:#212529;text-align:inherit;text-decoration:none;white-space:nowrap;background-color:transparent;border:0}
    .dropdown-item:hover{color:#1e2125;background-color:#f8f9fa}
    .dropdown-item.text-danger:hover{color:#fff;background-color:#dc3545}
    .dropdown-divider{height:0;margin:0.5rem 0;overflow:hidden;border-top:1px solid #dee2e6}
    
    /* Loading states */
    .loading{opacity:0.6;pointer-events:none}
    .spinner-border-sm{width:1rem;height:1rem}
  </style>
</head>
<body>
<div class="container-fluid py-4">
  <div class="row g-4">
    <!-- Sidebar -->
    <div class="col-xl-3 col-lg-4">
      <div class="panel p-3 sidebar">
        <div class="d-flex align-items-center mb-2">
          <h5 class="mb-0">Clients</h5>
          <button class="btn btn-sm btn-primary ms-auto" data-bs-toggle="modal" data-bs-target="#addClientModal">
            <i class="bi bi-plus-lg"></i> Add
          </button>
        </div>
        <input id="clientFilter" class="form-control form-control-sm mb-2" placeholder="Search name or phone">
        
        <!-- Advanced Filters -->
        <div class="filter-panel" id="advancedFilters" style="display:none;">
          <div class="row g-2 mb-3">
            <div class="col-md-6">
              <label class="form-label small">Status</label>
              <select id="filterStatus" class="form-select form-select-sm">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="vip">VIP</option>
                <option value="at_risk">At Risk</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small">Payment Terms</label>
              <select id="filterTerms" class="form-select form-select-sm">
                <option value="">All Terms</option>
                <option value="cash">Cash</option>
                <option value="prepaid">Prepaid</option>
                <option value="15d">15 days</option>
                <option value="30d">30 days</option>
                <option value="45d">45 days</option>
                <option value="60d">60 days</option>
              </select>
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-md-6">
              <label class="form-label small">Outstanding Balance</label>
              <select id="filterBalance" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="0">No Outstanding</option>
                <option value="1+">Any Outstanding</option>
                <option value="1-1000">AED 1 - 1,000</option>
                <option value="1000-5000">AED 1,000 - 5,000</option>
                <option value="5000+">AED 5,000+</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small">Last Order</label>
              <select id="filterLastOrder" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="7">Last 7 days</option>
                <option value="30">Last 30 days</option>
                <option value="90">Last 90 days</option>
                <option value="365">Last year</option>
                <option value="never">Never ordered</option>
              </select>
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-md-6">
              <label class="form-label small">Health Score</label>
              <select id="filterHealth" class="form-select form-select-sm">
                <option value="">All Scores</option>
                <option value="80-100">Excellent (80-100)</option>
                <option value="60-79">Good (60-79)</option>
                <option value="40-59">Fair (40-59)</option>
                <option value="0-39">Poor (0-39)</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small">Credit Limit</label>
              <select id="filterCredit" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="0">No Limit</option>
                <option value="1-5000">AED 1 - 5,000</option>
                <option value="5000-25000">AED 5,000 - 25,000</option>
                <option value="25000+">AED 25,000+</option>
              </select>
            </div>
          </div>
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <button class="btn btn-sm btn-outline-primary" onclick="applyFilters()">
                <i class="bi bi-funnel"></i> Apply Filters
              </button>
              <button class="btn btn-sm btn-outline-secondary" onclick="clearFilters()">
                <i class="bi bi-x-circle"></i> Clear
              </button>
            </div>
            <div>
              <button class="btn btn-sm btn-outline-success" onclick="saveFilterPreset()">
                <i class="bi bi-bookmark"></i> Save Preset
              </button>
            </div>
          </div>
        </div>
        
        <!-- Filter Presets -->
        <div class="mb-3">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <small class="text-muted">Quick Filters</small>
            <button class="btn btn-sm btn-link p-0" onclick="toggleAdvancedFilters()">
              <i class="bi bi-sliders"></i> Advanced
            </button>
          </div>
          <div class="d-flex flex-wrap gap-1">
            <span class="filter-chip active" data-preset="all" onclick="applyFilterPreset('all')">All Clients</span>
            <span class="filter-chip" data-preset="vip" onclick="applyFilterPreset('vip')">VIP Clients</span>
            <span class="filter-chip" data-preset="outstanding" onclick="applyFilterPreset('outstanding')">Outstanding</span>
            <span class="filter-chip" data-preset="inactive" onclick="applyFilterPreset('inactive')">Inactive</span>
            <span class="filter-chip" data-preset="at_risk" onclick="applyFilterPreset('at_risk')">At Risk</span>
          </div>
        </div>
        <div class="list-group" id="clientList" style="max-height:66vh;overflow:auto">
          <?php foreach ($clients as $c): echo clients_render_sidebar_item($c, $client_id); endforeach; ?>
        </div>
        <div id="clientListStatus" class="small text-muted text-center py-2"></div>
      </div>
    </div>

    <!-- Main -->
    <div class="col-xl-9 col-lg-8">
      <div class="panel p-0">
        <div id="clientDetailPanel" class="client-detail-panel">
          <div class="p-5 text-center text-muted" id="clientDetailPlaceholder">
            <i class="bi bi-person-circle" style="font-size:64px"></i>
            <div class="mt-2">Select a client to view details.</div>
          </div>
        </div>
      </div> <!-- /.panel -->
    </div> <!-- /.col -->
  </div> <!-- /.row -->
</div> <!-- /.container-fluid -->

<!-- Add Client Modal -->
<div class="modal fade" id="addClientModal" tabindex="-1" aria-labelledby="addClientModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form id="add-client-form" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addClientModalLabel">Add New Client</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <!-- Core -->
        <div class="mb-2">
          <label class="form-label">Client Name <span class="text-danger">*</span></label>
          <input type="text" name="client_name" class="form-control" required>
        </div>
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">Email <span class="text-danger">*</span></label>
            <input type="email" name="email" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Mobile Number <span class="text-danger">*</span></label>
            <input type="text" name="mobile_num" class="form-control" required>
          </div>
        </div>

        <!-- Ops cadence -->
        <div class="mb-2">
          <label class="form-label">Payment Type (Ops cadence) <span class="text-danger">*</span></label>
          <select name="payment" class="form-select" required>
            <option value="">-- Choose --</option>
            <option value="D">D (Daily)</option>
            <option value="W">W (Weekly)</option>
            <option value="Bi-W">Bi-W (Bi-Weekly)</option>
            <option value="M">M (Monthly)</option>
          </select>
        </div>

        <!-- AR terms & finance -->
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">AR Terms</label>
            <select name="terms" class="form-select">
              <option value="cash">Cash</option>
              <option value="prepaid">Prepaid</option>
              <option value="15d">15 days</option>
              <option value="30d" selected>30 days</option>
              <option value="45d">45 days</option>
              <option value="60d">60 days</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Default VAT %</label>
            <input type="number" step="0.01" name="default_vat_rate" class="form-control" value="5.00">
          </div>
          <div class="col-md-3">
            <label class="form-label">Credit Limit (AED)</label>
            <input type="number" step="0.01" name="credit_limit" class="form-control" placeholder="0.00">
          </div>
        </div>

        <!-- Extras -->
        <div class="row g-2 mt-1">
          <div class="col-md-6">
            <label class="form-label">Landline</label>
            <input type="text" name="cell_num" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">Default Hourly Rate (AED)</label>
            <input type="number" step="0.01" name="rate" class="form-control">
          </div>
        </div>
        <div class="mb-2 mt-1">
          <label class="form-label">Address <span class="text-danger">*</span></label>
          <input type="text" name="address" class="form-control" required>
        </div>
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">TRN Number</label>
            <input type="text" name="trn" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">Opening Balance</label>
            <input type="number" name="balance" step="0.01" class="form-control" placeholder="0.00">
          </div>
        </div>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" id="key_le" name="key_le" value="Key">
          <label class="form-check-label" for="key_le">Leave key with us</label>
        </div>

        <div id="add-client-error" class="text-danger mt-2" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-success">Save Client</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Client Modal -->
<div class="modal fade" id="editClientModal" tabindex="-1" aria-labelledby="editClientModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form id="edit-client-form" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editClientModalLabel">Edit Client</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" id="edit-client-id">
        <div class="mb-2">
          <label class="form-label">Client Name <span class="text-danger">*</span></label>
          <input type="text" name="client_name" id="edit-client-name" class="form-control" required>
        </div>
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">Email <span class="text-danger">*</span></label>
            <input type="email" name="email" id="edit-client-email" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Mobile Number <span class="text-danger">*</span></label>
            <input type="text" name="mobile_num" id="edit-client-mobile" class="form-control" required>
          </div>
        </div>

        <div class="mb-2">
          <label class="form-label">Payment Type (Ops cadence) <span class="text-danger">*</span></label>
          <select name="payment" id="edit-client-payment" class="form-select" required>
            <option value="">-- Choose --</option>
            <option value="D">D (Daily)</option>
            <option value="W">W (Weekly)</option>
            <option value="Bi-W">Bi-W (Bi-Weekly)</option>
            <option value="M">M (Monthly)</option>
          </select>
        </div>

        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">AR Terms</label>
            <select name="terms" id="edit-client-terms" class="form-select">
              <option value="cash">Cash</option>
              <option value="prepaid">Prepaid</option>
              <option value="15d">15 days</option>
              <option value="30d">30 days</option>
              <option value="45d">45 days</option>
              <option value="60d">60 days</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Default VAT %</label>
            <input type="number" step="0.01" name="default_vat_rate" id="edit-client-default-vat" class="form-control">
          </div>
          <div class="col-md-3">
            <label class="form-label">Credit Limit (AED)</label>
            <input type="number" step="0.01" name="credit_limit" id="edit-client-credit-limit" class="form-control">
          </div>
        </div>

        <div class="row g-2 mt-1">
          <div class="col-md-6">
            <label class="form-label">Landline</label>
            <input type="text" name="cell_num" id="edit-client-cell" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">Default Hourly Rate (AED)</label>
            <input type="number" step="0.01" name="rate" id="edit-client-rate" class="form-control">
          </div>
        </div>
        <div class="mb-2 mt-1">
          <label class="form-label">Address</label>
          <input type="text" name="address" id="edit-client-address" class="form-control">
        </div>
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">TRN Number</label>
            <input type="text" name="trn" id="edit-client-trn" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">Balance</label>
            <input type="number" name="balance" id="edit-client-balance" step="0.01" class="form-control">
          </div>
        </div>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" id="edit-client-key_le" name="key_le" value="Key">
          <label class="form-check-label" for="edit-client-key_le">Leave key with us</label>
        </div>

        <div id="edit-client-error" class="text-danger mt-2" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-success">Save Changes</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Upload Document Modal -->
<div class="modal fade" id="uploadDocumentModal" tabindex="-1" aria-labelledby="uploadDocumentModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form id="upload-document-form" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="uploadDocumentModalLabel">Upload Document</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="client_id" id="upload-client-id" value="<?= (int)$client_id ?>">
        <div class="mb-3">
          <label class="form-label">Document Type <span class="text-danger">*</span></label>
          <select name="doc_type" class="form-select" required>
            <option value="">Select Document Type</option>
            <option value="contract">Contract</option>
            <option value="agreement">Service Agreement</option>
            <option value="id_copy">ID Copy</option>
            <option value="license">License</option>
            <option value="insurance">Insurance</option>
            <option value="invoice">Invoice</option>
            <option value="receipt">Receipt</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">File <span class="text-danger">*</span></label>
          <input type="file" name="file" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.txt" required>
          <div class="form-text">Allowed formats: PDF, JPG, PNG, GIF, DOC, DOCX, TXT (Max 10MB)</div>
        </div>
        <div class="mb-3">
          <label class="form-label">Expiry Date (Optional)</label>
          <input type="date" name="expires_at" class="form-control">
          <div class="form-text">Leave empty if document doesn't expire</div>
        </div>
        <div id="upload-document-error" class="text-danger mt-2" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-cloud-upload"></i> Upload Document
        </button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Send Email Modal -->
<div class="modal fade" id="sendEmailModal" tabindex="-1" aria-labelledby="sendEmailModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form id="send-email-form" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="sendEmailModalLabel">Send Email</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="client_id" id="email-client-id" value="<?= (int)$client_id ?>">
        <input type="hidden" name="type" value="email">
        
        <!-- Email Info -->
        <div class="alert alert-info">
          <div class="d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
              <i class="bi bi-info-circle me-2"></i>
              <div>
                <strong>Email Details:</strong>
                <div class="small">
                  <span id="email-to-info">To: Loading client email...</span><br>
                  <span id="email-from-info">From: Loading sender info...</span>
                </div>
              </div>
            </div>
            <div>
              <a href="operation/email_settings.php" class="btn btn-sm btn-outline-primary" target="_blank">
                <i class="bi bi-gear"></i> Email Settings
              </a>
            </div>
          </div>
        </div>
        
        <div class="mb-3">
          <label class="form-label">Template (Optional)</label>
          <select name="template_id" class="form-select" onchange="loadEmailTemplate(this.value)">
            <option value="">Custom Message</option>
            <option value="welcome">Welcome Email</option>
            <option value="invoice_reminder">Invoice Reminder</option>
            <option value="service_confirmation">Service Confirmation</option>
          </select>
          <div class="form-text">Select a template to auto-fill subject and message</div>
        </div>
        <div class="mb-3">
          <label class="form-label">Subject <span class="text-danger">*</span></label>
          <input type="text" name="subject" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Message <span class="text-danger">*</span></label>
          <textarea name="message" class="form-control" rows="6" required></textarea>
        </div>
        <div id="send-email-error" class="text-danger mt-2" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-success">
          <i class="bi bi-envelope"></i> Send Email
        </button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Send SMS Modal -->
<div class="modal fade" id="sendSMSModal" tabindex="-1" aria-labelledby="sendSMSModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form id="send-sms-form" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="sendSMSModalLabel">Send SMS</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="client_id" id="sms-client-id" value="<?= (int)$client_id ?>">
        <input type="hidden" name="type" value="sms">
        <div class="mb-3">
          <label class="form-label">Template (Optional)</label>
          <select name="template_id" class="form-select" onchange="loadSMSTemplate(this.value)">
            <option value="">Custom Message</option>
            <option value="appointment_reminder">Appointment Reminder</option>
            <option value="payment_reminder_sms">Payment Reminder</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Message <span class="text-danger">*</span></label>
          <textarea name="message" class="form-control" rows="4" maxlength="160" required></textarea>
          <div class="form-text">SMS limit: 160 characters</div>
        </div>
        <div id="send-sms-error" class="text-danger mt-2" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-info">
          <i class="bi bi-chat-text"></i> Send SMS
        </button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Note Modal -->
<div class="modal fade" id="addNoteModal" tabindex="-1" aria-labelledby="addNoteModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form id="add-note-form" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addNoteModalLabel">Add Note</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="client_id" id="note-client-id" value="<?= (int)$client_id ?>">
        <div class="mb-3">
          <label class="form-label">Note Type</label>
          <select name="note_type" class="form-select">
            <option value="general">General</option>
            <option value="important">Important</option>
            <option value="task">Task</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Content <span class="text-danger">*</span></label>
          <textarea name="content" class="form-control" rows="4" required></textarea>
        </div>
        <div id="add-note-error" class="text-danger mt-2" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-plus-circle"></i> Add Note
        </button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Task Modal -->
<div class="modal fade" id="addTaskModal" tabindex="-1" aria-labelledby="addTaskModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form id="add-task-form" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addTaskModalLabel">Add Task</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="client_id" id="task-client-id" value="<?= (int)$client_id ?>">
        <div class="mb-3">
          <label class="form-label">Task Description <span class="text-danger">*</span></label>
          <textarea name="content" class="form-control" rows="3" required></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label">Due Date (Optional)</label>
          <input type="date" name="due_date" class="form-control">
        </div>
        <div class="mb-3">
          <label class="form-label">Priority</label>
          <select name="priority" class="form-select">
            <option value="low">Low</option>
            <option value="medium" selected>Medium</option>
            <option value="high">High</option>
          </select>
        </div>
        <div id="add-task-error" class="text-danger mt-2" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-info">
          <i class="bi bi-plus-circle"></i> Add Task
        </button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Site Modal -->
<div class="modal fade" id="addSiteModal" tabindex="-1" aria-labelledby="addSiteModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form id="add-site-form" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addSiteModalLabel">Add Service Location</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="client_id" id="site-client-id" value="<?= (int)$client_id ?>">
        <div class="mb-3">
          <label class="form-label">Site Name <span class="text-danger">*</span></label>
          <input type="text" name="site_name" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Address</label>
          <textarea name="address" class="form-control" rows="3"></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label">Contact Person</label>
          <input type="text" name="contact_person" class="form-control">
        </div>
        <div class="mb-3">
          <label class="form-label">Contact Phone</label>
          <input type="text" name="contact_phone" class="form-control">
        </div>
        <div class="mb-3">
          <label class="form-label">Notes/Instructions</label>
          <textarea name="notes" class="form-control" rows="3"></textarea>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="is_primary" id="is_primary">
          <label class="form-check-label" for="is_primary">
            Set as Primary Location
          </label>
        </div>
        <div id="add-site-error" class="text-danger mt-2" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-plus-circle"></i> Add Site
        </button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Rating Modal -->
<div class="modal fade" id="addRatingModal" tabindex="-1" aria-labelledby="addRatingModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form id="add-rating-form" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addRatingModalLabel">Add Quality Rating</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="client_id" id="rating-client-id" value="<?= (int)$client_id ?>">
        <div class="mb-3">
          <label class="form-label">Order (Optional)</label>
          <select name="order_id" class="form-select">
            <option value="">Select Order</option>
            <!-- Will be populated by JavaScript -->
          </select>
        </div>
        <div class="row mb-3">
          <div class="col-4">
            <label class="form-label">Quality Score</label>
            <select name="quality_score" class="form-select" required>
              <option value="">Select</option>
              <option value="1">1 - Poor</option>
              <option value="2">2 - Fair</option>
              <option value="3">3 - Good</option>
              <option value="4">4 - Very Good</option>
              <option value="5">5 - Excellent</option>
            </select>
          </div>
          <div class="col-4">
            <label class="form-label">Timeliness</label>
            <select name="timeliness_score" class="form-select" required>
              <option value="">Select</option>
              <option value="1">1 - Very Late</option>
              <option value="2">2 - Late</option>
              <option value="3">3 - On Time</option>
              <option value="4">4 - Early</option>
              <option value="5">5 - Very Early</option>
            </select>
          </div>
          <div class="col-4">
            <label class="form-label">Professionalism</label>
            <select name="professionalism_score" class="form-select" required>
              <option value="">Select</option>
              <option value="1">1 - Poor</option>
              <option value="2">2 - Fair</option>
              <option value="3">3 - Good</option>
              <option value="4">4 - Very Good</option>
              <option value="5">5 - Excellent</option>
            </select>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Feedback/Comments</label>
          <textarea name="feedback" class="form-control" rows="3"></textarea>
        </div>
        <div id="add-rating-error" class="text-danger mt-2" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-success">
          <i class="bi bi-star"></i> Add Rating
        </button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Client Modal -->
<div class="modal fade" id="editClientModal" tabindex="-1" aria-labelledby="editClientModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form id="edit-client-form" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editClientModalLabel">Edit Client</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" id="edit-client-id">
        <div class="mb-2">
          <label class="form-label">Client Name <span class="text-danger">*</span></label>
          <input type="text" name="client_name" id="edit-client-name" class="form-control" required>
        </div>
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">Email <span class="text-danger">*</span></label>
            <input type="email" name="email" id="edit-client-email" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Mobile Number <span class="text-danger">*</span></label>
            <input type="text" name="mobile_num" id="edit-client-mobile" class="form-control" required>
          </div>
        </div>

        <div class="mb-2">
          <label class="form-label">Payment Type (Ops cadence) <span class="text-danger">*</span></label>
          <select name="payment" id="edit-client-payment" class="form-select" required>
            <option value="">-- Choose --</option>
            <option value="D">D (Daily)</option>
            <option value="W">W (Weekly)</option>
            <option value="Bi-W">Bi-W (Bi-Weekly)</option>
            <option value="M">M (Monthly)</option>
          </select>
        </div>

        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">AR Terms</label>
            <select name="terms" id="edit-client-terms" class="form-select">
              <option value="cash">Cash</option>
              <option value="prepaid">Prepaid</option>
              <option value="15d">15 days</option>
              <option value="30d">30 days</option>
              <option value="45d">45 days</option>
              <option value="60d">60 days</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Default VAT %</label>
            <input type="number" step="0.01" name="default_vat_rate" id="edit-client-default-vat" class="form-control">
          </div>
          <div class="col-md-3">
            <label class="form-label">Credit Limit (AED)</label>
            <input type="number" step="0.01" name="credit_limit" id="edit-client-credit-limit" class="form-control">
          </div>
        </div>

        <div class="row g-2 mt-1">
          <div class="col-md-6">
            <label class="form-label">Landline</label>
            <input type="text" name="cell_num" id="edit-client-cell" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">Default Hourly Rate (AED)</label>
            <input type="number" step="0.01" name="rate" id="edit-client-rate" class="form-control">
          </div>
        </div>
        <div class="mb-2 mt-1">
          <label class="form-label">Address</label>
          <input type="text" name="address" id="edit-client-address" class="form-control">
        </div>
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">TRN Number</label>
            <input type="text" name="trn" id="edit-client-trn" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">Balance</label>
            <input type="number" name="balance" id="edit-client-balance" step="0.01" class="form-control">
          </div>
        </div>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" id="edit-client-key_le" name="key_le" value="Key">
          <label class="form-check-label" for="edit-client-key_le">Leave key with us</label>
        </div>

        <div id="edit-client-error" class="text-danger mt-2" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-success">Save Changes</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Global variables
let charts = {};
let currentClientId = <?= (int)$client_id ?>;
let analyticsData = null;
let clientsListPreset = 'all';
let clientsPanelLoading = false;
const clientsPanelCache = {};
let clientListFetchTimer = null;

async function fetchClientList(options = {}) {
  const q = document.getElementById('clientFilter')?.value.trim() || '';
  const preset = options.preset ?? clientsListPreset;
  const params = new URLSearchParams({
    q,
    preset,
    limit: '80',
    active_id: String(currentClientId || 0),
  });
  const statusEl = document.getElementById('clientListStatus');
  if (statusEl) statusEl.textContent = 'Loading clients...';
  try {
    const res = await fetch('operation/ajax_client_list.php?' + params.toString(), { credentials: 'same-origin' });
    const data = await res.json();
    if (!data.success) throw new Error(data.error || 'Failed');
    const list = document.getElementById('clientList');
    if (list) {
      list.innerHTML = data.html || '<div class="text-muted small p-3">No clients found.</div>';
      wireClientListClicks();
    }
    if (statusEl) {
      statusEl.textContent = data.count
        ? `${data.count} client(s) shown${data.has_more ? '+' : ''}`
        : 'No matches';
    }
  } catch (e) {
    if (statusEl) statusEl.textContent = 'Could not load clients';
  }
}

function scheduleClientListFetch(options = {}) {
  clearTimeout(clientListFetchTimer);
  clientListFetchTimer = setTimeout(() => fetchClientList(options), 280);
}

function wireClientListClicks() {
  document.querySelectorAll('#clientList .client-list-item').forEach(el => {
    if (el.dataset.bound === '1') return;
    el.dataset.bound = '1';
    el.addEventListener('click', function(e) {
      e.preventDefault();
      selectClient(parseInt(this.dataset.clientId, 10));
    });
  });
}

async function selectClient(id, opts = {}) {
  if (!id) return;
  currentClientId = id;
  sessionStorage.setItem('operation_client_id', String(id));
  document.querySelectorAll('#clientList .client-list-item').forEach(el => {
    el.classList.toggle('active', parseInt(el.dataset.clientId, 10) === id);
  });
  await loadClientPanel(opts.from || '', opts.to || '', true);
}

async function loadClientPanel(from = '', to = '', bustCache = false) {
  if (!currentClientId) return;
  const panel = document.getElementById('clientDetailPanel');
  if (!panel) return;
  const cacheKey = `${currentClientId}|${from}|${to}`;
  if (!bustCache && clientsPanelCache[cacheKey]) {
    panel.innerHTML = clientsPanelCache[cacheKey];
    reinitClientPanelScripts();
    return;
  }
  clientsPanelLoading = true;
  panel.innerHTML = '<div class="p-5 text-center"><div class="spinner-border text-primary"></div><div class="mt-2 text-muted">Loading client...</div></div>';
  if (typeof cache !== 'undefined' && cache.clear) cache.clear();
  try {
    const params = new URLSearchParams({ client_id: String(currentClientId), from, to });
    const res = await fetch('operation/ajax_client_panel.php?' + params.toString(), { credentials: 'same-origin' });
    const html = await res.text();
    if (!res.ok) throw new Error('Load failed');
    panel.innerHTML = html;
    clientsPanelCache[cacheKey] = html;
    reinitClientPanelScripts();
  } catch (e) {
    panel.innerHTML = '<div class="alert alert-danger m-3">Failed to load client details.</div>';
  } finally {
    clientsPanelLoading = false;
  }
}

function reinitClientPanelScripts() {
  if (typeof initializeAnalytics === 'function') initializeAnalytics();
  if (typeof initializeTimeline === 'function') initializeTimeline();
  if (typeof initializeActionButtons === 'function') initializeActionButtons();
  const analyticsTab = document.getElementById('tab-analytics');
  if (analyticsTab && analyticsTab.classList.contains('show') && typeof loadAnalytics === 'function') {
    loadAnalytics();
  }
}

window.applyClientDateFilter = function(e) {
  if (e) e.preventDefault();
  const from = document.getElementById('clientFilterFrom')?.value || '';
  const to = document.getElementById('clientFilterTo')?.value || '';
  loadClientPanel(from, to, true);
  return false;
};

window.clearClientDateFilter = function() {
  loadClientPanel('', '', true);
};

function initClientsPageFastLoad() {
  wireClientListClicks();
  const clientFilter = document.getElementById('clientFilter');
  if (clientFilter) {
    clientFilter.addEventListener('input', () => scheduleClientListFetch());
  }
  const initialId = currentClientId
    || parseInt(sessionStorage.getItem('operation_client_id') || '0', 10)
    || 0;
  if (initialId > 0) {
    selectClient(initialId);
  }
}

// Global action functions - must be accessible from onclick handlers
window.downloadDocument = function(docId) {
  console.log('downloadDocument called with ID:', docId);
  // Get document info and create download link
  fetch(`operation/ajax_client_documents.php?client_id=${currentClientId}&action=get&doc_id=${docId}`)
    .then(response => response.json())
    .then(data => {
      if (data.success && data.document) {
        // Create a temporary download link
        const link = document.createElement('a');
        link.href = data.document.file_path;
        link.download = data.document.file_name;
        link.target = '_blank';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        showToast('success', 'Download started');
      } else {
        showToast('error', 'Failed to download document');
      }
    })
    .catch(error => {
      showToast('error', 'Download failed');
    });
};

window.editDocument = function(docId) {
  console.log('editDocument called with ID:', docId);
  // Get document info and show edit modal
  fetch(`operation/ajax_client_documents.php?client_id=${currentClientId}&action=get&doc_id=${docId}`)
    .then(response => response.json())
    .then(data => {
      if (data.success && data.document) {
        const doc = data.document;
        
        // Create edit modal dynamically
        const modalHtml = `
          <div class="modal fade" id="editDocumentModal" tabindex="-1">
            <div class="modal-dialog">
              <form id="edit-document-form" class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title">Edit Document</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                  <input type="hidden" name="doc_id" value="${docId}">
                  <div class="mb-3">
                    <label class="form-label">Document Type</label>
                    <select name="doc_type" class="form-select" required>
                      <option value="contract" ${doc.doc_type === 'contract' ? 'selected' : ''}>Contract</option>
                      <option value="agreement" ${doc.doc_type === 'agreement' ? 'selected' : ''}>Service Agreement</option>
                      <option value="id_copy" ${doc.doc_type === 'id_copy' ? 'selected' : ''}>ID Copy</option>
                      <option value="license" ${doc.doc_type === 'license' ? 'selected' : ''}>License</option>
                      <option value="insurance" ${doc.doc_type === 'insurance' ? 'selected' : ''}>Insurance</option>
                      <option value="invoice" ${doc.doc_type === 'invoice' ? 'selected' : ''}>Invoice</option>
                      <option value="receipt" ${doc.doc_type === 'receipt' ? 'selected' : ''}>Receipt</option>
                      <option value="other" ${doc.doc_type === 'other' ? 'selected' : ''}>Other</option>
                    </select>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Expiry Date</label>
                    <input type="date" name="expires_at" class="form-control" value="${doc.expires_at || ''}">
                  </div>
                  <div id="edit-document-error" class="text-danger" style="display:none;"></div>
                </div>
                <div class="modal-footer">
                  <button type="submit" class="btn btn-primary">Save Changes</button>
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
              </form>
            </div>
          </div>
        `;
        
        // Remove existing modal if any
        const existingModal = document.getElementById('editDocumentModal');
        if (existingModal) existingModal.remove();
        
        // Add modal to body
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('editDocumentModal'));
        modal.show();
        
        // Add form handler
        document.getElementById('edit-document-form').addEventListener('submit', function(e) {
          e.preventDefault();
          const formData = new FormData(this);
          formData.append('action', 'update');
          formData.append('client_id', currentClientId);
          
          fetch('operation/ajax_client_documents.php', {
            method: 'POST',
            body: formData
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              showToast('success', 'Document updated successfully');
              modal.hide();
              loadDocuments();
            } else {
              document.getElementById('edit-document-error').textContent = data.error;
              document.getElementById('edit-document-error').style.display = 'block';
            }
          })
          .catch(error => {
            document.getElementById('edit-document-error').textContent = 'Update failed';
            document.getElementById('edit-document-error').style.display = 'block';
          });
        });
      } else {
        showToast('error', 'Failed to load document details');
      }
    })
    .catch(error => {
      showToast('error', 'Failed to load document details');
    });
};

window.deleteDocument = function(docId) {
  console.log('deleteDocument called with ID:', docId);
  if (!confirm('Are you sure you want to delete this document?')) return;
  
  fetch('operation/ajax_client_documents.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `action=delete&doc_id=${docId}&client_id=${currentClientId}`
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      showToast('success', 'Document deleted successfully');
      loadDocuments();
    } else {
      showToast('error', 'Failed to delete document: ' + data.error);
    }
  })
  .catch(error => {
    showToast('error', 'Delete failed');
  });
};

window.editNote = function(noteId) {
  console.log('editNote called with ID:', noteId);
  // Get note info and show edit modal
  fetch(`operation/ajax_client_notes.php?client_id=${currentClientId}&action=get&note_id=${noteId}`)
    .then(response => response.json())
    .then(data => {
      if (data.success && data.note) {
        const note = data.note;
        
        // Create edit modal dynamically
        const modalHtml = `
          <div class="modal fade" id="editNoteModal" tabindex="-1">
            <div class="modal-dialog">
              <form id="edit-note-form" class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title">Edit Note</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                  <input type="hidden" name="note_id" value="${noteId}">
                  <div class="mb-3">
                    <label class="form-label">Note Type</label>
                    <select name="note_type" class="form-select">
                      <option value="general" ${note.note_type === 'general' ? 'selected' : ''}>General</option>
                      <option value="important" ${note.note_type === 'important' ? 'selected' : ''}>Important</option>
                      <option value="task" ${note.note_type === 'task' ? 'selected' : ''}>Task</option>
                    </select>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Content</label>
                    <textarea name="content" class="form-control" rows="4" required>${note.content}</textarea>
                  </div>
                  <div id="edit-note-error" class="text-danger" style="display:none;"></div>
                </div>
                <div class="modal-footer">
                  <button type="submit" class="btn btn-primary">Save Changes</button>
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
              </form>
            </div>
          </div>
        `;
        
        // Remove existing modal if any
        const existingModal = document.getElementById('editNoteModal');
        if (existingModal) existingModal.remove();
        
        // Add modal to body
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('editNoteModal'));
        modal.show();
        
        // Add form handler
        document.getElementById('edit-note-form').addEventListener('submit', function(e) {
          e.preventDefault();
          const formData = new FormData(this);
          formData.append('action', 'update');
          formData.append('client_id', currentClientId);
          
          fetch('operation/ajax_client_notes.php', {
            method: 'POST',
            body: formData
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              showToast('success', 'Note updated successfully');
              modal.hide();
              loadNotes();
            } else {
              document.getElementById('edit-note-error').textContent = data.error;
              document.getElementById('edit-note-error').style.display = 'block';
            }
          })
          .catch(error => {
            document.getElementById('edit-note-error').textContent = 'Update failed';
            document.getElementById('edit-note-error').style.display = 'block';
          });
        });
      } else {
        showToast('error', 'Failed to load note details');
      }
    })
    .catch(error => {
      showToast('error', 'Failed to load note details');
    });
};

window.deleteNote = function(noteId) {
  console.log('deleteNote called with ID:', noteId);
  if (!confirm('Are you sure you want to delete this note?')) return;
  
  fetch('operation/ajax_client_notes.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `action=delete&note_id=${noteId}&client_id=${currentClientId}`
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      showToast('success', 'Note deleted successfully');
      loadNotes();
    } else {
      showToast('error', 'Failed to delete note: ' + data.error);
    }
  })
  .catch(error => {
    showToast('error', 'Delete failed');
  });
};

window.editTask = function(taskId) {
  console.log('editTask called with ID:', taskId);
  // Get task info and show edit modal
  fetch(`operation/ajax_client_notes.php?client_id=${currentClientId}&action=get&note_id=${taskId}`)
    .then(response => response.json())
    .then(data => {
      if (data.success && data.note) {
        const task = data.note;
        
        // Create edit modal dynamically
        const modalHtml = `
          <div class="modal fade" id="editTaskModal" tabindex="-1" aria-labelledby="editTaskModalLabel" aria-hidden="true">
            <div class="modal-dialog">
              <form id="edit-task-form" class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title" id="editTaskModalLabel">Edit Task</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <input type="hidden" name="note_id" value="${task.id}">
                  <div class="mb-3">
                    <label class="form-label">Task Description <span class="text-danger">*</span></label>
                    <textarea name="content" class="form-control" rows="3" required>${task.content}</textarea>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Due Date (Optional)</label>
                    <input type="date" name="due_date" class="form-control" value="${task.due_date || ''}">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Priority</label>
                    <select name="priority" class="form-select">
                      <option value="low" ${task.priority === 'low' ? 'selected' : ''}>Low</option>
                      <option value="medium" ${task.priority === 'medium' ? 'selected' : ''}>Medium</option>
                      <option value="high" ${task.priority === 'high' ? 'selected' : ''}>High</option>
                    </select>
                  </div>
                  <div id="edit-task-error" class="text-danger mt-2" style="display:none;"></div>
                </div>
                <div class="modal-footer">
                  <button type="submit" class="btn btn-info">
                    <i class="bi bi-check-circle"></i> Update Task
                  </button>
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
              </form>
            </div>
          </div>
        `;
        
        // Remove existing modal if any
        const existingModal = document.getElementById('editTaskModal');
        if (existingModal) {
          existingModal.remove();
        }
        
        // Add modal to DOM
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('editTaskModal'));
        modal.show();
        
        // Handle form submission
        document.getElementById('edit-task-form').addEventListener('submit', function(e) {
          e.preventDefault();
          const formData = new FormData(this);
          formData.append('action', 'update');
          formData.append('client_id', currentClientId);
          
          fetch('operation/ajax_client_notes.php', {
            method: 'POST',
            body: formData
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              showToast('success', 'Task updated successfully');
              modal.hide();
              loadTasks();
            } else {
              document.getElementById('edit-task-error').textContent = data.error;
              document.getElementById('edit-task-error').style.display = 'block';
            }
          })
          .catch(error => {
            document.getElementById('edit-task-error').textContent = 'Update failed';
            document.getElementById('edit-task-error').style.display = 'block';
          });
        });
      } else {
        showToast('error', 'Failed to load task details');
      }
    })
    .catch(error => {
      showToast('error', 'Failed to load task details');
    });
};

window.deleteTask = function(taskId) {
  console.log('deleteTask called with ID:', taskId);
  if (!confirm('Are you sure you want to delete this task?')) return;
  
  fetch('operation/ajax_client_notes.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `action=delete&note_id=${taskId}&client_id=${currentClientId}`
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      showToast('success', 'Task deleted successfully');
      loadTasks();
    } else {
      showToast('error', 'Failed to delete task: ' + data.error);
    }
  })
  .catch(error => {
    showToast('error', 'Delete failed');
  });
};

window.editSite = function(siteId) {
  console.log('editSite called with ID:', siteId);
  // Get site info and show edit modal
  fetch(`operation/ajax_client_sites.php?client_id=${currentClientId}&action=get&site_id=${siteId}`)
    .then(response => response.json())
    .then(data => {
      if (data.success && data.site) {
        const site = data.site;
        
        // Create edit modal dynamically
        const modalHtml = `
          <div class="modal fade" id="editSiteModal" tabindex="-1">
            <div class="modal-dialog">
              <form id="edit-site-form" class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title">Edit Site</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                  <input type="hidden" name="site_id" value="${siteId}">
                  <div class="mb-3">
                    <label class="form-label">Site Name</label>
                    <input type="text" name="site_name" class="form-control" value="${site.site_name || ''}" required>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="3">${site.address || ''}</textarea>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Contact Person</label>
                    <input type="text" name="contact_person" class="form-control" value="${site.contact_person || ''}">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Contact Phone</label>
                    <input type="text" name="contact_phone" class="form-control" value="${site.contact_phone || ''}">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Notes/Instructions</label>
                    <textarea name="notes" class="form-control" rows="3">${site.notes || ''}</textarea>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_primary" id="edit_is_primary" ${site.is_primary == 1 ? 'checked' : ''}>
                    <label class="form-check-label" for="edit_is_primary">
                      Set as Primary Location
                    </label>
                  </div>
                  <div id="edit-site-error" class="text-danger" style="display:none;"></div>
                </div>
                <div class="modal-footer">
                  <button type="submit" class="btn btn-primary">Save Changes</button>
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
              </form>
            </div>
          </div>
        `;
        
        // Remove existing modal if any
        const existingModal = document.getElementById('editSiteModal');
        if (existingModal) existingModal.remove();
        
        // Add modal to body
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('editSiteModal'));
        modal.show();
        
        // Add form handler
        document.getElementById('edit-site-form').addEventListener('submit', function(e) {
          e.preventDefault();
          const formData = new FormData(this);
          formData.append('action', 'update');
          
          fetch('operation/ajax_client_sites.php', {
            method: 'POST',
            body: formData
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              showToast('success', 'Site updated successfully');
              modal.hide();
              loadSites();
            } else {
              document.getElementById('edit-site-error').textContent = data.error;
              document.getElementById('edit-site-error').style.display = 'block';
            }
          })
          .catch(error => {
            document.getElementById('edit-site-error').textContent = 'Update failed';
            document.getElementById('edit-site-error').style.display = 'block';
          });
        });
      } else {
        showToast('error', 'Failed to load site details');
      }
    })
    .catch(error => {
      showToast('error', 'Failed to load site details');
    });
};

window.deleteSite = function(siteId) {
  console.log('deleteSite called with ID:', siteId);
  if (!confirm('Are you sure you want to delete this site?')) return;
  
  fetch('operation/ajax_client_sites.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `action=delete&site_id=${siteId}`
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      showToast('success', 'Site deleted successfully');
      loadSites();
    } else {
      showToast('error', 'Failed to delete site: ' + data.error);
    }
  })
  .catch(error => {
    showToast('error', 'Delete failed');
  });
};

window.setPrimarySite = function(siteId) {
  console.log('setPrimarySite called with ID:', siteId);
  fetch('operation/ajax_client_sites.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `action=set_primary&site_id=${siteId}&client_id=${currentClientId}`
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      showToast('success', 'Primary site updated successfully');
      loadSites();
    } else {
      showToast('error', 'Failed to update primary site: ' + data.error);
    }
  })
  .catch(error => {
    showToast('error', 'Update failed');
  });
};

window.editPreferences = function() {
  console.log('editPreferences called');
  // Get current preferences and show edit modal
  fetch(`operation/ajax_client_preferences.php?client_id=${currentClientId}&action=get`)
    .then(response => response.json())
    .then(data => {
      if (data.success && data.preferences) {
        const prefs = data.preferences;
        
        // Create edit modal dynamically
        const modalHtml = `
          <div class="modal fade" id="editPreferencesModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
              <form id="edit-preferences-form" class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title">Edit Service Preferences</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                  <input type="hidden" name="client_id" value="${currentClientId}">
                  <div class="mb-3">
                    <label class="form-label">Special Instructions</label>
                    <textarea name="special_instructions" class="form-control" rows="3">${prefs.special_instructions || ''}</textarea>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Preferred Workers (comma-separated)</label>
                    <input type="text" name="preferred_workers" class="form-control" value="${prefs.preferred_workers ? prefs.preferred_workers.join(', ') : ''}" placeholder="e.g., John Smith, Sarah Johnson">
                    <div class="form-text">Enter worker names separated by commas</div>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Service Frequency Preference</label>
                    <select name="service_frequency" class="form-select">
                      <option value="weekly" ${prefs.service_frequency === 'weekly' ? 'selected' : ''}>Weekly</option>
                      <option value="bi-weekly" ${prefs.service_frequency === 'bi-weekly' ? 'selected' : ''}>Bi-weekly</option>
                      <option value="monthly" ${prefs.service_frequency === 'monthly' ? 'selected' : ''}>Monthly</option>
                      <option value="as-needed" ${prefs.service_frequency === 'as-needed' ? 'selected' : ''}>As Needed</option>
                    </select>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">VAT Mode <i class="bi bi-info-circle" data-bs-toggle="tooltip" title="Default VAT setting for orders with this client"></i></label>
                    <select name="vat_mode" class="form-select">
                      <option value="yes" ${prefs.vat_mode === 'yes' ? 'selected' : ''}>Yes (fee includes VAT)</option>
                      <option value="no" ${prefs.vat_mode === 'no' ? 'selected' : ''}>No (add VAT)</option>
                    </select>
                    <div class="form-text">This will be auto-selected when creating new orders</div>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Preferred Time Slots</label>
                    <div class="row">
                      <div class="col-md-6">
                        <label class="form-label small">Morning (8AM - 12PM)</label>
                        <input type="checkbox" name="time_slots[]" value="morning" ${prefs.time_slots && prefs.time_slots.includes('morning') ? 'checked' : ''} class="form-check-input">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label small">Afternoon (12PM - 5PM)</label>
                        <input type="checkbox" name="time_slots[]" value="afternoon" ${prefs.time_slots && prefs.time_slots.includes('afternoon') ? 'checked' : ''} class="form-check-input">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label small">Evening (5PM - 8PM)</label>
                        <input type="checkbox" name="time_slots[]" value="evening" ${prefs.time_slots && prefs.time_slots.includes('evening') ? 'checked' : ''} class="form-check-input">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label small">Flexible</label>
                        <input type="checkbox" name="time_slots[]" value="flexible" ${prefs.time_slots && prefs.time_slots.includes('flexible') ? 'checked' : ''} class="form-check-input">
                      </div>
                    </div>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Access Instructions</label>
                    <textarea name="access_instructions" class="form-control" rows="2" placeholder="e.g., Key under mat, Code: 1234, Call before arrival">${prefs.access_instructions || ''}</textarea>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Special Requirements</label>
                    <textarea name="special_requirements" class="form-control" rows="2" placeholder="e.g., Use eco-friendly products, Avoid certain areas">${prefs.special_requirements || ''}</textarea>
                  </div>
                  <div id="edit-preferences-error" class="text-danger" style="display:none;"></div>
                </div>
                <div class="modal-footer">
                  <button type="submit" class="btn btn-primary">Save Preferences</button>
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
              </form>
            </div>
          </div>
        `;
        
        // Remove existing modal if any
        const existingModal = document.getElementById('editPreferencesModal');
        if (existingModal) existingModal.remove();
        
        // Add modal to body
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('editPreferencesModal'));
        modal.show();
        
        // Add form handler
        document.getElementById('edit-preferences-form').addEventListener('submit', function(e) {
          e.preventDefault();
          const formData = new FormData(this);
          formData.append('action', 'update');
          
          fetch('operation/ajax_client_preferences.php', {
            method: 'POST',
            body: formData
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              showToast('success', 'Preferences updated successfully');
              modal.hide();
              loadPreferences();
            } else {
              document.getElementById('edit-preferences-error').textContent = data.error;
              document.getElementById('edit-preferences-error').style.display = 'block';
            }
          })
          .catch(error => {
            document.getElementById('edit-preferences-error').textContent = 'Update failed';
            document.getElementById('edit-preferences-error').style.display = 'block';
          });
        });
      } else {
        showToast('error', 'Failed to load preferences');
      }
    })
    .catch(error => {
      showToast('error', 'Failed to load preferences');
    });
};

window.toggleTaskComplete = function(taskId, isCompleted) {
  console.log('toggleTaskComplete called with ID:', taskId, 'completed:', isCompleted);
  
  fetch('operation/ajax_client_notes.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: `action=complete_task&task_id=${taskId}&completed=${isCompleted ? 1 : 0}`
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      showToast('success', data.message);
      loadTasks(); // Refresh tasks list
    } else {
      showToast('error', 'Failed to update task: ' + data.error);
    }
  })
  .catch(error => {
    console.error('Task update error:', error);
    showToast('error', 'Failed to update task');
  });
};

// Initialize everything when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
  initializeFilters();
  initializeBulkActions();
  initializeAnalytics();
  initializeTimeline();
  initializeMobileFeatures();
  initializePerformanceOptimizations();
  initializeActionButtons();
  initClientsPageFastLoad();
  
  // Load analytics if analytics tab is active
  const analyticsTab = document.getElementById('tab-analytics');
  if (analyticsTab && analyticsTab.classList.contains('show')) {
    loadAnalytics();
  }
  
  // Initialize form event listeners
  initializeFormHandlers();
  
  // Clear upload form errors when modal is shown
  const uploadModal = document.getElementById('uploadDocumentModal');
  if (uploadModal) {
    uploadModal.addEventListener('show.bs.modal', function() {
      const errorDiv = document.getElementById('upload-document-error');
      if (errorDiv) {
        errorDiv.style.display = 'none';
        errorDiv.textContent = '';
      }
    });
  }
  
  // Clear note form errors when modal is shown
  const noteModal = document.getElementById('addNoteModal');
  if (noteModal) {
    noteModal.addEventListener('show.bs.modal', function() {
      const errorDiv = document.getElementById('add-note-error');
      if (errorDiv) {
        errorDiv.style.display = 'none';
        errorDiv.textContent = '';
      }
    });
  }
  
  // Clear task form errors when modal is shown
  const taskModal = document.getElementById('addTaskModal');
  if (taskModal) {
    taskModal.addEventListener('show.bs.modal', function() {
      const errorDiv = document.getElementById('add-task-error');
      if (errorDiv) {
        errorDiv.style.display = 'none';
        errorDiv.textContent = '';
      }
    });
  }
  
  // Clear email form errors and load email info when modal is shown
  const emailModal = document.getElementById('sendEmailModal');
  if (emailModal) {
    emailModal.addEventListener('show.bs.modal', function() {
      const errorDiv = document.getElementById('send-email-error');
      if (errorDiv) {
        errorDiv.style.display = 'none';
        errorDiv.textContent = '';
      }
      
      // Load email info
      loadEmailInfo();
    });
  }
  
  // Clear add client form errors when modal is shown
  const addClientModal = document.getElementById('addClientModal');
  if (addClientModal) {
    addClientModal.addEventListener('show.bs.modal', function() {
      const errorDiv = document.getElementById('add-client-error');
      if (errorDiv) {
        errorDiv.style.display = 'none';
        errorDiv.textContent = '';
      }
    });
  }
});

// Initialize form event handlers
function initializeFormHandlers() {
  // Upload Document Form
  const uploadForm = document.getElementById('upload-document-form');
  if (uploadForm) {
    uploadForm.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const formData = new FormData(this);
      formData.append('action', 'upload');
      
      // Show loading state
      const submitBtn = this.querySelector('button[type="submit"]');
      const originalText = submitBtn.innerHTML;
      submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Uploading...';
      submitBtn.disabled = true;
      
      fetch('operation/ajax_client_documents.php', {
        method: 'POST',
        body: formData
      })
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
      })
      .then(data => {
        if (data.success) {
          showToast('success', 'Document uploaded successfully');
          // Close modal and reset form
          const modal = bootstrap.Modal.getInstance(document.getElementById('uploadDocumentModal'));
          if (modal) {
            modal.hide();
          }
          this.reset();
          loadDocuments();
        } else {
          showToast('error', 'Upload failed: ' + (data.error || 'Unknown error'));
          // Show error in modal
          const errorDiv = document.getElementById('upload-document-error');
          if (errorDiv) {
            errorDiv.textContent = data.error || 'Upload failed';
            errorDiv.style.display = 'block';
          }
        }
      })
      .catch(error => {
        console.error('Upload error:', error);
        showToast('error', 'Upload failed: ' + error.message);
        // Show error in modal
        const errorDiv = document.getElementById('upload-document-error');
        if (errorDiv) {
          errorDiv.textContent = 'Upload failed: ' + error.message;
          errorDiv.style.display = 'block';
        }
      })
      .finally(() => {
        // Restore button state
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
      });
    });
  }
  
  // Add Rating Form
  const ratingForm = document.getElementById('add-rating-form');
  if (ratingForm) {
    ratingForm.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const formData = new FormData(this);
      formData.append('action', 'add_rating');
      
      fetch('operation/ajax_client_preferences.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          showToast('success', 'Rating added successfully');
          bootstrap.Modal.getInstance(document.getElementById('addRatingModal')).hide();
          this.reset();
          loadQualityRatings();
        } else {
          showToast('error', 'Failed to add rating: ' + data.error);
        }
      })
      .catch(error => {
        showToast('error', 'Failed to add rating');
      });
    });
  }
  
  // Add Rating Modal - populate orders when shown
  const addRatingModal = document.getElementById('addRatingModal');
  if (addRatingModal) {
    addRatingModal.addEventListener('show.bs.modal', function() {
      loadOrdersForRating();
    });
  }
  
  // Add Note Form
  const noteForm = document.getElementById('add-note-form');
  if (noteForm) {
    noteForm.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const formData = new FormData(this);
      formData.append('action', 'create');
      
      // Show loading state
      const submitBtn = this.querySelector('button[type="submit"]');
      const originalText = submitBtn.innerHTML;
      submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Adding...';
      submitBtn.disabled = true;
      
      fetch('operation/ajax_client_notes.php', {
        method: 'POST',
        body: formData
      })
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
      })
      .then(data => {
        if (data.success) {
          showToast('success', 'Note added successfully');
          // Close modal and reset form
          const modal = bootstrap.Modal.getInstance(document.getElementById('addNoteModal'));
          if (modal) {
            modal.hide();
          }
          this.reset();
          loadNotes();
        } else {
          showToast('error', 'Failed to add note: ' + (data.error || 'Unknown error'));
          // Show error in modal
          const errorDiv = document.getElementById('add-note-error');
          if (errorDiv) {
            errorDiv.textContent = data.error || 'Failed to add note';
            errorDiv.style.display = 'block';
          }
        }
      })
      .catch(error => {
        console.error('Note creation error:', error);
        showToast('error', 'Failed to add note: ' + error.message);
        // Show error in modal
        const errorDiv = document.getElementById('add-note-error');
        if (errorDiv) {
          errorDiv.textContent = 'Failed to add note: ' + error.message;
          errorDiv.style.display = 'block';
        }
      })
      .finally(() => {
        // Restore button state
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
      });
    });
  }
  
  // Add Task Form
  const taskForm = document.getElementById('add-task-form');
  if (taskForm) {
    taskForm.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const formData = new FormData(this);
      formData.append('action', 'create_task');
      
      // Show loading state
      const submitBtn = this.querySelector('button[type="submit"]');
      const originalText = submitBtn.innerHTML;
      submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Adding...';
      submitBtn.disabled = true;
      
      fetch('operation/ajax_client_notes.php', {
        method: 'POST',
        body: formData
      })
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
      })
      .then(data => {
        if (data.success) {
          showToast('success', 'Task added successfully');
          // Close modal and reset form
          const modal = bootstrap.Modal.getInstance(document.getElementById('addTaskModal'));
          if (modal) {
            modal.hide();
          }
          this.reset();
          loadTasks();
        } else {
          showToast('error', 'Failed to add task: ' + (data.error || 'Unknown error'));
          // Show error in modal
          const errorDiv = document.getElementById('add-task-error');
          if (errorDiv) {
            errorDiv.textContent = data.error || 'Failed to add task';
            errorDiv.style.display = 'block';
          }
        }
      })
      .catch(error => {
        console.error('Task creation error:', error);
        showToast('error', 'Failed to add task: ' + error.message);
        // Show error in modal
        const errorDiv = document.getElementById('add-task-error');
        if (errorDiv) {
          errorDiv.textContent = 'Failed to add task: ' + error.message;
          errorDiv.style.display = 'block';
        }
      })
      .finally(() => {
        // Restore button state
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
      });
    });
  }
  
  // Add Site Form
  const siteForm = document.getElementById('add-site-form');
  if (siteForm) {
    siteForm.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const formData = new FormData(this);
      formData.append('action', 'create');
      
      // Show loading state
      const submitBtn = this.querySelector('button[type="submit"]');
      const originalText = submitBtn.innerHTML;
      submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Adding...';
      submitBtn.disabled = true;
      
      fetch('operation/ajax_client_sites.php', {
        method: 'POST',
        body: formData
      })
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
      })
      .then(data => {
        if (data.success) {
          showToast('success', 'Site added successfully');
          // Close modal and reset form
          const modal = bootstrap.Modal.getInstance(document.getElementById('addSiteModal'));
          if (modal) {
            modal.hide();
          }
          this.reset();
          loadSites();
        } else {
          showToast('error', 'Failed to add site: ' + (data.error || 'Unknown error'));
          // Show error in modal
          const errorDiv = document.getElementById('add-site-error');
          if (errorDiv) {
            errorDiv.textContent = data.error || 'Failed to add site';
            errorDiv.style.display = 'block';
          }
        }
      })
      .catch(error => {
        console.error('Site creation error:', error);
        showToast('error', 'Failed to add site: ' + error.message);
        // Show error in modal
        const errorDiv = document.getElementById('add-site-error');
        if (errorDiv) {
          errorDiv.textContent = 'Failed to add site: ' + error.message;
          errorDiv.style.display = 'block';
        }
      })
      .finally(() => {
        // Restore button state
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
      });
    });
  }
  
  // Send Email Form
  const emailForm = document.getElementById('send-email-form');
  if (emailForm) {
    emailForm.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const formData = new FormData(this);
      formData.append('action', 'send');
      formData.append('client_id', currentClientId);
      
      // Show loading state
      const submitBtn = this.querySelector('button[type="submit"]');
      const originalText = submitBtn.innerHTML;
      submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Sending...';
      submitBtn.disabled = true;
      
      fetch('operation/ajax_client_communications.php', {
        method: 'POST',
        body: formData
      })
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
      })
      .then(data => {
        if (data.success) {
          showToast('success', 'Email sent successfully');
          // Close modal and reset form
          const modal = bootstrap.Modal.getInstance(document.getElementById('sendEmailModal'));
          if (modal) {
            modal.hide();
          }
          this.reset();
          loadCommunications();
        } else {
          showToast('error', 'Failed to send email: ' + (data.error || 'Unknown error'));
          // Show error in modal
          const errorDiv = document.getElementById('send-email-error');
          if (errorDiv) {
            errorDiv.textContent = data.error || 'Failed to send email';
            errorDiv.style.display = 'block';
          }
        }
      })
      .catch(error => {
        console.error('Email sending error:', error);
        showToast('error', 'Failed to send email: ' + error.message);
        // Show error in modal
        const errorDiv = document.getElementById('send-email-error');
        if (errorDiv) {
          errorDiv.textContent = 'Failed to send email: ' + error.message;
          errorDiv.style.display = 'block';
        }
      })
      .finally(() => {
        // Restore button state
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
      });
    });
  }
  
  // Add Client Form
  const addClientForm = document.getElementById('add-client-form');
  if (addClientForm) {
    addClientForm.addEventListener('submit', function(e) {
      e.preventDefault();
      
      const formData = new FormData(this);
      const submitBtn = this.querySelector('button[type="submit"]');
      const originalText = submitBtn.innerHTML;
      
      // Show loading state
      submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
      submitBtn.disabled = true;
      
      fetch('operation/ajax_add_client.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        console.log('Server response:', data); // Debug log
        if (data.success) {
          // Show success message
          const errorDiv = document.getElementById('add-client-error');
          errorDiv.innerHTML = '<div class="alert alert-success">Client added successfully!</div>';
          errorDiv.style.display = 'block';
          
          // Close modal after a short delay
          setTimeout(() => {
            const modal = bootstrap.Modal.getInstance(document.getElementById('addClientModal'));
            if (modal) {
              modal.hide();
            }
            // Reset form
            this.reset();
            // Reload the page to refresh the client list
            location.reload();
          }, 1500);
        } else {
          // Show error message with more details
          const errorDiv = document.getElementById('add-client-error');
          const errorMsg = data.error || 'Failed to add client';
          console.error('Add client error:', errorMsg); // Debug log
          errorDiv.innerHTML = '<div class="alert alert-danger">Error: ' + errorMsg + '</div>';
          errorDiv.style.display = 'block';
        }
      })
      .catch(error => {
        console.error('Add client error:', error);
        const errorDiv = document.getElementById('add-client-error');
        errorDiv.innerHTML = '<div class="alert alert-danger">Error: Failed to add client. Please try again.</div>';
        errorDiv.style.display = 'block';
      })
      .finally(() => {
        // Restore button state
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
      });
    });
  }
}

// Mobile features initialization
function initializeMobileFeatures() {
  // Add mobile menu toggle for sidebar
  if (window.innerWidth <= 768) {
    addMobileMenuToggle();
    enableTouchGestures();
    enablePullToRefresh();
  }
  
  // Handle window resize
  window.addEventListener('resize', debounce(function() {
    if (window.innerWidth <= 768) {
      if (!document.querySelector('.mobile-menu-toggle')) {
        addMobileMenuToggle();
      }
    } else {
      const toggle = document.querySelector('.mobile-menu-toggle');
      if (toggle) toggle.remove();
    }
  }, 250));
}

function initializeActionButtons() {
  // Document actions
  document.addEventListener('click', function(e) {
    if (e.target.classList.contains('document-action')) {
      e.preventDefault();
      const action = e.target.dataset.action;
      const id = e.target.dataset.id;
      
      console.log('Document action clicked:', action, 'ID:', id);
      
      switch(action) {
        case 'download':
          downloadDocument(id);
          break;
        case 'edit':
          editDocument(id);
          break;
        case 'delete':
          deleteDocument(id);
          break;
      }
    }
    
    // Note actions
    if (e.target.classList.contains('note-action')) {
      e.preventDefault();
      const action = e.target.dataset.action;
      const id = e.target.dataset.id;
      
      console.log('Note action clicked:', action, 'ID:', id);
      
      switch(action) {
        case 'edit':
          editNote(id);
          break;
        case 'delete':
          deleteNote(id);
          break;
      }
    }
    
    // Site actions
    if (e.target.classList.contains('site-action')) {
      e.preventDefault();
      const action = e.target.dataset.action;
      const id = e.target.dataset.id;
      
      console.log('Site action clicked:', action, 'ID:', id);
      
      switch(action) {
        case 'edit':
          editSite(id);
          break;
        case 'delete':
          deleteSite(id);
          break;
        case 'set_primary':
          setPrimarySite(id);
          break;
      }
    }
    
    // Preferences actions
    if (e.target.classList.contains('preferences-action')) {
      e.preventDefault();
      const action = e.target.dataset.action;
      
      console.log('Preferences action clicked:', action);
      
      switch(action) {
        case 'edit':
          editPreferences();
          break;
      }
    }
  });
}

function addMobileMenuToggle() {
  const toggle = document.createElement('button');
  toggle.className = 'mobile-menu-toggle';
  toggle.innerHTML = '<i class="bi bi-list"></i>';
  toggle.addEventListener('click', toggleMobileSidebar);
  document.body.appendChild(toggle);
  
  // Add overlay
  const overlay = document.createElement('div');
  overlay.className = 'sidebar-overlay';
  overlay.addEventListener('click', toggleMobileSidebar);
  document.body.appendChild(overlay);
}

function toggleMobileSidebar() {
  const sidebar = document.querySelector('.sidebar');
  const overlay = document.querySelector('.sidebar-overlay');
  
  if (sidebar && overlay) {
    sidebar.classList.toggle('show');
    overlay.classList.toggle('show');
  }
}

function enableTouchGestures() {
  const clientList = document.getElementById('clientList');
  if (!clientList) return;
  
  let startX = 0;
  let currentX = 0;
  
  clientList.addEventListener('touchstart', function(e) {
    startX = e.touches[0].clientX;
  });
  
  clientList.addEventListener('touchmove', function(e) {
    currentX = e.touches[0].clientX;
    const diff = currentX - startX;
    
    if (Math.abs(diff) > 50) {
      e.preventDefault();
    }
  });
  
  clientList.addEventListener('touchend', function(e) {
    const diff = currentX - startX;
    
    // Swipe left - could show quick actions
    if (diff < -80) {
      console.log('Swipe left detected');
    }
    // Swipe right - could show more info
    else if (diff > 80) {
      console.log('Swipe right detected');
    }
    
    startX = 0;
    currentX = 0;
  });
}

function enablePullToRefresh() {
  let startY = 0;
  let isPulling = false;
  
  document.addEventListener('touchstart', function(e) {
    if (window.scrollY === 0) {
      startY = e.touches[0].clientY;
      isPulling = true;
    }
  });
  
  document.addEventListener('touchmove', function(e) {
    if (!isPulling) return;
    
    const currentY = e.touches[0].clientY;
    const diff = currentY - startY;
    
    if (diff > 80) {
      showPullToRefreshIndicator();
    }
  });
  
  document.addEventListener('touchend', function(e) {
    if (isPulling && startY > 0) {
      const indicator = document.querySelector('.ptr-indicator');
      if (indicator && indicator.classList.contains('active')) {
        refreshCurrentView();
      }
      hidePullToRefreshIndicator();
    }
    isPulling = false;
    startY = 0;
  });
}

function showPullToRefreshIndicator() {
  let indicator = document.querySelector('.ptr-indicator');
  if (!indicator) {
    indicator = document.createElement('div');
    indicator.className = 'ptr-indicator';
    indicator.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Release to refresh';
    document.body.insertBefore(indicator, document.body.firstChild);
  }
  indicator.classList.add('active');
}

function hidePullToRefreshIndicator() {
  const indicator = document.querySelector('.ptr-indicator');
  if (indicator) {
    indicator.classList.remove('active');
    setTimeout(() => indicator.remove(), 300);
  }
}

function refreshCurrentView() {
  showToast('info', 'Refreshing data...');
  
  // Determine which tab is active and refresh its data
  const activeTab = document.querySelector('.tab-pane.active');
  if (activeTab) {
    if (activeTab.id === 'tab-analytics') {
      loadAnalytics();
    } else if (activeTab.id === 'tab-timeline') {
      loadTimeline();
    } else if (activeTab.id === 'tab-documents') {
      loadDocuments();
    } else if (activeTab.id === 'tab-communications') {
      loadCommunications();
    } else if (activeTab.id === 'tab-notes') {
      loadNotes();
      loadTasks();
    } else if (activeTab.id === 'tab-service') {
      loadSites();
      loadPreferences();
      loadQualityRatings();
      loadWorkerPreferences();
    } else {
      location.reload();
    }
  }
}

// Performance optimizations
function initializePerformanceOptimizations() {
  // Lazy load images
  if ('IntersectionObserver' in window) {
    const lazyImages = document.querySelectorAll('.lazy-load');
    const imageObserver = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('loaded');
          imageObserver.unobserve(entry.target);
        }
      });
    });
    
    lazyImages.forEach(img => imageObserver.observe(img));
  }
  
  // Debounce search inputs
  const searchInputs = document.querySelectorAll('input[type="search"], #clientFilter');
  searchInputs.forEach(input => {
    input.addEventListener('input', debounce(function(e) {
      // Search handling is already debounced in initializeFilters
    }, 300));
  });
}

// Utility function for debouncing
function debounce(func, wait) {
  let timeout;
  return function executedFunction(...args) {
    const later = () => {
      clearTimeout(timeout);
      func(...args);
    };
    clearTimeout(timeout);
    timeout = setTimeout(later, wait);
  };
}

// Cache management
const cache = {
  analytics: null,
  timeline: null,
  documents: null,
  communications: null,
  notes: null,
  tasks: null,
  sites: null,
  preferences: null,
  qualityRatings: null,
  
  set: function(key, data, ttl = 300000) { // 5 minutes default TTL
    this[key] = {
      data: data,
      timestamp: Date.now(),
      ttl: ttl
    };
  },
  
  get: function(key) {
    const cached = this[key];
    if (cached && (Date.now() - cached.timestamp) < cached.ttl) {
      return cached.data;
    }
    return null;
  },
  
  clear: function(key) {
    if (key) {
      this[key] = null;
    } else {
      // Clear all cache
      Object.keys(this).forEach(k => {
        if (typeof this[k] !== 'function') {
          this[k] = null;
        }
      });
    }
  }
};

// Enhanced sidebar search — server-side for speed
function initializeFilters() {
  const clientFilter = document.getElementById('clientFilter');
  if (clientFilter) {
    clientFilter.addEventListener('input', () => scheduleClientListFetch());
  }

  const filterElements = [
    'filterStatus', 'filterTerms', 'filterBalance',
    'filterLastOrder', 'filterHealth', 'filterCredit'
  ];

  filterElements.forEach(id => {
    const element = document.getElementById(id);
    if (element) {
      element.addEventListener('change', applyFilters);
    }
  });
}

// Advanced filtering: server reload for presets/search; client-side for advanced dropdowns
function applyFilters() {
  const searchQuery = document.getElementById('clientFilter')?.value.trim() || '';
  const hasAdvanced = [
    'filterStatus', 'filterTerms', 'filterBalance',
    'filterLastOrder', 'filterHealth', 'filterCredit'
  ].some(id => document.getElementById(id)?.value);

  if (searchQuery.length >= 2 || clientsListPreset !== 'all') {
    scheduleClientListFetch();
    return;
  }

  if (!hasAdvanced) {
    updateFilterStatus(document.querySelectorAll('#clientList .client-list-item').length, document.querySelectorAll('#clientList .client-list-item').length);
    return;
  }

  const clientItems = document.querySelectorAll('#clientList .client-list-item');
  let visibleCount = 0;
  const status = document.getElementById('filterStatus')?.value || '';
  const balance = document.getElementById('filterBalance')?.value || '';
  const health = document.getElementById('filterHealth')?.value || '';
  const credit = document.getElementById('filterCredit')?.value || '';

  clientItems.forEach(item => {
    let matches = true;
    const clientStatus = item.dataset.status?.toLowerCase() || '';
    const clientHealth = parseInt(item.dataset.health, 10) || 0;
    const clientBalance = parseFloat(item.dataset.balance) || 0;
    const clientCredit = parseFloat(item.dataset.credit) || 0;

    if (status && clientStatus !== status) matches = false;
    if (health) {
      const [min, max] = health.split('-').map(Number);
      if (clientHealth < min || clientHealth > max) matches = false;
    }
    if (balance === '0' && clientBalance > 0) matches = false;
    if (balance === '1+' && clientBalance < 1) matches = false;
    if (credit === '0' && clientCredit > 0) matches = false;

    item.style.display = matches ? '' : 'none';
    if (matches) visibleCount++;
  });

  updateFilterStatus(visibleCount, clientItems.length);
}

function applyFilterPreset(preset) {
  clearFilters(false);
  clientsListPreset = preset || 'all';

  document.querySelectorAll('.filter-chip').forEach(chip => {
    chip.classList.remove('active');
  });
  const chip = document.querySelector(`[data-preset="${preset}"]`);
  if (chip) chip.classList.add('active');

  switch (preset) {
    case 'vip':
      if (document.getElementById('filterStatus')) document.getElementById('filterStatus').value = 'vip';
      break;
    case 'outstanding':
      if (document.getElementById('filterBalance')) document.getElementById('filterBalance').value = '1+';
      break;
    case 'inactive':
      if (document.getElementById('filterStatus')) document.getElementById('filterStatus').value = 'inactive';
      break;
    case 'at_risk':
      if (document.getElementById('filterStatus')) document.getElementById('filterStatus').value = 'at_risk';
      break;
  }

  fetchClientList({ preset: clientsListPreset });
}

function clearFilters(resetPreset = true) {
  document.getElementById('clientFilter').value = '';
  document.getElementById('filterStatus').value = '';
  document.getElementById('filterTerms').value = '';
  document.getElementById('filterBalance').value = '';
  document.getElementById('filterLastOrder').value = '';
  document.getElementById('filterHealth').value = '';
  document.getElementById('filterCredit').value = '';

  if (resetPreset) {
    clientsListPreset = 'all';
    document.querySelectorAll('.filter-chip').forEach(chip => chip.classList.remove('active'));
    const allChip = document.querySelector('[data-preset="all"]');
    if (allChip) allChip.classList.add('active');
    fetchClientList({ preset: 'all' });
  } else {
    applyFilters();
  }
}

function toggleAdvancedFilters() {
  const panel = document.getElementById('advancedFilters');
  const isVisible = panel.style.display !== 'none';
  panel.style.display = isVisible ? 'none' : 'block';
}

function saveFilterPreset() {
  const presetName = prompt('Enter a name for this filter preset:');
  if (!presetName) return;
  
  const filters = {
    search: document.getElementById('clientFilter').value,
    status: document.getElementById('filterStatus').value,
    terms: document.getElementById('filterTerms').value,
    balance: document.getElementById('filterBalance').value,
    lastOrder: document.getElementById('filterLastOrder').value,
    health: document.getElementById('filterHealth').value,
    credit: document.getElementById('filterCredit').value
  };
  
  // Save to localStorage
  const presets = JSON.parse(localStorage.getItem('clientFilterPresets') || '{}');
  presets[presetName] = filters;
  localStorage.setItem('clientFilterPresets', JSON.stringify(presets));
  
  showToast('success', `Filter preset "${presetName}" saved successfully`);
}

function updateFilterStatus(visible, total) {
  // Update any status indicators if needed
  console.log(`Showing ${visible} of ${total} clients`);
}

// Analytics functionality
function initializeAnalytics() {
  const analyticsPeriod = document.getElementById('analyticsPeriod');
  const refreshAnalytics = document.getElementById('refreshAnalytics');
  
  if (analyticsPeriod) {
    analyticsPeriod.addEventListener('change', loadAnalytics);
  }
  
  if (refreshAnalytics) {
    refreshAnalytics.addEventListener('click', loadAnalytics);
  }
}

function loadAnalytics() {
  if (!currentClientId) {
    showToast('error', 'No client selected');
    return;
  }
  
  const period = document.getElementById('analyticsPeriod')?.value || '12months';
  const refreshBtn = document.getElementById('refreshAnalytics');
  
  if (refreshBtn) {
    refreshBtn.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i> Loading...';
    refreshBtn.disabled = true;
  }
  
  // Load insights first
  loadInsights();
  
  fetch(`operation/ajax_client_analytics.php?client_id=${currentClientId}&period=${period}`)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        analyticsData = data.analytics;
        renderCharts();
        updateAnalyticsSummary();
      } else {
        showToast('error', 'Failed to load analytics: ' + data.error);
      }
    })
    .catch(error => {
      console.error('Analytics fetch error:', error);
      showToast('error', 'Failed to load analytics');
    })
    .finally(() => {
      if (refreshBtn) {
        refreshBtn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Refresh';
        refreshBtn.disabled = false;
      }
    });
}

function loadInsights() {
  if (!currentClientId) return;
  
  fetch(`operation/ajax_client_insights.php?client_id=${currentClientId}`)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        renderInsights(data.insights);
      } else {
        showToast('error', 'Failed to load insights: ' + data.error);
      }
    })
    .catch(error => {
      console.error('Insights error:', error);
      showToast('error', 'Failed to load insights');
    });
}

function renderInsights(insights) {
  const container = document.getElementById('insightsContainer');
  if (!container) {
    console.error('Insights container not found');
    return;
  }
  
  console.log('Rendering insights:', insights);
  
  if (insights.length === 0) {
    container.innerHTML = '<div class="text-center py-4 text-muted">No insights available for this client yet.</div>';
    return;
  }
  
  const insightsHTML = insights.map(insight => `
    <div class="insight-card insight-${getInsightAlertClass(insight.type)}">
      <div class="d-flex align-items-start">
        <div class="flex-grow-1">
          <h6 class="insight-title">
            <i class="bi ${getInsightIcon(insight.type)} me-2"></i>
            ${insight.title}
          </h6>
          <p class="insight-message">${insight.message}</p>
          ${insight.action ? `<div class="insight-action"><strong>Recommended Action:</strong> ${insight.action}</div>` : ''}
        </div>
        <div class="ms-3">
          <span class="badge bg-${getInsightBadgeClass(insight.priority)}">Priority ${insight.priority}</span>
        </div>
      </div>
    </div>
  `).join('');
  
  console.log('Generated insights HTML length:', insightsHTML.length);
  container.innerHTML = insightsHTML;
  
  // Force a reflow to ensure proper rendering
  container.offsetHeight;
}

function getInsightAlertClass(type) {
  switch(type) {
    case 'success': return 'success';
    case 'warning': return 'warning';
    case 'danger': return 'danger';
    case 'info': return 'info';
    default: return 'secondary';
  }
}

function getInsightIcon(type) {
  switch(type) {
    case 'success': return 'bi-check-circle-fill';
    case 'warning': return 'bi-exclamation-triangle-fill';
    case 'danger': return 'bi-x-circle-fill';
    case 'info': return 'bi-info-circle-fill';
    default: return 'bi-lightbulb-fill';
  }
}

function getInsightBadgeClass(priority) {
  if (priority >= 9) return 'danger';
  if (priority >= 7) return 'warning';
  if (priority >= 5) return 'info';
  return 'secondary';
}

function renderCharts() {
  if (!analyticsData) {
    console.error('No analytics data available for rendering charts');
    return;
  }
  
  renderRevenueChart();
  renderHealthChart();
  renderOrderDistributionChart();
  renderPaymentChart();
  renderHoursRevenueChart();
  renderARAgingChart();
}

function renderRevenueChart() {
  const ctx = document.getElementById('revenueChart');
  if (!ctx) return;
  
  // Destroy existing chart
  if (charts.revenue) {
    charts.revenue.destroy();
  }
  
  const data = analyticsData.revenue_trends || [];
  
  // Filter out months with no data for better visualization
  const filteredData = data.filter(item => item.revenue > 0 || item.orders > 0);
  
  if (filteredData.length === 0) {
    ctx.parentNode.innerHTML = '<div class="text-center py-4 text-muted">No revenue data available for the selected period</div>';
    return;
  }
  
  const labels = filteredData.map(item => {
    const date = new Date(item.month + '-01');
    return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
  });
  
  charts.revenue = new Chart(ctx, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [{
        label: 'Revenue (AED)',
        data: filteredData.map(item => item.revenue),
        borderColor: '#0d6efd',
        backgroundColor: 'rgba(13, 110, 253, 0.1)',
        tension: 0.4,
        fill: true
      }, {
        label: 'Orders',
        data: filteredData.map(item => item.orders),
        borderColor: '#20c997',
        backgroundColor: 'rgba(32, 201, 151, 0.1)',
        tension: 0.4,
        yAxisID: 'y1'
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      aspectRatio: 2,
      scales: {
        y: {
          beginAtZero: true,
          title: {
            display: true,
            text: 'Revenue (AED)'
          }
        },
        y1: {
          type: 'linear',
          display: true,
          position: 'right',
          title: {
            display: true,
            text: 'Orders'
          },
          grid: {
            drawOnChartArea: false,
          },
        }
      },
      plugins: {
        tooltip: {
          callbacks: {
            label: function(context) {
              if (context.datasetIndex === 0) {
                return 'Revenue: AED ' + context.parsed.y.toFixed(2);
              } else {
                return 'Orders: ' + context.parsed.y;
              }
            }
          }
        }
      }
    }
  });
}

function renderHealthChart() {
  const ctx = document.getElementById('healthChart');
  if (!ctx) return;
  
  if (charts.health) {
    charts.health.destroy();
  }
  
  const healthScore = analyticsData.health_score?.overall_score || 50;
  const riskLevel = analyticsData.health_score?.risk_level || 'Medium Risk';
  
  charts.health = new Chart(ctx, {
    type: 'doughnut',
    data: {
      datasets: [{
        data: [healthScore, 100 - healthScore],
        backgroundColor: [
          getHealthScoreColor(healthScore),
          '#e9ecef'
        ],
        borderWidth: 0
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      aspectRatio: 1,
      cutout: '70%',
      plugins: {
        legend: {
          display: false
        },
        tooltip: {
          callbacks: {
            label: function(context) {
              return 'Health Score: ' + healthScore + '/100';
            }
          }
        }
      }
    }
  });
  
  // Add center text
  const centerText = document.createElement('div');
  centerText.innerHTML = `
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
      <div style="font-size: 24px; font-weight: bold; color: ${getHealthScoreColor(healthScore)};">${healthScore}</div>
      <div style="font-size: 12px; color: #6c757d;">${riskLevel}</div>
    </div>
  `;
  ctx.parentNode.style.position = 'relative';
  ctx.parentNode.appendChild(centerText);
}

function renderOrderDistributionChart() {
  const ctx = document.getElementById('orderDistributionChart');
  if (!ctx) return;
  
  if (charts.orderDistribution) {
    charts.orderDistribution.destroy();
  }
  
  const data = analyticsData.order_distribution || [];
  
  charts.orderDistribution = new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: data.map(item => item.payment_status),
      datasets: [{
        data: data.map(item => item.count),
        backgroundColor: [
          '#20c997',
          '#fd7e14',
          '#dc3545',
          '#6c757d'
        ]
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      aspectRatio: 1.5,
      plugins: {
        legend: {
          position: 'bottom'
        }
      }
    }
  });
}

function renderPaymentChart() {
  const ctx = document.getElementById('paymentChart');
  if (!ctx) return;
  
  if (charts.payment) {
    charts.payment.destroy();
  }
  
  const data = analyticsData.payment_patterns || [];
  
  charts.payment = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: data.map(item => item.payment_method || 'Unknown'),
      datasets: [{
        label: 'Count',
        data: data.map(item => item.count),
        backgroundColor: '#0d6efd'
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      aspectRatio: 2,
      plugins: {
        legend: {
          display: false
        }
      }
    }
  });
}

function renderHoursRevenueChart() {
  const ctx = document.getElementById('hoursRevenueChart');
  if (!ctx) return;
  
  if (charts.hoursRevenue) {
    charts.hoursRevenue.destroy();
  }
  
  const data = analyticsData.hours_revenue || [];
  
  charts.hoursRevenue = new Chart(ctx, {
    type: 'scatter',
    data: {
      datasets: [{
        label: 'Hours vs Revenue',
        data: data.map(item => ({
          x: item.hours,
          y: item.revenue
        })),
        backgroundColor: '#0d6efd',
        borderColor: '#0d6efd'
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      aspectRatio: 2,
      scales: {
        x: {
          title: {
            display: true,
            text: 'Hours'
          }
        },
        y: {
          title: {
            display: true,
            text: 'Revenue (AED)'
          }
        }
      }
    }
  });
}

function renderARAgingChart() {
  const ctx = document.getElementById('arAgingChart');
  if (!ctx) return;
  
  if (charts.arAging) {
    charts.arAging.destroy();
  }
  
  const data = analyticsData.ar_aging || [];
  
  charts.arAging = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: data.map(item => item.aging_bucket),
      datasets: [{
        label: 'Outstanding Amount (AED)',
        data: data.map(item => item.outstanding_amount),
        backgroundColor: [
          '#20c997',
          '#ffc107',
          '#fd7e14',
          '#dc3545'
        ]
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      aspectRatio: 2,
      plugins: {
        legend: {
          display: false
        }
      }
    }
  });
}

function updateAnalyticsSummary() {
  const summary = analyticsData.summary;
  if (!summary) return;
  
  const container = document.getElementById('analyticsSummary');
  if (!container) return;
  
  container.innerHTML = `
    <div class="col-md-3">
      <div class="text-center">
        <div class="h4 text-primary">AED ${summary.total_revenue.toFixed(2)}</div>
        <div class="small text-muted">Total Revenue</div>
        <div class="small ${summary.revenue_trend >= 0 ? 'text-success' : 'text-danger'}">
          <i class="bi bi-arrow-${summary.revenue_trend >= 0 ? 'up' : 'down'}"></i>
          ${Math.abs(summary.revenue_trend).toFixed(1)}%
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="text-center">
        <div class="h4 text-success">${summary.total_orders}</div>
        <div class="small text-muted">Total Orders</div>
        <div class="small ${summary.orders_trend >= 0 ? 'text-success' : 'text-danger'}">
          <i class="bi bi-arrow-${summary.orders_trend >= 0 ? 'up' : 'down'}"></i>
          ${Math.abs(summary.orders_trend).toFixed(1)}%
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="text-center">
        <div class="h4 text-warning">${summary.total_hours.toFixed(1)}</div>
        <div class="small text-muted">Total Hours</div>
        <div class="small text-info">
          <i class="bi bi-clock"></i>
          Avg: ${summary.avg_hourly_rate.toFixed(2)}/hr
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="text-center">
        <div class="h4 text-info">AED ${summary.avg_order_value.toFixed(2)}</div>
        <div class="small text-muted">Avg Order Value</div>
        <div class="small text-muted">
          <i class="bi bi-graph-up"></i>
          Per order
        </div>
      </div>
    </div>
  `;
}

// Timeline functionality
function initializeTimeline() {
  const timelineFilter = document.getElementById('timelineFilter');
  const refreshTimeline = document.getElementById('refreshTimeline');
  
  if (timelineFilter) {
    timelineFilter.addEventListener('change', loadTimeline);
  }
  
  if (refreshTimeline) {
    refreshTimeline.addEventListener('click', loadTimeline);
  }
}

function loadTimeline() {
  if (!currentClientId) return;
  
  const filter = document.getElementById('timelineFilter')?.value || '';
  const refreshBtn = document.getElementById('refreshTimeline');
  
  if (refreshBtn) {
    refreshBtn.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i> Loading...';
    refreshBtn.disabled = true;
  }
  
  let url = `operation/ajax_client_timeline.php?client_id=${currentClientId}`;
  if (filter) {
    url += `&type=${filter}`;
  }
  
  fetch(url)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        renderTimeline(data.timeline);
      } else {
        showToast('error', 'Failed to load timeline: ' + data.error);
      }
    })
    .catch(error => {
      console.error('Timeline error:', error);
      showToast('error', 'Failed to load timeline');
    })
    .finally(() => {
      if (refreshBtn) {
        refreshBtn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Refresh';
        refreshBtn.disabled = false;
      }
    });
}

function renderTimeline(timeline) {
  const container = document.getElementById('timelineContainer');
  if (!container) return;
  
  if (timeline.length === 0) {
    container.innerHTML = '<div class="text-center py-4 text-muted">No activities found</div>';
    return;
  }
  
  const timelineHTML = timeline.map(item => `
    <div class="timeline-item">
      <div class="timeline-icon" style="background-color: ${item.color};">
        <i class="bi ${item.icon}"></i>
      </div>
      <div class="timeline-content">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <h6 class="mb-1">${item.title}</h6>
            <p class="mb-1 text-muted small">${item.description}</p>
            ${item.amount ? `<span class="badge bg-success">AED ${parseFloat(item.amount).toFixed(2)}</span>` : ''}
          </div>
          <div class="text-end">
            <small class="text-muted">${item.formatted_date}</small>
            ${item.user_name ? `<div class="small text-muted">by ${item.user_name}</div>` : ''}
          </div>
        </div>
      </div>
    </div>
  `).join('');
  
  container.innerHTML = timelineHTML;
}

// Bulk actions functionality
function initializeBulkActions() {
  // Add checkboxes to client list
  const clientList = document.getElementById('clientList');
  if (clientList) {
    clientList.addEventListener('change', function(e) {
      if (e.target.type === 'checkbox') {
        updateBulkActions();
      }
    });
  }
}

function updateBulkActions() {
  const selected = document.querySelectorAll('#clientList input[type="checkbox"]:checked');
  const bulkActions = document.querySelector('.bulk-actions');
  
  if (selected.length > 0) {
    if (!bulkActions) {
      createBulkActionsPanel();
    }
    document.querySelector('.bulk-actions').classList.add('show');
    document.querySelector('.bulk-actions .selected-count').textContent = selected.length;
  } else {
    if (bulkActions) {
      bulkActions.classList.remove('show');
    }
  }
}

function createBulkActionsPanel() {
  const bulkHTML = `
    <div class="bulk-actions">
      <div class="d-flex align-items-center justify-content-between">
        <span><strong class="selected-count">0</strong> clients selected</span>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-outline-primary" onclick="bulkAction('email')">
            <i class="bi bi-envelope"></i> Email
          </button>
          <button class="btn btn-sm btn-outline-success" onclick="bulkAction('export')">
            <i class="bi bi-download"></i> Export
          </button>
          <button class="btn btn-sm btn-outline-warning" onclick="bulkAction('status')">
            <i class="bi bi-gear"></i> Status
          </button>
          <button class="btn btn-sm btn-outline-secondary" onclick="clearSelection()">
            <i class="bi bi-x"></i> Clear
          </button>
        </div>
      </div>
    </div>
  `;
  
  document.querySelector('.panel').insertAdjacentHTML('afterbegin', bulkHTML);
}

function bulkAction(action) {
  const selected = Array.from(document.querySelectorAll('#clientList input[type="checkbox"]:checked'))
    .map(cb => cb.value);
  
  if (selected.length === 0) {
    showToast('warning', 'Please select clients first');
    return;
  }
  
  // Implementation would depend on specific action
  showToast('info', `${action} action for ${selected.length} clients`);
}

function clearSelection() {
  document.querySelectorAll('#clientList input[type="checkbox"]').forEach(cb => cb.checked = false);
  updateBulkActions();
}

// Utility functions
function getHealthScoreColor(score) {
  if (score >= 80) return '#20c997';
  if (score >= 60) return '#ffc107';
  if (score >= 40) return '#fd7e14';
  return '#dc3545';
}


// Tab change handlers
document.addEventListener('shown.bs.tab', function(e) {
  const target = e.target.getAttribute('data-bs-target');
  
  if (target === '#tab-analytics') {
    loadAnalytics();
  } else if (target === '#tab-timeline') {
    loadTimeline();
  } else if (target === '#tab-documents') {
    loadDocuments();
  } else if (target === '#tab-communications') {
    loadCommunications();
  } else if (target === '#tab-notes') {
    loadNotes();
    loadTasks();
  } else if (target === '#tab-service') {
    loadSites();
    loadPreferences();
    loadQualityRatings();
    loadWorkerPreferences();
  }
});

// Document management functions
function loadDocuments() {
  if (!currentClientId) return;
  
  fetch(`operation/ajax_client_documents.php?client_id=${currentClientId}&action=list`)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        renderDocuments(data.documents);
      } else {
        showToast('error', 'Failed to load documents: ' + data.error);
      }
    })
    .catch(error => {
      console.error('Documents error:', error);
      showToast('error', 'Failed to load documents');
    });
}

function renderDocuments(documents) {
  const container = document.getElementById('documentsContainer');
  if (!container) return;
  
  if (documents.length === 0) {
    container.innerHTML = '<div class="text-center py-4 text-muted">No documents uploaded yet</div>';
    return;
  }
  
  const documentsHTML = documents.map(doc => `
    <div class="card mb-3">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between">
          <div class="flex-grow-1">
            <h6 class="card-title mb-1">${doc.file_name}</h6>
            <div class="small text-muted mb-2">
              <span class="badge bg-secondary">${doc.doc_type}</span>
              <span class="ms-2">${doc.formatted_size}</span>
              ${doc.expires_at ? `<span class="ms-2 badge ${getExpiryBadgeClass(doc.expiry_status)}">${doc.expiry_status.replace('_', ' ')}</span>` : ''}
            </div>
            <div class="small text-muted">
              Uploaded ${doc.relative_time} by ${doc.uploaded_by_name}
            </div>
          </div>
          <div class="dropdown position-relative">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="dropdownMenuButton${doc.id}" onclick="toggleDocumentDropdown(${doc.id})" aria-expanded="false">
              Actions
            </button>
            <ul class="dropdown-menu position-absolute" id="dropdownMenu${doc.id}" style="display: none; top: 100%; left: 0; z-index: 1000;">
              <li><a class="dropdown-item" href="javascript:void(0)" onclick="downloadDocument(${doc.id})"><i class="bi bi-download"></i> Download</a></li>
              <li><a class="dropdown-item" href="javascript:void(0)" onclick="editDocument(${doc.id})"><i class="bi bi-pencil"></i> Edit</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="deleteDocument(${doc.id})"><i class="bi bi-trash"></i> Delete</a></li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  `).join('');
  
  container.innerHTML = documentsHTML;
  
  // Initialize dropdowns after rendering
  setTimeout(() => {
    const dropdowns = container.querySelectorAll('.dropdown-toggle');
    console.log('Found dropdowns:', dropdowns.length);
    
    dropdowns.forEach((dropdown, index) => {
      console.log(`Dropdown ${index} ready:`, dropdown.id);
    });
  }, 100);
}

// Communication functions
function loadCommunications() {
  if (!currentClientId) return;
  
  fetch(`operation/ajax_client_communications.php?client_id=${currentClientId}&action=list`)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        renderCommunications(data.communications);
      } else {
        showToast('error', 'Failed to load communications: ' + data.error);
      }
    })
    .catch(error => {
      console.error('Communications error:', error);
      showToast('error', 'Failed to load communications');
    });
}

function renderCommunications(communications) {
  const container = document.getElementById('communicationsContainer');
  if (!container) return;
  
  if (communications.length === 0) {
    container.innerHTML = '<div class="text-center py-4 text-muted">No communications yet</div>';
    return;
  }
  
  const communicationsHTML = communications.map(comm => `
    <div class="card mb-3">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between">
          <div class="flex-grow-1">
            <h6 class="card-title mb-1">${comm.subject || 'No Subject'}</h6>
            <div class="small text-muted mb-2">
              <span class="badge bg-${getCommunicationTypeClass(comm.type)}">${comm.type.toUpperCase()}</span>
              <span class="ms-2 badge ${comm.status_class}">${comm.status}</span>
            </div>
            <p class="card-text small">${comm.message ? comm.message.substring(0, 200) + (comm.message.length > 200 ? '...' : '') : 'No message'}</p>
            <div class="small text-muted">
              Sent ${comm.formatted_date} by ${comm.sent_by_name}
            </div>
          </div>
        </div>
      </div>
    </div>
  `).join('');
  
  container.innerHTML = communicationsHTML;
}

// Notes and Tasks functions
function loadNotes() {
  if (!currentClientId) return;
  
  fetch(`operation/ajax_client_notes.php?client_id=${currentClientId}&action=list`)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        renderNotes(data.notes);
      } else {
        showToast('error', 'Failed to load notes: ' + data.error);
      }
    })
    .catch(error => {
      console.error('Notes error:', error);
      showToast('error', 'Failed to load notes');
    });
}

function loadTasks() {
  if (!currentClientId) return;
  
  fetch(`operation/ajax_client_notes.php?client_id=${currentClientId}&action=tasks`)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        renderTasks(data.tasks);
      } else {
        showToast('error', 'Failed to load tasks: ' + data.error);
      }
    })
    .catch(error => {
      console.error('Tasks error:', error);
      showToast('error', 'Failed to load tasks');
    });
}

function loadSites() {
  if (!currentClientId) return;
  
  fetch(`operation/ajax_client_sites.php?client_id=${currentClientId}&action=list`)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        renderSites(data.sites);
      } else {
        showToast('error', 'Failed to load sites: ' + data.error);
      }
    })
    .catch(error => {
      console.error('Sites error:', error);
      showToast('error', 'Failed to load sites');
    });
}

function loadPreferences() {
  if (!currentClientId) return;
  
  fetch(`operation/ajax_client_preferences.php?client_id=${currentClientId}&action=get`)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        renderPreferences(data.preferences, data.analytics);
      } else {
        showToast('error', 'Failed to load preferences: ' + data.error);
      }
    })
    .catch(error => {
      console.error('Preferences error:', error);
      showToast('error', 'Failed to load preferences');
    });
}

function loadQualityRatings() {
  if (!currentClientId) return;
  
  fetch(`operation/ajax_client_preferences.php?client_id=${currentClientId}&action=quality_ratings`)
    .then(response => {
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      return response.json();
    })
    .then(data => {
      if (data.success) {
        renderQualityRatings(data.ratings || [], data.averages || {});
      } else {
        showToast('error', 'Failed to load quality ratings: ' + (data.error || 'Unknown error'));
      }
    })
    .catch(error => {
      console.error('Quality ratings error:', error);
      showToast('error', 'Failed to load quality ratings: ' + error.message);
    });
}

function loadOrdersForRating() {
  if (!currentClientId) return;
  
  const orderSelect = document.querySelector('#addRatingModal select[name="order_id"]');
  if (!orderSelect) return;
  
  // Show loading state
  orderSelect.innerHTML = '<option value="">Loading orders...</option>';
  
  fetch(`operation/ajax_client_preferences.php?client_id=${currentClientId}&action=get_orders`)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        orderSelect.innerHTML = '<option value="">Select Order (Optional)</option>';
        data.orders.forEach(order => {
          const option = document.createElement('option');
          option.value = order.id;
          option.textContent = order.display_text;
          orderSelect.appendChild(option);
        });
      } else {
        orderSelect.innerHTML = '<option value="">No orders available</option>';
        console.error('Failed to load orders:', data.error);
      }
    })
    .catch(error => {
      orderSelect.innerHTML = '<option value="">Error loading orders</option>';
      console.error('Orders loading error:', error);
    });
}

function loadWorkerPreferences() {
  if (!currentClientId) return;
  
  fetch(`operation/ajax_client_preferences.php?client_id=${currentClientId}&action=get`)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        renderWorkerPreferences(data.analytics.workers);
      } else {
        showToast('error', 'Failed to load worker preferences: ' + data.error);
      }
    })
    .catch(error => {
      console.error('Worker preferences error:', error);
      showToast('error', 'Failed to load worker preferences');
    });
}

function renderNotes(notes) {
  const container = document.getElementById('notesContainer');
  if (!container) return;
  
  if (notes.length === 0) {
    container.innerHTML = '<div class="text-center py-4 text-muted">No notes yet</div>';
    return;
  }
  
  const notesHTML = notes.map(note => `
    <div class="card mb-3 ${note.type_class}">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between">
          <div class="flex-grow-1">
            <div class="d-flex align-items-center mb-2">
              <span class="badge bg-secondary">${note.note_type}</span>
              <small class="text-muted ms-2">${note.relative_time}</small>
            </div>
            <p class="card-text">${note.content}</p>
            <div class="small text-muted">
              Created by ${note.created_by_name}
            </div>
          </div>
          <div class="dropdown position-relative">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="noteDropdownButton${note.id}" onclick="toggleNoteDropdown(${note.id})" aria-expanded="false">
              Actions
            </button>
            <ul class="dropdown-menu position-absolute" id="noteDropdownMenu${note.id}" style="display: none; top: 100%; left: 0; z-index: 1000;">
              <li><a class="dropdown-item" href="javascript:void(0)" onclick="editNote(${note.id})"><i class="bi bi-pencil"></i> Edit</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="deleteNote(${note.id})"><i class="bi bi-trash"></i> Delete</a></li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  `).join('');
  
  container.innerHTML = notesHTML;
  
  // Initialize dropdowns after rendering
  setTimeout(() => {
    const dropdowns = container.querySelectorAll('.dropdown-toggle');
    console.log('Found note dropdowns:', dropdowns.length);
    
    dropdowns.forEach((dropdown, index) => {
      console.log(`Note dropdown ${index} ready:`, dropdown.id);
    });
  }, 100);
}

function renderTasks(tasks) {
  const container = document.getElementById('tasksContainer');
  if (!container) return;
  
  if (tasks.length === 0) {
    container.innerHTML = '<div class="text-center py-4 text-muted">No tasks yet</div>';
    return;
  }
  
  const tasksHTML = tasks.map(task => `
    <div class="card mb-2 ${task.is_completed ? 'bg-light' : ''}">
      <div class="card-body p-2">
        <div class="d-flex align-items-start">
          <input class="form-check-input me-2" type="checkbox" ${task.is_completed ? 'checked' : ''} 
                 onchange="toggleTaskComplete(${task.id}, this.checked)">
          <div class="flex-grow-1">
            <label class="form-check-label small ${task.is_completed ? 'text-muted' : ''}">
              ${task.content}
            </label>
            ${task.due_date ? `<div class="small text-muted mt-1 ${task.status_class}">Due: ${task.formatted_due_date}</div>` : ''}
            <div class="small text-muted">
              Created by ${task.created_by_name}
            </div>
          </div>
          <div class="dropdown position-relative">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="taskDropdownButton${task.id}" onclick="toggleTaskDropdown(${task.id})" aria-expanded="false">
              Actions
            </button>
            <ul class="dropdown-menu position-absolute" id="taskDropdownMenu${task.id}" style="display: none; top: 100%; left: 0; z-index: 1000;">
              <li><a class="dropdown-item" href="javascript:void(0)" onclick="editTask(${task.id})"><i class="bi bi-pencil"></i> Edit</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="deleteTask(${task.id})"><i class="bi bi-trash"></i> Delete</a></li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  `).join('');
  
  container.innerHTML = tasksHTML;
  
  // Initialize dropdowns after rendering
  setTimeout(() => {
    const dropdowns = container.querySelectorAll('.dropdown-toggle');
    console.log('Found task dropdowns:', dropdowns.length);
    
    dropdowns.forEach((dropdown, index) => {
      console.log(`Task dropdown ${index} ready:`, dropdown.id);
    });
  }, 100);
}

function renderSites(sites) {
  const container = document.getElementById('sitesContainer');
  if (!container) return;
  
  if (sites.length === 0) {
    container.innerHTML = '<div class="text-center py-4 text-muted">No sites added yet</div>';
    return;
  }
  
  const sitesHTML = sites.map(site => `
    <div class="card mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start">
          <div class="flex-grow-1">
            <h6 class="card-title mb-1">
              ${site.site_name}
              ${site.is_primary ? '<span class="badge bg-primary ms-2">Primary</span>' : ''}
            </h6>
            <p class="card-text small text-muted mb-2">${site.address || 'No address provided'}</p>
            ${site.contact_person ? `<p class="card-text small mb-1"><strong>Contact:</strong> ${site.contact_person}</p>` : ''}
            ${site.contact_phone ? `<p class="card-text small mb-1"><strong>Phone:</strong> ${site.contact_phone}</p>` : ''}
            ${site.notes ? `<p class="card-text small mb-0"><strong>Notes:</strong> ${site.notes}</p>` : ''}
          </div>
          <div class="dropdown position-relative">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="dropdownMenuButton${site.id}" onclick="toggleSiteDropdown(${site.id})" aria-expanded="false">
              Actions
            </button>
            <ul class="dropdown-menu position-absolute" id="dropdownMenu${site.id}" style="display: none; top: 100%; left: 0; z-index: 1000;">
              <li><a class="dropdown-item" href="javascript:void(0)" onclick="editSite(${site.id})"><i class="bi bi-pencil"></i> Edit</a></li>
              ${!site.is_primary ? `<li><a class="dropdown-item" href="javascript:void(0)" onclick="setPrimarySite(${site.id})"><i class="bi bi-star"></i> Set Primary</a></li>` : ''}
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="deleteSite(${site.id})"><i class="bi bi-trash"></i> Delete</a></li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  `).join('');
  
  container.innerHTML = sitesHTML;
  
  // Initialize dropdowns after rendering
  setTimeout(() => {
    const dropdowns = container.querySelectorAll('.dropdown-toggle');
    console.log('Found site dropdowns:', dropdowns.length);
    
    dropdowns.forEach((dropdown, index) => {
      console.log(`Site dropdown ${index} ready:`, dropdown.id);
    });
  }, 100);
}

function renderPreferences(preferences, analytics) {
  const container = document.getElementById('preferencesContainer');
  if (!container) return;
  
  let html = '<div class="row">';
  
  // Service Preferences
  const vatMode = preferences.service_preferences?.vat_mode || 'yes';
  const vatModeDisplay = vatMode === 'yes' ? 'Yes (fee includes VAT)' : 'No (add VAT)';
  
  html += `
    <div class="col-md-6 mb-3">
      <h6>Service Preferences</h6>
      <div class="card">
        <div class="card-body">
          <p class="small mb-2"><strong>Preferred Workers:</strong></p>
          <p class="small text-muted">${preferences.preferred_workers.length > 0 ? preferences.preferred_workers.join(', ') : 'None specified'}</p>
          
          <p class="small mb-2"><strong>VAT Mode:</strong></p>
          <p class="small text-muted">${vatModeDisplay}</p>
          
          <p class="small mb-2"><strong>Special Instructions:</strong></p>
          <p class="small text-muted">${preferences.special_instructions || 'None specified'}</p>
        </div>
      </div>
    </div>
  `;
  
  // Worker Analytics
  if (analytics.workers && analytics.workers.length > 0) {
    html += `
      <div class="col-md-6 mb-3">
        <h6>Worker Performance</h6>
        <div class="card">
          <div class="card-body">
            ${analytics.workers.slice(0, 3).map(worker => `
              <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="small">${worker.worker_name}</span>
                <span class="badge bg-primary">${worker.order_count} orders</span>
              </div>
            `).join('')}
          </div>
        </div>
      </div>
    `;
  }
  
  // Time Preferences
  if (analytics.time_slots && analytics.time_slots.length > 0) {
    html += `
      <div class="col-md-6 mb-3">
        <h6>Preferred Time Slots</h6>
        <div class="card">
          <div class="card-body">
            ${analytics.time_slots.slice(0, 3).map(slot => `
              <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="small">${slot.day_name} ${slot.time}</span>
                <span class="badge bg-info">${slot.frequency} times</span>
              </div>
            `).join('')}
          </div>
        </div>
      </div>
    `;
  }
  
  // Service Frequency
  if (analytics.frequency) {
    html += `
      <div class="col-md-6 mb-3">
        <h6>Service Frequency</h6>
        <div class="card">
          <div class="card-body">
            <p class="small mb-1"><strong>Total Orders:</strong> ${analytics.frequency.total_orders}</p>
            <p class="small mb-1"><strong>Orders per Month:</strong> ${analytics.frequency.orders_per_month ? analytics.frequency.orders_per_month.toFixed(1) : '0'}</p>
            <p class="small mb-0"><strong>Avg Days Between:</strong> ${analytics.frequency.avg_days_between_orders ? analytics.frequency.avg_days_between_orders.toFixed(1) : '0'}</p>
          </div>
        </div>
      </div>
    `;
  }
  
  html += '</div>';
  container.innerHTML = html;
}

function renderQualityRatings(ratings, averages) {
  const container = document.getElementById('qualityRatingsContainer');
  if (!container) return;
  
  // Handle undefined or null ratings
  if (!ratings || !Array.isArray(ratings) || ratings.length === 0) {
    container.innerHTML = '<div class="text-center py-4 text-muted">No quality ratings yet</div>';
    return;
  }
  
  let html = '';
  
  // Average ratings
  if (averages && averages.total_ratings > 0) {
    const avgQuality = parseFloat(averages.avg_quality) || 0;
    const avgTimeliness = parseFloat(averages.avg_timeliness) || 0;
    const avgProfessionalism = parseFloat(averages.avg_professionalism) || 0;
    
    html += `
      <div class="row mb-3">
        <div class="col-md-4">
          <div class="card text-center">
            <div class="card-body">
              <h5 class="card-title text-primary">${avgQuality.toFixed(1)}/5</h5>
              <p class="card-text small">Quality Score</p>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card text-center">
            <div class="card-body">
              <h5 class="card-title text-success">${avgTimeliness.toFixed(1)}/5</h5>
              <p class="card-text small">Timeliness</p>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card text-center">
            <div class="card-body">
              <h5 class="card-title text-info">${avgProfessionalism.toFixed(1)}/5</h5>
              <p class="card-text small">Professionalism</p>
            </div>
          </div>
        </div>
      </div>
    `;
  }
  
  // Individual ratings
  html += '<div class="row">';
  ratings.forEach(rating => {
    html += `
      <div class="col-md-6 mb-3">
        <div class="card">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div>
                <div class="d-flex align-items-center mb-1">
                  <h6 class="card-title mb-0 me-2">${rating.service_date ? new Date(rating.service_date).toLocaleDateString() : 'General Rating'}</h6>
                  ${rating.order_id ? '<span class="badge bg-primary">Order Rating</span>' : '<span class="badge bg-secondary">General Rating</span>'}
                </div>
                ${rating.order_id ? `
                  <div class="small text-primary">
                    <strong>Order #${rating.order_id}</strong>
                    ${rating.start_time && rating.end_time ? ` • ${rating.start_time} - ${rating.end_time}` : ''}
                    ${rating.grand_total ? ` • AED ${parseFloat(rating.grand_total).toFixed(2)}` : ''}
                  </div>
                ` : '<small class="text-muted">General Rating (No specific order)</small>'}
              </div>
              <small class="text-muted">${rating.rated_by_name || 'Unknown'}</small>
            </div>
            <div class="row text-center">
              <div class="col-4">
                <div class="small text-muted">Quality</div>
                <div class="fw-bold text-primary">${rating.quality_score}/5</div>
              </div>
              <div class="col-4">
                <div class="small text-muted">Timeliness</div>
                <div class="fw-bold text-success">${rating.timeliness_score}/5</div>
              </div>
              <div class="col-4">
                <div class="small text-muted">Professionalism</div>
                <div class="fw-bold text-info">${rating.professionalism_score}/5</div>
              </div>
            </div>
            ${rating.feedback ? `<p class="card-text small mt-2">${rating.feedback}</p>` : ''}
          </div>
        </div>
      </div>
    `;
  });
  html += '</div>';
  
  container.innerHTML = html;
}

function renderWorkerPreferences(workers) {
  const container = document.getElementById('workerPreferencesContainer');
  if (!container) return;
  
  if (!workers || workers.length === 0) {
    container.innerHTML = '<div class="text-center py-4 text-muted">No worker data available</div>';
    return;
  }
  
  let html = '<div class="row">';
  
  workers.forEach(worker => {
    html += `
      <div class="col-md-6 mb-3">
        <div class="card">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h6 class="card-title mb-0">${worker.worker_name}</h6>
              <span class="badge bg-primary">${worker.order_count} orders</span>
            </div>
            <div class="row text-center">
              <div class="col-4">
                <div class="small text-muted">Avg Hours</div>
                <div class="fw-bold text-primary">${parseFloat(worker.avg_hours).toFixed(1)}h</div>
              </div>
              <div class="col-4">
                <div class="small text-muted">Avg Value</div>
                <div class="fw-bold text-success">AED ${parseFloat(worker.avg_value).toFixed(0)}</div>
              </div>
              <div class="col-4">
                <div class="small text-muted">Last Service</div>
                <div class="fw-bold text-info">${new Date(worker.last_service).toLocaleDateString()}</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    `;
  });
  
  html += '</div>';
  container.innerHTML = html;
}

// Toast notification function
function showToast(type, message) {
  const toastElement = document.getElementById('appToast');
  const toastBody = document.getElementById('appToastBody');
  
  if (!toastElement || !toastBody) return;
  
  // Remove existing classes and add new ones
  toastElement.className = `toast align-items-center border-0 ${type}`;
  toastBody.textContent = message;
  
  // Show the toast
  const toast = new bootstrap.Toast(toastElement);
  toast.show();
}

// Custom dropdown toggle functions
window.toggleDocumentDropdown = function(docId) {
  console.log('toggleDocumentDropdown called for docId:', docId);
  
  // Close all other dropdowns
  document.querySelectorAll('.dropdown-menu').forEach(menu => {
    if (menu.id !== `dropdownMenu${docId}`) {
      menu.style.display = 'none';
      const button = menu.previousElementSibling;
      if (button) {
        button.setAttribute('aria-expanded', 'false');
      }
    }
  });
  
  // Toggle current dropdown
  const menu = document.getElementById(`dropdownMenu${docId}`);
  const button = document.getElementById(`dropdownMenuButton${docId}`);
  
  if (menu && button) {
    const isVisible = menu.style.display !== 'none';
    menu.style.display = isVisible ? 'none' : 'block';
    button.setAttribute('aria-expanded', !isVisible);
    
    console.log(`Dropdown ${docId} toggled:`, {
      isVisible: !isVisible,
      menu: menu,
      button: button
    });
    
    // Add click outside listener
    if (!isVisible) {
      setTimeout(() => {
        const closeDropdown = (e) => {
          if (!menu.contains(e.target) && !button.contains(e.target)) {
            menu.style.display = 'none';
            button.setAttribute('aria-expanded', 'false');
            document.removeEventListener('click', closeDropdown);
          }
        };
        document.addEventListener('click', closeDropdown);
      }, 100);
    }
  }
};

window.toggleStatusDropdown = function() {
  console.log('toggleStatusDropdown called');
  
  // Close all other dropdowns
  document.querySelectorAll('.dropdown-menu').forEach(menu => {
    if (menu.id !== 'statusDropdown') {
      menu.style.display = 'none';
      const button = menu.previousElementSibling;
      if (button) {
        button.setAttribute('aria-expanded', 'false');
      }
    }
  });
  
  // Toggle status dropdown
  const menu = document.getElementById('statusDropdown');
  const button = document.querySelector('[onclick="toggleStatusDropdown()"]');
  
  if (menu && button) {
    const isVisible = menu.style.display !== 'none';
    menu.style.display = isVisible ? 'none' : 'block';
    button.setAttribute('aria-expanded', !isVisible);
    
    console.log('Status dropdown toggled:', {
      isVisible: !isVisible,
      menu: menu,
      button: button
    });
    
    // Add click outside listener
    if (!isVisible) {
      setTimeout(() => {
        const closeDropdown = (e) => {
          if (!menu.contains(e.target) && !button.contains(e.target)) {
            menu.style.display = 'none';
            button.setAttribute('aria-expanded', 'false');
            document.removeEventListener('click', closeDropdown);
          }
        };
        document.addEventListener('click', closeDropdown);
      }, 100);
    }
  }
};

window.toggleSiteDropdown = function(siteId) {
  console.log('toggleSiteDropdown called for siteId:', siteId);
  
  // Close all other dropdowns
  document.querySelectorAll('.dropdown-menu').forEach(menu => {
    if (menu.id !== `dropdownMenu${siteId}`) {
      menu.style.display = 'none';
      const button = menu.previousElementSibling;
      if (button) {
        button.setAttribute('aria-expanded', 'false');
      }
    }
  });
  
  // Toggle current dropdown
  const menu = document.getElementById(`dropdownMenu${siteId}`);
  const button = document.getElementById(`dropdownMenuButton${siteId}`);
  
  if (menu && button) {
    const isVisible = menu.style.display !== 'none';
    menu.style.display = isVisible ? 'none' : 'block';
    button.setAttribute('aria-expanded', !isVisible);
    
    console.log(`Site dropdown ${siteId} toggled:`, {
      isVisible: !isVisible,
      menu: menu,
      button: button
    });
    
    // Add click outside listener
    if (!isVisible) {
      setTimeout(() => {
        const closeDropdown = (e) => {
          if (!menu.contains(e.target) && !button.contains(e.target)) {
            menu.style.display = 'none';
            button.setAttribute('aria-expanded', 'false');
            document.removeEventListener('click', closeDropdown);
          }
        };
        document.addEventListener('click', closeDropdown);
      }, 100);
    }
  }
};

window.toggleNoteDropdown = function(noteId) {
  console.log('toggleNoteDropdown called for noteId:', noteId);
  
  // Close all other dropdowns
  document.querySelectorAll('.dropdown-menu').forEach(menu => {
    if (menu.id !== `noteDropdownMenu${noteId}`) {
      menu.style.display = 'none';
      const button = menu.previousElementSibling;
      if (button) {
        button.setAttribute('aria-expanded', 'false');
      }
    }
  });
  
  // Toggle current dropdown
  const menu = document.getElementById(`noteDropdownMenu${noteId}`);
  const button = document.getElementById(`noteDropdownButton${noteId}`);
  
  if (menu && button) {
    const isVisible = menu.style.display !== 'none';
    menu.style.display = isVisible ? 'none' : 'block';
    button.setAttribute('aria-expanded', !isVisible);
    
    console.log(`Note dropdown ${noteId} toggled:`, {
      isVisible: !isVisible,
      menu: menu,
      button: button
    });
    
    // Add click outside listener
    if (!isVisible) {
      setTimeout(() => {
        const closeDropdown = (e) => {
          if (!menu.contains(e.target) && !button.contains(e.target)) {
            menu.style.display = 'none';
            button.setAttribute('aria-expanded', 'false');
            document.removeEventListener('click', closeDropdown);
          }
        };
        document.addEventListener('click', closeDropdown);
      }, 100);
    }
  }
};

window.toggleTaskDropdown = function(taskId) {
  console.log('toggleTaskDropdown called for taskId:', taskId);
  
  // Close all other dropdowns
  document.querySelectorAll('.dropdown-menu').forEach(menu => {
    if (menu.id !== `taskDropdownMenu${taskId}`) {
      menu.style.display = 'none';
      const button = menu.previousElementSibling;
      if (button) {
        button.setAttribute('aria-expanded', 'false');
      }
    }
  });
  
  // Toggle current dropdown
  const menu = document.getElementById(`taskDropdownMenu${taskId}`);
  const button = document.getElementById(`taskDropdownButton${taskId}`);
  
  if (menu && button) {
    const isVisible = menu.style.display !== 'none';
    menu.style.display = isVisible ? 'none' : 'block';
    button.setAttribute('aria-expanded', !isVisible);
    
    console.log(`Task dropdown ${taskId} toggled:`, {
      isVisible: !isVisible,
      menu: menu,
      button: button
    });
    
    // Add click outside listener
    if (!isVisible) {
      setTimeout(() => {
        const closeDropdown = (e) => {
          if (!menu.contains(e.target) && !button.contains(e.target)) {
            menu.style.display = 'none';
            button.setAttribute('aria-expanded', 'false');
            document.removeEventListener('click', closeDropdown);
          }
        };
        document.addEventListener('click', closeDropdown);
      }, 100);
    }
  }
};

// Open email settings in new window
function openEmailSettings() {
  console.log('openEmailSettings function called');
  
  try {
    // Construct the absolute URL to email settings
    const protocol = window.location.protocol; // http: or https:
    const host = window.location.host; // localhost
    const pathname = window.location.pathname; // /herosys/operation.php
    
    // Extract the base path (everything before operation.php)
    const basePath = pathname.replace('/operation.php', '');
    const emailSettingsUrl = protocol + '//' + host + basePath + '/operation/email_settings.php';
    
    console.log('Current URL:', window.location.href);
    console.log('Protocol:', protocol);
    console.log('Host:', host);
    console.log('Pathname:', pathname);
    console.log('Base path:', basePath);
    console.log('Email settings URL:', emailSettingsUrl);
    
    // Test if the URL is valid
    if (!emailSettingsUrl.includes('email_settings.php')) {
      console.error('Invalid URL constructed:', emailSettingsUrl);
      alert('Error: Could not construct email settings URL');
      return;
    }
    
    // Open in new window
    const newWindow = window.open(emailSettingsUrl, '_blank', 'width=800,height=600,scrollbars=yes,resizable=yes');
    
    if (!newWindow) {
      console.error('Failed to open new window - popup blocked?');
      alert('Popup blocked! Please allow popups for this site.');
    } else {
      console.log('New window opened successfully');
    }
    
  } catch (error) {
    console.error('Error in openEmailSettings:', error);
    alert('Error opening email settings: ' + error.message);
  }
}

// Load email information (client email and sender info)
function loadEmailInfo() {
  if (!currentClientId) return;
  
  // Load client email
  fetch(`operation/ajax_client_communications.php?action=get_client_info&client_id=${currentClientId}`)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        const toInfo = document.getElementById('email-to-info');
        const fromInfo = document.getElementById('email-from-info');
        
        if (toInfo) {
          toInfo.textContent = `To: ${data.client.email || 'No email address'}`;
          if (!data.client.email) {
            toInfo.innerHTML += ' <span class="text-warning">(Email required to send)</span>';
          }
        }
        
        if (fromInfo) {
          fromInfo.textContent = `From: ${data.sender.name} (${data.sender.email})`;
        }
      }
    })
    .catch(error => {
      console.error('Error loading email info:', error);
      const toInfo = document.getElementById('email-to-info');
      const fromInfo = document.getElementById('email-from-info');
      
      if (toInfo) toInfo.textContent = 'To: Error loading client info';
      if (fromInfo) fromInfo.textContent = 'From: Error loading sender info';
    });
}

// Email template loading function
window.loadEmailTemplate = function(templateId) {
  console.log('Loading email template:', templateId);
  
  if (!templateId || templateId === '') {
    // Clear fields for custom message
    document.querySelector('input[name="subject"]').value = '';
    document.querySelector('textarea[name="message"]').value = '';
    return;
  }
  
  // Fetch templates from server
  fetch('operation/ajax_client_communications.php?action=templates')
    .then(response => response.json())
    .then(data => {
      if (data.success && data.templates) {
        const templates = data.templates.email;
        const template = templates.find(t => t.id === templateId);
        
        if (template) {
          // Populate subject and message fields
          const subjectField = document.querySelector('input[name="subject"]');
          const messageField = document.querySelector('textarea[name="message"]');
          
          if (subjectField) {
            let subject = template.subject || '';
            
            // Replace placeholders in subject as well
            if (currentClientId) {
              fetch(`operation/ajax_client_communications.php?action=get_template_data&client_id=${currentClientId}`)
                .then(response => response.json())
                .then(templateData => {
                  if (templateData.success) {
                    const data = templateData.template_data;
                    // Replace all placeholders
                    subject = subject.replace(/\{\{client_name\}\}/g, '{{client_name}}'); // Will be replaced by client name
                    subject = subject.replace(/\{\{invoice_no\}\}/g, data.invoice_no || 'N/A');
                    subject = subject.replace(/\{\{amount\}\}/g, data.amount || 'AED 0.00');
                    subject = subject.replace(/\{\{due_date\}\}/g, data.due_date || new Date().toLocaleDateString());
                    subject = subject.replace(/\{\{service_date\}\}/g, data.service_date || new Date().toLocaleDateString());
                    subject = subject.replace(/\{\{service_time\}\}/g, data.service_time || '9:00 AM');
                    subject = subject.replace(/\{\{worker_names\}\}/g, data.worker_names || 'TBD');
                    subject = subject.replace(/\{\{duration\}\}/g, data.duration || '2');
                    
                    // Now get client name and replace it
                    fetch(`operation/ajax_client_communications.php?action=get_client_info&client_id=${currentClientId}`)
                      .then(response => response.json())
                      .then(clientData => {
                        if (clientData.success) {
                          const client = clientData.client;
                          subject = subject.replace(/\{\{client_name\}\}/g, client.client_name || '{{client_name}}');
                        }
                        subjectField.value = subject;
                      })
                      .catch(error => {
                        console.error('Error loading client name:', error);
                        subjectField.value = subject;
                      });
                  }
                })
                .catch(error => {
                  console.error('Error loading template data for subject:', error);
                  subjectField.value = subject;
                });
            } else {
              subjectField.value = subject;
            }
          }
          
          if (messageField) {
            // Replace placeholders with client data
            let message = template.body || '';
            
            // Get client data from current client
            if (currentClientId) {
              // Fetch actual template data
              fetch(`operation/ajax_client_communications.php?action=get_template_data&client_id=${currentClientId}`)
                .then(response => response.json())
                .then(templateData => {
                  if (templateData.success) {
                    const data = templateData.template_data;
                    
                    // Replace placeholders with actual client data
                    message = message.replace(/\{\{client_name\}\}/g, '{{client_name}}'); // Will be replaced by client name
                    message = message.replace(/\{\{invoice_no\}\}/g, data.invoice_no || 'N/A');
                    message = message.replace(/\{\{amount\}\}/g, data.amount || 'AED 0.00');
                    message = message.replace(/\{\{due_date\}\}/g, data.due_date || new Date().toLocaleDateString());
                    message = message.replace(/\{\{service_date\}\}/g, data.service_date || new Date().toLocaleDateString());
                    message = message.replace(/\{\{service_time\}\}/g, data.service_time || '9:00 AM');
                    message = message.replace(/\{\{worker_names\}\}/g, data.worker_names || 'TBD');
                    message = message.replace(/\{\{duration\}\}/g, data.duration || '2');
                    
                    // Now get client name and replace it
                    fetch(`operation/ajax_client_communications.php?action=get_client_info&client_id=${currentClientId}`)
                      .then(response => response.json())
                      .then(clientData => {
                        if (clientData.success) {
                          const client = clientData.client;
                          message = message.replace(/\{\{client_name\}\}/g, client.client_name || '{{client_name}}');
                        }
                        messageField.value = message;
                      })
                      .catch(error => {
                        console.error('Error loading client name for message:', error);
                        messageField.value = message;
                      });
                  } else {
                    console.error('Failed to load template data:', templateData.error);
                    messageField.value = message; // Use template as-is
                  }
                })
                .catch(error => {
                  console.error('Error loading template data:', error);
                  messageField.value = message; // Use template as-is
                });
            } else {
              messageField.value = message;
            }
          }
          
          console.log('Template loaded successfully:', template);
        } else {
          console.warn('Template not found:', templateId);
        }
      } else {
        console.error('Failed to load templates:', data.error);
      }
    })
    .catch(error => {
      console.error('Error loading templates:', error);
    });
};

// Debug function to test dropdowns
window.debugDropdowns = function() {
  console.log('=== Dropdown Debug Info ===');
  console.log('Bootstrap available:', typeof bootstrap !== 'undefined');
  console.log('Bootstrap.Dropdown available:', typeof bootstrap !== 'undefined' && bootstrap.Dropdown);
  
  const container = document.getElementById('documentsContainer');
  if (!container) {
    console.log('Documents container not found');
    return;
  }
  
  const dropdowns = container.querySelectorAll('.dropdown-toggle');
  console.log('Found dropdowns:', dropdowns.length);
  
  dropdowns.forEach((dropdown, index) => {
    console.log(`Dropdown ${index}:`, {
      element: dropdown,
      id: dropdown.id,
      hasDataBsToggle: dropdown.hasAttribute('data-bs-toggle'),
      nextElement: dropdown.nextElementSibling,
      isVisible: dropdown.offsetParent !== null
    });
    
    // Test manual toggle
    dropdown.click();
    setTimeout(() => {
      const menu = dropdown.nextElementSibling;
      if (menu) {
        console.log(`Dropdown ${index} menu after click:`, {
          hasShowClass: menu.classList.contains('show'),
          isVisible: menu.offsetParent !== null,
          display: menu.style.display
        });
      }
    }, 100);
  });
};

// Utility functions for new features
function getExpiryBadgeClass(status) {
  switch(status) {
    case 'expired': return 'bg-danger';
    case 'expiring_soon': return 'bg-warning';
    case 'expiring_warning': return 'bg-info';
    case 'valid': return 'bg-success';
    default: return 'bg-secondary';
  }
}

function getCommunicationTypeClass(type) {
  switch(type) {
    case 'email': return 'primary';
    case 'sms': return 'info';
    case 'whatsapp': return 'success';
    case 'call': return 'warning';
    default: return 'secondary';
  }
}

// Mobile menu functions
function toggleMobileSidebar() {
  const sidebar = document.querySelector('.sidebar');
  const overlay = document.querySelector('.mobile-overlay');
  
  if (sidebar && overlay) {
    sidebar.classList.toggle('show');
    overlay.classList.toggle('show');
    document.body.classList.toggle('sidebar-open');
  }
}

// Performance optimizations
function debounce(func, wait) {
  let timeout;
  return function executedFunction(...args) {
    const later = () => {
      clearTimeout(timeout);
      func(...args);
    };
    clearTimeout(timeout);
    timeout = setTimeout(later, wait);
  };
}

// Add mobile-specific styles
const style = document.createElement('style');
style.textContent = `
  .mobile-menu-toggle {
    position: fixed;
    top: 20px;
    left: 20px;
    z-index: 1050;
    background: #dc3545;
    color: white;
    border: none;
    border-radius: 50%;
    width: 50px;
    height: 50px;
    display: none;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  }
  
  .mobile-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1040;
    display: none;
  }
  
  .mobile-overlay.show {
    display: block;
  }
  
  @media (max-width: 768px) {
    .mobile-menu-toggle {
      display: flex;
    }
    
    .sidebar {
      transform: translateX(-100%);
      transition: transform 0.3s ease;
    }
    
    .sidebar.show {
      transform: translateX(0);
    }
    
    .sidebar-open {
      overflow: hidden;
    }
  }
  
  .loading-spinner {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 3px solid #f3f3f3;
    border-top: 3px solid #3498db;
    border-radius: 50%;
    animation: spin 1s linear infinite;
  }
  
  @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
  
  /* Fix filter text visibility */
  .form-select {
    color: #000 !important;
    background-color: #fff !important;
  }
  
  .form-select option {
    color: #000 !important;
    background-color: #fff !important;
  }
  
  .form-select:focus {
    color: #000 !important;
    background-color: #fff !important;
  }
  
  .form-select option:first-child {
    color: #6c757d !important;
    background-color: #fff !important;
  }
  
  /* Specific fix for filter selects */
  #filterStatus, #filterTerms, #filterBalance, #filterLastOrder, #filterHealth, #filterCredit {
    color: #000 !important;
    background-color: #fff !important;
  }
  
  #filterStatus option, #filterTerms option, #filterBalance option, #filterLastOrder option, #filterHealth option, #filterCredit option {
    color: #000 !important;
    background-color: #fff !important;
  }
`;
document.head.appendChild(style);
</script>
<script>
// Sidebar search
document.getElementById('clientFilter')?.addEventListener('input', function(){
  const q=this.value.toLowerCase();
  document.querySelectorAll('#clientList a').forEach(a=>{
    const name=a.dataset.name?.toLowerCase()||'';
    const phone=a.dataset.phone?.toLowerCase()||'';
    const visible = name.includes(q) || phone.includes(q);
    a.style.display = visible ? 'block' : 'none';
  });
});

// Mobile menu toggle
document.addEventListener('click', function(e) {
  if (e.target.classList.contains('mobile-overlay')) {
    toggleMobileSidebar();
  }
});

// Touch gestures for mobile
let startY = 0;
let startX = 0;

document.addEventListener('touchstart', function(e) {
  startY = e.touches[0].clientY;
  startX = e.touches[0].clientX;
});

document.addEventListener('touchmove', function(e) {
  if (!startY || !startX) return;
  
  const currentY = e.touches[0].clientY;
  const currentX = e.touches[0].clientX;
  const diffY = startY - currentY;
  const diffX = startX - currentX;
  
  // Swipe down to refresh
  if (diffY < -50 && Math.abs(diffX) < 50) {
    location.reload();
  }
  
  // Swipe left to close mobile menu
  if (diffX > 50 && Math.abs(diffY) < 50) {
    const sidebar = document.querySelector('.sidebar.show');
    if (sidebar) {
      toggleMobileSidebar();
    }
  }
});

document.addEventListener('touchend', function() {
  startY = 0;
  startX = 0;
});

// Pull to refresh
let pullStart = 0;
let pullDistance = 0;

document.addEventListener('touchstart', function(e) {
  if (window.scrollY === 0) {
    pullStart = e.touches[0].clientY;
  }
});

document.addEventListener('touchmove', function(e) {
  if (pullStart && window.scrollY === 0) {
    pullDistance = e.touches[0].clientY - pullStart;
    if (pullDistance > 0) {
      e.preventDefault();
      document.body.style.transform = `translateY(${Math.min(pullDistance * 0.5, 100)}px)`;
    }
  }
});

document.addEventListener('touchend', function() {
  if (pullDistance > 100) {
    location.reload();
  }
  document.body.style.transform = '';
  pullStart = 0;
  pullDistance = 0;
});

// Load client into edit modal
function showEditClientModal(clientId) {
  if (!clientId) { 
    alert('Client ID missing!'); 
    return; 
  }
  
  fetch('operation/ajax_get_client.php?id=' + clientId)
    .then(res => res.json())
    .then(resp => {
      if (!resp.success || !resp.client) { 
        alert('Client not found'); 
        return; 
      }
      
      var client = resp.client;
      document.getElementById('edit-client-id').value = client.id || '';
      document.getElementById('edit-client-name').value = client.client_name || '';
      document.getElementById('edit-client-email').value = client.email || '';
      document.getElementById('edit-client-payment').value = client.payment || '';
      document.getElementById('edit-client-mobile').value = client.mobile_num || '';
      document.getElementById('edit-client-cell').value = client.cell_num || '';
      document.getElementById('edit-client-rate').value = client.rate || '';
      document.getElementById('edit-client-address').value = client.address || '';
      document.getElementById('edit-client-trn').value = client.trn || '';
      document.getElementById('edit-client-balance').value = client.balance || '';
      document.getElementById('edit-client-terms').value = client.terms || 'cash';
      document.getElementById('edit-client-default-vat').value = client.default_vat_rate || '';
      document.getElementById('edit-client-credit-limit').value = client.credit_limit || '';
      document.getElementById('edit-client-key_le').checked = client.key_le === 'Key';
      
      new bootstrap.Modal(document.getElementById('editClientModal')).show();
    })
    .catch(() => alert('Error loading client details!'));
}

// Save edit
document.getElementById('edit-client-form')?.addEventListener('submit', function(e) {
  e.preventDefault();
  const fd = new FormData(this);
  if (!fd.get('key_le')) fd.set('key_le', 'No key');
  
  // Show loading state
  const submitBtn = this.querySelector('button[type="submit"]');
  const originalText = submitBtn.innerHTML;
  submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
  submitBtn.disabled = true;
  
  fetch('operation/ajax_update_client.php', {method: 'POST', body: fd})
    .then(r => r.json())
    .then(j => {
      if (j.success) { 
        // Show success message
        showToast('success', 'Client updated successfully!');
        
        // Close modal
        const modal = bootstrap.Modal.getInstance(document.getElementById('editClientModal'));
        if (modal) {
          modal.hide();
        }
        
        // Reload page after a short delay to show the toast
        setTimeout(() => {
          fetchClientList({ preset: clientsListPreset });
          selectClient(parseInt(j.client.id, 10));
        }, 1500);
      } else {
        const el = document.getElementById('edit-client-error'); 
        el.textContent = j.error || 'Update failed'; 
        el.style.display = '';
        
        // Restore button state
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
      }
    })
    .catch(() => {
      const el = document.getElementById('edit-client-error'); 
      el.textContent = 'Server error'; 
      el.style.display = '';
      
      // Restore button state
      submitBtn.innerHTML = originalText;
      submitBtn.disabled = false;
    });
});

// Delete client
async function confirmDeleteClient(clientId) {
  try {
    if (!clientId) {
      const active = document.querySelector('#clientList .list-group-item.active');
      if (!active) { 
        alert('No client selected!'); 
        return; 
      }
      clientId = active.getAttribute('data-client-id');
    }
    
    if (!confirm('Delete this client? If they have history, they will be marked inactive.')) return;

    const res = await fetch('operation/ajax_delete_client.php', {
      method: 'POST',
      body: new URLSearchParams({ client_id: clientId })
    });

    const text = await res.text();
    let data; 
    try { 
      data = JSON.parse(text); 
    } catch { 
      data = null; 
    }
    
    if (!res.ok || !data) { 
      alert((data && data.error) || text || 'Server error.'); 
      return; 
    }
    
    if (data.success !== true) { 
      alert(data.error || 'Delete failed.'); 
      return; 
    }

    if (data.soft_deleted) {
      alert('Client has related data and was marked inactive.');
    } else {
      alert('Client deleted successfully.');
    }
    
    // Reload the page to refresh the client list
    location.reload();
    
  } catch (error) {
    console.error('Delete error:', error);
    alert('Error deleting client: ' + error.message);
  }
}

// Set client status (VIP, active, inactive, at_risk)
async function setClientStatus(clientId, status) {
  try {
    if (!clientId) {
      const active = document.querySelector('#clientList .list-group-item.active');
      if (!active) { 
        alert('No client selected!'); 
        return; 
      }
      clientId = active.getAttribute('data-client-id');
    }
    
    const statusText = status === 'vip' ? 'VIP' : status;
    if (!confirm(`Set this client as ${statusText}?`)) return;

    const res = await fetch('operation/ajax_set_client_status.php', {
      method: 'POST',
      body: new URLSearchParams({ client_id: clientId, status: status })
    });

    const text = await res.text();
    let data; 
    try { 
      data = JSON.parse(text); 
    } catch { 
      data = null; 
    }
    
    if (!res.ok || !data) { 
      alert((data && data.error) || text || 'Server error.'); 
      return; 
    }
    
    if (data.success !== true) { 
      alert(data.error || 'Status update failed.'); 
      return; 
    }

    alert(`Client set as ${statusText} successfully!`);
    
    // Reload the page to refresh the client list
    location.reload();
    
  } catch (error) {
    console.error('Status update error:', error);
    alert('Error updating client status: ' + error.message);
  }
}
</script>

<!-- Toasts -->
<div aria-live="polite" aria-atomic="true" class="position-fixed top-0 start-50 translate-middle-x p-3" style="z-index:1080">
  <div id="appToast" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div id="appToastBody" class="toast-body"></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>
</body>
</html>
