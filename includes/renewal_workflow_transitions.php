<?php
/**
 * Lease renewal workflow — allowed status transitions (portal + admin).
 * Keep in sync with re_lease_renewal_workflows.status ENUM.
 */

if (!function_exists('renewal_wf_terminal_statuses')) {
    function renewal_wf_terminal_statuses(): array {
        return ['converted', 'rejected'];
    }
}

if (!function_exists('renewal_wf_is_terminal')) {
    function renewal_wf_is_terminal(string $status): bool {
        return in_array($status, renewal_wf_terminal_statuses(), true);
    }
}

if (!function_exists('renewal_wf_is_completed_like')) {
    /** No further business changes (includes completed lifecycle). */
    function renewal_wf_is_completed_like(string $status): bool {
        return renewal_wf_is_terminal($status) || $status === 'completed';
    }
}

if (!function_exists('renewal_wf_tenant_frozen')) {
    /** Tenant cannot change decision, ack again, upload, or sign again. */
    function renewal_wf_tenant_frozen(string $status): bool {
        return in_array($status, ['signed', 'completed', 'converted', 'rejected'], true);
    }
}

if (!function_exists('renewal_wf_send_notice_target_status')) {
    /**
     * After sending email: initiated → notice_sent; otherwise keep status (resend).
     */
    function renewal_wf_send_notice_target_status(string $current): ?string {
        if (renewal_wf_is_terminal($current) || $current === 'completed') {
            return null;
        }
        return $current === 'initiated' ? 'notice_sent' : null;
    }
}

if (!function_exists('renewal_wf_can_send_notice')) {
    function renewal_wf_can_send_notice(string $current): bool {
        if (renewal_wf_is_terminal($current) || $current === 'completed') {
            return false;
        }
        return true;
    }
}

if (!function_exists('renewal_wf_can_mark_contract_ready')) {
    function renewal_wf_can_mark_contract_ready(string $current): bool {
        if (renewal_wf_is_completed_like($current)) {
            return false;
        }
        if ($current === 'contract_ready') {
            return false;
        }
        return in_array($current, ['accepted', 'negotiation', 'approved', 'pending_response'], true);
    }
}

if (!function_exists('renewal_wf_can_approve_convert')) {
    /**
     * Convert leases: after e-sign, legacy approved, or tenant accepted (offline path).
     */
    function renewal_wf_can_approve_convert(string $current): bool {
        if (renewal_wf_is_terminal($current) || $current === 'completed') {
            return false;
        }
        return in_array($current, ['signed', 'approved', 'accepted'], true);
    }
}

if (!function_exists('renewal_wf_can_admin_reject')) {
    function renewal_wf_can_admin_reject(string $current): bool {
        if (renewal_wf_is_terminal($current)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('renewal_wf_can_save_renewal_amounts')) {
    function renewal_wf_can_save_renewal_amounts(string $current): bool {
        if (renewal_wf_is_terminal($current) || $current === 'completed') {
            return false;
        }
        return !in_array($current, ['signed'], true);
    }
}

if (!function_exists('renewal_wf_can_save_notice_content')) {
    function renewal_wf_can_save_notice_content(string $current): bool {
        return !renewal_wf_is_terminal($current) && $current !== 'completed';
    }
}

if (!function_exists('renewal_wf_can_tenant_acknowledge')) {
    /**
     * @return array{ok:bool, reason:?string}
     */
    function renewal_wf_can_tenant_acknowledge(string $status, ?string $alreadyAckAt): array {
        if (renewal_wf_tenant_frozen($status)) {
            return ['ok' => false, 'reason' => 'This renewal is closed.'];
        }
        if (!empty($alreadyAckAt)) {
            return ['ok' => false, 'reason' => 'already_acknowledged'];
        }
        if (!in_array($status, ['notice_sent', 'viewed_by_tenant', 'pending_response', 'negotiation'], true)) {
            return ['ok' => false, 'reason' => 'Acknowledgement is not available at this stage.'];
        }
        return ['ok' => true, 'reason' => null];
    }
}

if (!function_exists('renewal_wf_can_tenant_decide')) {
    /**
     * @return array{ok:bool, reason:?string}
     */
    function renewal_wf_can_tenant_decide(string $status, ?string $existingDecision): array {
        if ($existingDecision !== null && $existingDecision !== '') {
            return ['ok' => false, 'reason' => 'already_decided'];
        }
        if (renewal_wf_tenant_frozen($status)) {
            return ['ok' => false, 'reason' => 'You cannot change your response for this renewal anymore.'];
        }
        if (in_array($status, ['contract_ready', 'approved'], true)) {
            return ['ok' => false, 'reason' => 'Response is locked at this stage. Contact management if you need to make a change.'];
        }
        if (!in_array($status, [
            'notice_sent', 'viewed_by_tenant', 'acknowledged', 'pending_response', 'negotiation',
        ], true)) {
            return ['ok' => false, 'reason' => 'A response is not available at this stage.'];
        }
        return ['ok' => true, 'reason' => null];
    }
}

if (!function_exists('renewal_wf_can_tenant_upload')) {
    function renewal_wf_can_tenant_upload(string $status): bool {
        if (renewal_wf_tenant_frozen($status)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('renewal_wf_can_tenant_electronic_sign')) {
    function renewal_wf_can_tenant_electronic_sign(string $status, bool $hasSignatureRow): bool {
        if ($hasSignatureRow) {
            return false;
        }
        return $status === 'contract_ready';
    }
}

if (!function_exists('renewal_wf_can_admin_post_negotiation_reply')) {
    /** Staff can reply while renewal is in active negotiation (not closed / not past signing). */
    function renewal_wf_can_admin_post_negotiation_reply(string $status): bool {
        if (renewal_wf_is_completed_like($status)) {
            return false;
        }
        if (in_array($status, ['signed', 'contract_ready', 'accepted'], true)) {
            return false;
        }
        return in_array($status, ['negotiation', 'pending_response'], true);
    }
}

if (!function_exists('renewal_wf_can_tenant_post_negotiation_message')) {
    /** Follow-up message after tenant chose negotiate. */
    function renewal_wf_can_tenant_post_negotiation_message(string $status, string $portalDecision): bool {
        if (renewal_wf_tenant_frozen($status)) {
            return false;
        }
        return $status === 'negotiation' && $portalDecision === 'negotiate';
    }
}

if (!function_exists('renewal_wf_can_tenant_finalize_negotiation')) {
    /** Switch from negotiate to accept or reject. */
    function renewal_wf_can_tenant_finalize_negotiation(string $status, string $portalDecision): bool {
        if (renewal_wf_tenant_frozen($status)) {
            return false;
        }
        return $status === 'negotiation' && $portalDecision === 'negotiate';
    }
}

if (!function_exists('renewal_wf_admin_save_response_new_status')) {
    /**
     * Derive status change when admin saves response/notes (does not downgrade tenant-accepted flows).
     *
     * @return string|null null = leave status unchanged
     */
    function renewal_wf_admin_save_response_new_status(
        string $current,
        bool $hasTenantResponseText,
        bool $hasNegotiationNotes
    ): ?string {
        if (renewal_wf_is_completed_like($current)) {
            return null;
        }
        if (in_array($current, ['signed', 'contract_ready'], true)) {
            return null;
        }
        if (in_array($current, ['accepted'], true)) {
            return null;
        }
        if ($hasNegotiationNotes) {
            if (in_array($current, [
                'initiated', 'notice_sent', 'viewed_by_tenant', 'acknowledged',
                'pending_response', 'negotiation',
            ], true)) {
                return 'negotiation';
            }
            return null;
        }
        if ($hasTenantResponseText) {
            if (in_array($current, [
                'initiated', 'notice_sent', 'viewed_by_tenant', 'acknowledged', 'negotiation',
            ], true)) {
                return 'pending_response';
            }
        }
        return null;
    }
}

if (!function_exists('renewal_wf_log_admin_event')) {
    /**
     * Audit row in re_renewal_portal_events (staff user id in legacy_user_id; tenant_portal_user_id NULL).
     */
    function renewal_wf_log_admin_event(
        PDO $conn,
        int $workflowId,
        int $companyId,
        int $staffUserId,
        string $eventType,
        string $message,
        ?string $metaJson = null
    ): void {
        try {
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
            $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
            if (strlen($ua) > 500) {
                $ua = substr($ua, 0, 500);
            }
            $st = $conn->prepare("
                INSERT INTO re_renewal_portal_events
                (workflow_id, company_id, event_type, message, tenant_portal_user_id, legacy_user_id, ip_address, user_agent, meta_json)
                VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?)
            ");
            $st->execute([
                $workflowId,
                $companyId,
                $eventType,
                $message,
                $staffUserId ?: null,
                $ip !== '' ? $ip : null,
                $ua,
                $metaJson,
            ]);
        } catch (Throwable $e) {
            error_log('renewal_wf_log_admin_event: ' . $e->getMessage());
        }
    }
}
