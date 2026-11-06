<?php
/**
 * Finance > Fixed Assets (fin_assets) and their monthly depreciation.
 *
 * An asset is written off straight-line over its useful life from its start
 * date, with nothing left at the end; part months count by days (see
 * fin_schedule_spread()). Each month's entry, Dr depreciation expense and
 * Cr accumulated depreciation, is made as pending and posted as a Fixed Journal
 * Voucher when Accounts approve it (fin_auto_entry_helper.php).
 *
 * post_from is the first month the system posts. Months before it count as
 * depreciated before the system, already in the opening balances.
 * Once a month is posted, the cost, life, dates and accounts are fixed; the
 * asset can still be renamed or stopped (depreciation ends on the stop date).
 * An asset can come from a supplier invoice (source_voucher_id).
 */

require_once __DIR__ . '/fin_auto_entry_helper.php';
require_once __DIR__ . '/fin_supplier_invoice_helper.php';

const FIN_ASSET_MAX_MONTHS = 600;

function fin_asset_ready(PDO $conn): bool
{
    $chk = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('fin_assets', 'fin_auto_entries')");
    $chk->execute();
    return (int)$chk->fetchColumn() === 2 && fin_si_ready($conn);
}

/** The asset of this company with its account codes and names, or null. */
function fin_asset_get(PDO $conn, int $companyId, int $id): ?array
{
    $stmt = $conn->prepare("
        SELECT a.*, CONCAT(aa.code, ' ', aa.name) AS asset_account, CONCAT(ac.code, ' ', ac.name) AS accum_account,
               CONCAT(ae.code, ' ', ae.name) AS expense_account, sv.voucher_no AS source_no
          FROM fin_assets a
          JOIN fin_coa_accounts aa ON aa.id = a.asset_account_id
          JOIN fin_coa_accounts ac ON ac.id = a.accum_account_id
          JOIN fin_coa_accounts ae ON ae.id = a.expense_account_id
          LEFT JOIN fin_vouchers sv ON sv.id = a.source_voucher_id
         WHERE a.id = ? AND a.company_id = ?");
    $stmt->execute([$id, $companyId]);
    $a = $stmt->fetch(PDO::FETCH_ASSOC);
    return $a ?: null;
}

/**
 * The asset's months: each schedule row with its `state` - 'before' (before
 * the system), 'posted', 'pending', 'skipped' or 'due later' - and, for an
 * entry made, `entry_id` and `voucher_no`.
 */
function fin_asset_schedule(PDO $conn, int $companyId, array $asset): array
{
    $made = [];
    foreach (fin_auto_entries($conn, $companyId, null, 'asset', (int)$asset['id']) as $e) {
        $made[$e['period']] = $e;
    }
    $cut = $asset['status'] === 'stopped' ? $asset['stop_date'] : null;
    $rows = fin_schedule_spread((float)$asset['cost'], (int)$asset['life_months'], $asset['start_date'], $cut);
    foreach ($rows as $i => $r) {
        $e = $made[$r['period']] ?? null;
        $rows[$i]['state'] = $e ? $e['status'] : ($r['period'] < $asset['post_from'] ? 'before' : 'due later');
        $rows[$i]['entry_id'] = $e ? (int)$e['id'] : null;
        $rows[$i]['voucher_id'] = $e ? $e['voucher_id'] : null;
        $rows[$i]['voucher_no'] = $e['voucher_no'] ?? null;
        // An entry already made keeps the amount it was made with.
        if ($e) {
            $rows[$i]['amount'] = (float)$e['amount'];
        }
    }
    return $rows;
}

/** Totals of a schedule: before the system, posted, and what is left (net book value). */
function fin_asset_totals(array $asset, array $schedule): array
{
    $before = $posted = 0.0;
    foreach ($schedule as $r) {
        if ($r['state'] === 'before') {
            $before += $r['amount'];
        } elseif ($r['state'] === 'posted') {
            $posted += $r['amount'];
        }
    }
    $before = round($before, 2);
    $posted = round($posted, 2);
    return ['before' => $before, 'posted' => $posted, 'book_value' => round((float)$asset['cost'] - $before - $posted, 2),
            'monthly' => round((float)$asset['cost'] / (int)$asset['life_months'], 2)];
}

/** The company's assets, newest first, each with its schedule totals. */
function fin_asset_list(PDO $conn, int $companyId): array
{
    $stmt = $conn->prepare("SELECT id FROM fin_assets WHERE company_id = ? ORDER BY start_date DESC, id DESC");
    $stmt->execute([$companyId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $a = fin_asset_get($conn, $companyId, (int)$id);
        $a['totals'] = fin_asset_totals($a, fin_asset_schedule($conn, $companyId, $a));
        $out[] = $a;
    }
    return $out;
}

/** How many months of an asset are posted. */
function fin_asset_posted_count(PDO $conn, int $companyId, int $id): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) FROM fin_auto_entries WHERE company_id = ? AND source_type = 'asset' AND source_id = ? AND status = 'posted'");
    $stmt->execute([$companyId, $id]);
    return (int)$stmt->fetchColumn();
}

/**
 * Check an asset as entered. $data: name, asset_account_id, accum_account_id,
 * expense_account_id, cost, life_months, start_date, post_from (YYYY-MM),
 * source_voucher_id (optional), notes. Returns the row to save.
 */
function fin_asset_prepare(PDO $conn, int $companyId, array $data): array
{
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 150) {
        throw new InvalidArgumentException('Enter the asset name (150 characters at most).');
    }
    $accounts = array_column(fin_voucher_ledger_accounts($conn, $companyId), null, 'id');
    $ids = [];
    foreach (['asset_account_id' => 'the asset account', 'accum_account_id' => 'the accumulated depreciation account', 'expense_account_id' => 'the depreciation expense account'] as $k => $label) {
        $ids[$k] = (int)($data[$k] ?? 0);
        if (!isset($accounts[$ids[$k]])) {
            throw new InvalidArgumentException('Choose ' . $label . '.');
        }
    }
    if (count(array_unique($ids)) < 3) {
        throw new InvalidArgumentException('The asset, accumulated depreciation and expense accounts must be three different accounts.');
    }
    $cost = fin_voucher_parse_amount($data['cost'] ?? '');
    if ($cost === null || $cost <= 0 || $cost > FIN_VOUCHER_MAX_AMOUNT) {
        throw new InvalidArgumentException('Enter the purchase cost.');
    }
    $life = (int)($data['life_months'] ?? 0);
    if ($life < 1 || $life > FIN_ASSET_MAX_MONTHS) {
        throw new InvalidArgumentException('Enter the useful life (1 month to 50 years).');
    }
    $start = trim((string)($data['start_date'] ?? ''));
    if (!fin_voucher_valid_date($start)) {
        throw new InvalidArgumentException('Enter the depreciation start date.');
    }
    $postFrom = trim((string)($data['post_from'] ?? ''));
    if ($postFrom === '') {
        $postFrom = substr($start, 0, 7);
    }
    if (!preg_match('/^\d{4}-\d{2}$/', $postFrom) || !fin_voucher_valid_date($postFrom . '-01')) {
        throw new InvalidArgumentException('Choose the first month to post.');
    }
    $postFrom .= '-01';
    if ($postFrom < substr($start, 0, 7) . '-01' || $postFrom > fin_schedule_end($start, $life)) {
        throw new InvalidArgumentException('The first month to post must fall within the useful life.');
    }
    $source = (int)($data['source_voucher_id'] ?? 0);
    if ($source) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM fin_vouchers WHERE id = ? AND company_id = ? AND type = 'si' AND status = 'posted'");
        $stmt->execute([$source, $companyId]);
        if (!(int)$stmt->fetchColumn()) {
            throw new InvalidArgumentException('The supplier invoice of this asset was not found.');
        }
    }
    $notes = trim((string)($data['notes'] ?? ''));
    if (mb_strlen($notes) > 255) {
        throw new InvalidArgumentException('The notes are too long (255 characters at most).');
    }
    return $ids + ['name' => $name, 'cost' => $cost, 'life_months' => $life, 'start_date' => $start, 'post_from' => $postFrom,
                   'source_voucher_id' => $source ?: null, 'notes' => $notes !== '' ? $notes : null];
}

/** Add an asset and make its months that are already due. Returns its id. */
function fin_asset_create(PDO $conn, int $companyId, array $data, ?int $userId, ?string $today = null): int
{
    $p = fin_asset_prepare($conn, $companyId, $data);
    $conn->prepare("INSERT INTO fin_assets (company_id, name, asset_account_id, accum_account_id, expense_account_id, cost, life_months, start_date, post_from, source_voucher_id, notes, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$companyId, $p['name'], $p['asset_account_id'], $p['accum_account_id'], $p['expense_account_id'], $p['cost'], $p['life_months'], $p['start_date'], $p['post_from'], $p['source_voucher_id'], $p['notes'], $userId]);
    $id = (int)$conn->lastInsertId();
    fin_asset_generate($conn, $companyId, $today ?? date('Y-m-d'), $id);
    return $id;
}

/**
 * Change an asset. Before any month is posted everything can change, and the
 * months not yet decided are made again. After, only the name and notes.
 */
function fin_asset_update(PDO $conn, int $companyId, int $id, array $data, ?int $userId, ?string $today = null): void
{
    $a = fin_asset_get($conn, $companyId, $id);
    if (!$a) {
        throw new InvalidArgumentException('Asset not found in this company.');
    }
    $ownTx = !$conn->inTransaction();
    if ($ownTx) {
        $conn->beginTransaction();
    }
    try {
        if (fin_asset_posted_count($conn, $companyId, $id) > 0) {
            $name = trim((string)($data['name'] ?? ''));
            $notes = trim((string)($data['notes'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150 || mb_strlen($notes) > 255) {
                throw new InvalidArgumentException('Enter the asset name (150 characters at most) and notes of 255 at most.');
            }
            $conn->prepare("UPDATE fin_assets SET name = ?, notes = ?, updated_by = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$name, $notes !== '' ? $notes : null, $userId, $id]);
        } else {
            $p = fin_asset_prepare($conn, $companyId, $data + ['source_voucher_id' => $a['source_voucher_id']]);
            $conn->prepare("UPDATE fin_assets SET name = ?, asset_account_id = ?, accum_account_id = ?, expense_account_id = ?, cost = ?, life_months = ?, start_date = ?, post_from = ?, notes = ?, updated_by = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$p['name'], $p['asset_account_id'], $p['accum_account_id'], $p['expense_account_id'], $p['cost'], $p['life_months'], $p['start_date'], $p['post_from'], $p['notes'], $userId, $id]);
            // Nothing posted yet: the months not decided are made again from the new figures.
            $conn->prepare("DELETE FROM fin_auto_entries WHERE company_id = ? AND source_type = 'asset' AND source_id = ? AND status = 'pending'")->execute([$companyId, $id]);
            fin_asset_generate($conn, $companyId, $today ?? date('Y-m-d'), $id);
        }
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
 * Stop depreciating (sold, scrapped): the stop month counts its days up to the
 * stop date and later months are dropped. Months already posted after the stop
 * date must be deleted first. $stopDate null starts it again.
 */
function fin_asset_stop(PDO $conn, int $companyId, int $id, ?string $stopDate, ?int $userId, ?string $today = null): void
{
    $a = fin_asset_get($conn, $companyId, $id);
    if (!$a) {
        throw new InvalidArgumentException('Asset not found in this company.');
    }
    if ($stopDate !== null) {
        if (!fin_voucher_valid_date($stopDate) || $stopDate < $a['start_date']) {
            throw new InvalidArgumentException('Enter a stop date on or after the start date.');
        }
        $stmt = $conn->prepare("SELECT COUNT(*) FROM fin_auto_entries WHERE company_id = ? AND source_type = 'asset' AND source_id = ? AND status = 'posted' AND period >= ?");
        $stmt->execute([$companyId, $id, substr($stopDate, 0, 7) . '-01']);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new InvalidArgumentException('Months from the stop month on are already posted. Delete those Fixed Journal Vouchers first.');
        }
    }
    $ownTx = !$conn->inTransaction();
    if ($ownTx) {
        $conn->beginTransaction();
    }
    try {
        $conn->prepare("UPDATE fin_assets SET status = ?, stop_date = ?, updated_by = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$stopDate !== null ? 'stopped' : 'active', $stopDate, $userId, $id]);
        $from = $stopDate !== null ? substr($stopDate, 0, 7) . '-01' : '0000-01-01';
        $conn->prepare("DELETE FROM fin_auto_entries WHERE company_id = ? AND source_type = 'asset' AND source_id = ? AND status <> 'posted' AND period >= ?")
            ->execute([$companyId, $id, $from]);
        fin_asset_generate($conn, $companyId, $today ?? date('Y-m-d'), $id);
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

/** Delete an asset that has no posted month, with its pending and skipped months. */
function fin_asset_delete(PDO $conn, int $companyId, int $id): void
{
    if (!fin_asset_get($conn, $companyId, $id)) {
        throw new InvalidArgumentException('Asset not found in this company.');
    }
    if (fin_asset_posted_count($conn, $companyId, $id) > 0) {
        throw new InvalidArgumentException('Months of this asset are posted. Stop it instead, or delete those Fixed Journal Vouchers first.');
    }
    $conn->prepare("DELETE FROM fin_auto_entries WHERE company_id = ? AND source_type = 'asset' AND source_id = ?")->execute([$companyId, $id]);
    $conn->prepare("DELETE FROM fin_assets WHERE id = ? AND company_id = ?")->execute([$id, $companyId]);
}

/**
 * Make the pending depreciation months that are due by $today, for all of a
 * company's assets or one ($assetId). Returns how many were made.
 */
function fin_asset_generate(PDO $conn, int $companyId, string $today, ?int $assetId = null): int
{
    if (!fin_asset_ready($conn)) {
        return 0;
    }
    $sql = "SELECT id FROM fin_assets WHERE company_id = ?";
    $args = [$companyId];
    if ($assetId !== null) {
        $sql .= " AND id = ?";
        $args[] = $assetId;
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute($args);
    $n = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $a = fin_asset_get($conn, $companyId, (int)$id);
        $cut = $a['status'] === 'stopped' ? $a['stop_date'] : null;
        foreach (fin_schedule_spread((float)$a['cost'], (int)$a['life_months'], $a['start_date'], $cut) as $r) {
            if ($r['period'] < $a['post_from'] || $r['date'] > $today) {
                continue;
            }
            $label = date('M Y', strtotime($r['period']));
            $n += fin_auto_add($conn, $companyId, 'asset', (int)$a['id'], $r['period'], $r['date'], $r['amount'],
                'Depreciation ' . $a['name'] . ' - ' . $label, [
                    ['account_id' => (int)$a['expense_account_id'], 'description' => '', 'debit' => (string)$r['amount'], 'credit' => ''],
                    ['account_id' => (int)$a['accum_account_id'], 'description' => '', 'debit' => '', 'credit' => (string)$r['amount']],
                ]);
        }
    }
    return $n;
}

/**
 * What an asset form starts with when it comes from a supplier invoice: the
 * description as name, the amount before VAT as cost, the invoice date as start
 * and the account of its first line as the asset account.
 */
function fin_asset_from_invoice(PDO $conn, int $companyId, int $voucherId): ?array
{
    $v = fin_voucher_get($conn, $companyId, $voucherId);
    $si = $v && $v['type'] === 'si' && $v['status'] === 'posted' ? fin_si_get($conn, $companyId, $voucherId) : null;
    if (!$si) {
        return null;
    }
    return [
        'name' => mb_substr((string)$v['narration'], 0, 150),
        'asset_account_id' => (int)$v['lines'][0]['account_id'],
        'cost' => number_format((float)$si['net_amount'], 2, '.', ''),
        'start_date' => $v['voucher_date'],
        'source_voucher_id' => $voucherId,
        'source_no' => $v['voucher_no'],
    ];
}

/** Assets made from a supplier invoice. */
function fin_assets_of_invoice(PDO $conn, int $companyId, int $voucherId): array
{
    $stmt = $conn->prepare("SELECT id, name FROM fin_assets WHERE company_id = ? AND source_voucher_id = ? ORDER BY id");
    $stmt->execute([$companyId, $voucherId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The accumulated depreciation and expense accounts last used with an asset
 * account, so the form can suggest them: asset account id => [accum, expense].
 */
function fin_asset_last_accounts(PDO $conn, int $companyId): array
{
    $stmt = $conn->prepare("SELECT asset_account_id, accum_account_id, expense_account_id FROM fin_assets WHERE company_id = ? ORDER BY id");
    $stmt->execute([$companyId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['asset_account_id']] = [(int)$r['accum_account_id'], (int)$r['expense_account_id']];
    }
    return $out;
}

/** "5 years", "18 months", "2 years 6 months". */
function fin_asset_life_label(int $months): string
{
    $y = intdiv($months, 12);
    $m = $months % 12;
    $parts = [];
    if ($y) {
        $parts[] = $y . ($y === 1 ? ' year' : ' years');
    }
    if ($m) {
        $parts[] = $m . ($m === 1 ? ' month' : ' months');
    }
    return implode(' ', $parts);
}
