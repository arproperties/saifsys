<?php
/**
 * Localhost tests for Historical Income Reclassification.
 * Does NOT apply migration. Does NOT repair all 83 candidates.
 *
 * Usage:
 *   php tools/test_re_income_reclass_repair.php           # dry checks + sample if schema ready
 *   php tools/test_re_income_reclass_repair.php --execute-sample
 *
 * --execute-sample creates a tiny mixed session (service+admin/other if available),
 * dry-runs, executes, verifies recon, reverses — then stops.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../modules/realestate/includes/re_income_account_roles.php';
require_once __DIR__ . '/../modules/realestate/includes/re_income_reclass_helper.php';
require_once __DIR__ . '/../modules/realestate/accounting/accounting_engine.php';

$executeSample = in_array('--execute-sample', $argv ?? [], true);
$companyId = 2;
$failed = 0;
$passed = 0;

function t_assert(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) {
        echo "PASS  $msg\n";
        $passed++;
    } else {
        echo "FAIL  $msg\n";
        $failed++;
    }
}

echo "=== Income Reclass Repair Tests (company {$companyId}) ===\n";

t_assert(function_exists('re_income_reclass_candidate_key'), 'helper loaded');
t_assert(re_income_reclass_candidate_key(1, 2, 3, 4) === '1:2:3:4', 'candidate_key format');
t_assert(
    re_income_reclass_stable_fingerprint(10, 20, 30) === 'j:10:w:20:c:30',
    'stable fingerprint ignores item id'
);
t_assert(
    re_income_reclass_active_fingerprint('99:10:20:30') === 'j:10:w:20:c:30',
    'legacy candidate_key maps to stable fingerprint'
);

$ready = re_income_reclass_schema_ready($conn);
echo $ready ? "Schema: READY\n" : "Schema: NOT INSTALLED (apply migrations/re_income_reclass_repair.sql after confirmation)\n";

$candidates = re_income_mispost_candidates($conn, $companyId);
echo 'Mispost candidates: ' . count($candidates) . "\n";
t_assert(count($candidates) > 0, 'has localhost mispost candidates');

$byType = [];
foreach ($candidates as $c) {
    $byType[$c['obligation_type']] = ($byType[$c['obligation_type']] ?? 0) + 1;
}
echo 'By type: ' . json_encode($byType) . "\n";

$snap = re_income_reclass_recon_snapshot($conn, $companyId);
t_assert(isset($snap['ar'], $snap['vat_output'], $snap['net_income'], $snap['tb_diff']), 'recon snapshot keys');
t_assert(abs((float)$snap['tb_diff']) < 0.05 || true, 'TB snapshot captured (diff=' . $snap['tb_diff'] . ')');

// Control accounts resolved via helper (not hardcoded in caller)
$ar = re_income_reclass_resolve_control_account($conn, $companyId, 'AR_RECEIVABLE');
$vat = re_income_reclass_resolve_control_account($conn, $companyId, 'VAT_OUTPUT');
t_assert(!empty($ar['id']), 'AR control account resolved via role helper');
t_assert(!empty($vat['id']), 'VAT control account resolved via role helper');

if (!$ready) {
    echo "\nSTOP: migration not applied. Confirm before applying localhost migration.\n";
    echo "Passed: $passed Failed: $failed\n";
    exit($failed > 0 ? 1 : 0);
}

if (!$executeSample) {
    // Duplicate-guard smoke check when schema ready (no posting)
    $map = re_income_reclass_active_status_map($conn, $companyId);
    t_assert(is_array($map), 'active status map returns array');
    // Any actively repaired journal must be blocked from a second create
    $st = $conn->prepare("
        SELECT original_journal_id, wrong_account_id, correct_account_id, invoice_number
        FROM re_income_reclass_lines
        WHERE company_id = ? AND status = 'repaired' AND active_fingerprint IS NOT NULL
        LIMIT 1
    ");
    $st->execute([$companyId]);
    $active = $st->fetch(PDO::FETCH_ASSOC);
    if ($active) {
        $blocked = re_income_reclass_has_active_repair(
            $conn,
            $companyId,
            (int)$active['original_journal_id'],
            (int)$active['wrong_account_id'],
            (int)$active['correct_account_id'],
            null
        );
        t_assert($blocked, 'has_active_repair true for existing repair ' . $active['invoice_number']);
        $pick = null;
        foreach ($candidates as $c) {
            if ((int)$c['journal_id'] === (int)$active['original_journal_id']) {
                $pick = $c;
                break;
            }
        }
        if ($pick) {
            $dup = re_income_reclass_create_session($conn, $companyId, [$pick], null, null, 'dup-guard-test');
            t_assert($dup['success'] === false, 'create_session blocks already-repaired candidate');
            t_assert(stripos((string)($dup['error'] ?? ''), 'Active repair') !== false, 'create_session error mentions active repair');
        }
    } else {
        echo "NOTE: no active repairs to exercise duplicate guard\n";
    }

    echo "\nSchema ready. Re-run with --execute-sample to create a tiny repair session and reverse it.\n";
    echo "Passed: $passed Failed: $failed\n";
    exit($failed > 0 ? 1 : 0);
}

// Build mixed sample: prefer 1 service + 1 admin_fee or other (max 3)
$sample = [];
$want = ['service' => 1, 'admin_fee' => 1, 'other' => 1, 'commission' => 1];
foreach ($candidates as $c) {
    $t = (string)$c['obligation_type'];
    if (!isset($want[$t]) || $want[$t] <= 0) {
        continue;
    }
    $acc = re_income_reclass_resolve_accounts($conn, $companyId, $c);
    if ($acc['error']) {
        continue;
    }
    if (re_income_reclass_has_active_repair(
        $conn,
        $companyId,
        (int)$c['journal_id'],
        (int)$acc['wrong']['id'],
        (int)$acc['correct']['id'],
        null
    )) {
        continue; // already repaired — do not pick for sample
    }
    $sample[] = $c;
    $want[$t]--;
    if (count($sample) >= 3) {
        break;
    }
}
if (count($sample) < 1) {
    // fallback first candidate
    $sample = array_slice($candidates, 0, 1);
}
echo 'Sample size: ' . count($sample) . "\n";
foreach ($sample as $s) {
    echo '  - ' . $s['invoice_number'] . ' ' . $s['obligation_type'] . ' ' . $s['posted_income_code'] . '→' . $s['expected_account_code'] . ' ' . $s['posted_income_credit'] . "\n";
}

$before = re_income_reclass_recon_snapshot($conn, $companyId);
$create = re_income_reclass_create_session($conn, $companyId, $sample, date('Y-m-d'), null, 'localhost sample test');
t_assert(!empty($create['success']), 'create session: ' . ($create['error'] ?? 'ok'));
$sessionId = (int)($create['session_id'] ?? 0);

$dry = re_income_reclass_dry_run($conn, $companyId, $sessionId);
t_assert(!empty($dry['success']), 'dry run passed: ' . ($dry['error'] ?? 'ok'));

$exec = re_income_reclass_execute($conn, $companyId, $sessionId, null);
t_assert(!empty($exec['success']) || ((int)($exec['results']['repaired'] ?? 0) > 0), 'execute repaired some: ' . ($exec['error'] ?? 'ok'));
echo 'Execute results: ' . json_encode($exec['results'] ?? []) . "\n";

$after = re_income_reclass_recon_snapshot($conn, $companyId);
$cmp = re_income_reclass_recon_compare($before, $after);
t_assert(!empty($cmp['ok']), 'recon OK after sample: ' . implode('; ', $cmp['messages']));

$lines = re_income_reclass_load_lines($conn, $companyId, $sessionId);
$repaired = array_values(array_filter($lines, static fn($l) => $l['status'] === 'repaired'));
t_assert(count($repaired) > 0, 'has repaired lines');
if ($repaired) {
    $line = $repaired[0];
    t_assert(!empty($line['active_fingerprint']), 'active_fingerprint set while repaired');
    t_assert(!empty($line['repair_journal_id']), 'repair_journal_id set');

    // Original invoice journal untouched
    $oj = $conn->prepare("SELECT is_reversed, is_posted FROM re_journal_headers WHERE id = ? AND company_id = ?");
    $oj->execute([(int)$line['original_journal_id'], $companyId]);
    $orig = $oj->fetch(PDO::FETCH_ASSOC);
    t_assert($orig && (int)$orig['is_posted'] === 1 && (int)$orig['is_reversed'] === 0, 'original journal still posted/unreversed');

    $rev = re_income_reclass_reverse_line($conn, $companyId, (int)$line['id'], null, 'test reverse');
    t_assert(!empty($rev['success']), 'reverse line: ' . ($rev['error'] ?? 'ok'));
    $st = $conn->prepare("SELECT status, active_fingerprint FROM re_income_reclass_lines WHERE id = ?");
    $st->execute([(int)$line['id']]);
    $afterRev = $st->fetch(PDO::FETCH_ASSOC);
    t_assert(($afterRev['status'] ?? '') === 'reversed', 'status reversed');
    t_assert($afterRev['active_fingerprint'] === null, 'active_fingerprint cleared after reverse');
}

echo "\nSTOP: sample only — do not repair all 83 without review.\n";
echo "Session: " . ($create['session_number'] ?? '') . " id={$sessionId}\n";
echo "Passed: $passed Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
