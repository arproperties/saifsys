<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/legal_helper.php';

require_login();
require_module_access($conn, MODULE_LEGAL);
legal_require_access($conn);

$currentCompanyId = current_company_id($conn) ?: 1;
$stmt = $conn->prepare("SELECT * FROM re_legal_counsel WHERE company_id = ? ORDER BY name");
$stmt->execute([$currentCompanyId]);
$list = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'External Counsel';
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
        <div class="d-flex justify-content-between mb-4">
            <h1><i class="bi bi-person-badge"></i> External Counsel</h1>
            <a href="legal_counsel_add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Add Counsel</a>
        </div>
        <div class="card"><div class="card-body table-responsive">
            <table class="table table-hover align-middle">
                <thead><tr><th>Name</th><th>Type</th><th>Contact</th><th>Specialization</th><th>Rate</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php if (empty($list)): ?><tr><td colspan="7" class="text-muted text-center">No counsel registered.</td></tr>
                <?php else: foreach ($list as $c): ?>
                    <tr>
                        <td><strong><?= h($c['name']) ?></strong><?php if ($c['contact_person']): ?><br><small class="text-muted"><?= h($c['contact_person']) ?></small><?php endif; ?></td>
                        <td><?= h(legal_counsel_types()[$c['counsel_type']] ?? $c['counsel_type']) ?></td>
                        <td><small><?= h($c['email'] ?: '-') ?><br><?= h($c['phone'] ?: '') ?></small></td>
                        <td><?= h($c['specialization'] ?: '-') ?></td>
                        <td><?= $c['hourly_rate'] ? number_format((float)$c['hourly_rate'], 2) : '-' ?></td>
                        <td><span class="badge bg-<?= $c['is_active'] ? 'success' : 'secondary' ?>"><?= $c['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                        <td><a href="legal_counsel_edit.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div></div>
<?php require_once __DIR__ . '/includes/legal_layout_footer.php'; ?>
