<?php
/**
 * One-off repair: Extra Service Charge invoices that are part of a rent cheque's own
 * installment ("Includes service charge +750") but were left open when that cheque
 * cleared — the receipt went to tenant credit and the credit sweep skips Extra SC.
 *
 * Applies tenant credit to such an invoice only when the cheque has a receipt and the
 * credit fully clears the invoice. Dry run unless applied.
 *
 * CLI: php tools/re_repair_linked_extra_service_credit.php [--apply]
 * Web: Owner/Admin; review the list, then press Apply.
 */
declare(strict_types=1);

$isCli = (PHP_SAPI === 'cli');
if (!$isCli && session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/db_connect.php';
if (!$isCli) {
    require_once dirname(__DIR__) . '/includes/auth.php';
    require_role(['Owner', 'Admin'], $conn);
    header('Content-Type: text/html; charset=utf-8');
}
require_once dirname(__DIR__) . '/modules/realestate/accounting/accounting_integration.php';

$apply = $isCli
    ? in_array('--apply', $argv ?? [], true)
    : ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['apply']) && csrf_verify(false));
$userId = $isCli ? null : current_user_id();

$stmt = $conn->query("
    SELECT i.id AS invoice_id,
           i.invoice_number,
           i.company_id,
           i.lease_id,
           i.due_date,
           i.outstanding_amount,
           bi.item_name,
           l.tenant_id,
           (SELECT GROUP_CONCAT(DISTINCT c.cheque_number)
              FROM re_post_dated_cheques c
              JOIN re_payments p ON p.cheque_id = c.id AND p.lease_id = c.lease_id
             WHERE c.installment_id = bi.installment_id AND c.lease_id = i.lease_id) AS cleared_cheques
    FROM re_invoices i
    JOIN re_invoice_items ii ON ii.invoice_id = i.id AND ii.company_id = i.company_id
    JOIN re_obligations o ON o.id = ii.obligation_id AND o.company_id = i.company_id
    JOIN re_billing_items bi ON bi.id = o.source_id AND bi.company_id = o.company_id
    JOIN re_leases l ON l.id = i.lease_id AND l.company_id = i.company_id
    WHERE o.source_type = 'billing_item'
      AND o.obligation_type = 'service'
      AND bi.item_type = 'service_charge'
      AND bi.installment_id IS NOT NULL
      AND i.status IN ('sent', 'partial', 'overdue')
      AND i.outstanding_amount > 0.005
    GROUP BY i.id
    ORDER BY i.company_id, i.lease_id, i.due_date, i.id
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$out = [];
foreach ($rows as $row) {
    if ((string)($row['cleared_cheques'] ?? '') === '') {
        continue; // cheque not received yet — nothing was paid for this charge
    }
    $companyId = (int)$row['company_id'];
    $credit = round(get_tenant_credit_balance($conn, (int)$row['tenant_id'], $companyId), 2);
    $open = round((float)$row['outstanding_amount'], 2);
    $line = sprintf(
        '%s lease %d due %s %s — open %.2f, cheque %s, tenant credit %.2f',
        $row['invoice_number'],
        (int)$row['lease_id'],
        $row['due_date'],
        $row['item_name'],
        $open,
        $row['cleared_cheques'],
        $credit
    );
    if ($credit + 0.005 < $open) {
        $out[] = $line . ' → SKIP (credit does not cover it; needs a receipt)';
        continue;
    }
    if (!$apply) {
        $out[] = $line . ' → would apply ' . number_format($open, 2);
        continue;
    }
    try {
        $conn->beginTransaction();
        $result = apply_tenant_credit_to_invoice_accounting((int)$row['invoice_id'], $companyId, $userId, true);
        if (empty($result['success'])) {
            throw new RuntimeException((string)($result['error'] ?? 'credit apply failed'));
        }
        $conn->commit();
        $out[] = $line . ' → APPLIED ' . number_format((float)($result['applied'] ?? 0), 2);
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $out[] = $line . ' → ERROR ' . $e->getMessage();
    }
}
if ($out === []) {
    $out[] = 'Nothing to repair.';
}

if ($isCli) {
    echo ($apply ? "APPLY\n" : "DRY RUN (pass --apply)\n"), implode("\n", $out), "\n";
    exit(0);
}
?>
<!doctype html>
<title>Linked extra service repair</title>
<h3><?= $apply ? 'Applied' : 'Dry run' ?></h3>
<pre><?= htmlspecialchars(implode("\n", $out)) ?></pre>
<?php if (!$apply): ?>
<form method="post">
    <?php csrf_field(); ?>
    <input type="hidden" name="apply" value="1">
    <button type="submit">Apply</button>
</form>
<?php endif; ?>
