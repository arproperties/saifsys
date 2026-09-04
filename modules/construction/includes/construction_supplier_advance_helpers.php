<?php
/**
 * Construction Suppliers / AP — Phase 2 Supplier Advances (core).
 * Configurable COA via settings.co_supplier_advance_account_code (default 1410).
 * Does NOT call RE vendor_advance_helper. Shared engine via create_and_post_journal / reverse_journal.
 *
 * Lifecycle (per payment advance portion, derived):
 *   none → created → partially_applied → fully_consumed
 *   refunded reserved for Phase 3
 *
 * First-class supplier position (reuse in Phase 4 dashboard):
 *   co_supplier_financial_position() → outstanding_ap, advance_balance, net_payable
 */

if (!function_exists('co_db_table_exists')) {
    require_once __DIR__ . '/construction_helpers.php';
}

const CO_SUPPLIER_ADVANCE_SETTING_KEY = 'co_supplier_advance_account_code';
const CO_SUPPLIER_ADVANCE_DEFAULT_CODE = '1410';

function co_supplier_setting(PDO $conn, string $key, string $default = ''): string {
    try {
        $st = $conn->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : (string)$v;
    } catch (Throwable $e) {
        return $default;
    }
}

function co_supplier_save_setting(PDO $conn, string $key, string $value): void {
    $conn->prepare("
        INSERT INTO settings (`key`, `value`) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ")->execute([$key, $value]);
}

function co_supplier_money($amount): float {
    return round((float)$amount, 2);
}

function co_supplier_advance_schema_ready(PDO $conn): bool {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $ready = function_exists('co_db_table_exists')
        && co_db_table_exists($conn, 'co_supplier_advance_balances')
        && co_db_table_exists($conn, 'co_supplier_advance_applications')
        && function_exists('co_db_column_exists')
        && co_db_column_exists($conn, 'co_supplier_payments', 'advance_amount');
    return $ready;
}

/**
 * Resolve Supplier Advances asset account from settings (never hardcode only).
 * Default setting value is 1410; falls back to finding 1410 if configured code missing.
 */
function co_supplier_advance_account(PDO $conn, int $companyId): ?array {
    if ($companyId <= 0 || !function_exists('find_account_by_code')) {
        return null;
    }
    $code = trim(co_supplier_setting($conn, CO_SUPPLIER_ADVANCE_SETTING_KEY, CO_SUPPLIER_ADVANCE_DEFAULT_CODE));
    if ($code === '') {
        $code = CO_SUPPLIER_ADVANCE_DEFAULT_CODE;
    }
    $acct = find_account_by_code($code, $companyId);
    if ($acct) {
        return $acct;
    }
    if ($code !== CO_SUPPLIER_ADVANCE_DEFAULT_CODE) {
        return find_account_by_code(CO_SUPPLIER_ADVANCE_DEFAULT_CODE, $companyId) ?: null;
    }
    return null;
}

function co_supplier_advance_account_code(PDO $conn): string {
    $code = trim(co_supplier_setting($conn, CO_SUPPLIER_ADVANCE_SETTING_KEY, CO_SUPPLIER_ADVANCE_DEFAULT_CODE));
    return $code !== '' ? $code : CO_SUPPLIER_ADVANCE_DEFAULT_CODE;
}

function co_supplier_advance_balance(PDO $conn, int $companyId, int $supplierId): float {
    if (!co_supplier_advance_schema_ready($conn) || $companyId <= 0 || $supplierId <= 0) {
        return 0.0;
    }
    $st = $conn->prepare("SELECT balance_aed FROM co_supplier_advance_balances WHERE company_id = ? AND supplier_id = ? LIMIT 1");
    $st->execute([$companyId, $supplierId]);
    $v = $st->fetchColumn();
    return $v === false ? 0.0 : co_supplier_money($v);
}

function co_supplier_lock_advance_balance(PDO $conn, int $companyId, int $supplierId): array {
    $conn->prepare("
        INSERT IGNORE INTO co_supplier_advance_balances (company_id, supplier_id, balance_aed)
        VALUES (?, ?, 0)
    ")->execute([$companyId, $supplierId]);
    $st = $conn->prepare("
        SELECT * FROM co_supplier_advance_balances
        WHERE company_id = ? AND supplier_id = ?
        FOR UPDATE
    ");
    $st->execute([$companyId, $supplierId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Unable to lock supplier advance balance.');
    }
    return $row;
}

function co_supplier_adjust_advance_balance(PDO $conn, int $companyId, int $supplierId, float $delta): float {
    $row = co_supplier_lock_advance_balance($conn, $companyId, $supplierId);
    $new = co_supplier_money(((float)$row['balance_aed']) + $delta);
    if ($new < -0.005) {
        throw new RuntimeException('Supplier advance balance cannot go negative.');
    }
    if ($new < 0) {
        $new = 0.0;
    }
    $conn->prepare("UPDATE co_supplier_advance_balances SET balance_aed = ? WHERE company_id = ? AND supplier_id = ?")
        ->execute([$new, $companyId, $supplierId]);
    return $new;
}

function co_supplier_advance_applied_on_invoice(PDO $conn, int $companyId, int $invoiceId): float {
    if (!co_supplier_advance_schema_ready($conn)) {
        return 0.0;
    }
    $st = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM co_supplier_advance_applications
        WHERE company_id = ? AND supplier_invoice_id = ? AND status = 'posted'
    ");
    $st->execute([$companyId, $invoiceId]);
    return co_supplier_money($st->fetchColumn());
}

function co_supplier_payment_advance_applied(PDO $conn, int $companyId, int $paymentId): float {
    if (!co_supplier_advance_schema_ready($conn)) {
        return 0.0;
    }
    $st = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM co_supplier_advance_applications
        WHERE company_id = ? AND supplier_payment_id = ? AND status = 'posted'
    ");
    $st->execute([$companyId, $paymentId]);
    return co_supplier_money($st->fetchColumn());
}

/**
 * Remaining advance on a payment.
 * = original − applied − posted Advance VAT − posted refunds.
 */
function co_supplier_payment_advance_remaining(PDO $conn, int $companyId, int $paymentId): float {
    if (!co_supplier_advance_schema_ready($conn)) {
        return 0.0;
    }
    $st = $conn->prepare("SELECT COALESCE(advance_amount, 0) FROM co_supplier_payments WHERE id = ? AND company_id = ?");
    $st->execute([$paymentId, $companyId]);
    $orig = co_supplier_money($st->fetchColumn());
    $vat = 0.0;
    $refunded = 0.0;
    if (function_exists('co_supplier_payment_advance_vat_posted')) {
        $vat = co_supplier_payment_advance_vat_posted($conn, $companyId, $paymentId);
    } elseif (function_exists('co_supplier_advance_vat_table_ready') && co_supplier_advance_vat_table_ready($conn)) {
        require_once __DIR__ . '/construction_supplier_advance_vat_helpers.php';
        $vat = co_supplier_payment_advance_vat_posted($conn, $companyId, $paymentId);
    }
    if (function_exists('co_supplier_payment_advance_refunded')) {
        $refunded = co_supplier_payment_advance_refunded($conn, $companyId, $paymentId);
    } elseif (function_exists('co_supplier_advance_refund_table_ready') && co_supplier_advance_refund_table_ready($conn)) {
        require_once __DIR__ . '/construction_supplier_advance_refund_helpers.php';
        $refunded = co_supplier_payment_advance_refunded($conn, $companyId, $paymentId);
    }
    return co_supplier_money(max(
        0,
        $orig - co_supplier_payment_advance_applied($conn, $companyId, $paymentId) - $vat - $refunded
    ));
}

/**
 * Per-payment advance lifecycle status (derived).
 * @return string none|created|partially_applied|fully_consumed|refunded
 */
function co_supplier_payment_advance_lifecycle(PDO $conn, int $companyId, int $paymentId, ?array $payment = null): string {
    if (!co_supplier_advance_schema_ready($conn)) {
        return 'none';
    }
    if ($payment === null) {
        $st = $conn->prepare("SELECT advance_amount FROM co_supplier_payments WHERE id = ? AND company_id = ?");
        $st->execute([$paymentId, $companyId]);
        $payment = ['advance_amount' => $st->fetchColumn()];
    }
    $orig = co_supplier_money($payment['advance_amount'] ?? 0);
    if ($orig <= 0.005) {
        return 'none';
    }
    $applied = co_supplier_payment_advance_applied($conn, $companyId, $paymentId);
    $vat = function_exists('co_supplier_payment_advance_vat_posted')
        ? co_supplier_payment_advance_vat_posted($conn, $companyId, $paymentId)
        : 0.0;
    $refunded = function_exists('co_supplier_payment_advance_refunded')
        ? co_supplier_payment_advance_refunded($conn, $companyId, $paymentId)
        : 0.0;
    $remaining = co_supplier_money(max(0, $orig - $applied - $vat - $refunded));
    if ($remaining <= 0.005 && $refunded > 0.005 && ($applied + $vat) <= 0.005) {
        return 'refunded';
    }
    if ($remaining <= 0.005 && ($applied + $vat + $refunded) > 0.005) {
        return 'fully_consumed';
    }
    if ($applied > 0.005 || $vat > 0.005 || $refunded > 0.005) {
        return 'partially_applied';
    }
    return 'created';
}

function co_supplier_payment_advance_lifecycle_label(string $status): string {
    $map = [
        'none' => 'No Advance',
        'created' => 'Advance Created',
        'partially_applied' => 'Partially Applied',
        'fully_consumed' => 'Fully Consumed',
        'refunded' => 'Refunded',
    ];
    return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/**
 * Outstanding posted AP for a supplier (gross open invoice balances).
 * Excludes drafts/voided when lifecycle ready.
 */
function co_supplier_outstanding_ap(PDO $conn, int $companyId, int $supplierId): float {
    if ($companyId <= 0 || $supplierId <= 0) {
        return 0.0;
    }
    $statusSql = '';
    if (function_exists('co_supplier_invoice_lifecycle_ready') && co_supplier_invoice_lifecycle_ready($conn)) {
        $statusSql = " AND si.status NOT IN ('draft', 'voided') ";
    }
    $paidExpr = '0';
    $joins = '';
    if (function_exists('co_supplier_allocations_ready') && co_supplier_allocations_ready($conn)) {
        $joins .= "
            LEFT JOIN (
                SELECT invoice_id, SUM(allocated_amount) AS allocated_amount
                FROM co_supplier_payment_allocations
                WHERE company_id = {$companyId}
                GROUP BY invoice_id
            ) a ON a.invoice_id = si.id
        ";
        $paidExpr = 'COALESCE(a.allocated_amount, 0)';
    }
    if (co_supplier_advance_schema_ready($conn)) {
        $joins .= "
            LEFT JOIN (
                SELECT supplier_invoice_id, SUM(amount) AS advance_applied
                FROM co_supplier_advance_applications
                WHERE company_id = {$companyId} AND status = 'posted'
                GROUP BY supplier_invoice_id
            ) adv ON adv.supplier_invoice_id = si.id
        ";
        $paidExpr = "({$paidExpr} + COALESCE(adv.advance_applied, 0))";
    }
    $linkedVatExpr = '0';
    if (function_exists('co_supplier_advance_vat_table_ready') && co_supplier_advance_vat_table_ready($conn)) {
        $joins .= "
            LEFT JOIN (
                SELECT supplier_invoice_id, SUM(vat_amount_linked) AS linked_vat
                FROM co_supplier_advance_vat_invoice_links
                WHERE company_id = {$companyId} AND status = 'posted'
                GROUP BY supplier_invoice_id
            ) vlink ON vlink.supplier_invoice_id = si.id
        ";
        $linkedVatExpr = 'COALESCE(vlink.linked_vat, 0)';
    }
    $sql = "
        SELECT COALESCE(SUM(GREATEST(si.total - {$linkedVatExpr} - {$paidExpr}, 0)), 0)
        FROM co_supplier_invoices si
        {$joins}
        WHERE si.company_id = ?
          AND si.supplier_id = ?
          AND si.journal_id IS NOT NULL
          {$statusSql}
    ";
    $st = $conn->prepare($sql);
    $st->execute([$companyId, $supplierId]);
    return co_supplier_money($st->fetchColumn());
}

/**
 * First-class supplier financial position for UI / Phase 4 dashboard.
 * @return array{outstanding_ap:float,advance_balance:float,net_payable:float,schema_ready:bool}
 */
function co_supplier_financial_position(PDO $conn, int $companyId, int $supplierId): array {
    $ap = co_supplier_outstanding_ap($conn, $companyId, $supplierId);
    $adv = co_supplier_advance_balance($conn, $companyId, $supplierId);
    return [
        'outstanding_ap' => $ap,
        'advance_balance' => $adv,
        'net_payable' => co_supplier_money($ap - $adv),
        'schema_ready' => co_supplier_advance_schema_ready($conn),
    ];
}

/**
 * @return list<array{id:int,payment_date:string,remaining:float,lifecycle:string}>
 */
function co_supplier_advance_source_payments(PDO $conn, int $companyId, int $supplierId): array {
    if (!co_supplier_advance_schema_ready($conn)) {
        return [];
    }
    $st = $conn->prepare("
        SELECT sp.id, sp.payment_date, sp.advance_amount,
               COALESCE((
                   SELECT SUM(a.amount) FROM co_supplier_advance_applications a
                   WHERE a.company_id = sp.company_id AND a.supplier_payment_id = sp.id AND a.status = 'posted'
               ), 0) AS applied
        FROM co_supplier_payments sp
        WHERE sp.company_id = ? AND sp.supplier_id = ?
          AND sp.journal_id IS NOT NULL
          AND COALESCE(sp.advance_amount, 0) > 0.005
        ORDER BY sp.payment_date ASC, sp.id ASC
    ");
    $st->execute([$companyId, $supplierId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $pid = (int)$r['id'];
        $rem = co_supplier_payment_advance_remaining($conn, $companyId, $pid);
        if ($rem > 0.005) {
            $lifecycle = co_supplier_payment_advance_lifecycle($conn, $companyId, $pid, $r);
            $out[] = [
                'id' => $pid,
                'payment_date' => (string)$r['payment_date'],
                'remaining' => $rem,
                'lifecycle' => $lifecycle,
            ];
        }
    }
    return $out;
}

/**
 * @return list<array{payment_id:int,amount:float}>
 */
function co_supplier_advance_plan_consumption(array $sources, float $amount): array {
    $left = co_supplier_money($amount);
    $plan = [];
    $available = 0.0;
    foreach ($sources as $src) {
        $available = co_supplier_money($available + (float)$src['remaining']);
        if ($left <= 0.005) {
            break;
        }
        $take = co_supplier_money(min((float)$src['remaining'], $left));
        if ($take <= 0.005) {
            continue;
        }
        $plan[] = ['payment_id' => (int)$src['id'], 'amount' => $take];
        $left = co_supplier_money($left - $take);
    }
    if ($left > 0.005) {
        throw new RuntimeException(
            'Insufficient supplier advance remaining. Available: '
            . number_format($available, 2) . ' AED; requested: ' . number_format($amount, 2) . ' AED.'
        );
    }
    return $plan;
}

/**
 * Apply supplier advance to a posted unpaid/partial invoice (FIFO by payment date).
 * Journal: Dr 2110 / Cr advance asset. No bank movement.
 * @return array{success:bool,error:?string,application_ids:list<int>,journal_id:?int}
 */
function co_supplier_apply_advance(
    PDO $conn,
    int $companyId,
    int $supplierId,
    int $invoiceId,
    float $amount,
    ?int $userId = null
): array {
    if ($companyId <= 0) {
        return ['success' => false, 'error' => 'Company context is required.', 'application_ids' => [], 'journal_id' => null];
    }
    if (!co_supplier_advance_schema_ready($conn)) {
        return ['success' => false, 'error' => 'Run migrations/construction_supplier_ap_phase2_advances.sql', 'application_ids' => [], 'journal_id' => null];
    }
    if (!function_exists('create_and_post_journal')) {
        require_once __DIR__ . '/construction_accounting_integration.php';
    }
    if (!function_exists('co_supplier_ap_audit')) {
        require_once __DIR__ . '/construction_supplier_ap_helpers.php';
    }

    $amount = co_supplier_money($amount);
    if ($amount <= 0.005) {
        return ['success' => false, 'error' => 'Apply amount must be greater than zero.', 'application_ids' => [], 'journal_id' => null];
    }

    try {
        $inv = function_exists('co_supplier_invoice_load')
            ? co_supplier_invoice_load($conn, $companyId, $invoiceId)
            : null;
        if (!$inv || (int)$inv['supplier_id'] !== $supplierId) {
            throw new RuntimeException('Invoice not found for this supplier.');
        }
        $status = function_exists('co_supplier_invoice_status') ? co_supplier_invoice_status($inv) : '';
        if (in_array($status, ['draft', 'voided', 'paid'], true) || empty($inv['journal_id'])) {
            throw new RuntimeException('Invoice is not open for advance application.');
        }

        $bal = co_supplier_advance_balance($conn, $companyId, $supplierId);
        if ($amount > $bal + 0.005) {
            throw new RuntimeException('Apply amount exceeds available supplier advance (' . number_format($bal, 2) . ' AED).');
        }

        $paid = function_exists('co_supplier_invoice_paid_amount')
            ? co_supplier_invoice_paid_amount($conn, $companyId, $invoiceId)
            : 0.0;
        $due = co_supplier_money(max(0, (float)$inv['total'] - $paid));
        if ($amount > $due + 0.005) {
            throw new RuntimeException('Apply amount exceeds invoice balance due (' . number_format($due, 2) . ' AED).');
        }

        $sources = co_supplier_advance_source_payments($conn, $companyId, $supplierId);
        if (!$sources) {
            throw new RuntimeException('No source payments with remaining supplier advance were found.');
        }
        $plan = co_supplier_advance_plan_consumption($sources, $amount);

        $ap = find_account_by_code(CO_ACCOUNT_SUPPLIER_PAYABLE, $companyId);
        $adv = co_supplier_advance_account($conn, $companyId);
        if (!$ap) {
            throw new RuntimeException('Supplier Payable account (2110) not found.');
        }
        if (!$adv) {
            throw new RuntimeException(
                'Supplier Advances account (' . co_supplier_advance_account_code($conn)
                . ') not found. Configure it under Construction Setup Accounts.'
            );
        }

        $ins = $conn->prepare("
            INSERT INTO co_supplier_advance_applications
                (company_id, supplier_id, supplier_payment_id, supplier_invoice_id, amount, journal_id, status, created_by)
            VALUES (?, ?, ?, ?, ?, NULL, 'posted', ?)
        ");
        $upd = $conn->prepare("
            UPDATE co_supplier_advance_applications SET journal_id = ? WHERE id = ? AND company_id = ?
        ");
        $appIds = [];
        $lastJournalId = null;
        foreach ($plan as $p) {
            $slice = co_supplier_money($p['amount']);
            $ins->execute([$companyId, $supplierId, $p['payment_id'], $invoiceId, $slice, $userId]);
            $applicationId = (int)$conn->lastInsertId();
            if ($applicationId <= 0) {
                throw new RuntimeException('Failed to create advance application row.');
            }
            $desc = 'Apply supplier advance to invoice ' . ($inv['invoice_number'] ?? $invoiceId) . ' (PAY-' . (int)$p['payment_id'] . ')';
            $lines = [
                ['account_id' => (int)$ap['id'], 'debit' => $slice, 'credit' => 0, 'description' => $desc, 'reference' => $inv['invoice_number'] ?? null],
                ['account_id' => (int)$adv['id'], 'debit' => 0, 'credit' => $slice, 'description' => $desc, 'reference' => $inv['invoice_number'] ?? null],
            ];
            // create_and_post_journal owns its transaction — do not wrap.
            $res = create_and_post_journal(
                $companyId,
                'payment',
                'co_supplier_advance_application',
                $applicationId,
                $lines,
                $desc,
                date('Y-m-d'),
                $userId
            );
            if (empty($res['success'])) {
                throw new RuntimeException($res['error'] ?? 'Advance application journal failed');
            }
            $journalId = (int)$res['journal_id'];
            $lastJournalId = $journalId;
            $upd->execute([$journalId, $applicationId, $companyId]);
            $appIds[] = $applicationId;
        }

        $ownTx = !$conn->inTransaction();
        if ($ownTx) {
            $conn->beginTransaction();
        }
        try {
            co_supplier_adjust_advance_balance($conn, $companyId, $supplierId, -$amount);
            if (function_exists('co_supplier_invoice_refresh_status')) {
                co_supplier_invoice_refresh_status($conn, $companyId, $invoiceId);
            }
            co_supplier_ap_audit(
                $conn,
                $companyId,
                $supplierId,
                $invoiceId,
                (int)$plan[0]['payment_id'],
                'advance_applied',
                null,
                (string)json_encode($plan),
                $amount,
                'Supplier advance applied to invoice',
                $userId,
                'supplier_advance_apply',
                $lastJournalId
            );
            if ($ownTx) {
                $conn->commit();
            }
        } catch (Throwable $e) {
            if ($ownTx && $conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }

        return ['success' => true, 'error' => null, 'application_ids' => $appIds, 'journal_id' => $lastJournalId];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage(), 'application_ids' => [], 'journal_id' => null];
    }
}

/**
 * Unapply a posted advance application (reverse JE, restore balance).
 * @return array{success:bool,error:?string,reversal_journal_id:?int}
 */
function co_supplier_unapply_advance(
    PDO $conn,
    int $companyId,
    int $applicationId,
    string $reason = '',
    ?int $userId = null
): array {
    if ($companyId <= 0) {
        return ['success' => false, 'error' => 'Company context is required.', 'reversal_journal_id' => null];
    }
    if (!co_supplier_advance_schema_ready($conn)) {
        return ['success' => false, 'error' => 'Advance schema not installed.', 'reversal_journal_id' => null];
    }
    if (!function_exists('reverse_journal')) {
        require_once __DIR__ . '/construction_accounting_integration.php';
    }
    if (!function_exists('co_supplier_ap_audit')) {
        require_once __DIR__ . '/construction_supplier_ap_helpers.php';
    }

    try {
        $st = $conn->prepare("SELECT * FROM co_supplier_advance_applications WHERE id = ? AND company_id = ?");
        $st->execute([$applicationId, $companyId]);
        $app = $st->fetch(PDO::FETCH_ASSOC);
        if (!$app) {
            throw new RuntimeException('Advance application not found.');
        }
        if (($app['status'] ?? '') === 'reversed') {
            return ['success' => true, 'error' => null, 'reversal_journal_id' => null, 'already_reversed' => true];
        }

        $supplierId = (int)$app['supplier_id'];
        $invoiceId = (int)$app['supplier_invoice_id'];
        $amount = co_supplier_money($app['amount']);
        $journalId = (int)($app['journal_id'] ?? 0);

        $reversalJournalId = null;
        if ($journalId > 0) {
            $rev = reverse_journal($journalId, $reason !== '' ? $reason : 'Supplier advance unapplied', $userId);
            if (empty($rev['success'])) {
                throw new RuntimeException($rev['error'] ?? 'Failed to reverse advance application journal');
            }
            $reversalJournalId = isset($rev['reversal_journal_id']) ? (int)$rev['reversal_journal_id'] : null;
        }

        $conn->prepare("UPDATE co_supplier_advance_applications SET status = 'reversed' WHERE id = ? AND company_id = ?")
            ->execute([$applicationId, $companyId]);
        co_supplier_adjust_advance_balance($conn, $companyId, $supplierId, $amount);
        if (function_exists('co_supplier_invoice_refresh_status')) {
            co_supplier_invoice_refresh_status($conn, $companyId, $invoiceId);
        }
        co_supplier_ap_audit(
            $conn,
            $companyId,
            $supplierId,
            $invoiceId,
            (int)$app['supplier_payment_id'],
            'advance_unapplied',
            (string)$journalId,
            (string)($reversalJournalId ?? ''),
            $amount,
            $reason !== '' ? $reason : 'Supplier advance unapplied',
            $userId,
            'supplier_advance_unapply',
            $reversalJournalId
        );
        return ['success' => true, 'error' => null, 'reversal_journal_id' => $reversalJournalId];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage(), 'reversal_journal_id' => null];
    }
}

/**
 * After payment GL posts with advance_amount > 0: increase balance + audit.
 * Caller posts the journal; this only maintains subledger balance.
 */
function co_supplier_advance_on_payment_posted(
    PDO $conn,
    int $companyId,
    int $supplierId,
    int $paymentId,
    float $advanceAmount,
    ?int $userId,
    ?int $journalId
): void {
    if (!co_supplier_advance_schema_ready($conn) || $advanceAmount <= 0.005) {
        return;
    }
    co_supplier_adjust_advance_balance($conn, $companyId, $supplierId, $advanceAmount);
    if (function_exists('co_supplier_ap_audit')) {
        co_supplier_ap_audit(
            $conn,
            $companyId,
            $supplierId,
            null,
            $paymentId,
            'advance_created',
            null,
            'created',
            $advanceAmount,
            'Supplier advance created from payment remainder',
            $userId,
            'supplier_payment',
            $journalId
        );
    }
}

/**
 * Complete advance consumption history for a payment (Created → Applied → VAT → Refund → Remaining).
 * @return list<array{event:string,label:string,date:?string,amount:float,status:string,ref:?string,link:?string,meta:array}>
 */
function co_supplier_advance_history(PDO $conn, int $companyId, int $paymentId): array {
    if (!co_supplier_advance_schema_ready($conn) || $companyId <= 0 || $paymentId <= 0) {
        return [];
    }
    $st = $conn->prepare("SELECT * FROM co_supplier_payments WHERE id = ? AND company_id = ?");
    $st->execute([$paymentId, $companyId]);
    $pay = $st->fetch(PDO::FETCH_ASSOC);
    if (!$pay) {
        return [];
    }
    $orig = co_supplier_money($pay['advance_amount'] ?? 0);
    if ($orig <= 0.005) {
        return [];
    }
    $events = [];
    $events[] = [
        'event' => 'created',
        'label' => 'Advance Created',
        'date' => (string)$pay['payment_date'],
        'amount' => $orig,
        'status' => 'posted',
        'ref' => (string)($pay['reference'] ?? ''),
        'link' => 'supplier_payment_view.php?id=' . $paymentId,
        'meta' => ['payment_id' => $paymentId],
    ];

    $appSt = $conn->prepare("
        SELECT a.*, si.invoice_number
        FROM co_supplier_advance_applications a
        JOIN co_supplier_invoices si ON si.id = a.supplier_invoice_id AND si.company_id = a.company_id
        WHERE a.company_id = ? AND a.supplier_payment_id = ?
        ORDER BY a.created_at ASC, a.id ASC
    ");
    $appSt->execute([$companyId, $paymentId]);
    foreach ($appSt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $app) {
        $events[] = [
            'event' => 'applied',
            'label' => 'Applied to Invoice ' . ($app['invoice_number'] ?? ('#' . $app['supplier_invoice_id'])),
            'date' => substr((string)($app['created_at'] ?? ''), 0, 10),
            'amount' => co_supplier_money($app['amount']),
            'status' => (string)$app['status'],
            'ref' => (string)($app['invoice_number'] ?? ''),
            'link' => 'supplier_invoice_view.php?id=' . (int)$app['supplier_invoice_id'],
            'meta' => [
                'application_id' => (int)$app['id'],
                'invoice_id' => (int)$app['supplier_invoice_id'],
                'remaining_on_payment_after' => null,
            ],
        ];
    }

    if (function_exists('co_supplier_advance_vat_table_ready') && co_supplier_advance_vat_table_ready($conn)) {
        $vatSt = $conn->prepare("
            SELECT * FROM co_supplier_advance_vat_documents
            WHERE company_id = ? AND supplier_payment_id = ?
            ORDER BY supplier_invoice_date ASC, id ASC
        ");
        $vatSt->execute([$companyId, $paymentId]);
        foreach ($vatSt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $doc) {
            $events[] = [
                'event' => 'advance_vat',
                'label' => 'Advance VAT ' . ($doc['supplier_invoice_number'] ?? ''),
                'date' => (string)$doc['supplier_invoice_date'],
                'amount' => co_supplier_money($doc['vat_amount']),
                'status' => (string)$doc['status'],
                'ref' => (string)($doc['supplier_invoice_number'] ?? ''),
                'link' => 'supplier_advance_vat_view.php?id=' . (int)$doc['id'],
                'meta' => ['vat_document_id' => (int)$doc['id']],
            ];
        }
    }

    if (function_exists('co_supplier_advance_refund_table_ready') && co_supplier_advance_refund_table_ready($conn)) {
        $rfSt = $conn->prepare("
            SELECT * FROM co_supplier_advance_refunds
            WHERE company_id = ? AND supplier_payment_id = ?
            ORDER BY refund_date ASC, id ASC
        ");
        $rfSt->execute([$companyId, $paymentId]);
        foreach ($rfSt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rf) {
            $events[] = [
                'event' => 'refunded',
                'label' => 'Refunded to Bank',
                'date' => (string)$rf['refund_date'],
                'amount' => co_supplier_money($rf['amount']),
                'status' => (string)$rf['status'],
                'ref' => (string)($rf['reference'] ?? ''),
                'link' => 'supplier_advance_refund_view.php?id=' . (int)$rf['id'],
                'meta' => ['refund_id' => (int)$rf['id']],
            ];
        }
    }

    $remaining = co_supplier_payment_advance_remaining($conn, $companyId, $paymentId);
    $events[] = [
        'event' => 'remaining',
        'label' => 'Remaining Balance',
        'date' => date('Y-m-d'),
        'amount' => $remaining,
        'status' => $remaining > 0.005 ? 'open' : 'closed',
        'ref' => co_supplier_payment_advance_lifecycle_label(
            co_supplier_payment_advance_lifecycle($conn, $companyId, $paymentId, $pay)
        ),
        'link' => null,
        'meta' => [],
    ];
    return $events;
}

/**
 * Invoice-side advance application rows with source payment remaining (traceability).
 * @return list<array<string,mixed>>
 */
function co_supplier_invoice_advance_trace(PDO $conn, int $companyId, int $invoiceId): array {
    if (!co_supplier_advance_schema_ready($conn)) {
        return [];
    }
    $st = $conn->prepare("
        SELECT a.*, sp.payment_date, sp.reference AS payment_reference, sp.advance_amount,
               sp.amount AS payment_amount
        FROM co_supplier_advance_applications a
        JOIN co_supplier_payments sp ON sp.id = a.supplier_payment_id AND sp.company_id = a.company_id
        WHERE a.company_id = ? AND a.supplier_invoice_id = ?
        ORDER BY a.created_at DESC, a.id DESC
    ");
    $st->execute([$companyId, $invoiceId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $pid = (int)$row['supplier_payment_id'];
        $row['source_advance_original'] = co_supplier_money($row['advance_amount'] ?? 0);
        $row['source_advance_remaining'] = co_supplier_payment_advance_remaining($conn, $companyId, $pid);
        $row['source_advance_lifecycle'] = co_supplier_payment_advance_lifecycle($conn, $companyId, $pid);
    }
    unset($row);
    return $rows;
}

function co_supplier_invoice_linked_advance_vat(PDO $conn, int $companyId, int $invoiceId): float {
    if (!function_exists('co_supplier_advance_vat_linked_to_invoice')) {
        if (is_file(__DIR__ . '/construction_supplier_advance_vat_helpers.php')) {
            require_once __DIR__ . '/construction_supplier_advance_vat_helpers.php';
        } else {
            return 0.0;
        }
    }
    return co_supplier_advance_vat_linked_to_invoice($conn, $companyId, $invoiceId);
}

/** Open balance on invoice after cash, advance apply, and linked advance VAT. */
function co_supplier_invoice_outstanding_amount(PDO $conn, int $companyId, int $invoiceId): float {
    $inv = function_exists('co_supplier_invoice_load')
        ? co_supplier_invoice_load($conn, $companyId, $invoiceId)
        : null;
    if (!$inv) {
        return 0.0;
    }
    $paid = function_exists('co_supplier_invoice_paid_amount')
        ? co_supplier_invoice_paid_amount($conn, $companyId, $invoiceId)
        : 0.0;
    $linkedVat = co_supplier_invoice_linked_advance_vat($conn, $companyId, $invoiceId);
    return co_supplier_money(max(0, (float)$inv['total'] - $linkedVat - $paid));
}

// Soft-load Phase 3 helpers so remaining/lifecycle stay consistent.
if (is_file(__DIR__ . '/construction_supplier_advance_vat_helpers.php')) {
    require_once __DIR__ . '/construction_supplier_advance_vat_helpers.php';
}
if (is_file(__DIR__ . '/construction_supplier_advance_refund_helpers.php')) {
    require_once __DIR__ . '/construction_supplier_advance_refund_helpers.php';
}
