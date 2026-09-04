<?php
$root = dirname(__DIR__);
require_once $root . '/includes/db_connect.php';
require_once $root . '/includes/erp_expense_number.php';

$companies = $conn->query('SELECT DISTINCT company_id FROM erp_expense_headers')->fetchAll(PDO::FETCH_COLUMN);
foreach ($companies as $cid) {
    $cid = (int)$cid;
    $rows = $conn->prepare('SELECT id FROM erp_expense_headers WHERE company_id = ? AND (expense_number IS NULL OR expense_number = \'\') ORDER BY id');
    $rows->execute([$cid]);
    $ids = $rows->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) {
        erp_sync_expense_seq_from_headers($conn, $cid);
        continue;
    }
    $conn->beginTransaction();
    try {
        $stMax = $conn->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(expense_number, '-', -1) AS UNSIGNED)), 0) FROM erp_expense_headers WHERE company_id = ? AND expense_number LIKE 'EXP-%'");
        $stMax->execute([$cid]);
        $n = (int)$stMax->fetchColumn();
        $up = $conn->prepare('UPDATE erp_expense_headers SET expense_number = ? WHERE id = ? AND company_id = ?');
        foreach ($ids as $id) {
            $n++;
            $num = 'EXP-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
            $up->execute([$num, (int)$id, $cid]);
        }
        $conn->prepare('INSERT INTO erp_expense_seq (company_id, last_num) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_num = GREATEST(last_num, VALUES(last_num))')->execute([$cid, $n]);
        $conn->commit();
        echo "Company $cid: assigned " . count($ids) . " expense number(s).\n";
    } catch (Throwable $e) {
        $conn->rollBack();
        echo "Company $cid error: " . $e->getMessage() . "\n";
    }
}
echo "Done.\n";
