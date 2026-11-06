<?php
// cron/re_rent_invoices/fix_deposit_double_post.php
// One-off repair. Invoice Mode receipts that included a security deposit were
// posted twice for the deposit part: once by the deposit journal (Dr Bank / Cr 2200)
// and again inside the receipt journal (Dr Bank / Cr 1310). Bank was overstated and
// Rent Receivable over-credited by the deposit.
//
// For each such receipt this does what Edit Receipt does: reverse the receipt journal
// on its own date, reverse its tenant ledger entry, and post it again with the fixed
// post_invoice_mode_receipt_to_accounting(). The deposit journal is left as it is.
// Command line only.
//
//   php fix_deposit_double_post.php            dry run: list receipts, change nothing
//   php fix_deposit_double_post.php --apply    repair them

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Asia/Dubai');

require_once __DIR__ . '/../../includes/db_connect.php';
@require_once __DIR__ . '/../../modules/realestate/accounting/accounting_integration.php';
@require_once __DIR__ . '/../../modules/realestate/includes/receipt_edit_helper.php';

$apply = in_array('--apply', $argv, true);

// Receipt journal still credits AR for a deposit that has its own live deposit journal.
$rows = $conn->query("
    SELECT p.id, p.company_id, p.receipt_number, p.payment_date, p.amount,
           pj.id AS receipt_journal_id,
           (SELECT SUM(jl.debit_amount) FROM re_journal_lines jl
             JOIN re_chart_of_accounts a ON a.id = jl.account_id
            WHERE jl.journal_id = dj.id AND a.account_type = 'Asset') AS deposit_amount,
           (SELECT SUM(jl.debit_amount) FROM re_journal_lines jl
             JOIN re_chart_of_accounts a ON a.id = jl.account_id
            WHERE jl.journal_id = pj.id AND a.account_type = 'Asset') AS receipt_bank_debit
    FROM re_payments p
    JOIN re_journal_headers pj ON pj.company_id = p.company_id AND pj.reference_type = 'payment'
         AND pj.reference_id = p.id AND pj.journal_type = 'payment' AND pj.is_posted = 1 AND COALESCE(pj.is_reversed, 0) = 0
    JOIN re_journal_headers dj ON dj.company_id = p.company_id AND dj.reference_type = 'security_deposit_receipt'
         AND dj.reference_id = p.id AND dj.journal_type = 'deposit' AND dj.is_posted = 1 AND COALESCE(dj.is_reversed, 0) = 0
    WHERE p.accounting_mode = 'invoice'
    ORDER BY p.payment_date, p.id
")->fetchAll(PDO::FETCH_ASSOC);

// Only receipts whose journal still carries the full receipt amount (not yet fixed).
$rows = array_values(array_filter($rows, static fn($r) => abs((float)$r['receipt_bank_debit'] - (float)$r['amount']) < 0.01));

$total = 0.0;
$fixed = 0;
$failed = 0;
foreach ($rows as $r) {
    $total += (float)$r['deposit_amount'];
    $line = sprintf('%s  %s  receipt %s  deposit counted twice %s', $r['receipt_number'], $r['payment_date'], number_format((float)$r['amount'], 2), number_format((float)$r['deposit_amount'], 2));
    if (!$apply) {
        echo $line, "\n";
        continue;
    }
    $companyId = (int)$r['company_id'];
    $conn->beginTransaction();
    try {
        $rev = reverse_journal((int)$r['receipt_journal_id'], 'Deposit was posted twice - receipt re-posted', null, null);
        if (empty($rev['success'])) {
            throw new RuntimeException('reverse: ' . ($rev['error'] ?? 'unknown'));
        }
        re_receipt_edit_reverse_tenant_ledger($conn, $companyId, (int)$r['receipt_journal_id'], (int)$rev['reversal_journal_id']);
        $post = post_invoice_mode_receipt_to_accounting((int)$r['id'], $companyId, null);
        if (empty($post['success'])) {
            throw new RuntimeException('re-post: ' . ($post['error'] ?? 'unknown'));
        }
        $conn->commit();
        $fixed++;
        echo "fixed  $line\n";
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $failed++;
        echo "FAILED $line: ", $e->getMessage(), "\n";
    }
}

echo sprintf("\n%d receipt(s), deposits counted twice: AED %s%s\n", count($rows), number_format($total, 2), $apply ? " | fixed $fixed, failed $failed" : ' (dry run - add --apply to repair)');
exit($failed > 0 ? 1 : 0);
