<?php
/**
 * Usage report — which pages and staff-app actions are used, and by whom.
 *
 * Reads usage_page_hits, filled by includes/usage_tracker.php. The page list
 * comes from the files on disk, so a page nobody ever opened still shows up —
 * that is the whole point. Owner only.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/url_helper.php';
require_role(['Owner'], $conn);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

const USAGE_KEEP_DAYS = 400; // a little over 13 months, so yearly pages get one full cycle

$appBase = get_application_web_root();
$root = realpath(__DIR__ . '/..');
$me = (int)current_user_id();
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Dubai')))->format('Y-m-d');

// ---------------------------------------------------------------------------
// The pages on disk
// ---------------------------------------------------------------------------

/** Folders that hold helpers, data or tooling — nothing a person opens. */
function usage_report_skip_dirs(): array
{
    return [
        'includes', 'partials', 'vendor', 'node_modules', 'lib', 'migrations', 'database', 'backups',
        'tests', 'scripts', 'cron', 'logs', 'storage', 'uploads', 'docs', 'live_hotfix', 'assets',
        'images', 'integrations', '__MACOSX', 'DRIVER-MOBILE-APP', 'OPERATION-MOBILE-APP',
    ];
}

/** @return string[] every .php (or, with $all, .php/.js/.html) path from the root */
function usage_report_files(string $root, bool $all = false): array
{
    $skip = array_flip($all ? ['vendor', 'node_modules', 'backups', 'uploads', 'storage', 'logs', '__MACOSX',
        'DRIVER-MOBILE-APP', 'OPERATION-MOBILE-APP'] : usage_report_skip_dirs());
    $filter = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file) use ($skip, $all): bool {
            $name = $file->getFilename();
            if ($name[0] === '.') {
                return false;
            }
            if ($file->isDir()) {
                return !isset($skip[$name]);
            }
            $ext = strtolower($file->getExtension());
            return $ext === 'php' || ($all && ($ext === 'js' || $ext === 'html'));
        }
    );
    $out = [];
    foreach (new RecursiveIteratorIterator($filter) as $file) {
        $out[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    }
    sort($out);
    return $out;
}

/** "modules/realestate/leases.php" -> "realestate";  "hr/payroll.php" -> "hr";  "login.php" -> "main" */
function usage_report_area(string $page): string
{
    $parts = explode('/', $page);
    if (count($parts) === 1) {
        return 'main';
    }
    if ($parts[0] === 'modules' && count($parts) > 2) {
        return $parts[1];
    }
    return $parts[0];
}

/** Folds "a/b/../c.php" to "a/c.php"; null when it climbs above the root. */
function usage_report_normalize(string $path): ?string
{
    $out = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            if (!$out) {
                return null;
            }
            array_pop($out);
            continue;
        }
        $out[] = $part;
    }
    return implode('/', $out);
}

/**
 * How many OTHER files mention each page — a link, a form action, a redirect,
 * a fetch. A rough guide, not proof: a link built from pieces at run time is
 * not seen, and a mention in dead code still counts.
 *
 * @param string[] $pages
 * @return array<string,int>
 */
function usage_report_links(string $root, array $pages): array
{
    $isPage = array_flip($pages);
    $from = [];
    foreach (usage_report_files($root, true) as $file) {
        $text = @file_get_contents($root . '/' . $file);
        if ($text === false || !preg_match_all('#[A-Za-z0-9_\-./]*[A-Za-z0-9_\-]\.php#', $text, $m)) {
            continue;
        }
        $dir = dirname($file) === '.' ? '' : dirname($file);
        $up = ($dir === '' || dirname($dir) === '.') ? '' : dirname($dir);
        foreach (array_unique($m[0]) as $token) {
            // The same text can mean "next to me", "next to whoever includes
            // me" (menus live in includes/) or "from the site root".
            $tries = [
                usage_report_normalize($dir . '/' . $token),
                usage_report_normalize($up . '/' . $token),
                usage_report_normalize(preg_replace('#^(\.\./|\./|/)+#', '', $token)),
            ];
            foreach ($tries as $target) {
                if ($target !== null && $target !== $file && isset($isPage[$target])) {
                    $from[$target][$file] = true;
                    break;
                }
            }
        }
    }
    $counts = [];
    foreach ($pages as $page) {
        $counts[$page] = isset($from[$page]) ? count($from[$page]) : 0;
    }
    return $counts;
}

// ---------------------------------------------------------------------------
// Switch, clean-up, filters
// ---------------------------------------------------------------------------

$tableReady = true;
try {
    $conn->query('SELECT 1 FROM usage_page_hits LIMIT 1');
} catch (Throwable $e) {
    $tableReady = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    csrf_verify();
    $off = ($_POST['turn'] ?? '') === 'off' ? '1' : '0';
    $conn->prepare("INSERT INTO settings (`key`, `value`) VALUES ('usage_tracking_off', ?)
                    ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")->execute([$off]);
    header('Location: ' . $appBase . '/admin/usage_report.php');
    exit;
}

$trackingOff = false;
try {
    $st = $conn->query("SELECT `value` FROM settings WHERE `key` = 'usage_tracking_off' LIMIT 1");
    $trackingOff = $st->fetchColumn() === '1';
} catch (Throwable $e) {
}

$since = null;
if ($tableReady) {
    $cut = (new DateTimeImmutable($today))->modify('-' . USAGE_KEEP_DAYS . ' days')->format('Y-m-d');
    $conn->prepare('DELETE FROM usage_page_hits WHERE hit_date < ?')->execute([$cut]);
    $since = $conn->query('SELECT MIN(hit_date) FROM usage_page_hits')->fetchColumn() ?: null;
}

$tab = ($_GET['tab'] ?? '') === 'app' ? 'app' : 'pages';
$days = (int)($_GET['days'] ?? 90);
if (!in_array($days, [7, 30, 90, 365], true)) {
    $days = 90;
}
$from = (new DateTimeImmutable($today))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
$area = (string)($_GET['area'] ?? '');
$show = (string)($_GET['show'] ?? 'all');
$q = trim((string)($_GET['q'] ?? ''));
// On unless the form was sent without the tick.
$skipMe = isset($_GET['days']) ? !empty($_GET['skipme']) : true;
$onePage = (string)($_GET['page'] ?? '');
$oneUser = (int)($_GET['user'] ?? 0);

$meSql = $skipMe ? ' AND h.user_id <> ' . $me : '';
$keep = static function (array $change) use ($tab, $days, $area, $show, $q, $skipMe): string {
    $base = ['tab' => $tab, 'days' => $days, 'area' => $area, 'show' => $show, 'q' => $q, 'skipme' => $skipMe ? 1 : 0];
    return '?' . http_build_query(array_filter(array_merge($base, $change), static fn($v) => $v !== '' && $v !== null));
};
$when = static function (?string $at) use ($today): string {
    if (!$at) {
        return '—';
    }
    $gap = (int)(new DateTimeImmutable($today))->diff(new DateTimeImmutable(substr($at, 0, 10)))->days;
    $label = $gap === 0 ? 'today' : ($gap === 1 ? 'yesterday' : $gap . ' days ago');
    return date('d M Y', strtotime($at)) . ' · ' . $label;
};
$clientNames = ['staff_web' => 'Staff web app', 'driver_web' => 'Driver web app', 'apk' => 'Android app'];

// ---------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------

$rows = [];
$areas = [];
$detail = [];
$totals = ['pages' => 0, 'unused' => 0, 'never' => 0];
$people = [];
$actions = [];

if ($tableReady && $tab === 'pages' && $onePage !== '') {
    $st = $conn->prepare("
        SELECT h.user_id, COALESCE(NULLIF(u.fullname, ''), u.username) AS name,
               SUM(h.views) AS views, SUM(h.saves) AS saves,
               COUNT(DISTINCT h.hit_date) AS days_used, MAX(h.last_at) AS last_at
        FROM usage_page_hits h
        LEFT JOIN user u ON u.id = h.user_id
        WHERE h.channel = 'web' AND h.page = ? AND h.hit_date >= ?
        GROUP BY h.user_id, name
        ORDER BY last_at DESC
    ");
    $st->execute([$onePage, $from]);
    $detail = $st->fetchAll();
} elseif ($tableReady && $tab === 'pages') {
    $pages = usage_report_files($root);
    $links = usage_report_links($root, $pages);

    $st = $conn->prepare("
        SELECT h.page, MAX(h.last_at) AS last_at,
               SUM(CASE WHEN h.hit_date >= :f1 THEN h.views ELSE 0 END) AS views,
               SUM(CASE WHEN h.hit_date >= :f2 THEN h.saves ELSE 0 END) AS saves,
               COUNT(DISTINCT CASE WHEN h.hit_date >= :f3 THEN h.user_id END) AS people
        FROM usage_page_hits h
        WHERE h.channel = 'web' $meSql
        GROUP BY h.page
    ");
    $st->execute([':f1' => $from, ':f2' => $from, ':f3' => $from]);
    $hits = [];
    foreach ($st as $r) {
        $hits[$r['page']] = $r;
    }

    // Files on disk, plus anything that was opened but is not in the list
    // (a helper file somebody reached directly, a page since deleted).
    foreach (array_unique(array_merge($pages, array_keys($hits))) as $page) {
        $hit = $hits[$page] ?? null;
        $views = (int)($hit['views'] ?? 0);
        $saves = (int)($hit['saves'] ?? 0);
        if (!$hit) {
            $status = 'never';
        } elseif ($views + $saves === 0) {
            $status = 'idle';
        } elseif ($saves === 0) {
            $status = 'opened';
        } else {
            $status = 'used';
        }
        $pageArea = usage_report_area($page);
        $areas[$pageArea] = true;
        $totals['pages']++;
        $totals['never'] += $status === 'never' ? 1 : 0;
        $totals['unused'] += ($status === 'never' || $status === 'idle') ? 1 : 0;

        if ($area !== '' && $pageArea !== $area) {
            continue;
        }
        if ($q !== '' && stripos($page, $q) === false) {
            continue;
        }
        if ($show === 'unused' && !in_array($status, ['never', 'idle'], true)) {
            continue;
        }
        if ($show === 'dead' && !($status === 'never' && ($links[$page] ?? 0) === 0)) {
            continue;
        }
        if ($show === 'opened' && $status !== 'opened') {
            continue;
        }
        if ($show === 'used' && !in_array($status, ['opened', 'used'], true)) {
            continue;
        }
        $rows[] = [
            'page' => $page, 'area' => $pageArea, 'status' => $status,
            'last_at' => $hit['last_at'] ?? null, 'views' => $views, 'saves' => $saves,
            'people' => (int)($hit['people'] ?? 0),
            'links' => $links[$page] ?? null,
            'on_disk' => isset($links[$page]),
        ];
    }
    ksort($areas);
    $rank = ['never' => 0, 'idle' => 1, 'opened' => 2, 'used' => 3];
    usort($rows, static function (array $a, array $b) use ($rank): int {
        return [$rank[$a['status']], $a['views'] + $a['saves'], $a['page']]
           <=> [$rank[$b['status']], $b['views'] + $b['saves'], $b['page']];
    });
} elseif ($tableReady && $oneUser > 0) {
    $st = $conn->prepare("
        SELECT h.page, h.channel, SUM(h.views) AS views, SUM(h.saves) AS saves,
               COUNT(DISTINCT h.hit_date) AS days_used, MAX(h.last_at) AS last_at
        FROM usage_page_hits h
        WHERE h.channel <> 'web' AND h.user_id = ? AND h.hit_date >= ?
        GROUP BY h.page, h.channel
        ORDER BY last_at DESC
    ");
    $st->execute([$oneUser, $from]);
    $detail = $st->fetchAll();
    $st = $conn->prepare("SELECT COALESCE(NULLIF(fullname, ''), username) FROM user WHERE id = ?");
    $st->execute([$oneUser]);
    $oneUserName = (string)($st->fetchColumn() ?: 'User ' . $oneUser);
} elseif ($tableReady) {
    // Everyone holding a PIN, whether they have used the app or not — the
    // people who never signed in matter as much as the ones who did.
    $pinHolders = [];
    try {
        $pinHolders = $conn->query("
            SELECT p.user_id, COALESCE(NULLIF(u.fullname, ''), u.username) AS name, u.status
            FROM ops_staff_pins p JOIN user u ON u.id = p.user_id
        ")->fetchAll();
    } catch (Throwable $e) {
    }
    foreach ($pinHolders as $p) {
        $people[(int)$p['user_id']] = ['name' => $p['name'], 'pin' => true, 'active' => (int)$p['status'] === 1];
    }

    $st = $conn->prepare("
        SELECT h.user_id, COALESCE(NULLIF(u.fullname, ''), u.username) AS name,
               SUBSTRING_INDEX(h.page, '/', 1) AS app, h.channel,
               MAX(h.last_at) AS last_at,
               COUNT(DISTINCT CASE WHEN h.hit_date >= :f1 THEN h.hit_date END) AS days_used,
               SUM(CASE WHEN h.hit_date >= :f2 THEN h.saves ELSE 0 END) AS saves
        FROM usage_page_hits h
        LEFT JOIN user u ON u.id = h.user_id
        WHERE h.channel <> 'web'
        GROUP BY h.user_id, name, app, h.channel
    ");
    $st->execute([':f1' => $from, ':f2' => $from]);
    foreach ($st as $r) {
        $id = (int)$r['user_id'];
        $people[$id] ??= ['name' => $r['name'] ?: 'User ' . $id, 'pin' => false, 'active' => true];
        $slot = &$people[$id]['apps'][$r['app']];
        $slot['last_at'] = max($slot['last_at'] ?? '', (string)$r['last_at']);
        $slot['days'] = max($slot['days'] ?? 0, (int)$r['days_used']);
        $slot['saves'] = ($slot['saves'] ?? 0) + (int)$r['saves'];
        unset($slot);
        $seen = &$people[$id]['clients'][$r['channel']];
        $seen = max($seen ?? '', (string)$r['last_at']);
        unset($seen);
    }
    foreach ($people as $id => &$p) {
        $p['id'] = $id;
        $p['last_at'] = '';
        foreach ($p['apps'] ?? [] as $a) {
            $p['last_at'] = max($p['last_at'], $a['last_at']);
        }
        $p['in_period'] = $p['last_at'] !== '' && substr($p['last_at'], 0, 10) >= $from;
    }
    unset($p);
    if ($q !== '') {
        $people = array_filter($people, static fn($p) => stripos((string)$p['name'], $q) !== false);
    }
    if ($show === 'unused') {
        $people = array_filter($people, static fn($p) => !$p['in_period']);
    } elseif ($show === 'used') {
        $people = array_filter($people, static fn($p) => $p['in_period']);
    }
    usort($people, static fn($a, $b) => [$b['last_at'], $a['name']] <=> [$a['last_at'], $b['name']]);

    $st = $conn->prepare("
        SELECT h.page, SUM(h.views) AS views, SUM(h.saves) AS saves,
               COUNT(DISTINCT h.user_id) AS people, MAX(h.last_at) AS last_at
        FROM usage_page_hits h
        WHERE h.channel <> 'web' AND h.hit_date >= ?
        GROUP BY h.page
        ORDER BY h.page
    ");
    $st->execute([$from]);
    $actions = $st->fetchAll();
}

// ---------------------------------------------------------------------------
// Excel (CSV) download of whatever the filters show
// ---------------------------------------------------------------------------

if (($_GET['export'] ?? '') === 'csv' && $tableReady && $onePage === '' && $oneUser === 0) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="usage_' . $tab . '_' . $today . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($tab === 'pages') {
        fputcsv($out, ['Area', 'Page', 'Status', 'Last used', 'Opens', 'Saves', 'People', 'Linked from']);
        $labels = ['never' => 'Never used', 'idle' => 'Not used in period', 'opened' => 'Opened only', 'used' => 'Used'];
        foreach ($rows as $r) {
            fputcsv($out, [$r['area'], $r['page'], $labels[$r['status']], $r['last_at'] ?? '', $r['views'], $r['saves'],
                $r['people'], $r['on_disk'] ? $r['links'] : 'file not in page list']);
        }
    } else {
        fputcsv($out, ['Person', 'Has PIN', 'Cleaning last used', 'Driver last used', 'Days used in period', 'Came from']);
        foreach ($people as $p) {
            fputcsv($out, [
                $p['name'], $p['pin'] ? 'yes' : 'no',
                $p['apps']['cleaning']['last_at'] ?? '', $p['apps']['driver']['last_at'] ?? '',
                max($p['apps']['cleaning']['days'] ?? 0, $p['apps']['driver']['days'] ?? 0),
                implode(', ', array_map(static fn($c) => $clientNames[$c] ?? $c, array_keys($p['clients'] ?? []))),
            ]);
        }
    }
    fclose($out);
    exit;
}

$statusBadge = [
    'never' => ['Never used', 'danger'],
    'idle' => ['Not used in period', 'warning'],
    'opened' => ['Opened only', 'info'],
    'used' => ['Used', 'success'],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Usage report</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { background: #f6f7f9; }
  .page-path { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85rem; word-break: break-all; }
  .table td, .table th { vertical-align: middle; }
  .num { text-align: right; font-variant-numeric: tabular-nums; }
  .stat { background: #fff; border: 1px solid #e5e7eb; border-radius: .5rem; padding: .75rem 1rem; }
  .stat b { font-size: 1.4rem; display: block; }
</style>
</head>
<body>
<div class="container-fluid py-3" style="max-width:1300px">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h4 class="mb-0">Usage report</h4>
      <div class="text-muted small">
        <?php if ($since): ?>Counting since <?= h(date('d M Y', strtotime($since))) ?>.<?php else: ?>Nothing counted yet.<?php endif; ?>
        A page is only "never used" since that date.
      </div>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <form method="post" class="d-flex align-items-center gap-2">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="turn" value="<?= $trackingOff ? 'on' : 'off' ?>">
        <span class="badge text-bg-<?= $trackingOff ? 'secondary' : 'success' ?>">Counting is <?= $trackingOff ? 'OFF' : 'ON' ?></span>
        <button class="btn btn-sm btn-outline-secondary"><?= $trackingOff ? 'Turn on' : 'Turn off' ?></button>
      </form>
      <a class="btn btn-sm btn-outline-secondary" href="<?= h($appBase) ?>/settings.php">Back to Settings</a>
    </div>
  </div>

  <?php if (!$tableReady): ?>
    <div class="alert alert-warning">The table is missing. Run <code>migrations/usage_page_hits.sql</code> first.</div>
  <?php else: ?>

  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab === 'pages' ? 'active' : '' ?>" href="?tab=pages&amp;days=<?= $days ?>&amp;skipme=<?= $skipMe ? 1 : 0 ?>">Pages</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'app' ? 'active' : '' ?>" href="?tab=app&amp;days=<?= $days ?>&amp;skipme=<?= $skipMe ? 1 : 0 ?>">Staff app</a></li>
  </ul>

  <?php if ($onePage !== '' || $oneUser > 0): ?>
    <a class="btn btn-sm btn-outline-secondary mb-3" href="<?= h($keep([])) ?>">&larr; Back</a>
    <h6 class="page-path"><?= h($onePage !== '' ? $onePage : $oneUserName) ?> <span class="text-muted">· last <?= $days ?> days</span></h6>
    <div class="table-responsive bg-white border rounded">
      <table class="table table-sm table-hover mb-0">
        <thead><tr>
          <th><?= $onePage !== '' ? 'Person' : 'Action' ?></th>
          <?php if ($oneUser > 0): ?><th>Came from</th><?php endif; ?>
          <th class="num">Opens</th><th class="num">Saves</th><th class="num">Days used</th><th>Last used</th>
        </tr></thead>
        <tbody>
        <?php foreach ($detail as $d): ?>
          <tr>
            <?php if ($onePage !== ''): ?>
              <td><?= h((int)$d['user_id'] === 0 ? 'Not signed in (public / tenant)' : ($d['name'] ?: 'User ' . $d['user_id'])) ?></td>
            <?php else: ?>
              <td class="page-path"><?= h($d['page']) ?></td>
              <td><?= h($clientNames[$d['channel']] ?? $d['channel']) ?></td>
            <?php endif; ?>
            <td class="num"><?= (int)$d['views'] ?></td>
            <td class="num"><?= (int)$d['saves'] ?></td>
            <td class="num"><?= (int)$d['days_used'] ?></td>
            <td><?= h($when($d['last_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$detail): ?><tr><td colspan="6" class="text-center text-muted py-4">Nothing in this period.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

  <?php else: ?>

  <form method="get" class="row g-2 align-items-end mb-3">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <div class="col-auto">
      <label class="form-label small mb-1">Period</label>
      <select name="days" class="form-select form-select-sm">
        <?php foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last 12 months'] as $d => $label): ?>
          <option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($tab === 'pages'): ?>
    <div class="col-auto">
      <label class="form-label small mb-1">Area</label>
      <select name="area" class="form-select form-select-sm">
        <option value="">All areas</option>
        <?php foreach (array_keys($areas) as $a): ?>
          <option value="<?= h($a) ?>" <?= $area === $a ? 'selected' : '' ?>><?= h($a) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="col-auto">
      <label class="form-label small mb-1">Show</label>
      <select name="show" class="form-select form-select-sm">
        <option value="all">Everything</option>
        <option value="unused" <?= $show === 'unused' ? 'selected' : '' ?>>Not used</option>
        <?php if ($tab === 'pages'): ?>
        <option value="dead" <?= $show === 'dead' ? 'selected' : '' ?>>Never used and nothing links to it</option>
        <option value="opened" <?= $show === 'opened' ? 'selected' : '' ?>>Opened, never saved</option>
        <?php endif; ?>
        <option value="used" <?= $show === 'used' ? 'selected' : '' ?>>Used</option>
      </select>
    </div>
    <div class="col-auto">
      <label class="form-label small mb-1">Search</label>
      <input name="q" value="<?= h($q) ?>" class="form-control form-control-sm" placeholder="<?= $tab === 'pages' ? 'part of the page name' : 'name' ?>">
    </div>
    <?php if ($tab === 'pages'): ?>
    <div class="col-auto form-check ms-2 mb-1">
      <input class="form-check-input" type="checkbox" name="skipme" value="1" id="skipme" <?= $skipMe ? 'checked' : '' ?>>
      <label class="form-check-label small" for="skipme">Leave out my own visits</label>
    </div>
    <?php endif; ?>
    <div class="col-auto">
      <button class="btn btn-sm btn-primary">Apply</button>
      <a class="btn btn-sm btn-outline-secondary" href="<?= h($keep(['export' => 'csv'])) ?>">Excel</a>
    </div>
  </form>

  <?php if ($tab === 'pages'): ?>
    <div class="row g-2 mb-3">
      <div class="col-auto"><div class="stat"><b><?= $totals['pages'] ?></b><span class="small text-muted">pages in the app</span></div></div>
      <div class="col-auto"><div class="stat"><b><?= $totals['unused'] ?></b><span class="small text-muted">not used in the last <?= $days ?> days</span></div></div>
      <div class="col-auto"><div class="stat"><b><?= $totals['never'] ?></b><span class="small text-muted">never used since counting began</span></div></div>
      <div class="col-auto"><div class="stat"><b><?= count($rows) ?></b><span class="small text-muted">shown below</span></div></div>
    </div>
    <div class="table-responsive bg-white border rounded">
      <table class="table table-sm table-hover mb-0">
        <thead><tr>
          <th>Area</th><th>Page</th><th>Status</th><th>Last used</th>
          <th class="num">Opens</th><th class="num">Saves</th><th class="num">People</th>
          <th class="num" title="How many other files mention this page. A rough guide.">Linked from</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): [$label, $colour] = $statusBadge[$r['status']]; ?>
          <tr>
            <td><?= h($r['area']) ?></td>
            <td class="page-path">
              <?php if ($r['status'] === 'never'): ?><?= h($r['page']) ?>
              <?php else: ?><a href="<?= h($keep(['page' => $r['page']])) ?>"><?= h($r['page']) ?></a><?php endif; ?>
              <?php if (!$r['on_disk']): ?><span class="badge text-bg-light border">not in page list</span><?php endif; ?>
            </td>
            <td><span class="badge text-bg-<?= $colour ?>"><?= $label ?></span></td>
            <td class="small"><?= h($when($r['last_at'])) ?></td>
            <td class="num"><?= $r['views'] ?></td>
            <td class="num"><?= $r['saves'] ?></td>
            <td class="num"><?= $r['people'] ?></td>
            <td class="num"><?= $r['on_disk'] ? (int)$r['links'] : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">Nothing matches.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <p class="small text-muted mt-2 mb-0">
      Opens = the page was loaded. Saves = a form was sent. "Linked from" counts other files that mention the page;
      it is a guide, not proof. Pages used once a year (year end, VAT) will look unused until a year has passed.
    </p>

  <?php else: ?>
    <h6>People</h6>
    <div class="table-responsive bg-white border rounded mb-4">
      <table class="table table-sm table-hover mb-0">
        <thead><tr>
          <th>Person</th><th>Cleaning</th><th>Driver</th>
          <th class="num">Days used (last <?= $days ?>)</th><th>Came from</th>
        </tr></thead>
        <tbody>
        <?php foreach ($people as $p): ?>
          <tr>
            <td>
              <?php if ($p['last_at'] !== ''): ?><a href="<?= h($keep(['user' => $p['id']])) ?>"><?= h($p['name']) ?></a>
              <?php else: ?><?= h($p['name']) ?> <span class="badge text-bg-danger">Never used</span><?php endif; ?>
              <?php if ($p['last_at'] !== '' && !$p['in_period']): ?><span class="badge text-bg-warning">Stopped</span><?php endif; ?>
              <?php if (!$p['pin']): ?><span class="badge text-bg-light border">no PIN now</span><?php endif; ?>
              <?php if (!$p['active']): ?><span class="badge text-bg-light border">account closed</span><?php endif; ?>
            </td>
            <td class="small"><?= h($when($p['apps']['cleaning']['last_at'] ?? null)) ?></td>
            <td class="small"><?= h($when($p['apps']['driver']['last_at'] ?? null)) ?></td>
            <td class="num"><?= max($p['apps']['cleaning']['days'] ?? 0, $p['apps']['driver']['days'] ?? 0) ?></td>
            <td class="small">
              <?php foreach ($p['clients'] ?? [] as $client => $at): ?>
                <div><?= h($clientNames[$client] ?? $client) ?> <span class="text-muted">· <?= h($when($at)) ?></span></div>
              <?php endforeach; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$people): ?><tr><td colspan="5" class="text-center text-muted py-4">Nobody matches.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <h6>What the app is used for <span class="text-muted fw-normal small">· last <?= $days ?> days</span></h6>
    <div class="table-responsive bg-white border rounded">
      <table class="table table-sm table-hover mb-0">
        <thead><tr><th>Action</th><th class="num">Opens</th><th class="num">Saves</th><th class="num">People</th><th>Last used</th></tr></thead>
        <tbody>
        <?php foreach ($actions as $a): ?>
          <tr>
            <td class="page-path"><?= h($a['page']) ?></td>
            <td class="num"><?= (int)$a['views'] ?></td>
            <td class="num"><?= (int)$a['saves'] ?></td>
            <td class="num"><?= (int)$a['people'] ?></td>
            <td class="small"><?= h($when($a['last_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$actions): ?><tr><td colspan="5" class="text-center text-muted py-4">No app activity in this period.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <p class="small text-muted mt-2 mb-0">
      Everyone with a PIN is listed, used or not. Taps made offline are counted when the phone next has signal.
    </p>
  <?php endif; ?>
  <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
