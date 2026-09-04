<?php
// hr/payroll_run_slips_zip.php

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/company_helper.php';
require_once __DIR__.'/includes/hr_payroll_company_access.php';
require_once __DIR__.'/includes/hr_payroll_accounting.php';
require_role(['Owner','Admin','HR'], $conn);

$run_id=(int)($_GET['id']??0);
if(!$run_id){ http_response_code(400); exit('Missing run id'); }

$run=$conn->prepare("SELECT * FROM payroll_runs WHERE id=? AND status IN ('posted','finalized','paid')");
$run->execute([$run_id]); $run=$run->fetch(PDO::FETCH_ASSOC);
if(!$run){ http_response_code(404); exit('Run not found or not finalized'); }
hr_payroll_require_run_access($conn, $run);
$payrollType = (($run['payroll_type'] ?? 'wps') === 'cash') ? 'cash' : 'wps';

$rows=$conn->prepare("
  SELECT pi.*, e.employee_code, e.full_name
  FROM payroll_items pi
  JOIN employees e ON e.id=pi.employee_id
  WHERE pi.payroll_run_id=?
  ORDER BY e.full_name
");
$rows->execute([$run_id]);
$items=$rows->fetchAll(PDO::FETCH_ASSOC);
if(!$items){ http_response_code(404); exit('No items to export'); }

$zipname = sys_get_temp_dir()."/payslips_run_{$run_id}_".time().".zip";
$zip = new ZipArchive();
if ($zip->open($zipname, ZipArchive::CREATE)!==TRUE) { exit("Cannot create zip"); }

foreach($items as $pi){
  ob_start();
  $period = $run['period_from'].' → '.$run['period_to'];
  ?>
  <!doctype html><html><head><meta charset="utf-8">
  <style>body{font-family:Arial,Helvetica,sans-serif;font-size:12px}
  h3{margin:0 0 8px 0}</style></head><body>
  <h3>Payslip</h3>
  <div>Payroll Type: <?= htmlspecialchars(hr_payroll_run_type_label($payrollType)) ?></div>
  <div>Period: <?= htmlspecialchars($period) ?></div>
  <div>Employee: <b><?= htmlspecialchars($pi['full_name']) ?></b> (<?= htmlspecialchars($pi['employee_code']) ?>)</div>
  <hr>
  <table>
    <tr><td style="width:180px">Basic</td><td><?= number_format($pi['base_pay'],2) ?></td></tr>
    <tr><td>Allowance</td><td><?= number_format($pi['allowance'],2) ?></td></tr>
    <tr><td>Bonus / OT</td><td><?= number_format($pi['bonus'],2) ?></td></tr>
    <tr><td>Deductions</td><td><?= number_format($pi['deductions'],2) ?></td></tr>
    <tr><td><b>Net Pay</b></td><td><b><?= number_format($pi['net_pay'],2) ?></b></td></tr>
  </table>
  <?php if(!empty($pi['notes'])): ?><div>Notes: <?= htmlspecialchars($pi['notes']) ?></div><?php endif; ?>
  </body></html>
  <?php
  $html = ob_get_clean();
  $safe = preg_replace('/[^A-Za-z0-9_\-]+/','_', $pi['full_name'].'_'.$pi['employee_code']);
  $zip->addFromString("payslip_{$safe}.html", $html);
}
$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="payslips_run_'.$run_id.'.zip"');
header('Content-Length: '.filesize($zipname));
readfile($zipname);
@unlink($zipname);
