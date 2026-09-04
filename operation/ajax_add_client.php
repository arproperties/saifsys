<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
header('Content-Type: application/json');

function jerr($msg){ echo json_encode(['success'=>false,'error'=>$msg]); exit; }

$client_name = trim($_POST['client_name'] ?? '');
$email       = trim($_POST['email'] ?? '');
$payment     = trim($_POST['payment'] ?? '');            // D/W/Bi-W/M from the UI
$mobile_num  = trim($_POST['mobile_num'] ?? '');
$cell_num    = trim($_POST['cell_num'] ?? '');
$rate        = (string)($_POST['rate'] ?? '');
$address     = trim($_POST['address'] ?? '');
$key_le      = ($_POST['key_le'] ?? 'No key') === 'Key' ? 'Key' : 'No key';
$trn         = trim($_POST['trn'] ?? '');
$balance     = (string)($_POST['balance'] ?? '0');

// Newer AR-related fields (optional – safe defaults)
$terms             = trim($_POST['terms'] ?? 'cash');           // cash/prepaid/15d/30d/45d/60d
$default_vat_rate  = is_numeric($_POST['default_vat_rate'] ?? null) ? (float)$_POST['default_vat_rate'] : 5.00;
$credit_limit      = is_numeric($_POST['credit_limit'] ?? null) ? (float)$_POST['credit_limit'] : 0.00;

// Additional required fields from database structure
$billing_cadence     = 'per_order';  // Default value
$billing_anchor_dow  = 1;            // Default value (Monday)
$is_active          = 1;             // Default value
$currency           = 'AED';         // Default value

// Basic validation - check all mandatory fields
if ($client_name === '' || $email === '' || $payment === '' || $mobile_num === '' || $address === '') {
  jerr('Please fill all required fields (Client Name, Email, Payment Type, Mobile Number, and Address are required).');
}

try {
  // Debug: Log the received data
  error_log("Add client data: " . print_r($_POST, true));
  
  // Prevent obvious duplicates (same name OR same email)
  $du = $conn->prepare("SELECT id FROM client WHERE client_name = ? OR email = ? LIMIT 1");
  $du->execute([$client_name, $email]);
  if ($du->fetchColumn()) {
    jerr('Client already exists (same name or email).');
  }

  $sql = "
    INSERT INTO client
      (client_name, email, mobile_num, cell_num, rate, address, key_le, trn,
       balance, payment, terms, default_vat_rate, credit_limit, is_active,
       billing_cadence, billing_anchor_dow, currency)
    VALUES
      (:client_name, :email, :mobile_num, :cell_num, :rate, :address, :key_le, :trn,
       :balance, :payment, :terms, :default_vat_rate, :credit_limit, :is_active,
       :billing_cadence, :billing_anchor_dow, :currency)
  ";
  $st = $conn->prepare($sql);
  $st->execute([
    ':client_name'      => $client_name,
    ':email'            => $email,
    ':mobile_num'       => $mobile_num,
    ':cell_num'         => $cell_num !== '' ? $cell_num : '',
    ':rate'             => is_numeric($rate) ? (float)$rate : null,
    ':address'          => $address,
    ':key_le'           => $key_le,
    ':trn'              => $trn !== '' ? $trn : null,
    ':balance'          => is_numeric($balance) ? (float)$balance : 0.00,
    ':payment'          => $payment,
    ':terms'            => $terms,
    ':default_vat_rate' => $default_vat_rate,
    ':credit_limit'     => $credit_limit,
    ':is_active'        => $is_active,
    ':billing_cadence'  => $billing_cadence,
    ':billing_anchor_dow' => $billing_anchor_dow,
    ':currency'         => $currency,
  ]);

  $newId = (int)$conn->lastInsertId();
  $row   = $conn->query("SELECT * FROM client WHERE id = ".$newId)->fetch(PDO::FETCH_ASSOC);

  echo json_encode(['success'=>true, 'client'=>$row]);
} catch (Throwable $e) {
  // Log the actual error for debugging
  error_log("Add client error: " . $e->getMessage());
  error_log("Add client error trace: " . $e->getTraceAsString());
  
  echo json_encode(['success'=>false, 'error'=>'Could not add client: ' . $e->getMessage()]);
}
