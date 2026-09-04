<?php
require_once __DIR__ . '/re_bank_reco_bootstrap.php';
re_bank_reco_require_tables($conn);
re_bank_reco_guard($conn, 'realestate.bank_reconciliation.view');

if (!re_bank_reco_csrf_ok()) {
    re_bank_reco_json_error('CSRF token invalid or missing.');
}

$line_id = (int) ($_POST['line_id'] ?? 0);
$note = trim((string) ($_POST['note'] ?? ''));
if ($line_id <= 0 || $note === '') {
    re_bank_reco_json_error('line_id and note required');
}
if (!re_db_table_exists($conn, 're_bank_line_notes')) {
    re_bank_reco_json_error('Discussion notes table not installed. Run migrations/re_bank_reconciliation_v2.sql');
}

$line = re_bank_get_statement_line($conn, $line_id, $cid);
if (!$line) {
    re_bank_reco_json_error('Statement line not found');
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
        INSERT INTO re_bank_line_notes
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
    $conn->prepare("UPDATE re_bank_statement_lines SET status = 'investigating' WHERE id = ? AND company_id = ?")
        ->execute([$line_id, $cid]);
    re_bank_rec_audit($conn, $cid, (int) $line['bank_account_id'], $line_id, null, 'discuss_note', null, json_encode(['note' => $note]), $uid, 'discuss');
    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    re_bank_reco_json_error($e->getMessage());
}
