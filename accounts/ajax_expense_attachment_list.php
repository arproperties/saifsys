<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_role(['Owner','Admin','Account'], $conn);

header('Content-Type: application/json');

$expense_id = (int)($_GET['expense_id'] ?? 0);
if ($expense_id <= 0) { echo json_encode(['success'=>false, 'items'=>[]]); exit; }

$st = $conn->prepare("SELECT id, file_name, file_path, file_size, uploaded_at FROM expense_attachments WHERE expense_id=? ORDER BY id DESC");
$st->execute([$expense_id]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$items = array_map(function($r){
  return [
    'id'         => (int)$r['id'],
    'file_name'  => $r['file_name'],
    'url'        => '../'.$r['file_path'],   // path relative to accounts/
    'size_fmt'   => formatBytes((int)$r['file_size']),
    'uploaded_at'=> $r['uploaded_at'],
  ];
}, $rows);

echo json_encode(['success'=>true, 'items'=>$items]);

function formatBytes($bytes){
  $u = ['B','KB','MB','GB']; $i=0;
  while ($bytes >= 1024 && $i < count($u)-1) { $bytes/=1024; $i++; }
  return number_format($bytes, ($i?1:0)).' '.$u[$i];
}
