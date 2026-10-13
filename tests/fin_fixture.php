<?php
declare(strict_types=1);

/**
 * Chart and journals the finance tests build for themselves inside their
 * rolled-back transaction. Test data only: none of it is loaded by the app.
 */

/** Each entry is [code, name] or [code, name, normal balance]. */
function fin_test_chart(): array
{
    return [
        ['1000000', 'Assets'],
        ['1100000', 'Current assets'],
        ['1110000', 'Cash & banks'],
        ['1111000', 'Cash in hand'],
        ['1111001', 'Cash in hand'],
        ['1111002', 'Petty cash'],
        ['1112000', 'Bank accounts'],
        ['1112001', 'AHLI 0001 555999 301'],
        ['1113000', 'Credit cards'],
        ['1113001', 'Credit cards'],
        ['1120000', 'Trade & other receivables'],
        ['1120001', 'Clients'],
        ['1120002', 'Staff loans'],
        ['1120003', 'Prepayments'],
        ['1120004', 'Purchase control'],
        ['1120005', 'AR clearing'],
        ['1120006', 'Production control'],
        ['1130000', 'Due from related parties'],
        ['1130001', 'Current accounts with group companies'],
        ['1140000', 'Inventory'],
        ['1140001', 'Raw material (RM)'],
        ['1140002', 'SRM'],
        ['1140003', 'Ready-made'],
        ['1140004', 'Finished products (FP)'],
        ['1140005', 'Work in progress (WIP)'],
        ['1140006', 'Inventory provision', 'credit'],
        ['1150000', 'Deposits'],
        ['1150001', 'Showroom rent deposits'],
        ['1150002', 'Electricity deposits'],
        ['1150003', 'Other deposits'],
        ['1200000', 'Non-current assets'],
        ['1210000', 'Fixed assets'],
        ['1210001', 'Tangible fixed assets'],
        ['1210002', 'Intangible assets'],
        ['1210003', 'Accumulated depreciation', 'credit'],
        ['1210004', 'Accumulated amortization', 'credit'],
        ['1220000', 'Investments'],
        ['1220001', 'Projects under process'],
        ['1220002', 'Investee companies'],

        ['2000000', 'Liabilities'],
        ['2100000', 'Current liabilities'],
        ['2110000', 'Trade & other payables'],
        ['2110001', 'Suppliers'],
        ['2110002', 'Accruals'],
        ['2110003', 'Salary payable'],
        ['2110004', 'Related parties'],
        ['2110005', 'Ticket provision'],
        ['2110006', 'Leave provision'],
        ['2200000', 'Non-current liabilities'],
        ['2210000', 'Loans & borrowings'],
        ['2210001', 'Bank loans'],
        ['2210002', 'Gratuity provision'],

        ['3000000', 'Equity'],
        ['3100000', 'Capital'],
        ['3100001', 'Paid capital'],
        ['3100002', 'Legal reserve'],
        ['3200000', 'Retained earnings'],
        ['3200001', 'Retained earnings'],
        ['3200002', 'Profit & loss account'],
        ['3300000', "Shareholders' account"],
        ['3300001', "Shareholders' current account"],

        ['4000000', 'Revenue'],
        ['4100000', 'Sales'],
        ['4100001', 'Sales - Ready-made'],
        ['4100002', 'Sales - Raw material'],
        ['4100003', 'Sales - Packing material'],
        ['4100004', 'Sales - Wet wipes'],
        ['4100005', 'Sales returns', 'debit'],
        ['4100006', 'Sales discounts', 'debit'],
        ['4100007', 'Gift coupons', 'debit'],
        ['4200000', 'Other income'],
        ['4200001', 'Other income'],
        ['4200002', 'Gain or loss on sale of assets'],

        ['5000000', 'Expenses'],
        ['5100000', 'Cost of sales / production'],
        ['5100001', 'Production cost - Material'],
        ['5100002', 'Production cost - Labour'],
        ['5100003', 'Production cost - Overhead'],
        ['5100004', 'Cost of sales - Ready-made'],
        ['5100005', 'Cost of sales - Raw material'],
        ['5100006', 'Cost of sales - Packing material'],
        ['5100007', 'Cost of sales - Wet wipes'],
        ['5100008', 'Inventory loss/gain'],
        ['5200000', 'Packing & display'],
        ['5200001', 'Packing and display expense'],
        ['5300000', 'Operation expenses'],
        ['5300001', 'Salaries'],
        ['5300002', 'Overtime'],
        ['5300003', 'Accommodation'],
        ['5300004', 'Transport'],
        ['5300005', 'Employee benefits'],
        ['5300006', 'Utilities'],
        ['5400000', 'General expenses'],
        ['5400001', 'Rent'],
        ['5400002', 'Admin expenses'],
        ['5400003', 'Advertising'],
        ['5400004', 'Cleaning'],
        ['5400005', 'Maintenance'],
        ['5400006', 'Finance cost'],
        ['5400007', 'Legal fees'],
        ['5400008', 'Government fees'],
        ['5400009', 'Miscellaneous'],
        ['5500000', 'Depreciation'],
        ['5500001', 'Depreciation - IT equipment'],
        ['5500002', 'Depreciation - Software'],
        ['5500003', 'Depreciation - Furniture'],
        ['5500004', 'Depreciation - Electrical'],
        ['5500005', 'Depreciation - Machinery'],
        ['5500006', 'Depreciation - Vehicles'],
        ['5500007', 'Amortization'],

        // Outside the five classes: items in transit until they are matched.
        ['9000000', 'Clearing accounts'],
        ['9000001', 'Purchases clearing'],
        ['9000002', 'Sales clearing'],
    ];
}

function fin_test_load_chart(PDO $conn, int $companyId): int
{
    foreach (fin_test_chart() as $a) {
        fin_coa_add_account($conn, $companyId, $a[0], $a[1], $a[2] ?? null, null);
    }
    return count(fin_test_chart());
}

/** Each journal is [date, description, [[code, debit, credit], ...]]. */
function fin_test_journals(): array
{
    return [
        ['2025-12-31', 'Opening balances', [
            ['1111001', 12500.00, 0],
            ['1112001', 486320.75, 0],
            ['1120001', 215400.00, 0],
            ['1120003', 48000.00, 0],
            ['1140003', 132750.50, 0],
            ['1150001', 60000.00, 0],
            ['1210001', 380000.00, 0],
            ['1210003', 0, 95000.00],
            ['2110001', 0, 164300.25],
            ['2110002', 0, 22671.00],
            ['2210001', 0, 250000.00],
            ['3100001', 0, 500000.00],
            ['3200001', 0, 303000.00],
        ]],
        ['2026-01-05', 'Cash sales', [['1112001', 84500.00, 0], ['4100001', 0, 84500.00]]],
        ['2026-01-08', 'Credit sales', [['1120001', 46200.00, 0], ['4100004', 0, 46200.00]]],
        ['2026-01-10', 'Receipt from clients', [['1112001', 120000.00, 0], ['1120001', 0, 120000.00]]],
        ['2026-01-12', 'Payment to suppliers', [['2110001', 75000.00, 0], ['1112001', 0, 75000.00]]],
        ['2026-01-15', 'Stock purchased on credit', [['1140003', 58300.00, 0], ['2110001', 0, 58300.00]]],
        ['2026-01-20', 'Cost of goods sold', [['5100004', 61750.00, 0], ['1140003', 0, 61750.00]]],
        ['2026-01-25', 'Rent for the month', [['5400001', 16000.00, 0], ['1120003', 0, 16000.00]]],
        ['2026-01-28', 'Salaries paid', [['5300001', 42800.00, 0], ['1112001', 0, 42800.00]]],
        ['2026-01-31', 'Depreciation', [['5500003', 3950.00, 0], ['1210003', 0, 3950.00]]],
        ['2026-01-31', 'Utilities paid in cash', [['5300006', 1840.50, 0], ['1111001', 0, 1840.50]]],
        ['2026-01-31', 'Sales returned by clients', [['4100005', 2100.00, 0], ['1120001', 0, 2100.00]]],
        ['2026-02-03', 'Cash sales', [['1112001', 15000.00, 0], ['4100001', 0, 15000.00]]],
    ];
}

/** Posts the journals as journal vouchers. Returns the number of lines. */
function fin_test_load_journals(PDO $conn, int $companyId): int
{
    $stmt = $conn->prepare("SELECT code, id FROM fin_coa_accounts WHERE company_id = ? AND level = 'ledger'");
    $stmt->execute([$companyId]);
    $ids = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $n = 0;
    foreach (fin_test_journals() as [$date, $desc, $lines]) {
        $voucherLines = [];
        foreach ($lines as [$code, $debit, $credit]) {
            $voucherLines[] = ['account_id' => (int)$ids[$code], 'description' => '', 'debit' => $debit, 'credit' => $credit];
            $n++;
        }
        fin_voucher_create($conn, $companyId, 'jv', ['date' => $date, 'narration' => $desc, 'lines' => $voucherLines]);
    }
    return $n;
}
