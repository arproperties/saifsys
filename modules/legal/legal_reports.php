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

$summary = $conn->prepare("
    SELECT
        COUNT(*) AS total_cases,
        SUM(CASE WHEN status NOT IN ('settled','closed','withdrawn') THEN 1 ELSE 0 END) AS open_cases,
        COALESCE(SUM(claim_amount),0) AS total_claim,
        COALESCE(SUM(recovered_amount),0) AS total_recovered,
        COALESCE(SUM(CASE WHEN status NOT IN ('settled','closed','withdrawn') THEN claim_amount ELSE 0 END),0) AS open_claim
    FROM re_legal_cases WHERE company_id = ? AND deleted_at IS NULL
");
$summary->execute([$currentCompanyId]);
$summary = $summary->fetch(PDO::FETCH_ASSOC) ?: [];

$costSummary = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS total_cost, COALESCE(SUM(CASE WHEN is_recovered=1 THEN amount ELSE 0 END),0) AS recovered_cost FROM re_legal_case_costs WHERE company_id = ?");
$costSummary->execute([$currentCompanyId]);
$costSummary = $costSummary->fetch(PDO::FETCH_ASSOC) ?: [];

$byStatus = $conn->prepare("SELECT status, COUNT(*) cnt FROM re_legal_cases WHERE company_id = ? AND deleted_at IS NULL GROUP BY status ORDER BY cnt DESC");
$byStatus->execute([$currentCompanyId]);
$byStatus = $byStatus->fetchAll(PDO::FETCH_ASSOC);

$byType = $conn->prepare("SELECT case_type, COUNT(*) cnt, COALESCE(SUM(claim_amount),0) claim FROM re_legal_cases WHERE company_id = ? AND deleted_at IS NULL GROUP BY case_type ORDER BY cnt DESC");
$byType->execute([$currentCompanyId]);
$byType = $byType->fetchAll(PDO::FETCH_ASSOC);

$topCosts = $conn->prepare("
    SELECT c.case_number, c.title, COALESCE(SUM(co.amount),0) AS total_cost
    FROM re_legal_case_costs co JOIN re_legal_cases c ON c.id = co.case_id
    WHERE co.company_id = ? GROUP BY co.case_id ORDER BY total_cost DESC LIMIT 10
");
$topCosts->execute([$currentCompanyId]);
$topCosts = $topCosts->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Legal Reports';
require_once __DIR__ . '/includes/legal_layout_header.php';
?>
        <h1 class="mb-4"><i class="bi bi-bar-chart-line"></i> Legal Reports</h1>
        <div class="row g-3 mb-4">
            <div class="col-md-3"><div class="card text-center"><div class="card-body"><h3><?= (int)$summary['open_cases'] ?></h3><small class="text-muted">Open Cases</small></div></div></div>
            <div class="col-md-3"><div class="card text-center"><div class="card-body"><h5><?= number_format((float)$summary['open_claim'],0) ?></h5><small class="text-muted">Open Claim (AED)</small></div></div></div>
            <div class="col-md-3"><div class="card text-center border-success"><div class="card-body"><h5 class="text-success"><?= number_format((float)$summary['total_recovered'],0) ?></h5><small class="text-muted">Recovered (AED)</small></div></div></div>
            <div class="col-md-3"><div class="card text-center"><div class="card-body"><h5><?= number_format((float)$costSummary['total_cost'],0) ?></h5><small class="text-muted">Legal Costs (AED)</small></div></div></div>
        </div>
        <div class="row g-4">
            <div class="col-md-6"><div class="card"><div class="card-header">Cases by Status</div><div class="card-body p-0"><table class="table table-sm mb-0"><tbody>
                <?php foreach ($byStatus as $r): ?><tr><td><?= h(legal_case_statuses()[$r['status']] ?? $r['status']) ?></td><td class="text-end"><?= (int)$r['cnt'] ?></td></tr><?php endforeach; ?>
            </tbody></table></div></div></div>
            <div class="col-md-6"><div class="card"><div class="card-header">Cases by Type</div><div class="card-body p-0"><table class="table table-sm mb-0"><tbody>
                <?php foreach ($byType as $r): ?><tr><td><?= h(legal_case_types()[$r['case_type']] ?? $r['case_type']) ?></td><td class="text-end"><?= (int)$r['cnt'] ?></td><td class="text-end"><?= number_format((float)$r['claim'],0) ?></td></tr><?php endforeach; ?>
            </tbody></table></div></div></div>
            <div class="col-12"><div class="card"><div class="card-header">Top Cases by Legal Cost</div><div class="card-body p-0"><table class="table table-sm mb-0"><thead><tr><th>Case</th><th>Title</th><th class="text-end">Cost</th></tr></thead><tbody>
                <?php if (empty($topCosts)): ?><tr><td colspan="3" class="text-muted text-center">No costs recorded.</td></tr>
                <?php else: foreach ($topCosts as $r): ?><tr><td><?= h($r['case_number']) ?></td><td><?= h($r['title']) ?></td><td class="text-end"><?= number_format((float)$r['total_cost'],2) ?></td></tr><?php endforeach; endif; ?>
            </tbody></table></div></div></div>
        </div>
<?php require_once __DIR__ . '/includes/legal_layout_footer.php'; ?>
