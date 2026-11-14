<?php
/**
 * Real Estate - Tenant Document Expiry Summary (one e-mail, grouped by lease)
 *
 * Replaces one e-mail per document. The cron (compliance_document_expiry_cron.php)
 * and the "Send" button on compliance_send_alerts.php both end up in
 * send_document_expiry_digest().
 *
 * When is it sent?
 *   - any day a document enters a new stage (now within its reminder window,
 *     or now expired) that has not been reported yet  -> marked NEW in the e-mail
 *   - every RE_DOC_DIGEST_WEEKLY_DAY, the full open list, even with nothing new
 *   - never when the list is empty
 * Which documents? Only watched ones - see document_watch_helper.php.
 */

require_once __DIR__ . '/document_watch_helper.php';

if (!defined('RE_DOC_DIGEST_WEEKLY_DAY')) {
    /** ISO weekday for the full weekly summary (1 = Monday ... 7 = Sunday). */
    define('RE_DOC_DIGEST_WEEKLY_DAY', 1);
}

if (!function_exists('re_doc_digest_rows')) {
    /**
     * Open items: watched documents already expired or inside their reminder window.
     * Each row carries the lease it belongs to today (tenant papers uploaded on an
     * older lease are shown under the tenant's current lease).
     */
    function re_doc_digest_rows(PDO $conn, int $companyId): array
    {
        $leaseStatuses = re_doc_sql_list(RE_DOC_WATCH_LEASE_STATUSES);
        $watched = re_doc_watched_sql('d');

        $sql = "
            SELECT x.*,
                   gl.lease_number, gl.end_date AS lease_end_date,
                   u.unit_number, b.name AS building_name,
                   t.tenant_type, t.company_name, t.first_name, t.last_name, t.phone, t.email AS tenant_email
            FROM (
                SELECT d.id, d.document_type, d.document_name, d.file_name, d.expiry_date,
                       COALESCE(dt.document_type_name, '') AS type_name,
                       DATEDIFF(d.expiry_date, CURDATE()) AS days_left,
                       tn.tenant_id,
                       CASE
                         WHEN d.related_type = 'lease' AND EXISTS (
                              SELECT 1 FROM re_leases sl WHERE sl.id = d.related_id
                                AND sl.status IN ({$leaseStatuses}) AND sl.deleted_at IS NULL)
                           THEN d.related_id
                         ELSE (SELECT wl.id FROM re_leases wl
                               WHERE wl.tenant_id = tn.tenant_id AND wl.company_id = d.company_id
                                 AND wl.status IN ({$leaseStatuses}) AND wl.deleted_at IS NULL
                               ORDER BY wl.start_date DESC, wl.id DESC LIMIT 1)
                       END AS group_lease_id,
                       CASE WHEN d.related_type = 'unit' THEN d.related_id END AS doc_unit_id,
                       NOT EXISTS (
                           SELECT 1 FROM re_document_expiry_alerts a
                           WHERE a.document_id = d.id AND a.expiry_date = d.expiry_date AND a.status = 'sent'
                             AND (CASE WHEN d.expiry_date < CURDATE() THEN a.days_before_expiry < 0
                                       ELSE a.days_before_expiry >= 0 END)
                       ) AS is_new
                FROM re_documents d
                LEFT JOIN re_document_types dt ON dt.id = d.document_type_id
                LEFT JOIN (
                    SELECT dd.id AS doc_id,
                           CASE dd.related_type
                             WHEN 'lease'  THEN (SELECT tl.tenant_id FROM re_leases tl WHERE tl.id = dd.related_id)
                             WHEN 'tenant' THEN dd.related_id
                           END AS tenant_id
                    FROM re_documents dd WHERE dd.company_id = ?
                ) tn ON tn.doc_id = d.id
                WHERE d.company_id = ?
                  AND d.expiry_date IS NOT NULL
                  AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL COALESCE(dt.alert_days_before_expiry, 30) DAY)
                  AND {$watched}
            ) x
            LEFT JOIN re_leases gl ON gl.id = x.group_lease_id
            LEFT JOIN re_units u ON u.id = COALESCE(gl.unit_id, x.doc_unit_id)
            LEFT JOIN re_buildings b ON b.id = u.building_id
            LEFT JOIN re_tenants t ON t.id = COALESCE(gl.tenant_id, x.tenant_id)
            ORDER BY x.days_left ASC, x.id ASC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$companyId, $companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['days_left'] = (int)$r['days_left'];
            $r['is_new'] = (bool)$r['is_new'];
            $r['doc_label'] = re_doc_digest_type_label($r);
        }
        unset($r);
        return $rows;
    }
}

if (!function_exists('re_doc_digest_type_label')) {
    function re_doc_digest_type_label(array $r): string
    {
        $map = [
            'tenant_id'       => 'Emirates ID',
            'tenant_visa'     => 'Residence Visa',
            'tenant_passport' => 'Passport',
            'lease_agreement' => 'Tenancy Contract',
            'ejari'           => 'Ejari Certificate',
        ];
        if (isset($map[$r['document_type']])) {
            return $map[$r['document_type']];
        }
        if (($r['type_name'] ?? '') !== '' && $r['type_name'] !== 'Other' && $r['type_name'] !== 'All Documents') {
            return $r['type_name'];
        }
        $name = trim((string)($r['document_name'] ?: $r['file_name']));
        return $name !== '' ? $name : 'Other document';
    }
}

if (!function_exists('re_doc_digest_group')) {
    /** Group rows by lease; most urgent lease first. */
    function re_doc_digest_group(array $rows): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $key = $r['group_lease_id'] ? 'L' . $r['group_lease_id'] : 'U' . ($r['doc_unit_id'] ?: 0);
            if (!isset($groups[$key])) {
                $tenant = ($r['tenant_type'] ?? '') === 'company' && trim((string)$r['company_name']) !== ''
                    ? trim((string)$r['company_name'])
                    : trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                $groups[$key] = [
                    'lease_id'     => $r['group_lease_id'] ? (int)$r['group_lease_id'] : null,
                    'lease_number' => $r['lease_number'],
                    'lease_end'    => $r['lease_end_date'],
                    'property'     => trim(($r['building_name'] ?? '') . ($r['unit_number'] ? ' – Unit ' . $r['unit_number'] : '')),
                    'tenant'       => $tenant,
                    'phone'        => $r['phone'] ?? '',
                    'docs'         => [],
                    'worst'        => PHP_INT_MAX,
                ];
            }
            $groups[$key]['docs'][] = $r;
            $groups[$key]['worst'] = min($groups[$key]['worst'], $r['days_left']);
        }
        uasort($groups, static function ($a, $b) {
            return [$a['worst'], $a['property']] <=> [$b['worst'], $b['property']];
        });
        return array_values($groups);
    }
}

if (!function_exists('re_doc_digest_status_text')) {
    function re_doc_digest_status_text(int $days): string
    {
        if ($days < 0) {
            $n = -$days;
            return 'Expired ' . $n . ' day' . ($n === 1 ? '' : 's') . ' ago';
        }
        if ($days === 0) {
            return 'Expires today';
        }
        return 'Expires in ' . $days . ' day' . ($days === 1 ? '' : 's');
    }
}

if (!function_exists('re_doc_digest_action_text')) {
    function re_doc_digest_action_text(array $r): string
    {
        if (in_array($r['document_type'], ['lease_agreement', 'ejari'], true)) {
            return $r['days_left'] < 0
                ? 'Lease is still Active – confirm renewal or update lease status'
                : 'Follow up renewal';
        }
        return $r['days_left'] < 0 ? 'Collect renewed copy from tenant' : 'Request renewed copy from tenant';
    }
}

if (!function_exists('re_doc_digest_html')) {
    /** Build the e-mail body (inline styles, table layout: safe for Outlook / Gmail). */
    function re_doc_digest_html(string $companyName, array $rows, string $baseUrl): string
    {
        $e = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        $fmt = static function ($d) { return $d ? date('d M Y', strtotime($d)) : '–'; };

        $groups   = re_doc_digest_group($rows);
        $expired  = count(array_filter($rows, static function ($r) { return $r['days_left'] < 0; }));
        $expiring = count($rows) - $expired;
        $new      = count(array_filter($rows, static function ($r) { return $r['is_new']; }));
        $asOf     = date('l, d F Y');
        // Tag NEW only when it tells the reader something (not when every item is new).
        $showNew  = $new > 0 && $new < count($rows);

        // Summary by document type
        $byType = [];
        foreach ($rows as $r) {
            $k = $r['doc_label'];
            $byType[$k] = $byType[$k] ?? ['expired' => 0, 'expiring' => 0];
            $byType[$k][$r['days_left'] < 0 ? 'expired' : 'expiring']++;
        }
        uasort($byType, static function ($a, $b) {
            return ($b['expired'] + $b['expiring']) <=> ($a['expired'] + $a['expiring']);
        });

        $red = '#b42318'; $amber = '#b54708'; $ink = '#1d2939'; $muted = '#667085'; $line = '#e4e7ec';
        $navy = '#1f3a68';

        $tile = static function ($num, $label, $color) use ($e, $line, $muted) {
            return "<td width=\"33%\" style=\"padding:6px;\"><table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"border:1px solid {$line};border-radius:6px;\">"
                . "<tr><td style=\"padding:14px 12px;text-align:center;\">"
                . "<div style=\"font-size:26px;font-weight:bold;color:{$color};line-height:1.1;\">{$e($num)}</div>"
                . "<div style=\"font-size:12px;color:{$muted};margin-top:4px;\">{$e($label)}</div>"
                . "</td></tr></table></td>";
        };

        $h  = "<!doctype html><html><body style=\"margin:0;padding:0;background:#f2f4f7;\">";
        $h .= "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background:#f2f4f7;\"><tr><td align=\"center\" style=\"padding:24px 12px;\">";
        $h .= "<table role=\"presentation\" width=\"720\" cellpadding=\"0\" cellspacing=\"0\" style=\"max-width:720px;width:100%;background:#ffffff;border-radius:8px;font-family:Arial,Helvetica,sans-serif;color:{$ink};\">";

        // Header
        $h .= "<tr><td style=\"background:{$navy};color:#ffffff;padding:20px 24px;border-radius:8px 8px 0 0;\">"
            . "<div style=\"font-size:12px;letter-spacing:1px;text-transform:uppercase;opacity:.85;\">{$e($companyName)}</div>"
            . "<div style=\"font-size:20px;font-weight:bold;margin-top:4px;\">Tenant Document Expiry Summary</div>"
            . "<div style=\"font-size:13px;opacity:.85;margin-top:4px;\">As of {$e($asOf)}</div></td></tr>";

        // Intro
        $h .= "<tr><td style=\"padding:20px 24px 4px;font-size:14px;line-height:1.6;\">"
            . "<p style=\"margin:0 0 10px;\">Dear Team,</p>"
            . "<p style=\"margin:0;\">Please find below the documents on <strong>active leases</strong> that have expired or will expire soon. "
            . "Kindly contact the tenants concerned, obtain the renewed copies and upload them to the lease in the system. "
            . "Once a renewed copy is uploaded, the item is removed from this summary automatically.</p></td></tr>";

        // Tiles
        $h .= "<tr><td style=\"padding:12px 18px 4px;\"><table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\"><tr>"
            . $tile($expired, 'Expired', $red)
            . $tile($expiring, 'Expiring soon', $amber)
            . $tile(count($groups), 'Leases affected', $navy)
            . "</tr></table>";
        if ($showNew) {
            $h .= "<div style=\"font-size:12px;color:{$muted};padding:4px 6px 0;\">{$e($new)} item(s) are new since the last summary and are marked <strong style=\"color:{$navy};\">NEW</strong>.</div>";
        }
        $h .= "</td></tr>";

        // Summary by type
        $h .= "<tr><td style=\"padding:18px 24px 4px;\"><div style=\"font-size:15px;font-weight:bold;margin-bottom:8px;\">Summary by document</div>"
            . "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"font-size:13px;border-collapse:collapse;\">"
            . "<tr style=\"background:#f9fafb;\"><td style=\"padding:8px;border-bottom:1px solid {$line};font-weight:bold;\">Document</td>"
            . "<td style=\"padding:8px;border-bottom:1px solid {$line};font-weight:bold;text-align:right;\">Expired</td>"
            . "<td style=\"padding:8px;border-bottom:1px solid {$line};font-weight:bold;text-align:right;\">Expiring soon</td>"
            . "<td style=\"padding:8px;border-bottom:1px solid {$line};font-weight:bold;text-align:right;\">Total</td></tr>";
        foreach ($byType as $label => $c) {
            $h .= "<tr><td style=\"padding:8px;border-bottom:1px solid {$line};\">{$e($label)}</td>"
                . "<td style=\"padding:8px;border-bottom:1px solid {$line};text-align:right;color:{$red};\">{$e($c['expired'] ?: '–')}</td>"
                . "<td style=\"padding:8px;border-bottom:1px solid {$line};text-align:right;color:{$amber};\">{$e($c['expiring'] ?: '–')}</td>"
                . "<td style=\"padding:8px;border-bottom:1px solid {$line};text-align:right;font-weight:bold;\">{$e($c['expired'] + $c['expiring'])}</td></tr>";
        }
        $h .= "<tr><td style=\"padding:8px;font-weight:bold;\">Total</td>"
            . "<td style=\"padding:8px;text-align:right;font-weight:bold;color:{$red};\">{$e($expired)}</td>"
            . "<td style=\"padding:8px;text-align:right;font-weight:bold;color:{$amber};\">{$e($expiring)}</td>"
            . "<td style=\"padding:8px;text-align:right;font-weight:bold;\">{$e(count($rows))}</td></tr>";
        $h .= "</table></td></tr>";

        // Details by lease
        $h .= "<tr><td style=\"padding:18px 24px 4px;\"><div style=\"font-size:15px;font-weight:bold;margin-bottom:4px;\">Details by lease</div>"
            . "<div style=\"font-size:12px;color:{$muted};margin-bottom:8px;\">Most urgent first.</div>";
        foreach ($groups as $i => $g) {
            $title = $g['property'] !== '' ? $g['property'] : 'Unit documents';
            $meta  = [];
            if ($g['tenant'] !== '')  $meta[] = 'Tenant: ' . $g['tenant'];
            if ($g['lease_number'])   $meta[] = 'Lease: ' . $g['lease_number'];
            if ($g['lease_end'])      $meta[] = 'Lease ends: ' . $fmt($g['lease_end']);
            if ($g['phone'] !== '')   $meta[] = 'Tel: ' . $g['phone'];
            $link = $g['lease_id'] ? $baseUrl . '/modules/realestate/lease_view.php?id=' . $g['lease_id'] : '';

            $h .= "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"border:1px solid {$line};border-radius:6px;margin:0 0 12px;font-size:13px;border-collapse:separate;table-layout:fixed;\">"
                . "<tr><td colspan=\"3\" style=\"background:#f9fafb;padding:10px 12px;border-bottom:1px solid {$line};border-radius:6px 6px 0 0;\">"
                . "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\"><tr>"
                . "<td style=\"font-size:14px;font-weight:bold;\">" . ($i + 1) . ". {$e($title)}</td>"
                . ($link ? "<td align=\"right\" style=\"font-size:12px;\"><a href=\"{$e($link)}\" style=\"color:{$navy};\">View lease &rarr;</a></td>" : '')
                . "</tr></table>"
                . ($meta ? "<div style=\"font-size:12px;color:{$muted};margin-top:3px;\">{$e(implode('  ·  ', $meta))}</div>" : '')
                . "</td></tr>"
                . "<tr><td width=\"34%\" style=\"width:34%;padding:6px 12px;color:{$muted};font-size:12px;\">Document</td>"
                . "<td width=\"20%\" style=\"width:20%;padding:6px 12px;color:{$muted};font-size:12px;\">Expiry date</td>"
                . "<td width=\"46%\" style=\"width:46%;padding:6px 12px;color:{$muted};font-size:12px;\">Status / action</td></tr>";
            foreach ($g['docs'] as $r) {
                $c = $r['days_left'] < 0 ? $red : $amber;
                $newTag = ($showNew && $r['is_new'])
                    ? " <span style=\"background:{$navy};color:#fff;font-size:10px;font-weight:bold;padding:1px 5px;border-radius:3px;\">NEW</span>"
                    : '';
                $h .= "<tr><td style=\"padding:7px 12px;border-top:1px solid {$line};\">{$e($r['doc_label'])}{$newTag}</td>"
                    . "<td style=\"padding:7px 12px;border-top:1px solid {$line};white-space:nowrap;\">{$e($fmt($r['expiry_date']))}</td>"
                    . "<td style=\"padding:7px 12px;border-top:1px solid {$line};\"><strong style=\"color:{$c};\">{$e(re_doc_digest_status_text($r['days_left']))}</strong>"
                    . "<div style=\"font-size:12px;color:{$muted};\">{$e(re_doc_digest_action_text($r))}</div></td></tr>";
            }
            $h .= "</table>";
        }
        $h .= "</td></tr>";

        // Footer
        $h .= "<tr><td style=\"padding:12px 24px 22px;font-size:12px;color:{$muted};line-height:1.6;border-top:1px solid {$line};\">"
            . "This summary covers active leases only. Documents of expired or terminated leases, and documents already replaced by a newer copy, are not included.<br>"
            . "This is an automated message from the Real Estate Management System. Please do not reply to this e-mail."
            . "</td></tr>";

        $h .= "</table></td></tr></table></body></html>";
        return $h;
    }
}

if (!function_exists('re_doc_digest_recipients')) {
    function re_doc_digest_recipients(PDO $conn, int $companyId): array
    {
        $list = [];
        $c = $conn->prepare("SELECT recipient_emails FROM re_collections_alerts_config
                             WHERE company_id = ? AND alert_type = 'document_expiry' AND is_enabled = 1");
        $c->execute([$companyId]);
        foreach ($c->fetchAll(PDO::FETCH_COLUMN) as $csv) {
            $list = array_merge($list, array_map('trim', explode(',', (string)$csv)));
        }
        // re_email_notifications recipients are added by send_re_email_notification() itself.
        return array_values(array_unique(array_filter($list)));
    }
}

if (!function_exists('send_document_expiry_digest')) {
    /**
     * Send the summary for one company (if due).
     * @param bool $force send even when nothing is new (manual "Send now")
     * @return array{total:int,new:int,sent:int,skipped:int,errors:array,message:string}
     */
    function send_document_expiry_digest(PDO $conn, int $companyId, bool $force = false): array
    {
        $rows = re_doc_digest_rows($conn, $companyId);
        $new  = array_filter($rows, static function ($r) { return $r['is_new']; });
        $out  = ['total' => count($rows), 'new' => count($new), 'sent' => 0, 'skipped' => 0, 'errors' => [], 'message' => ''];

        if (!$rows) {
            $out['message'] = 'Nothing to report – no expired or expiring documents on active leases.';
            return $out;
        }
        $weekly = ((int)date('N') === (int)RE_DOC_DIGEST_WEEKLY_DAY);
        if (!$force && !$new && !$weekly) {
            $out['skipped'] = 1;
            $out['message'] = 'No new items today – the full list goes out on the weekly summary day.';
            return $out;
        }

        $companyName = (string)($conn->query("SELECT name FROM companies WHERE id = " . (int)$companyId)->fetchColumn() ?: 'Real Estate');
        $expired  = count(array_filter($rows, static function ($r) { return $r['days_left'] < 0; }));
        $expiring = count($rows) - $expired;
        $subject  = "Tenant Document Expiry Summary – " . date('d M Y') . " – {$expired} expired, {$expiring} expiring soon";
        $html     = re_doc_digest_html($companyName, $rows, get_base_url());

        $send = send_re_email_notification(
            $conn, $companyId, 'document_expiry', $subject, $html,
            re_doc_digest_recipients($conn, $companyId), null, 'document_digest'
        );
        if (empty($send['success'])) {
            $out['errors'] = $send['errors'] ?? ['E-mail could not be sent'];
            $out['message'] = 'Summary not sent: ' . implode('; ', $out['errors']);
            return $out;
        }

        // Remember what was reported so tomorrow's run only sends when something is new.
        $sentTo = implode(', ', $send['sent_to'] ?? []);
        $log = $conn->prepare("INSERT INTO re_document_expiry_alerts
            (company_id, document_id, alert_sent_date, alert_sent_to, days_before_expiry, expiry_date, status)
            VALUES (?, ?, CURDATE(), ?, ?, ?, 'sent')");
        $mark = $conn->prepare("UPDATE re_documents
            SET expiry_alert_sent = 1, is_expired = CASE WHEN expiry_date < CURDATE() THEN 1 ELSE 0 END
            WHERE id = ?");
        foreach ($new as $r) {
            $log->execute([$companyId, (int)$r['id'], $sentTo, $r['days_left'], $r['expiry_date']]);
            $mark->execute([(int)$r['id']]);
        }

        $out['sent'] = 1;
        $out['message'] = "Summary sent to {$sentTo} – " . count($rows) . " document(s) across "
            . count(re_doc_digest_group($rows)) . " lease(s), " . count($new) . " new.";
        return $out;
    }
}
