<?php
require_once __DIR__.'/../includes/db_connect.php'; // Adjust path if needed

header('Content-Type: application/json');

$client_name = $_GET['client_name'] ?? '';
if (!$client_name) {
    echo json_encode(['error' => 'No client name']);
    exit;
}

$stmt = $conn->prepare("SELECT client_name, email AS email, mobile_num, address, fee_charge, payment_type, balance 
                        FROM client 
                        WHERE client_name = :client_name");
$stmt->execute([':client_name' => $client_name]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    // If your field names are different, adjust here!
    echo json_encode([
        'email'        => $row['email'] ?? '',
        'mobile_num'   => $row['mobile_num'] ?? '',
        'address'      => $row['address'] ?? '',
        'fee_charge'   => $row['fee_charge'] ?? '',
        'payment_type' => $row['payment_type'] ?? '',
        'balance'      => $row['balance'] ?? ''
    ]);
} else {
    echo json_encode(['error' => 'Client not found']);
}
