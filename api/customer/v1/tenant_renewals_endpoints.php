<?php
/**
 * Tenant lease renewals — list, detail, actions (mirrors tenant_portal renewal_detail.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../tenant_portal/includes/renewal_portal_helper.php';
require_once __DIR__ . '/../../../includes/renewal_workflow_transitions.php';
require_once __DIR__ . '/../../../includes/renewal_negotiation_thread.php';

/**
 * @param array<string,mixed> $ctx
 * @return array{tpu_id:int,legacy_user_id:int}
 */
function customer_api_tenant_renewal_actor_ids(array $ctx): array {
    return [
        'tpu_id' => (int)($ctx['tenant_portal_user_id'] ?? 0),
        'legacy_user_id' => (int)($ctx['user_id'] ?? 0),
    ];
}

function customer_api_tenant_renewals_configured(PDO $conn): bool {
    try {
        $conn->query('SELECT 1 FROM re_lease_renewal_workflows LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return array<string,mixed>|null
 */
function customer_api_tenant_renewal_load_lease_summary(PDO $conn, int $leaseId): ?array {
    $stmt = $conn->prepare("
        SELECT l.id AS lease_id, l.lease_number, l.start_date, l.end_date, l.annual_rent,
               l.monthly_rent, l.status AS lease_status,
               u.unit_number, u.unit_type, b.name AS building_name,
               t.first_name, t.last_name
        FROM re_leases l
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        JOIN re_tenants t ON t.id = l.tenant_id
        WHERE l.id = ?
        LIMIT 1
    ");
    $stmt->execute([$leaseId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'lease_id' => (int)$row['lease_id'],
        'lease_number' => (string)($row['lease_number'] ?? ''),
        'building_name' => (string)($row['building_name'] ?? ''),
        'unit_number' => (string)($row['unit_number'] ?? ''),
        'unit_type' => (string)($row['unit_type'] ?? ''),
        'start_date' => (string)($row['start_date'] ?? ''),
        'end_date' => (string)($row['end_date'] ?? ''),
        'annual_rent' => $row['annual_rent'] !== null
            ? number_format((float)$row['annual_rent'], 2, '.', '')
            : null,
        'monthly_rent' => $row['monthly_rent'] !== null
            ? number_format((float)$row['monthly_rent'], 2, '.', '')
            : null,
        'lease_status' => (string)($row['lease_status'] ?? ''),
        'tenant_name' => trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')),
    ];
}

/**
 * @param array<string,mixed> $wf
 * @return list<array<string,mixed>>
 */
function customer_api_tenant_renewal_timeline(array $wf, bool $hasSignature): array {
    $status = (string)($wf['status'] ?? '');
    $steps = [
        [
            'key' => 'notice',
            'label' => 'Notice sent',
            'at' => !empty($wf['notice_sent_at']) ? (string)$wf['notice_sent_at'] : ($wf['initiated_date'] ?? null),
            'completed' => !empty($wf['notice_sent_at']) || !in_array($status, ['initiated'], true),
            'current' => in_array($status, ['notice_sent', 'viewed_by_tenant'], true),
        ],
        [
            'key' => 'viewed',
            'label' => 'Viewed',
            'at' => $wf['tenant_first_viewed_at'] ?? null,
            'completed' => !empty($wf['tenant_first_viewed_at']),
            'current' => $status === 'viewed_by_tenant',
        ],
        [
            'key' => 'acknowledged',
            'label' => 'Acknowledged',
            'at' => $wf['tenant_acknowledged_at'] ?? null,
            'completed' => !empty($wf['tenant_acknowledged_at']),
            'current' => $status === 'acknowledged',
        ],
        [
            'key' => 'response',
            'label' => 'Your response',
            'at' => $wf['tenant_portal_decision_at'] ?? null,
            'completed' => !empty($wf['tenant_portal_decision']),
            'current' => in_array($status, ['pending_response', 'negotiation'], true),
        ],
        [
            'key' => 'contract',
            'label' => 'Contract signing',
            'at' => null,
            'completed' => $hasSignature || in_array($status, ['signed', 'completed', 'converted'], true),
            'current' => $status === 'contract_ready',
        ],
        [
            'key' => 'complete',
            'label' => in_array($status, ['rejected'], true) ? 'Closed' : 'Completed',
            'at' => null,
            'completed' => in_array($status, ['converted', 'rejected', 'completed'], true),
            'current' => in_array($status, ['converted', 'rejected', 'completed'], true),
        ],
    ];
    return $steps;
}

/**
 * @param array<string,mixed> $item
 * @return array<string,mixed>
 */
function customer_api_tenant_format_negotiation_item(array $item): array {
    $kind = (string)($item['kind'] ?? '');
    if ($kind === 'msg') {
        $r = $item['row'] ?? [];
        if (!is_array($r)) {
            $r = [];
        }
        return [
            'kind' => 'message',
            'author_role' => (string)($r['author_role'] ?? ''),
            'body' => (string)($r['body'] ?? ''),
            'created_at' => $r['created_at'] !== null ? (string)$r['created_at'] : null,
        ];
    }
    return [
        'kind' => $kind === 'legacy_admin' ? 'legacy_admin' : 'legacy_tenant',
        'author_role' => $kind === 'legacy_admin' ? 'admin' : 'tenant',
        'body' => (string)($item['body'] ?? ''),
        'created_at' => isset($item['at']) && $item['at'] !== null ? (string)$item['at'] : null,
    ];
}

/**
 * @param array<string,mixed> $wf
 * @return array<string,mixed>
 */
function customer_api_tenant_renewal_capabilities(
    PDO $conn,
    array $wf,
    int $leaseId,
    bool $hasSignature
): array {
    $status = (string)($wf['status'] ?? '');
    $portalDec = (string)($wf['tenant_portal_decision'] ?? '');
    $ackChk = renewal_wf_can_tenant_acknowledge($status, $wf['tenant_acknowledged_at'] ?? null);
    $decChk = renewal_wf_can_tenant_decide($status, $portalDec !== '' ? $portalDec : null);
    $noticePath = trim((string)($wf['renewal_notice_pdf_path'] ?? ''));
    $hasNotice = $noticePath !== ''
        && function_exists('customer_api_tenant_document_resolve_relative_path')
        && customer_api_tenant_document_resolve_relative_path($noticePath) !== null;

    $hasContract = false;
    $draftStatus = null;
    if (!empty($wf['new_lease_id'])) {
        $dl = $conn->prepare('SELECT status, generated_contract_path FROM re_leases WHERE id = ? LIMIT 1');
        $dl->execute([(int)$wf['new_lease_id']]);
        $draft = $dl->fetch(PDO::FETCH_ASSOC);
        if ($draft) {
            $draftStatus = (string)($draft['status'] ?? '');
            $rel = trim((string)($draft['generated_contract_path'] ?? ''));
            $hasContract = $rel !== ''
                && function_exists('customer_api_tenant_document_resolve_relative_path')
                && customer_api_tenant_document_resolve_relative_path($rel) !== null;
        }
    }

    return [
        'can_acknowledge' => ($ackChk['ok'] ?? false) === true,
        'acknowledge_blocked_reason' => ($ackChk['ok'] ?? false) ? null : ($ackChk['reason'] ?? null),
        'can_decide' => ($decChk['ok'] ?? false) === true,
        'decide_blocked_reason' => ($decChk['ok'] ?? false) ? null : ($decChk['reason'] ?? null),
        'can_upload' => renewal_wf_can_tenant_upload($status),
        'can_post_negotiation_message' => renewal_wf_can_tenant_post_negotiation_message($status, $portalDec),
        'can_finalize_negotiation' => renewal_wf_can_tenant_finalize_negotiation($status, $portalDec),
        'can_electronic_sign' => renewal_wf_can_tenant_electronic_sign($status, $hasSignature)
            && $draftStatus === 'draft',
        'has_notice_pdf' => $hasNotice,
        'has_contract_pdf' => $hasContract,
        'is_frozen' => renewal_wf_tenant_frozen($status),
    ];
}

/**
 * @param array<string,mixed> $wf
 * @return array<string,mixed>
 */
function customer_api_tenant_format_renewal_row(
    PDO $conn,
    array $wf,
    int $leaseId,
    bool $includeDetail = false
): array {
    $id = (int)($wf['id'] ?? 0);
    $status = (string)($wf['status'] ?? '');
    $sigCount = (int)($wf['signature_count'] ?? 0);
    $hasSignature = $sigCount > 0;

    $out = [
        'id' => $id,
        'status' => $status,
        'status_label' => tenant_renewal_status_label($status),
        'initiated_date' => !empty($wf['initiated_date']) ? (string)$wf['initiated_date'] : null,
        'notice_sent_at' => !empty($wf['notice_sent_at']) ? (string)$wf['notice_sent_at'] : null,
        'proposed_rent' => isset($wf['proposed_rent'])
            ? number_format((float)$wf['proposed_rent'], 2, '.', '')
            : null,
        'proposed_start_date' => !empty($wf['proposed_start_date']) ? (string)$wf['proposed_start_date'] : null,
        'proposed_end_date' => !empty($wf['proposed_end_date']) ? (string)$wf['proposed_end_date'] : null,
        'tenant_acknowledged_at' => !empty($wf['tenant_acknowledged_at'])
            ? (string)$wf['tenant_acknowledged_at']
            : null,
        'tenant_portal_decision' => !empty($wf['tenant_portal_decision'])
            ? (string)$wf['tenant_portal_decision']
            : null,
        'awaiting_signature' => $status === 'contract_ready' && !$hasSignature,
        'has_notice_pdf' => trim((string)($wf['renewal_notice_pdf_path'] ?? '')) !== '',
    ];

    if ($includeDetail) {
        $hasSignatureRow = $hasSignature;
        try {
            $s = $conn->prepare('SELECT id FROM re_renewal_electronic_signatures WHERE workflow_id = ? LIMIT 1');
            $s->execute([$id]);
            $hasSignatureRow = (bool)$s->fetchColumn();
        } catch (Throwable $e) {
        }

        $uploads = [];
        try {
            $u = $conn->prepare('SELECT * FROM re_renewal_tenant_uploads WHERE workflow_id = ? ORDER BY uploaded_at DESC');
            $u->execute([$id]);
            foreach ($u->fetchAll(PDO::FETCH_ASSOC) as $up) {
                $uploads[] = [
                    'id' => (int)$up['id'],
                    'document_type' => (string)($up['document_type'] ?? ''),
                    'original_filename' => (string)($up['original_filename'] ?? ''),
                    'uploaded_at' => $up['uploaded_at'] !== null ? (string)$up['uploaded_at'] : null,
                    'download_path' => "tenant/leases/{$leaseId}/renewals/{$id}/uploads/" . (int)$up['id'] . '/download',
                ];
            }
        } catch (Throwable $e) {
        }

        $draftLease = null;
        if (!empty($wf['new_lease_id'])) {
            $dl = $conn->prepare('
                SELECT id, lease_number, status, generated_contract_path, annual_rent, start_date, end_date
                FROM re_leases WHERE id = ? LIMIT 1
            ');
            $dl->execute([(int)$wf['new_lease_id']]);
            $d = $dl->fetch(PDO::FETCH_ASSOC);
            if ($d) {
                $draftLease = [
                    'lease_id' => (int)$d['id'],
                    'lease_number' => (string)($d['lease_number'] ?? ''),
                    'status' => (string)($d['status'] ?? ''),
                    'annual_rent' => $d['annual_rent'] !== null
                        ? number_format((float)$d['annual_rent'], 2, '.', '')
                        : null,
                    'start_date' => (string)($d['start_date'] ?? ''),
                    'end_date' => (string)($d['end_date'] ?? ''),
                    'has_contract_pdf' => trim((string)($d['generated_contract_path'] ?? '')) !== '',
                ];
            }
        }

        $negotiation = [];
        try {
            $raw = renewal_negotiation_thread_for_display($conn, $id, $wf);
            foreach ($raw as $item) {
                $negotiation[] = customer_api_tenant_format_negotiation_item($item);
            }
        } catch (Throwable $e) {
        }

        $out['number_of_cheques'] = (int)($wf['number_of_cheques'] ?? 0);
        $out['chiller_charges'] = number_format((float)($wf['chiller_charges'] ?? 0), 2, '.', '');
        $out['admin_fees'] = number_format((float)($wf['admin_fees'] ?? 0), 2, '.', '');
        $out['vat_extra_charges'] = number_format((float)($wf['vat_extra_charges'] ?? 0), 2, '.', '');
        $out['tenant_portal_decision_at'] = !empty($wf['tenant_portal_decision_at'])
            ? (string)$wf['tenant_portal_decision_at']
            : null;
        $out['tenant_response'] = $wf['tenant_response'] !== null ? (string)$wf['tenant_response'] : null;
        $out['has_signature'] = $hasSignatureRow;
        $out['draft_lease'] = $draftLease;
        $out['uploads'] = $uploads;
        $out['negotiation_thread'] = $negotiation;
        $out['timeline'] = customer_api_tenant_renewal_timeline($wf, $hasSignatureRow);
        $out['capabilities'] = customer_api_tenant_renewal_capabilities($conn, $wf, $leaseId, $hasSignatureRow);
        $out['notice_download_path'] = $out['capabilities']['has_notice_pdf']
            ? "tenant/leases/{$leaseId}/renewals/{$id}/notice/download"
            : null;
        $out['contract_download_path'] = ($status === 'contract_ready'
            && is_array($draftLease)
            && ($draftLease['has_contract_pdf'] ?? false))
            ? "tenant/leases/{$leaseId}/renewals/{$id}/contract/download"
            : null;
    }

    return $out;
}

/**
 * @return array{0:array<string,mixed>,1:int,2:int}|null
 */
function customer_api_tenant_renewal_require_workflow(
    PDO $conn,
    array $ctx,
    int $leaseId,
    int $workflowId,
    bool $markOpened = false
): ?array {
    $leaseCtx = customer_api_tenant_services_lease_context($conn, $ctx, $leaseId);
    if ($leaseCtx === null) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }
    $companyId = $leaseCtx['company_id'];
    $wf = tenant_renewal_fetch_workflow($conn, $workflowId, $leaseId, $companyId);
    if (!$wf) {
        customer_api_send_error('not_found', 'Renewal not found', 404);
    }
    if (($wf['status'] ?? '') === 'initiated'
        && empty($wf['notice_sent_at'])
        && empty($wf['renewal_notice_pdf_path'])) {
        customer_api_send_error('not_found', 'Renewal not found', 404);
    }
    if ($markOpened) {
        try {
            $wf = tenant_renewal_on_first_open($conn, $wf, $leaseId, $companyId);
        } catch (Throwable $e) {
        }
    }
    return [$wf, $leaseId, $companyId];
}

function customer_api_tenant_handle_renewals_list(PDO $conn, int $leaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    if (!customer_api_tenant_services_lease_context($conn, $ctx, $leaseId)) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }
    if (!customer_api_tenant_renewals_configured($conn)) {
        customer_api_send_ok(['configured' => false, 'renewals' => []]);
    }

    $companyId = (int)($ctx['company_id'] ?? 0);
    $items = [];
    try {
        $q = $conn->prepare("
            SELECT rw.id, rw.status, rw.initiated_date, rw.notice_sent_at,
                   rw.proposed_rent, rw.proposed_start_date, rw.proposed_end_date,
                   rw.renewal_notice_pdf_path, rw.tenant_acknowledged_at, rw.tenant_portal_decision,
                   (SELECT COUNT(*) FROM re_renewal_electronic_signatures es WHERE es.workflow_id = rw.id) AS signature_count
            FROM re_lease_renewal_workflows rw
            INNER JOIN re_leases l ON l.id = rw.lease_id AND l.company_id = ?
            WHERE (rw.lease_id = ? OR rw.new_lease_id = ?)
              AND rw.status <> 'initiated'
              AND (
                rw.notice_sent_at IS NOT NULL
                OR rw.renewal_notice_pdf_path IS NOT NULL
                OR rw.status IN ('viewed_by_tenant','acknowledged','pending_response','negotiation','accepted','rejected','approved','contract_ready','signed','completed','converted')
              )
            ORDER BY rw.id DESC
        ");
        $q->execute([$companyId, $leaseId, $leaseId]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = customer_api_tenant_format_renewal_row($conn, $row, $leaseId, false);
        }
    } catch (Throwable $e) {
        customer_api_send_ok(['configured' => false, 'renewals' => [], 'message' => 'Renewals are not available on this system yet.']);
    }

    customer_api_send_ok(['configured' => true, 'renewals' => $items]);
}

function customer_api_tenant_handle_renewal_detail(PDO $conn, int $leaseId, int $workflowId): void {
    $ctx = customer_api_tenant_require_access($conn);
    $pack = customer_api_tenant_renewal_require_workflow($conn, $ctx, $leaseId, $workflowId, true);
    [$wf, $leaseId, $companyId] = $pack;

    $currentLease = customer_api_tenant_renewal_load_lease_summary($conn, $leaseId);
    $renewal = customer_api_tenant_format_renewal_row($conn, $wf, $leaseId, true);

    $leaseRow = customer_api_tenant_load_lease_detail($conn, $leaseId);
    $expectedSigner = '';
    if ($leaseRow) {
        $expectedSigner = tenant_renewal_expected_signer_display_name(
            (string)($leaseRow['first_name'] ?? ''),
            (string)($leaseRow['last_name'] ?? '')
        );
    }

    customer_api_send_ok([
        'renewal' => $renewal,
        'current_lease' => $currentLease,
        'expected_signer_name' => $expectedSigner,
        'upload_document_types' => [
            ['value' => 'passport', 'label' => 'Passport copy'],
            ['value' => 'visa', 'label' => 'Visa page'],
            ['value' => 'trade_license', 'label' => 'Trade license'],
            ['value' => 'poa', 'label' => 'Power of attorney'],
            ['value' => 'other', 'label' => 'Other'],
        ],
    ]);
}

function customer_api_tenant_renewal_stream_notice(PDO $conn, int $leaseId, int $workflowId): void {
    $ctx = customer_api_tenant_require_access($conn);
    $pack = customer_api_tenant_renewal_require_workflow($conn, $ctx, $leaseId, $workflowId, false);
    [$wf] = $pack;
    $rel = trim((string)($wf['renewal_notice_pdf_path'] ?? ''));
    if ($rel === '' || !function_exists('customer_api_tenant_document_resolve_relative_path')) {
        customer_api_send_error('not_found', 'Notice PDF not found', 404);
    }
    $resolved = customer_api_tenant_document_resolve_relative_path($rel);
    if ($resolved === null) {
        customer_api_send_error('not_found', 'File not found', 404);
    }
    customer_api_tenant_document_stream_file($resolved[0], 'renewal_notice_' . $workflowId . '.pdf', 'application/pdf', true);
}

function customer_api_tenant_renewal_stream_contract(PDO $conn, int $leaseId, int $workflowId): void {
    $ctx = customer_api_tenant_require_access($conn);
    $pack = customer_api_tenant_renewal_require_workflow($conn, $ctx, $leaseId, $workflowId, false);
    [$wf] = $pack;
    $nlid = (int)($wf['new_lease_id'] ?? 0);
    if ($nlid <= 0) {
        customer_api_send_error('not_found', 'Draft lease not found', 404);
    }
    $st = $conn->prepare('SELECT generated_contract_path FROM re_leases WHERE id = ? LIMIT 1');
    $st->execute([$nlid]);
    $rel = trim((string)($st->fetchColumn() ?: ''));
    if ($rel === '') {
        customer_api_send_error('not_found', 'Contract PDF not found', 404);
    }
    $resolved = customer_api_tenant_document_resolve_relative_path($rel);
    if ($resolved === null) {
        customer_api_send_error('not_found', 'File not found', 404);
    }
    customer_api_tenant_document_stream_file($resolved[0], 'renewal_contract_' . $workflowId . '.pdf', 'application/pdf', true);
}

function customer_api_tenant_renewal_stream_upload(PDO $conn, int $leaseId, int $workflowId, int $uploadId): void {
    $ctx = customer_api_tenant_require_access($conn);
    $pack = customer_api_tenant_renewal_require_workflow($conn, $ctx, $leaseId, $workflowId, false);
    [, , $companyId] = $pack;
    $st = $conn->prepare('
        SELECT stored_path, original_filename, mime_type
        FROM re_renewal_tenant_uploads
        WHERE id = ? AND workflow_id = ? AND company_id = ?
        LIMIT 1
    ');
    $st->execute([$uploadId, $workflowId, $companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['stored_path'])) {
        customer_api_send_error('not_found', 'Upload not found', 404);
    }
    $resolved = customer_api_tenant_document_resolve_relative_path((string)$row['stored_path']);
    if ($resolved === null) {
        customer_api_send_error('not_found', 'File not found', 404);
    }
    $name = (string)(($row['original_filename'] ?? '') ?: 'document');
    customer_api_tenant_document_stream_file(
        $resolved[0],
        $name,
        !empty($row['mime_type']) ? (string)$row['mime_type'] : null,
        true
    );
}

function customer_api_tenant_handle_renewal_acknowledge(PDO $conn, int $leaseId, int $workflowId): void {
    $ctx = customer_api_tenant_require_access($conn);
    $pack = customer_api_tenant_renewal_require_workflow($conn, $ctx, $leaseId, $workflowId, false);
    [$wf, $leaseId, $companyId] = $pack;
    $actor = customer_api_tenant_renewal_actor_ids($ctx);

    $ackChk = renewal_wf_can_tenant_acknowledge((string)($wf['status'] ?? ''), $wf['tenant_acknowledged_at'] ?? null);
    if (($ackChk['reason'] ?? '') === 'already_acknowledged') {
        customer_api_send_ok(['renewal' => customer_api_tenant_format_renewal_row($conn, $wf, $leaseId, true), 'message' => 'Already acknowledged.']);
    }
    if (!($ackChk['ok'] ?? false)) {
        customer_api_send_error('validation_error', (string)($ackChk['reason'] ?? 'Acknowledgement is not available.'), 422);
    }

    $ackStmt = $conn->prepare("
        UPDATE re_lease_renewal_workflows
        SET tenant_acknowledged_at = NOW(),
            tenant_acknowledged_ip = ?,
            tenant_acknowledged_by_tpu_id = ?,
            status = 'acknowledged'
        WHERE id = ? AND (lease_id = ? OR new_lease_id = ?)
          AND tenant_acknowledged_at IS NULL
          AND status IN ('notice_sent','viewed_by_tenant','pending_response','negotiation')
    ");
    $ackStmt->execute([
        tenant_renewal_client_ip(),
        $actor['tpu_id'] ?: null,
        $workflowId,
        $leaseId,
        $leaseId,
    ]);
    if ($ackStmt->rowCount() > 0) {
        tenant_renewal_log_event($conn, $workflowId, $companyId, 'acknowledged', 'Tenant acknowledged via mobile app.');
    }
    $wf = tenant_renewal_fetch_workflow($conn, $workflowId, $leaseId, $companyId) ?: $wf;
    customer_api_send_ok([
        'renewal' => customer_api_tenant_format_renewal_row($conn, $wf, $leaseId, true),
        'message' => 'Thank you — your receipt has been recorded.',
    ]);
}

function customer_api_tenant_handle_renewal_decision(PDO $conn, int $leaseId, int $workflowId): void {
    $ctx = customer_api_tenant_require_access($conn);
    $pack = customer_api_tenant_renewal_require_workflow($conn, $ctx, $leaseId, $workflowId, false);
    [$wf, $leaseId, $companyId] = $pack;
    $actor = customer_api_tenant_renewal_actor_ids($ctx);
    $body = customer_api_read_json_body();

    $existingDec = trim((string)($wf['tenant_portal_decision'] ?? ''));
    $decChk = renewal_wf_can_tenant_decide((string)($wf['status'] ?? ''), $existingDec !== '' ? $existingDec : null);
    if (($decChk['reason'] ?? '') === 'already_decided') {
        customer_api_send_ok(['renewal' => customer_api_tenant_format_renewal_row($conn, $wf, $leaseId, true), 'message' => 'Your response was already submitted.']);
    }
    if (!($decChk['ok'] ?? false)) {
        customer_api_send_error('validation_error', (string)($decChk['reason'] ?? 'You cannot submit this response now.'), 422);
    }

    $dec = trim((string)($body['decision'] ?? ''));
    if (!in_array($dec, ['accept', 'negotiate', 'reject'], true)) {
        customer_api_send_error('validation_error', 'decision must be accept, negotiate, or reject', 400);
    }
    $msg = trim((string)($body['message'] ?? ''));
    if ($dec === 'negotiate' && $msg === '') {
        customer_api_send_error('validation_error', 'message is required when negotiating', 400);
    }

    $newStatus = match ($dec) {
        'accept' => 'accepted',
        'negotiate' => 'negotiation',
        'reject' => 'rejected',
        default => 'pending_response',
    };
    $tenantResp = $msg !== '' ? $msg : ($dec === 'accept' ? 'Tenant accepted the renewal proposal via mobile app.' : '');

    $decStmt = $conn->prepare("
        UPDATE re_lease_renewal_workflows
        SET tenant_portal_decision = ?,
            tenant_portal_decision_at = NOW(),
            tenant_portal_decision_ip = ?,
            tenant_portal_decision_by_tpu_id = ?,
            tenant_response = ?,
            tenant_response_date = CURDATE(),
            negotiation_notes = COALESCE(?, negotiation_notes),
            status = ?
        WHERE id = ? AND (lease_id = ? OR new_lease_id = ?)
          AND (tenant_portal_decision IS NULL OR tenant_portal_decision = '')
          AND status IN ('notice_sent','viewed_by_tenant','acknowledged','pending_response','negotiation')
    ");
    $decStmt->execute([
        $dec,
        tenant_renewal_client_ip(),
        $actor['tpu_id'] ?: null,
        $tenantResp,
        $dec === 'negotiate' ? $msg : null,
        $newStatus,
        $workflowId,
        $leaseId,
        $leaseId,
    ]);
    if ($decStmt->rowCount() === 0) {
        customer_api_send_error('validation_error', 'Your response could not be saved. Please refresh.', 422);
    }

    tenant_renewal_log_event($conn, $workflowId, $companyId, 'decision_' . $dec, $msg !== '' ? $msg : 'Decision: ' . $dec);
    if ($dec === 'negotiate' && $msg !== '') {
        try {
            renewal_negotiation_insert_tenant_message(
                $conn,
                $workflowId,
                $companyId,
                $msg,
                $actor['tpu_id'],
                $actor['legacy_user_id'],
                tenant_renewal_client_ip() ?: null
            );
        } catch (Throwable $e) {
            error_log('renewal negotiation seed: ' . $e->getMessage());
        }
    }

    $wf = tenant_renewal_fetch_workflow($conn, $workflowId, $leaseId, $companyId) ?: $wf;
    customer_api_send_ok([
        'renewal' => customer_api_tenant_format_renewal_row($conn, $wf, $leaseId, true),
        'message' => 'Your response has been sent to management.',
    ]);
}

function customer_api_tenant_handle_renewal_negotiation_message(PDO $conn, int $leaseId, int $workflowId): void {
    $ctx = customer_api_tenant_require_access($conn);
    $pack = customer_api_tenant_renewal_require_workflow($conn, $ctx, $leaseId, $workflowId, false);
    [$wf, $leaseId, $companyId] = $pack;
    $actor = customer_api_tenant_renewal_actor_ids($ctx);
    $body = customer_api_read_json_body();
    $text = trim((string)($body['message'] ?? $body['negotiation_body'] ?? ''));

    if (strlen($text) < 2) {
        customer_api_send_error('validation_error', 'message must be at least 2 characters', 400);
    }
    if (strlen($text) > 8000) {
        customer_api_send_error('validation_error', 'message is too long', 400);
    }

    $stN = (string)($wf['status'] ?? '');
    $decN = (string)($wf['tenant_portal_decision'] ?? '');
    if (!renewal_wf_can_tenant_post_negotiation_message($stN, $decN)) {
        customer_api_send_error('validation_error', 'You cannot send a message at this stage.', 422);
    }

    renewal_negotiation_insert_tenant_message(
        $conn,
        $workflowId,
        $companyId,
        $text,
        $actor['tpu_id'],
        $actor['legacy_user_id'],
        tenant_renewal_client_ip() ?: null
    );
    tenant_renewal_log_event($conn, $workflowId, $companyId, 'negotiation_message_tenant', $text);
    $wf = tenant_renewal_fetch_workflow($conn, $workflowId, $leaseId, $companyId) ?: $wf;
    customer_api_send_ok([
        'renewal' => customer_api_tenant_format_renewal_row($conn, $wf, $leaseId, true),
        'message' => 'Your message was sent to management.',
    ]);
}

function customer_api_tenant_handle_renewal_negotiation_finalize(PDO $conn, int $leaseId, int $workflowId): void {
    $ctx = customer_api_tenant_require_access($conn);
    $pack = customer_api_tenant_renewal_require_workflow($conn, $ctx, $leaseId, $workflowId, false);
    [$wf, $leaseId, $companyId] = $pack;
    $actor = customer_api_tenant_renewal_actor_ids($ctx);
    $body = customer_api_read_json_body();
    $fin = trim((string)($body['finalize'] ?? ''));

    if (!in_array($fin, ['accept', 'reject'], true)) {
        customer_api_send_error('validation_error', 'finalize must be accept or reject', 400);
    }

    $stF = (string)($wf['status'] ?? '');
    $decF = (string)($wf['tenant_portal_decision'] ?? '');
    if (!renewal_wf_can_tenant_finalize_negotiation($stF, $decF)) {
        customer_api_send_error('validation_error', 'You cannot finalize from negotiation at this stage.', 422);
    }

    if ($fin === 'accept') {
        $newDec = 'accept';
        $newSt = 'accepted';
        $note = 'Tenant accepted after negotiation (mobile app).';
    } else {
        $newDec = 'reject';
        $newSt = 'rejected';
        $note = 'Tenant rejected after negotiation (mobile app).';
    }

    $finStmt = $conn->prepare("
        UPDATE re_lease_renewal_workflows
        SET tenant_portal_decision = ?,
            tenant_portal_decision_at = NOW(),
            tenant_portal_decision_ip = ?,
            tenant_portal_decision_by_tpu_id = ?,
            status = ?
        WHERE id = ? AND (lease_id = ? OR new_lease_id = ?)
          AND tenant_portal_decision = 'negotiate'
          AND status = 'negotiation'
    ");
    $finStmt->execute([
        $newDec,
        tenant_renewal_client_ip(),
        $actor['tpu_id'] ?: null,
        $newSt,
        $workflowId,
        $leaseId,
        $leaseId,
    ]);
    if ($finStmt->rowCount() === 0) {
        customer_api_send_error('validation_error', 'Could not update your decision. Please refresh.', 422);
    }
    tenant_renewal_log_event($conn, $workflowId, $companyId, 'negotiation_finalize_' . $fin, $note);
    $wf = tenant_renewal_fetch_workflow($conn, $workflowId, $leaseId, $companyId) ?: $wf;
    customer_api_send_ok([
        'renewal' => customer_api_tenant_format_renewal_row($conn, $wf, $leaseId, true),
        'message' => $fin === 'accept'
            ? 'You have accepted the renewal. Management will proceed with the next steps.'
            : 'You have declined this renewal.',
    ]);
}

function customer_api_tenant_handle_renewal_upload(PDO $conn, int $leaseId, int $workflowId): void {
    $ctx = customer_api_tenant_require_access($conn);
    $pack = customer_api_tenant_renewal_require_workflow($conn, $ctx, $leaseId, $workflowId, false);
    [$wf, $leaseId, $companyId] = $pack;
    $actor = customer_api_tenant_renewal_actor_ids($ctx);

    if (!renewal_wf_can_tenant_upload((string)($wf['status'] ?? ''))) {
        customer_api_send_error('validation_error', 'Uploads are closed for this renewal.', 422);
    }

    $docType = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_POST['document_type'] ?? 'other')));
    $allowedTypes = ['passport', 'visa', 'trade_license', 'poa', 'other'];
    if (!in_array($docType, $allowedTypes, true)) {
        $docType = 'other';
    }

    if (empty($_FILES['file']['tmp_name']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        customer_api_send_error('validation_error', 'file is required', 400);
    }
    if (($_FILES['file']['size'] ?? 0) > TENANT_RENEWAL_UPLOAD_MAX_BYTES) {
        customer_api_send_error('validation_error', 'File is too large (max 8 MB)', 400);
    }

    $orig = (string)($_FILES['file']['name'] ?? 'document');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $mime = tenant_renewal_allowed_mime($ext);
    if ($mime === null) {
        customer_api_send_error('validation_error', 'Allowed types: PDF, PNG, JPG, WEBP', 400);
    }

    $basePath = dirname(__DIR__, 3);
    $dir = $basePath . '/uploads/renewal_tenant_docs/' . $companyId . '/' . $workflowId;
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        error_log('renewal upload: failed to create dir ' . $dir);
        customer_api_send_error('upload_failed', 'Could not save file', 500);
    }
    if (!is_writable($dir)) {
        error_log('renewal upload: dir not writable ' . $dir);
        customer_api_send_error('upload_failed', 'Could not save file', 500);
    }
    $safe = 'doc_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $abs = $dir . '/' . $safe;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $abs)) {
        error_log('renewal upload: move_uploaded_file failed to ' . $abs);
        customer_api_send_error('upload_failed', 'Could not save file', 500);
    }
    $rel = 'uploads/renewal_tenant_docs/' . $companyId . '/' . $workflowId . '/' . $safe;
    $conn->prepare("
        INSERT INTO re_renewal_tenant_uploads
        (workflow_id, company_id, tenant_portal_user_id, legacy_user_id, document_type, original_filename, stored_path, mime_type, size_bytes, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $workflowId,
        $companyId,
        $actor['tpu_id'] ?: null,
        $actor['legacy_user_id'] ?: null,
        $docType,
        substr($orig, 0, 240),
        $rel,
        $mime,
        (int)($_FILES['file']['size'] ?? 0),
        tenant_renewal_client_ip() ?: null,
    ]);
    tenant_renewal_log_event($conn, $workflowId, $companyId, 'document_uploaded', $docType . ': ' . $orig);
    $wf = tenant_renewal_fetch_workflow($conn, $workflowId, $leaseId, $companyId) ?: $wf;
    customer_api_send_ok([
        'renewal' => customer_api_tenant_format_renewal_row($conn, $wf, $leaseId, true),
        'message' => 'Document uploaded.',
    ]);
}

/**
 * Decode a drawn signature payload (data URL or raw base64 PNG) into raw bytes.
 * Returns null when the payload is missing or not a valid small PNG image.
 */
function customer_api_tenant_renewal_decode_signature(string $payload): ?string {
    $payload = trim($payload);
    if ($payload === '') {
        return null;
    }
    if (stripos($payload, 'data:') === 0) {
        $comma = strpos($payload, ',');
        if ($comma === false) {
            return null;
        }
        $meta = substr($payload, 0, $comma);
        if (stripos($meta, 'image/png') === false) {
            return null;
        }
        $payload = substr($payload, $comma + 1);
    }
    $payload = str_replace(' ', '+', $payload);
    $bytes = base64_decode($payload, true);
    if ($bytes === false || strlen($bytes) < 64) {
        return null;
    }
    // Cap at ~2 MB and verify PNG magic header.
    if (strlen($bytes) > 2 * 1024 * 1024) {
        return null;
    }
    if (substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        return null;
    }
    return $bytes;
}

/**
 * Save signature bytes under uploads/renewal_signatures and return the relative path.
 */
function customer_api_tenant_renewal_store_signature(string $bytes, int $companyId, int $workflowId): ?string {
    $basePath = dirname(__DIR__, 3);
    $dir = $basePath . '/uploads/renewal_signatures/' . $companyId . '/' . $workflowId;
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        error_log('renewal sign: failed to create dir ' . $dir);
        return null;
    }
    if (!is_writable($dir)) {
        error_log('renewal sign: dir not writable ' . $dir);
        return null;
    }
    $safe = 'sig_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.png';
    $abs = $dir . '/' . $safe;
    if (file_put_contents($abs, $bytes) === false) {
        error_log('renewal sign: failed to write ' . $abs);
        return null;
    }
    return 'uploads/renewal_signatures/' . $companyId . '/' . $workflowId . '/' . $safe;
}

function customer_api_tenant_handle_renewal_electronic_sign(PDO $conn, int $leaseId, int $workflowId): void {
    $ctx = customer_api_tenant_require_access($conn);
    $pack = customer_api_tenant_renewal_require_workflow($conn, $ctx, $leaseId, $workflowId, false);
    [$wf, $leaseId, $companyId] = $pack;
    $actor = customer_api_tenant_renewal_actor_ids($ctx);
    $body = customer_api_read_json_body();

    $chk = $conn->prepare('SELECT COUNT(*) FROM re_renewal_electronic_signatures WHERE workflow_id = ?');
    $chk->execute([$workflowId]);
    $hasSig = (int)$chk->fetchColumn() > 0;
    if (!renewal_wf_can_tenant_electronic_sign((string)($wf['status'] ?? ''), $hasSig)) {
        customer_api_send_error(
            'validation_error',
            $hasSig ? 'You have already signed this renewal.' : 'Contract is not ready for signature yet.',
            422
        );
    }

    $nlid = (int)($wf['new_lease_id'] ?? 0);
    if ($nlid <= 0) {
        customer_api_send_error('validation_error', 'No draft lease linked.', 422);
    }

    $typed = trim((string)($body['typed_full_name'] ?? ''));
    $accept = !empty($body['accept_terms']);
    if (strlen($typed) < 3) {
        customer_api_send_error('validation_error', 'typed_full_name is required', 400);
    }
    if (!$accept) {
        customer_api_send_error('validation_error', 'accept_terms must be true', 400);
    }

    // Drawn signature (PNG, base64 or data URL). Required for the mobile flow.
    $sigBytes = customer_api_tenant_renewal_decode_signature((string)($body['signature_image'] ?? ''));
    if ($sigBytes === null) {
        customer_api_send_error('validation_error', 'A drawn signature is required.', 400);
    }

    $leaseRow = customer_api_tenant_load_lease_detail($conn, $leaseId);
    $leaseFn = (string)($leaseRow['first_name'] ?? '');
    $leaseLn = (string)($leaseRow['last_name'] ?? '');
    if ($leaseRow) {
        $tStmt = $conn->prepare('SELECT first_name, last_name FROM re_tenants WHERE id = ? LIMIT 1');
        $tStmt->execute([(int)($leaseRow['tenant_id'] ?? 0)]);
        $t = $tStmt->fetch(PDO::FETCH_ASSOC);
        if ($t) {
            $leaseFn = (string)($t['first_name'] ?? '');
            $leaseLn = (string)($t['last_name'] ?? '');
        }
    }
    if (!tenant_renewal_typed_name_matches_lease_tenant($typed, $leaseFn, $leaseLn)) {
        $mustMatch = tenant_renewal_expected_signer_display_name($leaseFn, $leaseLn);
        customer_api_send_error(
            'validation_error',
            'The name you typed does not match our records.'
                . ($mustMatch !== '' ? ' Expected: ' . $mustMatch : ''),
            400
        );
    }

    // Persist the drawn signature image before recording the signature so it can
    // be embedded into the contract PDF (via the draft lease tenant_signature_path).
    $sigRel = customer_api_tenant_renewal_store_signature($sigBytes, $companyId, $workflowId);
    if ($sigRel === null) {
        customer_api_send_error('upload_failed', 'Could not save your signature. Please try again.', 500);
    }

    $conn->beginTransaction();
    try {
        $conn->prepare("
            INSERT INTO re_renewal_electronic_signatures
            (workflow_id, company_id, new_lease_id, typed_full_name, signature_image_path, terms_accepted, tenant_portal_user_id, legacy_user_id, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?)
        ")->execute([
            $workflowId,
            $companyId,
            $nlid,
            $typed,
            $sigRel,
            $actor['tpu_id'] ?: null,
            $actor['legacy_user_id'] ?: null,
            tenant_renewal_client_ip() ?: null,
            tenant_renewal_user_agent(),
        ]);
    } catch (Throwable $insEx) {
        $conn->rollBack();
        if (strpos($insEx->getMessage(), 'Duplicate') !== false || strpos($insEx->getMessage(), '1062') !== false) {
            customer_api_send_error('validation_error', 'You have already signed this renewal.', 422);
        }
        throw $insEx;
    }

    // Attach the signature to the draft lease so the existing contract PDF
    // generator embeds it whenever the contract is regenerated/downloaded.
    try {
        $conn->prepare('UPDATE re_leases SET tenant_signature_path = ? WHERE id = ? AND company_id = ?')
            ->execute([$sigRel, $nlid, $companyId]);
    } catch (Throwable $e) {
        error_log('renewal sign: failed to set tenant_signature_path on lease ' . $nlid . ': ' . $e->getMessage());
    }

    $updSign = $conn->prepare("
        UPDATE re_lease_renewal_workflows
        SET status = 'signed'
        WHERE id = ? AND (lease_id = ? OR new_lease_id = ?) AND status = 'contract_ready'
    ");
    $updSign->execute([$workflowId, $leaseId, $leaseId]);
    if ($updSign->rowCount() === 0) {
        $conn->rollBack();
        customer_api_send_error('validation_error', 'Signature could not be finalized. Please refresh.', 422);
    }
    $conn->commit();
    tenant_renewal_log_event($conn, $workflowId, $companyId, 'electronic_sign', 'Typed name: ' . $typed . ' (drawn signature captured)');

    // Best-effort: regenerate the draft contract PDF so the drawn signature is
    // embedded (the generator reads re_leases.tenant_signature_path). Never let a
    // generation failure undo a recorded signature — admin can still regenerate
    // or download the saved signature image manually.
    try {
        $generatorFile = dirname(__DIR__, 3) . '/modules/realestate/includes/contract_pdf_generator.php';
        if (is_file($generatorFile)) {
            require_once $generatorFile;
            if (class_exists('ContractPDFGenerator')) {
                (new ContractPDFGenerator($conn, $companyId))->generateFromLease($nlid);
            }
        }
    } catch (Throwable $genEx) {
        error_log('renewal sign: contract PDF regeneration failed for lease ' . $nlid . ': ' . $genEx->getMessage());
    }

    $wf = tenant_renewal_fetch_workflow($conn, $workflowId, $leaseId, $companyId) ?: $wf;
    customer_api_send_ok([
        'renewal' => customer_api_tenant_format_renewal_row($conn, $wf, $leaseId, true),
        'message' => 'Your electronic signature has been recorded.',
    ]);
}
