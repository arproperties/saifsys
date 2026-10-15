<?php
declare(strict_types=1);

/**
 * Lease renewal service (modules/realestate/includes/renewal_service.php): the quote is
 * what create + convert really make, a quote keeps nothing, and convert does what the
 * Approve & Convert button did.
 *
 * Convert commits, so this runs only against a throwaway copy of the database, named
 * with a _test suffix:
 *
 *     mariadb -uroot -e "CREATE DATABASE saifsys_renewal_test"
 *     mariadb-dump -uroot u385648797_Mainsys | mariadb -uroot saifsys_renewal_test
 *     RENEWAL_TEST_DB=saifsys_renewal_test php tests/renewal_service_test.php
 */

$testDb = (string)getenv('RENEWAL_TEST_DB');
if (!preg_match('/_test$/', $testDb)) {
    fwrite(STDERR, "Set RENEWAL_TEST_DB to a throwaway copy of the database whose name ends in _test.\n");
    exit(2);
}
define('DB_NAME', $testDb);

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../modules/realestate/includes/renewal_service.php';

$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$failures = 0;
function check(string $what, $expected, $actual): void
{
    global $failures;
    if ($expected === $actual) {
        echo "ok   - {$what}\n";
        return;
    }
    $failures++;
    echo "FAIL - {$what}\n       expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
}
$count = static fn(string $table): int => (int)$conn->query("SELECT COUNT(*) FROM $table")->fetchColumn();

// An active lease ending in the next 120 days, paid by cheque, with no renewal under way.
$lease = $conn->query("
    SELECT l.* FROM re_leases l
    WHERE l.status = 'active' AND l.payment_method = 'cheque'
      AND l.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 120 DAY)
      AND NOT EXISTS (SELECT 1 FROM re_lease_renewal_workflows rw WHERE rw.lease_id = l.id AND rw.status NOT IN ('rejected', 'cancelled'))
    ORDER BY l.end_date LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$lease) {
    fwrite(STDERR, "No active cheque lease ending within 120 days without a renewal: nothing to test with.\n");
    exit(2);
}
$companyId = (int)$lease['company_id'];
$leaseId = (int)$lease['id'];
$userId = (int)$conn->query("SELECT id FROM `user` ORDER BY id LIMIT 1")->fetchColumn();
$current = (float)($lease['annual_rent'] ?: $lease['monthly_rent'] * 12);
$terms = ['increase_type' => 'fixed', 'increase_value' => 0, 'proposed_rent' => round($current * 1.05, 2), 'number_of_cheques' => 4, 'carry_ejari' => false];
echo "# lease #$leaseId ({$lease['lease_number']}), rent $current, ends {$lease['end_date']}\n";

// ---------- a quote keeps nothing ----------

$before = [$count('re_lease_renewal_workflows'), $count('re_leases'), $count('re_lease_installments'), $count('re_post_dated_cheques')];
$quote = re_renewal_quote($conn, $companyId, $userId, $leaseId, $terms);
check('a quote leaves no workflow, lease, installment or cheque behind', $before,
    [$count('re_lease_renewal_workflows'), $count('re_leases'), $count('re_lease_installments'), $count('re_post_dated_cheques')]);
check('the quote is at the rent asked', round($current * 1.05, 2), $quote['proposed_rent']);
check('the new lease starts the day after the old one ends', date('Y-m-d', strtotime($lease['end_date'] . ' +1 day')), $quote['start_date']);
check('four rent cheques, plus a fees cheque only when the fees are not in the first',
    true, count(array_filter($quote['cheques'], fn($c) => $c['type'] === 'rent')) === 4);
check('the cheques add up to their total', $quote['cheques_total'], round(array_sum(array_column($quote['cheques'], 'amount')), 2));
check('a quote inside another transaction is refused', 'A quote cannot be made inside another transaction.', (function () use ($conn, $companyId, $userId, $leaseId, $terms) {
    $conn->beginTransaction();
    try { re_renewal_quote($conn, $companyId, $userId, $leaseId, $terms); return ''; }
    catch (Exception $e) { return $e->getMessage(); }
    finally { $conn->rollBack(); }
})());

// ---------- create + convert make what the quote said ----------

$conn->beginTransaction();
$made = re_renewal_create($conn, $companyId, $userId, $leaseId, $terms);
$conn->commit();
$draft = $conn->query("SELECT * FROM re_leases WHERE id = " . (int)$made['new_lease_id'])->fetch(PDO::FETCH_ASSOC);
check('the draft lease is a renewal of the old one', [$leaseId, 'draft', 1], [(int)$draft['parent_lease_id'], $draft['status'], (int)$draft['is_renewal_lease']]);
check('carry_ejari false: the old Ejari number is not copied', null, $draft['ejari_registration_number']);

check('convert is refused before the tenant accepted', 'Approve & Convert is only allowed after tenant acceptance/signature, unless the current user is Owner/Admin.',
    (function () use ($conn, $companyId, $userId, $made) {
        try { re_renewal_convert($conn, $companyId, $userId, $made['workflow_id'], false); return ''; }
        catch (Exception $e) { return $e->getMessage(); }
    })());

$conn->prepare("UPDATE re_lease_renewal_workflows SET status = 'accepted' WHERE id = ?")->execute([$made['workflow_id']]);
$done = re_renewal_convert($conn, $companyId, $userId, $made['workflow_id'], false);
check('convert reports the leases it worked on', [$leaseId, $made['new_lease_id']], [$done['old_lease_id'], $done['new_lease_id']]);
check('the workflow is converted', 'converted', $conn->query("SELECT status FROM re_lease_renewal_workflows WHERE id = " . (int)$made['workflow_id'])->fetchColumn());

$rows = $conn->query("SELECT installment_date, amount FROM re_lease_installments WHERE lease_id = " . (int)$made['new_lease_id'] . " ORDER BY installment_date, id")->fetchAll(PDO::FETCH_ASSOC);
check('the converted lease has the cheques the quote promised', array_map(fn($c) => [$c['date'], $c['amount']], $quote['cheques']),
    array_map(fn($r) => [(string)$r['installment_date'], round((float)$r['amount'], 2)], $rows));
check('one post-dated cheque per installment', count($rows), (int)$conn->query("SELECT COUNT(*) FROM re_post_dated_cheques WHERE lease_id = " . (int)$made['new_lease_id'])->fetchColumn());
check('the new lease waits for its start date; the old one stays active until then',
    ['draft', 'active'], [
        $conn->query("SELECT status FROM re_leases WHERE id = " . (int)$made['new_lease_id'])->fetchColumn(),
        $conn->query("SELECT status FROM re_leases WHERE id = $leaseId")->fetchColumn(),
    ]);

echo $failures ? "\n$failures failed\n" : "\nall passed\n";
exit($failures ? 1 : 0);
