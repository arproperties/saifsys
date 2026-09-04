<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/scheduled_reports_service.php';
require_role(['Owner','Admin','Account'], $conn);

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$msg = $err = '';
$downloadRunId = null;
$scheduledReportsService = new ScheduledReportsService($conn);
$reportCatalog = $scheduledReportsService->getReportTypeCatalog();

$quickTemplates = [
    [
        'name' => 'Weekly Management Pack',
        'report_type' => 'management_dashboard',
        'frequency' => 'weekly',
        'export_format' => 'pdf',
        'date_range' => 'last_week',
    ],
    [
        'name' => 'Weekly Payments to Owner',
        'report_type' => 'payments',
        'frequency' => 'weekly',
        'export_format' => 'pdf',
        'date_range' => 'last_week',
    ],
    [
        'name' => 'Weekly Operations Summary',
        'report_type' => 'operations_summary',
        'frequency' => 'weekly',
        'export_format' => 'pdf',
        'date_range' => 'last_week',
    ],
    [
        'name' => 'Monthly AR + Ageing',
        'report_type' => 'ar_aging',
        'frequency' => 'monthly',
        'export_format' => 'pdf',
        'date_range' => 'last_month',
    ],
    [
        'name' => 'Daily Overdue Alert',
        'report_type' => 'overdue_invoices',
        'frequency' => 'daily',
        'export_format' => 'pdf',
        'date_range' => 'today',
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        $report_id = (int)($_POST['report_id'] ?? 0);

        switch ($action) {
            case 'create':
            case 'update':
                $parameters = [
                    'date_range' => trim($_POST['date_range'] ?? 'this_month'),
                ];
                if (!empty($_POST['from_date']) && !empty($_POST['to_date'])) {
                    $parameters['from_date'] = trim($_POST['from_date']);
                    $parameters['to_date'] = trim($_POST['to_date']);
                }
                $data = [
                    'report_name' => trim($_POST['report_name'] ?? ''),
                    'report_type' => trim($_POST['report_type'] ?? ''),
                    'frequency' => trim($_POST['frequency'] ?? ''),
                    'parameters' => $parameters,
                    'email_recipients' => trim($_POST['email_recipients'] ?? ''),
                    'export_format' => trim($_POST['export_format'] ?? 'pdf'),
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                    'created_by' => $_SESSION['user_id'] ?? null,
                ];
                if ($data['report_name'] === '' || $data['report_type'] === '' || $data['frequency'] === '') {
                    throw new Exception('Report name, type, and frequency are required.');
                }
                if ($action === 'create') {
                    $result = $scheduledReportsService->createScheduledReport($data);
                    $msg = $result['success'] ? 'Scheduled report created.' : null;
                    $err = $result['success'] ? '' : ($result['error'] ?? 'Create failed');
                } else {
                    if ($report_id <= 0) {
                        throw new Exception('Invalid report ID');
                    }
                    unset($data['created_by']);
                    $result = $scheduledReportsService->updateScheduledReport($report_id, $data);
                    $msg = $result['success'] ? 'Scheduled report updated.' : null;
                    $err = $result['success'] ? '' : ($result['error'] ?? 'Update failed');
                }
                break;

            case 'delete':
                if ($report_id <= 0) {
                    throw new Exception('Invalid report ID');
                }
                $result = $scheduledReportsService->deleteScheduledReport($report_id);
                $msg = $result['success'] ? 'Report deleted.' : null;
                $err = $result['success'] ? '' : ($result['error'] ?? 'Delete failed');
                break;

            case 'run_now':
            case 'run_download':
                if ($report_id <= 0) {
                    throw new Exception('Invalid report ID');
                }
                $downloadOnly = ($action === 'run_download');
                $result = $scheduledReportsService->processScheduledReport($report_id, $downloadOnly);
                if ($result['success']) {
                    $downloadRunId = (int)($result['run_id'] ?? 0);
                    $msg = $downloadOnly
                        ? 'Report generated. Use Download below or open History.'
                        : 'Report generated and emailed to recipients.';
                } else {
                    $err = $result['error'] ?? 'Run failed';
                    if (!empty($result['download_hint'])) {
                        $err .= ' ' . $result['download_hint'];
                    }
                }
                break;
        }
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

$active_only = isset($_GET['active_only']);
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 24;
$offset = ($page - 1) * $per_page;
$reports = $scheduledReportsService->getScheduledReports($per_page, $offset, $active_only);
$stats = $scheduledReportsService->getStatistics();
$catalogJson = json_encode($reportCatalog, JSON_UNESCAPED_UNICODE);
$templatesJson = json_encode($quickTemplates, JSON_UNESCAPED_UNICODE);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Scheduled Reports | Accounts</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        :root { --sr-primary: #1e40af; --sr-muted: #64748b; }
        body { background: #f1f5f9; }
        .sr-hero { background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); color: #fff; border-radius: 16px; }
        .sr-stat { border: none; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .sr-stat .value { font-size: 1.75rem; font-weight: 700; }
        .report-card { border: none; border-radius: 14px; box-shadow: 0 1px 4px rgba(0,0,0,.06); transition: transform .15s, box-shadow .15s; }
        .report-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,.08); }
        .type-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: #eff6ff; color: var(--sr-primary); font-size: 1.2rem; }
        .template-chip { cursor: pointer; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 12px; background: #fff; transition: border-color .15s, background .15s; }
        .template-chip:hover { border-color: var(--sr-primary); background: #eff6ff; }
        .badge-soft { background: #e0e7ff; color: #3730a3; }
    </style>
</head>
<body>
<div class="container-fluid py-4 px-lg-4">
    <div class="sr-hero p-4 p-md-5 mb-4">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="flex-grow-1">
                <a href="../account" class="btn btn-sm btn-light mb-3"><i class="bi bi-arrow-left"></i> Accounts</a>
                <h1 class="h3 mb-1">Scheduled Reports</h1>
                <p class="mb-0 opacity-75">Automated PDF/CSV packs for owners and managers — collections, AR, operations, and expenses.</p>
            </div>
            <button class="btn btn-light btn-lg" data-bs-toggle="modal" data-bs-target="#reportModal">
                <i class="bi bi-plus-lg"></i> New Report
            </button>
        </div>
    </div>

    <?php if ($msg): ?><div class="alert alert-success alert-dismissible fade show"><?= h($msg) ?>
        <?php if ($downloadRunId): ?>
            <a class="alert-link ms-2" href="ajax/download_scheduled_report_run.php?run_id=<?= $downloadRunId ?>">Download file</a>
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger alert-dismissible fade show"><?= h($err) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3"><div class="card sr-stat"><div class="card-body"><div class="text-muted small">Total</div><div class="value text-primary"><?= (int)($stats['total_reports'] ?? 0) ?></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card sr-stat"><div class="card-body"><div class="text-muted small">Active</div><div class="value text-success"><?= (int)($stats['active_reports'] ?? 0) ?></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card sr-stat"><div class="card-body"><div class="text-muted small">Successful runs</div><div class="value text-info"><?= (int)($stats['successful_runs'] ?? 0) ?></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card sr-stat"><div class="card-body"><div class="text-muted small">Failed runs</div><div class="value text-danger"><?= (int)($stats['failed_runs'] ?? 0) ?></div></div></div></div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-0 pt-3"><h2 class="h6 mb-0">Quick templates</h2></div>
        <div class="card-body">
            <div class="row g-2" id="templateRow">
                <?php foreach ($quickTemplates as $i => $tpl): ?>
                <div class="col-md-4 col-lg">
                    <div class="template-chip h-100" data-template-index="<?= $i ?>">
                        <div class="fw-semibold small"><?= h($tpl['name']) ?></div>
                        <div class="text-muted" style="font-size:.8rem"><?= h($reportCatalog[$tpl['report_type']]['label'] ?? $tpl['report_type']) ?> · <?= h(ucfirst($tpl['frequency'])) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <form method="get" class="d-flex gap-2 align-items-center">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="active_only" id="active_only" <?= $active_only ? 'checked' : '' ?> onchange="this.form.submit()">
                <label class="form-check-label" for="active_only">Active only</label>
            </div>
        </form>
    </div>

    <?php if (empty($reports)): ?>
    <div class="text-center py-5 bg-white rounded-3 shadow-sm">
        <i class="bi bi-envelope-paper display-4 text-muted"></i>
        <p class="text-muted mt-3 mb-3">No scheduled reports yet. Use a quick template or create your own.</p>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#reportModal">Create report</button>
    </div>
    <?php else: ?>
    <div class="row g-3">
        <?php foreach ($reports as $report):
            $cat = $reportCatalog[$report['report_type']] ?? null;
            $typeLabel = $cat['label'] ?? $report['report_type'];
            $icon = $cat['icon'] ?? 'bi-file-earmark-text';
            $params = json_decode($report['parameters'] ?? '{}', true) ?: [];
        ?>
        <div class="col-md-6 col-xl-4">
            <div class="card report-card h-100">
                <div class="card-body">
                    <div class="d-flex gap-3 mb-2">
                        <div class="type-icon"><i class="bi <?= h($icon) ?>"></i></div>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-start">
                                <h3 class="h6 mb-1"><?= h($report['report_name']) ?></h3>
                                <span class="badge <?= $report['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $report['is_active'] ? 'Active' : 'Off' ?></span>
                            </div>
                            <div class="text-muted small"><?= h($typeLabel) ?></div>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-1 mb-2">
                        <span class="badge badge-soft"><?= h(ucfirst($report['frequency'])) ?></span>
                        <span class="badge bg-light text-dark border"><?= strtoupper(h($report['export_format'])) ?></span>
                        <?php if (!empty($params['date_range'])): ?>
                        <span class="badge bg-light text-dark border"><?= h(str_replace('_', ' ', $params['date_range'])) ?></span>
                        <?php endif; ?>
                    </div>
                    <ul class="list-unstyled small text-muted mb-0">
                        <li><i class="bi bi-envelope"></i> <?= count(array_filter(array_map('trim', explode(',', $report['email_recipients'])))) ?> recipient(s)</li>
                        <li><i class="bi bi-play-circle"></i> <?= (int)$report['total_runs'] ?> run(s)<?php if ((int)$report['failed_runs'] > 0): ?>, <span class="text-danger"><?= (int)$report['failed_runs'] ?> failed</span><?php endif; ?></li>
                        <?php if ($report['next_run_at']): ?><li><i class="bi bi-clock"></i> Next: <?= date('M j, H:i', strtotime($report['next_run_at'])) ?></li><?php endif; ?>
                        <?php if ($report['last_successful_run']): ?><li><i class="bi bi-check2-circle text-success"></i> Last OK: <?= date('M j, H:i', strtotime($report['last_successful_run'])) ?></li><?php endif; ?>
                    </ul>
                </div>
                <div class="card-footer bg-white border-0 pt-0">
                    <div class="btn-group btn-group-sm w-100">
                        <button type="button" class="btn btn-outline-primary" onclick="editReport(<?= (int)$report['id'] ?>)"><i class="bi bi-pencil"></i></button>
                        <button type="button" class="btn btn-outline-success" title="Run & email" onclick="runReport(<?= (int)$report['id'] ?>, false)"><i class="bi bi-send"></i></button>
                        <button type="button" class="btn btn-outline-secondary" title="Generate download only" onclick="runReport(<?= (int)$report['id'] ?>, true)"><i class="bi bi-download"></i></button>
                        <button type="button" class="btn btn-outline-info" onclick="viewHistory(<?= (int)$report['id'] ?>)"><i class="bi bi-clock-history"></i></button>
                        <button type="button" class="btn btn-outline-danger" onclick="deleteReport(<?= (int)$report['id'] ?>)"><i class="bi bi-trash"></i></button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="reportModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="post" id="reportForm">
            <div class="modal-header">
                <h5 class="modal-title" id="reportModalTitle">New Scheduled Report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="report_id" id="report_id" value="0">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Report name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="report_name" id="report_name" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Report type <span class="text-danger">*</span></label>
                        <select class="form-select" name="report_type" id="report_type" required>
                            <option value="">Select…</option>
                            <?php
                            $byCat = [];
                            foreach ($reportCatalog as $key => $meta) {
                                $byCat[$meta['category']][$key] = $meta;
                            }
                            foreach ($byCat as $category => $types): ?>
                            <optgroup label="<?= h($category) ?>">
                                <?php foreach ($types as $key => $meta): ?>
                                <option value="<?= h($key) ?>" data-frequency="<?= h($meta['default_frequency'] ?? 'monthly') ?>"><?= h($meta['label']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text" id="report_type_help"></div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Frequency <span class="text-danger">*</span></label>
                        <select class="form-select" name="frequency" id="frequency" required>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="yearly">Yearly</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Period</label>
                        <select class="form-select" name="date_range" id="date_range">
                            <option value="last_week">Last week</option>
                            <option value="this_week">This week</option>
                            <option value="last_month">Last month</option>
                            <option value="this_month">This month (MTD)</option>
                            <option value="today">Today</option>
                            <option value="custom">Custom dates…</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Format</label>
                        <select class="form-select" name="export_format" id="export_format">
                            <option value="pdf">PDF</option>
                            <option value="csv">CSV</option>
                        </select>
                    </div>
                    <div class="col-md-6 custom-dates d-none">
                        <label class="form-label">From</label>
                        <input type="date" class="form-control" name="from_date" id="from_date">
                    </div>
                    <div class="col-md-6 custom-dates d-none">
                        <label class="form-label">To</label>
                        <input type="date" class="form-control" name="to_date" id="to_date">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Email recipients <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="email_recipients" id="email_recipients" rows="2" placeholder="owner@company.com, manager@company.com" required></textarea>
                        <div class="form-text">Comma-separated. Required even for download-only tests if you save the schedule.</div>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
                            <label class="form-check-label" for="is_active">Active (include in automatic cron runs)</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="historyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Run history</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body" id="historyContent"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const reportCatalog = <?= $catalogJson ?>;
const quickTemplates = <?= $templatesJson ?>;

function updateTypeHelp() {
    const key = document.getElementById('report_type').value;
    const el = document.getElementById('report_type_help');
    if (reportCatalog[key]) {
        el.textContent = reportCatalog[key].description || '';
        const freq = document.getElementById('frequency');
        if (!freq.dataset.userChanged && reportCatalog[key].default_frequency) {
            freq.value = reportCatalog[key].default_frequency;
        }
    } else {
        el.textContent = '';
    }
}

document.getElementById('report_type').addEventListener('change', updateTypeHelp);
document.getElementById('frequency').addEventListener('change', function() { this.dataset.userChanged = '1'; });
document.getElementById('date_range').addEventListener('change', function() {
    document.querySelectorAll('.custom-dates').forEach(el => {
        el.classList.toggle('d-none', this.value !== 'custom');
    });
});

document.querySelectorAll('.template-chip').forEach(chip => {
    chip.addEventListener('click', function() {
        const tpl = quickTemplates[parseInt(this.dataset.templateIndex, 10)];
        if (!tpl) return;
        document.getElementById('report_id').value = '0';
        document.querySelector('input[name="action"]').value = 'create';
        document.getElementById('reportModalTitle').textContent = 'New Scheduled Report';
        document.getElementById('report_name').value = tpl.name;
        document.getElementById('report_type').value = tpl.report_type;
        document.getElementById('frequency').value = tpl.frequency;
        document.getElementById('export_format').value = tpl.export_format;
        document.getElementById('date_range').value = tpl.date_range;
        document.getElementById('is_active').checked = true;
        updateTypeHelp();
        new bootstrap.Modal(document.getElementById('reportModal')).show();
    });
});

function parseReportParameters(raw) {
    if (raw == null || raw === '') return {};
    if (typeof raw === 'object') return raw;
    try {
        return JSON.parse(raw);
    } catch (e) {
        return {};
    }
}

function editReport(id) {
    fetch('ajax/get_scheduled_report.php?id=' + id)
        .then(r => r.json())
        .then(data => {
            if (!data.success) throw new Error(data.error || 'Load failed');
            const r = data.report;
            const p = parseReportParameters(r.parameters);
            document.getElementById('report_id').value = r.id;
            document.getElementById('report_name').value = r.report_name;
            document.getElementById('report_type').value = r.report_type;
            document.getElementById('frequency').value = r.frequency;
            document.getElementById('export_format').value = r.export_format;
            document.getElementById('email_recipients').value = r.email_recipients;
            document.getElementById('is_active').checked = r.is_active == 1;
            document.getElementById('date_range').value = p.date_range || 'this_month';
            document.getElementById('from_date').value = p.from_date || '';
            document.getElementById('to_date').value = p.to_date || '';
            document.querySelectorAll('.custom-dates').forEach(el => el.classList.toggle('d-none', !p.from_date));
            document.getElementById('reportModalTitle').textContent = 'Edit Scheduled Report';
            document.querySelector('input[name="action"]').value = 'update';
            updateTypeHelp();
            new bootstrap.Modal(document.getElementById('reportModal')).show();
        })
        .catch(e => alert(e.message));
}

function postAction(action, reportId) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="action" value="' + action + '"><input type="hidden" name="report_id" value="' + reportId + '">';
    document.body.appendChild(form);
    form.submit();
}

function runReport(id, downloadOnly) {
    const msg = downloadOnly
        ? 'Generate this report now (download only, no email)?'
        : 'Run now and email all recipients?';
    if (confirm(msg)) postAction(downloadOnly ? 'run_download' : 'run_now', id);
}

function deleteReport(id) {
    if (confirm('Delete this scheduled report?')) postAction('delete', id);
}

function viewHistory(id) {
    fetch('ajax/get_report_history.php?id=' + id)
        .then(r => r.text())
        .then(html => {
            document.getElementById('historyContent').innerHTML = html;
            new bootstrap.Modal(document.getElementById('historyModal')).show();
        });
}

document.getElementById('reportModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('reportForm').reset();
    document.getElementById('report_id').value = '0';
    document.getElementById('reportModalTitle').textContent = 'New Scheduled Report';
    document.querySelector('input[name="action"]').value = 'create';
    document.getElementById('frequency').dataset.userChanged = '';
    document.querySelectorAll('.custom-dates').forEach(el => el.classList.add('d-none'));
});
</script>
</body>
</html>
