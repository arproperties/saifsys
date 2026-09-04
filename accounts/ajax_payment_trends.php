<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_role(['Owner','Admin','Account'], $conn);

header('Content-Type: application/json');

try {
  // Get payment data for last 6 months
  $months = [];
  $amounts = [];
  
  for ($i = 5; $i >= 0; $i--) {
    $date = date('Y-m-01', strtotime("-$i months"));
    $month_name = date('M Y', strtotime($date));
    $months[] = $month_name;
    
    // Get total payments for this month
    $st = $conn->prepare("
      SELECT COALESCE(SUM(amount), 0) as total
      FROM receipts 
      WHERE receipt_date >= ? AND receipt_date < DATE_ADD(?, INTERVAL 1 MONTH)
    ");
    $st->execute([$date, $date]);
    $amounts[] = (float)$st->fetchColumn();
  }
  
  echo json_encode([
    'success' => true,
    'months' => $months,
    'amounts' => $amounts
  ]);
} catch (Throwable $e) {
  echo json_encode([
    'success' => false,
    'error' => 'Failed to load payment trends data'
  ]);
}
