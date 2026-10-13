<?php
/**
 * Finance > Partners: the tenants and suppliers a ledger line can belong to.
 *
 * All tenants post to one account (Sundry Debtors) and suppliers to another, so
 * the account alone cannot say who owes what. A line therefore carries its
 * partner (fin_ledger_entries.partner_type and partner_id), and the lines of one
 * partner add up to that partner's balance.
 *
 * A partner is written as "type:id" ("tenant:12") wherever it is passed around.
 * Balances are debit-positive: Dr is owed to the company, Cr is owed by it.
 */

/** Partner type => where it is kept, what it counts as and the SQL of its name (row alias p). */
const FIN_PARTNER_SOURCES = [
    'tenant'      => ['kind' => 'tenant', 'table' => 're_tenants',
                      'name' => "COALESCE(NULLIF(TRIM(IF(p.tenant_type = 'company', p.company_name, '')), ''), NULLIF(TRIM(CONCAT_WS(' ', p.first_name, p.last_name)), ''), NULLIF(TRIM(p.company_name), ''), CONCAT('Tenant #', p.id))"],
    're_vendor'   => ['kind' => 'supplier', 'table' => 're_vendors', 'name' => 'TRIM(p.vendor_name)'],
    'co_supplier' => ['kind' => 'supplier', 'table' => 'co_suppliers', 'name' => 'TRIM(p.supplier_name)'],
    'vendor'      => ['kind' => 'supplier', 'table' => 'vendors', 'name' => 'TRIM(p.name)'],
];

const FIN_PARTNER_KINDS = ['tenant' => 'Tenants', 'supplier' => 'Suppliers'];

function fin_partner_ready(PDO $conn): bool
{
    $chk = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fin_ledger_entries' AND COLUMN_NAME = 'partner_type'");
    $chk->execute();
    return (int)$chk->fetchColumn() === 1;
}

/** SQL giving the name of the partner on a ledger line whose alias is $e. */
function fin_partner_name_sql(string $e): string
{
    $sql = "CASE {$e}.partner_type";
    foreach (FIN_PARTNER_SOURCES as $type => $src) {
        $sql .= " WHEN '{$type}' THEN (SELECT {$src['name']} FROM {$src['table']} p WHERE p.id = {$e}.partner_id)";
    }
    return $sql . ' END';
}

/**
 * Link from a ledger line (partner_type, partner_id, partner_name) to its
 * partner's statement, as HTML; '' when the line has no partner. $badge shows
 * it as a small label after other text, otherwise as a plain link.
 */
function fin_partner_link(array $line, bool $badge = true): string
{
    $key = fin_partner_key($line['partner_type'] ?? null, $line['partner_id'] ?? 0);
    if ($key === '') {
        return '';
    }
    $name = htmlspecialchars((string)($line['partner_name'] ?? '') !== '' ? (string)$line['partner_name'] : $key, ENT_QUOTES, 'UTF-8');
    $class = $badge ? ' class="badge rounded-pill text-bg-light border text-decoration-none ms-2"' : '';
    return '<a href="fin_partner_ledger.php?partner=' . rawurlencode($key) . '"' . $class . '>' . $name . '</a>';
}

/** "tenant:12" from a line's two columns, or '' when the line has no partner. */
function fin_partner_key($type, $id): string
{
    return $type !== null && $type !== '' && (int)$id > 0 ? $type . ':' . (int)$id : '';
}

/** ['tenant', 12] from "tenant:12", or null when it is not a partner key. */
function fin_partner_parse(string $key): ?array
{
    [$type, $id] = array_pad(explode(':', $key, 2), 2, '');
    if (!isset(FIN_PARTNER_SOURCES[$type]) || !ctype_digit($id) || (int)$id === 0) {
        return null;
    }
    return [$type, (int)$id];
}

/**
 * The company's tenants and suppliers: key ("tenant:12") => ['key', 'kind'
 * (tenant or supplier), 'name'], tenants first, each kind by name.
 */
function fin_partners(PDO $conn, int $companyId): array
{
    $out = [];
    foreach (FIN_PARTNER_SOURCES as $type => $src) {
        $stmt = $conn->prepare("SELECT p.id, {$src['name']} AS name FROM {$src['table']} p WHERE p.company_id = ?");
        $stmt->execute([$companyId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = $type . ':' . (int)$r['id'];
            $out[$key] = ['key' => $key, 'kind' => $src['kind'], 'name' => (string)$r['name']];
        }
    }
    uasort($out, static fn($a, $b) => [$a['kind'] !== 'tenant', mb_strtolower($a['name'])] <=> [$b['kind'] !== 'tenant', mb_strtolower($b['name'])]);
    return $out;
}

/**
 * What every partner with entries stands at on a date (that day included).
 * $kind narrows it to tenants or suppliers. Each row: key, kind, name, debit,
 * credit and balance; rows by kind, then name. 'totals' adds them up.
 */
function fin_partner_balances(PDO $conn, int $companyId, string $asAt, ?string $kind = null): array
{
    $stmt = $conn->prepare("
        SELECT partner_type, partner_id, SUM(debit) AS debit, SUM(credit) AS credit
          FROM fin_ledger_entries
         WHERE company_id = ? AND is_void = 0 AND partner_type IS NOT NULL AND entry_date <= ?
         GROUP BY partner_type, partner_id
    ");
    $stmt->execute([$companyId, $asAt]);
    $sums = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $sums[fin_partner_key($s['partner_type'], $s['partner_id'])] = $s;
    }

    $rows = [];
    $totals = ['debit' => 0.0, 'credit' => 0.0, 'balance' => 0.0];
    foreach (fin_partners($conn, $companyId) as $key => $p) {
        if (!isset($sums[$key]) || ($kind !== null && $p['kind'] !== $kind)) {
            continue;
        }
        $debit = round((float)$sums[$key]['debit'], 2);
        $credit = round((float)$sums[$key]['credit'], 2);
        $rows[] = $p + ['debit' => $debit, 'credit' => $credit, 'balance' => round($debit - $credit, 2)];
        $totals['debit'] = round($totals['debit'] + $debit, 2);
        $totals['credit'] = round($totals['credit'] + $credit, 2);
    }
    $totals['balance'] = round($totals['debit'] - $totals['credit'], 2);
    return ['rows' => $rows, 'totals' => $totals];
}

/**
 * Statement of one partner for a period (both dates included).
 * Returns null when the partner is not this company's, else ['partner' => key,
 * kind and name, 'opening', 'lines' (date, voucher, account, description, debit,
 * credit, running `balance`), 'debit', 'credit' and 'closing'].
 */
function fin_partner_ledger(PDO $conn, int $companyId, string $key, string $from, string $to): ?array
{
    $partner = fin_partners($conn, $companyId)[$key] ?? null;
    if (!$partner) {
        return null;
    }
    [$type, $id] = fin_partner_parse($key);

    $stmt = $conn->prepare("SELECT COALESCE(SUM(debit - credit), 0) FROM fin_ledger_entries WHERE company_id = ? AND partner_type = ? AND partner_id = ? AND is_void = 0 AND entry_date < ?");
    $stmt->execute([$companyId, $type, $id, $from]);
    $opening = round((float)$stmt->fetchColumn(), 2);

    $stmt = $conn->prepare("
        SELECT e.entry_date, e.description, e.debit, e.credit, e.account_id, a.code, a.name AS account_name,
               v.id AS voucher_id, v.voucher_no, v.type, v.reference
          FROM fin_ledger_entries e
          JOIN fin_vouchers v ON v.id = e.voucher_id
          JOIN fin_coa_accounts a ON a.id = e.account_id
         WHERE e.company_id = ? AND e.partner_type = ? AND e.partner_id = ? AND e.is_void = 0 AND e.entry_date BETWEEN ? AND ?
         ORDER BY e.entry_date, e.voucher_id, e.line_no, e.id
    ");
    $stmt->execute([$companyId, $type, $id, $from, $to]);
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
        'partner' => $partner,
        'opening' => $opening,
        'lines'   => $lines,
        'debit'   => round($debit, 2),
        'credit'  => round($credit, 2),
        'closing' => $balance,
    ];
}
