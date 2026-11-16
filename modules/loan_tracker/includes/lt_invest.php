<?php
/**
 * Loan Tracker — Investments: money invested TO a person or a company.
 * Confidential: only the employees in LT_INV_EMPLOYEES; anyone else can request access by email.
 *
 * An investment: who (person / company), the amount and date, how it was paid,
 * the duration (months, end date worked out), the return (a % of the amount or a
 * fixed AED, per month / per quarter / once at the end), the security (cheques,
 * property, gold, guarantor, agreement…), documents, and the returns received.
 * Closed by hand once the money is back, with the reason.
 * Tables: migrations/loan_tracker.sql (lt_investments*). Files: uploads/loan_tracker/inv{id}/.
 */

require_once __DIR__ . '/lt_helper.php';

/**
 * Confidential: the owners of investments. They see everything and decide who else may see it.
 * Anyone else needs a share from them (read only or edit), or can ask them by email.
 */
const LT_INV_EMPLOYEES = ['E00008', 'E00041'];

/** The signed-in login's employee: ['id', 'code', 'name'], or null. */
function lt_inv_me(PDO $conn): ?array
{
    static $me = false;
    if ($me === false) {
        $q = $conn->prepare('SELECT e.id, e.employee_code, e.full_name FROM `user` u JOIN employees e ON e.id = u.employee_id OR e.user_id = u.id
                              WHERE u.id = ? ORDER BY (e.id = u.employee_id) DESC LIMIT 1');
        $q->execute([(int)current_user_id()]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        $me = $r ? ['id' => (int)$r['id'], 'code' => strtoupper(trim((string)$r['employee_code'])), 'name' => trim((string)$r['full_name'])] : null;
    }
    return $me;
}

/** The employee code of the signed-in login ('' if the login has no employee). */
function lt_inv_my_code(PDO $conn): string
{
    return lt_inv_me($conn)['code'] ?? '';
}

/** What the signed-in user may do: 'owner', 'edit', 'view', or '' (no access). */
function lt_inv_level(PDO $conn): string
{
    static $level = null;
    if ($level === null) {
        $me = lt_inv_me($conn);
        if (!$me) {
            $level = '';
        } elseif (in_array($me['code'], LT_INV_EMPLOYEES, true)) {
            $level = 'owner';
        } else {
            try {
                $q = $conn->prepare('SELECT level FROM lt_investment_access WHERE employee_id = ?');
                $q->execute([$me['id']]);
                $level = (string)($q->fetchColumn() ?: '');
            } catch (Throwable $e) {
                $level = '';
            }
        }
    }
    return $level;
}

/** May the signed-in user open investments? */
function lt_inv_allowed(PDO $conn): bool
{
    return lt_inv_level($conn) !== '';
}

/** May the signed-in user add, change, record or close? (owners and those shared with "edit") */
function lt_inv_can_edit(PDO $conn): bool
{
    return in_array(lt_inv_level($conn), ['owner', 'edit'], true);
}

/** Stop a read-only user from changing anything. */
function lt_inv_require_edit(PDO $conn, string $backUrl): void
{
    if (!lt_inv_can_edit($conn)) {
        lt_flash('You have read-only access to Investments, so you cannot change anything.', 'warning');
        header('Location: ' . $backUrl);
        exit;
    }
}

/** Login, and only the owners or those they shared with. Returns the company the investments belong to. */
function lt_inv_boot(PDO $conn): int
{
    require_once dirname(__DIR__, 3) . '/includes/company_helper.php';
    require_login(get_application_web_root() . '/login');
    if (!lt_inv_allowed($conn)) {
        lt_inv_denied($conn);
    }
    return (int)(current_company_id($conn) ?: 1);
}

/** Everyone the owners have shared investments with. */
function lt_inv_shares(PDO $conn): array
{
    return $conn->query("SELECT a.id, a.level, a.granted_at, e.employee_code, e.full_name, COALESCE(NULLIF(u.fullname,''), u.username) AS granted_by_name
                           FROM lt_investment_access a JOIN employees e ON e.id = a.employee_id LEFT JOIN `user` u ON u.id = a.granted_by
                          ORDER BY e.full_name")->fetchAll(PDO::FETCH_ASSOC);
}

/** Give or change access for one employee. Returns the flash sentence, or null if it was refused. */
function lt_inv_grant(PDO $conn, int $emp, string $lvl): ?string
{
    $e = $emp ? lt_employee($conn, $emp) : null;
    if (!$e) {
        lt_flash('Please choose an employee (active employees only).', 'danger');
        return null;
    }
    if (in_array(strtoupper((string)$e['employee_code']), LT_INV_EMPLOYEES, true)) {
        lt_flash($e['full_name'] . ' already has full access.', 'info');
        return null;
    }
    $conn->prepare("INSERT INTO lt_investment_access (employee_id, level, granted_by) VALUES (?,?,?)
                    ON DUPLICATE KEY UPDATE level = VALUES(level), granted_by = VALUES(granted_by), granted_at = NOW()")
        ->execute([$emp, $lvl, current_user_id()]);
    return $e['full_name'] . ' can now ' . ($lvl === 'edit' ? 'see and change' : 'see (read only)') . ' Investments.';
}

/** Owners only: give / take back access, approve / decline a request. Handles the POST, then redirects. */
function lt_inv_handle_share(PDO $conn, string $backUrl): void
{
    $do = (string)($_POST['do'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($do, ['share', 'unshare', 'approve', 'decline'], true)) {
        return;
    }
    csrf_verify();
    $backUrl .= '#give-access';
    if (lt_inv_level($conn) !== 'owner') {
        lt_flash('Only the owners of Investments can give access.', 'warning');
    } elseif ($do === 'share') {
        if ($msg = lt_inv_grant($conn, (int)($_POST['employee_id'] ?? 0), ($_POST['level'] ?? '') === 'edit' ? 'edit' : 'view')) {
            lt_flash($msg);
        }
    } elseif ($do === 'unshare') {
        $q = $conn->prepare("SELECT e.full_name FROM lt_investment_access a JOIN employees e ON e.id = a.employee_id WHERE a.id = ?");
        $q->execute([(int)($_POST['share_id'] ?? 0)]);
        $name = $q->fetchColumn();
        $conn->prepare("DELETE FROM lt_investment_access WHERE id = ?")->execute([(int)($_POST['share_id'] ?? 0)]);
        if ($name) { lt_flash($name . ' no longer has access to Investments.'); }
    } else {
        $q = $conn->prepare("SELECT r.id, r.employee_id, e.full_name FROM lt_investment_access_requests r JOIN employees e ON e.id = r.employee_id
                              WHERE r.id = ? AND r.status = 'pending'");
        $q->execute([(int)($_POST['request_id'] ?? 0)]);
        $req = $q->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            lt_flash('That request was already answered.', 'info');
        } elseif ($do === 'approve') {
            if ($msg = lt_inv_grant($conn, (int)$req['employee_id'], ($_POST['level'] ?? '') === 'edit' ? 'edit' : 'view')) {
                // every open request from this person is answered by this approval
                $conn->prepare("UPDATE lt_investment_access_requests SET status = 'approved', decided_by = ?, decided_at = NOW()
                                 WHERE employee_id = ? AND status = 'pending'")->execute([current_user_id(), (int)$req['employee_id']]);
                lt_flash('Request approved. ' . $msg);
            }
        } else {
            $conn->prepare("UPDATE lt_investment_access_requests SET status = 'declined', decided_by = ?, decided_at = NOW()
                             WHERE employee_id = ? AND status = 'pending'")->execute([current_user_id(), (int)$req['employee_id']]);
            lt_flash('Request from ' . $req['full_name'] . ' declined.');
        }
    }
    header('Location: ' . $backUrl);
    exit;
}

/** Open access requests, newest first, one line per person. */
function lt_inv_requests(PDO $conn): array
{
    return $conn->query("SELECT r.id, r.employee_id, r.reason, r.created_at, e.employee_code, e.full_name, c.name AS company_name,
                                (SELECT COUNT(*) FROM lt_investment_access_requests x WHERE x.employee_id = r.employee_id AND x.status = 'pending') AS times
                           FROM lt_investment_access_requests r
                           JOIN employees e ON e.id = r.employee_id LEFT JOIN companies c ON c.id = e.company_id
                          WHERE r.status = 'pending'
                            AND r.id = (SELECT MAX(y.id) FROM lt_investment_access_requests y WHERE y.employee_id = r.employee_id AND y.status = 'pending')
                          ORDER BY r.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
}

/** Owners only: access requests, "Give access" and who it is shared with. Drawn at the top of the investments list. */
function lt_inv_render_welcome(PDO $conn): void
{
    if (lt_inv_level($conn) !== 'owner') {
        return;
    }
    $requests = lt_inv_requests($conn);
    $shares = lt_inv_shares($conn);
    ?>
    <div class="card card-round mb-3" id="give-access" style="max-width:960px;border-left:4px solid var(--primary)"><div class="card-body p-3 p-md-4">

      <?php if ($requests): ?>
        <div class="lt-section mb-2"><i class="bi bi-bell me-1"></i>Access requests <span class="badge text-bg-danger ms-1"><?= count($requests) ?></span></div>
        <?php foreach ($requests as $r): ?>
          <div class="d-flex flex-wrap align-items-center gap-2 py-2 border-bottom">
            <div class="flex-grow-1" style="min-width:220px">
              <div class="fw-semibold"><?= h($r['full_name']) ?> <span class="text-muted fw-normal small">(<?= h($r['employee_code']) ?><?= $r['company_name'] ? ' · ' . h($r['company_name']) : '' ?>)</span></div>
              <div class="small text-muted">Asked <?= h(lt_date($r['created_at'])) ?><?= (int)$r['times'] > 1 ? ' · ' . (int)$r['times'] . ' times' : '' ?>
                <?= $r['reason'] ? ' · “' . h($r['reason']) . '”' : '' ?></div>
            </div>
            <form method="post" class="d-flex gap-1 m-0">
              <?php csrf_field(); ?><input type="hidden" name="do" value="approve"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
              <select class="form-select form-select-sm" name="level" style="width:auto"><option value="view">Read only</option><option value="edit">Edit</option></select>
              <button class="btn btn-lt btn-sm"><i class="bi bi-check-lg me-1"></i>Approve</button>
            </form>
            <form method="post" class="m-0" onsubmit="return confirm('Decline the request from <?= h(addslashes($r['full_name'])) ?>?');">
              <?php csrf_field(); ?><input type="hidden" name="do" value="decline"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg me-1"></i>Decline</button>
            </form>
          </div>
        <?php endforeach; ?>
        <div class="mb-4"></div>
      <?php endif; ?>

      <div class="lt-section mb-1"><i class="bi bi-person-plus me-1"></i>Give access</div>
      <div class="text-muted small mb-3">Choose the employee and what they may do.</div>
      <form method="post" class="row g-2 align-items-end">
        <?php csrf_field(); ?><input type="hidden" name="do" value="share">
        <div class="col-md-6">
          <label class="form-label small fw-semibold" for="shareEmp">Employee</label>
          <select class="form-select" id="shareEmp" name="employee_id" required>
            <option value="">Choose an employee…</option>
            <?php $grp = null; foreach (lt_employees($conn) as $e):
                if (in_array(strtoupper((string)$e['employee_code']), LT_INV_EMPLOYEES, true)) { continue; }
                if ($grp !== ($e['company_name'] ?: 'Other')): if ($grp !== null): ?></optgroup><?php endif; $grp = $e['company_name'] ?: 'Other'; ?><optgroup label="<?= h($grp) ?>"><?php endif; ?>
              <option value="<?= (int)$e['id'] ?>"><?= h($e['full_name']) ?> (<?= h($e['employee_code']) ?>)</option>
            <?php endforeach; if ($grp !== null): ?></optgroup><?php endif; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-semibold" for="shareLvl">Access</label>
          <select class="form-select" id="shareLvl" name="level">
            <option value="view">Read only</option>
            <option value="edit">Edit</option>
          </select>
        </div>
        <div class="col-md-3"><button class="btn btn-lt w-100"><i class="bi bi-person-plus me-1"></i> Give access</button></div>
      </form>

      <?php if ($shares): ?>
        <div class="small fw-semibold mt-3 mb-1">Shared with</div>
        <?php foreach ($shares as $s): ?>
          <div class="d-flex align-items-center gap-2 py-1 border-top small">
            <span class="flex-grow-1"><?= h($s['full_name']) ?> (<?= h($s['employee_code']) ?>)
              · <span class="badge <?= $s['level'] === 'edit' ? 'text-bg-warning' : 'text-bg-light border' ?>"><?= $s['level'] === 'edit' ? 'Edit' : 'Read only' ?></span>
              <span class="text-muted">· given by <?= h($s['granted_by_name'] ?: '—') ?>, <?= h(lt_date($s['granted_at'])) ?></span></span>
            <form method="post" class="m-0" onsubmit="return confirm('Take back access from <?= h(addslashes($s['full_name'])) ?>?');">
              <?php csrf_field(); ?><input type="hidden" name="do" value="unshare"><input type="hidden" name="share_id" value="<?= (int)$s['id'] ?>">
              <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-x-lg"></i> Remove</button>
            </form>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div></div>
    <?php
}

/** The people who can give access: name and email (work email first, else the login's). */
function lt_inv_owners(PDO $conn): array
{
    $in = implode(',', array_fill(0, count(LT_INV_EMPLOYEES), '?'));
    $q = $conn->prepare("SELECT e.employee_code, e.full_name, COALESCE(NULLIF(TRIM(e.email),''), NULLIF(TRIM(u.email),'')) AS email
                           FROM employees e LEFT JOIN `user` u ON u.id = e.user_id
                          WHERE e.employee_code IN ($in) ORDER BY e.employee_code");
    $q->execute(LT_INV_EMPLOYEES);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Someone without access: a Confidential page with "Request access". The person writes a
 * reason (optional), sees the email exactly as it will go out, and only "Send" emails the
 * people in LT_INV_EMPLOYEES. Never returns.
 */
function lt_inv_denied(PDO $conn): void
{
    http_response_code(403);
    $owners = lt_inv_owners($conn);
    $do = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['do'] ?? '') : '';
    $why = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 500);
    $step = 'write';
    $sent = 0;
    $error = '';

    // The email, the same for the preview and for sending
    $who = lt_user_name($conn, current_user_id()) ?: 'A user';
    $code = lt_inv_my_code($conn);
    $subject = 'Access request: Investments (confidential)';
    $url = (isset($_SERVER['HTTP_HOST']) ? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] : '')
        . get_application_web_root() . '/modules/loan_tracker/investments.php';
    $html = '<p><b>' . h($who) . '</b>' . ($code !== '' ? ' (' . h($code) . ')' : '') . ' is asking for access to <b>Investments</b> (confidential) in Loan Tracker.</p>'
        . ($why !== '' ? '<p>Reason: ' . nl2br(h($why)) . '</p>' : '')
        . '<p>Page: ' . h($url) . '</p><p>Access is set in the code (LT_INV_EMPLOYEES in modules/loan_tracker/includes/lt_invest.php). Reply to the person if you agree.</p>';
    $to = array_values(array_filter($owners, static fn($o) => $o['email'] && filter_var($o['email'], FILTER_VALIDATE_EMAIL)));

    if ($do === 'preview' || $do === 'send') {
        csrf_verify();
        $step = 'preview';
    }
    if ($do === 'send' && !empty($_POST['confirm'])) {
        require_once dirname(__DIR__, 3) . '/includes/mailer.php';
        $set = $conn->query("SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1")->fetch(PDO::FETCH_ASSOC);
        // Saved first, so the owners see it on the Investments page even if the email fails
        $me = lt_inv_me($conn);
        if ($me) {
            $conn->prepare("INSERT INTO lt_investment_access_requests (employee_id, user_id, reason) VALUES (?,?,?)")
                ->execute([$me['id'], current_user_id(), $why !== '' ? $why : null]);
        }
        foreach ($to as $o) {
            if (!$set) { break; }
            $r = send_smtp_mail($set, $o['email'], $subject, $html);
            if (!empty($r['ok'])) { $sent++; } else { error_log('lt investments access request to ' . $o['employee_code'] . ': ' . ($r['error'] ?? '')); }
        }
        if ($sent > 0) {
            $step = 'sent';
        } elseif ($me) {
            $step = 'sent';
            $error = 'Your request is saved and they will see it on the Investments page, but the email could not be sent.';
        } else {
            $error = $set ? 'The email could not be sent. Please try again later.' : 'Email is not set up in the system, so the request could not be sent.';
        }
    } elseif ($do === 'send') {
        $error = 'Please tick the box to confirm, then press Send.';
    }

    $pageTitle = 'Investments';
    require __DIR__ . '/lt_layout_header.php';
    ?>
    <div class="card card-round" style="max-width:680px"><div class="card-body p-4">
      <div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-lock-fill fs-4" style="color:#991b1b"></i><h1 class="lt-title mb-0">This is confidential</h1></div>
      <p class="text-muted mb-3">You do not have access to Investments. Only
        <?= h(implode(' and ', array_map(static fn($o) => $o['full_name'], $owners)) ?: 'the people named by management') ?> can open it.</p>

      <?php if ($step === 'sent'): ?>
        <?php if ($error): ?>
          <div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-1"></i> <?= h($error) ?></div>
        <?php else: ?>
          <div class="alert alert-success mb-0"><i class="bi bi-check-circle me-1"></i> Your request was emailed. You will hear back from them.</div>
        <?php endif; ?>

      <?php elseif ($step === 'preview'): ?>
        <?php if ($error): ?><div class="alert alert-warning"><?= h($error) ?></div><?php endif; ?>
        <div class="fw-semibold mb-2">Check the email before it is sent</div>
        <div class="border rounded-3 mb-3" style="background:#fff">
          <div class="px-3 py-2 border-bottom small">
            <div><span class="text-muted">To:</span> <?= $to ? h(implode(', ', array_map(static fn($o) => $o['full_name'] . ' <' . $o['email'] . '>', $to))) : '<span class="text-danger">no email address found</span>' ?></div>
            <div><span class="text-muted">Subject:</span> <?= h($subject) ?></div>
          </div>
          <div class="px-3 py-2 small"><?= $html ?></div>
        </div>
        <form method="post">
          <?php csrf_field(); ?>
          <input type="hidden" name="reason" value="<?= h($why) ?>">
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="confirm" name="confirm" value="1" required>
            <label class="form-check-label" for="confirm">I confirm this request is correct and want it emailed to them.</label>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-lt-ghost" name="do" value="edit" formnovalidate><i class="bi bi-pencil me-1"></i> Change</button>
            <button class="btn btn-lt" name="do" value="send" <?= $to ? '' : 'disabled' ?>><i class="bi bi-send me-1"></i> Send</button>
          </div>
        </form>

      <?php else: ?>
        <form method="post">
          <?php csrf_field(); ?><input type="hidden" name="do" value="preview">
          <label class="form-label small fw-semibold" for="reason">Why do you need access? <span class="text-muted fw-normal">(optional)</span></label>
          <textarea class="form-control mb-3" id="reason" name="reason" rows="2" maxlength="500"><?= h($why) ?></textarea>
          <button class="btn btn-lt"><i class="bi bi-eye me-1"></i> Preview request</button>
        </form>
      <?php endif; ?>
    </div></div>
    <?php
    require __DIR__ . '/lt_layout_footer.php';
    exit;
}

function lt_inv_to(): array
{
    return [
        'person'  => ['label' => 'Person',  'plural' => 'Persons',   'icon' => 'bi-person',   'color' => '#c9a227'],
        'company' => ['label' => 'Company', 'plural' => 'Companies', 'icon' => 'bi-building', 'color' => '#16a34a'],
    ];
}

function lt_inv_paid_by(): array
{
    return ['bank' => 'Bank transfer', 'cash' => 'Cash', 'cheque' => 'Cheque'];
}

function lt_inv_frequencies(): array
{
    return ['monthly' => 'every month', 'quarterly' => 'every 3 months', 'end' => 'once, at the end'];
}

/** Where the return stands (optional, set by hand). */
function lt_inv_return_statuses(): array
{
    return ['not_started' => 'Not started', 'ongoing' => 'Ongoing', 'late' => 'Late', 'completed' => 'Completed'];
}

function lt_inv_security_kinds(): array
{
    return [
        'cheque'    => ['label' => 'Cheque',    'icon' => 'bi-credit-card-2-front'],
        'property'  => ['label' => 'Property',  'icon' => 'bi-house'],
        'gold'      => ['label' => 'Gold',      'icon' => 'bi-gem'],
        'guarantor' => ['label' => 'Guarantor', 'icon' => 'bi-person-check'],
        'agreement' => ['label' => 'Agreement', 'icon' => 'bi-file-earmark-text'],
        'other'     => ['label' => 'Other',     'icon' => 'bi-shield-check'],
    ];
}

function lt_inv_no(int $id): string
{
    return 'IN-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

/** The return for one period, in AED (a % of the amount, or a fixed amount). */
function lt_inv_per_period(array $inv): float
{
    if ($inv['return_type'] === 'percent') {
        return round((float)$inv['amount'] * (float)$inv['return_value'] / 100, 2);
    }
    return $inv['return_type'] === 'fixed' ? round((float)$inv['return_value'], 2) : 0.0;
}

/** How many times the return is paid over the whole duration. */
function lt_inv_periods(array $inv): int
{
    if ($inv['return_type'] === 'none') {
        return 0;
    }
    if ($inv['return_type'] === 'custom') {
        return (int)($inv['plan_count'] ?? 0);
    }
    $m = max(1, (int)$inv['duration_months']);
    return match ($inv['return_frequency']) {
        'monthly' => $m,
        'quarterly' => (int)ceil($m / 3),
        default => 1,
    };
}

/** All the return expected over the duration. */
function lt_inv_expected(array $inv): float
{
    if ($inv['return_type'] === 'custom') {
        return round((float)($inv['plan_total'] ?? 0), 2);
    }
    return round(lt_inv_per_period($inv) * lt_inv_periods($inv), 2);
}

/** "3% (AED 6,000.00) every month", "AED 5,000.00 every 3 months", "No return agreed". */
function lt_inv_return_text(array $inv): string
{
    if ($inv['return_type'] === 'none') {
        return 'No payout agreed';
    }
    if ($inv['return_type'] === 'custom') {
        $n = (int)($inv['plan_count'] ?? 0);
        return $n ? 'Custom plan: ' . $n . ' payout' . ($n === 1 ? '' : 's') . ', AED ' . lt_money($inv['plan_total']) . ' in total' : 'Custom plan (no payouts yet)';
    }
    if ((float)($inv['return_value'] ?? 0) <= 0) {
        return 'Not set yet';
    }
    $per = 'AED ' . lt_money(lt_inv_per_period($inv));
    $f = lt_inv_frequencies()[$inv['return_frequency']] ?? '';
    if ($inv['return_type'] === 'percent') {
        return rtrim(rtrim(number_format((float)$inv['return_value'], 3, '.', ''), '0'), '.') . '% (' . $per . ') ' . $f;
    }
    return $per . ' ' . $f;
}

/** "Receive after" units. */
const LT_INV_AFTER_UNITS = ['days' => 'days', 'weeks' => 'weeks', 'months' => 'months', 'years' => 'years'];

/** The date N days / weeks / months / years after a date (a 31st falls back to the month's last day). */
function lt_inv_after_date(string $from, int $n, string $unit): string
{
    return match ($unit) {
        'days' => date('Y-m-d', strtotime($from . ' +' . $n . ' days')),
        'weeks' => date('Y-m-d', strtotime($from . ' +' . ($n * 7) . ' days')),
        'years' => lt_add_months($from, $n * 12),
        default => lt_add_months($from, $n),
    };
}

/** "after 1 month", "after 2 weeks". */
function lt_inv_after_text($n, ?string $unit): string
{
    if ($n === null || $n === '') { return ''; }
    $unit = trim((string)$unit) ?: 'months';
    // a known unit reads "1 month" / "2 months"; a word typed in is shown as it is
    return 'after ' . (int)$n . ' ' . (isset(LT_INV_AFTER_UNITS[strtolower($unit)]) && (int)$n === 1 ? rtrim(strtolower($unit), 's') : $unit);
}

/**
 * The plan's payouts with what was received against them (oldest first):
 * each row gets 'got' (AED) and 'state' = received | part | overdue | due.
 */
function lt_inv_plan_states(array $plan, float $received, bool $active = true): array
{
    $left = $received;
    $today = date('Y-m-d');
    foreach ($plan as &$p) {
        $amt = (float)$p['amount'];
        $p['got'] = round(min($amt, max(0.0, $left)), 2);
        $left -= $amt;
        $p['state'] = $p['got'] >= $amt - 0.005 ? 'received' : ($p['got'] > 0 ? 'part' : ($active && $p['due_date'] < $today ? 'overdue' : 'due'));
        if ($p['state'] === 'part' && $active && $p['due_date'] < $today) { $p['state'] = 'overdue'; }
    }
    return $plan;
}

/**
 * One investment's timeline: invested, each payout received, each note, the planned payouts
 * the money received does not cover yet, closed. Oldest first. Used by the page and the list.
 */
function lt_inv_timeline(PDO $conn, array $inv, string $ltBase): array
{
    $id = (int)$inv['id'];
    $paidBy = lt_inv_paid_by();
    $q = $conn->prepare("SELECT * FROM lt_investment_returns WHERE investment_id = ? ORDER BY received_on, id");
    $q->execute([$id]);
    $rets = $q->fetchAll(PDO::FETCH_ASSOC);
    $received = array_sum(array_map(static fn($r) => (float)$r['amount'], $rets));
    $q = $conn->prepare("SELECT n.*, COALESCE(NULLIF(u.fullname,''), u.username) AS by_name FROM lt_investment_notes n LEFT JOIN `user` u ON u.id = n.created_by WHERE n.investment_id = ? ORDER BY n.created_at, n.id");
    $q->execute([$id]);
    $notes = $q->fetchAll(PDO::FETCH_ASSOC);
    $plan = $inv['return_type'] === 'custom' ? lt_inv_plan($conn, $id) : [];
    if (!array_key_exists('created_by_name', $inv)) {
        $inv['created_by_name'] = lt_user_name($conn, isset($inv['created_by']) ? (int)$inv['created_by'] : null);
    }
    $events = [];
    $add = static function (string $sort, int $order, array $e) use (&$events) { $events[] = ['sort' => $sort . '|' . $order] + $e + ['amount' => '', 'note' => '', 'file' => null]; };
    $add($inv['invested_on'], 0, ['kind' => 'given', 'icon' => 'bi-cash-coin', 'date' => lt_date($inv['invested_on']), 'title' => 'Invested',
        'amount' => 'AED ' . lt_money($inv['amount']), 'note' => trim(($inv['purpose'] ?: '') . ($inv['created_by_name'] ? ' · added by ' . $inv['created_by_name'] : ''), ' ·')]);
    foreach ($rets as $r) {
        $add($r['received_on'], 1, ['kind' => 'pay', 'icon' => 'bi-arrow-down-circle', 'date' => lt_date($r['received_on']), 'title' => 'Payout received',
            'amount' => 'AED ' . lt_money($r['amount']), 'note' => trim(($paidBy[$r['paid_by']] ?? '') . ($r['notes'] ? ' · ' . $r['notes'] : ''), ' ·'),
            'file' => $r['file_path'] ? $ltBase . '/investment_file.php?k=ret&id=' . (int)$r['id'] : null]);
    }
    foreach ($notes as $n) {
        $add(substr($n['created_at'], 0, 10), 2, ['kind' => 'memo', 'icon' => 'bi-chat-left-text', 'date' => lt_date($n['created_at']), 'title' => 'Note' . ($n['by_name'] ? ' · ' . $n['by_name'] : ''), 'note' => $n['note']]);
    }
    $cum = 0.0;
    foreach ($plan as $p) {
        $cum += (float)$p['amount'];
        if ($cum <= $received + 0.005) { continue; }
        $late = $p['due_date'] < date('Y-m-d') && $inv['status'] === 'active';
        $add($p['due_date'], 3, ['kind' => $late ? 'warn' : 'end', 'icon' => $late ? 'bi-exclamation-lg' : 'bi-calendar-event', 'date' => lt_date($p['due_date']),
            'title' => $late ? 'Payout due, not received yet' : 'Payout planned', 'amount' => 'AED ' . lt_money($p['amount']),
            'note' => trim(lt_inv_after_text($p['after_months'], $p['after_unit'] ?? 'months') . ($p['note'] ? ' · ' . $p['note'] : ''), ' ·')]);
    }
    if ($inv['status'] === 'closed') {
        $add(substr((string)$inv['closed_at'], 0, 10), 4, ['kind' => 'done', 'icon' => 'bi-lock', 'date' => lt_date($inv['closed_at']), 'title' => 'Closed', 'note' => (string)$inv['close_note']]);
    }
    usort($events, static fn($a, $b) => strcmp($a['sort'], $b['sort']));
    return $events;
}

/** The payouts of a custom plan, in date order. */
function lt_inv_plan(PDO $conn, int $id): array
{
    $q = $conn->prepare("SELECT * FROM lt_investment_plan WHERE investment_id = ? ORDER BY due_date, seq");
    $q->execute([$id]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Received so far, per investment id. */
function lt_inv_received(PDO $conn, array $ids): array
{
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $q = $conn->prepare("SELECT investment_id, SUM(amount) FROM lt_investment_returns WHERE investment_id IN ($in) GROUP BY investment_id");
    $q->execute(array_values($ids));
    return array_map('floatval', $q->fetchAll(PDO::FETCH_KEY_PAIR) ?: []);
}

/** "12 cheques · Guarantor · Property" from a list of security rows. */
function lt_inv_security_text(array $rows): string
{
    if (!$rows) {
        return 'No security';
    }
    $kinds = lt_inv_security_kinds();
    $n = [];
    foreach ($rows as $r) {
        $n[$r['kind']] = ($n[$r['kind']] ?? 0) + 1;
    }
    $out = [];
    foreach ($n as $k => $c) {
        $label = $kinds[$k]['label'] ?? $k;
        $out[] = $k === 'cheque' ? $c . ' cheque' . ($c === 1 ? '' : 's') : ($c > 1 ? $c . ' × ' . $label : $label);
    }
    return implode(' · ', $out);
}

/** Where the end date stands: ['class' => 'late'|'soon'|'', 'text' => …]. */
function lt_inv_end_state(array $inv, ?string $today = null): array
{
    if ($inv['status'] !== 'active') {
        return ['class' => 'done', 'text' => 'CLOSED'];
    }
    $today = $today ?? date('Y-m-d');
    $days = (int)floor((strtotime($inv['end_date']) - strtotime($today)) / 86400);
    if ($days < 0) {
        return ['class' => 'late', 'text' => 'ENDED ' . lt_date($inv['end_date']) . ' — money due back'];
    }
    if ($days <= 31) {
        return ['class' => 'soon', 'text' => $days === 0 ? 'ENDS TODAY' : 'ENDS IN ' . $days . ' DAY' . ($days === 1 ? '' : 'S')];
    }
    if ($days <= 92) {
        $m = (int)round($days / 30);
        return ['class' => 'soon', 'text' => 'ENDS IN ' . $m . ' MONTH' . ($m === 1 ? '' : 'S')];
    }
    return ['class' => '', 'text' => ''];
}

/** One file from a multi-file input ($_FILES['x']['name'][$i] …) as a single $_FILES entry. */
function lt_inv_file_at(?array $files, $i): ?array
{
    if (!$files || !isset($files['name'][$i])) {
        return null;
    }
    return ['name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i],
        'error' => $files['error'][$i], 'size' => $files['size'][$i]];
}

/** An investment of this company, or null. */
function lt_inv_get(PDO $conn, int $id, int $companyId): ?array
{
    $q = $conn->prepare("SELECT i.*, COALESCE(NULLIF(u.fullname,''), u.username) AS created_by_name,
                                COALESCE(NULLIF(c.fullname,''), c.username) AS closed_by_name
                           FROM lt_investments i LEFT JOIN user u ON u.id = i.created_by LEFT JOIN user c ON c.id = i.closed_by
                          WHERE i.id = ? AND i.company_id = ?");
    $q->execute([$id, $companyId]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
