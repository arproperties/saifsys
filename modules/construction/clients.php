<?php
/**
 * Construction Module — Clients (for CLIENT projects)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_income_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;

$hasIncomeClientColumns = co_db_column_exists($conn, 'co_clients', 'client_type');
$stmt = $conn->prepare("SELECT * FROM co_clients WHERE company_id = ? ORDER BY client_name");
$stmt->execute([$cid]);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Clients';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<?= co_ui_page_header(
    'Clients',
    'Customers and income clients for Construction AR',
    [['label' => 'Construction', 'href' => 'index.php'], ['label' => 'Clients']],
    '<a href="client_add.php" class="btn btn-primary btn-sm"><i data-lucide="plus" style="width:14px;height:14px"></i> Add Client</a>'
) ?>

<div class="card card-round co-table-shell">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Name</th><?php if ($hasIncomeClientColumns): ?><th>Type</th><?php endif; ?><th>Contact</th><th>Phone</th><th>Email</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($clients as $c): ?>
                    <tr>
                        <td><strong><?= h($c['client_name']) ?></strong></td>
                        <?php if ($hasIncomeClientColumns): ?><td><span class="badge bg-secondary"><?= h(co_client_type_options()[$c['client_type'] ?? 'customer'] ?? 'Customer') ?></span></td><?php endif; ?>
                        <td><?= h($c['contact_person'] ?? '-') ?></td>
                        <td><?= h($c['phone'] ?? '-') ?></td>
                        <td><?= h($c['email'] ?? '-') ?></td>
                        <td><a href="client_edit.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (empty($clients)): ?>
        <div class="p-4 text-center text-muted">No clients found. Add clients for CLIENT projects.</div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
