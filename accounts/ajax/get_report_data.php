<?php
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db_connect.php';
require_once __DIR__.'/../../includes/ar_helpers.php';
require_role(['Owner','Admin','Account'], $conn);

header('Content-Type: application/json');

$report_type = $_GET['type'] ?? '';
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$comparison = $_GET['comparison'] ?? 'none';

// Calculate comparison periods
$comparison_from = $comparison_to = null;
if ($comparison === 'yoy') {
    $comparison_from = date('Y-m-d', strtotime($from_date . ' -1 year'));
    $comparison_to = date('Y-m-d', strtotime($to_date . ' -1 year'));
} elseif ($comparison === 'mom') {
    $comparison_from = date('Y-m-d', strtotime($from_date . ' -1 month'));
    $comparison_to = date('Y-m-d', strtotime($to_date . ' -1 month'));
}

try {
    if (empty($report_type)) {
        throw new Exception('Report type is required');
    }
    
    $data = [];
    
    switch ($report_type) {
        case 'pnl':
            $data = getPnLData($conn, $from_date, $to_date, $comparison_from, $comparison_to);
            break;
        case 'balance_sheet':
            $data = getBalanceSheetData($conn, $to_date, $comparison_to);
            break;
        case 'trial_balance':
            $data = getTrialBalanceData($conn, $to_date, $comparison_to);
            break;
        case 'ar_ageing':
            $data = getARAgeingData($conn, $to_date, $comparison_to);
            break;
        case 'vat':
            $data = getVATData($conn, $from_date, $to_date, $comparison_from, $comparison_to);
            break;
        default:
            throw new Exception('Invalid report type: ' . $report_type);
    }
    
    // Ensure data has required structure
    if (!isset($data['datasets'])) {
        $data['datasets'] = [];
    }
    if (!isset($data['current'])) {
        $data['current'] = 0;
    }
    if (!isset($data['comparison'])) {
        $data['comparison'] = 0;
    }
    if (!isset($data['change'])) {
        $data['change'] = 0;
    }
    
    echo json_encode(['success' => true, 'data' => $data]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function getPnLData($conn, $from_date, $to_date, $comparison_from, $comparison_to) {
    // Use GL journals like the actual P&L report does
    $sql = "
        SELECT 
            DATE_FORMAT(j.journal_date, '%Y-%m') as month,
            a.type,
            SUM(l.debit) AS debit, 
            SUM(l.credit) AS credit
        FROM gl_journal_lines l
        JOIN gl_journals j ON j.id = l.journal_id 
            AND j.journal_date BETWEEN ? AND ? 
            AND j.is_posted = 1 
            AND j.is_reversed = 0
        JOIN chart_of_accounts a ON a.id = l.account_id
        WHERE a.type IN ('Revenue','Expense')
        GROUP BY DATE_FORMAT(j.journal_date, '%Y-%m'), a.type
        ORDER BY month, a.type
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$from_date, $to_date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Organize by month
    $monthly_data = [];
    foreach ($rows as $row) {
        $month = $row['month'];
        if (!isset($monthly_data[$month])) {
            $monthly_data[$month] = ['revenue' => 0, 'expense' => 0];
        }
        if ($row['type'] === 'Revenue') {
            $monthly_data[$month]['revenue'] += (float)$row['credit'] - (float)$row['debit'];
        } else {
            $monthly_data[$month]['expense'] += (float)$row['debit'] - (float)$row['credit'];
        }
    }
    
    // Calculate totals
    $current_revenue = 0;
    $current_expense = 0;
    foreach ($monthly_data as $data) {
        $current_revenue += $data['revenue'];
        $current_expense += $data['expense'];
    }
    $current_profit = $current_revenue - $current_expense;
    
    // Generate monthly labels and values
    $labels = [];
    $revenue_values = [];
    $expense_values = [];
    
    $start = new DateTime($from_date);
    $end = new DateTime($to_date);
    $interval = new DateInterval('P1M');
    
    while ($start <= $end) {
        $month = $start->format('Y-m');
        $labels[] = $start->format('M Y');
        
        $revenue_values[] = $monthly_data[$month]['revenue'] ?? 0;
        $expense_values[] = $monthly_data[$month]['expense'] ?? 0;
        
        $start->add($interval);
    }
    
    // Get comparison data
    $comparison_revenue = 0;
    $comparison_expense = 0;
    if ($comparison_from && $comparison_to) {
        $stmt = $conn->prepare($sql);
        $stmt->execute([$comparison_from, $comparison_to]);
        $comp_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($comp_rows as $row) {
            if ($row['type'] === 'Revenue') {
                $comparison_revenue += (float)$row['credit'] - (float)$row['debit'];
            } else {
                $comparison_expense += (float)$row['debit'] - (float)$row['credit'];
            }
        }
    }
    
    $change = 0;
    $comparison_profit = $comparison_revenue - $comparison_expense;
    if ($comparison_profit != 0) {
        $change = (($current_profit - $comparison_profit) / abs($comparison_profit)) * 100;
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            ['data' => $revenue_values],
            ['data' => $expense_values]
        ],
        'current' => $current_profit,
        'comparison' => $comparison_profit,
        'change' => $change
    ];
}

function getBalanceSheetData($conn, $to_date, $comparison_to) {
    // Get current period data
    $assets_sql = "
        SELECT SUM(COALESCE(ab.debit, 0) - COALESCE(ab.credit, 0)) as total
        FROM chart_of_accounts coa
        LEFT JOIN gl_account_balances ab ON coa.id = ab.account_id AND ab.period = ?
        WHERE coa.type = 'Asset' AND coa.is_active = 1
    ";
    
    $liabilities_sql = "
        SELECT SUM(COALESCE(ab.credit, 0) - COALESCE(ab.debit, 0)) as total
        FROM chart_of_accounts coa
        LEFT JOIN gl_account_balances ab ON coa.id = ab.account_id AND ab.period = ?
        WHERE coa.type = 'Liability' AND coa.is_active = 1
    ";
    
    $equity_sql = "
        SELECT SUM(COALESCE(ab.credit, 0) - COALESCE(ab.debit, 0)) as total
        FROM chart_of_accounts coa
        LEFT JOIN gl_account_balances ab ON coa.id = ab.account_id AND ab.period = ?
        WHERE coa.type = 'Equity' AND coa.is_active = 1
    ";
    
    $period = substr($to_date, 0, 7);
    $comparison_period = $comparison_to ? substr($comparison_to, 0, 7) : null;
    
    $stmt = $conn->prepare($assets_sql);
    $stmt->execute([$period]);
    $assets = (float)$stmt->fetchColumn();
    
    $stmt = $conn->prepare($liabilities_sql);
    $stmt->execute([$period]);
    $liabilities = (float)$stmt->fetchColumn();
    
    $stmt = $conn->prepare($equity_sql);
    $stmt->execute([$period]);
    $equity = (float)$stmt->fetchColumn();
    
    // Get comparison data
    $comparison_assets = $comparison_liabilities = $comparison_equity = 0;
    if ($comparison_period) {
        $stmt = $conn->prepare($assets_sql);
        $stmt->execute([$comparison_period]);
        $comparison_assets = (float)$stmt->fetchColumn();
        
        $stmt = $conn->prepare($liabilities_sql);
        $stmt->execute([$comparison_period]);
        $comparison_liabilities = (float)$stmt->fetchColumn();
        
        $stmt = $conn->prepare($equity_sql);
        $stmt->execute([$comparison_period]);
        $comparison_equity = (float)$stmt->fetchColumn();
    }
    
    $change = 0;
    if ($comparison_assets > 0) {
        $change = (($assets - $comparison_assets) / $comparison_assets) * 100;
    }
    
    return [
        'datasets' => [[
            'data' => [max(0, $assets), max(0, $liabilities), max(0, $equity)]
        ]],
        'current' => $assets,
        'comparison' => $comparison_assets,
        'change' => $change
    ];
}

function getTrialBalanceData($conn, $to_date, $comparison_to) {
    // Use GL journals like the actual Trial Balance report does (cumulative up to to_date)
    $sql = "
        SELECT 
            a.account_no, 
            a.name, 
            a.type,
            a.normal_balance,
            COALESCE(SUM(l.debit), 0) AS total_debit,
            COALESCE(SUM(l.credit), 0) AS total_credit,
            COALESCE(SUM(
                CASE WHEN a.normal_balance = 'debit' 
                    THEN l.debit - l.credit 
                    ELSE l.credit - l.debit 
                END
            ), 0) AS cumulative_balance
        FROM chart_of_accounts a
        LEFT JOIN gl_journal_lines l ON l.account_id = a.id
        LEFT JOIN gl_journals j ON j.id = l.journal_id
        WHERE a.is_active = 1
            AND (j.journal_date IS NULL OR j.journal_date <= ?)
            AND (j.is_posted IS NULL OR j.is_posted = 1)
            AND (j.is_reversed IS NULL OR j.is_reversed = 0)
        GROUP BY a.id, a.account_no, a.name, a.type, a.normal_balance
        HAVING total_debit > 0 OR total_credit > 0
        ORDER BY a.account_no
        LIMIT 10
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$to_date]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $labels = [];
    $debits = [];
    $credits = [];
    $total_debit = 0;
    $total_credit = 0;
    
    foreach ($data as $row) {
        $labels[] = $row['account_no'] . ' - ' . substr($row['name'], 0, 20);
        $debits[] = (float)$row['total_debit'];
        $credits[] = (float)$row['total_credit'];
        $total_debit += (float)$row['total_debit'];
        $total_credit += (float)$row['total_credit'];
    }
    
    // Get comparison data
    $comparison_total = 0;
    if ($comparison_to) {
        $stmt = $conn->prepare($sql);
        $stmt->execute([$comparison_to]);
        $comp_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $comparison_total = array_sum(array_column($comp_data, 'total_debit'));
    }
    
    $change = 0;
    if ($comparison_total > 0) {
        $change = (($total_debit - $comparison_total) / $comparison_total) * 100;
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            ['data' => $debits],
            ['data' => $credits]
        ],
        'current' => $total_debit,
        'comparison' => $comparison_total,
        'change' => $change
    ];
}

function getARAgeingData($conn, $to_date, $comparison_to) {
    // Get current period data
    $sql = "
        SELECT 
            SUM(CASE WHEN DATEDIFF(?, due_date) <= 0 THEN balance_due ELSE 0 END) as current,
            SUM(CASE WHEN DATEDIFF(?, due_date) BETWEEN 1 AND 30 THEN balance_due ELSE 0 END) as days_30,
            SUM(CASE WHEN DATEDIFF(?, due_date) BETWEEN 31 AND 60 THEN balance_due ELSE 0 END) as days_60,
            SUM(CASE WHEN DATEDIFF(?, due_date) BETWEEN 61 AND 90 THEN balance_due ELSE 0 END) as days_90,
            SUM(CASE WHEN DATEDIFF(?, due_date) > 90 THEN balance_due ELSE 0 END) as days_90_plus
        FROM invoices 
        WHERE status IN ('issued', 'partially_paid') AND balance_due > 0
          AND " . ar_collectible_invoice_sql() . "
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$to_date, $to_date, $to_date, $to_date, $to_date]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $current_total = array_sum($data);
    
    // Get comparison data
    $comparison_total = 0;
    if ($comparison_to) {
        $stmt = $conn->prepare($sql);
        $stmt->execute([$comparison_to, $comparison_to, $comparison_to, $comparison_to, $comparison_to]);
        $comp_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $comparison_total = array_sum($comp_data);
    }
    
    $change = 0;
    if ($comparison_total > 0) {
        $change = (($current_total - $comparison_total) / $comparison_total) * 100;
    }
    
    return [
        'datasets' => [[
            'data' => [
                (float)$data['current'],
                (float)$data['days_30'],
                (float)$data['days_60'],
                (float)$data['days_90'],
                (float)$data['days_90_plus']
            ]
        ]],
        'current' => $current_total,
        'comparison' => $comparison_total,
        'change' => $change
    ];
}

function getVATData($conn, $from_date, $to_date, $comparison_from, $comparison_to) {
    // Get current period data
    $input_vat_sql = "
        SELECT SUM(COALESCE(ab.debit, 0)) as total
        FROM chart_of_accounts coa
        LEFT JOIN gl_account_balances ab ON coa.id = ab.account_id AND ab.period = ?
        WHERE coa.account_no = '1260' AND coa.is_active = 1
    ";
    
    $output_vat_sql = "
        SELECT SUM(COALESCE(ab.credit, 0)) as total
        FROM chart_of_accounts coa
        LEFT JOIN gl_account_balances ab ON coa.id = ab.account_id AND ab.period = ?
        WHERE coa.account_no = '2210' AND coa.is_active = 1
    ";
    
    $period = substr($to_date, 0, 7);
    $stmt = $conn->prepare($input_vat_sql);
    $stmt->execute([$period]);
    $input_vat = (float)$stmt->fetchColumn();
    
    $stmt = $conn->prepare($output_vat_sql);
    $stmt->execute([$period]);
    $output_vat = (float)$stmt->fetchColumn();
    
    $net_vat = $output_vat - $input_vat;
    
    // Get comparison data
    $comparison_input = $comparison_output = 0;
    if ($comparison_from && $comparison_to) {
        $comparison_period = substr($comparison_to, 0, 7);
        $stmt = $conn->prepare($input_vat_sql);
        $stmt->execute([$comparison_period]);
        $comparison_input = (float)$stmt->fetchColumn();
        
        $stmt = $conn->prepare($output_vat_sql);
        $stmt->execute([$comparison_period]);
        $comparison_output = (float)$stmt->fetchColumn();
    }
    
    $comparison_net = $comparison_output - $comparison_input;
    
    $change = 0;
    if ($comparison_net != 0) {
        $change = (($net_vat - $comparison_net) / abs($comparison_net)) * 100;
    }
    
    return [
        'datasets' => [[
            'data' => [$input_vat, $output_vat, $net_vat]
        ]],
        'current' => $net_vat,
        'comparison' => $comparison_net,
        'change' => $change
    ];
}
?>
