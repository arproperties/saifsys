<?php
// accounts/expense_delete.php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/gl_posting.php';
require_once __DIR__.'/../includes/sm_expense_service.php';
require_role(['Owner','Admin','Account'], $conn);
$id = (int)($_POST['id'] ?? 0);
if ($id<=0){ header('Location: expenses.php'); exit; }

try {
  $conn->beginTransaction();
  
  // Fetch expense data before deletion for audit log
  $stmt = $conn->prepare("SELECT * FROM expenses WHERE id=? LIMIT 1");
  $stmt->execute([$id]);
  $expenseData = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$expenseData) {
    throw new RuntimeException("Expense not found: {$id}");
  }
  
  // Check if already voided
  if ($expenseData['status'] === 'void') {
    $conn->rollBack();
    header('Location: expenses.php?rev=0&msg=' . urlencode('Expense is already voided'));
    exit;
  }

  // Block void if prepaid amortization already posted
  $schedule = sm_expense_prepaid_schedule($conn, $id);
  if ($schedule && (int)($schedule['posted_cnt'] ?? 0) > 0) {
    throw new RuntimeException(
      'Cannot void: prepaid amortization already posted for schedule #' . (int)$schedule['id']
      . '. Reverse those amortization journals first (or contact Owner).'
    );
  }
  
  // find linked journal(s) and reverse
  $journalIds = gl_find_expense_journals($conn, $id);
  foreach ($journalIds as $jid) {
    gl_reverse_journal($conn, $jid);
  }

  if ($schedule && in_array((string)$schedule['status'], ['active', 'completed'], true)) {
    sm_prepaid_cancel_schedule_if_safe($conn, (int)$schedule['id']);
  }
  
  // Update expense status to void
  $conn->prepare("UPDATE expenses SET status='void' WHERE id=?")->execute([$id]);
  
  $conn->commit();
  
  // Audit Log: Track expense deletion
  require_once __DIR__ . '/../includes/AuditService.php';
  AuditService::logDelete('expenses', $id, $expenseData, "Voided expense #{$id}");
  
  header('Location: expenses.php?rev=1');
} catch(Throwable $e) {
  if ($conn->inTransaction()) {
    $conn->rollBack();
  }
  error_log("Error voiding expense #{$id}: " . $e->getMessage());
  header('Location: expenses.php?rev=0&msg=' . urlencode('Failed to void expense: ' . $e->getMessage()));
}
