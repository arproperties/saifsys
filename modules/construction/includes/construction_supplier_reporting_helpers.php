<?php
/**
 * Construction Suppliers / AP — Phase 4 reporting helpers.
 * Dashboard KPIs, financial timeline, printable statement, project spend.
 * Construction-owned; does not call RE vendor helpers.
 */

if (!function_exists('co_supplier_require_company_id')) {
    require_once __DIR__ . '/construction_supplier_ap_helpers.php';
}
if (!function_exists('co_supplier_financial_position')) {
    require_once __DIR__ . '/construction_supplier_advance_helpers.php';
}

/**
 * Dashboard KPI pack for a supplier profile.
 * @return array<string,mixed>
 */
function co_supplier_dashboard_kpis(PDO $conn, int $companyId, int $supplierId): array {
    $position = co_supplier_financial_position($conn, $companyId, $supplierId);
    $openInvoices = 0;
    $partialInvoices = 0;
    $draftInvoices = 0;
    $paidInvoices = 0;
    $voidedInvoices = 0;

    if (function_exists('co_supplier_invoice_lifecycle_ready') && co_supplier_invoice_lifecycle_ready($conn)) {
        $st = $conn->prepare("
            SELECT status, COUNT(*) AS cnt
            FROM co_supplier_invoices
            WHERE company_id = ? AND supplier_id = ?
            GROUP BY status
        ");
        $st->execute([$companyId, $supplierId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $status = (string)$row['status'];
            $cnt = (int)$row['cnt'];
            if ($status === 'posted') {
                $openInvoices = $cnt;
            } elseif ($status === 'partially_paid') {
                $partialInvoices = $cnt;
            } elseif ($status === 'draft') {
                $draftInvoices = $cnt;
            } elseif ($status === 'paid') {
                $paidInvoices = $cnt;
            } elseif ($status === 'voided') {
                $voidedInvoices = $cnt;
            }
        }
    } else {
        $st = $conn->prepare("
            SELECT id, total, journal_id FROM co_supplier_invoices
            WHERE company_id = ? AND supplier_id = ?
        ");
        $st->execute([$companyId, $supplierId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $inv) {
            if (empty($inv['journal_id'])) {
                $draftInvoices++;
                continue;
            }
            $paid = function_exists('co_supplier_invoice_paid_amount')
                ? co_supplier_invoice_paid_amount($conn, $companyId, (int)$inv['id'])
                : 0.0;
            $bal = max(0, (float)$inv['total'] - $paid);
            if ($bal <= 0.005) {
                $paidInvoices++;
            } elseif ($paid > 0.005) {
                $partialInvoices++;
            } else {
                $openInvoices++;
            }
        }
    }

    $vatPending = co_supplier_vat_pending($conn, $companyId, $supplierId);

    $totalPurchased = 0.0;
    if (function_exists('co_supplier_invoice_lifecycle_ready') && co_supplier_invoice_lifecycle_ready($conn)) {
        $st = $conn->prepare("
            SELECT COALESCE(SUM(total), 0) FROM co_supplier_invoices
            WHERE company_id = ? AND supplier_id = ? AND status <> 'voided'
        ");
    } else {
        $st = $conn->prepare("
            SELECT COALESCE(SUM(total), 0) FROM co_supplier_invoices
            WHERE company_id = ? AND supplier_id = ?
        ");
    }
    $st->execute([$companyId, $supplierId]);
    $totalPurchased = co_supplier_money($st->fetchColumn());

    $st = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM co_supplier_payments
        WHERE company_id = ? AND supplier_id = ? AND journal_id IS NOT NULL
    ");
    $st->execute([$companyId, $supplierId]);
    $totalPaid = co_supplier_money($st->fetchColumn());

    $lastInvoice = null;
    $st = $conn->prepare("
        SELECT id, invoice_number, invoice_date, total, status
        FROM co_supplier_invoices
        WHERE company_id = ? AND supplier_id = ?
        ORDER BY invoice_date DESC, id DESC
        LIMIT 1
    ");
    $st->execute([$companyId, $supplierId]);
    $lastInvoice = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    $lastPayment = null;
    $st = $conn->prepare("
        SELECT id, payment_date, amount, reference
        FROM co_supplier_payments
        WHERE company_id = ? AND supplier_id = ? AND journal_id IS NOT NULL
        ORDER BY payment_date DESC, id DESC
        LIMIT 1
    ");
    $st->execute([$companyId, $supplierId]);
    $lastPayment = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    return [
        'outstanding_ap' => $position['outstanding_ap'],
        'advance_balance' => $position['advance_balance'],
        'net_payable' => $position['net_payable'],
        'vat_pending' => $vatPending['total'],
        'vat_pending_detail' => $vatPending,
        'open_invoices' => $openInvoices,
        'partially_paid_invoices' => $partialInvoices,
        'draft_invoices' => $draftInvoices,
        'paid_invoices' => $paidInvoices,
        'voided_invoices' => $voidedInvoices,
        'total_purchased' => $totalPurchased,
        'total_paid' => $totalPaid,
        'last_invoice' => $lastInvoice,
        'last_payment' => $lastPayment,
        'schema_ready' => !empty($position['schema_ready']),
    ];
}

/**
 * Current VAT Pending = remaining Input VAT on draft invoices (net of links)
 * + remaining unlinked posted Advance VAT documents.
 * @return array{draft_invoice_vat:float,unlinked_advance_vat:float,total:float}
 */
function co_supplier_vat_pending(PDO $conn, int $companyId, int $supplierId): array {
    $draftVat = 0.0;
    $st = $conn->prepare("
        SELECT id, vat_amount, journal_id, status
        FROM co_supplier_invoices
        WHERE company_id = ? AND supplier_id = ?
    ");
    $st->execute([$companyId, $supplierId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $inv) {
        $isDraft = empty($inv['journal_id']);
        if (function_exists('co_supplier_invoice_status')) {
            $isDraft = co_supplier_invoice_status($inv) === 'draft';
        }
        if (!$isDraft) {
            continue;
        }
        $linked = function_exists('co_supplier_invoice_linked_advance_vat')
            ? co_supplier_invoice_linked_advance_vat($conn, $companyId, (int)$inv['id'])
            : 0.0;
        $draftVat = co_supplier_money($draftVat + max(0, (float)$inv['vat_amount'] - $linked));
    }

    $unlinkedAdvVat = 0.0;
    if (function_exists('co_supplier_eligible_advance_vat_docs')) {
        foreach (co_supplier_eligible_advance_vat_docs($conn, $companyId, $supplierId) as $doc) {
            $unlinkedAdvVat = co_supplier_money($unlinkedAdvVat + (float)($doc['remaining_vat'] ?? 0));
        }
    } elseif (function_exists('co_supplier_advance_vat_table_ready') && co_supplier_advance_vat_table_ready($conn)) {
        $st = $conn->prepare("
            SELECT id, vat_amount FROM co_supplier_advance_vat_documents
            WHERE company_id = ? AND supplier_id = ? AND status = 'posted'
        ");
        $st->execute([$companyId, $supplierId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $doc) {
            $rem = function_exists('co_supplier_advance_vat_doc_remaining')
                ? co_supplier_advance_vat_doc_remaining($conn, $companyId, (int)$doc['id'])
                : (float)$doc['vat_amount'];
            if ($rem > 0.005) {
                $unlinkedAdvVat = co_supplier_money($unlinkedAdvVat + $rem);
            }
        }
    }

    return [
        'draft_invoice_vat' => $draftVat,
        'unlinked_advance_vat' => $unlinkedAdvVat,
        'total' => co_supplier_money($draftVat + $unlinkedAdvVat),
    ];
}

/**
 * Complete supplier financial timeline (newest first by default).
 * @return list<array<string,mixed>>
 */
function co_supplier_financial_timeline(
    PDO $conn,
    int $companyId,
    int $supplierId,
    int $limit = 200,
    bool $newestFirst = true
): array {
    $events = [];

    $st = $conn->prepare("SELECT id, supplier_name, created_at FROM co_suppliers WHERE id = ? AND company_id = ?");
    $st->execute([$supplierId, $companyId]);
    $sup = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sup) {
        return [];
    }
    $events[] = [
        'event' => 'supplier_created',
        'label' => 'Supplier Created',
        'occurred_at' => (string)$sup['created_at'],
        'date' => substr((string)$sup['created_at'], 0, 10),
        'amount' => null,
        'ref' => (string)$sup['supplier_name'],
        'link' => 'supplier_view.php?id=' . $supplierId,
        'meta' => ['supplier_id' => $supplierId],
    ];

    $st = $conn->prepare("
        SELECT si.id, si.invoice_number, si.invoice_date, si.created_at, si.total, si.status,
               si.journal_id, si.voided_at, si.void_reason, si.amended_from_invoice_id,
               jh.journal_date, jh.created_at AS journal_created_at
        FROM co_supplier_invoices si
        LEFT JOIN re_journal_headers jh ON jh.id = si.journal_id
        WHERE si.company_id = ? AND si.supplier_id = ?
        ORDER BY si.id ASC
    ");
    $st->execute([$companyId, $supplierId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $inv) {
        $invId = (int)$inv['id'];
        $link = 'supplier_invoice_view.php?id=' . $invId;
        $events[] = [
            'event' => 'invoice_created',
            'label' => 'Invoice Created',
            'occurred_at' => (string)($inv['created_at'] ?: $inv['invoice_date'] . ' 00:00:00'),
            'date' => substr((string)($inv['created_at'] ?: $inv['invoice_date']), 0, 10),
            'amount' => co_supplier_money($inv['total']),
            'ref' => (string)$inv['invoice_number'],
            'link' => $link,
            'meta' => ['invoice_id' => $invId, 'status' => $inv['status']],
        ];
        if (!empty($inv['journal_id'])) {
            $postedAt = (string)($inv['journal_created_at'] ?: (($inv['journal_date'] ?? $inv['invoice_date']) . ' 12:00:00'));
            $events[] = [
                'event' => 'invoice_posted',
                'label' => 'Invoice Posted',
                'occurred_at' => $postedAt,
                'date' => substr($postedAt, 0, 10),
                'amount' => co_supplier_money($inv['total']),
                'ref' => (string)$inv['invoice_number'],
                'link' => $link,
                'meta' => ['invoice_id' => $invId, 'journal_id' => (int)$inv['journal_id']],
            ];
        }
        if (($inv['status'] ?? '') === 'voided' || !empty($inv['voided_at'])) {
            $voidAt = !empty($inv['voided_at'])
                ? (string)$inv['voided_at']
                : ((string)$inv['invoice_date'] . ' 23:00:00');
            $events[] = [
                'event' => 'void',
                'label' => 'Invoice Voided',
                'occurred_at' => $voidAt,
                'date' => substr($voidAt, 0, 10),
                'amount' => co_supplier_money($inv['total']),
                'ref' => (string)$inv['invoice_number'],
                'link' => $link,
                'meta' => ['invoice_id' => $invId, 'reason' => (string)($inv['void_reason'] ?? '')],
            ];
        }
        if (!empty($inv['amended_from_invoice_id'])) {
            $events[] = [
                'event' => 'amend',
                'label' => 'Invoice Amended (New Draft)',
                'occurred_at' => (string)($inv['created_at'] ?: $inv['invoice_date'] . ' 00:00:01'),
                'date' => substr((string)($inv['created_at'] ?: $inv['invoice_date']), 0, 10),
                'amount' => co_supplier_money($inv['total']),
                'ref' => (string)$inv['invoice_number'],
                'link' => $link,
                'meta' => [
                    'invoice_id' => $invId,
                    'amended_from_invoice_id' => (int)$inv['amended_from_invoice_id'],
                ],
            ];
        }
    }

    $st = $conn->prepare("
        SELECT id, payment_date, amount, advance_amount, reference, journal_id, created_at
        FROM co_supplier_payments
        WHERE company_id = ? AND supplier_id = ?
        ORDER BY id ASC
    ");
    $st->execute([$companyId, $supplierId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $pay) {
        $payId = (int)$pay['id'];
        $link = 'supplier_payment_view.php?id=' . $payId;
        $occurred = (string)($pay['created_at'] ?: $pay['payment_date'] . ' 00:00:00');
        if (!empty($pay['journal_id'])) {
            $events[] = [
                'event' => 'payment_made',
                'label' => 'Payment Made',
                'occurred_at' => $occurred,
                'date' => (string)$pay['payment_date'],
                'amount' => co_supplier_money($pay['amount']),
                'ref' => (string)($pay['reference'] ?: ('PAY-' . $payId)),
                'link' => $link,
                'meta' => ['payment_id' => $payId, 'journal_id' => (int)$pay['journal_id']],
            ];
            $adv = co_supplier_money($pay['advance_amount'] ?? 0);
            if ($adv > 0.005) {
                $events[] = [
                    'event' => 'advance_created',
                    'label' => 'Supplier Advance Created',
                    'occurred_at' => $occurred,
                    'date' => (string)$pay['payment_date'],
                    'amount' => $adv,
                    'ref' => (string)($pay['reference'] ?: ('PAY-' . $payId)),
                    'link' => $link,
                    'meta' => ['payment_id' => $payId],
                ];
            }
        }
    }

    if (function_exists('co_supplier_advance_vat_table_ready') && co_supplier_advance_vat_table_ready($conn)) {
        $st = $conn->prepare("
            SELECT id, supplier_invoice_number, supplier_invoice_date, vat_amount, status,
                   posted_at, created_at, supplier_payment_id
            FROM co_supplier_advance_vat_documents
            WHERE company_id = ? AND supplier_id = ? AND status IN ('posted','reversed')
            ORDER BY id ASC
        ");
        $st->execute([$companyId, $supplierId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $doc) {
            if (($doc['status'] ?? '') !== 'posted' && empty($doc['posted_at'])) {
                continue;
            }
            $occurred = (string)($doc['posted_at'] ?: ($doc['supplier_invoice_date'] . ' 12:00:00'));
            $events[] = [
                'event' => 'advance_vat_posted',
                'label' => 'Advance VAT Posted',
                'occurred_at' => $occurred,
                'date' => substr($occurred, 0, 10),
                'amount' => co_supplier_money($doc['vat_amount']),
                'ref' => (string)$doc['supplier_invoice_number'],
                'link' => 'supplier_advance_vat_view.php?id=' . (int)$doc['id'],
                'meta' => [
                    'vat_document_id' => (int)$doc['id'],
                    'payment_id' => (int)$doc['supplier_payment_id'],
                    'status' => (string)$doc['status'],
                ],
            ];
        }
    }

    if (function_exists('co_supplier_advance_schema_ready') && co_supplier_advance_schema_ready($conn)) {
        $st = $conn->prepare("
            SELECT a.id, a.amount, a.status, a.created_at, a.supplier_payment_id, a.supplier_invoice_id,
                   si.invoice_number
            FROM co_supplier_advance_applications a
            JOIN co_supplier_invoices si ON si.id = a.supplier_invoice_id AND si.company_id = a.company_id
            WHERE a.company_id = ? AND a.supplier_id = ?
            ORDER BY a.id ASC
        ");
        $st->execute([$companyId, $supplierId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $app) {
            if (($app['status'] ?? '') !== 'posted') {
                continue;
            }
            $events[] = [
                'event' => 'advance_applied',
                'label' => 'Advance Applied',
                'occurred_at' => (string)$app['created_at'],
                'date' => substr((string)$app['created_at'], 0, 10),
                'amount' => co_supplier_money($app['amount']),
                'ref' => (string)($app['invoice_number'] ?? ''),
                'link' => 'supplier_invoice_view.php?id=' . (int)$app['supplier_invoice_id'],
                'meta' => [
                    'application_id' => (int)$app['id'],
                    'payment_id' => (int)$app['supplier_payment_id'],
                    'invoice_id' => (int)$app['supplier_invoice_id'],
                ],
            ];
        }
    }

    if (function_exists('co_supplier_advance_refund_table_ready') && co_supplier_advance_refund_table_ready($conn)) {
        $st = $conn->prepare("
            SELECT id, refund_date, amount, reference, status, posted_at, created_at, supplier_payment_id
            FROM co_supplier_advance_refunds
            WHERE company_id = ? AND supplier_id = ? AND status = 'posted'
            ORDER BY id ASC
        ");
        $st->execute([$companyId, $supplierId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rf) {
            $occurred = (string)($rf['posted_at'] ?: ($rf['refund_date'] . ' 12:00:00'));
            $events[] = [
                'event' => 'refund',
                'label' => 'Advance Refunded',
                'occurred_at' => $occurred,
                'date' => (string)$rf['refund_date'],
                'amount' => co_supplier_money($rf['amount']),
                'ref' => (string)($rf['reference'] ?: ('REF-' . $rf['id'])),
                'link' => 'supplier_advance_refund_view.php?id=' . (int)$rf['id'],
                'meta' => ['refund_id' => (int)$rf['id'], 'payment_id' => (int)$rf['supplier_payment_id']],
            ];
        }
    }

    usort($events, static function ($a, $b) use ($newestFirst) {
        $cmp = strcmp((string)$a['occurred_at'], (string)$b['occurred_at']);
        if ($cmp === 0) {
            $order = [
                'supplier_created' => 0,
                'invoice_created' => 1,
                'amend' => 2,
                'invoice_posted' => 3,
                'payment_made' => 4,
                'advance_created' => 5,
                'advance_vat_posted' => 6,
                'advance_applied' => 7,
                'refund' => 8,
                'void' => 9,
            ];
            $ao = $order[$a['event']] ?? 50;
            $bo = $order[$b['event']] ?? 50;
            $cmp = $ao <=> $bo;
        }
        return $newestFirst ? -$cmp : $cmp;
    });

    if ($limit > 0 && count($events) > $limit) {
        $events = array_slice($events, 0, $limit);
    }
    return $events;
}

/**
 * Printable / exportable supplier statement rows (full lifecycle).
 * Balance tracks Net Payable (Outstanding AP − Advance Balance effects).
 *
 * @return array{opening:float,closing:float,rows:list<array<string,mixed>>,advance_opening:float,advance_closing:float}
 */
function co_supplier_statement_build(
    PDO $conn,
    int $companyId,
    int $supplierId,
    string $dateFrom,
    string $dateTo
): array {
    $rows = [];

    // Opening: approximate from movements before dateFrom
    $openingAp = 0.0;
    $openingAdv = 0.0;

    $voidFilter = (function_exists('co_supplier_invoice_lifecycle_ready') && co_supplier_invoice_lifecycle_ready($conn))
        ? " AND status <> 'voided' "
        : '';

    $st = $conn->prepare("
        SELECT COALESCE(SUM(total), 0) FROM co_supplier_invoices
        WHERE company_id = ? AND supplier_id = ? AND journal_id IS NOT NULL
          AND invoice_date < ? {$voidFilter}
    ");
    $st->execute([$companyId, $supplierId, $dateFrom]);
    $openingAp += (float)$st->fetchColumn();

    // Cash allocations before period
    if (function_exists('co_supplier_allocations_ready') && co_supplier_allocations_ready($conn)) {
        $st = $conn->prepare("
            SELECT COALESCE(SUM(a.allocated_amount), 0)
            FROM co_supplier_payment_allocations a
            JOIN co_supplier_payments sp ON sp.id = a.payment_id AND sp.company_id = a.company_id
            WHERE a.company_id = ? AND sp.supplier_id = ? AND sp.payment_date < ? AND sp.journal_id IS NOT NULL
        ");
        $st->execute([$companyId, $supplierId, $dateFrom]);
        $openingAp -= (float)$st->fetchColumn();
    }

    if (function_exists('co_supplier_advance_schema_ready') && co_supplier_advance_schema_ready($conn)) {
        $st = $conn->prepare("
            SELECT COALESCE(SUM(a.amount), 0)
            FROM co_supplier_advance_applications a
            WHERE a.company_id = ? AND a.supplier_id = ? AND a.status = 'posted'
              AND DATE(a.created_at) < ?
        ");
        $st->execute([$companyId, $supplierId, $dateFrom]);
        $openingAp -= (float)$st->fetchColumn();

        $st = $conn->prepare("
            SELECT COALESCE(SUM(sp.advance_amount), 0)
            FROM co_supplier_payments sp
            WHERE sp.company_id = ? AND sp.supplier_id = ? AND sp.journal_id IS NOT NULL
              AND sp.payment_date < ? AND COALESCE(sp.advance_amount,0) > 0
        ");
        $st->execute([$companyId, $supplierId, $dateFrom]);
        $openingAdv += (float)$st->fetchColumn();

        $st = $conn->prepare("
            SELECT COALESCE(SUM(a.amount), 0)
            FROM co_supplier_advance_applications a
            WHERE a.company_id = ? AND a.supplier_id = ? AND a.status = 'posted'
              AND DATE(a.created_at) < ?
        ");
        $st->execute([$companyId, $supplierId, $dateFrom]);
        $openingAdv -= (float)$st->fetchColumn();
    }

    if (function_exists('co_supplier_advance_vat_table_ready') && co_supplier_advance_vat_table_ready($conn)) {
        $st = $conn->prepare("
            SELECT COALESCE(SUM(vat_amount), 0) FROM co_supplier_advance_vat_documents
            WHERE company_id = ? AND supplier_id = ? AND status = 'posted'
              AND supplier_invoice_date < ?
        ");
        $st->execute([$companyId, $supplierId, $dateFrom]);
        $openingAdv -= (float)$st->fetchColumn();

        // Linked advance VAT reduces AP on posted invoices (invoice_date before)
        $st = $conn->prepare("
            SELECT COALESCE(SUM(l.vat_amount_linked), 0)
            FROM co_supplier_advance_vat_invoice_links l
            JOIN co_supplier_invoices si ON si.id = l.supplier_invoice_id AND si.company_id = l.company_id
            WHERE l.company_id = ? AND si.supplier_id = ? AND l.status = 'posted'
              AND si.invoice_date < ? AND si.journal_id IS NOT NULL
        ");
        $st->execute([$companyId, $supplierId, $dateFrom]);
        $openingAp -= (float)$st->fetchColumn();
    }

    if (function_exists('co_supplier_advance_refund_table_ready') && co_supplier_advance_refund_table_ready($conn)) {
        $st = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM co_supplier_advance_refunds
            WHERE company_id = ? AND supplier_id = ? AND status = 'posted' AND refund_date < ?
        ");
        $st->execute([$companyId, $supplierId, $dateFrom]);
        $openingAdv -= (float)$st->fetchColumn();
    }

    $openingAp = co_supplier_money(max(0, $openingAp));
    $openingAdv = co_supplier_money(max(0, $openingAdv));
    $openingNet = co_supplier_money($openingAp - $openingAdv);

    // Period movements
    $st = $conn->prepare("
        SELECT si.id, si.invoice_date, si.invoice_number, si.total, si.description, si.status, si.voided_at
        FROM co_supplier_invoices si
        WHERE si.company_id = ? AND si.supplier_id = ? AND si.journal_id IS NOT NULL
          AND si.invoice_date BETWEEN ? AND ?
        ORDER BY si.invoice_date ASC, si.id ASC
    ");
    $st->execute([$companyId, $supplierId, $dateFrom, $dateTo]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $inv) {
        $linked = function_exists('co_supplier_invoice_linked_advance_vat')
            ? co_supplier_invoice_linked_advance_vat($conn, $companyId, (int)$inv['id'])
            : 0.0;
        $apImpact = co_supplier_money(max(0, (float)$inv['total'] - $linked));
        if (($inv['status'] ?? '') === 'voided') {
            $rows[] = [
                'txn_date' => (string)substr((string)($inv['voided_at'] ?: $inv['invoice_date']), 0, 10),
                'txn_type' => 'Void',
                'reference' => (string)$inv['invoice_number'],
                'description' => 'Voided invoice',
                'debit' => 0.0,
                'credit' => $apImpact,
                'ap_effect' => -$apImpact,
                'advance_effect' => 0.0,
                'link' => 'supplier_invoice_view.php?id=' . (int)$inv['id'],
            ];
        } else {
            $rows[] = [
                'txn_date' => (string)$inv['invoice_date'],
                'txn_type' => 'Invoice',
                'reference' => (string)$inv['invoice_number'],
                'description' => (string)($inv['description'] ?: 'Supplier invoice'),
                'debit' => $apImpact,
                'credit' => 0.0,
                'ap_effect' => $apImpact,
                'advance_effect' => 0.0,
                'link' => 'supplier_invoice_view.php?id=' . (int)$inv['id'],
            ];
        }
    }

    $st = $conn->prepare("
        SELECT id, payment_date, amount, advance_amount, reference
        FROM co_supplier_payments
        WHERE company_id = ? AND supplier_id = ? AND journal_id IS NOT NULL
          AND payment_date BETWEEN ? AND ?
        ORDER BY payment_date ASC, id ASC
    ");
    $st->execute([$companyId, $supplierId, $dateFrom, $dateTo]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $pay) {
        $amount = co_supplier_money($pay['amount']);
        $adv = co_supplier_money($pay['advance_amount'] ?? 0);
        $allocated = co_supplier_money(max(0, $amount - $adv));
        $rows[] = [
            'txn_date' => (string)$pay['payment_date'],
            'txn_type' => 'Payment',
            'reference' => (string)($pay['reference'] ?: ('PAY-' . $pay['id'])),
            'description' => $adv > 0.005
                ? ('Payment (allocated ' . number_format($allocated, 2) . ' + advance ' . number_format($adv, 2) . ')')
                : 'Supplier payment',
            'debit' => 0.0,
            'credit' => $amount,
            'ap_effect' => -$allocated,
            'advance_effect' => $adv,
            'link' => 'supplier_payment_view.php?id=' . (int)$pay['id'],
        ];
        if ($adv > 0.005) {
            $rows[] = [
                'txn_date' => (string)$pay['payment_date'],
                'txn_type' => 'Advance Created',
                'reference' => (string)($pay['reference'] ?: ('PAY-' . $pay['id'])),
                'description' => 'Supplier advance from payment',
                'debit' => 0.0,
                'credit' => 0.0,
                'ap_effect' => 0.0,
                'advance_effect' => 0.0, // already counted on Payment row
                'memo' => true,
                'link' => 'supplier_payment_view.php?id=' . (int)$pay['id'],
            ];
        }
    }

    if (function_exists('co_supplier_advance_schema_ready') && co_supplier_advance_schema_ready($conn)) {
        $st = $conn->prepare("
            SELECT a.id, a.amount, a.created_at, si.invoice_number, a.supplier_invoice_id, a.supplier_payment_id
            FROM co_supplier_advance_applications a
            JOIN co_supplier_invoices si ON si.id = a.supplier_invoice_id AND si.company_id = a.company_id
            WHERE a.company_id = ? AND a.supplier_id = ? AND a.status = 'posted'
              AND DATE(a.created_at) BETWEEN ? AND ?
            ORDER BY a.created_at ASC, a.id ASC
        ");
        $st->execute([$companyId, $supplierId, $dateFrom, $dateTo]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $app) {
            $amt = co_supplier_money($app['amount']);
            $rows[] = [
                'txn_date' => substr((string)$app['created_at'], 0, 10),
                'txn_type' => 'Advance Applied',
                'reference' => (string)$app['invoice_number'],
                'description' => 'Advance applied to invoice (from PAY-' . (int)$app['supplier_payment_id'] . ')',
                'debit' => 0.0,
                'credit' => $amt,
                'ap_effect' => -$amt,
                'advance_effect' => -$amt,
                'link' => 'supplier_invoice_view.php?id=' . (int)$app['supplier_invoice_id'],
            ];
        }
    }

    if (function_exists('co_supplier_advance_vat_table_ready') && co_supplier_advance_vat_table_ready($conn)) {
        $st = $conn->prepare("
            SELECT id, supplier_invoice_number, supplier_invoice_date, vat_amount
            FROM co_supplier_advance_vat_documents
            WHERE company_id = ? AND supplier_id = ? AND status = 'posted'
              AND supplier_invoice_date BETWEEN ? AND ?
            ORDER BY supplier_invoice_date ASC, id ASC
        ");
        $st->execute([$companyId, $supplierId, $dateFrom, $dateTo]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $doc) {
            $amt = co_supplier_money($doc['vat_amount']);
            $rows[] = [
                'txn_date' => (string)$doc['supplier_invoice_date'],
                'txn_type' => 'Advance VAT',
                'reference' => (string)$doc['supplier_invoice_number'],
                'description' => 'Advance VAT posted (Dr Input VAT / Cr Advances)',
                'debit' => $amt,
                'credit' => 0.0,
                'ap_effect' => 0.0,
                'advance_effect' => -$amt,
                'link' => 'supplier_advance_vat_view.php?id=' . (int)$doc['id'],
            ];
        }
    }

    if (function_exists('co_supplier_advance_refund_table_ready') && co_supplier_advance_refund_table_ready($conn)) {
        $st = $conn->prepare("
            SELECT id, refund_date, amount, reference
            FROM co_supplier_advance_refunds
            WHERE company_id = ? AND supplier_id = ? AND status = 'posted'
              AND refund_date BETWEEN ? AND ?
            ORDER BY refund_date ASC, id ASC
        ");
        $st->execute([$companyId, $supplierId, $dateFrom, $dateTo]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rf) {
            $amt = co_supplier_money($rf['amount']);
            $rows[] = [
                'txn_date' => (string)$rf['refund_date'],
                'txn_type' => 'Refund',
                'reference' => (string)($rf['reference'] ?: ('REF-' . $rf['id'])),
                'description' => 'Unused advance refunded to bank',
                'debit' => $amt,
                'credit' => 0.0,
                'ap_effect' => 0.0,
                'advance_effect' => -$amt,
                'link' => 'supplier_advance_refund_view.php?id=' . (int)$rf['id'],
            ];
        }
    }

    usort($rows, static function ($a, $b) {
        $cmp = strcmp((string)$a['txn_date'], (string)$b['txn_date']);
        if ($cmp !== 0) {
            return $cmp;
        }
        $order = [
            'Invoice' => 1,
            'Payment' => 2,
            'Advance Created' => 3,
            'Advance VAT' => 4,
            'Advance Applied' => 5,
            'Refund' => 6,
            'Void' => 7,
        ];
        return ($order[$a['txn_type']] ?? 50) <=> ($order[$b['txn_type']] ?? 50);
    });

    $apBal = $openingAp;
    $advBal = $openingAdv;
    $netBal = $openingNet;
    foreach ($rows as &$row) {
        if (empty($row['memo'])) {
            $apBal = co_supplier_money(max(0, $apBal + (float)$row['ap_effect']));
            $advBal = co_supplier_money(max(0, $advBal + (float)$row['advance_effect']));
            $netBal = co_supplier_money($apBal - $advBal);
        }
        $row['ap_balance'] = $apBal;
        $row['advance_balance'] = $advBal;
        $row['balance'] = $netBal; // Net Payable
        $row['debit'] = (float)$row['debit'];
        $row['credit'] = (float)$row['credit'];
    }
    unset($row);

    return [
        'opening' => $openingNet,
        'opening_ap' => $openingAp,
        'opening_advance' => $openingAdv,
        'closing' => $netBal,
        'closing_ap' => $apBal,
        'closing_advance' => $advBal,
        'rows' => $rows,
    ];
}

/**
 * Supplier spend and outstanding by project.
 * @return list<array<string,mixed>>
 */
function co_supplier_project_spend(PDO $conn, int $companyId, int $supplierId): array {
    $map = []; // project_id => row

    $add = static function (array &$map, ?int $projectId, string $code, string $name, float $invoiced, float $outstanding): void {
        $key = $projectId && $projectId > 0 ? (string)$projectId : '0';
        if (!isset($map[$key])) {
            $map[$key] = [
                'project_id' => $projectId && $projectId > 0 ? $projectId : null,
                'project_code' => $code !== '' ? $code : '—',
                'project_name' => $name !== '' ? $name : 'Unassigned',
                'invoiced' => 0.0,
                'outstanding' => 0.0,
                'invoice_count' => 0,
            ];
        }
        $map[$key]['invoiced'] = co_supplier_money($map[$key]['invoiced'] + $invoiced);
        $map[$key]['outstanding'] = co_supplier_money($map[$key]['outstanding'] + $outstanding);
        $map[$key]['invoice_count']++;
    };

    $voidSql = (function_exists('co_supplier_invoice_lifecycle_ready') && co_supplier_invoice_lifecycle_ready($conn))
        ? " AND si.status <> 'voided' "
        : '';

    $st = $conn->prepare("
        SELECT si.id, si.total, si.project_id, si.journal_id, p.project_code, p.project_name
        FROM co_supplier_invoices si
        LEFT JOIN co_projects p ON p.id = si.project_id AND p.company_id = si.company_id
        WHERE si.company_id = ? AND si.supplier_id = ?
          {$voidSql}
        ORDER BY si.id
    ");
    $st->execute([$companyId, $supplierId]);
    $invoices = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $hasItems = function_exists('co_db_table_exists') && co_db_table_exists($conn, 'co_supplier_invoice_items');

    foreach ($invoices as $inv) {
        $invId = (int)$inv['id'];
        $outstanding = !empty($inv['journal_id'])
            ? (function_exists('co_supplier_invoice_outstanding_amount')
                ? co_supplier_invoice_outstanding_amount($conn, $companyId, $invId)
                : max(0, (float)$inv['total']))
            : 0.0;
        $total = co_supplier_money($inv['total']);

        $lineProjects = [];
        if ($hasItems) {
            $ls = $conn->prepare("
                SELECT li.project_id, li.line_total, p.project_code, p.project_name
                FROM co_supplier_invoice_items li
                LEFT JOIN co_projects p ON p.id = li.project_id AND p.company_id = li.company_id
                WHERE li.company_id = ? AND li.invoice_id = ?
            ");
            $ls->execute([$companyId, $invId]);
            foreach ($ls->fetchAll(PDO::FETCH_ASSOC) ?: [] as $line) {
                if (!empty($line['project_id'])) {
                    $lineProjects[] = $line;
                }
            }
        }

        if ($lineProjects) {
            $lineSum = array_sum(array_map(static fn($l) => (float)$l['line_total'], $lineProjects));
            foreach ($lineProjects as $line) {
                $share = $lineSum > 0.005 ? ((float)$line['line_total'] / $lineSum) : 0;
                $add(
                    $map,
                    (int)$line['project_id'],
                    (string)($line['project_code'] ?? ''),
                    (string)($line['project_name'] ?? ''),
                    co_supplier_money($total * $share),
                    co_supplier_money($outstanding * $share)
                );
            }
        } else {
            $add(
                $map,
                !empty($inv['project_id']) ? (int)$inv['project_id'] : null,
                (string)($inv['project_code'] ?? ''),
                (string)($inv['project_name'] ?? ''),
                $total,
                $outstanding
            );
        }
    }

    $rows = array_values($map);
    usort($rows, static function ($a, $b) {
        return ($b['invoiced'] <=> $a['invoiced']);
    });
    return $rows;
}
