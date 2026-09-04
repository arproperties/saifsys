<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_role(['Owner','Admin','Account'], $conn);
header('Content-Type: application/json');

$id    = (int)($_POST['id'] ?? 0);
$no    = trim($_POST['account_no'] ?? '');
$name  = trim($_POST['name'] ?? '');
$type  = trim($_POST['type'] ?? '');
$norm  = trim($_POST['normal_balance'] ?? '');
$pid   = ($_POST['parent_id'] ?? '') === '' ? null : (int)$_POST['parent_id'];
$hdr   = !empty($_POST['is_header']) ? 1 : 0;
$act   = !empty($_POST['is_active']) ? 1 : 0;

if ($no==='' || $name==='' || !in_array($type,['Asset','Liability','Equity','Revenue','Expense'],true) || !in_array($norm,['debit','credit'],true)) {
  echo json_encode(['success'=>false,'error'=>'Invalid input']); exit;
}

// Validate normal balance matches account type (accounting constraint)
// Assets: normal balance = debit
// Liabilities: normal balance = credit
// Equity: normal balance = credit
// Revenue: normal balance = credit
// Expenses: normal balance = debit
$expectedNorm = null;
switch ($type) {
  case 'Asset':
  case 'Expense':
    $expectedNorm = 'debit';
    break;
  case 'Liability':
  case 'Equity':
  case 'Revenue':
    $expectedNorm = 'credit';
    break;
}
if ($expectedNorm !== null && $norm !== $expectedNorm) {
  echo json_encode(['success'=>false,'error'=>"Invalid normal balance for {$type} account. {$type} accounts must have normal balance of '{$expectedNorm}', not '{$norm}'."]); exit;
}

try{
  if ($id>0){
    $st = $conn->prepare("UPDATE chart_of_accounts
      SET account_no=?, name=?, type=?, parent_id=?, normal_balance=?, is_header=?, is_active=?, updated_at=NOW()
      WHERE id=?");
    $st->execute([$no,$name,$type,$pid,$norm,$hdr,$act,$id]);
  } else {
    $st = $conn->prepare("INSERT INTO chart_of_accounts
      (account_no,name,type,parent_id,normal_balance,is_header,is_active,created_at)
      VALUES (?,?,?,?,?,?,?,NOW())");
    $st->execute([$no,$name,$type,$pid,$norm,$hdr,$act]);
  }
  echo json_encode(['success'=>true]);
}catch(Throwable $e){
  echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
