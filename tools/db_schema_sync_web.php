<?php
/**
 * Browser runner for the data-safe schema sync (for hosts without SSH, e.g. Hostinger).
 *
 * SECURITY — READ THIS:
 *   1) Edit SCHEMA_SYNC_TOKEN below to a long random string before uploading.
 *   2) Open it as:  https://yourdomain.com/tools/db_schema_sync_web.php?token=YOUR_TOKEN
 *   3) DELETE THIS FILE from the live server as soon as you are done.
 *
 * It only ever runs additive DDL (CREATE TABLE / ADD COLUMN / ADD KEY). It never
 * drops anything and never changes row data. Type/ENUM differences are shown but
 * only applied if you tick "Apply MODIFY for type differences".
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

// >>> CHANGE THIS before uploading <<<
const SCHEMA_SYNC_TOKEN = 'CHANGE_ME_TO_A_LONG_RANDOM_STRING';

require_once __DIR__ . '/db_schema_sync.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$token = $_GET['token'] ?? $_POST['token'] ?? '';
if (SCHEMA_SYNC_TOKEN === 'CHANGE_ME_TO_A_LONG_RANDOM_STRING') {
    http_response_code(403);
    exit('Refusing to run: set SCHEMA_SYNC_TOKEN to a private value in this file first.');
}
if (!hash_equals(SCHEMA_SYNC_TOKEN, (string)$token)) {
    http_response_code(403);
    exit('Forbidden: invalid or missing token.');
}

$f = function (string $k, string $d = '') { return trim((string)($_POST[$k] ?? $d)); };
$result = null; $error = null; $plan = null; $applyRes = null;
$doApply  = isset($_POST['do_apply']);
$emitModify = !empty($_POST['emit_modify']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $src = schema_sync_connect($f('src_host', 'localhost'), $f('src_db'), $f('src_user'), (string)($_POST['src_pass'] ?? ''));
        $dst = schema_sync_connect($f('dst_host', 'localhost'), $f('dst_db'), $f('dst_user'), (string)($_POST['dst_pass'] ?? ''));
        $plan = schema_sync_generate($src, $f('src_db'), $dst, $f('dst_db'), $emitModify);
        if ($doApply) {
            $applyRes = schema_sync_apply($dst, $plan['ddl']);
        }
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Schema Sync (data-safe)</title>
<style>
 body{font-family:system-ui,Arial,sans-serif;max-width:900px;margin:24px auto;padding:0 16px;color:#1e293b}
 h1{font-size:1.3rem} label{display:block;font-size:.85rem;font-weight:600;margin:.5rem 0 .2rem}
 input{width:100%;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px}
 .row{display:grep;display:grid;grid-template-columns:1fr 1fr;gap:12px}
 fieldset{border:1px solid #e2e8f0;border-radius:10px;margin:12px 0;padding:12px}
 .btn{display:inline-block;background:#2563eb;color:#fff;border:0;padding:.6rem 1rem;border-radius:8px;cursor:pointer;font-weight:600}
 .btn.danger{background:#dc2626}
 .warn{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 12px;border-radius:8px}
 .ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;padding:10px 12px;border-radius:8px}
 pre{background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;overflow:auto;max-height:340px;font-size:.8rem}
 .muted{color:#64748b;font-size:.85rem}
 .chk{display:flex;gap:8px;align-items:center;font-weight:600;margin-top:8px}
</style></head><body>
<h1>Data-safe Schema Sync</h1>
<p class="warn"><b>Additive only.</b> Creates missing tables/columns/indexes. Never drops anything, never edits row data.
<b>Back up your live DB first</b>, and <b>delete this file</b> when finished.</p>

<?php if ($error): ?><p class="warn">Error: <?= e($error) ?></p><?php endif; ?>

<form method="post">
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <fieldset><legend><b>SOURCE</b> — your up-to-date schema (e.g. the temp DB you imported your localhost structure into)</legend>
    <div class="row">
      <div><label>Host</label><input name="src_host" value="<?= e($f('src_host','localhost')) ?>"></div>
      <div><label>Database</label><input name="src_db" value="<?= e($f('src_db')) ?>" placeholder="schema_src"></div>
      <div><label>User</label><input name="src_user" value="<?= e($f('src_user')) ?>"></div>
      <div><label>Password</label><input name="src_pass" type="password" value=""></div>
    </div>
  </fieldset>
  <fieldset><legend><b>TARGET</b> — the database to update (your LIVE DB)</legend>
    <div class="row">
      <div><label>Host</label><input name="dst_host" value="<?= e($f('dst_host','localhost')) ?>"></div>
      <div><label>Database</label><input name="dst_db" value="<?= e($f('dst_db')) ?>" placeholder="live_db"></div>
      <div><label>User</label><input name="dst_user" value="<?= e($f('dst_user')) ?>"></div>
      <div><label>Password</label><input name="dst_pass" type="password" value=""></div>
    </div>
  </fieldset>
  <label class="chk"><input type="checkbox" name="emit_modify" value="1" <?= $emitModify ? 'checked' : '' ?>> Also apply MODIFY for type/ENUM differences (review first — widening is safe, narrowing can truncate)</label>
  <p style="margin-top:14px">
    <button class="btn" name="do_preview" value="1">Preview changes (no changes made)</button>
  </p>
</form>

<?php if ($plan): $r = $plan['report']; ?>
  <h2>Summary</h2>
  <p class="muted"><?= (int)$r['new_tables'] ?> new table(s), <?= (int)$r['new_columns'] ?> new column(s),
     <?= (int)$r['new_indexes'] ?> new index(es), <?= (int)$r['type_diffs'] ?> type difference(s).</p>

  <?php if (!empty($plan['type_diffs'])): ?>
    <p class="warn"><b>Type differences (not applied unless you tick MODIFY):</b><br>
      <?php foreach ($plan['type_diffs'] as $d): ?><?= e($d) ?><br><?php endforeach; ?></p>
  <?php endif; ?>

  <?php if ($applyRes): ?>
    <p class="ok"><b>Applied:</b> <?= (int)$applyRes['applied'] ?> statement(s), <?= (int)$applyRes['errors'] ?> error(s).</p>
    <?php if (!empty($applyRes['messages'])): ?>
      <pre><?php foreach ($applyRes['messages'] as $m) echo e($m) . "\n"; ?></pre>
    <?php endif; ?>
  <?php endif; ?>

  <h2>Generated SQL</h2>
  <pre><?= e($plan['sql']) ?></pre>

  <?php if (!$applyRes && !empty($plan['ddl'])): ?>
    <form method="post" onsubmit="return confirm('Apply these additive changes to the TARGET (live) database now? Make sure you have a backup.');">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <?php foreach (['src_host','src_db','src_user','dst_host','dst_db','dst_user'] as $k): ?>
        <input type="hidden" name="<?= $k ?>" value="<?= e($f($k)) ?>">
      <?php endforeach; ?>
      <input type="hidden" name="src_pass" value="<?= e((string)($_POST['src_pass'] ?? '')) ?>">
      <input type="hidden" name="dst_pass" value="<?= e((string)($_POST['dst_pass'] ?? '')) ?>">
      <input type="hidden" name="emit_modify" value="<?= $emitModify ? '1' : '' ?>">
      <button class="btn danger" name="do_apply" value="1">Apply to TARGET now</button>
      <span class="muted">Or copy the SQL above and run it in phpMyAdmin.</span>
    </form>
  <?php endif; ?>
<?php endif; ?>

</body></html>
