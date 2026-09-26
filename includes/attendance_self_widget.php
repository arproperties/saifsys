<?php
/**
 * The check-in pop-up and the check-out bar.
 *
 * Include this once, just inside <body>, on any page that should offer it:
 *
 *     require __DIR__ . '/includes/attendance_self_widget.php';
 *
 * It draws itself and nothing else. Everything it needs to decide comes from
 * attendance_self_state(). It shows in every state: the check-in pop-up, the
 * break screen, the check-out bar, the day already recorded, a day HR has
 * marked, and a login with no employee record behind it. Only the feature being off, or a field
 * staff login, prints nothing.
 *
 * The styles are inline on purpose: this has to look right on thirteen
 * different module layouts without depending on any of their stylesheets.
 */

if (!isset($conn) || !($conn instanceof PDO)) {
    return;
}

require_once __DIR__ . '/attendance_self.php';
require_once __DIR__ . '/url_helper.php';

// Every page that reaches here has loaded auth.php, which defines these. The
// fallback is for the gate's own page, which is drawn from almost nothing —
// without it the form would die half-printed on an undefined function.
if (!function_exists('csrf_field')) {
    require_once __DIR__ . '/csrf.php';
}

$asState = attendance_self_state($conn);
// The only two silences left: the feature switched off, and field staff, who
// record their day on the PIN app and would be confused by a second place to
// do it. Every other state draws something, so the bar is always on screen —
// a day already recorded and a login with no employee record included.
if ($asState['reason'] === 'disabled' || $asState['reason'] === 'worker') {
    return;
}

$asFlash = $_SESSION['attendance_self_flash'] ?? null;
unset($_SESSION['attendance_self_flash']);

$asRoot     = function_exists('get_application_web_root') ? get_application_web_root() : '';
$asEndpoint = ($asRoot !== '' ? $asRoot : '') . '/api/attendance_self.php';
// The gate draws this pop-up as the whole page, so it carries the only way out
// of a wrong account — without it, signing in as someone else is a dead end.
$asLogout   = ($asRoot !== '' ? $asRoot : '') . '/logout';
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

// On a break the screen is held whatever the check-in switch says: the person
// chose to step away, and nobody should be working while marked away.
$asOnBreak   = ($asState['stage'] === 'break');
$asBreakSecs = 0;
if ($asOnBreak) {
    $asBreakSecs = attendance_self_minutes(substr((string)$asState['break_start'], 0, 5), $asState['now_time']) * 60;
}
// One break a day, so the button is offered only until it has been taken.
$asCanBreak = !empty($asState['breaks_on']) && empty($asState['break_start']);
$asBreakMins = $asState['break_mins'] !== null ? (int)$asState['break_mins'] : null;

// What HR marked today, in the words the attendance pages use.
$asStatusLabels = [
    'on_leave'       => 'On leave',
    'absent'         => 'Marked absent',
    'half'           => 'Half day',
    'excused_absent' => 'Excused absent',
];
$asStatusLabel = $asStatusLabels[(string)$asState['att_status']] ?? 'Recorded by HR';

/** A stored time as 9:05 AM, or an em dash when it is empty. */
if (!function_exists('as_time_label')) {
    function as_time_label($t) {
        $t = trim((string)$t);
        return $t === '' ? '—' : date('g:i A', strtotime($t));
    }
}

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
.as-who{margin:18px 0 0;padding-top:14px;border-top:1px solid #f0ebe3;color:#a8a29e;font-size:12px;line-height:1.5;}
.as-who strong{color:#78716c;font-weight:600;}
.as-who a{color:#9a3412;font-weight:600;text-decoration:none;}
.as-who a:hover{text-decoration:underline;}
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
.as-bar-btn-in{background:#1c1917;}
.as-bar-btn-alt{background:#f5f0e8;color:#44403c;}
.as-bar-btns{display:flex;align-items:center;gap:8px;}
.as-btn-back{background:#166534;}
.as-bar-note{color:#a8a29e;font-size:12.5px;font-weight:600;white-space:nowrap;}
.as-bar-warn{color:#9a3412;font-size:12.5px;font-weight:600;white-space:nowrap;}
.as-bar{cursor:grab;touch-action:none;user-select:none;-webkit-user-select:none;}
.as-bar.as-dragging{cursor:grabbing;box-shadow:0 18px 44px rgba(28,25,23,.26);}
.as-bar button{touch-action:manipulation;}
.as-bar-grip{margin-left:-6px;color:#d6d0c6;font-size:14px;line-height:1;letter-spacing:-2px;}
@media (max-width:520px){
  .as-bar{left:18px;right:18px;}
  .as-clock{font-size:36px;}
}
</style>

<?php if ($asOnBreak): ?>
<div class="as-scrim" id="asBreakScrim" role="dialog" aria-modal="true" aria-labelledby="asBreakTitle">
  <div class="as-card">
    <span class="as-badge">On break</span>
    <h2 id="asBreakTitle">On break<?= $asFirst !== '' ? ', ' . h($asFirst) : '' ?></h2>
    <p class="as-day">Break started <?= h(as_time_label($asState['break_start'])) ?></p>

    <div class="as-clock" id="asBreakClock">0:00</div>

    <?php if ($asFlash && empty($asFlash['ok'])): ?>
      <p class="as-err"><?= h($asFlash['message']) ?></p>
    <?php endif; ?>

    <p class="as-note">Tap <strong>Check In</strong> when you are back at your desk. The system is paused until you do.</p>
    <form method="post" action="<?= h($asEndpoint) ?>" id="asFormBack">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="resume">
      <input type="hidden" name="redirect" value="<?= h($asBack) ?>">
      <button type="submit" class="as-btn as-btn-back" id="asBtnBack">Check In</button>
    </form>
    <p class="as-foot">Your break start and your return are both recorded for HR. The break is not taken off your hours &mdash; the day is still counted from your morning check-in to your check-out.</p>

    <p class="as-who">
      Signed in as <strong><?= h($asName !== '' ? $asName : 'this account') ?></strong> &middot;
      <a href="<?= h($asLogout) ?>">Not you? Sign out</a>
    </p>
  </div>
</div>
<script>
(function () {
  // Counts up from however long the break has already run, so reloading the
  // page or opening another tab shows the same figure.
  var el = document.getElementById('asBreakClock');
  var started = Date.now();
  var base = <?= json_encode($asBreakSecs) ?>;
  function paint() {
    var s = base + Math.floor((Date.now() - started) / 1000);
    var hh = Math.floor(s / 3600), mm = Math.floor(s / 60) % 60, ss = s % 60;
    var t = (mm < 10 && hh > 0 ? '0' : '') + mm + ':' + (ss < 10 ? '0' : '') + ss;
    el.textContent = hh > 0 ? hh + ':' + t : t;
  }
  if (el) { paint(); setInterval(paint, 1000); }

  var form = document.getElementById('asFormBack');
  var btn = document.getElementById('asBtnBack');
  if (form && btn) {
    form.addEventListener('submit', function () {
      btn.disabled = true;
      btn.textContent = 'One moment…';
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); }
  }, true);
}());
</script>

<?php elseif ($asBlocking): ?>
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

    <p class="as-who">
      Signed in as <strong><?= h($asName !== '' ? $asName : 'this account') ?></strong> &middot;
      <a href="<?= h($asLogout) ?>">Not you? Sign out</a>
    </p>
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

<?php elseif ($asState['stage'] === 'check_in'): ?>
<div class="as-bar">
  <span class="as-bar-grip" aria-hidden="true" title="Drag to move">&#8942;&#8942;</span>
  <div class="as-bar-txt">
    <strong>Not checked in yet</strong>
    <?php if (!$asState['ip_allowed']): ?>
      <span>Office network only</span>
    <?php elseif ($asFlash && empty($asFlash['ok'])): ?>
      <span><?= h($asFlash['message']) ?></span>
    <?php else: ?>
      <span>Tap when you start</span>
    <?php endif; ?>
  </div>
  <?php if ($asState['ip_allowed']): ?>
  <form method="post" action="<?= h($asEndpoint) ?>" id="asFormInBar">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="in">
    <input type="hidden" name="redirect" value="<?= h($asBack) ?>">
    <button type="submit" class="as-bar-btn as-bar-btn-in" id="asBtnInBar">Check In</button>
  </form>
  <?php else: ?>
  <span class="as-bar-warn">Off network</span>
  <?php endif; ?>
</div>
<script>
(function () {
  var form = document.getElementById('asFormInBar');
  var btn = document.getElementById('asBtnInBar');
  if (form && btn) {
    form.addEventListener('submit', function () {
      btn.disabled = true;
      btn.textContent = 'Saving…';
    });
  }
}());
</script>

<?php elseif ($asState['stage'] === 'check_out'): ?>
<div class="as-bar">
  <span class="as-bar-grip" aria-hidden="true" title="Drag to move">&#8942;&#8942;</span>
  <div class="as-bar-txt">
    <strong>Checked in <?= h(as_time_label($asState['check_in'])) ?></strong>
    <?php if ($asBreakMins !== null): ?>
      <span>Back <?= h(as_time_label($asState['break_end'])) ?> &middot; break was <?= h($asBreakMins) ?> min</span>
    <?php elseif ($asCanBreak): ?>
      <span>Break when you step away</span>
    <?php else: ?>
      <span>Tap when you leave</span>
    <?php endif; ?>
  </div>
  <div class="as-bar-btns">
    <?php if ($asCanBreak): ?>
    <form method="post" action="<?= h($asEndpoint) ?>" id="asFormBreak">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="break">
      <input type="hidden" name="redirect" value="<?= h($asBack) ?>">
      <button type="submit" class="as-bar-btn as-bar-btn-alt" id="asBtnBreak">Break</button>
    </form>
    <?php endif; ?>
    <form method="post" action="<?= h($asEndpoint) ?>" id="asFormOut">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="out">
      <input type="hidden" name="redirect" value="<?= h($asBack) ?>">
      <button type="submit" class="as-bar-btn" id="asBtnOut">Check Out</button>
    </form>
  </div>
</div>
<script>
(function () {
  var bForm = document.getElementById('asFormBreak');
  var bBtn = document.getElementById('asBtnBreak');
  if (bForm && bBtn) {
    bForm.addEventListener('submit', function (e) {
      if (!window.confirm('Start your break? The system pauses until you tap Check In again.')) {
        e.preventDefault();
        return;
      }
      bBtn.disabled = true;
      bBtn.textContent = '…';
    });
  }

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

<?php elseif ($asState['stage'] === 'done'): ?>
<div class="as-bar">
  <span class="as-bar-grip" aria-hidden="true" title="Drag to move">&#8942;&#8942;</span>
  <div class="as-bar-txt">
    <strong><?= h(as_time_label($asState['check_in'])) ?> &rarr; <?= h(as_time_label($asState['check_out'])) ?></strong>
    <span>Recorded for today<?= $asBreakMins !== null ? ' &middot; ' . h($asBreakMins) . ' min break' : '' ?></span>
  </div>
  <span class="as-bar-done"><?= $asState['hours'] !== null ? h($asState['hours']) . ' h' : 'Done' ?></span>
</div>

<?php elseif ($asState['stage'] === 'excused'): ?>
<div class="as-bar">
  <span class="as-bar-grip" aria-hidden="true" title="Drag to move">&#8942;&#8942;</span>
  <div class="as-bar-txt">
    <strong><?= h($asStatusLabel) ?></strong>
    <?php if (!empty($asState['check_in'])): ?>
      <span><?= h(as_time_label($asState['check_in'])) ?> &rarr; <?= h(as_time_label($asState['check_out'])) ?></span>
    <?php else: ?>
      <span>HR has recorded today for you</span>
    <?php endif; ?>
  </div>
  <span class="as-bar-note">No action needed</span>
</div>

<?php else: ?>
<div class="as-bar">
  <span class="as-bar-grip" aria-hidden="true" title="Drag to move">&#8942;&#8942;</span>
  <div class="as-bar-txt">
    <strong>Attendance not set up</strong>
    <span>Your login is not linked to an employee record</span>
  </div>
  <span class="as-bar-warn">Ask HR</span>
</div>
<?php endif; ?>
<script>
(function () {
  // The bar can be dragged anywhere on screen, and stays where it was left on
  // every page. Buttons still work as buttons; double-click puts it back.
  var bar = document.querySelector('.as-bar');
  if (!bar) return;
  var KEY = 'as_bar_pos';

  function place(x, y) {
    var maxX = Math.max(0, window.innerWidth - bar.offsetWidth);
    var maxY = Math.max(0, window.innerHeight - bar.offsetHeight);
    bar.style.left = Math.min(Math.max(0, x), maxX) + 'px';
    bar.style.top = Math.min(Math.max(0, y), maxY) + 'px';
    bar.style.right = 'auto';
    bar.style.bottom = 'auto';
  }
  function reset() {
    bar.style.left = bar.style.top = bar.style.right = bar.style.bottom = '';
  }

  var saved = null;
  try { saved = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) {}
  if (saved && typeof saved.x === 'number' && typeof saved.y === 'number') {
    place(saved.x, saved.y);
  }

  var dx = 0, dy = 0, dragging = false;
  bar.addEventListener('pointerdown', function (e) {
    if (e.button !== 0 || e.target.closest('button,a,input,select,textarea')) return;
    var r = bar.getBoundingClientRect();
    dx = e.clientX - r.left;
    dy = e.clientY - r.top;
    dragging = true;
    bar.classList.add('as-dragging');
    bar.setPointerCapture(e.pointerId);
    e.preventDefault();
  });
  bar.addEventListener('pointermove', function (e) {
    if (dragging) place(e.clientX - dx, e.clientY - dy);
  });
  function stop() {
    if (!dragging) return;
    dragging = false;
    bar.classList.remove('as-dragging');
    try {
      localStorage.setItem(KEY, JSON.stringify({ x: parseFloat(bar.style.left), y: parseFloat(bar.style.top) }));
    } catch (e) {}
  }
  bar.addEventListener('pointerup', stop);
  bar.addEventListener('pointercancel', stop);

  bar.addEventListener('dblclick', function (e) {
    if (e.target.closest('button,a,input')) return;
    reset();
    try { localStorage.removeItem(KEY); } catch (err) {}
  });

  // Keep it on screen when the window shrinks.
  window.addEventListener('resize', function () {
    if (bar.style.left) place(parseFloat(bar.style.left), parseFloat(bar.style.top));
  });
}());
</script>
