<?php
/**
 * Operations — which client each building's cleaning is billed to.
 *
 * The one piece of set-up automatic invoicing needs, done once per building.
 * After that, a cleaning job finished in the building invoices that client by
 * itself — at the client's hourly rate, VAT and terms, which stay on the client
 * record where the old cleaning module already keeps them, so there is one rate
 * to change and not two. See includes/ops_billing.php.
 *
 * Saving a client also invoices the finished jobs that were waiting for one, so
 * setting a building up late loses nothing and needs no second visit to each
 * job.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/includes/ops_helper.php';
require_once __DIR__ . '/includes/ops_billing.php';

require_login(get_application_web_root() . '/login');
ops_require_access($conn);

$appBase = get_application_web_root();
$opsBase = $appBase . '/modules/operations';
$companyId = ops_company_id($conn);
$userId = (int)current_user_id();

/** Finished jobs in a building still waiting for a client, oldest first. */
function ops_billing_waiting_jobs(PDO $conn, int $buildingId): array
{
    $stmt = $conn->prepare("
        SELECT DISTINCT j.id
        FROM ops_jobs j
        JOIN ops_job_places p ON p.job_id = j.id
        WHERE p.building_id = ?
          AND j.status = 'done'
          AND j.order_id IS NULL
          AND j.billing_status IN ('no_client', 'failed')
        ORDER BY j.id ASC
    ");
    $stmt->execute([$buildingId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $buildingId = (int)($_POST['building_id'] ?? 0);
    $clientId = (int)($_POST['client_id'] ?? 0);

    $own = $conn->prepare("SELECT name FROM re_buildings WHERE id = ? AND company_id = ?");
    $own->execute([$buildingId, $companyId]);
    $buildingName = (string)($own->fetchColumn() ?: '');

    if ($buildingName === '') {
        ops_flash('That building could not be found.', 'danger');
    } elseif ($clientId <= 0) {
        $conn->prepare("DELETE FROM ops_building_clients WHERE building_id = ?")->execute([$buildingId]);
        ops_flash('Cleaning in ' . $buildingName . ' will no longer be invoiced.', 'info');
    } else {
        $client = $conn->prepare("SELECT client_name, rate FROM client WHERE id = ?");
        $client->execute([$clientId]);
        $clientRow = $client->fetch(PDO::FETCH_ASSOC);

        if (!$clientRow) {
            ops_flash('That client could not be found.', 'danger');
        } else {
            $conn->prepare("
                INSERT INTO ops_building_clients (building_id, client_id, updated_by)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE client_id = VALUES(client_id), updated_by = VALUES(updated_by)
            ")->execute([$buildingId, $clientId, $userId]);

            // The jobs that finished while this building had nobody to bill.
            $billed = 0;
            foreach (ops_billing_waiting_jobs($conn, $buildingId) as $waitingId) {
                if (ops_bill_finished_job($conn, $waitingId)['status'] === 'billed') {
                    $billed++;
                }
            }

            $message = $buildingName . ' → ' . $clientRow['client_name'] . '.';
            if ((float)$clientRow['rate'] <= 0) {
                $message .= ' This client has no hourly rate yet, so nothing will be invoiced until one is added.';
            } elseif ($billed > 0) {
                $message .= ' ' . $billed . ' waiting job' . ($billed === 1 ? ' was' : 's were') . ' invoiced.';
            }
            ops_flash($message, (float)$clientRow['rate'] <= 0 ? 'warning' : 'success');
        }
    }

    header('Location: ' . $opsBase . '/billing.php');
    exit;
}

// ---------------------------------------------------------------------------

$buildings = $conn->prepare("
    SELECT b.id, b.name,
           bc.client_id,
           c.client_name, c.rate, c.default_vat_rate, c.terms,
           (SELECT COUNT(DISTINCT j.id)
              FROM ops_jobs j JOIN ops_job_places p ON p.job_id = j.id
             WHERE p.building_id = b.id AND j.status = 'done'
               AND j.order_id IS NULL AND j.billing_status IN ('no_client','failed')) AS waiting,
           (SELECT COUNT(DISTINCT j.id)
              FROM ops_jobs j JOIN ops_job_places p ON p.job_id = j.id
             WHERE p.building_id = b.id AND j.billing_status = 'billed'
               AND j.finished_at >= ?) AS billed_30d
    FROM re_buildings b
    LEFT JOIN ops_building_clients bc ON bc.building_id = b.id
    LEFT JOIN client c ON c.id = bc.client_id
    WHERE b.company_id = ?
    ORDER BY b.name ASC
");
$buildings->execute([date('Y-m-d', strtotime('-30 days')), $companyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC) ?: [];

// The clients worth choosing from: anyone billed in the last year, plus
// whoever is already set. The full client list is over ten thousand names,
// almost all one-off customers, and a dropdown of those helps nobody.
$clients = $conn->prepare("
    SELECT c.id, c.client_name, c.rate
    FROM client c
    WHERE c.id IN (
            SELECT DISTINCT client_id FROM make_order
             WHERE service_date >= ? AND client_id IS NOT NULL
          )
       OR c.id IN (SELECT client_id FROM ops_building_clients)
    ORDER BY c.client_name ASC
");
$clients->execute([date('Y-m-d', strtotime('-365 days'))]);
$clients = $clients->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageTitle = 'Billing';
require __DIR__ . '/includes/ops_layout_header.php';
?>

<div class="mb-4">
  <div class="page-header-label">Billing</div>
  <div class="text-muted small">
    A cleaning job finished in a building invoices that building's client automatically —
    app time rounded up to the half hour, at most <?= (int)OPS_BILL_MAX_HOURS ?> hours,
    at the client's own rate and VAT. Tenant maintenance requests bill the same client,
    at most <?= (int)OPS_BILL_MAX_HOURS_TENANT ?> hours. Tenant cleaning bookings (already paid)
    and maintenance jobs staff raise themselves are not invoiced.
  </div>
</div>

<div class="card card-round">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead>
        <tr>
          <th>Building</th>
          <th style="min-width:280px">Billed to</th>
          <th>Rate</th>
          <th class="text-end">Last 30 days</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$buildings): ?>
          <tr><td colspan="4" class="text-center text-muted py-5">No buildings for this company.</td></tr>
        <?php endif; ?>
        <?php foreach ($buildings as $b): ?>
          <tr>
            <td class="fw-semibold">
              <?= h($b['name']) ?>
              <?php if ((int)$b['waiting'] > 0): ?>
                <div class="small text-warning-emphasis">
                  <i class="bi bi-exclamation-triangle"></i>
                  <?= (int)$b['waiting'] ?> finished job<?= (int)$b['waiting'] === 1 ? '' : 's' ?> waiting to be invoiced
                </div>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" class="d-flex gap-2">
                <?php csrf_field(); ?>
                <input type="hidden" name="building_id" value="<?= (int)$b['id'] ?>">
                <select name="client_id" class="form-select form-select-sm">
                  <option value="">Not invoiced</option>
                  <?php foreach ($clients as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= (int)$b['client_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                      <?= h($c['client_name']) ?><?= (float)$c['rate'] > 0 ? ' — AED ' . h(number_format((float)$c['rate'], 2)) . '/h' : ' — no rate' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-outline-primary">Save</button>
              </form>
            </td>
            <td class="small text-nowrap">
              <?php if ($b['client_id']): ?>
                <?php if ((float)$b['rate'] > 0): ?>
                  AED <?= h(number_format((float)$b['rate'], 2)) ?>/h
                  + <?= h(rtrim(rtrim(number_format((float)($b['default_vat_rate'] ?? 5), 2), '0'), '.')) ?>% VAT
                  <div class="text-muted"><?= h($b['terms'] ?: 'cash') ?></div>
                <?php else: ?>
                  <span class="text-danger">Client has no rate</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-end small"><?= (int)$b['billed_30d'] ?> invoiced</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/includes/ops_layout_footer.php'; ?>
