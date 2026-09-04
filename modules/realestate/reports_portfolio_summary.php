<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/reporting_mode_helper.php';
require_login(); require_module_access($conn, MODULE_REALESTATE);
$brand = getBrandSettings($conn); $companyId = current_company_id($conn) ?: 1;
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');} function m($n){return number_format((float)$n,2);} 
$modeCounts = re_report_mode_counts($conn,$companyId);
$outstanding = re_report_combined_outstanding($conn,$companyId,date('Y-m-d'));
$collections = re_report_combined_collections($conn,$companyId,date('Y-m-01'),date('Y-m-d'));
$rent = re_report_combined_rent_roll($conn,$companyId);
$counts = ['buildings'=>0,'units'=>0,'occupied'=>0,'vacant'=>0,'active_leases'=>0,'expiring'=>0,'unreconciled_bank'=>0,'deposit_liability'=>0];
foreach ([
    'buildings'=>"SELECT COUNT(*) FROM re_buildings WHERE company_id=? AND is_active=1",
    'units'=>"SELECT COUNT(*) FROM re_units WHERE company_id=?",
    'occupied'=>"SELECT COUNT(*) FROM re_units WHERE company_id=? AND status='occupied'",
    'vacant'=>"SELECT COUNT(*) FROM re_units WHERE company_id=? AND status='vacant'",
    'active_leases'=>"SELECT COUNT(*) FROM re_leases WHERE company_id=? AND status='active'",
    'expiring'=>"SELECT COUNT(*) FROM re_leases WHERE company_id=? AND status='active' AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 120 DAY)",
] as $k=>$sql){try{$st=$conn->prepare($sql);$st->execute([$companyId]);$counts[$k]=(int)$st->fetchColumn();}catch(Throwable $e){}}
try{$st=$conn->prepare("SELECT COALESCE(SUM(ABS(net_amount)),0) FROM re_bank_statement_lines WHERE company_id=? AND status IN ('unmatched','partially_matched','investigating')");$st->execute([$companyId]);$counts['unreconciled_bank']=(float)$st->fetchColumn();}catch(Throwable $e){}
try{$st=$conn->prepare("SELECT COALESCE(SUM(gl.credit_amount-gl.debit_amount),0) FROM re_general_ledger gl JOIN re_chart_of_accounts coa ON coa.id=gl.account_id WHERE gl.company_id=? AND coa.account_code=?");$st->execute([$companyId,'2200']);$counts['deposit_liability']=(float)$st->fetchColumn();}catch(Throwable $e){}
$occPct=$counts['units']>0?round($counts['occupied']/$counts['units']*100,1):0;
$pageTitle='Portfolio Summary'; require_once __DIR__ . '/includes/re_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4"><div class="page-header-label"><i class="bi bi-speedometer2"></i> Portfolio Summary</div><a href="reports.php" class="btn btn-outline-secondary">Reports Center</a></div>
<div class="alert alert-info">This report includes both Legacy Mode and Invoice Mode leases. Legacy values use historical installment/payment logic. Invoice Mode values use obligations, invoices, receipts, allocations, and GL.</div>
<div class="row g-3 mb-4">
<?php foreach ([['Buildings',$counts['buildings']],['Units',$counts['units']],['Occupied',$counts['occupied']],['Vacant',$counts['vacant']],['Occupancy %',$occPct.'%'],['Active Leases',$counts['active_leases']],['Expiring 120 Days',$counts['expiring']],['Unreconciled Bank',m($counts['unreconciled_bank']).' AED'],['Deposit Liability',m($counts['deposit_liability']).' AED']] as $card): ?>
<div class="col-md-3"><div class="card card-round h-100"><div class="card-body text-center"><div class="text-muted small"><?=h($card[0])?></div><div class="h4 mb-0"><?=h($card[1])?></div></div></div></div>
<?php endforeach; ?>
</div>
<div class="row g-3"><div class="col-md-4"><div class="card card-round"><div class="card-header">Contracted Annual Rent</div><div class="card-body">Legacy: <strong><?=m($rent['legacy'])?></strong><br>Invoice Mode: <strong><?=m($rent['invoice'])?></strong><hr>Combined: <strong><?=m($rent['combined'])?></strong></div></div></div><div class="col-md-4"><div class="card card-round"><div class="card-header">Outstanding</div><div class="card-body">Legacy: <strong><?=m($outstanding['legacy'])?></strong><br>Invoice Mode: <strong><?=m($outstanding['invoice'])?></strong><hr>Combined: <strong><?=m($outstanding['combined'])?></strong></div></div></div><div class="col-md-4"><div class="card card-round"><div class="card-header">Collections This Month</div><div class="card-body">Legacy: <strong><?=m($collections['legacy'])?></strong><br>Invoice Mode: <strong><?=m($collections['invoice'])?></strong><hr>Combined: <strong><?=m($collections['combined'])?></strong></div></div></div></div>
<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
