<?php
/**
 * Legal Department Module - Shared helpers (Phase 1)
 *
 * Centralizes enums/labels/colors, the controlled status workflow,
 * case/notice reference generation, polymorphic link handling, the case
 * event timeline, deadline state, audit logging, and lookup helpers.
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../../../includes/AuditService.php';

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// ---------------------------------------------------------------------------
// Enums / labels / colors
// ---------------------------------------------------------------------------
function legal_case_types(): array {
    return [
        'cheque_bounce'   => 'Bounced Cheque',
        'eviction'        => 'Eviction',
        'rent_recovery'   => 'Rent Recovery',
        'deposit_dispute' => 'Deposit Dispute',
        'contract_breach' => 'Contract Breach',
        'rdc_dispute'     => 'RDC Dispute',
        'other'           => 'Other',
    ];
}

function legal_case_sources(): array {
    return [
        'manual'          => 'Manual',
        'bounced_cheque'  => 'Bounced Cheque',
        'lease_violation' => 'Lease Violation',
        'tenant_complaint'=> 'Tenant Complaint',
        'renewal'         => 'Renewal',
        'document'        => 'Document',
        'other'           => 'Other',
    ];
}

function legal_case_statuses(): array {
    return [
        'draft'      => 'Draft',
        'open'       => 'Open',
        'notice_sent'=> 'Notice Sent',
        'filed'      => 'Filed',
        'in_hearing' => 'In Hearing',
        'judgment'   => 'Judgment',
        'execution'  => 'Execution',
        'settled'    => 'Settled',
        'closed'     => 'Closed',
        'withdrawn'  => 'Withdrawn',
    ];
}

function legal_status_color(string $status): string {
    $map = [
        'draft'      => 'secondary',
        'open'       => 'primary',
        'notice_sent'=> 'info',
        'filed'      => 'info',
        'in_hearing' => 'warning',
        'judgment'   => 'warning',
        'execution'  => 'dark',
        'settled'    => 'success',
        'closed'     => 'success',
        'withdrawn'  => 'secondary',
    ];
    return $map[$status] ?? 'secondary';
}

function legal_priorities(): array {
    return ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'];
}

function legal_priority_color(string $priority): string {
    $map = ['low' => 'secondary', 'medium' => 'info', 'high' => 'warning', 'urgent' => 'danger'];
    return $map[$priority] ?? 'secondary';
}

function legal_notice_types(): array {
    return [
        'warning'                    => 'Warning Letter',
        'demand_payment'             => 'Demand for Payment',
        'eviction_30day'             => 'Eviction Notice (30 days)',
        'eviction_12month_notarized' => 'Eviction Notice (12 months, notarized)',
        'cheque_bounce_demand'       => 'Bounced Cheque Demand',
        'contract_termination'       => 'Contract Termination',
        'final_notice'               => 'Final Notice',
        'other'                      => 'Other',
    ];
}

function legal_notice_delivery_methods(): array {
    return [
        'email'           => 'Email',
        'courier'         => 'Courier',
        'hand'            => 'Hand Delivery',
        'registered_post' => 'Registered Post',
        'notary'          => 'Notary Public',
        'other'           => 'Other',
    ];
}

function legal_delivery_statuses(): array {
    return [
        'draft'        => 'Draft',
        'sent'         => 'Sent',
        'delivered'    => 'Delivered',
        'acknowledged' => 'Acknowledged',
        'no_response'  => 'No Response',
    ];
}

function legal_link_types(): array {
    return [
        'building'    => 'Property / Building',
        'unit'        => 'Unit',
        'tenant'      => 'Tenant',
        'lease'       => 'Lease',
        'owner'       => 'Owner',
        'cheque'      => 'Cheque',
        'document'    => 'Document',
        'payment'     => 'Payment',
        'installment' => 'Installment',
    ];
}

// ---------------------------------------------------------------------------
// Controlled status workflow
// Draft -> Open -> Notice Sent -> Filed -> In Hearing -> Judgment ->
// Execution -> Settled / Closed / Withdrawn
// ---------------------------------------------------------------------------
function legal_allowed_transitions(string $current): array {
    $flow = [
        'draft'       => ['open', 'withdrawn'],
        'open'        => ['notice_sent', 'filed', 'settled', 'closed', 'withdrawn'],
        'notice_sent' => ['filed', 'settled', 'closed', 'withdrawn'],
        'filed'       => ['in_hearing', 'settled', 'closed', 'withdrawn'],
        'in_hearing'  => ['judgment', 'settled', 'closed', 'withdrawn'],
        'judgment'    => ['execution', 'settled', 'closed', 'withdrawn'],
        'execution'   => ['settled', 'closed', 'withdrawn'],
        'settled'     => ['closed', 'open'],
        'closed'      => ['open'],
        'withdrawn'   => ['open'],
    ];
    return $flow[$current] ?? [];
}

function legal_status_is_terminal(string $status): bool {
    return in_array($status, ['settled', 'closed', 'withdrawn'], true);
}

// ---------------------------------------------------------------------------
// Permissions
// ---------------------------------------------------------------------------
function legal_can_manage(?PDO $conn = null): bool {
    // Owner/Admin auto-pass inside has_department_access().
    if (has_department_access(MODULE_LEGAL, DEPT_LEGAL, $conn)) {
        return true;
    }
    // Legacy: realestate_legal dept before RBAC migration
    return has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_LEGAL, $conn);
}

function legal_require_access(?PDO $conn = null): void {
    if (!legal_can_manage($conn)) {
        require_department_access(MODULE_LEGAL, DEPT_LEGAL, $conn);
    }
}

/** Base path to Real Estate module pages (deep links from Legal). */
function legal_re_path(): string {
    return '../realestate/';
}

// ---------------------------------------------------------------------------
// Reference number generation (per company, per year)
// ---------------------------------------------------------------------------
function legal_generate_case_number(PDO $conn, int $companyId): string {
    $year = date('Y');
    $prefix = "LGL-{$year}-";
    $stmt = $conn->prepare("
        SELECT case_number FROM re_legal_cases
        WHERE company_id = ? AND case_number LIKE ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$companyId, $prefix . '%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last && preg_match('/(\d+)$/', $last, $m)) {
        $next = (int)$m[1] + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function legal_generate_notice_reference(PDO $conn, int $companyId): string {
    $year = date('Y');
    $prefix = "LGN-{$year}-";
    $stmt = $conn->prepare("
        SELECT reference_number FROM re_legal_notices
        WHERE company_id = ? AND reference_number LIKE ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$companyId, $prefix . '%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last && preg_match('/(\d+)$/', $last, $m)) {
        $next = (int)$m[1] + 1;
    }
    return $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

// ---------------------------------------------------------------------------
// Event timeline
// ---------------------------------------------------------------------------
function legal_log_event(PDO $conn, int $caseId, string $type, string $description, $old = null, $new = null): void {
    try {
        $stmt = $conn->prepare("
            INSERT INTO re_legal_case_events
            (case_id, event_type, description, old_value, new_value, event_date, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, CURDATE(), ?, NOW())
        ");
        $stmt->execute([
            $caseId, $type, $description,
            $old !== null ? (string)$old : null,
            $new !== null ? (string)$new : null,
            current_user_id(),
        ]);
    } catch (Throwable $e) {
        error_log('legal_log_event failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Polymorphic links
// ---------------------------------------------------------------------------
function legal_add_link(PDO $conn, int $caseId, string $type, int $linkId, ?string $label = null): bool {
    if (!array_key_exists($type, legal_link_types()) || $linkId <= 0) {
        return false;
    }
    try {
        $stmt = $conn->prepare("
            INSERT IGNORE INTO re_legal_case_links (case_id, link_type, link_id, label, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$caseId, $type, $linkId, $label, current_user_id()]);
        return true;
    } catch (Throwable $e) {
        error_log('legal_add_link failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Resolve a human label + native page URL for a linked entity.
 * Returns ['label' => string, 'url' => string|null].
 */
function legal_resolve_link(PDO $conn, string $type, int $id): array {
    $label = ucfirst($type) . ' #' . $id;
    $url = null;
    $re = legal_re_path();
    try {
        switch ($type) {
            case 'building':
                $s = $conn->prepare("SELECT name FROM re_buildings WHERE id = ?");
                $s->execute([$id]);
                if ($n = $s->fetchColumn()) { $label = $n; }
                $url = $re . 'buildings.php';
                break;
            case 'unit':
                $s = $conn->prepare("SELECT u.unit_number, b.name FROM re_units u JOIN re_buildings b ON b.id = u.building_id WHERE u.id = ?");
                $s->execute([$id]);
                if ($r = $s->fetch(PDO::FETCH_ASSOC)) { $label = $r['name'] . ' - ' . $r['unit_number']; }
                $url = $re . 'unit_view.php?id=' . $id;
                break;
            case 'tenant':
                $s = $conn->prepare("SELECT TRIM(CONCAT(first_name, ' ', last_name)) AS nm, company_name, tenant_type FROM re_tenants WHERE id = ?");
                $s->execute([$id]);
                if ($r = $s->fetch(PDO::FETCH_ASSOC)) {
                    $label = ($r['tenant_type'] === 'company' && $r['company_name']) ? $r['company_name'] : ($r['nm'] ?: $label);
                }
                $url = $re . 'tenant_view.php?id=' . $id;
                break;
            case 'lease':
                $s = $conn->prepare("SELECT lease_number FROM re_leases WHERE id = ?");
                $s->execute([$id]);
                if ($n = $s->fetchColumn()) { $label = $n; }
                $url = $re . 'lease_view.php?id=' . $id;
                break;
            case 'owner':
                $s = $conn->prepare("SELECT name FROM re_property_owners WHERE id = ?");
                $s->execute([$id]);
                if ($n = $s->fetchColumn()) { $label = $n; }
                break;
            case 'cheque':
                $s = $conn->prepare("SELECT cheque_number, cheque_amount FROM re_post_dated_cheques WHERE id = ?");
                $s->execute([$id]);
                if ($r = $s->fetch(PDO::FETCH_ASSOC)) { $label = $r['cheque_number'] . ' (' . number_format((float)$r['cheque_amount'], 2) . ')'; }
                $url = $re . 'billing_cheque_view.php?id=' . $id;
                break;
            case 'document':
                $s = $conn->prepare("SELECT document_name, file_name FROM re_documents WHERE id = ?");
                $s->execute([$id]);
                if ($r = $s->fetch(PDO::FETCH_ASSOC)) { $label = $r['document_name'] ?: $r['file_name']; }
                $url = $re . 'documents_view.php?id=' . $id;
                break;
            case 'payment':
                $s = $conn->prepare("SELECT receipt_number, amount, payment_date FROM re_payments WHERE id = ?");
                $s->execute([$id]);
                if ($r = $s->fetch(PDO::FETCH_ASSOC)) { $label = ($r['receipt_number'] ?: ('Payment #' . $id)) . ' (' . number_format((float)$r['amount'], 2) . ')'; }
                $url = $re . 'payment_view.php?id=' . $id;
                break;
            case 'installment':
                $s = $conn->prepare("SELECT installment_date, amount FROM re_lease_installments WHERE id = ?");
                $s->execute([$id]);
                if ($r = $s->fetch(PDO::FETCH_ASSOC)) { $label = 'Installment ' . $r['installment_date'] . ' (' . number_format((float)$r['amount'], 2) . ')'; }
                break;
        }
    } catch (Throwable $e) {
        error_log('legal_resolve_link failed: ' . $e->getMessage());
    }
    return ['label' => $label, 'url' => $url];
}

function legal_fetch_links(PDO $conn, int $caseId): array {
    $stmt = $conn->prepare("SELECT * FROM re_legal_case_links WHERE case_id = ? ORDER BY link_type, id");
    $stmt->execute([$caseId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $resolved = legal_resolve_link($conn, $row['link_type'], (int)$row['link_id']);
        $row['resolved_label'] = $row['label'] ?: $resolved['label'];
        $row['url'] = $resolved['url'];
    }
    return $rows;
}

// ---------------------------------------------------------------------------
// Deadline state
// Returns 'overdue' | 'due_soon' | 'none'
// ---------------------------------------------------------------------------
function legal_deadline_state(?string $deadlineDate, int $reminderDays, string $status): string {
    if (empty($deadlineDate) || legal_status_is_terminal($status)) {
        return 'none';
    }
    $today = new DateTime('today');
    try {
        $deadline = new DateTime($deadlineDate);
    } catch (Throwable $e) {
        return 'none';
    }
    $deadline->setTime(0, 0, 0);
    if ($deadline < $today) {
        return 'overdue';
    }
    $reminderDays = max(0, $reminderDays);
    $threshold = (clone $today)->modify('+' . $reminderDays . ' days');
    if ($deadline <= $threshold) {
        return 'due_soon';
    }
    return 'none';
}

function legal_deadline_badge(?string $deadlineDate, int $reminderDays, string $status): string {
    $state = legal_deadline_state($deadlineDate, $reminderDays, $status);
    if ($state === 'overdue') {
        return '<span class="badge bg-danger">Overdue</span>';
    }
    if ($state === 'due_soon') {
        return '<span class="badge bg-warning text-dark">Due soon</span>';
    }
    return '';
}

// ---------------------------------------------------------------------------
// Audit wrapper
// ---------------------------------------------------------------------------
function legal_audit(string $action, string $objectType, $objectId, string $summary, $old = null, $new = null): void {
    $companyId = null;
    if (function_exists('current_company_id')) {
        global $conn;
        if ($conn instanceof PDO) {
            $companyId = current_company_id($conn);
        }
    }
    AuditService::logEvent([
        'action'       => $action,
        'action_label' => AuditService::actionLabel($action),
        'module'       => 'legal',
        'company_id'   => $companyId,
        'object_type'  => $objectType,
        'object_id'    => $objectId !== null ? (string)$objectId : null,
        'object_ref'   => $objectId !== null ? (strtoupper(str_replace('_', ' ', $objectType)) . ' #' . $objectId) : null,
        'summary'      => $summary,
        'old_data'     => $old,
        'new_data'     => $new,
        'source'       => 'user',
        'success'      => true,
    ]);
}

// ---------------------------------------------------------------------------
// Lookups for dropdowns
// ---------------------------------------------------------------------------
function legal_fetch_buildings(PDO $conn, int $companyId): array {
    $stmt = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? AND is_active = 1 ORDER BY name");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function legal_fetch_owners(PDO $conn, int $companyId): array {
    $stmt = $conn->prepare("SELECT id, name FROM re_property_owners WHERE company_id = ? AND is_active = 1 ORDER BY name");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function legal_fetch_assignable_users(PDO $conn, int $companyId): array {
    $stmt = $conn->prepare("
        SELECT DISTINCT u.id, COALESCE(NULLIF(u.fullname, ''), u.username) AS name
        FROM user u
        JOIN user_companies uc ON uc.user_id = u.id
        LEFT JOIN user_roles ur ON ur.user_id = u.id
        LEFT JOIN roles r ON r.id = ur.role_id
        WHERE uc.company_id = ?
          AND (r.module IN ('realestate','legal') OR r.name IN ('Owner', 'Admin'))
        ORDER BY name
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Tenant display name helper for a tenant row (handles company vs individual).
 */
function legal_tenant_name(array $t): string {
    if (($t['tenant_type'] ?? '') === 'company' && !empty($t['company_name'])) {
        return $t['company_name'];
    }
    return trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? '')) ?: ('Tenant #' . ($t['id'] ?? ''));
}

// ---------------------------------------------------------------------------
// Phase 2 — hearings, counsel, costs
// ---------------------------------------------------------------------------
function legal_hearing_types(): array {
    return [
        'hearing' => 'Court Hearing',
        'deadline' => 'Deadline',
        'filing' => 'Filing',
        'mediation' => 'Mediation',
        'site_visit' => 'Site Visit',
        'other' => 'Other',
    ];
}

function legal_hearing_statuses(): array {
    return ['scheduled' => 'Scheduled', 'completed' => 'Completed', 'postponed' => 'Postponed', 'cancelled' => 'Cancelled'];
}

function legal_cost_types(): array {
    return [
        'court_fee' => 'Court Fee',
        'counsel_fee' => 'Counsel Fee',
        'notary' => 'Notary',
        'courier' => 'Courier',
        'filing' => 'Filing',
        'translation' => 'Translation',
        'expert' => 'Expert Witness',
        'other' => 'Other',
    ];
}

function legal_counsel_types(): array {
    return ['firm' => 'Law Firm', 'individual' => 'Individual Lawyer'];
}

function legal_fetch_counsel(PDO $conn, int $companyId, bool $activeOnly = true): array {
    $sql = "SELECT id, name, counsel_type FROM re_legal_counsel WHERE company_id = ?";
    if ($activeOnly) { $sql .= " AND is_active = 1"; }
    $sql .= " ORDER BY name";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function legal_case_cost_total(PDO $conn, int $caseId): float {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM re_legal_case_costs WHERE case_id = ?");
    $stmt->execute([$caseId]);
    return (float)$stmt->fetchColumn();
}
