<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
header('Content-Type: application/json');

$name = trim($_POST['name'] ?? '');
$trn  = trim($_POST['trn'] ?? '');
$phone= trim($_POST['phone'] ?? '');
$email= trim($_POST['email'] ?? '');
$addr = trim($_POST['address'] ?? '');

if ($name==='') { echo json_encode(['success'=>false,'error'=>'Name is required']); exit; }

try {
  $st = $conn->prepare("INSERT INTO vendors (name,trn,phone,email,address) VALUES (?,?,?,?,?)");
  $st->execute([$name,$trn,$phone,$email,$addr]);
  $vendor_id = $conn->lastInsertId();
  
  // Audit Log: Track vendor creation
  require_once __DIR__ . '/../includes/AuditService.php';
  AuditService::logCreate('vendors', $vendor_id, [
    'name' => $name,
    'trn' => $trn,
    'phone' => $phone,
    'email' => $email,
    'address' => $addr
  ], "Created vendor '{$name}'");
  
  echo json_encode(['success'=>true,'vendor'=>['id'=>$vendor_id,'name'=>$name]]);
} catch (Throwable $e) {
  echo json_encode(['success'=>false,'error'=>'Could not add supplier (maybe duplicate name)']);
}
