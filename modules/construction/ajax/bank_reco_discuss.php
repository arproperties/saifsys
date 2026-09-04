<?php
require_once __DIR__ . '/bank_reco_bootstrap.php';
co_bank_reco_require_tables($conn);
co_bank_reco_guard($conn, 'construction.bank_reconciliation.view');

if (!co_bank_reco_csrf_ok()) {
    co_bank_reco_json_error('CSRF token invalid or missing.');
}

$line_id = (int) ($_POST['line_id'] ?? 0);
$note = trim((string) ($_POST['note'] ?? ''));
if ($line_id <= 0 || $note === '') {
    co_bank_reco_json_error('line_id and note required');
}
if (!co_db_table_exists($conn, 'co_bank_line_notes')) {
    co_bank_reco_json_error('Discussion notes table not installed. Run migrations/construction_bank_reconciliation_v2.sql');
}

$line = co_bank_get_statement_line($conn, $line_id, $cid);
if (!$line) {
    co_bank_reco_json_error('Statement line not found');
}

$uid = current_user_id();
$assignee = (int) ($_POST['assignee_user_id'] ?? 0) ?: null;
$needInvoice = !empty($_POST['need_invoice']) ? 1 : 0;
$needApproval = !empty($_POST['need_approval']) ? 1 : 0;
$needVendor = !empty($_POST['need_vendor_confirmation']) ? 1 : 0;
$followUp = trim((string) ($_POST['follow_up_date'] ?? ''));
$followUp = $followUp !== '' ? $followUp : null;

try {
    $conn->beginTransaction();
    $st = $conn->prepare('
        INSERT INTO co_bank_line_notes
          (company_id, bank_account_id, statement_line_id, note, assignee_user_id, need_invoice, need_approval, need_vendor_confirmation, follow_up_date, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $st->execute([
        $cid,
        (int) $line['bank_account_id'],
        $line_id,
        $note,
        $assignee,
        $needInvoice,
        $needApproval,
        $needVendor,
        $followUp,
        $uid ?: null,
    ]);
    if (co_db_column_exists($conn, 'co_bank_statement_lines', 'status')) {
        $conn->prepare("UPDATE co_bank_statement_lines SET status = 'discussed', updated_at = NOW() WHERE id = ? AND company_id = ?")
            ->execute([$line_id, $cid]);
    }
    co_bank_reco_audit($conn, $cid, (int) $line['bank_account_id'], $line_id, null, 'discuss_note', null, ['note' => $note], $uid);
    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    co_bank_reco_json_error($e->getMessage());
}
