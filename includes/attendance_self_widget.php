<?php
/**
 * The check-in pop-up and the check-out bar.
 *
 * Include this once, just inside <body>, on any page that should offer it:
 *
 *     require __DIR__ . '/includes/attendance_self_widget.php';
 *
 * It draws itself and nothing else. Everything it needs to decide comes from
 * attendance_self_state(), so it is safe to include on a page where the
 * feature is off, the user has no employee record, or the day is already
 * recorded — in all of those it prints nothing at all.
 *
 * The styles are inline on purpose: this has to look right on thirteen
 * different module layouts without depending on any of their stylesheets.
 */

if (!isset($conn) || !($conn instanceof PDO)) {
    return;
}

require_once __DIR__ . '/attendance_self.php';
require_once __DIR__ . '/url_helper.php';

$asState = attendance_self_state($conn);
if ($asState['stage'] === 'n/a' || $asState['stage'] === 'excused') {
    return;
}

$asFlash = $_SESSION['attendance_self_flash'] ?? null;
unset($_SESSION['attendance_self_flash']);

$asRoot     = function_exists('get_application_web_root') ? get_application_web_root() : '';
$asEndpoint = ($asRoot !== '' ? $asRoot : '') . '/api/attendance_self.php';
$asBack     = (string)($_SERVER['REQUEST_URI'] ?? '');
if ($asBack === '' || $asBack[0] !== '/') {
    $asBack = ($asRoot !== '' ? $asRoot : '') . '/select-module';
}

$asName = trim((string)($asState['employee']['full_name'] ?? ''));
$asFirst = $asName !== '' ? explode(' ', $asName)[0] : '';

$asMoment = attendance_self_now();
$asHour = (int)$asMoment->format('G');
$asGreeting = $asHour < 12 ? 'Good morning' : ($asHour < 17 ? 'Good afternoon' : 'Good evening');
// Seconds since midnight, company time — the running clock counts up from this.
$asSecs = ($asHour * 3600) + ((int)$asMoment->format('i') * 60) + (int)$asMoment->format('s');

$asBlocking = ($asState['stage'] === 'check_in') && attendance_self_blocking();

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
?>
<style>
.as-scrim{position:fixed;inset:0;z-index:20000;display:flex;align-items:center;justify-content:center;padding:20px;
  background:rgba(28,25,23,.55);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);}
.as-card{width:100%;max-width:430px;background:#fffdfa;border-radius:20px;padding:30px 28px 26px;
  box-shadow:0 24px 70px rgba(28,25,23,.32);text-align:center;
  font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1c1917;}
.as-badge{display:inline-flex;align-items:center;gap:7px;padding:5px 12px;border-radius:999px;
  background:#f5f0e8;color:#78716c;font-size:11px;font-weight:600;letter-spacing:.09em;text-transform:uppercase;}
.as-card h2{margin:16px 0 4px;font-size:25px;line-height:1.25;font-weight:700;letter-spacing:-.4px;}
.as-day{margin:0;color:#78716c;font-size:13.5px;}
.as-clock{margin:20px 0 4px;font-size:42px;font-weight:700;letter-spacing:-1.5px;font-variant-numeric:tabular-nums;}
.as-note{margin:0 0 20px;color:#78716c;font-size:13.5px;line-height:1.5;}
.as-btn{display:block;width:100%;border:0;border-radius:13px;padding:15px;font-size:15.5px;font-weight:650;
  cursor:pointer;color:#fff;background:#1c1917;transition:transform .12s ease,opacity .12s ease;}
.as-btn:hover{opacity:.9;}
.as-btn:active{transform:scale(.985);}
.as-btn[disabled]{opacity:.55;cursor:default;}
.as-btn-out{background:#9a3412;}
.as-foot{margin:16px 0 0;color:#a8a29e;font-size:12px;line-height:1.5;}
.as-err{margin:0 0 16px;padding:10px 13px;border-radius:11px;background:#fef2f2;color:#991b1b;
  font-size:13px;text-align:left;line-height:1.45;}
.as-ok{margin:0 0 16px;padding:10px 13px;border-radius:11px;background:#f0fdf4;color:#166534;
  font-size:13px;text-align:left;line-height:1.45;}

.as-bar{position:fixed;right:18px;bottom:18px;z-index:19000;display:flex;align-items:center;gap:13px;
  background:#fffdfa;border:1px solid #eae5dd;border-radius:15px;padding:11px 13px 11px 16px;
  box-shadow:0 12px 34px rgba(28,25,23,.16);
  font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1c1917;}
.as-bar-txt{font-size:12.5px;line-height:1.35;}
.as-bar-txt strong{display:block;font-size:13.5px;}
.as-bar-txt span{color:#78716c;}
.as-bar-btn{border:0;border-radius:10px;padding:9px 15px;font-size:13px;font-weight:650;color:#fff;
  background:#9a3412;cursor:pointer;white-space:nowrap;}
.as-bar-btn[disabled]{opacity:.55;cursor:default;}
.as-bar-done{color:#166534;font-size:12.5px;font-weight:600;white-space:nowrap;}
@media (max-width:520px){
  .as-bar{left:18px;right:18px;}
  .as-clock{font-size:36px;}
}
</style>

<?php if ($asBlocking): ?>
<div class="as-scrim" id="asScrim" role="dialog" aria-modal="true" aria-labelledby="asTitle">
  <div class="as-card">
    <span class="as-badge">Attendance</span>
    <h2 id="asTitle"><?= h($asGreeting) ?><?= $asFirst !== '' ? ' ' . h($asFirst) : '' ?></h2>
    <p class="as-day"><?= h($asState['day_label']) ?></p>

    <div class="as-clock" id="asClock"><?= h($asState['now_label']) ?></div>

    <?php if ($asFlash && empty($asFlash['ok'])): ?>
      <p class="as-err"><?= h($asFlash['message']) ?></p>
    <?php endif; ?>

    <?php if (!$asState['ip_allowed']): ?>
      <p class="as-err">You can only check in from the office network. Connect to the office WiFi and reload this page.</p>
      <p class="as-note">If you believe this is wrong, contact HR.</p>
    <?php else: ?>
      <p class="as-note">Check in to start your day. You need to check in before you can continue.</p>
      <form method="post" action="<?= h($asEndpoint) ?>" id="asFormIn">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="in">
        <input type="hidden" name="redirect" value="<?= h($asBack) ?>">
        <button type="submit" class="as-btn" id="asBtnIn">Check In</button>
      </form>
      <p class="as-foot">Your arrival time is recorded as <?= h($asState['now_label']) ?>. HR can correct it if something is wrong.</p>
    <?php endif; ?>
  </div>
</div>
<script>
(function () {
  // Keep the clock honest while the page sits open, counting from the server's
  // time so a wrong clock on the staff member's computer changes nothing.
  var el = document.getElementById('asClock');
  var started = Date.now();
  var base = <?= json_encode($asSecs) ?>;
  if (el) {
    setInterval(function () {
      var s = base + Math.floor((Date.now() - started) / 1000);
      var hh = Math.floor(s / 3600) % 24, mm = Math.floor(s / 60) % 60;
      var ap = hh < 12 ? 'AM' : 'PM', h12 = hh % 12; if (h12 === 0) h12 = 12;
      el.textContent = h12 + ':' + (mm < 10 ? '0' : '') + mm + ' ' + ap;
    }, 1000);
  }

  // One tap only, so a slow connection does not produce two submissions.
  var form = document.getElementById('asFormIn');
  var btn = document.getElementById('asBtnIn');
  if (form && btn) {
    form.addEventListener('submit', function () {
      btn.disabled = true;
      btn.textContent = 'Checking in…';
    });
  }

  // Nothing behind the pop-up is reachable by keyboard while it is up.
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); }
  }, true);
}());
</script>

<?php elseif ($asState['stage'] === 'check_out'): ?>
<div class="as-bar">
  <div class="as-bar-txt">
    <strong>Checked in <?= h(date('g:i A', strtotime((string)$asState['check_in']))) ?></strong>
    <span>Tap when you leave</span>
  </div>
  <form method="post" action="<?= h($asEndpoint) ?>" id="asFormOut">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="out">
    <input type="hidden" name="redirect" value="<?= h($asBack) ?>">
    <button type="submit" class="as-bar-btn" id="asBtnOut">Check Out</button>
  </form>
</div>
<script>
(function () {
  var form = document.getElementById('asFormOut');
  var btn = document.getElementById('asBtnOut');
  if (form && btn) {
    form.addEventListener('submit', function (e) {
      if (!window.confirm('Check out now? This ends your working day.')) {
        e.preventDefault();
        return;
      }
      btn.disabled = true;
      btn.textContent = 'Saving…';
    });
  }
}());
</script>

<?php elseif ($asState['stage'] === 'done' && $asFlash && !empty($asFlash['ok'])): ?>
<div class="as-bar">
  <div class="as-bar-txt">
    <strong><?= h(date('g:i A', strtotime((string)$asState['check_in']))) ?> &rarr; <?= h(date('g:i A', strtotime((string)$asState['check_out']))) ?></strong>
    <span>Recorded for today</span>
  </div>
  <span class="as-bar-done"><?= h($asState['hours']) ?> h</span>
</div>
<?php endif; ?>
