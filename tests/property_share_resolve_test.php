<?php
/**
 * Smoke tests for property share resolve (unavailable-safe).
 * Run: php tests/property_share_resolve_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/app_mobile_config_helper.php';
require_once __DIR__ . '/../includes/property_share_helper.php';

$failed = 0;
$passed = 0;

function assert_true(bool $cond, string $label): void
{
    global $failed, $passed;
    if ($cond) {
        echo "PASS: {$label}\n";
        $passed++;
    } else {
        echo "FAIL: {$label}\n";
        $failed++;
    }
}

re_property_share_ensure_schema($conn);
app_mobile_config_ensure_schema($conn);

$bogus = re_property_share_resolve($conn, '00000000-0000-4000-8000-000000000000');
assert_true($bogus['available'] === false, 'unknown code is unavailable');
assert_true(
    !isset($bogus['listing_title']) && empty($bogus['unit_id']),
    'unavailable response has no unit_id / listing fields'
);
assert_true(
    ($bogus['message'] ?? '') === 'This property is no longer available.',
    'friendly unavailable message'
);

$invalid = re_property_share_resolve($conn, 'bad');
assert_true($invalid['available'] === false, 'invalid code format unavailable');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
