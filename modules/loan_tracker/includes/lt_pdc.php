<?php
/**
 * Post-dated cheques — a register of any cheque we give (issued) or receive, shown under
 * Recurring Payments. Owner, Admin and HR (same as the loans pages).
 * Separate from Real Estate's tenant cheques (re_post_dated_cheques).
 * Tables: migrations/loan_tracker.sql (lt_pdc, lt_pdc_history). Files: uploads/loan_tracker/pdc{id}/.
 */

require_once __DIR__ . '/lt_helper.php';

function lt_pdc_directions(): array
{
    return [
        'issued'   => ['label' => 'Issued',   'long' => 'Issued by us',  'party' => 'Paid to',       'icon' => 'bi-box-arrow-up-right'],
        'received' => ['label' => 'Received', 'long' => 'Received by us', 'party' => 'Received from', 'icon' => 'bi-box-arrow-in-down-left'],
    ];
}

/** Each status: label and badge colours. */
function lt_pdc_statuses(): array
{
    return [
        'pending'   => ['label' => 'Pending',   'bg' => '#fef3c7', 'fg' => '#92400e'],
        'deposited' => ['label' => 'Deposited', 'bg' => '#dbeafe', 'fg' => '#1e40af'],
        'cleared'   => ['label' => 'Cleared',   'bg' => '#dcfce7', 'fg' => '#166534'],
        'on_hold'   => ['label' => 'On hold',   'bg' => '#ede9fe', 'fg' => '#5b21b6'],
        'bounced'   => ['label' => 'Bounced',   'bg' => '#fee2e2', 'fg' => '#991b1b'],
        'replaced'  => ['label' => 'Replaced',  'bg' => '#f3f4f6', 'fg' => '#374151'],
        'cancelled' => ['label' => 'Cancelled', 'bg' => '#f3f4f6', 'fg' => '#6b7280'],
        'other'     => ['label' => 'Other',     'bg' => '#e0f2fe', 'fg' => '#075985'],
    ];
}

/** A status as words: "Cleared", or the name typed for "Other" ("With legal"). */
function lt_pdc_label(string $status, ?string $other = null): string
{
    if ($status === 'other' && trim((string)$other) !== '') {
        return trim((string)$other);
    }
    return lt_pdc_statuses()[$status]['label'] ?? $status;
}

function lt_pdc_badge(string $status, ?string $other = null): string
{
    $s = lt_pdc_statuses()[$status] ?? ['bg' => '#f3f4f6', 'fg' => '#374151'];
    return '<span class="lt-badge" style="background:' . $s['bg'] . ';color:' . $s['fg'] . '">' . h(lt_pdc_label($status, $other)) . '</span>';
}

/** The "Other" names already used, so they can be picked again. */
function lt_pdc_custom_statuses(PDO $conn, int $companyId): array
{
    $q = $conn->prepare("SELECT DISTINCT TRIM(x) FROM (
                             SELECT p.status_other AS x FROM lt_pdc p WHERE p.company_id = ? AND p.status = 'other'
                             UNION SELECT hst.to_other FROM lt_pdc_history hst JOIN lt_pdc p ON p.id = hst.pdc_id WHERE p.company_id = ? AND hst.to_status = 'other'
                           ) t WHERE x IS NOT NULL AND TRIM(x) <> '' ORDER BY 1");
    $q->execute([$companyId, $companyId]);
    return $q->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * The status dropdown: the usual statuses, the "Other" names used before (value "custom:Name"),
 * then "Other (type a new one)…" (value "other"), which shows the box named "{$name}_other".
 */
function lt_pdc_status_options(array $customs, string $selected = '', ?string $selectedOther = null): string
{
    $html = '';
    foreach (lt_pdc_statuses() as $k => $st) {
        if ($k === 'other') { continue; }
        $html .= '<option value="' . h($k) . '"' . ($selected === $k ? ' selected' : '') . '>' . h($st['label']) . '</option>';
    }
    if ($customs) {
        $html .= '<optgroup label="Added before">';
        foreach ($customs as $c) {
            $on = $selected === 'other' && strcasecmp((string)$selectedOther, $c) === 0;
            $html .= '<option value="custom:' . h($c) . '"' . ($on ? ' selected' : '') . '>' . h($c) . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html . '<option value="other">Other (type a new one)…</option>';
}

/** What the dropdown and the "Other" box say, as [status, other name], or null if "Other" was left empty. */
function lt_pdc_parse_status(string $choice, string $typed): ?array
{
    if (str_starts_with($choice, 'custom:')) {
        $name = trim(mb_substr(substr($choice, 7), 0, 60));
        return $name !== '' ? ['other', $name] : null;
    }
    if ($choice === 'other') {
        $name = trim(mb_substr($typed, 0, 60));
        return $name !== '' ? ['other', $name] : null;
    }
    return isset(lt_pdc_statuses()[$choice]) ? [$choice, null] : null;
}

/** A cheque of this company, or null. */
function lt_pdc_get(PDO $conn, int $id, int $companyId): ?array
{
    $q = $conn->prepare("SELECT p.*, COALESCE(NULLIF(u.fullname,''), u.username) AS created_by_name
                           FROM lt_pdc p LEFT JOIN `user` u ON u.id = p.created_by WHERE p.id = ? AND p.company_id = ?");
    $q->execute([$id, $companyId]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Change the status and write it in the history. Call inside a transaction. */
function lt_pdc_set_status(PDO $conn, array $pdc, string $to, ?string $toOther, string $on, string $note): int
{
    $conn->prepare("UPDATE lt_pdc SET status = ?, status_other = ?, status_date = ?, updated_by = ? WHERE id = ?")
        ->execute([$to, $to === 'other' ? $toOther : null, $on, current_user_id(), (int)$pdc['id']]);
    $conn->prepare("INSERT INTO lt_pdc_history (pdc_id, from_status, from_other, to_status, to_other, changed_on, note, changed_by) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([(int)$pdc['id'], $pdc['status'], $pdc['status_other'] ?? null, $to, $to === 'other' ? $toOther : null, $on,
                   $note !== '' ? mb_substr($note, 0, 255) : null, current_user_id()]);
    return (int)$conn->lastInsertId();
}

/** "in 3 days", "today", "5 days ago" for a pending cheque's date. */
function lt_pdc_when(string $date): array
{
    $days = (int)floor((strtotime($date) - strtotime(date('Y-m-d'))) / 86400);
    if ($days === 0) { return ['text' => 'today', 'class' => 'soon']; }
    if ($days < 0) { return ['text' => abs($days) . ' day' . ($days === -1 ? '' : 's') . ' ago', 'class' => 'late']; }
    if ($days <= 7) { return ['text' => 'in ' . $days . ' day' . ($days === 1 ? '' : 's'), 'class' => 'soon']; }
    return ['text' => '', 'class' => ''];
}

/* ---------------------------------------------------------------- Email alerts
 * Who: the person who added the cheque, and the addresses in lt_pdc_alert_recipients.
 * When: a pending cheque dated within the next 7 days (once), the same cheque once more if it
 * becomes overdue, and a cheque set to Bounced (each time). Each alert is logged once, so
 * running the check again never sends it twice.
 */

/** The addresses for one cheque and one kind of alert (issuer first, no repeats). */
function lt_pdc_alert_to(PDO $conn, array $pdc, string $kind): array
{
    $to = [];
    if (!empty($pdc['created_by'])) {
        $q = $conn->prepare("SELECT COALESCE(NULLIF(TRIM(e.email),''), NULLIF(TRIM(u.email),'')) FROM `user` u
                               LEFT JOIN employees e ON e.id = u.employee_id WHERE u.id = ?");
        $q->execute([(int)$pdc['created_by']]);
        $issuer = trim((string)$q->fetchColumn());
        if ($issuer !== '' && filter_var($issuer, FILTER_VALIDATE_EMAIL)) { $to[strtolower($issuer)] = $issuer; }
    }
    $col = $kind === 'bounced' ? 'on_bounce' : 'on_due';
    $q = $conn->prepare("SELECT email FROM lt_pdc_alert_recipients WHERE company_id = ? AND $col = 1 ORDER BY id");
    $q->execute([(int)$pdc['company_id']]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $e) {
        if (filter_var($e, FILTER_VALIDATE_EMAIL)) { $to[strtolower($e)] = $e; }
    }
    return array_values($to);
}

/** Subject and body of an alert. */
function lt_pdc_alert_mail(array $pdc, string $kind, string $note = ''): array
{
    $dir = lt_pdc_directions()[$pdc['direction']] ?? ['long' => '', 'party' => 'Party'];
    $days = (int)floor((strtotime($pdc['cheque_date']) - strtotime(date('Y-m-d'))) / 86400);
    $what = match ($kind) {
        'bounced' => 'has BOUNCED',
        'overdue' => 'is overdue (dated ' . lt_date($pdc['cheque_date']) . ', ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' ago)',
        default   => $days === 0 ? 'is due today' : 'is due in ' . $days . ' day' . ($days === 1 ? '' : 's') . ' (' . lt_date($pdc['cheque_date']) . ')',
    };
    $subject = '[PDC] Cheque ' . $pdc['cheque_no'] . ' · ' . $pdc['party_name'] . ' · AED ' . lt_money($pdc['amount']) . ' ' . $what;
    $url = (isset($_SERVER['HTTP_HOST']) ? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] : '')
        . (function_exists('get_application_web_root') ? get_application_web_root() : '') . '/modules/loan_tracker/pdc_form.php?id=' . (int)$pdc['id'];
    $row = static fn($k, $v) => '<tr><td style="padding:4px 12px 4px 0;color:#6b7280">' . h($k) . '</td><td style="padding:4px 0"><b>' . h($v) . '</b></td></tr>';
    $html = '<p>Post dated cheque <b>' . h($pdc['cheque_no']) . '</b> ' . h($what) . '.</p>'
        . '<table style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:14px">'
        . $row('Type', $dir['long']) . $row($dir['party'], $pdc['party_name']) . $row('Cheque no.', $pdc['cheque_no'])
        . ($pdc['bank'] ? $row('Bank', $pdc['bank']) : '') . $row('Cheque date', lt_date($pdc['cheque_date']))
        . $row('Amount', 'AED ' . lt_money($pdc['amount'])) . $row('Status', lt_pdc_label($kind === 'bounced' ? 'bounced' : $pdc['status'], $pdc['status_other'] ?? null))
        . ($pdc['purpose'] ? $row('Purpose', $pdc['purpose']) : '') . ($note !== '' ? $row('Note', $note) : '')
        . '</table>'
        . (str_starts_with($url, 'http') ? '<p><a href="' . h($url) . '">Open the cheque</a></p>' : '')
        . '<p style="color:#6b7280;font-size:12px">You get this because your address is on the Post Dated Cheques alert list, or you added this cheque.</p>';
    return [$subject, $html];
}

/**
 * Send one alert, once. $dry: work out who and what, log nothing, send nothing.
 * Returns ['result' => sent|failed|no_recipients|already|dry, 'to' => [...], 'subject' => …].
 */
function lt_pdc_send_alert(PDO $conn, array $pdc, string $kind, string $refKey, string $note = '', bool $dry = false): array
{
    $q = $conn->prepare("SELECT 1 FROM lt_pdc_alert_log WHERE pdc_id = ? AND kind = ? AND ref_key = ?");
    $q->execute([(int)$pdc['id'], $kind, $refKey]);
    if ($q->fetchColumn()) {
        return ['result' => 'already', 'to' => [], 'subject' => ''];
    }
    $to = lt_pdc_alert_to($conn, $pdc, $kind);
    [$subject, $html] = lt_pdc_alert_mail($pdc, $kind, $note);
    if ($dry) {
        return ['result' => 'dry', 'to' => $to, 'subject' => $subject];
    }
    $result = 'no_recipients';
    $error = null;
    $sentTo = [];
    if ($to) {
        require_once dirname(__DIR__, 3) . '/includes/mailer.php';
        $set = $conn->query("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1")->fetch(PDO::FETCH_ASSOC);
        if (!$set) {
            $result = 'failed';
            $error = 'Email is not set up or is switched off.';
        } else {
            foreach ($to as $addr) {
                $r = send_smtp_mail($set, $addr, $subject, $html);
                if (!empty($r['ok'])) { $sentTo[] = $addr; } else { $error = mb_substr((string)($r['error'] ?? 'send failed'), 0, 255); }
            }
            $result = $sentTo ? 'sent' : 'failed';
        }
    }
    $conn->prepare("INSERT IGNORE INTO lt_pdc_alert_log (pdc_id, kind, ref_key, sent_to, result, error) VALUES (?,?,?,?,?,?)")
        ->execute([(int)$pdc['id'], $kind, $refKey, mb_substr(implode(', ', $sentTo ?: $to), 0, 1000) ?: null, $result, $error]);
    return ['result' => $result, 'to' => $sentTo ?: $to, 'subject' => $subject];
}

/**
 * The daily check: every pending cheque dated within the next 7 days is alerted once as
 * "due soon", and once more as "overdue" when its date has passed (a changed cheque date
 * counts as new). Each person gets ONE email with a table of all their cheques, not one
 * email per cheque. Returns one line per cheque: id, cheque_no, kind, result, to.
 */
function lt_pdc_run_due_alerts(PDO $conn, bool $dry = false): array
{
    $rows = $conn->query("SELECT p.* FROM lt_pdc p
                           WHERE p.status = 'pending' AND p.cheque_date <= CURDATE() + INTERVAL 7 DAY
                             AND NOT EXISTS (SELECT 1 FROM lt_pdc_alert_log a WHERE a.pdc_id = p.id AND a.ref_key = p.cheque_date
                                              AND a.kind = IF(p.cheque_date < CURDATE(), 'overdue', 'due_soon'))
                           ORDER BY p.cheque_date, p.id")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [];
    }
    // who gets which cheques
    $byTo = [];
    $items = [];
    foreach ($rows as $p) {
        $kind = $p['cheque_date'] < date('Y-m-d') ? 'overdue' : 'due_soon';
        $to = lt_pdc_alert_to($conn, $p, $kind);
        $items[(int)$p['id']] = ['p' => $p, 'kind' => $kind, 'to' => $to, 'sent' => []];
        foreach ($to as $addr) { $byTo[strtolower($addr)]['addr'] = $addr; $byTo[strtolower($addr)]['ids'][] = (int)$p['id']; }
    }
    if ($dry) {
        return array_values(array_map(static fn($i) => ['id' => (int)$i['p']['id'], 'cheque_no' => $i['p']['cheque_no'], 'kind' => $i['kind'], 'result' => 'dry', 'to' => $i['to']], $items));
    }
    $set = null;
    $error = null;
    if ($byTo) {
        require_once dirname(__DIR__, 3) . '/includes/mailer.php';
        $set = $conn->query("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1")->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$set) { $error = 'Email is not set up or is switched off.'; }
    }
    foreach ($byTo as $t) {
        if (!$set) { break; }
        [$subject, $html] = lt_pdc_digest_mail(array_map(static fn($id) => $items[$id], $t['ids']));
        try {
            $r = send_smtp_mail($set, $t['addr'], $subject, $html);
        } catch (Throwable $e) {
            $r = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (!empty($r['ok'])) {
            foreach ($t['ids'] as $id) { $items[$id]['sent'][] = $t['addr']; }
        } else {
            $error = mb_substr((string)($r['error'] ?? 'send failed'), 0, 255);
            error_log('pdc digest to ' . $t['addr'] . ': ' . $error);
        }
    }
    // log each cheque once, so it is not sent again
    $log = $conn->prepare("INSERT IGNORE INTO lt_pdc_alert_log (pdc_id, kind, ref_key, sent_to, result, error) VALUES (?,?,?,?,?,?)");
    $out = [];
    foreach ($items as $i) {
        $result = $i['sent'] ? 'sent' : ($i['to'] ? 'failed' : 'no_recipients');
        $log->execute([(int)$i['p']['id'], $i['kind'], $i['p']['cheque_date'], mb_substr(implode(', ', $i['sent'] ?: $i['to']), 0, 1000) ?: null,
                       $result, $result === 'failed' ? $error : null]);
        $out[] = ['id' => (int)$i['p']['id'], 'cheque_no' => $i['p']['cheque_no'], 'kind' => $i['kind'], 'result' => $result, 'to' => $i['sent'] ?: $i['to']];
    }
    return $out;
}

/** One email for one person: a table of their overdue and due-soon cheques. */
function lt_pdc_digest_mail(array $items): array
{
    usort($items, static fn($a, $b) => [$a['kind'] === 'overdue' ? 0 : 1, $a['p']['cheque_date']] <=> [$b['kind'] === 'overdue' ? 0 : 1, $b['p']['cheque_date']]);
    $over = count(array_filter($items, static fn($i) => $i['kind'] === 'overdue'));
    $soon = count($items) - $over;
    $total = array_sum(array_map(static fn($i) => (float)$i['p']['amount'], $items));
    $parts = array_filter([$over ? $over . ' overdue' : '', $soon ? $soon . ' due in the next 7 days' : '']);
    $subject = '[PDC] ' . implode(', ', $parts) . ' · AED ' . lt_money($total);
    $today = strtotime(date('Y-m-d'));
    $th = 'style="text-align:left;padding:6px 10px;background:#1e3a8a;color:#fff;font-size:12px"';
    $td = 'style="padding:6px 10px;border-bottom:1px solid #e5e7eb;font-size:13px"';
    $html = '<p>Post dated cheques that need attention: <b>' . h(implode(', ', $parts)) . '</b>.</p>'
        . '<table style="border-collapse:collapse;font-family:Arial,sans-serif">'
        . '<tr><th ' . $th . '>When</th><th ' . $th . '>Cheque date</th><th ' . $th . '>Cheque no.</th><th ' . $th . '>Type</th><th ' . $th . '>Paid to / from</th>'
        . '<th ' . $th . '>Bank</th><th ' . $th . '>Reason</th><th ' . $th . ' align="right">Amount (AED)</th></tr>';
    foreach ($items as $i) {
        $p = $i['p'];
        $days = (int)round((strtotime($p['cheque_date']) - $today) / 86400);
        $when = $i['kind'] === 'overdue' ? '<b style="color:#b91c1c">Overdue ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . '</b>'
            : '<span style="color:#b45309">' . ($days === 0 ? 'Today' : 'In ' . $days . ' day' . ($days === 1 ? '' : 's')) . '</span>';
        $html .= '<tr><td ' . $td . '>' . $when . '</td><td ' . $td . '>' . h(lt_date($p['cheque_date'])) . '</td><td ' . $td . '><b>' . h($p['cheque_no']) . '</b></td>'
            . '<td ' . $td . '>' . h(lt_pdc_directions()[$p['direction']]['label'] ?? '') . '</td><td ' . $td . '>' . h($p['party_name']) . '</td>'
            . '<td ' . $td . '>' . h($p['bank'] ?: '—') . '</td><td ' . $td . '>' . h($p['purpose'] ?: '—') . '</td>'
            . '<td ' . $td . ' align="right">' . lt_money($p['amount']) . '</td></tr>';
    }
    $html .= '<tr><td colspan="7" style="padding:8px 10px;font-weight:bold;font-size:13px">Total</td><td style="padding:8px 10px;font-weight:bold;font-size:13px" align="right">' . lt_money($total) . '</td></tr></table>';
    $url = (isset($_SERVER['HTTP_HOST']) ? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] : '')
        . (function_exists('get_application_web_root') ? get_application_web_root() : '') . '/modules/loan_tracker/pdc.php?status=pending&due=7';
    $html .= (str_starts_with($url, 'http') ? '<p><a href="' . h($url) . '">Open Post Dated Cheques</a></p>' : '')
        . '<p style="color:#6b7280;font-size:12px">You get this because your address is on the Post Dated Cheques alert list, or you added these cheques. Each cheque is listed once when it gets close, and once more if it becomes overdue.</p>';
    return [$subject, $html];
}

/** Run the daily check at most once a day, after the page has been sent (works without a cron job). */
function lt_pdc_daily_alerts_after_response(PDO $conn): void
{
    try {
        $ins = $conn->prepare("INSERT IGNORE INTO lt_pdc_alert_runs (run_date) VALUES (CURDATE())");
        $ins->execute();
        if ($ins->rowCount() !== 1) {
            return; // already ran today
        }
    } catch (Throwable $e) {
        return;
    }
    register_shutdown_function(static function () use ($conn) {
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
        try { lt_pdc_run_due_alerts($conn); } catch (Throwable $e) { error_log('pdc daily alerts: ' . $e->getMessage()); }
    });
}
