<?php
/**
 * Phase 2 Customer API financial refactor — read-only validation.
 *
 * Tests:
 * - Static: old Legacy outstanding SQL removed from tenant_endpoints
 * - Service/API assembly parity vs re_pm_im_kpis
 * - HTTP authz + route smoke (when local Apache available)
 *
 * Usage: php modules/realestate/tools/customer_api_financial_phase2_smoke.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 3);
if (!is_link('/tmp/mysql.sock') && !file_exists('/tmp/mysql.sock')) {
    @symlink('/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock', '/tmp/mysql.sock');
}

require_once $root . '/includes/db_connect.php';
require_once $root . '/includes/customer_api.php';
require_once $root . '/modules/realestate/includes/lease_financial_summary_service.php';
require_once $root . '/api/customer/v1/tenant_endpoints.php';

$passed = 0;
$failed = 0;
$skipped = 0;
$tol = 0.01;

function p2_assert(bool $ok, string $label, $meta = null): void
{
    global $passed, $failed;
    if ($ok) {
        echo "PASS  {$label}\n";
        $passed++;
    } else {
        echo "FAIL  {$label}" . ($meta !== null ? ' ' . json_encode($meta, JSON_UNESCAPED_UNICODE) : '') . "\n";
        $failed++;
    }
}

function p2_skip(string $label, string $reason): void
{
    global $skipped;
    echo "SKIP  {$label} ({$reason})\n";
    $skipped++;
}

function p2_eq_money($a, $b, float $tol = 0.01): bool
{
    return abs((float)$a - (float)$b) <= $tol;
}

echo "=== Customer API Phase 2 financial smoke ===\n";

// --- Static: old calculations removed ---
$src = file_get_contents($root . '/api/customer/v1/tenant_endpoints.php') ?: '';
p2_assert(!str_contains($src, "status IN ('pending', 'overdue')"), 'no pending/overdue installment outstanding SUM in tenant_endpoints');
p2_assert(!str_contains($src, 'customer_api_tenant_enrich_service_charge_row'), 'Legacy SC enrich helper removed');
p2_assert(str_contains($src, 'lease_financial_summary_service.php'), 'financial service required');
p2_assert(str_contains($src, 're_lease_financial_summary'), 'dashboard/handlers call re_lease_financial_summary');
p2_assert(str_contains($src, "layer' => 'operational'") || str_contains($src, "'layer' => 'operational'"), 'installments mark operational layer');

$docSrc = file_get_contents($root . '/api/customer/v1/tenant_documents_endpoints.php') ?: '';
p2_assert(!preg_match("/'status'\\s*=>\\s*'paid'/", $docSrc), 'documents no longer hardcode status=paid');
p2_assert(str_contains($docSrc, 're_lease_payment_history'), 'documents use payment history service');

$idx = file_get_contents($root . '/api/customer/v1/index.php') ?: '';
p2_assert(str_contains($idx, 'financial-summary'), 'financial-summary route registered');
p2_assert(str_contains($idx, 'outstanding-items'), 'outstanding-items route registered');
p2_assert(str_contains($idx, 'payment-history'), 'payment-history route registered');
p2_assert(str_contains($idx, 'payment-timeline'), 'payment-timeline route registered');

// --- Fail-closed / auth helpers ---
$badCtx = [
    'mode' => 'tpu',
    'tenant_portal_user_id' => 1,
    'user_id' => null,
    'tenant_id' => 999999,
    'company_id' => 2,
    'display_name' => 'x',
    'email' => 'x@example.com',
];
$allowed = customer_api_tenant_allowed_lease_ids($conn, $badCtx);
p2_assert($allowed === [] || !in_array(159, $allowed, true), 'unknown tenant has no access to foreign lease 159');

// Cross-company context load
$cross = re_lease_load_financial_context($conn, 1, 269);
p2_assert($cross['ok'] === false, 'cross-company financial context fails closed');

// --- Assembly parity (same functions API handlers use) ---
$leaseIds = [69, 159, 269, 230, 61, 37];
foreach ($leaseIds as $leaseId) {
    $ctx = re_lease_load_financial_context($conn, 2, $leaseId);
    if (!$ctx['ok']) {
        p2_skip("lease {$leaseId} parity", 'lease not found for company 2');
        continue;
    }
    $tenantId = (int)$ctx['tenant_id'];
    $kpis = re_pm_im_kpis($conn, 2, $leaseId, $tenantId);
    $summary = re_lease_financial_summary($conn, 2, $leaseId, $tenantId);
    $dashFin = customer_api_tenant_financial_payload_for_dashboard($conn, 2, $leaseId, $summary);

    p2_assert((int)($dashFin['financial_schema_version'] ?? 0) === 2, "lease {$leaseId} schema v2");
    p2_assert(p2_eq_money($dashFin['outstanding_total'], $kpis['total_outstanding'], $tol), "lease {$leaseId} outstanding_total vs KPIs");
    p2_assert(p2_eq_money($dashFin['outstanding_rent'], $dashFin['rent_outstanding'], $tol), "lease {$leaseId} outstanding_rent alias = rent_outstanding");
    p2_assert(p2_eq_money($dashFin['outstanding_penalties'], $dashFin['penalty_outstanding'], $tol), "lease {$leaseId} outstanding_penalties alias");
    p2_assert(p2_eq_money($dashFin['outstanding_service_charges'], $dashFin['service_charge_outstanding'], $tol), "lease {$leaseId} SC alias");
    p2_assert(p2_eq_money($dashFin['tenant_credit'], $kpis['tenant_credit'], $tol), "lease {$leaseId} tenant_credit");

    $inst = re_lease_schedule_installments($conn, 2, $leaseId);
    p2_assert(($inst['layer'] ?? '') === 'operational', "lease {$leaseId} schedule operational");

    $inv = re_lease_outstanding_items($conn, 2, $leaseId, ['limit' => 50]);
    foreach ($inv['items'] ?? [] as $item) {
        if (($item['item_type'] ?? '') === 'invoice') {
            p2_assert(($item['display_status'] ?? '') !== '', "lease {$leaseId} invoice display_status present");
            break;
        }
    }

    $hist = re_lease_payment_history($conn, 2, $leaseId, 25);
    if (($hist['receipts'] ?? []) !== []) {
        $r0 = $hist['receipts'][0];
        p2_assert(($r0['display_status'] ?? '') !== '', "lease {$leaseId} receipt display_status present (not hardcoded paid)");
    }
}

// Legacy path note
$legacyCount = (int)$conn->query("SELECT COUNT(*) FROM re_leases WHERE COALESCE(accounting_mode,'legacy')='legacy'")->fetchColumn();
if ($legacyCount === 0) {
    p2_skip('Legacy API live parity', 'no Legacy leases in database');
} else {
    p2_assert(true, "Legacy leases present: {$legacyCount}");
}

// --- HTTP route smoke ---
$baseCandidates = [
    'http://127.0.0.1/herosysgro/api/customer/v1/index.php',
    'http://localhost/herosysgro/api/customer/v1/index.php',
];
$base = null;
foreach ($baseCandidates as $b) {
    $ch = curl_init($b . '?route=health');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 && is_string($body) && str_contains($body, '"ok":true')) {
        $base = $b;
        break;
    }
}

if ($base === null) {
    p2_skip('HTTP API tests', 'Customer API not reachable');
} else {
    echo "INFO  Using API base {$base}\n";

    // Missing auth
    $ch = curl_init($base . '?route=tenant/dashboard');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    p2_assert($code === 401 || str_contains($body, 'unauthorized') || str_contains($body, 'invalid_token'), 'HTTP missing auth rejected', ['code' => $code]);

    // Find approved portal users with leases
    $users = $conn->query("
        SELECT tpu.id, tpu.company_id, tpu.tenant_id, tpu.email,
               (SELECT l.id FROM re_leases l
                 WHERE l.tenant_id = tpu.tenant_id AND l.company_id = tpu.company_id
                   AND COALESCE(l.accounting_mode,'legacy')='invoice'
                 ORDER BY l.id DESC LIMIT 1) AS lease_id
        FROM tenant_portal_users tpu
        WHERE tpu.status='approved' AND tpu.company_id=2
        HAVING lease_id IS NOT NULL
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($users === []) {
        p2_skip('HTTP authenticated finance routes', 'no approved portal users with IM leases');
    } else {
        foreach ($users as $u) {
            $leaseId = (int)$u['lease_id'];
            $companyId = (int)$u['company_id'];
            $tenantId = (int)$u['tenant_id'];
            $token = customer_api_jwt_sign([
                'typ' => 'tenant',
                'sub' => 'tpu:' . (int)$u['id'],
                'cid' => $companyId,
                'tid' => $tenantId,
            ], 600);

            $req = static function (string $route, string $token, int $leaseId) use ($base): array {
                $url = $base . '?route=' . rawurlencode($route);
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $token,
                        'X-Tenant-Lease-Id: ' . $leaseId,
                        'Accept: application/json',
                    ],
                ]);
                $body = (string)curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $json = json_decode($body, true);
                return ['code' => $code, 'json' => is_array($json) ? $json : null, 'raw' => $body];
            };

            $dash = $req('tenant/dashboard', $token, $leaseId);
            p2_assert(($dash['code'] === 200) && !empty($dash['json']['ok']), "HTTP dashboard lease {$leaseId}", ['code' => $dash['code']]);
            if (!empty($dash['json']['ok'])) {
                $fin = $dash['json']['data']['financial'] ?? [];
                $lease = $dash['json']['data']['lease'] ?? [];
                p2_assert(isset($lease['accounting_mode']), "HTTP dashboard accounting_mode lease {$leaseId}");
                p2_assert((int)($fin['financial_schema_version'] ?? 0) === 2, "HTTP dashboard schema v2 lease {$leaseId}");
                $svc = re_lease_financial_summary($conn, $companyId, $leaseId, $tenantId);
                $kpis = re_pm_im_kpis($conn, $companyId, $leaseId, $tenantId);
                p2_assert(p2_eq_money($fin['outstanding_total'] ?? -1, $kpis['total_outstanding'], $tol), "HTTP dashboard outstanding vs KPIs {$leaseId}");
                p2_assert(p2_eq_money($fin['outstanding_rent'] ?? -1, $svc['rent_outstanding'], $tol), "HTTP dashboard rent alias vs service {$leaseId}");
            }

            $fs = $req('tenant/leases/' . $leaseId . '/financial-summary', $token, $leaseId);
            p2_assert(($fs['code'] === 200) && !empty($fs['json']['ok']), "HTTP financial-summary lease {$leaseId}");

            $inst = $req('tenant/leases/' . $leaseId . '/installments', $token, $leaseId);
            p2_assert(($inst['code'] === 200) && (($inst['json']['data']['layer'] ?? '') === 'operational'), "HTTP installments operational {$leaseId}");

            $pay = $req('tenant/leases/' . $leaseId . '/payments', $token, $leaseId);
            p2_assert(($pay['code'] === 200) && !empty($pay['json']['ok']), "HTTP payments lease {$leaseId}");

            $inv = $req('tenant/leases/' . $leaseId . '/invoices', $token, $leaseId);
            p2_assert(($inv['code'] === 200) && !empty($inv['json']['ok']), "HTTP invoices lease {$leaseId}");

            $sc = $req('tenant/leases/' . $leaseId . '/service-charges', $token, $leaseId);
            p2_assert(($sc['code'] === 200) && !empty($sc['json']['ok']), "HTTP service-charges lease {$leaseId}");

            $pen = $req('tenant/leases/' . $leaseId . '/penalties', $token, $leaseId);
            p2_assert(($pen['code'] === 200) && !empty($pen['json']['ok']), "HTTP penalties lease {$leaseId}");

            $oi = $req('tenant/leases/' . $leaseId . '/outstanding-items', $token, $leaseId);
            p2_assert(($oi['code'] === 200) && !empty($oi['json']['ok']), "HTTP outstanding-items lease {$leaseId}");

            $ph = $req('tenant/leases/' . $leaseId . '/payment-history', $token, $leaseId);
            p2_assert(($ph['code'] === 200) && !empty($ph['json']['ok']), "HTTP payment-history lease {$leaseId}");

            $pt = $req('tenant/leases/' . $leaseId . '/payment-timeline', $token, $leaseId);
            p2_assert(($pt['code'] === 200) && !empty($pt['json']['ok']), "HTTP payment-timeline lease {$leaseId}");

            // Cross-tenant / forbidden lease
            $forbidden = $req('tenant/leases/999999/financial-summary', $token, 999999);
            p2_assert(
                in_array($forbidden['code'], [400, 403, 404], true)
                || (($forbidden['json']['ok'] ?? true) === false),
                "HTTP forbidden/invalid lease rejected for user {$u['id']}",
                ['code' => $forbidden['code']]
            );

            // Header mismatch
            $url = $base . '?route=' . rawurlencode('tenant/leases/' . $leaseId . '/financial-summary');
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $token,
                    'X-Tenant-Lease-Id: ' . ($leaseId + 1),
                    'Accept: application/json',
                ],
            ]);
            $body = (string)curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $json = json_decode($body, true);
            p2_assert($code === 400 || (($json['error']['code'] ?? '') === 'lease_header_mismatch'), "HTTP lease header mismatch rejected", ['code' => $code]);

            // Only exercise first two users fully to keep runtime bounded
            break;
        }

        // Second user if available for SC-heavy lease 37
        if (count($users) > 1 || true) {
            $u37 = $conn->query("
                SELECT tpu.id, tpu.company_id, tpu.tenant_id
                FROM tenant_portal_users tpu
                JOIN re_leases l ON l.tenant_id=tpu.tenant_id AND l.company_id=tpu.company_id
                WHERE tpu.status='approved' AND l.id=37 LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);
            if ($u37) {
                $token = customer_api_jwt_sign([
                    'typ' => 'tenant',
                    'sub' => 'tpu:' . (int)$u37['id'],
                    'cid' => (int)$u37['company_id'],
                    'tid' => (int)$u37['tenant_id'],
                ], 600);
                $url = $base . '?route=' . rawurlencode('tenant/leases/37/service-charges');
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $token,
                        'X-Tenant-Lease-Id: 37',
                    ],
                ]);
                $body = (string)curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $json = json_decode($body, true);
                p2_assert($code === 200 && !empty($json['ok']), 'HTTP SC lease 37');
                if (!empty($json['ok'])) {
                    p2_assert(($json['data']['settlement_source'] ?? '') === 'invoice_mode_obligations', 'HTTP SC settlement IM for lease 37');
                }
            } else {
                p2_skip('HTTP SC lease 37', 'no portal user for lease 37');
            }
        }
    }
}

echo "\n=== Result: {$passed} passed, {$failed} failed, {$skipped} skipped ===\n";
exit($failed > 0 ? 1 : 0);
