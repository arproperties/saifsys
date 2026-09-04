<?php
/**
 * Key Money activation smoke test (company 3).
 * Usage: php modules/construction/tools/key_money_smoke.php
 * Requires: XAMPP MySQL + migrations/construction_shop_rental_phase_key_money.sql applied.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/includes/db_connect.php';
require_once $root . '/modules/construction/includes/construction_shop_rental_helpers.php';
require_once $root . '/modules/construction/includes/construction_shop_rental_charge_helpers.php';
require_once $root . '/modules/construction/includes/construction_receipt_allocation_service.php';

if (!is_link('/tmp/mysql.sock') && !file_exists('/tmp/mysql.sock')) {
    @symlink('/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock', '/tmp/mysql.sock');
}

$companyId = 3;
$userId = 1;
$passed = 0;
$failed = 0;

function km_assert(bool $ok, string $label, $meta = null): void {
    global $passed, $failed;
    if ($ok) {
        echo "PASS  {$label}\n";
        $passed++;
    } else {
        echo "FAIL  {$label}" . ($meta !== null ? ' ' . json_encode($meta) : '') . "\n";
        $failed++;
    }
}

echo "=== Key Money smoke (company {$companyId}) ===\n";

km_assert(co_shop_charges_schema_ready($conn), 'charges schema ready');
km_assert(co_db_column_exists($conn, 'co_shop_contract_charges', 'invoice_timing'), 'invoice_timing column exists (run key_money migration)');

co_shop_seed_charge_types($conn, $companyId);
$type = co_shop_charge_type_by_code($conn, $companyId, 'key_money');
km_assert($type && (int)$type['invoicing_enabled'] === 1, 'key_money invoicing_enabled=1', $type);
km_assert($type && (($type['default_coa_code'] ?? '') === '4170' || ($type['default_coa_code'] ?? '') !== ''), 'key_money default COA set', $type);

$acct = co_shop_ensure_key_money_income_account($conn, $companyId);
km_assert($acct && ($acct['account_code'] ?? '') === '4170', 'COA 4170 Key Money Income exists', $acct);

// Pick an active contract without key money invoice (or create charge on contract 8)
$contractId = 8;
$stmt = $conn->prepare("SELECT * FROM co_shop_rental_contracts WHERE id = ? AND company_id = ?");
$stmt->execute([$contractId, $companyId]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);
km_assert((bool)$contract, 'fixture contract 8 exists');

if ($contract) {
    // Snapshot existing KM invoice if any — use a unique notes tag for this run
    $tag = 'KM-SMOKE-' . date('YmdHis');
    $existing = co_shop_key_money_invoice($conn, $companyId, $contractId);
    if ($existing) {
        echo "INFO  Contract 8 already has Key Money invoice #{$existing['id']} — validating status/summary only\n";
        $st = co_shop_key_money_status($conn, $companyId, $contractId);
        km_assert(in_array($st['status'], ['invoiced', 'partial', 'collected'], true), 'key_money status invoiced/partial/collected', $st);
        km_assert(isset($st['invoiced'], $st['collected'], $st['outstanding']), 'status has invoiced/collected/outstanding');
        $summary = co_shop_contract_financial_summary($conn, $companyId, $contractId);
        km_assert(!empty($summary['key_money']), 'financial summary has key_money block');
        $opens = co_receipt_open_invoices_for_contract($conn, $companyId, $contractId);
        $hasKm = false;
        foreach ($opens as $o) {
            if (($o['kind'] ?? '') === 'key_money' || stripos((string)($o['kind_label'] ?? ''), 'Key Money') !== false) {
                $hasKm = true;
                break;
            }
        }
        // Open list only if still outstanding
        if (($st['outstanding'] ?? 0) > 0.005) {
            km_assert($hasKm, 'workspace open invoices include Key Money when outstanding');
        } else {
            km_assert(true, 'Key Money fully collected — open list skip OK');
        }
        // Journal credit to 4170
        $jid = (int)($existing['journal_id'] ?? 0);
        if ($jid > 0) {
            $jl = $conn->prepare("
                SELECT a.account_code, l.credit_amount AS credit
                FROM re_journal_lines l
                JOIN re_chart_of_accounts a ON a.id = l.account_id AND a.company_id = l.company_id
                WHERE l.journal_id = ? AND l.company_id = ? AND l.credit_amount > 0
            ");
            $jl->execute([$jid, $companyId]);
            $credits = $jl->fetchAll(PDO::FETCH_ASSOC);
            $has4170 = false;
            $has4120Only = true;
            foreach ($credits as $c) {
                if (($c['account_code'] ?? '') === '4170') {
                    $has4170 = true;
                    $has4120Only = false;
                }
                if (($c['account_code'] ?? '') !== '4120') {
                    $has4120Only = false;
                }
            }
            km_assert($has4170, 'invoice journal credits Key Money income 4170', $credits);
            km_assert(!$has4120Only, 'Key Money not posted only to rental 4120', $credits);
        }
    } else {
        $res = co_shop_apply_key_money_settings($conn, $companyId, $contractId, [
            'key_money_enabled' => 1,
            'key_money_amount' => 1000,
            'key_money_vat_mode' => 'exclusive',
            'key_money_vat_rate' => 5,
            'key_money_coa' => '4170',
            'key_money_notes' => $tag,
            'key_money_invoice_timing' => 'immediate',
        ], $userId, true);
        km_assert(!empty($res['generated']) && (int)$res['invoice_id'] > 0, 'immediate generate created invoice', $res);
        $inv = co_shop_key_money_invoice($conn, $companyId, $contractId);
        km_assert($inv && abs((float)$inv['subtotal'] - 1000) < 0.02, 'invoice net 1000', $inv);
        km_assert($inv && abs((float)$inv['vat_amount'] - 50) < 0.02, 'invoice VAT 50', $inv);
        $st = co_shop_key_money_status($conn, $companyId, $contractId);
        km_assert(($st['status'] ?? '') === 'invoiced', 'status invoiced after generate', $st);
        km_assert(abs(($st['outstanding'] ?? 0) - 1050) < 0.02, 'outstanding 1050', $st);
        $opens = co_receipt_open_invoices_for_contract($conn, $companyId, $contractId);
        $hasKm = false;
        foreach ($opens as $o) {
            if (($o['kind'] ?? '') === 'key_money') {
                $hasKm = true;
                break;
            }
        }
        km_assert($hasKm, 'workspace lists Key Money open invoice');

        // Timing on_start block test on a temporary amount path — re-save timing only if not invoiced
        // Already invoiced — skip regenerate. Test generation_due helper with synthetic timing check:
        km_assert(!co_shop_key_money_generation_due($conn, $companyId, $contractId), 'generation not due after invoiced');
    }

    $summary = co_shop_contract_financial_summary($conn, $companyId, $contractId);
    km_assert(isset($summary['key_money']['status']), 'summary.key_money.status present', $summary['key_money'] ?? null);
}

// Isolation: no RE allocation engine from construction charge helpers file
$src = file_get_contents($root . '/modules/construction/includes/construction_shop_rental_charge_helpers.php');
km_assert(strpos($src, 'receipt_allocation_engine.php') === false, 'charge helpers do not include RE receipt_allocation_engine');

echo "\n=== SUMMARY ===\nPassed: {$passed}\nFailed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
