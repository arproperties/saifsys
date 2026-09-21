<?php
// hr/fleet_trip.php — one trip explained: where its km and time came from,
// with GPS jumps, signal gaps and stops marked on the map.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/includes/hr_fleet.php';
require_once __DIR__ . '/includes/hr_fleet_ui.php';
require_role(HR_FLEET_ROLES, $conn);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$id = (int)($_GET['id'] ?? 0);
$trip = fleet_tables_ready($conn) ? fleet_load_trip($conn, $id) : null;

$pageTitle = $trip ? 'Trip #' . $id : 'Trip';
$pageHead = hr_fleet_map_head();
$pageStyles = hr_fleet_map_styles() . '
.fleet-points{max-height:60vh;overflow:auto}
.fleet-points tr.is-suspect td{background:#fee2e2}
.fleet-points tr.is-gap td{border-top:3px dashed #f97316}
.fleet-points tr.is-muted td{color:#9ca3af}
.fleet-timeline tr[data-lat]{cursor:pointer}
.fleet-timeline tr.is-stop td{background:#eff6ff}
.fleet-stop-no{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;padding:0 5px;border-radius:11px;
  background:#2563eb;color:#fff;font-weight:700;font-size:11px;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)}
.fleet-stop-pin{transform:translate(-50%,-50%)}
';
require_once __DIR__ . '/includes/hr_layout_header.php';

if (!$trip) {
    echo '<div class="alert alert-warning">Trip not found. <a href="fleet_history">Back to trip history</a></div>';
    require_once __DIR__ . '/includes/hr_layout_footer.php';
    exit;
}

$minStop = (int)($_GET['min_stop'] ?? 0);
if (!in_array($minStop, fleet_stop_second_options(), true)) {
    $minStop = fleet_stop_seconds();
}
$a = fleet_trip_analysis($conn, $trip, $minStop);
$findings = fleet_trip_findings($trip, $a);
$open = $trip['ended_at'] === null;
$duration = $a['end_ts'] - $a['start_ts'];
$moving = max(0, $duration - $a['stopped_s']);
$avg = $moving > 0 ? $trip['distance_m'] / 1000 / ($moving / 3600) : 0;
$fastest = 0.0;
foreach ($a['points'] as $p) {
    if ($p['status'] === 'counted' && $p['hop_kmh'] > $fastest) {
        $fastest = $p['hop_kmh'];
    }
}

$statusLabels = [
    'first' => 'Start',
    'counted' => 'Counted',
    'suspect' => 'Counted — too fast',
    'still' => 'Not moving',
    'dropped' => 'Jump, ignored',
    'poor' => 'Poor accuracy, ignored',
];

$back = (string)($_GET['back'] ?? '');
if (!preg_match('#^(vehicle_view|fleet_history)(\?[A-Za-z0-9_=&%.\-]*)?$#', $back)) {
    $back = 'fleet_history';
}

echo hr_ui_page_header(
    $trip['plate_no'] . ' · ' . date('d M Y', $a['start_ts']),
    $trip['driver_name'] . ' · ' . date('H:i', $a['start_ts']) . ' – ' . ($open ? 'now' : date('H:i', $a['end_ts']))
        . ($trip['ended_by'] !== null ? ' · ended by office' : ''),
    [['label' => 'HR', 'href' => $hrBase . '/dashboard'], ['label' => 'Trip History', 'href' => $hrBase . '/fleet_history'], ['label' => 'Trip #' . $id]],
    '<a class="btn btn-outline-secondary" href="' . htmlspecialchars($back) . '"><i class="bi bi-arrow-left me-1"></i>Back</a>'
);

$tiles = [
    ['Distance', fleet_format_km((int)$trip['distance_m'])],
    ['Duration', fleet_format_seconds($duration)],
    ['Moving', fleet_format_seconds($moving)],
    ['Stopped', fleet_format_seconds($a['stopped_s'])],
    ['Avg moving speed', $avg > 0 ? (int)round($avg) . ' km/h' : '—'],
    ['GPS points', number_format(count($a['points']))],
];
?>

<div class="row g-3 mb-3">
  <?php foreach ($tiles as [$label, $value]): ?>
  <div class="col-6 col-md-2">
    <div class="hr-settings-card h-100"><div class="card-body">
      <div class="small text-muted"><?= htmlspecialchars($label) ?></div>
      <div class="fs-5 fw-semibold"><?= htmlspecialchars($value) ?></div>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-5">
    <div class="hr-settings-card h-100">
      <div class="settings-header"><strong>What we found</strong></div>
      <div class="card-body">
        <?php foreach ($findings as $f): ?>
          <div class="alert alert-<?= $f['level'] ?> py-2 small mb-2"><?= htmlspecialchars($f['text']) ?></div>
        <?php endforeach; ?>
        <?php if ($fastest > 0): ?>
          <div class="small text-muted">Fastest counted hop: <?= (int)round($fastest) ?> km/h.</div>
        <?php endif; ?>

        <?php if ($a['gaps']): ?>
          <div class="fw-semibold small mt-3 mb-1">Signal gaps (<?= (int)(fleet_gap_seconds() / 60) ?>+ min without GPS)</div>
          <table class="table table-sm small mb-0">
            <thead><tr><th>From</th><th>To</th><th class="text-end">Time</th><th class="text-end">Straight line</th></tr></thead>
            <tbody>
            <?php foreach ($a['gaps'] as $g): ?>
              <tr>
                <td><?= date('H:i', $g['from_ts']) ?></td>
                <td><?= date('H:i', $g['to_ts']) ?></td>
                <td class="text-end"><?= fleet_format_seconds($g['seconds']) ?></td>
                <td class="text-end"><?= fleet_format_km((int)round($g['meters'])) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="d-none">
      <input type="checkbox" class="fleet-trip-toggle" data-trip="<?= $id ?>" data-color="#7c3aed" checked>
    </div>
    <div id="fleet-map" class="fleet-map"></div>
    <div class="d-flex gap-2 align-items-center mt-2">
      <button type="button" class="btn btn-sm btn-outline-primary fleet-play" data-trip="<?= $id ?>"><i class="bi bi-play-fill"></i> Replay</button>
      <span class="small text-muted">
        <span class="fleet-swatch ms-0" style="background:#dc2626"></span> too-fast hop
        <span class="fleet-swatch" style="background:#f97316"></span> signal gap
        <span class="fleet-stop-no" style="transform:scale(.8)">1</span> stop
      </span>
    </div>
    <?php hr_fleet_player(); ?>
  </div>
</div>

<?php
$stopCount = count(array_filter($a['timeline'], static fn(array $e): bool => $e['type'] === 'stop'));
$driveCount = count($a['timeline']) - $stopCount;
?>
<div class="hr-settings-card mb-3">
  <div class="settings-header d-flex flex-wrap align-items-center gap-3">
    <strong>Trip timeline</strong>
    <span class="small text-muted"><?= $stopCount ?> stop(s), <?= $driveCount ?> drive(s)</span>
    <form class="d-flex align-items-center gap-2 ms-auto small">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="back" value="<?= htmlspecialchars($back) ?>">
      <label for="fleet-min-stop" class="text-nowrap">Count a stop after</label>
      <select id="fleet-min-stop" name="min_stop" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
        <?php foreach (fleet_stop_second_options() as $sec): ?>
          <option value="<?= $sec ?>" <?= $sec === $a['min_stop_s'] ? 'selected' : '' ?>><?= $sec / 60 ?> min</option>
        <?php endforeach; ?>
      </select>
      <span class="text-muted text-nowrap">in one place (<?= (int)fleet_stop_radius_m() ?> m)</span>
    </form>
  </div>
  <div class="card-body p-0 fleet-timeline" style="overflow-x:auto">
    <table class="table table-sm table-hover small align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th style="width:56px">#</th>
          <th>What</th>
          <th>From</th>
          <th>To</th>
          <th class="text-end">Time</th>
          <th class="text-end">Distance</th>
          <th class="text-end">Avg speed</th>
          <th class="text-end">Top speed</th>
          <th>Place</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($a['first_ts'] !== null): ?>
        <tr class="text-muted">
          <td><i class="bi bi-flag"></i></td>
          <td colspan="8">Start pressed <?= date('H:i:s', $a['start_ts']) ?> · first GPS point <?= date('H:i:s', $a['first_ts']) ?></td>
        </tr>
      <?php endif; ?>
      <?php foreach ($a['timeline'] as $e): ?>
        <?php if ($e['type'] === 'stop'): ?>
        <tr class="is-stop" data-lat="<?= $e['lat'] ?>" data-lng="<?= $e['lng'] ?>">
          <td><span class="fleet-stop-no"><?= $e['number'] ?></span></td>
          <td class="fw-semibold">Stop<?= $e['at_start'] ? ' (before driving)' : ($e['at_end'] ? ' (at end)' : '') ?></td>
          <td><?= date('H:i:s', $e['from_ts']) ?></td>
          <td><?= date('H:i:s', $e['to_ts']) ?></td>
          <td class="text-end fw-semibold"><?= $e['seconds'] < 60 ? $e['seconds'] . 's' : fleet_format_seconds($e['seconds']) ?></td>
          <td class="text-end"></td>
          <td class="text-end"></td>
          <td class="text-end"></td>
          <td>
            <?php if ($e['pickup'] !== null): ?>
              <i class="bi bi-geo-alt-fill text-success"></i> <strong><?= htmlspecialchars($e['pickup']) ?></strong> <span class="text-muted">(pickup point)</span>
            <?php else: ?>
              <span data-place="<?= $e['lat'] ?>,<?= $e['lng'] ?>" class="text-muted">…</span>
            <?php endif; ?>
            <a class="ms-1" href="https://www.openstreetmap.org/?mlat=<?= $e['lat'] ?>&amp;mlon=<?= $e['lng'] ?>#map=18/<?= $e['lat'] ?>/<?= $e['lng'] ?>" target="_blank" rel="noopener" title="Open in OpenStreetMap"><i class="bi bi-box-arrow-up-right"></i></a>
          </td>
        </tr>
        <?php else:
            $mid = $e['path'] ? $e['path'][intdiv(count($e['path']), 2)] : null; ?>
        <tr <?= $mid ? 'data-lat="' . $mid[0] . '" data-lng="' . $mid[1] . '"' : '' ?>>
          <td class="text-muted"><i class="bi bi-arrow-down"></i></td>
          <td>Drive</td>
          <td><?= date('H:i:s', $e['from_ts']) ?></td>
          <td><?= date('H:i:s', $e['to_ts']) ?></td>
          <td class="text-end"><?= $e['seconds'] < 60 ? $e['seconds'] . 's' : fleet_format_seconds($e['seconds']) ?></td>
          <td class="text-end"><?= fleet_format_km((int)round($e['meters'])) ?></td>
          <td class="text-end"><?= $e['avg_kmh'] !== null ? (int)round($e['avg_kmh']) . ' km/h' : '—' ?></td>
          <td class="text-end"><?= $e['max_kmh'] !== null ? (int)round($e['max_kmh']) . ' km/h' : '—' ?></td>
          <td class="text-muted small">
            <?php if ($e['path']): ?>
              <span data-place="<?= $e['path'][0][0] ?>,<?= $e['path'][0][1] ?>">…</span> →
              <span data-place="<?= $e['path'][count($e['path']) - 1][0] ?>,<?= $e['path'][count($e['path']) - 1][1] ?>">…</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if ($a['last_ts'] !== null): ?>
        <tr class="text-muted">
          <td><i class="bi bi-flag-fill"></i></td>
          <td colspan="8">Last GPS point <?= date('H:i:s', $a['last_ts']) ?> · <?= $open ? 'trip still open' : 'Stop pressed ' . date('H:i:s', $a['end_ts']) . ($trip['ended_by'] !== null ? ' (by office)' : '') ?></td>
        </tr>
      <?php endif; ?>
      <?php if (!$a['timeline']): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">No GPS points.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="hr-settings-card">
  <div class="settings-header d-flex align-items-center gap-3">
    <strong>Every GPS point</strong>
    <label class="form-check small mb-0">
      <input type="checkbox" class="form-check-input" id="fleet-problems-only"> Only problems
    </label>
  </div>
  <div class="card-body p-0 fleet-points">
    <table class="table table-sm table-hover small mb-0">
      <thead class="table-light" style="position:sticky;top:0">
        <tr>
          <th>Time</th>
          <th class="text-end">Gap</th>
          <th class="text-end">Accuracy</th>
          <th class="text-end">Phone speed</th>
          <th class="text-end">Hop</th>
          <th class="text-end">Hop speed</th>
          <th>Distance count</th>
          <th class="text-end">Sent late</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($a['points'] as $p):
          $isGap = $p['gap_s'] !== null && $p['gap_s'] >= fleet_gap_seconds();
          $problem = $isGap || in_array($p['status'], ['suspect', 'dropped', 'poor'], true);
          $class = trim(($p['status'] === 'suspect' ? 'is-suspect ' : '') . ($isGap ? 'is-gap ' : '')
              . (in_array($p['status'], ['still', 'poor'], true) ? 'is-muted' : ''));
          ?>
        <tr class="<?= $class ?>" <?= $problem ? 'data-problem' : '' ?>>
          <td><a href="https://www.openstreetmap.org/?mlat=<?= $p['lat'] ?>&amp;mlon=<?= $p['lng'] ?>#map=17/<?= $p['lat'] ?>/<?= $p['lng'] ?>" target="_blank" rel="noopener"><?= date('H:i:s', $p['ts']) ?></a></td>
          <td class="text-end"><?= $p['gap_s'] === null ? '' : ($p['gap_s'] < 60 ? $p['gap_s'] . 's' : fleet_format_seconds($p['gap_s'])) ?></td>
          <td class="text-end"><?= $p['accuracy'] === null ? '—' : (int)round($p['accuracy']) . ' m' ?></td>
          <td class="text-end"><?= $p['speed'] === null ? '—' : (int)round($p['speed']) . ' km/h' ?></td>
          <td class="text-end"><?= $p['hop_m'] === null ? '' : (int)round($p['hop_m']) . ' m' ?></td>
          <td class="text-end"><?= $p['hop_kmh'] === null ? '' : (int)round($p['hop_kmh']) . ' km/h' ?></td>
          <td><?= htmlspecialchars($statusLabels[$p['status']] ?? $p['status']) ?></td>
          <td class="text-end"><?= $p['late_s'] > 300 ? fleet_format_seconds($p['late_s']) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$a['points']): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No GPS points.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$issues = [
    'jumps' => array_map(static fn(array $j): array => [
        'from' => $j['from'], 'to' => $j['to'], 't' => date('H:i:s', $j['ts']),
        'kmh' => (int)round($j['kmh']), 'km' => round($j['meters'] / 1000, 2),
    ], $a['jumps']),
    'gaps' => array_map(static fn(array $g): array => [
        'from' => $g['from'], 'to' => $g['to'], 't' => date('H:i', $g['from_ts']) . '–' . date('H:i', $g['to_ts']),
        'len' => fleet_format_seconds($g['seconds']), 'km' => round($g['meters'] / 1000, 2),
    ], $a['gaps']),
    'stops' => array_values(array_map(static fn(array $s): array => [
        'n' => $s['number'], 'lat' => $s['lat'], 'lng' => $s['lng'], 'name' => $s['pickup'],
        't' => date('H:i', $s['from_ts']) . '–' . date('H:i', $s['to_ts']),
        'len' => $s['seconds'] < 60 ? $s['seconds'] . 's' : fleet_format_seconds($s['seconds']),
    ], array_filter($a['timeline'], static fn(array $e): bool => $e['type'] === 'stop'))),
];

$issuesJson = json_encode($issues, JSON_HEX_TAG | JSON_HEX_AMP);
$pageScripts = hr_fleet_map_scripts($hrAssetBase) . str_replace('ISSUES_JSON', $issuesJson, <<<'JS'
<script>
(function () {
  var fm = FleetMap.routes({ mapEl: "fleet-map", toggles: ".fleet-trip-toggle", url: "fleet_data?trip=", playerEl: "fleet-player" });
  var issues = ISSUES_JSON;
  var map = fm.map;
  issues.gaps.forEach(function (g) {
    L.polyline([g.from, g.to], { color: "#f97316", weight: 4, dashArray: "8 8" })
      .bindTooltip("No GPS " + g.t + " (" + g.len + ", " + g.km + " km straight line)").addTo(map);
  });
  issues.jumps.forEach(function (j) {
    L.polyline([j.from, j.to], { color: "#dc2626", weight: 5 })
      .bindTooltip("Too fast at " + j.t + ": " + j.kmh + " km/h, adds " + j.km + " km").addTo(map);
  });
  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" }[c];
    });
  }
  issues.stops.forEach(function (s) {
    L.marker([s.lat, s.lng], {
      icon: L.divIcon({ className: "", iconSize: null, html: "<div class=\"fleet-stop-pin\"><span class=\"fleet-stop-no\">" + s.n + "</span></div>" }),
      zIndexOffset: 500
    }).bindTooltip("Stop " + s.n + (s.name ? " · " + esc(s.name) : "") + "<br>" + s.t + " (" + s.len + ")").addTo(map);
  });

  // Timeline row click: show that spot on the map.
  document.querySelectorAll(".fleet-timeline tr[data-lat]").forEach(function (tr) {
    tr.addEventListener("click", function (e) {
      if (e.target.closest("a")) return;
      map.setView([+tr.getAttribute("data-lat"), +tr.getAttribute("data-lng")], 17);
      document.getElementById("fleet-map").scrollIntoView({ behavior: "smooth", block: "center" });
    });
  });

  // Area names from OpenStreetMap (Nominatim): one lookup a second, as its
  // usage policy asks, and each spot looked up once per browser.
  var cache = {};
  try { cache = JSON.parse(localStorage.getItem("fleetPlaces") || "{}"); } catch (e) {}
  var spans = Array.prototype.slice.call(document.querySelectorAll("[data-place]"));
  var queue = [];
  spans.forEach(function (el) {
    var parts = el.getAttribute("data-place").split(",");
    var key = (+parts[0]).toFixed(4) + "," + (+parts[1]).toFixed(4);
    el.setAttribute("data-key", key);
    if (cache[key]) el.textContent = cache[key]; else if (queue.indexOf(key) < 0) queue.push(key);
  });
  function fill(key, name) {
    spans.forEach(function (el) { if (el.getAttribute("data-key") === key) el.textContent = name; });
  }
  function next() {
    var key = queue.shift();
    if (!key) return;
    var ll = key.split(",");
    fetch("https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=17&accept-language=en&lat=" + ll[0] + "&lon=" + ll[1])
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var a = d.address || {};
        var name = [a.road, a.neighbourhood || a.suburb || a.quarter || a.city_district || a.city]
          .filter(function (x, i, all) { return x && all.indexOf(x) === i; }).join(", ") || d.display_name || "—";
        cache[key] = name;
        try { localStorage.setItem("fleetPlaces", JSON.stringify(cache)); } catch (e) {}
        fill(key, name);
      })
      .catch(function () { fill(key, "—"); })
      .then(function () { setTimeout(next, 1100); });
  }
  next();
  document.getElementById("fleet-problems-only").addEventListener("change", function (e) {
    document.querySelectorAll(".fleet-points tbody tr").forEach(function (tr) {
      tr.hidden = e.target.checked && !tr.hasAttribute("data-problem");
    });
  });
})();
</script>
JS);
require_once __DIR__ . '/includes/hr_layout_footer.php';
