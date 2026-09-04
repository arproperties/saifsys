<?php
/**
 * ARS Home Rentals — Shared Helpers
 */

if (!function_exists('h')) {
    /** HTML-escape helper used across ARS pages (shell + legacy layouts). */
    function h($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('getBrandSettings')) {
    require_once dirname(__DIR__, 3) . '/includes/branding.php';
}
require_once __DIR__ . '/ars_guest_notifications.php';
require_once __DIR__ . '/ars_financial_core_version.php';

function getArsCompanyId(PDO $conn): int {
    static $id = null;
    if ($id !== null) return $id;
    $stmt = $conn->prepare("SELECT id FROM companies WHERE code = 'ARS' AND is_active = 1 LIMIT 1");
    $stmt->execute();
    $id = (int) ($stmt->fetchColumn() ?: 0);
    return $id;
}

function getArsSettings(PDO $conn, ?int $companyId = null): array {
    arsEnsureBookingNotificationSettings($conn);
    $cid = $companyId ?: getArsCompanyId($conn);
    $stmt = $conn->prepare("SELECT * FROM ars_company_settings WHERE company_id = ? LIMIT 1");
    $stmt->execute([$cid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [
            'company_id' => $cid,
            'pending_expiry_hours' => 24,
            'default_vat_rate' => 5.00,
            'default_check_in_time' => '15:00:00',
            'default_check_out_time' => '12:00:00',
            'cleaning_company_id' => null,
            'default_cleaning_fee' => 0.00,
            'default_worker_id' => null,
            'default_driver_id' => null,
            'currency' => 'AED',
            'booking_notification_emails' => '',
            'stripe_enabled' => 0,
            'stripe_mode' => 'test',
            'stripe_publishable_key_test' => '',
            'stripe_secret_key_test_enc' => '',
            'stripe_webhook_secret_test_enc' => '',
            'stripe_publishable_key_live' => '',
            'stripe_secret_key_live_enc' => '',
            'stripe_webhook_secret_live_enc' => '',
            'stripe_default_currency' => 'AED',
            'stripe_payment_policy' => 'full',
            'stripe_deposit_percentage' => 20.00,
            'stripe_auto_confirm' => 0,
            'stripe_success_url' => '',
            'stripe_failed_url' => '',
        ];
    }
    return $row;
}

function arsEnsureBookingNotificationSettings(PDO $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $stmt = $conn->query("SHOW COLUMNS FROM ars_company_settings LIKE 'booking_notification_emails'");
        if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
            $conn->exec("ALTER TABLE ars_company_settings ADD COLUMN booking_notification_emails TEXT NULL AFTER currency");
        }
    } catch (Throwable $e) {
        error_log('ARS settings migration failed: ' . $e->getMessage());
    }

    arsEnsureDocumentBrandingSettings($conn);
}

/** Additive columns for PDF document branding (Wave A). */
function arsEnsureDocumentBrandingSettings(PDO $conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $cols = [
        'doc_brand_name' => "VARCHAR(160) NULL",
        'doc_brand_address' => "TEXT NULL",
        'doc_brand_email' => "VARCHAR(160) NULL",
        'doc_brand_phone' => "VARCHAR(60) NULL",
        'doc_brand_trn' => "VARCHAR(60) NULL",
        'doc_brand_logo_path' => "VARCHAR(500) NULL",
        'doc_brand_primary_color' => "VARCHAR(7) NULL",
        'doc_brand_accent_color' => "VARCHAR(7) NULL",
    ];
    foreach ($cols as $name => $def) {
        try {
            $stmt = $conn->query("SHOW COLUMNS FROM ars_company_settings LIKE " . $conn->quote($name));
            if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
                $conn->exec("ALTER TABLE ars_company_settings ADD COLUMN {$name} {$def}");
            }
        } catch (Throwable $e) {
            error_log('ARS doc branding column ' . $name . ': ' . $e->getMessage());
        }
    }
}

function generateBookingNumber(PDO $conn, int $companyId): string {
    $year = date('y');
    $stmt = $conn->prepare("SELECT COUNT(*) + 1 FROM ars_bookings WHERE company_id = ? AND YEAR(created_at) = YEAR(NOW())");
    $stmt->execute([$companyId]);
    $seq = (int) $stmt->fetchColumn();
    return 'ARS-' . $year . '-' . str_pad($seq, 5, '0', STR_PAD_LEFT);
}

function formatArsAmount($amount, string $currency = 'AED'): string {
    return $currency . ' ' . number_format((float) $amount, 2);
}

/**
 * Expire pending bookings past their expires_at.
 * Called on page load of booking-related pages (no cron needed).
 */
function expirePendingBookings(PDO $conn, int $companyId): int {
    ars_guest_notifications_ensure_schema($conn);
    $expiredRows = [];
    $pick = $conn->prepare("
        SELECT id, guest_id, booking_number
        FROM ars_bookings
        WHERE company_id = ?
          AND status = 'pending'
          AND expires_at IS NOT NULL
          AND expires_at < NOW()
          AND COALESCE(is_historical, 0) = 0
    ");
    $pick->execute([$companyId]);
    $expiredRows = $pick->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $conn->prepare("
        UPDATE ars_bookings
        SET status = 'expired', updated_at = NOW()
        WHERE company_id = ?
          AND status = 'pending'
          AND expires_at IS NOT NULL
          AND expires_at < NOW()
          AND COALESCE(is_historical, 0) = 0
    ");
    $stmt->execute([$companyId]);
    $affected = $stmt->rowCount();

    if ($affected > 0 && $expiredRows) {
        foreach ($expiredRows as $row) {
            $bookingId = (int)($row['id'] ?? 0);
            $guestId = (int)($row['guest_id'] ?? 0);
            if ($bookingId <= 0 || $guestId <= 0) {
                continue;
            }
            ars_guest_notification_create($conn, [
                'company_id' => $companyId,
                'guest_id' => $guestId,
                'booking_id' => $bookingId,
                'event_type' => 'booking_expired',
                'title' => 'Booking request expired',
                'message' => 'Booking ' . (string)($row['booking_number'] ?? ('#' . $bookingId)) . ' expired before payment/confirmation.',
                'cta_route' => '/guest/bookings/' . $bookingId,
            ]);
        }
    }

    return $affected;
}

function arsBookingStatusBadge(string $status): string {
    $map = [
        'pending'     => '<span class="ars-badge pending"><i class="bi bi-clock-fill"></i> Pending</span>',
        'confirmed'   => '<span class="ars-badge confirmed"><i class="bi bi-check-circle-fill"></i> Confirmed</span>',
        'checked_in'  => '<span class="ars-badge check-in"><i class="bi bi-box-arrow-in-right"></i> Checked In</span>',
        'checked_out' => '<span class="ars-badge check-out"><i class="bi bi-box-arrow-right"></i> Checked Out</span>',
        'completed'   => '<span class="ars-badge booked"><i class="bi bi-check2-all"></i> Completed</span>',
        'cancelled'   => '<span class="ars-badge cancelled"><i class="bi bi-x-circle-fill"></i> Cancelled</span>',
        'expired'     => '<span class="ars-badge expired"><i class="bi bi-hourglass-bottom"></i> Expired</span>',
    ];
    return $map[$status] ?? '<span class="ars-badge">' . htmlspecialchars($status) . '</span>';
}

function arsPaymentStatusBadge(string $status): string {
    $map = [
        'unpaid'   => '<span class="badge bg-danger">Unpaid</span>',
        'partial'  => '<span class="badge bg-warning text-dark">Partial</span>',
        'paid'     => '<span class="badge bg-success">Paid</span>',
        'refunded' => '<span class="badge bg-secondary">Refunded</span>',
        'failed'   => '<span class="badge bg-danger">Failed</span>',
    ];
    return $map[$status] ?? '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
}

/**
 * Fetch all journal entries related to a booking (revenue, payments, deposits, reversals).
 * Returns structured array for the Accounting Trail display.
 */
function ars_get_booking_journals(PDO $conn, int $bookingId, array $paymentIds = []): array {
    $journals = [];

    $refPairs = [['ars_booking', $bookingId]];
    foreach ($paymentIds as $pid) {
        $refPairs[] = ['ars_payment', (int)$pid];
    }
    $refPairs[] = ['ars_deposit', $bookingId];
    $refPairs[] = ['ars_deposit_refund', $bookingId];
    $refPairs[] = ['ars_deposit_settlement', $bookingId];

    // Phase 2B adapter: revenue/payment/deposit journals reference Option B documents, not ars_booking.
    $extraJournalIds = [];
    try {
        $chk = $conn->query("SHOW TABLES LIKE 'ars_financial_documents'");
        if ($chk && $chk->fetchColumn()) {
            $ds = $conn->prepare("
                SELECT id, journal_id, reversal_journal_id
                FROM ars_financial_documents
                WHERE booking_id = ?
            ");
            $ds->execute([$bookingId]);
            foreach ($ds->fetchAll(PDO::FETCH_ASSOC) as $doc) {
                $refPairs[] = ['ars_financial_document', (int)$doc['id']];
                if (!empty($doc['journal_id'])) {
                    $extraJournalIds[] = (int)$doc['journal_id'];
                }
                if (!empty($doc['reversal_journal_id'])) {
                    $extraJournalIds[] = (int)$doc['reversal_journal_id'];
                }
            }
        }
    } catch (Throwable $e) {
        // Tables may be absent when adapter is not installed.
    }

    // Also include journals linked directly on the booking row (legacy + adapter).
    try {
        $bs = $conn->prepare("
            SELECT journal_id, deposit_journal_id, deposit_refund_journal_id, deposit_settlement_journal_id
            FROM ars_bookings WHERE id = ? LIMIT 1
        ");
        $bs->execute([$bookingId]);
        $bRow = $bs->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (['journal_id', 'deposit_journal_id', 'deposit_refund_journal_id', 'deposit_settlement_journal_id'] as $col) {
            if (!empty($bRow[$col])) {
                $extraJournalIds[] = (int)$bRow[$col];
            }
        }
    } catch (Throwable $e) {
        // Older schemas may lack settlement column.
    }

    $orClauses = [];
    $params = [];
    foreach ($refPairs as [$rType, $rId]) {
        $orClauses[] = "(h.reference_type = ? AND h.reference_id = ?)";
        $params[] = $rType;
        $params[] = $rId;
    }

    $extraJournalIds = array_values(array_unique(array_filter($extraJournalIds)));
    if ($extraJournalIds) {
        $placeholders = implode(',', array_fill(0, count($extraJournalIds), '?'));
        $orClauses[] = "h.id IN ($placeholders)";
        foreach ($extraJournalIds as $jid) {
            $params[] = $jid;
        }
    }

    if (empty($orClauses)) return [];

    $sql = "SELECT h.* FROM re_journal_headers h WHERE (" . implode(' OR ', $orClauses) . ") ORDER BY h.journal_date, h.id";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $headers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $reversalIds = array_filter(array_column($headers, 'reversal_journal_id'));
    if ($reversalIds) {
        $placeholders = implode(',', array_fill(0, count($reversalIds), '?'));
        $stmt = $conn->prepare("SELECT * FROM re_journal_headers WHERE id IN ($placeholders) ORDER BY journal_date, id");
        $stmt->execute(array_values($reversalIds));
        $reversals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $headers = array_merge($headers, $reversals);
    }

    $seen = [];
    foreach ($headers as $h) {
        if (isset($seen[$h['id']])) continue;
        $seen[$h['id']] = true;

        $lines = $conn->prepare("
            SELECT l.*, a.account_code, a.account_name
            FROM re_journal_lines l
            LEFT JOIN re_chart_of_accounts a ON a.id = l.account_id
            WHERE l.journal_id = ?
            ORDER BY l.id
        ");
        $lines->execute([$h['id']]);

        $typeLabel = 'Journal';
        $refType = (string)($h['reference_type'] ?? '');
        $jType = (string)($h['journal_type'] ?? '');
        if ($refType === 'ars_booking') $typeLabel = 'Revenue';
        elseif ($refType === 'ars_payment') $typeLabel = 'Payment';
        elseif ($refType === 'ars_deposit') $typeLabel = 'Deposit Received';
        elseif ($refType === 'ars_deposit_refund') $typeLabel = 'Deposit Refund';
        elseif ($refType === 'ars_deposit_settlement') $typeLabel = 'Deposit Settlement';
        elseif ($refType === 'ars_financial_document') {
            if ($jType === 'invoice' || $jType === 'revenue') $typeLabel = 'Invoice / Revenue';
            elseif ($jType === 'payment') $typeLabel = 'Payment';
            elseif ($jType === 'deposit') $typeLabel = 'Deposit';
            elseif ($jType === 'refund') $typeLabel = 'Refund';
            else $typeLabel = 'Financial Document';
        }
        elseif ($jType === 'reversal') $typeLabel = 'Reversal';

        $status = 'Draft';
        if (!empty($h['is_reversed'])) $status = 'Reversed';
        elseif (!empty($h['is_posted'])) $status = 'Posted';

        $journals[] = [
            'id' => (int)$h['id'],
            'journal_number' => $h['journal_number'] ?? '',
            'type_label' => $typeLabel,
            'journal_type' => $h['journal_type'] ?? '',
            'reference_type' => $h['reference_type'] ?? '',
            'date' => $h['journal_date'] ?? '',
            'status' => $status,
            'is_posted' => !empty($h['is_posted']),
            'is_reversed' => !empty($h['is_reversed']),
            'total_debit' => (float)($h['total_debit'] ?? 0),
            'description' => $h['description'] ?? '',
            'lines' => $lines->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    usort($journals, fn($a, $b) => $a['id'] <=> $b['id']);
    return $journals;
}

function arsPricingRuleTypeBadge(string $type): string {
    $map = [
        'seasonal'        => '<span class="badge bg-primary"><i class="bi bi-sun me-1"></i>Seasonal</span>',
        'weekend'         => '<span class="badge bg-warning text-dark"><i class="bi bi-calendar-week me-1"></i>Weekend</span>',
        'length_discount' => '<span class="badge bg-success"><i class="bi bi-percent me-1"></i>Length Discount</span>',
        'minimum_stay'    => '<span class="badge bg-danger"><i class="bi bi-calendar-minus me-1"></i>Min Stay</span>',
    ];
    return $map[$type] ?? '<span class="badge bg-secondary">' . htmlspecialchars($type) . '</span>';
}

function arsFormatRuleValue(array $rule): string {
    $type = $rule['rule_type'] ?? '';
    if ($type === 'seasonal' || $type === 'weekend') {
        if ($rule['rate_amount'] !== null && (float)$rule['rate_amount'] > 0) {
            return 'AED ' . number_format((float)$rule['rate_amount'], 2) . '/night';
        }
        if ($rule['rate_modifier'] !== null) {
            $mod = (float)$rule['rate_modifier'];
            return ($mod >= 0 ? '+' : '') . number_format($mod, 1) . '%';
        }
        return '—';
    }
    if ($type === 'length_discount') {
        $pct = (float)($rule['discount_percent'] ?? 0);
        $min = (int)($rule['min_nights'] ?? 0);
        return $min . '+ nights → ' . number_format($pct, 1) . '% off';
    }
    if ($type === 'minimum_stay') {
        return (int)($rule['min_nights'] ?? 0) . ' nights min';
    }
    return '—';
}

function arsMaintenancePriorityBadge(string $priority): string {
    $map = [
        'low'    => '<span class="badge bg-secondary">Low</span>',
        'medium' => '<span class="badge bg-info text-dark">Medium</span>',
        'high'   => '<span class="badge bg-warning text-dark">High</span>',
        'urgent' => '<span class="badge bg-danger">Urgent</span>',
    ];
    return $map[$priority] ?? '<span class="badge bg-secondary">' . htmlspecialchars($priority) . '</span>';
}

function arsMaintenanceStatusBadge(string $status): string {
    $map = [
        'pending'     => '<span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Pending</span>',
        'in_progress' => '<span class="badge bg-primary"><i class="bi bi-gear me-1"></i>In Progress</span>',
        'completed'   => '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Completed</span>',
        'cancelled'   => '<span class="badge bg-secondary"><i class="bi bi-x-circle me-1"></i>Cancelled</span>',
    ];
    return $map[$status] ?? '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
}

function arsBlockTypeBadge(string $type): string {
    if ($type === 'maintenance') {
        return '<span class="badge bg-danger"><i class="bi bi-wrench me-1"></i>Maintenance</span>';
    }
    return '<span class="badge bg-secondary"><i class="bi bi-calendar-x me-1"></i>Manual</span>';
}

function arsDepositStatusBadge(string $status): string {
    $map = [
        'none'                 => '<span class="badge bg-secondary">None</span>',
        'pending'              => '<span class="badge bg-warning text-dark">Pending</span>',
        'received'             => '<span class="badge bg-success">Received</span>',
        'partially_refunded'   => '<span class="badge bg-info">Partially Refunded</span>',
        'refunded'             => '<span class="badge bg-secondary">Refunded</span>',
        'forfeited'            => '<span class="badge bg-dark">Forfeited</span>',
    ];
    return $map[$status] ?? '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
}

/** Standard ARS page auth boilerplate — returns company ID */
function arsPageAuth(PDO $conn): int {
    require_once dirname(__DIR__, 3) . '/includes/module_access.php';
    require_once dirname(__DIR__, 3) . '/includes/rbac_department.php';
    require_login();
    $hasArsCore = has_department_access(MODULE_ARS, DEPT_ARS_CORE, $conn);
    $hasArsOps  = has_department_access(MODULE_ARS, DEPT_ARS_OPERATIONS, $conn);
    if (!$hasArsCore && !$hasArsOps) {
        require_module_access($conn, MODULE_ARS);
    }
    return getArsCompanyId($conn);
}
