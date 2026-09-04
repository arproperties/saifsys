<?php
/**
 * Renewal negotiation thread (tenant ↔ admin messages).
 * Table: re_renewal_negotiation_messages (see migrations/renewal_negotiation_thread.sql)
 */

if (!function_exists('renewal_negotiation_list_messages')) {
    function renewal_negotiation_list_messages(PDO $conn, int $workflowId): array {
        try {
            $st = $conn->prepare("
                SELECT * FROM re_renewal_negotiation_messages
                WHERE workflow_id = ?
                ORDER BY id ASC
            ");
            $st->execute([$workflowId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('renewal_negotiation_insert_tenant_message')) {
    function renewal_negotiation_insert_tenant_message(
        PDO $conn,
        int $workflowId,
        int $companyId,
        string $body,
        int $tpuId,
        int $legacyUserId,
        ?string $ip
    ): void {
        $body = trim($body);
        if ($body === '') {
            return;
        }
        $st = $conn->prepare("
            INSERT INTO re_renewal_negotiation_messages
            (workflow_id, company_id, author_role, body, tenant_portal_user_id, legacy_tenant_user_id, ip_address)
            VALUES (?, ?, 'tenant', ?, ?, ?, ?)
        ");
        $st->execute([
            $workflowId,
            $companyId,
            $body,
            $tpuId ?: null,
            $legacyUserId ?: null,
            $ip !== null && $ip !== '' ? $ip : null,
        ]);
    }
}

if (!function_exists('renewal_negotiation_insert_admin_message')) {
    function renewal_negotiation_insert_admin_message(
        PDO $conn,
        int $workflowId,
        int $companyId,
        string $body,
        int $adminUserId,
        ?string $ip
    ): void {
        $body = trim($body);
        if ($body === '') {
            return;
        }
        $st = $conn->prepare("
            INSERT INTO re_renewal_negotiation_messages
            (workflow_id, company_id, author_role, body, admin_user_id, ip_address)
            VALUES (?, ?, 'admin', ?, ?, ?)
        ");
        $st->execute([
            $workflowId,
            $companyId,
            $body,
            $adminUserId ?: null,
            $ip !== null && $ip !== '' ? $ip : null,
        ]);
    }
}

/**
 * Build ordered items for UI: legacy fields + DB rows (avoids duplicate first tenant line when seeded).
 *
 * @return list<array{kind:string, body?:string, at?:?string, row?:array}>
 */
if (!function_exists('renewal_negotiation_thread_for_display')) {
    function renewal_negotiation_thread_for_display(PDO $conn, int $workflowId, array $wf): array {
        $msgs = renewal_negotiation_list_messages($conn, $workflowId);
        $dec = (string)($wf['tenant_portal_decision'] ?? '');
        $tenantResp = trim((string)($wf['tenant_response'] ?? ''));
        $negNotes = trim((string)($wf['negotiation_notes'] ?? ''));
        $out = [];

        $firstDbTenantBody = null;
        foreach ($msgs as $m) {
            if (($m['author_role'] ?? '') === 'tenant') {
                $firstDbTenantBody = trim((string)($m['body'] ?? ''));
                break;
            }
        }

        if ($dec === 'negotiate' && $tenantResp !== '') {
            if ($firstDbTenantBody === null || $firstDbTenantBody !== $tenantResp) {
                $out[] = [
                    'kind' => 'legacy_tenant',
                    'body' => $tenantResp,
                    'at' => $wf['tenant_portal_decision_at'] ?? $wf['tenant_response_date'] ?? null,
                ];
            }
        }

        foreach ($msgs as $m) {
            $out[] = ['kind' => 'msg', 'row' => $m];
        }

        $hasAdminInDb = false;
        foreach ($msgs as $m) {
            if (($m['author_role'] ?? '') === 'admin') {
                $hasAdminInDb = true;
                break;
            }
        }
        if ($negNotes !== '' && !$hasAdminInDb) {
            $out[] = ['kind' => 'legacy_admin', 'body' => $negNotes, 'at' => null];
        }

        return $out;
    }
}
