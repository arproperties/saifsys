<?php
/**
 * Tenant Portal — Lease renewal workflow helpers (Phase 1).
 * Requires: $conn (PDO), tenant_auth loaded, migration tenant_portal_renewal_phase1.sql applied.
 */

if (!defined('TENANT_RENEWAL_UPLOAD_MAX_BYTES')) {
    define('TENANT_RENEWAL_UPLOAD_MAX_BYTES', 8 * 1024 * 1024);
}

function tenant_renewal_client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function tenant_renewal_user_agent(): string {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return strlen($ua) > 500 ? substr($ua, 0, 500) : $ua;
}

/**
 * @return array{tpu_id:int, legacy_user_id:int}
 */
function tenant_renewal_actor_ids(): array {
    $tpu = (int)($_SESSION[TENANT_PORTAL_USER_SESSION_KEY] ?? 0);
    $legacy = 0;
    if (!$tpu) {
        $legacy = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);
    }
    return ['tpu_id' => $tpu, 'legacy_user_id' => $legacy];
}

/**
 * Load renewal workflow if it belongs to the given lease + company.
 */
function tenant_renewal_fetch_workflow(PDO $conn, int $workflowId, int $leaseId, int $companyId): ?array {
    // After Approve & Convert, the tenant often switches to new_lease_id; rw.lease_id still points at the old lease.
    $st = $conn->prepare("
        SELECT rw.*
        FROM re_lease_renewal_workflows rw
        INNER JOIN re_leases l ON l.id = rw.lease_id AND l.company_id = ?
        WHERE rw.id = ? AND (rw.lease_id = ? OR rw.new_lease_id = ?)
        LIMIT 1
    ");
    $st->execute([$companyId, $workflowId, $leaseId, $leaseId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function tenant_renewal_log_event(
    PDO $conn,
    int $workflowId,
    int $companyId,
    string $eventType,
    ?string $message,
    ?string $metaJson = null
): void {
    try {
        $actor = tenant_renewal_actor_ids();
        $st = $conn->prepare("
            INSERT INTO re_renewal_portal_events
            (workflow_id, company_id, event_type, message, tenant_portal_user_id, legacy_user_id, ip_address, user_agent, meta_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $st->execute([
            $workflowId,
            $companyId,
            $eventType,
            $message,
            $actor['tpu_id'] ?: null,
            $actor['legacy_user_id'] ?: null,
            tenant_renewal_client_ip() ?: null,
            tenant_renewal_user_agent(),
            $metaJson,
        ]);
    } catch (Throwable $e) {
        error_log('tenant_renewal_log_event: ' . $e->getMessage());
    }
}

/**
 * Mark first portal open: notice_sent → viewed_by_tenant, timestamp once.
 */
function tenant_renewal_on_first_open(PDO $conn, array $wf, int $leaseId, int $companyId): array {
    if (!function_exists('renewal_wf_is_completed_like')) {
        require_once __DIR__ . '/../../includes/renewal_workflow_transitions.php';
    }
    $id = (int)$wf['id'];
    $st = (string)($wf['status'] ?? '');
    if (renewal_wf_is_completed_like($st)) {
        return $wf;
    }
    $logOpen = ($st === 'notice_sent') && empty($wf['tenant_first_viewed_at']);
    $conn->prepare("
        UPDATE re_lease_renewal_workflows
        SET tenant_first_viewed_at = COALESCE(tenant_first_viewed_at, NOW()),
            status = IF(status = 'notice_sent', 'viewed_by_tenant', status)
        WHERE id = ? AND (lease_id = ? OR new_lease_id = ?)
          AND status NOT IN ('converted','rejected','completed')
    ")->execute([$id, $leaseId, $leaseId]);
    if ($logOpen) {
        tenant_renewal_log_event($conn, $id, $companyId, 'notice_opened', 'Tenant opened renewal notice in portal.');
    }
    $fresh = tenant_renewal_fetch_workflow($conn, $id, $leaseId, $companyId);
    return $fresh ?: $wf;
}

/**
 * Normalise a name for e-signature comparison (case, spacing, some punctuation).
 */
function tenant_renewal_normalize_signature_name(string $s): string {
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    if (function_exists('mb_strtolower')) {
        $s = mb_strtolower($s, 'UTF-8');
    } else {
        $s = strtolower($s);
    }
    // NBSP, commas, periods → space; hyphens → space so "Al-Maktoum" matches "Al Maktoum" (keep apostrophes e.g. O'Brien)
    $s = str_replace(["\xc2\xa0", ',', '.', '"'], ' ', $s);
    $s = preg_replace('/[-_]+/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

/** @return list<string> */
function tenant_renewal_signature_name_tokens(string $s): array {
    $s = tenant_renewal_normalize_signature_name($s);
    if ($s === '') {
        return [];
    }
    $parts = preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
    return array_values($parts);
}

/**
 * True if typed full name matches tenant first + last (order-independent; extra/missing words fail).
 */
function tenant_renewal_typed_name_matches_lease_tenant(string $typed, string $firstName, string $lastName): bool {
    $expected = [];
    foreach (tenant_renewal_signature_name_tokens($firstName) as $t) {
        $expected[] = $t;
    }
    foreach (tenant_renewal_signature_name_tokens($lastName) as $t) {
        $expected[] = $t;
    }
    if (empty($expected)) {
        return false;
    }
    $got = tenant_renewal_signature_name_tokens($typed);
    if (empty($got)) {
        return false;
    }
    sort($expected, SORT_STRING);
    sort($got, SORT_STRING);
    return $expected === $got;
}

function tenant_renewal_expected_signer_display_name(string $firstName, string $lastName): string {
    return trim(trim($firstName) . ' ' . trim($lastName));
}

function tenant_renewal_allowed_mime(string $ext): ?string {
    $map = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];
    return $map[strtolower($ext)] ?? null;
}

function tenant_renewal_status_label(string $s): string {
    $labels = [
        'initiated' => 'Initiated',
        'notice_sent' => 'Notice sent',
        'viewed_by_tenant' => 'Viewed by tenant',
        'acknowledged' => 'Acknowledged',
        'pending_response' => 'Pending response',
        'negotiation' => 'Negotiation',
        'accepted' => 'Accepted by tenant',
        'rejected' => 'Rejected',
        'approved' => 'Approved',
        'contract_ready' => 'Contract ready to sign',
        'signed' => 'Signed electronically',
        'completed' => 'Completed',
        'converted' => 'Converted',
    ];
    return $labels[$s] ?? ucfirst(str_replace('_', ' ', $s));
}
