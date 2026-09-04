<?php
/* hr/overtime_export.php — CSV export */


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_role(['Owner','Admin','HR'], $conn);

$month = $_GET['month'] ?? date('Y-m');
$employee_id = (int)($_GET['employee_id'] ?? 0);

// range for month
$from = date('Y-m-01', strtotime($month.'-01'));
$to   = date('Y-m-t',  strtotime($month.'-01'));

$where = ["ot.ot_date BETWEEN ? AND ?", "ot.status='approved'"];
$prm = [$from, $to];
if ($employee_id) { $where[]="ot.employee_id=?"; $prm[]=$employee_id; }
$SQLWHERE = 'WHERE '.implode(' AND ', $where);

$rows = $conn->prepare("
  SELECT ot.*, e.employee_code, e.full_name
  FROM overtime_entries ot
  JOIN employees e ON e.id=ot.employee_id
  $SQLWHERE
  ORDER BY e.full_name, ot.ot_date
");
$rows->execute($prm);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="overtime_'.$month.'.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Employee Code','Employee Name','Date','Start','End','Manual Hours','Rounded Hours','Multiplier','Rate','Amount','Reason','Approved at']);

$total_amount = 0; $total_hours = 0;
while ($r = $rows->fetch(PDO::FETCH_ASSOC)) {
  fputcsv($out, [
    $r['employee_code'],
    $r['full_name'],
    $r['ot_date'],
    $r['start_time'],
    $r['end_time'],
    $r['manual_hours'],
    number_format((float)$r['pay_hours'],2),
    number_format((float)$r['pay_multiplier'],2),
    number_format((float)$r['pay_rate'],2),
    number_format((float)$r['pay_amount'],2),
    $r['reason'],
    $r['decided_at'],
  ]);
  $total_amount += (float)$r['pay_amount'];
  $total_hours  += (float)$r['pay_hours'];
}
fputcsv($out, []);
fputcsv($out, ['TOTAL','','','','','', number_format($total_hours,2),'','', number_format($total_amount,2)]);
fclose($out);
