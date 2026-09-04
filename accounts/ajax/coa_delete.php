<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_role(['Owner','Admin','Account'], $conn);
header('Content-Type: application/json');

$id = (int)($_POST['id'] ?? 0);
if ($id<=0){ echo json_encode(['success'=>false,'error'=>'Missing id']); exit; }

try{
  // If referenced in journals, just deactivate; else delete.
  $st = $conn->prepare("SELECT COUNT(*) FROM gl_journal_lines WHERE account_id=?");
  $st->execute([$id]);
  $cnt = (int)$st->fetchColumn();

  if ($cnt>0){
    $conn->prepare("UPDATE chart_of_accounts SET is_active=0, updated_at=NOW() WHERE id=?")->execute([$id]);
  } else {
    $conn->prepare("DELETE FROM chart_of_accounts WHERE id=?")->execute([$id]);
  }
  echo json_encode(['success'=>true]);
}catch(Throwable $e){
  echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
