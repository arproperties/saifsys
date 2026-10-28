<?php
// Prove the re-link on local inside a transaction, then roll back.
// Usage: php test_relink.example.php   (edit the CHANGE ME block first)
$root = '/Users/ainalreem/Sites/saifsys';
require $root . '/includes/config.php';
require $root . '/includes/db_connect.php';
foreach (['obligation_engine.php', 'receipt_allocation_engine.php', 'receipt_multi_cheque_helper.php'] as $f) {
    require_once $root . '/modules/realestate/includes/' . $f;
}
foreach (glob($root . '/modules/realestate/includes/cheque_*.php') as $f) {
    require_once $f;
}
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---- CHANGE ME ----
$companyId = 2;
$leaseId = 355;
$watchCheques = [5747, 5748];          // the cheque being fixed + the next one (must not change)
$receiptIds = [2923, 2924];
$sqlFile = $root . '/live_hotfix/relink_PARKPLAC-501-0001_receipts_to_CHQ-355-1.sql';
// -------------------

$show = function (string $label) use ($conn, $companyId, $leaseId, $watchCheques) {
    $s = re_lease_cheque_receipt_summaries($conn, $companyId, $leaseId, false); // false = don't write links
    foreach ($watchCheques as $id) {
        $r = $s[$id] ?? [];
        printf("%s cheque %d: collected=%s remaining=%s status=%s\n", $label, $id,
            $r['collected_total'] ?? '-', $r['remaining'] ?? '-', $r['display_status'] ?? '-');
    }
};
// The file holds one UPDATE (comments stripped).
$update = trim(preg_replace('/^\s*--.*$/m', '', file_get_contents($sqlFile)));

$conn->beginTransaction();
$show('BEFORE');
echo 'run 1 rows: ' . $conn->exec($update) . "\n";
$show('AFTER ');
echo 'run 2 rows (must be 0): ' . $conn->exec($update) . "\n";
$conn->rollBack();
$in = implode(',', array_map('intval', $receiptIds));
echo "rolled back; cheque_ids now: ";
print_r($conn->query("SELECT id, cheque_id FROM re_payments WHERE id IN ($in)")->fetchAll(PDO::FETCH_KEY_PAIR));
