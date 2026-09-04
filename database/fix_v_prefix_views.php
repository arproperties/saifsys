<?php
/**
 * Repair misclassified v_* objects: empty InnoDB tables that should be MySQL VIEWs.
 *
 * Root cause: mysqldump/import sometimes materialized views as empty tables.
 * Safe here: fake v_* tables have 0 rows and are not real business tables.
 * Excludes real table `vendors` (does not match v_ prefix pattern).
 *
 * Usage:
 *   php database/fix_v_prefix_views.php              # preview
 *   php database/fix_v_prefix_views.php --apply      # fix localhost
 *
 * Browser (Owner/Admin):
 *   http://localhost/herosysgro/database/fix_v_prefix_views.php
 */

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/db_connect.php';

class ErpViewsRepair
{
    /** Creation order — dependencies first (matches live server). */
    public const VIEW_ORDER = [
        'v_ar_invoices_open',
        'v_ar_open_invoices',
        'v_ar_invoices_open_optimized',
        'v_ar_ageing_optimized',
        'v_ar_summary_optimized',
        'v_ar_ageing',
        'v_ar_ageing_by_client',
        'v_ar_client_summary',
        'v_ar_overall',
        'v_ar_summary',
        'v_ar_client_credit',
        'v_ar_client_statement',
        'v_attendance_daily_emp',
        'v_attendance_period_emp',
        'v_worker_order_contrib',
        'v_worker_daily_perf',
        'v_worker_employee_map',
        'v_bookable_workers',
        'v_categories_tree',
        'v_client_unbilled_summary',
        'v_driver',
        'v_index_usage',
        'v_leave_balances_current',
        'v_online_bookings_summary',
        'v_profit_and_loss',
        'v_services_enhanced',
        'v_slow_queries',
        'v_table_sizes',
        'v_trial_balance',
        'v_workers',
    ];

    private PDO $conn;
    private string $database;
    private string $sqlFile;

    public function __construct(PDO $conn, string $database, string $sqlFile)
    {
        $this->conn = $conn;
        $this->database = $database;
        $this->sqlFile = $sqlFile;
    }

    public static function quoteIdent(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function loadViewDefinitions(): array
    {
        if (!is_file($this->sqlFile)) {
            throw new RuntimeException('View SQL file not found: ' . $this->sqlFile);
        }
        $sql = file_get_contents($this->sqlFile);
        $views = [];
        if (preg_match_all('/CREATE\s+(?:OR\s+REPLACE\s+)?(?:ALGORITHM=\w+\s+)?(?:SQL\s+SECURITY\s+\w+\s+)?VIEW\s+`([^`]+)`\s+AS\s+(.*?);/is', $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $name = $m[1];
                $body = trim($m[2]);
                $views[$name] = 'CREATE OR REPLACE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW '
                    . self::quoteIdent($name) . ' AS ' . $body;
            }
        }
        return $views;
    }

    public function audit(): array
    {
        $stmt = $this->conn->prepare("
            SELECT TABLE_NAME, TABLE_TYPE
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE 'v\\_%' ESCAPE '\\\\'
            ORDER BY TABLE_NAME
        ");
        $stmt->execute([$this->database]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $misclassified = [];
        $correctViews = [];
        $missing = [];

        foreach ($rows as $row) {
            if ($row['TABLE_TYPE'] === 'BASE TABLE') {
                $misclassified[] = $row['TABLE_NAME'];
            } else {
                $correctViews[] = $row['TABLE_NAME'];
            }
        }

        $definitions = $this->loadViewDefinitions();
        foreach (self::VIEW_ORDER as $name) {
            if (!in_array($name, $misclassified, true) && !in_array($name, $correctViews, true)) {
                $missing[] = $name;
            }
        }

        return [
            'misclassified_tables' => $misclassified,
            'existing_views' => $correctViews,
            'missing_views' => $missing,
            'expected_count' => count(self::VIEW_ORDER),
            'definitions_loaded' => count($definitions),
        ];
    }

    public function buildPlan(): array
    {
        $audit = $this->audit();
        $definitions = $this->loadViewDefinitions();
        $steps = [];

        foreach (array_reverse(self::VIEW_ORDER) as $name) {
            $steps[] = [
                'phase' => 'drop_view',
                'object' => $name,
                'sql' => 'DROP VIEW IF EXISTS ' . self::quoteIdent($name),
            ];
        }

        foreach ($audit['misclassified_tables'] as $name) {
            $steps[] = [
                'phase' => 'drop_table',
                'object' => $name,
                'sql' => 'DROP TABLE IF EXISTS ' . self::quoteIdent($name),
                'reason' => 'Misclassified empty table (should be a view)',
            ];
        }

        foreach (self::VIEW_ORDER as $name) {
            if (!isset($definitions[$name])) {
                $steps[] = [
                    'phase' => 'error',
                    'object' => $name,
                    'sql' => '',
                    'reason' => 'Missing CREATE VIEW definition in erp_schema_views.sql',
                ];
                continue;
            }
            $steps[] = [
                'phase' => 'create_view',
                'object' => $name,
                'sql' => $definitions[$name],
            ];
        }

        return ['audit' => $audit, 'steps' => $steps];
    }

    public function apply(bool $dryRun = true): array
    {
        $plan = $this->buildPlan();
        $result = [
            'dry_run' => $dryRun,
            'executed' => [],
            'failed' => [],
            'skipped' => [],
        ];

        if ($dryRun) {
            return $result + ['plan' => $plan];
        }

        foreach ($plan['steps'] as $step) {
            if (($step['phase'] ?? '') === 'error') {
                $result['failed'][] = $step;
                continue;
            }
            $sql = trim($step['sql'] ?? '');
            if ($sql === '') {
                continue;
            }
            try {
                $this->conn->exec($sql);
                $result['executed'][] = $step;
            } catch (Throwable $e) {
                $step['error'] = $e->getMessage();
                $result['failed'][] = $step;
            }
        }

        $result['audit_after'] = $this->audit();
        return $result;
    }
}

$isCli = (PHP_SAPI === 'cli');
$apply = $isCli ? in_array('--apply', $argv ?? [], true) : (($_POST['action'] ?? '') === 'apply');

if (!$isCli) {
    require_once dirname(__DIR__) . '/includes/auth.php';
    require_role(['Owner', 'Admin'], $conn);
    header('Content-Type: text/html; charset=utf-8');
}

$sqlFile = dirname(__DIR__) . '/database/views/erp_schema_views.sql';
$repair = new ErpViewsRepair($conn, DB_NAME, $sqlFile);

try {
    if ($apply) {
        $out = $repair->apply(false);
        $audit = $out['audit_after'] ?? $repair->audit();
    } else {
        $out = $repair->apply(true);
        $audit = $out['plan']['audit'] ?? $repair->audit();
    }
} catch (Throwable $e) {
    if ($isCli) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        exit(1);
    }
    echo '<div class="alert alert-danger m-4">' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}

if ($isCli) {
    echo ($apply ? "APPLY" : "PREVIEW") . " — ERP views repair\n";
    echo str_repeat('-', 50) . "\n";
    echo "Expected views: {$audit['expected_count']}\n";
    echo "Definitions loaded: {$audit['definitions_loaded']}\n";
    echo "Misclassified tables: " . count($audit['misclassified_tables']) . "\n";
    if ($audit['misclassified_tables']) {
        echo "  " . implode(', ', $audit['misclassified_tables']) . "\n";
    }
    echo "Existing views: " . count($audit['existing_views']) . "\n";
    echo "Missing views: " . count($audit['missing_views']) . "\n";
    if ($audit['missing_views']) {
        echo "  " . implode(', ', $audit['missing_views']) . "\n";
    }
    if ($apply) {
        echo "\nExecuted: " . count($out['executed'] ?? []) . "\n";
        echo "Failed: " . count($out['failed'] ?? []) . "\n";
        foreach ($out['failed'] ?? [] as $f) {
            echo "  FAIL {$f['object']}: {$f['error']}\n";
        }
        echo "\nAfter repair — views: " . count($audit['existing_views']) . ", misclassified: " . count($audit['misclassified_tables']) . "\n";
    } else {
        echo "\nRun with --apply to convert misclassified v_* tables to views.\n";
    }
    exit(empty($out['failed']) ? 0 : 1);
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$plan = $out['plan'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Fix v_* Views</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
<div class="container">
    <h1>Repair misclassified <code>v_*</code> views</h1>
    <p class="text-muted">Converts empty InnoDB tables named <code>v_*</code> into proper MySQL views (30 total, matching live server).</p>

    <?php if ($apply): ?>
    <div class="alert alert-<?= empty($out['failed']) ? 'success' : 'warning' ?>">
        Repair finished. Executed <?= count($out['executed'] ?? []) ?> step(s).
        <?php if (!empty($out['failed'])): ?> <?= count($out['failed']) ?> failed.<?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="fs-4"><?= (int)$audit['expected_count'] ?></div><div class="text-muted small">Expected views</div></div></div></div>
        <div class="col-md-3"><div class="card border-danger"><div class="card-body"><div class="fs-4 text-danger"><?= count($audit['misclassified_tables']) ?></div><div class="text-muted small">Misclassified tables</div></div></div></div>
        <div class="col-md-3"><div class="card border-success"><div class="card-body"><div class="fs-4 text-success"><?= count($audit['existing_views']) ?></div><div class="text-muted small">Correct views now</div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="fs-4"><?= count($audit['missing_views']) ?></div><div class="text-muted small">Missing entirely</div></div></div></div>
    </div>

    <?php if ($audit['misclassified_tables']): ?>
    <h2 class="h5">Will convert these tables → views</h2>
    <p><code><?= h(implode(', ', $audit['misclassified_tables'])) ?></code></p>
    <?php endif; ?>

    <?php if (!$apply && $plan): ?>
    <form method="post" onsubmit="return confirm('Drop misclassified v_* tables and recreate all 30 views? Tables are empty placeholders.');">
        <input type="hidden" name="action" value="apply">
        <button type="submit" class="btn btn-danger">Apply repair on localhost</button>
        <a href="?" class="btn btn-outline-secondary">Refresh preview</a>
    </form>
    <?php else: ?>
    <a href="?" class="btn btn-outline-primary">Preview again</a>
    <?php endif; ?>

    <p class="text-muted small mt-4">Real table <code>vendors</code> is not affected. After repair, re-run <code>database/export_schema_snapshot.php</code>.</p>
</div>
</body>
</html>
