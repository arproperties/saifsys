<?php
/**
 * ARS move-in / move-out inspection tests.
 *
 *   php tests/ars_move_inspection_test.php
 *       Builds its own throwaway database (test_ars_move_inspection, dropped
 *       and recreated on every run). Touches no real data.
 */

declare(strict_types=1);

const TEST_DB = 'test_ars_move_inspection';

$failed = 0;
$passed = 0;

function check(bool $cond, string $label): void
{
    global $failed, $passed;
    echo ($cond ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    $cond ? $passed++ : $failed++;
}

define('DB_NAME', TEST_DB);
@require_once __DIR__ . '/../includes/config.php'; // its own DB_NAME define is ignored
$root = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec('DROP DATABASE IF EXISTS ' . TEST_DB);
$root->exec('CREATE DATABASE ' . TEST_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$conn = new PDO('mysql:host=' . DB_HOST . ';dbname=' . TEST_DB . ';charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
check($conn->query('SELECT DATABASE()')->fetchColumn() === TEST_DB, 'connected to throwaway database');

require_once __DIR__ . '/../modules/ars/includes/ars_move_inspection.php';

$conn->exec("CREATE TABLE `user` (id INT PRIMARY KEY, fullname VARCHAR(100))");
$conn->exec("INSERT INTO `user` VALUES (7, 'Test Staff')");
ars_move_inspection_ensure_schema($conn);

// ---- Templates -------------------------------------------------------------
$in = ars_move_inspection_templates($conn, 1, 'in');
$out = ars_move_inspection_templates($conn, 1, 'out');
check(count($in) === 8 && count($out) === 6, 'default checklists are created the first time');
check(count(ars_move_inspection_templates($conn, 1, 'in')) === 8, 'defaults are not added twice');
$conn->prepare("UPDATE ars_move_checklist_templates SET is_active = 0 WHERE id = ?")->execute([$in[7]['id']]);
check(count(ars_move_inspection_templates($conn, 1, 'in')) === 7, 'an item turned off is left out');

// ---- Opening an inspection -------------------------------------------------
$a = ars_move_inspection_get_or_create($conn, 1, 500, 'in', 7);
$b = ars_move_inspection_get_or_create($conn, 1, 500, 'in', 7);
check((int)$a['id'] > 0 && (int)$a['id'] === (int)$b['id'], 'opening twice gives the same inspection');
$items = ars_move_inspection_items($conn, (int)$a['id']);
check(count($items) === 7, 'checklist is copied once, without the item turned off');
$o = ars_move_inspection_get_or_create($conn, 1, 500, 'out', 7);
check((int)$o['id'] !== (int)$a['id'] && count(ars_move_inspection_items($conn, (int)$o['id'])) === 6, 'move-out is a separate inspection with its own items');

$conn->prepare("UPDATE ars_move_checklist_templates SET item_name = 'Changed' WHERE id = ?")->execute([$in[0]['id']]);
check(ars_move_inspection_items($conn, (int)$a['id'])[0]['item_name'] !== 'Changed', 'editing the template does not change a started inspection');

check(ars_move_inspection_find($conn, 2, 500, 'in') === null, 'another company cannot see the inspection');

// ---- Ticking and completing ------------------------------------------------
$r = ars_move_inspection_complete($conn, $a, 7);
check($r['success'] === false, 'cannot complete while required items are unticked');

$r = ars_move_inspection_save_item($conn, $a, (int)$items[0]['id'], true, '  key card x2  ', 7);
$first = ars_move_inspection_items($conn, (int)$a['id'])[0];
check($r['success'] && (int)$first['is_completed'] === 1 && $first['remarks'] === 'key card x2' && $first['completed_by_name'] === 'Test Staff',
    'ticking saves the tick, the remarks and who ticked');
ars_move_inspection_save_item($conn, $a, (int)$items[0]['id'], false, '', 7);
$first = ars_move_inspection_items($conn, (int)$a['id'])[0];
check((int)$first['is_completed'] === 0 && $first['completed_at'] === null, 'unticking clears it');
check(ars_move_inspection_save_item($conn, $a, (int)$items[0]['id'], true, '', 7)['success']
    && ars_move_inspection_save_item($conn, $o, (int)$items[0]['id'], true, '', 7)['success'] === false,
    'an item cannot be ticked through another inspection');

foreach ($items as $it) {
    if ((int)$it['is_required'] === 1) {
        ars_move_inspection_save_item($conn, $a, (int)$it['id'], true, '', 7);
    }
}
$p = ars_move_inspection_progress($conn, (int)$a['id']);
check($p['required_left'] === 0 && $p['done'] === 6 && $p['total'] === 7, 'progress counts ticks; optional items may stay open');

$sum = ars_move_inspection_summary($conn, 1, [500, 501]);
check(ars_move_inspection_summary_label($sum['500:in'] ?? null) === '6 of 7'
    && ars_move_inspection_summary_label($sum['501:in'] ?? null) === 'Not started', 'report label shows progress or Not started');

check(ars_move_inspection_complete($conn, $a, 7)['success'], 'completes once required items are ticked');
$a = ars_move_inspection_find($conn, 1, 500, 'in');
check($a['status'] === 'completed' && $a['completed_by_name'] === 'Test Staff', 'completed state and who completed are saved');
check(ars_move_inspection_save_item($conn, $a, (int)$items[0]['id'], false, '', 7)['success'] === false, 'a completed checklist is locked');
check(ars_move_inspection_summary_label(ars_move_inspection_summary($conn, 1, [500])['500:in']) === 'Done', 'report label shows Done');

// ---- Photos ----------------------------------------------------------------
$tmp = tempnam(sys_get_temp_dir(), 'insp');
file_put_contents($tmp, '<?php echo 1;');
$r = ars_move_inspection_photo_upload($conn, $o, ['name' => 'evil.php', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 13], '', 7);
check($r['success'] === false, 'a non-photo file type is refused');
$r = ars_move_inspection_photo_upload($conn, $o, ['name' => 'evil.jpg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 13], '', 7);
check($r['success'] === false, 'a script renamed to .jpg is refused');
$r = ars_move_inspection_photo_upload($conn, $o, ['name' => 'x.jpg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0], '', 7);
check($r['success'] === false && (int)$conn->query('SELECT COUNT(*) FROM ars_move_inspection_photos')->fetchColumn() === 0,
    'a failed upload reports failure and saves nothing');
unlink($tmp);

$conn->prepare("
    INSERT INTO ars_move_inspection_photos (company_id, inspection_id, original_name, stored_name, relative_path, file_size)
    VALUES (1, ?, 'room.jpg', 'abc.jpg', 'uploads/ars_move_inspections/1/0/missing.jpg', 10)
")->execute([(int)$o['id']]);
$photoId = (int)$conn->lastInsertId();
check(ars_move_inspection_photo_resolve($conn, 2, $photoId)['success'] === false, 'another company cannot open the photo');
check(ars_move_inspection_photo_resolve($conn, 1, $photoId)['success'] === false, 'a photo missing from disk is reported, not served');
check(ars_move_inspection_photo_delete($conn, $a, $photoId)['success'] === false, 'a photo cannot be removed through another inspection');
check(ars_move_inspection_photo_delete($conn, $o, $photoId)['success'] === true, 'a photo on an open inspection can be removed');

$root->exec('DROP DATABASE IF EXISTS ' . TEST_DB);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
