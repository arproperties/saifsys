<?php
/**
 * Bridge domain audit events into central audit_log as owner-friendly summaries.
 * Domain tables remain the forensic system of record for money events.
 */

require_once __DIR__ . '/AuditService.php';

if (!function_exists('audit_bridge_company_name')) {
    function audit_bridge_company_name(PDO $conn, ?int $companyId): string
    {
        if (!$companyId) {
            return '';
        }
        try {
            $st = $conn->prepare('SELECT name FROM companies WHERE id = ? LIMIT 1');
            $st->execute([$companyId]);
            return (string)($st->fetchColumn() ?: '');
        } catch (Throwable $e) {
            return '';
        }
    }
}

/**
 * Emit a central summary for a Real Estate accounting domain event.
 */
if (!function_exists('audit_bridge_re_accounting')) {
    function audit_bridge_re_accounting(
        PDO $conn,
        int $companyId,
        string $action,
        string $entityType,
        $entityId,
        ?int $userId = null,
        $details = null,
        string $module = 'realestate'
    ): void {
        try {
            $companyName = audit_bridge_company_name($conn, $companyId);
            $who = 'A user';
            if ($userId) {
                $u = $conn->prepare('SELECT COALESCE(NULLIF(fullname,\'\'), username) FROM `user` WHERE id = ? LIMIT 1');
                $u->execute([$userId]);
                $who = (string)($u->fetchColumn() ?: 'A user');
            }
            $label = AuditService::actionLabel($action);
            $ref = strtoupper(str_replace('_', ' ', $entityType)) . ' #' . $entityId;
            $inCompany = $companyName !== '' ? " in {$companyName}" : '';
            $detailStr = '';
            if (is_string($details) && $details !== '') {
                $detailStr = ' — ' . mb_substr($details, 0, 200);
            } elseif (is_array($details) && !empty($details['summary'])) {
                $detailStr = ' — ' . mb_substr((string)$details['summary'], 0, 200);
            }
            $summary = "{$who} {$label} {$ref}{$inCompany}{$detailStr}";

            AuditService::logEvent([
                'action' => $action,
                'action_label' => $label,
                'module' => $module,
                'company_id' => $companyId,
                'object_type' => $entityType,
                'object_id' => (string)$entityId,
                'object_ref' => $ref,
                'summary' => $summary,
                'new_data' => is_array($details) ? $details : ($details !== null ? ['details' => $details] : null),
                'user_id' => $userId,
                'source' => 'user',
                'success' => true,
            ]);
        } catch (Throwable $e) {
            error_log('audit_bridge_re_accounting: ' . $e->getMessage());
        }
    }
}

/**
 * Bridge Construction bank reconciliation domain audit into central audit_log.
 */
if (!function_exists('audit_bridge_construction_bank_reco')) {
    function audit_bridge_construction_bank_reco(
        PDO $conn,
        int $companyId,
        string $action,
        ?int $statementLineId,
        ?int $matchId,
        ?int $userId = null,
        $details = null
    ): void {
        try {
            $objectId = $matchId ?: $statementLineId ?: 0;
            $ref = $matchId ? ('Match #' . $matchId) : ($statementLineId ? ('Line #' . $statementLineId) : 'Bank reco');
            $map = [
                'confirm_match' => 'approve',
                'undo_match' => 'reverse',
                'create_transaction' => 'journal_posted',
            ];
            $centralAction = $map[$action] ?? $action;
            audit_bridge_re_accounting(
                $conn,
                $companyId,
                $centralAction,
                'bank_reconciliation',
                $objectId,
                $userId,
                is_array($details) ? array_merge(['summary' => $action], $details) : ['summary' => $action],
                'construction'
            );
        } catch (Throwable $e) {
            error_log('audit_bridge_construction_bank_reco: ' . $e->getMessage());
        }
    }
}

/**
 * Emit a central summary for Real Estate operational events (leases, units, renewals, docs, cheques, payments).
 * Fail-safe: never throws to callers.
 */
if (!function_exists('audit_bridge_re_ops')) {
    function audit_bridge_re_ops(
        PDO $conn,
        int $companyId,
        string $action,
        string $objectType,
        $objectId,
        string $objectRef,
        string $summary,
        ?array $oldData = null,
        ?array $newData = null,
        ?int $userId = null,
        string $source = 'user'
    ): void {
        try {
            AuditService::logEvent([
                'action' => $action,
                'action_label' => AuditService::actionLabel($action),
                'module' => 'realestate',
                'company_id' => $companyId > 0 ? $companyId : null,
                'object_type' => $objectType,
                'object_id' => (string)$objectId,
                'object_ref' => $objectRef !== '' ? $objectRef : (strtoupper(str_replace('_', ' ', $objectType)) . ' #' . $objectId),
                'summary' => $summary,
                'old_data' => $oldData,
                'new_data' => $newData,
                'user_id' => $userId,
                'source' => $source,
                'success' => true,
            ]);
        } catch (Throwable $e) {
            error_log('audit_bridge_re_ops: ' . $e->getMessage());
        }
    }
}

/**
 * Fail-safe HR ops audit into central audit_log (attendance, leave, overtime, etc.).
 */
if (!function_exists('audit_bridge_hr_ops')) {
    function audit_bridge_hr_ops(
        string $action,
        string $objectType,
        $objectId,
        string $summary,
        ?int $companyId = null,
        ?array $newData = null,
        ?string $objectRef = null,
        ?int $userId = null
    ): void {
        try {
            if ($companyId === null && function_exists('current_company_id') && isset($GLOBALS['conn'])) {
                try {
                    $cid = current_company_id($GLOBALS['conn']);
                    $companyId = $cid ? (int)$cid : null;
                } catch (Throwable $e) {
                    $companyId = null;
                }
            }
            AuditService::logEvent([
                'action' => $action,
                'action_label' => AuditService::actionLabel($action),
                'module' => 'hr',
                'company_id' => $companyId,
                'object_type' => $objectType,
                'object_id' => (string)$objectId,
                'object_ref' => $objectRef !== null && $objectRef !== ''
                    ? $objectRef
                    : (ucfirst(str_replace('_', ' ', $objectType)) . ' #' . $objectId),
                'summary' => $summary,
                'new_data' => $newData,
                'user_id' => $userId,
                'source' => 'user',
                'success' => true,
            ]);
        } catch (Throwable $e) {
            error_log('audit_bridge_hr_ops: ' . $e->getMessage());
        }
    }
}

/**
 * Emit a central summary for Cleaning work-order money / status highlights.
 */
if (!function_exists('audit_bridge_cleaning_order')) {
    function audit_bridge_cleaning_order(
        string $action,
        int $orderId,
        string $summary,
        ?int $companyId = null,
        ?int $userId = null,
        string $source = 'user',
        $old = null,
        $new = null
    ): void {
        try {
            AuditService::logEvent([
                'action' => $action,
                'action_label' => AuditService::actionLabel($action),
                'module' => 'cleaning',
                'company_id' => $companyId,
                'object_type' => 'make_order',
                'object_id' => (string)$orderId,
                'object_ref' => 'Order #' . $orderId,
                'summary' => $summary,
                'old_data' => $old,
                'new_data' => $new,
                'user_id' => $userId,
                'source' => $source,
                'success' => true,
            ]);
        } catch (Throwable $e) {
            error_log('audit_bridge_cleaning_order: ' . $e->getMessage());
        }
    }
}
