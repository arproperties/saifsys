<?php
/**
 * Finance > Vouchers for the new chart (fin_coa_accounts).
 *
 * Four kinds, one table (fin_vouchers):
 *   jv  Journal Voucher  - any debits and credits, as long as they balance
 *   pc  Petty Cash       - paid out of a cash-in-hand account
 *   pv  Payment Voucher  - paid out of a cash or bank account
 *   rv  Receipt Voucher  - received into a cash or bank account
 *
 * Saving a voucher posts its lines to fin_ledger_entries, so it shows in the
 * Trial Balance and the account ledger at once. A posted voucher can be edited
 * (its lines are replaced) or deleted. A deleted voucher keeps its row and lines
 * with status 'void', out of every balance, so the record of it stays.
 * Every add, edit and delete is written to fin_voucher_history with the user.
 * A line can name the tenant or supplier it belongs to (fin_partner_helper.php).
 */

require_once __DIR__ . '/fin_coa_helper.php';
require_once __DIR__ . '/fin_partner_helper.php';

const FIN_VOUCHER_TYPES = [
    'jv' => ['list' => 'fin_journal_vouchers.php', 'new' => 'fin_journal_voucher_new.php', 'label' => 'Journal Voucher', 'plural' => 'Journal Vouchers', 'prefix' => 'JV', 'icon' => 'bi-journal-text',
             'party' => '', 'cash' => ''],
    'pc' => ['list' => 'fin_petty_cash.php', 'new' => 'fin_petty_cash_new.php', 'label' => 'Petty Cash Voucher', 'plural' => 'Petty Cash', 'prefix' => 'PC', 'icon' => 'bi-wallet2',
             'party' => 'Paid to', 'cash' => 'Petty cash account'],
    'pv' => ['list' => 'fin_payment_vouchers.php', 'new' => 'fin_payment_voucher_new.php', 'label' => 'Payment Voucher', 'plural' => 'Payment Vouchers', 'prefix' => 'PV', 'icon' => 'bi-box-arrow-up-right',
             'party' => 'Paid to', 'cash' => 'Paid from'],
    'rv' => ['list' => 'fin_receipt_vouchers.php', 'new' => 'fin_receipt_voucher_new.php', 'label' => 'Receipt Voucher', 'plural' => 'Receipt Vouchers', 'prefix' => 'RV', 'icon' => 'bi-box-arrow-in-down-left',
             'party' => 'Received from', 'cash' => 'Received into'],
];

const FIN_VOUCHER_MAX_AMOUNT = 9999999999.99;

function fin_voucher_tables_ready(PDO $conn): bool
{
    $chk = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'fin_vouchers' AND COLUMN_NAME = 'voucher_no') OR (TABLE_NAME = 'fin_ledger_entries' AND COLUMN_NAME IN ('voucher_id', 'partner_type')) OR (TABLE_NAME = 'fin_voucher_history' AND COLUMN_NAME = 'action'))");
    $chk->execute();
    return (int)$chk->fetchColumn() === 4;
}

function fin_voucher_valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}

/**
 * Accounts of a company a voucher can post to, in code order: active, and the
 * last level of their branch (nothing under them). A heading with no accounts
 * under it counts, so an entry can be recorded before the chart is complete.
 */
function fin_voucher_ledger_accounts(PDO $conn, int $companyId): array
{
    $stmt = $conn->prepare("
        SELECT a.id, a.code, a.name
          FROM fin_coa_accounts a
         WHERE a.company_id = ? AND a.is_active = 1 AND a.level <> 'total'
           AND NOT EXISTS (SELECT 1 FROM fin_coa_accounts c WHERE c.parent_id = a.id AND c.level <> 'total')
         ORDER BY a.code
    ");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Every active account with its parent, in code order, for the level-by-level
 * account picker: Level 1 lists the accounts with no parent, each choice opens
 * the accounts under it, and a choice with nothing under it is the account.
 */
function fin_voucher_account_tree(PDO $conn, int $companyId): array
{
    $stmt = $conn->prepare("SELECT id, parent_id, code, name FROM fin_coa_accounts WHERE company_id = ? AND is_active = 1 AND level <> 'total' ORDER BY code");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Accounts money can be paid from or received into: the postable accounts under
 * the group named Cash & banks (petty cash: Cash in hand), whatever its code.
 * Charts are numbered differently per company (Ain Al Reem's Cash & banks is
 * 1210000 and its 111xxxx is land), so the name decides, not the code. A chart
 * without such a group falls back to codes 1111 / 111, then to all assets.
 */
function fin_voucher_cash_accounts(PDO $conn, int $companyId, string $type): array
{
    $all = fin_voucher_ledger_accounts($conn, $companyId);

    $stmt = $conn->prepare("SELECT id, parent_id, name FROM fin_coa_accounts WHERE company_id = ? AND is_active = 1 AND level <> 'total'");
    $stmt->execute([$companyId]);
    $tree = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $norm = static fn(string $n): string => trim(preg_replace('/[^a-z]+/', ' ', str_replace('&', ' and ', strtolower($n))));
    $patterns = $type === 'pc' ? ['/^cash in hand$/', '/^cash and banks?$/'] : ['/^cash and banks?$/'];
    foreach ($patterns as $pattern) {
        $under = [];
        foreach ($tree as $a) {
            if (preg_match($pattern, $norm($a['name']))) {
                $under[(int)$a['id']] = true;
            }
        }
        if (!$under) {
            continue;
        }
        // Add children of what is already in, until nothing new turns up.
        do {
            $added = false;
            foreach ($tree as $a) {
                if (!isset($under[(int)$a['id']]) && $a['parent_id'] !== null && isset($under[(int)$a['parent_id']])) {
                    $under[(int)$a['id']] = true;
                    $added = true;
                }
            }
        } while ($added);
        $match = array_values(array_filter($all, static fn($a) => isset($under[(int)$a['id']])));
        if ($match) {
            return $match;
        }
    }

    foreach ([$type === 'pc' ? '1111' : '111', '111', '1'] as $prefix) {
        $match = array_values(array_filter($all, static fn($a) => strpos($a['code'], $prefix) === 0));
        if ($match) {
            return $match;
        }
    }
    return $all;
}

/** Amount typed by a user ("1,250.50") as a rounded float, or null when it is not a number. */
function fin_voucher_parse_amount($raw): ?float
{
    $s = str_replace([',', ' '], '', trim((string)$raw));
    if ($s === '') {
        return 0.0;
    }
    if (!preg_match('/^\d+(\.\d+)?$/', $s)) {
        return null;
    }
    return round((float)$s, 2);
}

/**
 * Check what was entered for a voucher and work out what it posts.
 *
 * $data: date (Y-m-d), party, reference, narration, lines, and for pc/pv/rv
 * cash_account_id. A jv line is [account_id, description, debit, credit]; a
 * line of the other kinds is [account_id, description, amount]. Either may
 * have a partner ("tenant:12"), the tenant or supplier the line belongs to.
 * Returns date, party, reference, narration, amount and postings, each posting
 * being [account id, description, debit, credit, partner or null].
 * Throws InvalidArgumentException with a message fit to show the user.
 */
function fin_voucher_prepare(PDO $conn, int $companyId, string $type, array $data): array
{
    if (!isset(FIN_VOUCHER_TYPES[$type])) {
        throw new InvalidArgumentException('Unknown voucher type.');
    }
    $date = trim((string)($data['date'] ?? ''));
    if (!fin_voucher_valid_date($date)) {
        throw new InvalidArgumentException('Enter a valid voucher date.');
    }
    $text = static function (string $key, int $max) use ($data): ?string {
        $v = trim((string)($data[$key] ?? ''));
        if (mb_strlen($v) > $max) {
            throw new InvalidArgumentException(ucfirst($key) . " is too long ({$max} characters at most).");
        }
        return $v === '' ? null : $v;
    };
    $party = $text('party', 150);
    $reference = $text('reference', 100);
    $narration = $text('narration', 255);

    $accounts = array_column(fin_voucher_ledger_accounts($conn, $companyId), null, 'id');
    $partners = fin_partners($conn, $companyId);
    $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];

    // Every kind ends up as the same thing: a list of [account, description, debit, credit, partner].
    $postings = [];
    $n = 0;
    foreach ($lines as $line) {
        $n++;
        $accountId = (int)($line['account_id'] ?? 0);
        $desc = trim((string)($line['description'] ?? ''));
        $partner = trim((string)($line['partner'] ?? ''));
        if ($type === 'jv') {
            $debit = fin_voucher_parse_amount($line['debit'] ?? '');
            $credit = fin_voucher_parse_amount($line['credit'] ?? '');
        } else {
            $amount = fin_voucher_parse_amount($line['amount'] ?? '');
            $debit = $type === 'rv' ? 0.0 : $amount;
            $credit = $type === 'rv' ? $amount : 0.0;
        }
        if ($debit === null || $credit === null) {
            throw new InvalidArgumentException("Line {$n}: the amount is not a number.");
        }
        if ($accountId === 0 && $debit == 0 && $credit == 0 && $desc === '') {
            continue; // an empty row left on the form
        }
        if (!isset($accounts[$accountId])) {
            throw new InvalidArgumentException("Line {$n}: choose a ledger account.");
        }
        if ($debit > 0 && $credit > 0) {
            throw new InvalidArgumentException("Line {$n}: enter a debit or a credit, not both.");
        }
        if ($debit <= 0 && $credit <= 0) {
            throw new InvalidArgumentException("Line {$n}: enter an amount greater than zero.");
        }
        if (max($debit, $credit) > FIN_VOUCHER_MAX_AMOUNT) {
            throw new InvalidArgumentException("Line {$n}: the amount is too large.");
        }
        if (mb_strlen($desc) > 255) {
            throw new InvalidArgumentException("Line {$n}: the description is too long (255 characters at most).");
        }
        if ($partner !== '' && !isset($partners[$partner])) {
            throw new InvalidArgumentException("Line {$n}: choose a tenant or supplier of this company.");
        }
        $postings[] = [$accountId, $desc !== '' ? $desc : $narration, $debit, $credit, $partner !== '' ? $partner : null];
    }

    if ($type === 'jv') {
        if (count($postings) < 2) {
            throw new InvalidArgumentException('A journal voucher needs at least two lines.');
        }
    } else {
        if (!$postings) {
            throw new InvalidArgumentException('Add at least one line.');
        }
        $cashId = (int)($data['cash_account_id'] ?? 0);
        $allowed = array_column(fin_voucher_cash_accounts($conn, $companyId, $type), null, 'id');
        if (!isset($allowed[$cashId])) {
            throw new InvalidArgumentException('Choose the ' . strtolower(FIN_VOUCHER_TYPES[$type]['cash']) . ' account.');
        }
        $total = 0.0;
        foreach ($postings as $p) {
            if ($p[0] === $cashId) {
                throw new InvalidArgumentException('A line cannot use the same account the money is ' . ($type === 'rv' ? 'received into' : 'paid from') . '.');
            }
            $total += $p[2] + $p[3];
        }
        $total = round($total, 2);
        // Left blank, "Paid to" or "Received from" is the partner of the first line that has one.
        foreach ($postings as $p) {
            if ($party === null && $p[4] !== null) {
                $party = mb_substr($partners[$p[4]]['name'], 0, 150);
            }
        }
        $cashDesc = $narration ?? $party;
        if ($type === 'rv') {
            array_unshift($postings, [$cashId, $cashDesc, $total, 0.0, null]);
        } else {
            $postings[] = [$cashId, $cashDesc, 0.0, $total, null];
        }
    }

    $debits = round(array_sum(array_column($postings, 2)), 2);
    $credits = round(array_sum(array_column($postings, 3)), 2);
    if (abs($debits - $credits) > 0.004) {
        throw new InvalidArgumentException('Debits (' . number_format($debits, 2) . ') and credits (' . number_format($credits, 2) . ') must be equal.');
    }
    if ($debits > FIN_VOUCHER_MAX_AMOUNT) {
        throw new InvalidArgumentException('The voucher total is too large.');
    }

    return ['date' => $date, 'party' => $party, 'reference' => $reference, 'narration' => $narration, 'amount' => $debits, 'postings' => $postings];
}

/** Writes a voucher's lines to the ledger. */
function fin_voucher_post_lines(PDO $conn, int $companyId, int $voucherId, string $date, array $postings, ?int $userId): void
{
    $ins = $conn->prepare("INSERT INTO fin_ledger_entries (company_id, account_id, voucher_id, line_no, entry_date, description, partner_type, partner_id, debit, credit, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    foreach ($postings as $i => [$accountId, $desc, $debit, $credit, $partner]) {
        [$partnerType, $partnerId] = $partner !== null ? fin_partner_parse($partner) : [null, null];
        $ins->execute([$companyId, $accountId, $voucherId, $i + 1, $date, $desc, $partnerType, $partnerId, $debit, $credit, $userId]);
    }
}

/**
 * Save and post a voucher. Returns its id. $data is described at fin_voucher_prepare().
 * Throws InvalidArgumentException with a message fit to show the user.
 */
function fin_voucher_create(PDO $conn, int $companyId, string $type, array $data, ?int $userId = null): int
{
    $p = fin_voucher_prepare($conn, $companyId, $type, $data);
    $date = $p['date'];

    $ownTx = !$conn->inTransaction();
    if ($ownTx) {
        $conn->beginTransaction();
    }
    try {
        // Numbers run per company, kind and year: PV-2026-0001. The lock keeps two
        // people saving at once from taking the same number.
        $stem = FIN_VOUCHER_TYPES[$type]['prefix'] . '-' . substr($date, 0, 4) . '-';
        $max = $conn->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(voucher_no, '-', -1) AS UNSIGNED)), 0) FROM fin_vouchers WHERE company_id = ? AND voucher_no LIKE ? FOR UPDATE");
        $max->execute([$companyId, $stem . '%']);
        $voucherNo = $stem . str_pad((string)((int)$max->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);

        $conn->prepare("INSERT INTO fin_vouchers (company_id, type, voucher_no, voucher_date, party, reference, narration, amount, created_by) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$companyId, $type, $voucherNo, $date, $p['party'], $p['reference'], $p['narration'], $p['amount'], $userId]);
        $voucherId = (int)$conn->lastInsertId();

        fin_voucher_post_lines($conn, $companyId, $voucherId, $date, $p['postings'], $userId);
        fin_voucher_log($conn, $companyId, $voucherId, 'created', $userId, null, fin_voucher_snapshot($conn, $companyId, $voucherId));
        if ($ownTx) {
            $conn->commit();
        }
        return $voucherId;
    } catch (Throwable $e) {
        if ($ownTx) {
            $conn->rollBack();
        }
        throw $e;
    }
}

/**
 * Change a posted voucher: its details and lines are replaced by $data (see
 * fin_voucher_prepare()). The voucher number and kind never change.
 * Returns false when nothing was different, so nothing was saved.
 */
function fin_voucher_update(PDO $conn, int $companyId, int $id, array $data, ?int $userId = null): bool
{
    $ownTx = !$conn->inTransaction();
    if ($ownTx) {
        $conn->beginTransaction();
    }
    try {
        $stmt = $conn->prepare("SELECT type, status FROM fin_vouchers WHERE id = ? AND company_id = ? FOR UPDATE");
        $stmt->execute([$id, $companyId]);
        $v = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$v) {
            throw new InvalidArgumentException('Voucher not found in this company.');
        }
        if ($v['status'] !== 'posted') {
            throw new InvalidArgumentException('A deleted voucher cannot be edited.');
        }
        $p = fin_voucher_prepare($conn, $companyId, $v['type'], $data);
        $before = fin_voucher_snapshot($conn, $companyId, $id);

        $conn->prepare("UPDATE fin_vouchers SET voucher_date = ?, party = ?, reference = ?, narration = ?, amount = ? WHERE id = ? AND company_id = ?")
            ->execute([$p['date'], $p['party'], $p['reference'], $p['narration'], $p['amount'], $id, $companyId]);
        $conn->prepare("DELETE FROM fin_ledger_entries WHERE voucher_id = ? AND company_id = ?")->execute([$id, $companyId]);
        fin_voucher_post_lines($conn, $companyId, $id, $p['date'], $p['postings'], $userId);

        $after = fin_voucher_snapshot($conn, $companyId, $id);
        $changed = $before != $after;
        if ($changed) {
            fin_voucher_log($conn, $companyId, $id, 'edited', $userId, $before, $after);
        }
        if ($ownTx) {
            $conn->commit();
        }
        return $changed;
    } catch (Throwable $e) {
        if ($ownTx) {
            $conn->rollBack();
        }
        throw $e;
    }
}

/**
 * What a saved voucher holds, as the form takes it: for pc/pv/rv the line the
 * money moves through becomes cash_account_id and is left out of the lines.
 */
function fin_voucher_form_data(array $v): array
{
    $lines = $v['lines'];
    $cashId = 0;
    if ($v['type'] !== 'jv' && $lines) {
        // A receipt posts the cash line first; payments and petty cash post it last.
        $cash = $v['type'] === 'rv' ? array_shift($lines) : array_pop($lines);
        $cashId = (int)$cash['account_id'];
    }
    $amount = static fn($n): string => (float)$n > 0 ? number_format((float)$n, 2, '.', '') : '';
    $out = [];
    foreach ($lines as $l) {
        $out[] = [
            'account_id'  => (int)$l['account_id'],
            // A line left blank was saved with the narration.
            'description' => (string)$l['description'] === (string)$v['narration'] ? '' : (string)$l['description'],
            'partner'     => fin_partner_key($l['partner_type'], $l['partner_id']),
            'debit'       => $amount($l['debit']),
            'credit'      => $amount($l['credit']),
            'amount'      => $amount((float)$l['debit'] + (float)$l['credit']),
        ];
    }
    return [
        'date' => $v['voucher_date'],
        'party' => (string)$v['party'],
        'reference' => (string)$v['reference'],
        'narration' => (string)$v['narration'],
        'cash_account_id' => $cashId,
        'lines' => $out,
    ];
}

/** A voucher as it stands now, in the shape kept in the history. Null when it does not exist. */
function fin_voucher_snapshot(PDO $conn, int $companyId, int $id): ?array
{
    $v = fin_voucher_get($conn, $companyId, $id);
    if (!$v) {
        return null;
    }
    $lines = [];
    foreach ($v['lines'] as $l) {
        $lines[] = [
            'account'     => $l['code'] . ' ' . $l['name'],
            'description' => (string)$l['description'],
            'partner'     => (string)$l['partner_name'],
            'debit'       => number_format((float)$l['debit'], 2, '.', ''),
            'credit'      => number_format((float)$l['credit'], 2, '.', ''),
        ];
    }
    return [
        'date'      => $v['voucher_date'],
        'party'     => (string)$v['party'],
        'reference' => (string)$v['reference'],
        'narration' => (string)$v['narration'],
        'amount'    => number_format((float)$v['amount'], 2, '.', ''),
        'lines'     => $lines,
    ];
}

/** One line of the history: who did what to a voucher, with the voucher before and after. */
function fin_voucher_log(PDO $conn, int $companyId, int $voucherId, string $action, ?int $userId, ?array $before, ?array $after): void
{
    $json = static fn(?array $a): ?string => $a === null ? null : json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $conn->prepare("INSERT INTO fin_voucher_history (company_id, voucher_id, action, user_id, before_json, after_json) VALUES (?,?,?,?,?,?)")
        ->execute([$companyId, $voucherId, $action, $userId, $json($before), $json($after)]);
}

/**
 * History of a company's vouchers, newest first, each row with the voucher
 * number and kind, the user's name and `changes` (see fin_voucher_changes()).
 * $filter: voucher_id, type, action, user_id, from, to (dates of the change).
 */
function fin_voucher_history(PDO $conn, int $companyId, array $filter = []): array
{
    $sql = "
        SELECT h.id, h.voucher_id, h.action, h.user_id, h.created_at, h.before_json, h.after_json,
               v.voucher_no, v.type, v.status,
               COALESCE(NULLIF(TRIM(u.fullname), ''), u.username, CONCAT('User #', h.user_id)) AS user_name
          FROM fin_voucher_history h
          JOIN fin_vouchers v ON v.id = h.voucher_id
          LEFT JOIN user u ON u.id = h.user_id
         WHERE h.company_id = ?";
    $args = [$companyId];
    foreach (['voucher_id' => 'h.voucher_id = ?', 'type' => 'v.type = ?', 'action' => 'h.action = ?', 'user_id' => 'h.user_id = ?',
              'from' => 'h.created_at >= ?', 'to' => 'h.created_at < DATE_ADD(?, INTERVAL 1 DAY)'] as $key => $cond) {
        if (!empty($filter[$key])) {
            $sql .= " AND {$cond}";
            $args[] = $filter[$key];
        }
    }
    $stmt = $conn->prepare($sql . " ORDER BY h.created_at DESC, h.id DESC LIMIT 1000");
    $stmt->execute($args);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $i => $r) {
        $before = $r['before_json'] !== null ? json_decode($r['before_json'], true) : null;
        $after = $r['after_json'] !== null ? json_decode($r['after_json'], true) : null;
        $rows[$i]['changes'] = $r['action'] === 'edited' ? fin_voucher_changes($before, $after) : [];
        $rows[$i]['amount'] = ($after ?? $before)['amount'] ?? null;
        unset($rows[$i]['before_json'], $rows[$i]['after_json']);
    }
    return $rows;
}

/** "Added", "Edited" or "Deleted" as a coloured badge. */
function fin_voucher_action_badge(string $action): string
{
    [$label, $class] = ['created' => ['Added', 'text-bg-success'], 'edited' => ['Edited', 'text-bg-primary'], 'deleted' => ['Deleted', 'text-bg-danger']][$action] ?? [$action, 'text-bg-secondary'];
    return '<span class="badge ' . $class . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * What an edit changed, as [label, before, after] rows. A line that was removed
 * has no "after", one that was added has no "before".
 */
function fin_voucher_changes(?array $before, ?array $after): array
{
    if ($before === null || $after === null) {
        return [];
    }
    $out = [];
    $show = static function (string $key, string $v): string {
        if ($v === '') {
            return '—';
        }
        if ($key === 'date') {
            return date('d M Y', strtotime($v));
        }
        return $key === 'amount' ? number_format((float)$v, 2) : $v;
    };
    foreach (['date' => 'Date', 'party' => 'Party', 'reference' => 'Reference', 'narration' => 'Narration', 'amount' => 'Amount'] as $key => $label) {
        $b = (string)($before[$key] ?? '');
        $a = (string)($after[$key] ?? '');
        if ($b !== $a) {
            $out[] = [$label, $show($key, $b), $show($key, $a)];
        }
    }
    $text = static function (array $l): string {
        $side = (float)$l['debit'] > 0 ? 'Dr ' . number_format((float)$l['debit'], 2) : 'Cr ' . number_format((float)$l['credit'], 2);
        // Vouchers saved before lines had partners have no 'partner' in their history.
        return $l['account'] . ' · ' . $side . (($l['partner'] ?? '') !== '' ? ' · ' . $l['partner'] : '') . ($l['description'] !== '' ? ' · ' . $l['description'] : '');
    };
    $old = array_map($text, $before['lines'] ?? []);
    $new = array_map($text, $after['lines'] ?? []);
    // Lines found on both sides did not change; each match is used once.
    foreach ($old as $i => $line) {
        $j = array_search($line, $new, true);
        if ($j !== false) {
            unset($old[$i], $new[$j]);
        }
    }
    foreach ($old as $line) {
        $out[] = ['Line removed', $line, ''];
    }
    foreach ($new as $line) {
        $out[] = ['Line added', '', $line];
    }
    return $out;
}

/** A voucher of this company with its lines (each with account code and name, and its partner's name), or null. */
function fin_voucher_get(PDO $conn, int $companyId, int $id): ?array
{
    $stmt = $conn->prepare("
        SELECT v.*,
               COALESCE(NULLIF(TRIM(c.fullname), ''), c.username) AS created_by_name,
               COALESCE(NULLIF(TRIM(d.fullname), ''), d.username) AS voided_by_name
          FROM fin_vouchers v
          LEFT JOIN user c ON c.id = v.created_by
          LEFT JOIN user d ON d.id = v.voided_by
         WHERE v.id = ? AND v.company_id = ?");
    $stmt->execute([$id, $companyId]);
    $v = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$v) {
        return null;
    }
    $stmt = $conn->prepare("
        SELECT e.line_no, e.account_id, e.description, e.debit, e.credit, a.code, a.name,
               e.partner_type, e.partner_id, " . fin_partner_name_sql('e') . " AS partner_name
          FROM fin_ledger_entries e
          JOIN fin_coa_accounts a ON a.id = e.account_id
         WHERE e.voucher_id = ? AND e.company_id = ?
         ORDER BY e.line_no, e.id
    ");
    $stmt->execute([$id, $companyId]);
    $v['lines'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $v;
}

/** Vouchers of one kind, newest first. Dates are optional. Deleted ones only when asked for. */
function fin_voucher_list(PDO $conn, int $companyId, string $type, ?string $from = null, ?string $to = null, bool $withDeleted = false): array
{
    $sql = "SELECT id, voucher_no, voucher_date, party, reference, narration, amount, status FROM fin_vouchers WHERE company_id = ? AND type = ?";
    $args = [$companyId, $type];
    if (!$withDeleted) {
        $sql .= " AND status = 'posted'";
    }
    if ($from) {
        $sql .= " AND voucher_date >= ?";
        $args[] = $from;
    }
    if ($to) {
        $sql .= " AND voucher_date <= ?";
        $args[] = $to;
    }
    $stmt = $conn->prepare($sql . " ORDER BY voucher_date DESC, id DESC LIMIT 500");
    $stmt->execute($args);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Delete a posted voucher: it and its lines stay on record (status 'void') but leave every balance. */
function fin_voucher_delete(PDO $conn, int $companyId, int $id, ?int $userId = null): void
{
    $stmt = $conn->prepare("SELECT status FROM fin_vouchers WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $companyId]);
    $status = $stmt->fetchColumn();
    if ($status === false) {
        throw new InvalidArgumentException('Voucher not found in this company.');
    }
    if ($status !== 'posted') {
        throw new InvalidArgumentException('This voucher is already deleted.');
    }

    $ownTx = !$conn->inTransaction();
    if ($ownTx) {
        $conn->beginTransaction();
    }
    try {
        $conn->prepare("UPDATE fin_vouchers SET status = 'void', voided_by = ?, voided_at = NOW() WHERE id = ? AND company_id = ?")
            ->execute([$userId, $id, $companyId]);
        $conn->prepare("UPDATE fin_ledger_entries SET is_void = 1 WHERE voucher_id = ? AND company_id = ?")
            ->execute([$id, $companyId]);
        fin_voucher_log($conn, $companyId, $id, 'deleted', $userId, fin_voucher_snapshot($conn, $companyId, $id), null);
        if ($ownTx) {
            $conn->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx) {
            $conn->rollBack();
        }
        throw $e;
    }
}

/**
 * Statement of one account for a period (both dates included).
 * Returns null when the account is not this company's, else ['account' => ...,
 * 'opening' => net debit before the period, 'lines' => each with a running
 * `balance`, 'debit' and 'credit' totals of the period, 'closing'].
 * Balances are debit-positive.
 */
function fin_account_ledger(PDO $conn, int $companyId, int $accountId, string $from, string $to): ?array
{
    $stmt = $conn->prepare("SELECT id, code, name, level, normal_balance, is_active FROM fin_coa_accounts WHERE id = ? AND company_id = ?");
    $stmt->execute([$accountId, $companyId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        return null;
    }

    $stmt = $conn->prepare("SELECT COALESCE(SUM(debit - credit), 0) FROM fin_ledger_entries WHERE company_id = ? AND account_id = ? AND is_void = 0 AND entry_date < ?");
    $stmt->execute([$companyId, $accountId, $from]);
    $opening = round((float)$stmt->fetchColumn(), 2);

    $stmt = $conn->prepare("
        SELECT e.entry_date, e.description, e.debit, e.credit,
               e.partner_type, e.partner_id, " . fin_partner_name_sql('e') . " AS partner_name,
               v.id AS voucher_id, v.voucher_no, v.type, v.party
          FROM fin_ledger_entries e
          JOIN fin_vouchers v ON v.id = e.voucher_id
         WHERE e.company_id = ? AND e.account_id = ? AND e.is_void = 0 AND e.entry_date BETWEEN ? AND ?
         ORDER BY e.entry_date, e.voucher_id, e.line_no, e.id
    ");
    $stmt->execute([$companyId, $accountId, $from, $to]);
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $balance = $opening;
    $debit = $credit = 0.0;
    foreach ($lines as $i => $l) {
        $balance = round($balance + (float)$l['debit'] - (float)$l['credit'], 2);
        $debit += (float)$l['debit'];
        $credit += (float)$l['credit'];
        $lines[$i]['balance'] = $balance;
    }

    return [
        'account' => $account,
        'opening' => $opening,
        'lines'   => $lines,
        'debit'   => round($debit, 2),
        'credit'  => round($credit, 2),
        'closing' => $balance,
    ];
}

/** "1,250.00 Dr", "300.00 Cr" or "0.00" for a debit-positive balance. */
function fin_balance_label(float $balance): string
{
    if (abs($balance) < 0.005) {
        return '0.00';
    }
    return number_format(abs($balance), 2) . ($balance > 0 ? ' Dr' : ' Cr');
}
