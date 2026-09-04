<?php
/**
 * Construction Accounting — Journal Entries list
 * Uses shared re_* accounting engine; presentation is Construction-only.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';

require_login();
if (!has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_CONSTRUCTION);
}

$brand = getBrandSettings($conn);
$currentCompanyId = (int)(current_company_id($conn) ?: 0);
if ($currentCompanyId <= 0) {
    http_response_code(400);
    die('Company context is required.');
}

$dateFrom = !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$dateTo = !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$journalType = !empty($_GET['journal_type']) ? $_GET['journal_type'] : '';
$isPosted = isset($_GET['is_posted']) && $_GET['is_posted'] !== '' ? $_GET['is_posted'] : '';

$where = ['jh.company_id = ?'];
$params = [$currentCompanyId];

if ($dateFrom) {
    $where[] = 'jh.journal_date >= ?';
    $params[] = $dateFrom;
}
if ($dateTo) {
    $where[] = 'jh.journal_date <= ?';
    $params[] = $dateTo;
}
if ($journalType) {
    $where[] = 'jh.journal_type = ?';
    $params[] = $journalType;
}
if ($isPosted !== '') {
    $where[] = 'jh.is_posted = ?';
    $params[] = (int)$isPosted;
}

$sql = "
    SELECT
        jh.*,
        u.username AS created_by_name,
        u2.username AS posted_by_name,
        COUNT(jl.id) AS line_count
    FROM re_journal_headers jh
    LEFT JOIN user u ON u.id = jh.created_by
    LEFT JOIN user u2 ON u2.id = jh.posted_by
    LEFT JOIN re_journal_lines jl ON jl.journal_id = jh.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY jh.id
    ORDER BY jh.journal_date DESC, jh.id DESC
    LIMIT 100
";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$journals = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Journal Entries';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div class="page-header-label">
        <i class="bi bi-journal-text"></i> Journal Entries
    </div>
    <div>
        <a href="journal_entry_add.php" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> New Journal Entry
        </a>
    </div>
</div>

<div class="card card-round mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-calendar"></i> From Date</label>
                <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-calendar"></i> To Date</label>
                <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-tag"></i> Type</label>
                <select name="journal_type" class="form-select">
                    <option value="">All Types</option>
                    <option value="manual" <?= $journalType === 'manual' ? 'selected' : '' ?>>Manual</option>
                    <option value="invoice" <?= $journalType === 'invoice' ? 'selected' : '' ?>>Invoice</option>
                    <option value="payment" <?= $journalType === 'payment' ? 'selected' : '' ?>>Payment</option>
                    <option value="expense" <?= $journalType === 'expense' ? 'selected' : '' ?>>Expense</option>
                    <option value="deposit" <?= $journalType === 'deposit' ? 'selected' : '' ?>>Deposit</option>
                    <option value="refund" <?= $journalType === 'refund' ? 'selected' : '' ?>>Refund</option>
                    <option value="adjustment" <?= $journalType === 'adjustment' ? 'selected' : '' ?>>Adjustment</option>
                    <option value="reversal" <?= $journalType === 'reversal' ? 'selected' : '' ?>>Reversal</option>
                    <option value="opening_balance" <?= $journalType === 'opening_balance' ? 'selected' : '' ?>>Opening Balance</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="bi bi-check-circle"></i> Status</label>
                <select name="is_posted" class="form-select">
                    <option value="">All</option>
                    <option value="1" <?= $isPosted === '1' ? 'selected' : '' ?>>Posted</option>
                    <option value="0" <?= $isPosted === '0' ? 'selected' : '' ?>>Unposted</option>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-funnel-fill"></i> Filter
                </button>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <a href="journal_entry_list.php" class="btn btn-outline-secondary w-100">
                    <i class="bi bi-x-circle"></i> Clear
                </a>
            </div>
        </form>
    </div>
</div>

<div class="card card-round">
    <div class="card-header bg-light d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-list-ul"></i> Journal Entries (<?= count($journals) ?>)</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th><i class="bi bi-hash"></i> Journal #</th>
                        <th><i class="bi bi-calendar"></i> Date</th>
                        <th><i class="bi bi-tag"></i> Type</th>
                        <th><i class="bi bi-file-text"></i> Description</th>
                        <th><i class="bi bi-hash"></i> Reference</th>
                        <th class="text-end"><i class="bi bi-arrow-down-left"></i> Debit</th>
                        <th class="text-end"><i class="bi bi-arrow-up-right"></i> Credit</th>
                        <th><i class="bi bi-check-circle"></i> Status</th>
                        <th><i class="bi bi-gear"></i> Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($journals)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-5">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                No journal entries found
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($journals as $journal): ?>
                            <tr>
                                <td><strong><?= h($journal['journal_number']) ?></strong></td>
                                <td><?= date('Y-m-d', strtotime($journal['journal_date'])) ?></td>
                                <td>
                                    <span class="badge bg-info"><?= ucfirst(h($journal['journal_type'])) ?></span>
                                </td>
                                <td><?= h($journal['description'] ?: '-') ?></td>
                                <td>
                                    <?php if ($journal['reference_type'] && $journal['reference_id']): ?>
                                        <code><?= h($journal['reference_type']) ?> #<?= (int)$journal['reference_id'] ?></code>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= number_format((float)$journal['total_debit'], 2) ?></td>
                                <td class="text-end"><?= number_format((float)$journal['total_credit'], 2) ?></td>
                                <td>
                                    <?php if ($journal['is_posted']): ?>
                                        <span class="badge bg-success">
                                            <i class="bi bi-check-circle"></i> Posted
                                        </span>
                                        <?php if ($journal['is_reversed']): ?>
                                            <span class="badge bg-warning">Reversed</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php
                                        $ast = $journal['approval_status'] ?? null;
                                        if ($ast === 'submitted'): ?>
                                            <span class="badge bg-info">Submitted</span>
                                        <?php elseif ($ast === 'approved'): ?>
                                            <span class="badge bg-primary">Approved</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><?= $ast === 'draft' ? 'Draft' : 'Unposted' ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="journal_entry_view.php?id=<?= (int)$journal['id'] ?>" class="btn btn-outline-primary" title="View">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if (!$journal['is_posted']): ?>
                                            <a href="journal_entry_add.php?id=<?= (int)$journal['id'] ?>" class="btn btn-outline-info" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
