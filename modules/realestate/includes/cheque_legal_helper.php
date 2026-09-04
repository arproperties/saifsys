<?php
/**
 * Cheque → Legal helpers.
 *
 * Shared by lease_view.php (raise bounce/hold/escalate) and the Legal module's
 * cheque notification inbox. Records escalations into re_legal_cheque_escalations
 * and emails the legal department.
 */
declare(strict_types=1);

if (!function_exists('cheque_legal_escalations_table_exists')) {
    function cheque_legal_escalations_table_exists(PDO $conn): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        try {
            $stmt = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_legal_cheque_escalations'");
            $exists = ((int)$stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            $exists = false;
        }
        return $exists;
    }
}

if (!function_exists('legal_department_emails')) {
    /**
     * Best-effort list of legal-department recipient emails for a company
     * (users whose role module is 'legal' or Owner/Admin). Kept for other callers;
     * bounce/escalate emails use Settings legal_escalation recipients only.
     *
     * @return list<string>
     */
    function legal_department_emails(PDO $conn, int $companyId): array
    {
        $emails = [];
        try {
            $stmt = $conn->prepare("
                SELECT DISTINCT u.email
                FROM user u
                JOIN user_companies uc ON uc.user_id = u.id
                LEFT JOIN user_roles ur ON ur.user_id = u.id
                LEFT JOIN roles r ON r.id = ur.role_id
                WHERE uc.company_id = ?
                  AND u.email IS NOT NULL AND u.email <> ''
                  AND (r.module = 'legal' OR r.name IN ('Owner','Admin'))
            ");
            $stmt->execute([$companyId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $e) {
                if ($e) {
                    $emails[] = (string)$e;
                }
            }
        } catch (Throwable $e) {
            // user/roles schema variations — ignore, rely on configured recipients.
        }
        return array_values(array_unique(array_filter($emails)));
    }
}

if (!function_exists('cheque_record_legal_escalation')) {
    /**
     * Insert a row into the legal cheque notification inbox.
     *
     * @param array $d Keys: lease_id, installment_id, cheque_id, tenant_id, type
     *                 ('bounced'|'escalated'|'hold'), cheque_number, cheque_amount,
     *                 cheque_date, reason, created_by
     * @return int|null New row id, or null if the table is missing / insert failed.
     */
    function cheque_record_legal_escalation(PDO $conn, int $companyId, array $d): ?int
    {
        if (!cheque_legal_escalations_table_exists($conn)) {
            return null;
        }
        try {
            $stmt = $conn->prepare("
                INSERT INTO re_legal_cheque_escalations
                    (company_id, lease_id, installment_id, cheque_id, tenant_id, type,
                     cheque_number, cheque_amount, cheque_date, reason, status, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', ?, NOW())
            ");
            $stmt->execute([
                $companyId,
                (int)$d['lease_id'],
                !empty($d['installment_id']) ? (int)$d['installment_id'] : null,
                !empty($d['cheque_id']) ? (int)$d['cheque_id'] : null,
                !empty($d['tenant_id']) ? (int)$d['tenant_id'] : null,
                (string)($d['type'] ?? 'escalated'),
                $d['cheque_number'] ?? null,
                isset($d['cheque_amount']) ? (float)$d['cheque_amount'] : null,
                $d['cheque_date'] ?? null,
                $d['reason'] ?? null,
                !empty($d['created_by']) ? (int)$d['created_by'] : null,
            ]);
            return (int)$conn->lastInsertId();
        } catch (Throwable $e) {
            error_log('cheque_record_legal_escalation failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('cheque_notify_legal_department')) {
    /**
     * Email the legal department about a bounced / escalated cheque.
     *
     * @param array $ctx Keys: type, lease_id, lease_number, tenant_name, unit_label,
     *                   cheque_number, cheque_amount, cheque_date, reason
     * @return array send_re_email_notification result (or a skipped marker)
     */
    function cheque_notify_legal_department(PDO $conn, int $companyId, array $ctx): array
    {
        // Loading the email helper pulls in the mailer/Composer autoload, which can throw
        // on a PHP-version platform mismatch. Never let that abort the caller's DB work.
        try {
            require_once __DIR__ . '/re_email_helper.php';
        } catch (\Throwable $e) {
            error_log('cheque_notify_legal_department: email helper unavailable: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Email helper unavailable: ' . $e->getMessage()]];
        }

        $typeLabel = $ctx['type'] === 'bounced' ? 'Bounced Cheque' : ucfirst((string)$ctx['type']) . ' Request';
        $subject = '[Legal] ' . $typeLabel . ' — Lease ' . ($ctx['lease_number'] ?? ('#' . ($ctx['lease_id'] ?? '')));

        $base = function_exists('get_base_url') ? get_base_url() : '';
        $leaseUrl = $base . '/modules/realestate/lease_view.php?id=' . (int)($ctx['lease_id'] ?? 0);
        $inboxUrl = $base . '/modules/legal/legal_cheque_notifications.php';

        $rows = [
            'Type'         => $typeLabel,
            'Lease'        => $ctx['lease_number'] ?? ('#' . ($ctx['lease_id'] ?? '')),
            'Tenant'       => $ctx['tenant_name'] ?? '',
            'Unit'         => $ctx['unit_label'] ?? '',
            'Cheque #'     => $ctx['cheque_number'] ?? '',
            'Amount'       => isset($ctx['cheque_amount']) ? number_format((float)$ctx['cheque_amount'], 2) . ' AED' : '',
            'Cheque Date'  => $ctx['cheque_date'] ?? '',
            'Reason / Note'=> $ctx['reason'] ?? '',
        ];
        $rowsHtml = '';
        foreach ($rows as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $rowsHtml .= '<tr><td style="padding:6px 12px;border:1px solid #e2e8f0;background:#f8fafc;font-weight:600;">'
                . htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="padding:6px 12px;border:1px solid #e2e8f0;">'
                . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }

        $html = '<div style="font-family:Arial,sans-serif;color:#1e293b;">'
            . '<h2 style="color:#b91c1c;">' . htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') . '</h2>'
            . '<p>The accounting team has flagged the following cheque for the legal department.</p>'
            . '<table style="border-collapse:collapse;font-size:14px;">' . $rowsHtml . '</table>'
            . '<p style="margin-top:16px;">'
            . '<a href="' . htmlspecialchars($leaseUrl, ENT_QUOTES, 'UTF-8') . '" style="background:#2563eb;color:#fff;padding:8px 14px;border-radius:6px;text-decoration:none;">View Lease</a>'
            . ' &nbsp; '
            . '<a href="' . htmlspecialchars($inboxUrl, ENT_QUOTES, 'UTF-8') . '" style="background:#475569;color:#fff;padding:8px 14px;border-radius:6px;text-decoration:none;">Open Legal Inbox</a>'
            . '</p></div>';

        $recipients = [];

        try {
            return send_re_email_notification(
                $conn,
                $companyId,
                'legal_escalation',
                $subject,
                $html,
                $recipients,
                (int)($ctx['lease_id'] ?? 0),
                'lease'
            );
        } catch (Throwable $e) {
            error_log('cheque_notify_legal_department failed: ' . $e->getMessage());
            return ['success' => false, 'errors' => [$e->getMessage()]];
        }
    }
}
