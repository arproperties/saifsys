<?php
/**
 * HR Fleet — the bits of page the fleet screens share: map assets, the trip
 * table, flash messages. Rules and queries live in hr_fleet.php.
 */

const HR_FLEET_ROLES = ['Owner', 'Admin', 'HR'];

function hr_fleet_palette(): array
{
    return ['#2563eb', '#dc2626', '#059669', '#d97706', '#7c3aed', '#0891b2', '#db2777', '#4d7c0f'];
}

/** CSS for <head>. */
function hr_fleet_map_head(): string
{
    // Material Design Icons: the same vehicle pictures the Driver app uses.
    return '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">'
        . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/MaterialDesign-Webfont/7.4.47/css/materialdesignicons.min.css">';
}

function hr_fleet_map_styles(): string
{
    return '
.fleet-map{height:70vh;min-height:420px;border-radius:12px;z-index:0}
.fleet-side{max-height:70vh;overflow:auto}
.fleet-pin{position:absolute;transform:translate(-50%,calc(-100% - 8px));background:#0E2038;color:#fff;font-weight:700;font-size:12px;
  padding:3px 8px 3px 5px;border-radius:8px;white-space:nowrap;box-shadow:0 2px 6px rgba(0,0,0,.35);border:2px solid #22c55e;
  display:flex;align-items:center;gap:4px;line-height:1}
.fleet-pin .mdi{font-size:18px;line-height:1}
.fleet-list-icon.mdi{font-size:18px;line-height:1;vertical-align:-3px;margin-right:4px;color:#0E2038}
.fleet-pin:after{content:"";position:absolute;left:50%;bottom:-8px;margin-left:-6px;border:6px solid transparent;border-top-color:#22c55e;border-bottom:0}
.fleet-pin.is-stale{background:#6b7280;border-color:#9ca3af}
.fleet-pin.is-stale:after{border-top-color:#9ca3af}
.fleet-swatch{display:inline-block;width:10px;height:10px;border-radius:50%;margin-left:6px;vertical-align:middle}
.fleet-pin.is-playing{background:#1d4ed8;border-color:#93c5fd}
.fleet-pin.is-playing:after{border-top-color:#93c5fd}
.fleet-player{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:8px 12px}
';
}

/** Play bar under a routes map. FleetMap.routes() drives it. */
function hr_fleet_player(): void
{
    ?>
    <div id="fleet-player" class="fleet-player mt-2" hidden>
      <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-primary btn-sm" data-play aria-label="Play or pause"><i class="bi bi-play-fill"></i></button>
        <input type="range" class="form-range flex-grow-1" min="0" max="1000" value="0" data-range aria-label="Position in the trip">
        <select class="form-select form-select-sm w-auto" data-speed aria-label="Playback speed">
          <option value="10">10×</option>
          <option value="30">30×</option>
          <option value="60" selected>60×</option>
          <option value="120">120×</option>
          <option value="300">300×</option>
        </select>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-close aria-label="Close player"><i class="bi bi-x-lg"></i></button>
      </div>
      <div class="d-flex justify-content-between small mt-1">
        <span class="fw-semibold" data-title></span>
        <span class="text-muted" data-label></span>
      </div>
    </div>
    <?php
}

/** Script tags for the footer, before the page's own init script. */
function hr_fleet_map_scripts(string $hrAssetBase): string
{
    return '<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>'
        . '<script src="' . htmlspecialchars($hrAssetBase, ENT_QUOTES, 'UTF-8') . '/fleet-map.js?v=20260911-3"></script>';
}

/** Flash messages set by the POST handlers (fleet_trip_end.php). */
function hr_fleet_flash(): void
{
    foreach (['flash_success' => 'success', 'flash_error' => 'danger'] as $key => $type) {
        if (!empty($_SESSION[$key])) {
            echo '<div class="alert alert-' . $type . '">' . htmlspecialchars((string)$_SESSION[$key]) . '</div>';
            unset($_SESSION[$key]);
        }
    }
}

/** A Y-m-d from the query string, or the fallback. */
function hr_fleet_date_param(string $key, string $fallback): string
{
    $value = (string)($_GET[$key] ?? '');
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $fallback;
}

/**
 * Trips with a tick box each; ticked rows are drawn on the map by
 * FleetMap.routes(). The newest trip starts ticked.
 *
 * @param string $back where the End trip form returns to
 */
function hr_fleet_trip_table(array $trips, bool $showVehicle, string $back): void
{
    $palette = hr_fleet_palette();
    $cols = $showVehicle ? 7 : 6;
    ?>
    <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table table-hover align-middle mb-0 small">
        <thead class="table-light">
          <tr>
            <th style="width:96px" title="Show on map / play">Map</th>
            <th>When</th>
            <?php if ($showVehicle): ?><th>Vehicle</th><?php endif; ?>
            <th>Driver</th>
            <th>Time</th>
            <th class="text-end">Distance</th>
            <th class="text-end"></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$trips): ?>
          <tr><td colspan="<?= $cols ?>" class="text-center text-muted py-4">No trips in this period.</td></tr>
        <?php endif; ?>
        <?php foreach ($trips as $i => $t):
            $color = $palette[$i % count($palette)];
            $open = $t['ended_at'] === null;
            $start = strtotime((string)$t['started_at']);
            ?>
          <tr>
            <td class="text-nowrap">
              <input type="checkbox" class="form-check-input fleet-trip-toggle" aria-label="Show this route on the map"
                     data-trip="<?= (int)$t['id'] ?>" data-color="<?= $color ?>" <?= $i === 0 ? 'checked' : '' ?>>
              <span class="fleet-swatch" style="background:<?= $color ?>"></span>
              <button type="button" class="btn btn-sm btn-outline-primary py-0 px-1 ms-1 fleet-play"
                      data-trip="<?= (int)$t['id'] ?>" title="Play this trip" aria-label="Play this trip">
                <i class="bi bi-play-fill"></i>
              </button>
            </td>
            <td>
              <div class="fw-semibold"><?= date('d M Y', $start) ?></div>
              <div class="text-muted"><?= date('H:i', $start) ?> – <?= $open ? 'now' : date('H:i', strtotime((string)$t['ended_at'])) ?></div>
              <?php $routeLabel = fleet_trip_route_label($t); ?>
              <?php if ($routeLabel !== ''): ?>
                <div class="text-muted"><i class="bi bi-signpost-2"></i> <?= htmlspecialchars($routeLabel) ?></div>
              <?php endif; ?>
            </td>
            <?php if ($showVehicle): ?>
            <td>
              <a href="vehicle_view?id=<?= (int)$t['vehicle_id'] ?>" class="fw-semibold"><?= htmlspecialchars((string)$t['plate_no']) ?></a>
              <?php if (!empty($t['vehicle_name'])): ?><div class="text-muted"><?= htmlspecialchars((string)$t['vehicle_name']) ?></div><?php endif; ?>
            </td>
            <?php endif; ?>
            <td><?= htmlspecialchars((string)$t['driver_name']) ?></td>
            <td><?= htmlspecialchars(fleet_format_duration((string)$t['started_at'], $t['ended_at'])) ?></td>
            <td class="text-end"><?= htmlspecialchars(fleet_format_km((int)$t['distance_m'])) ?></td>
            <td class="text-end text-nowrap">
              <?php if ($open): ?>
                <span class="badge text-bg-success">On trip</span>
                <form method="post" action="fleet_trip_end" class="d-inline"
                      onsubmit="return confirm('End this trip now? The driver\'s phone stops recording on its next send.');">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="trip_id" value="<?= (int)$t['id'] ?>">
                  <input type="hidden" name="back" value="<?= htmlspecialchars($back) ?>">
                  <button class="btn btn-sm btn-outline-danger ms-1">End trip</button>
                </form>
              <?php elseif ($t['ended_by'] !== null): ?>
                <span class="badge text-bg-warning" title="Ended from HR, not by the driver">Ended by office</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}
