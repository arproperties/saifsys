<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_role(['Owner','Admin','Account'], $conn);

if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

$report_type = $_GET['type'] ?? '';
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$comparison_period = $_GET['comparison'] ?? 'none'; // none, yoy, mom

// Calculate comparison periods
$comparison_from = $comparison_to = null;
if ($comparison_period === 'yoy') {
    $comparison_from = date('Y-m-d', strtotime($from_date . ' -1 year'));
    $comparison_to = date('Y-m-d', strtotime($to_date . ' -1 year'));
} elseif ($comparison_period === 'mom') {
    $comparison_from = date('Y-m-d', strtotime($from_date . ' -1 month'));
    $comparison_to = date('Y-m-d', strtotime($to_date . ' -1 month'));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Financial Reports | BMSystem</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .report-card { transition: transform 0.2s; }
        .report-card:hover { transform: translateY(-2px); }
        .chart-container { position: relative; height: 400px; }
        .comparison-badge { font-size: 0.8rem; }
        .hero{background:#fff;border-radius:18px;box-shadow:0 10px 24px rgba(0,0,0,.06);padding:18px 22px;margin-bottom:18px}
        .r-icon{width:48px;height:48px;border-radius:12px;display:grid;place-items:center;background:#80000010;color:#800000}
        .r-title{font-weight:700;margin-bottom:.2rem}
        .r-desc{color:#6b7280;font-size:.92rem}
        .badge-live{background:#e8fff2;color:#137a36;border:1px solid #bdf1cf}
    </style>
</head>
<body class="bg-light">
    <div class="container my-4">
        <div class="hero d-flex align-items-center">
            <div>
                <div class="text-uppercase small text-muted">Accounting</div>
                <h3 class="mb-0">Financial Reports</h3>
            </div>
            <div class="ms-auto">
                <button class="btn btn-outline-success me-2" onclick="showSavedConfigsModal()">
                    <i class="bi bi-bookmark me-1"></i>Saved Configs
                </button>
                <button class="btn btn-outline-primary me-2" onclick="saveCurrentConfig()">
                    <i class="bi bi-save me-1"></i>Save Config
                </button>
                <a href="../account" class="btn btn-outline-secondary" data-tab="dashboard"><i class="bi bi-arrow-left"></i> Back</a>
            </div>
        </div>

        <!-- Report Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Report Type</label>
                        <select name="type" class="form-select" onchange="this.form.submit()">
                            <option value="">All Reports</option>
                            <option value="pnl" <?= $report_type === 'pnl' ? 'selected' : '' ?>>Profit & Loss</option>
                            <option value="balance_sheet" <?= $report_type === 'balance_sheet' ? 'selected' : '' ?>>Balance Sheet</option>
                            <option value="trial_balance" <?= $report_type === 'trial_balance' ? 'selected' : '' ?>>Trial Balance</option>
                            <option value="ar_ageing" <?= $report_type === 'ar_ageing' ? 'selected' : '' ?>>AR Ageing</option>
                            <option value="vat" <?= $report_type === 'vat' ? 'selected' : '' ?>>VAT Report</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">From Date</label>
                        <input type="date" name="from_date" class="form-control" value="<?= h($from_date) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">To Date</label>
                        <input type="date" name="to_date" class="form-control" value="<?= h($to_date) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Comparison</label>
                        <select name="comparison" class="form-select">
                            <option value="none" <?= $comparison_period === 'none' ? 'selected' : '' ?>>None</option>
                            <option value="yoy" <?= $comparison_period === 'yoy' ? 'selected' : '' ?>>Year over Year</option>
                            <option value="mom" <?= $comparison_period === 'mom' ? 'selected' : '' ?>>Month over Month</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-search me-1"></i>Generate
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="resetFilters()">
                            <i class="bi bi-arrow-clockwise me-1"></i>Reset
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Reports Grid -->
        <div class="row" id="reportsContainer">
            <?php
            $reports = [
                'pnl' => [
                    'title' => 'Profit & Loss Statement',
                    'description' => 'Revenue and expenses summary',
                    'icon' => 'bi-graph-up',
                    'color' => 'primary',
                    'file' => 'report_pnl.php'
                ],
                'balance_sheet' => [
                    'title' => 'Balance Sheet',
                    'description' => 'Assets, liabilities, and equity',
                    'icon' => 'bi-pie-chart',
                    'color' => 'success',
                    'file' => 'report_balance_sheet.php'
                ],
                'trial_balance' => [
                    'title' => 'Trial Balance',
                    'description' => 'Account balances summary',
                    'icon' => 'bi-list-check',
                    'color' => 'info',
                    'file' => 'report_trial_balance.php'
                ],
                'ar_ageing' => [
                    'title' => 'AR Ageing Report',
                    'description' => 'Outstanding receivables by age',
                    'icon' => 'bi-clock-history',
                    'color' => 'warning',
                    'file' => 'report_ar_ap_ageing.php'
                ],
                'vat' => [
                    'title' => 'VAT Report',
                    'description' => 'VAT input and output summary',
                    'icon' => 'bi-receipt',
                    'color' => 'danger',
                    'file' => 'report_vat.php'
                ]
            ];

            foreach ($reports as $key => $report):
                if ($report_type && $report_type !== $key) continue;
            ?>
            <div class="col-lg-6 col-xl-4 mb-4">
                <div class="card report-card h-100 shadow-sm">
                    <div class="card-header bg-<?= $report['color'] ?> text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">
                                <i class="bi <?= $report['icon'] ?> me-2"></i><?= h($report['title']) ?>
                            </h6>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-light" onclick="viewReport('<?= $key ?>')" title="View Report">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button class="btn btn-outline-light" onclick="exportReport('<?= $key ?>', 'pdf')" title="Export PDF">
                                    <i class="bi bi-file-pdf"></i>
                                </button>
                                <button class="btn btn-outline-light" onclick="exportReport('<?= $key ?>', 'excel')" title="Export Excel">
                                    <i class="bi bi-file-excel"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        
                        <!-- Loading Indicator -->
                        <div id="loading_<?= $key ?>" class="text-center py-4">
                            <div class="spinner-border text-<?= $report['color'] ?>" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="text-muted mt-2 small">Loading data...</p>
                        </div>
                        
                        <!-- Chart Container -->
                        <div class="chart-container mb-3" id="chart_container_<?= $key ?>" style="display: none;">
                            <canvas id="chart_<?= $key ?>"></canvas>
                        </div>
                        
                        <!-- No Data Message -->
                        <div id="nodata_<?= $key ?>" class="text-center py-4 text-muted" style="display: none;">
                            <i class="bi bi-inbox fs-1"></i>
                            <p class="mt-2">No data available for the selected period</p>
                        </div>
                        
                        <!-- Report Summary -->
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="border-end">
                                    <div class="h5 mb-0 text-<?= $report['color'] ?>" id="current_<?= $key ?>">-</div>
                                    <small class="text-muted">Current Period</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="h5 mb-0 text-muted" id="comparison_<?= $key ?>">-</div>
                                <small class="text-muted">
                                    <?= $comparison_period === 'yoy' ? 'Last Year' : ($comparison_period === 'mom' ? 'Last Month' : 'No Comparison') ?>
                                </small>
                            </div>
                        </div>
                        
                        <?php if ($comparison_period !== 'none'): ?>
                        <div class="mt-2 text-center">
                            <span class="badge bg-<?= $report['color'] ?> comparison-badge" id="change_<?= $key ?>">
                                <i class="bi bi-arrow-up me-1"></i>0%
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer bg-light">
                        <div class="d-flex justify-content-between">
                            <small class="text-muted">
                                Period: <?= date('M d', strtotime($from_date)) ?> - <?= date('M d', strtotime($to_date)) ?>
                            </small>
                            <small class="text-muted">
                                <?= $comparison_period !== 'none' ? 'With ' . strtoupper($comparison_period) . ' comparison' : 'No comparison' ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Saved Configurations Modal -->
    <div class="modal fade" id="savedConfigsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Saved Report Configurations</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="savedConfigsList">
                        <div class="text-center">
                            <div class="spinner-border" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Save Configuration Modal -->
    <div class="modal fade" id="saveConfigModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Save Report Configuration</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="saveConfigForm">
                        <div class="mb-3">
                            <label class="form-label">Configuration Name</label>
                            <input type="text" class="form-control" id="configName" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Report Type</label>
                            <select class="form-select" id="configReportType" required>
                                <option value="">Select Report Type</option>
                                <option value="pnl">Profit & Loss</option>
                                <option value="balance_sheet">Balance Sheet</option>
                                <option value="trial_balance">Trial Balance</option>
                                <option value="ar_ageing">AR Ageing</option>
                                <option value="vat">VAT Report</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="setAsDefault">
                                <label class="form-check-label" for="setAsDefault">
                                    Set as default for this report type
                                </label>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveConfig()">Save Configuration</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global chart instances
        const charts = {};
        
        // Chart configurations
        const chartConfigs = {
            pnl: {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Revenue',
                        data: [],
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        tension: 0.4
                    }, {
                        label: 'Expenses',
                        data: [],
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' },
                        tooltip: { mode: 'index', intersect: false }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            },
            balance_sheet: {
                type: 'doughnut',
                data: {
                    labels: ['Assets', 'Liabilities', 'Equity'],
                    datasets: [{
                        data: [],
                        backgroundColor: ['#28a745', '#dc3545', '#007bff'],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            },
            trial_balance: {
                type: 'bar',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Debit',
                        data: [],
                        backgroundColor: '#28a745'
                    }, {
                        label: 'Credit',
                        data: [],
                        backgroundColor: '#dc3545'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            },
            ar_ageing: {
                type: 'bar',
                data: {
                    labels: ['Current', '1-30 days', '31-60 days', '61-90 days', '90+ days'],
                    datasets: [{
                        label: 'Amount',
                        data: [],
                        backgroundColor: ['#28a745', '#ffc107', '#fd7e14', '#dc3545', '#6f42c1']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            },
            vat: {
                type: 'bar',
                data: {
                    labels: ['Input VAT', 'Output VAT', 'Net VAT'],
                    datasets: [{
                        label: 'VAT Amount',
                        data: [],
                        backgroundColor: ['#dc3545', '#28a745', '#007bff']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            }
        };

        // Initialize charts
        function initializeCharts() {
            Object.keys(chartConfigs).forEach(key => {
                const canvas = document.getElementById(`chart_${key}`);
                if (canvas) {
                    try {
                        charts[key] = new Chart(canvas, chartConfigs[key]);
                        console.log('Chart initialized for', key);
                        
                        // Show loading, hide chart initially
                        const loadingEl = document.getElementById(`loading_${key}`);
                        const chartContainer = document.getElementById(`chart_container_${key}`);
                        if (loadingEl) loadingEl.style.display = 'block';
                        if (chartContainer) chartContainer.style.display = 'none';
                        
                        // Load data with a small delay to avoid overwhelming the server
                        setTimeout(() => {
                            loadReportData(key);
                        }, 100 * Object.keys(chartConfigs).indexOf(key));
                    } catch (error) {
                        console.error('Failed to initialize chart for', key, ':', error);
                        showEmptyState(key, 'Failed to initialize chart: ' + error.message);
                    }
                } else {
                    console.error('Canvas not found for', key);
                }
            });
        }

        // Load report data
        async function loadReportData(reportType) {
            try {
                const url = `ajax/get_report_data.php?type=${reportType}&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>&comparison=<?= $comparison_period ?>`;
                console.log('Loading data for', reportType, 'from', url);
                
                const response = await fetch(url);
                const responseText = await response.text();
                console.log('Raw response for', reportType, ':', responseText);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}, body: ${responseText}`);
                }
                
                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (e) {
                    console.error('Failed to parse JSON:', responseText);
                    throw new Error('Invalid JSON response: ' + responseText.substring(0, 200));
                }
                
                console.log('Parsed response for', reportType, ':', data);
                
                if (data.success && data.data !== undefined) {
                    console.log('Updating chart and summary for', reportType, 'with data:', data.data);
                    // Always update, even if data is empty (shows empty chart)
                    updateChart(reportType, data.data);
                    updateSummary(reportType, data.data);
                } else {
                    console.warn('No data for', reportType, ':', data.error || 'Unknown error');
                    // Show empty state with error message
                    showEmptyState(reportType, data.error || 'No data available');
                }
            } catch (error) {
                console.error('Error loading report data for', reportType, ':', error);
                showEmptyState(reportType, error.message);
            }
        }
        
        // Show empty state when no data
        function showEmptyState(reportType, message) {
            // Hide loading and chart, show no data message
            const loadingEl = document.getElementById(`loading_${reportType}`);
            const chartContainer = document.getElementById(`chart_container_${reportType}`);
            const noDataEl = document.getElementById(`nodata_${reportType}`);
            
            if (loadingEl) loadingEl.style.display = 'none';
            if (chartContainer) chartContainer.style.display = 'none';
            if (noDataEl) {
                noDataEl.style.display = 'block';
                if (message) {
                    const msgEl = noDataEl.querySelector('p');
                    if (msgEl) msgEl.textContent = message;
                }
            }
            
            const chart = charts[reportType];
            if (chart) {
                // Clear chart data
                chart.data.labels = [];
                chart.data.datasets.forEach(dataset => {
                    dataset.data = [];
                });
                chart.update();
            }
            
            // Update summary to show no data
            const currentElement = document.getElementById(`current_${reportType}`);
            const comparisonElement = document.getElementById(`comparison_${reportType}`);
            
            if (currentElement) {
                currentElement.textContent = 'No Data';
                currentElement.className = 'h5 mb-0 text-muted';
            }
            if (comparisonElement) {
                comparisonElement.textContent = '-';
            }
        }

        // Load sample data for demonstration
        function loadSampleData(reportType) {
            const sampleData = {
                pnl: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                    datasets: [
                        { data: [50000, 55000, 48000, 62000, 58000, 65000] },
                        { data: [30000, 32000, 28000, 35000, 33000, 38000] }
                    ],
                    current: 65000,
                    comparison: 58000,
                    change: 12.1
                },
                balance_sheet: {
                    datasets: [{ data: [150000, 80000, 70000] }],
                    current: 150000,
                    comparison: 140000,
                    change: 7.1
                },
                trial_balance: {
                    labels: ['Cash', 'AR', 'Inventory', 'AP', 'Equity'],
                    datasets: [
                        { data: [25000, 45000, 30000, 20000, 80000] },
                        { data: [0, 0, 0, 35000, 0] }
                    ],
                    current: 100000,
                    comparison: 95000,
                    change: 5.3
                },
                ar_ageing: {
                    datasets: [{ data: [40000, 25000, 15000, 8000, 5000] }],
                    current: 93000,
                    comparison: 85000,
                    change: 9.4
                },
                vat: {
                    datasets: [{ data: [5000, 8000, 3000] }],
                    current: 3000,
                    comparison: 2500,
                    change: 20.0
                }
            };

            const data = sampleData[reportType] || sampleData.pnl;
            updateChart(reportType, data);
            updateSummary(reportType, data);
        }

        // Update chart with data
        function updateChart(reportType, data) {
            const chart = charts[reportType];
            if (!chart) {
                console.error('Chart not found for', reportType);
                return;
            }

            // Hide loading, show chart
            const loadingEl = document.getElementById(`loading_${reportType}`);
            const chartContainer = document.getElementById(`chart_container_${reportType}`);
            const noDataEl = document.getElementById(`nodata_${reportType}`);
            
            if (loadingEl) loadingEl.style.display = 'none';
            if (noDataEl) noDataEl.style.display = 'none';
            if (chartContainer) chartContainer.style.display = 'block';

            // Update labels
            if (data.labels && Array.isArray(data.labels)) {
                chart.data.labels = data.labels;
            } else if (!chart.data.labels || chart.data.labels.length === 0) {
                // Set default labels if none provided
                chart.data.labels = [];
            }
            
            // Update datasets
            if (data.datasets && Array.isArray(data.datasets)) {
                chart.data.datasets.forEach((dataset, index) => {
                    if (data.datasets[index]) {
                        // Handle both {data: [...]} and just [...] formats
                        if (Array.isArray(data.datasets[index])) {
                            dataset.data = data.datasets[index];
                        } else if (data.datasets[index].data) {
                            dataset.data = data.datasets[index].data;
                        } else {
                            dataset.data = [];
                        }
                    } else {
                        dataset.data = [];
                    }
                });
            }
            
            chart.update('none'); // Update without animation for faster response
        }

        // Update summary cards
        function updateSummary(reportType, data) {
            const currentElement = document.getElementById(`current_${reportType}`);
            const comparisonElement = document.getElementById(`comparison_${reportType}`);
            const changeElement = document.getElementById(`change_${reportType}`);

            if (currentElement && data.current !== undefined) {
                currentElement.textContent = `AED ${data.current.toLocaleString()}`;
            }

            if (comparisonElement && data.comparison !== undefined) {
                comparisonElement.textContent = `AED ${data.comparison.toLocaleString()}`;
            }

            if (changeElement && data.change !== undefined) {
                const change = data.change;
                const isPositive = change >= 0;
                changeElement.innerHTML = `<i class="bi bi-arrow-${isPositive ? 'up' : 'down'} me-1"></i>${Math.abs(change).toFixed(1)}%`;
                changeElement.className = `badge bg-${isPositive ? 'success' : 'danger'} comparison-badge`;
            }
        }

        // View report
        function viewReport(reportType) {
            // Map report types to actual file names and parameter names
            const reportFiles = {
                'pnl': 'report_pnl.php',
                'balance_sheet': 'report_balance_sheet.php',
                'trial_balance': 'report_trial_balance.php',
                'ar_ageing': 'report_ar_ap_ageing.php',
                'vat': 'report_vat.php'
            };
            
            const fileName = reportFiles[reportType] || `report_${reportType}.php`;
            // Use 'from' and 'to' for most reports, 'asof' for balance sheet
            if (reportType === 'balance_sheet') {
                const url = `${fileName}?asof=<?= $to_date ?>`;
                window.open(url, '_blank');
            } else {
                const url = `${fileName}?from=<?= $from_date ?>&to=<?= $to_date ?>`;
                window.open(url, '_blank');
            }
        }

        // Export report
        function exportReport(reportType, format) {
            const reportFiles = {
                'pnl': 'report_pnl.php',
                'balance_sheet': 'report_balance_sheet.php',
                'trial_balance': 'report_trial_balance.php',
                'ar_ageing': 'report_ar_ap_ageing.php',
                'vat': 'report_vat.php'
            };
            
            const fileName = reportFiles[reportType] || `report_${reportType}.php`;
            if (reportType === 'balance_sheet') {
                const url = `${fileName}?asof=<?= $to_date ?>&export=csv`;
                window.open(url, '_blank');
            } else {
                const url = `${fileName}?from=<?= $from_date ?>&to=<?= $to_date ?>&export=csv`;
                window.open(url, '_blank');
            }
        }

        // Reset filters
        function resetFilters() {
            window.location.href = 'reports_enhanced.php';
        }

        // Saved Configurations Functions
        function showSavedConfigsModal() {
            new bootstrap.Modal(document.getElementById('savedConfigsModal')).show();
            loadSavedConfigs();
        }

        function loadSavedConfigs() {
            fetch('ajax/saved_reports.php?action=list')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displaySavedConfigs(data.configs);
                    } else {
                        document.getElementById('savedConfigsList').innerHTML = 
                            '<div class="alert alert-danger">Error loading configurations: ' + data.error + '</div>';
                    }
                })
                .catch(error => {
                    document.getElementById('savedConfigsList').innerHTML = 
                        '<div class="alert alert-danger">Error loading configurations</div>';
                });
        }

        function displaySavedConfigs(configs) {
            const container = document.getElementById('savedConfigsList');
            
            if (configs.length === 0) {
                container.innerHTML = '<div class="text-center text-muted">No saved configurations found</div>';
                return;
            }

            let html = '<div class="row g-3">';
            configs.forEach(config => {
                const reportTypeNames = {
                    'pnl': 'Profit & Loss',
                    'balance_sheet': 'Balance Sheet',
                    'trial_balance': 'Trial Balance',
                    'ar_ageing': 'AR Ageing',
                    'vat': 'VAT Report'
                };

                html += `
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="card-title">${config.config_name}</h6>
                                        <p class="card-text text-muted small">${reportTypeNames[config.report_type] || config.report_type}</p>
                                        <small class="text-muted">
                                            ${config.from_date} to ${config.to_date}
                                            ${config.comparison_period !== 'none' ? ` (${config.comparison_period.toUpperCase()})` : ''}
                                        </small>
                                    </div>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                            Actions
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="#" onclick="loadConfig(${config.id})">Load</a></li>
                                            <li><a class="dropdown-item" href="#" onclick="setAsDefault(${config.id}, '${config.report_type}')">Set as Default</a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a class="dropdown-item text-danger" href="#" onclick="deleteConfig(${config.id})">Delete</a></li>
                                        </ul>
                                    </div>
                                </div>
                                ${config.is_default ? '<span class="badge bg-primary">Default</span>' : ''}
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            container.innerHTML = html;
        }

        function loadConfig(configId) {
            fetch('ajax/saved_reports.php?action=load&config_id=' + configId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const config = data.config;
                        // Update form fields
                        document.querySelector('select[name="type"]').value = config.report_type;
                        document.querySelector('input[name="from_date"]').value = config.from_date;
                        document.querySelector('input[name="to_date"]').value = config.to_date;
                        document.querySelector('select[name="comparison"]').value = config.comparison_period;
                        
                        // Submit form to reload with new settings
                        document.querySelector('form').submit();
                    } else {
                        alert('Error loading configuration: ' + data.error);
                    }
                })
                .catch(error => {
                    alert('Error loading configuration');
                });
        }

        function setAsDefault(configId, reportType) {
            const formData = new FormData();
            formData.append('action', 'set_default');
            formData.append('config_id', configId);
            formData.append('report_type', reportType);

            fetch('ajax/saved_reports.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadSavedConfigs(); // Refresh the list
                } else {
                    alert('Error setting default: ' + data.error);
                }
            })
            .catch(error => {
                alert('Error setting default');
            });
        }

        function deleteConfig(configId) {
            if (!confirm('Are you sure you want to delete this configuration?')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('config_id', configId);

            fetch('ajax/saved_reports.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadSavedConfigs(); // Refresh the list
                } else {
                    alert('Error deleting configuration: ' + data.error);
                }
            })
            .catch(error => {
                alert('Error deleting configuration');
            });
        }

        function saveCurrentConfig() {
            new bootstrap.Modal(document.getElementById('saveConfigModal')).show();
        }

        function saveConfig() {
            const configName = document.getElementById('configName').value;
            const reportType = document.getElementById('configReportType').value;
            const setAsDefault = document.getElementById('setAsDefault').checked;

            if (!configName || !reportType) {
                alert('Please fill in all required fields');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'save');
            formData.append('config_name', configName);
            formData.append('report_type', reportType);
            formData.append('from_date', document.querySelector('input[name="from_date"]').value);
            formData.append('to_date', document.querySelector('input[name="to_date"]').value);
            formData.append('comparison_period', document.querySelector('select[name="comparison"]').value);
            formData.append('is_default', setAsDefault);

            fetch('ajax/saved_reports.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('saveConfigModal')).hide();
                    document.getElementById('saveConfigForm').reset();
                    alert('Configuration saved successfully!');
                } else {
                    alert('Error saving configuration: ' + data.error);
                }
            })
            .catch(error => {
                alert('Error saving configuration');
            });
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Check if Chart.js is loaded
            if (typeof Chart === 'undefined') {
                console.error('Chart.js is not loaded!');
                document.querySelectorAll('[id^="loading_"]').forEach(el => {
                    el.innerHTML = '<div class="alert alert-danger">Chart.js library failed to load. Please refresh the page.</div>';
                });
                return;
            }
            
            console.log('Chart.js loaded, initializing charts...');
            initializeCharts();
            
            // Fallback: If charts are still loading after 10 seconds, show error
            setTimeout(() => {
                Object.keys(chartConfigs).forEach(key => {
                    const loadingEl = document.getElementById(`loading_${key}`);
                    if (loadingEl && loadingEl.style.display !== 'none') {
                        console.warn('Chart', key, 'still loading after 10 seconds');
                        showEmptyState(key, 'Data loading timeout. Please check browser console for errors.');
                    }
                });
            }, 10000);
        });
    </script>
<script>
  // Clean URL navigation for data-tab links
  document.querySelectorAll('a[data-tab]').forEach(link => {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      const tab = this.getAttribute('data-tab');
      const form = document.createElement('form');
      form.method = 'POST';
      // Use absolute path based on current location
      const currentPath = window.location.pathname;
      const basePath = currentPath.substring(0, currentPath.indexOf('/accounts'));
      form.action = basePath + '/account';
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'tab';
      input.value = tab;
      form.appendChild(input);
      document.body.appendChild(form);
      form.submit();
    });
  });
</script>
</body>
</html>
