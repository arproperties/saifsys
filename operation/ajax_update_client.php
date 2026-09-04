<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
header('Content-Type: application/json');

function jerr($m){ echo json_encode(['success'=>false,'error'=>$m]); exit; }

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) jerr('Missing client ID.');

$client_name = trim($_POST['client_name'] ?? '');
$email       = trim($_POST['email'] ?? '');
$payment     = trim($_POST['payment'] ?? '');
$mobile_num  = trim($_POST['mobile_num'] ?? '');
$cell_num    = trim($_POST['cell_num'] ?? '');
$rate        = (string)($_POST['rate'] ?? '');
$address     = trim($_POST['address'] ?? '');
$trn         = trim($_POST['trn'] ?? '');
$balance     = (string)($_POST['balance'] ?? '0');
$key_le      = ($_POST['key_le'] ?? 'No key') === 'Key' ? 'Key' : 'No key';

// Optional / new AR fields
$terms             = trim($_POST['terms'] ?? 'cash');
$default_vat_rate  = is_numeric($_POST['default_vat_rate'] ?? null) ? (float)$_POST['default_vat_rate'] : 5.00;
$credit_limit      = is_numeric($_POST['credit_limit'] ?? null) ? (float)$_POST['credit_limit'] : 0.00;

// Required
if ($client_name === '' || $email === '' || $payment === '' || $mobile_num === '') {
  jerr('Missing required fields.');
}

// Uniqueness guard (allow same record)
$du = $conn->prepare("SELECT id FROM client WHERE (client_name = ? OR email = ?) AND id <> ? LIMIT 1");
$du->execute([$client_name, $email, $id]);
if ($du->fetchColumn()) jerr('Another client already uses this name or email.');

$sql = "
  UPDATE client
     SET client_name = :client_name,
         email       = :email,
         mobile_num  = :mobile_num,
         cell_num    = :cell_num,
         rate        = :rate,
         address     = :address,
         key_le      = :key_le,
         trn         = :trn,
         balance     = :balance,
         payment     = :payment,
         terms       = :terms,
         default_vat_rate = :default_vat_rate,
         credit_limit     = :credit_limit
   WHERE id = :id
";
$st = $conn->prepare($sql);
$ok = $st->execute([
  ':client_name'      => $client_name,
  ':email'            => $email,
  ':mobile_num'       => $mobile_num,
  ':cell_num'         => $cell_num !== '' ? $cell_num : null,
  ':rate'             => is_numeric($rate) ? (float)$rate : null,
  ':address'          => $address !== '' ? $address : null,
  ':key_le'           => $key_le,
  ':trn'              => $trn !== '' ? $trn : null,
  ':balance'          => is_numeric($balance) ? (float)$balance : 0.00,
  ':payment'          => $payment,
  ':terms'            => $terms,
  ':default_vat_rate' => $default_vat_rate,
  ':credit_limit'     => $credit_limit,
  ':id'               => $id
]);

if (!$ok) {
  echo json_encode(['success'=>false, 'error'=>'DB update failed.']);
  exit;
}

$row = $conn->query("SELECT * FROM client WHERE id=".$id)->fetch(PDO::FETCH_ASSOC);
echo json_encode(['success'=>true,'client'=>$row]);
