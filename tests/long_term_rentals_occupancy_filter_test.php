<?php
/**
 * Regression tests: Find Your Home occupancy filter
 * (api/mobile/long_term_rentals.php → ltr_base_where_sql).
 *
 * Run: php tests/long_term_rentals_occupancy_filter_test.php
 */
declare(strict_types=1);

function assert_true(bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $label);
    }
}

function assert_equals($expected, $actual, string $label): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            "Assertion failed: {$label}. Expected " . var_export($expected, true)
            . ' got ' . var_export($actual, true)
        );
    }
}

$apiFile = dirname(__DIR__) . '/api/mobile/long_term_rentals.php';
$src = file_get_contents($apiFile);
assert_true(is_string($src) && $src !== '', 'API source readable');

// Source-level contract: only non-deleted active leases block.
assert_true(
    (bool)preg_match("/l\\.status\\s*=\\s*'active'/", $src),
    "API source blocks status = 'active'"
);
assert_true(
    str_contains($src, 'l.deleted_at IS NULL'),
    'API source requires deleted_at IS NULL'
);
assert_true(
    !preg_match("/l\\.status\\s+IN\\s*\\(\\s*'draft'\\s*,\\s*'active'\\s*,\\s*'renewed'\\s*\\)/", $src),
    'API source no longer blocks draft/active/renewed set'
);

/**
 * Mirror of the listing occupancy predicate used by ltr_base_where_sql().
 * Kept inline so this test does not bootstrap the full mobile API router.
 */
function ltr_unit_passes_occupancy(PDO $conn, int $unitId): bool {
    $sql = "
        SELECT u.id
        FROM re_units u
        JOIN re_buildings b ON b.id = u.building_id AND b.company_id = u.company_id AND b.is_active = 1
        WHERE u.id = ?
          AND u.publish_to_mobile = 1
          AND u.rental_mode IN ('long_term', 'both')
          AND u.status = 'vacant'
          AND NOT EXISTS (
              SELECT 1
              FROM re_leases l
              LEFT JOIN re_lease_units lu ON lu.lease_id = l.id
              WHERE l.company_id = u.company_id
                AND l.status = 'active'
                AND l.deleted_at IS NULL
                AND (l.unit_id = u.id OR lu.unit_id = u.id)
              LIMIT 1
          )
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$unitId]);
    return (bool)$stmt->fetchColumn();
}

$conn = new PDO('sqlite::memory:');
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$conn->exec("
    CREATE TABLE re_buildings (
        id INTEGER PRIMARY KEY,
        company_id INTEGER NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 1
    )
");
$conn->exec("
    CREATE TABLE re_units (
        id INTEGER PRIMARY KEY,
        company_id INTEGER NOT NULL,
        building_id INTEGER NOT NULL,
        status TEXT NOT NULL,
        rental_mode TEXT NOT NULL,
        publish_to_mobile INTEGER NOT NULL DEFAULT 0
    )
");
$conn->exec("
    CREATE TABLE re_leases (
        id INTEGER PRIMARY KEY,
        company_id INTEGER NOT NULL,
        unit_id INTEGER NOT NULL,
        status TEXT NOT NULL,
        deleted_at TEXT NULL
    )
");
$conn->exec("
    CREATE TABLE re_lease_units (
        id INTEGER PRIMARY KEY,
        lease_id INTEGER NOT NULL,
        unit_id INTEGER NOT NULL
    )
");

$conn->exec("INSERT INTO re_buildings (id, company_id, is_active) VALUES (1, 2, 1)");

function seed_published_vacant(PDO $conn, int $unitId): void {
    $conn->prepare("
        INSERT INTO re_units (id, company_id, building_id, status, rental_mode, publish_to_mobile)
        VALUES (?, 2, 1, 'vacant', 'long_term', 1)
    ")->execute([$unitId]);
}

$cases = [];

// Published + Vacant + No lease → Visible
seed_published_vacant($conn, 101);
$cases['Published + Vacant + No lease → Visible'] = ltr_unit_passes_occupancy($conn, 101) === true;

// Published + Vacant + Draft lease → Visible
seed_published_vacant($conn, 102);
$conn->exec("INSERT INTO re_leases (id, company_id, unit_id, status, deleted_at) VALUES (1021, 2, 102, 'draft', NULL)");
$cases['Published + Vacant + Draft lease → Visible'] = ltr_unit_passes_occupancy($conn, 102) === true;

// Published + Vacant + Expired lease → Visible
seed_published_vacant($conn, 103);
$conn->exec("INSERT INTO re_leases (id, company_id, unit_id, status, deleted_at) VALUES (1031, 2, 103, 'expired', NULL)");
$cases['Published + Vacant + Expired lease → Visible'] = ltr_unit_passes_occupancy($conn, 103) === true;

// Published + Vacant + Deleted lease → Visible (soft-deleted draft leftover)
seed_published_vacant($conn, 104);
$conn->exec("INSERT INTO re_leases (id, company_id, unit_id, status, deleted_at) VALUES (1041, 2, 104, 'draft', '2026-07-01 00:00:00')");
$cases['Published + Vacant + Deleted lease → Visible'] = ltr_unit_passes_occupancy($conn, 104) === true;

// Published + Active lease → Hidden
seed_published_vacant($conn, 105);
$conn->exec("INSERT INTO re_leases (id, company_id, unit_id, status, deleted_at) VALUES (1051, 2, 105, 'active', NULL)");
$cases['Published + Active lease → Hidden'] = ltr_unit_passes_occupancy($conn, 105) === false;

// Extra: renewed (historical) must not block — ERP superseded lease
seed_published_vacant($conn, 106);
$conn->exec("INSERT INTO re_leases (id, company_id, unit_id, status, deleted_at) VALUES (1061, 2, 106, 'renewed', NULL)");
$cases['Published + Vacant + Renewed lease → Visible'] = ltr_unit_passes_occupancy($conn, 106) === true;

// Extra: Unit 85 production shape — vacant published + draft + expired → Visible
seed_published_vacant($conn, 85);
$conn->exec("INSERT INTO re_leases (id, company_id, unit_id, status, deleted_at) VALUES (65, 2, 85, 'expired', NULL)");
$conn->exec("INSERT INTO re_lease_units (id, lease_id, unit_id) VALUES (1, 65, 85)");
$conn->exec("INSERT INTO re_leases (id, company_id, unit_id, status, deleted_at) VALUES (383, 2, 85, 'draft', NULL)");
$cases['Unit 85 production shape (draft+expired) → Visible'] = ltr_unit_passes_occupancy($conn, 85) === true;

$failed = [];
foreach ($cases as $label => $ok) {
    if (!$ok) {
        $failed[] = $label;
    }
    echo ($ok ? 'PASS' : 'FAIL') . " — {$label}\n";
}

if ($failed) {
    fwrite(STDERR, "\n" . count($failed) . " failure(s)\n");
    exit(1);
}

echo "\nAll " . count($cases) . " occupancy filter regression checks passed.\n";
exit(0);
