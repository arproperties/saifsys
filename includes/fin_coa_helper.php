<?php
/**
 * Finance > Chart of Accounts (New).
 *
 * One chart per company in fin_coa_accounts. Standalone: it does not use the
 * older chart_of_accounts table, and nothing posts to it yet.
 *
 * A code is 7 digits: one digit for each of four group levels, then a 3-digit
 * ledger number. The levels are stored as class, group, sub_group, category and
 * shown as 1st to 4th group.
 *   1000000 1st group  Assets
 *   1100000 2nd group  Current assets
 *   1110000 3rd group  Cash & banks
 *   1112000 4th group  Bank accounts
 *   1112001 ledger     AHLI 0001 555999 301
 * Smaller classes skip levels: 3100001 Paid capital sits directly under 3100000.
 * Entries are posted to the last level of a branch: any account with nothing
 * under it, whether its code ends in 000 or not.
 * A code ending 999 is a total line (1112999 = Bank accounts total, 1199999 =
 * Current assets total). It closes its heading on the chart and takes no entries.
 */

const FIN_COA_LEVELS = [
    'class'     => '1st group',
    'group'     => '2nd group',
    'sub_group' => '3rd group',
    'category'  => '4th group',
    'ledger'    => 'Ledger account',
];

function fin_coa_table_ready(PDO $conn): bool
{
    $chk = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fin_coa_accounts'");
    $chk->execute();
    return (int)$chk->fetchColumn() > 0;
}

/** Level a code belongs to, or null when the code is not a valid 7-digit code. */
function fin_coa_level_for_code(string $code): ?string
{
    if (!preg_match('/^[1-9][0-9]{6}$/', $code)) {
        return null;
    }
    if (substr($code, 4) === '999') {
        return 'total';
    }
    if (substr($code, 4) !== '000') {
        return 'ledger';
    }
    if ($code[3] !== '0') {
        return 'category';
    }
    if ($code[2] !== '0') {
        return 'sub_group';
    }
    if ($code[1] !== '0') {
        return 'group';
    }
    return 'class';
}

/** Heading level a total line closes: 1111999 a category, 1119999 a sub-group, 1199999 a group, 1999999 a class. */
function fin_coa_total_of(string $code): string
{
    $nines = strlen($code) - strlen(rtrim($code, '9'));
    return $nines >= 6 ? 'class' : ($nines === 5 ? 'group' : ($nines === 4 ? 'sub_group' : 'category'));
}

/** Heading codes above a code, nearest first. */
function fin_coa_ancestor_codes(string $code): array
{
    $candidates = [
        substr($code, 0, 4) . '000',
        substr($code, 0, 3) . '0000',
        substr($code, 0, 2) . '00000',
        substr($code, 0, 1) . '000000',
    ];
    $out = [];
    foreach ($candidates as $c) {
        if ($c !== $code && !in_array($c, $out, true)) {
            $out[] = $c;
        }
    }
    return $out;
}

/**
 * Next free code for an account added by picking its place in the chart.
 * $codes are the codes the company already uses; $parentCode is the heading to
 * add under (null = top of the chart); $ledger picks a ledger account, otherwise
 * the next group level down. Null when nothing of that kind fits there.
 */
function fin_coa_suggest_code(array $codes, ?string $parentCode, bool $ledger): ?string
{
    $digits = 0; // leading digits the new code takes from its heading
    if ($parentCode !== null) {
        $level = fin_coa_level_for_code($parentCode);
        if ($level === null || $level === 'ledger' || $level === 'total') {
            return null;
        }
        $digits = ['class' => 1, 'group' => 2, 'sub_group' => 3, 'category' => 4][$level];
    }

    if ($ledger) {
        if ($parentCode === null) {
            return null;
        }
        $base = substr($parentCode, 0, 4);
        $last = 0;
        foreach ($codes as $c) {
            $c = (string)$c;
            if (substr($c, 0, 4) === $base && substr($c, 4) !== '999') {
                $last = max($last, (int)substr($c, 4));
            }
        }
        return $last < 998 ? $base . sprintf('%03d', $last + 1) : null;
    }

    if ($digits > 3) {
        return null;
    }
    $used = array_flip($codes);
    for ($d = 1; $d <= 9; $d++) {
        $code = str_pad(substr((string)$parentCode, 0, $digits) . $d, 7, '0');
        if (!isset($used[$code])) {
            return $code;
        }
    }
    return null;
}

/** Assets and expenses rise on the debit side; liabilities, equity and revenue on the credit side. */
function fin_coa_default_normal_balance(string $code): string
{
    return in_array($code[0], ['2', '3', '4'], true) ? 'credit' : 'debit';
}

/** Nearest heading the company already has above this code. */
function fin_coa_find_parent(PDO $conn, int $companyId, string $code): ?array
{
    $stmt = $conn->prepare("SELECT id, code, name, level FROM fin_coa_accounts WHERE company_id = ? AND code = ?");
    foreach (fin_coa_ancestor_codes($code) as $ancestor) {
        $stmt->execute([$companyId, $ancestor]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }
    return null;
}

/**
 * Add one account to a company's chart. Returns the new id.
 * Throws InvalidArgumentException with a message fit to show the user.
 * $blankNameOk is for loading a sheet that leaves some lines unnamed.
 */
function fin_coa_add_account(PDO $conn, int $companyId, string $code, string $name, ?string $normalBalance = null, ?int $userId = null, bool $blankNameOk = false): int
{
    $code = trim($code);
    $name = trim($name);
    $level = fin_coa_level_for_code($code);
    if ($level === null) {
        throw new InvalidArgumentException('The code must be 7 digits and cannot start with 0.');
    }
    if ($name === '' && !$blankNameOk) {
        throw new InvalidArgumentException('The account name is required.');
    }
    if (mb_strlen($name) > 150) {
        throw new InvalidArgumentException('The account name is too long (150 characters at most).');
    }
    if (!in_array($normalBalance, ['debit', 'credit'], true)) {
        $normalBalance = fin_coa_default_normal_balance($code);
    }

    $dup = $conn->prepare("SELECT COUNT(*) FROM fin_coa_accounts WHERE company_id = ? AND code = ?");
    $dup->execute([$companyId, $code]);
    if ((int)$dup->fetchColumn() > 0) {
        throw new InvalidArgumentException("Code {$code} is already used in this company.");
    }

    // No heading above it is allowed: the account then sits at the top of the chart.
    $parent = fin_coa_find_parent($conn, $companyId, $code);
    $parentId = $parent ? (int)$parent['id'] : null;

    $conn->prepare("INSERT INTO fin_coa_accounts (company_id, code, name, level, parent_id, normal_balance, created_by) VALUES (?,?,?,?,?,?,?)")
        ->execute([$companyId, $code, $name, $level, $parentId, $normalBalance, $userId]);
    $id = (int)$conn->lastInsertId();

    // A heading added between existing accounts and their old parent takes them over.
    $prefixLen = ['class' => 1, 'group' => 2, 'sub_group' => 3, 'category' => 4][$level] ?? null;
    if ($prefixLen !== null) {
        $conn->prepare("UPDATE fin_coa_accounts SET parent_id = ? WHERE company_id = ? AND id <> ? AND code LIKE ? AND parent_id <=> ?")
            ->execute([$id, $companyId, $id, substr($code, 0, $prefixLen) . '%', $parentId]);
    }

    return $id;
}

/** Change the name, normal balance or active flag. The code never changes. */
function fin_coa_update_account(PDO $conn, int $companyId, int $id, string $name, string $normalBalance, bool $isActive): void
{
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('The account name is required.');
    }
    if (mb_strlen($name) > 150) {
        throw new InvalidArgumentException('The account name is too long (150 characters at most).');
    }
    if (!in_array($normalBalance, ['debit', 'credit'], true)) {
        throw new InvalidArgumentException('Choose debit or credit.');
    }

    $stmt = $conn->prepare("SELECT id FROM fin_coa_accounts WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $companyId]);
    if (!$stmt->fetchColumn()) {
        throw new InvalidArgumentException('Account not found in this company.');
    }

    if (!$isActive) {
        $kids = $conn->prepare("SELECT COUNT(*) FROM fin_coa_accounts WHERE company_id = ? AND parent_id = ? AND is_active = 1 AND level <> 'total'");
        $kids->execute([$companyId, $id]);
        if ((int)$kids->fetchColumn() > 0) {
            throw new InvalidArgumentException('Deactivate the accounts under this heading first.');
        }
    }

    $conn->prepare("UPDATE fin_coa_accounts SET name = ?, normal_balance = ?, is_active = ? WHERE id = ? AND company_id = ?")
        ->execute([$name, $normalBalance, $isActive ? 1 : 0, $id, $companyId]);
}

/**
 * A company's chart in code order, which is also tree order. Each row carries
 * `depth` (0 = top), `is_leaf` (nothing under it, so entries can be posted to
 * it) and `ledger_count` (such accounts at or under the row).
 * Total lines are left out unless $withTotals is set; they are never a leaf and
 * never make their heading a parent.
 */
function fin_coa_accounts(PDO $conn, int $companyId, bool $withTotals = false): array
{
    $stmt = $conn->prepare("SELECT id, code, name, level, parent_id, normal_balance, is_active FROM fin_coa_accounts WHERE company_id = ?" . ($withTotals ? '' : " AND level <> 'total'") . " ORDER BY code");
    $stmt->execute([$companyId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byId = [];
    $hasChildren = [];
    foreach ($rows as $i => $r) {
        $rows[$i]['depth'] = 0;
        $rows[$i]['ledger_count'] = 0;
        $byId[(int)$r['id']] = $i;
        if ($r['parent_id'] !== null && $r['level'] !== 'total') {
            $hasChildren[(int)$r['parent_id']] = true;
        }
    }
    foreach ($rows as $i => $r) {
        $isLedger = $r['level'] !== 'total' && !isset($hasChildren[(int)$r['id']]);
        $rows[$i]['is_leaf'] = $isLedger;
        if ($isLedger) {
            $rows[$i]['ledger_count'] = 1;
        }
        $depth = 0;
        $pid = $r['parent_id'];
        while ($pid !== null && isset($byId[(int)$pid]) && $depth < 10) {
            $p = $byId[(int)$pid];
            if ($isLedger) {
                $rows[$p]['ledger_count']++;
            }
            $depth++;
            $pid = $rows[$p]['parent_id'];
        }
        $rows[$i]['depth'] = $depth;
    }
    return $rows;
}

/**
 * Starting chart: "Chart_Of_Accounts.pdf" line for line, with the names as they
 * are printed, total lines and unnamed lines included. One total the sheet
 * prints under a code already used was given the next number; it is marked
 * below. The two clearing accounts at the foot of the sheet are left out.
 * Each entry is [code, name].
 */
function fin_coa_sample_chart(): array
{
    return [
        ['1000000', 'ASSETS'],
        ['1100000', 'CURRENT ASSETS'],
        ['1110000', 'CASH & BANKS'],
        ['1111000', 'CASH IN HAND'],
        ['1111001', 'CASH'],
        ['1111002', 'PETTY CASH'],
        ['1111999', 'CASH IN HAND - TOTAL'],
        ['1112000', 'BANK ACCOUNTS'],
        ['1112001', 'AHLI 0001 555999 301'],
        ['1112002', 'QCB 4540 730222 001'],
        ['1112003', 'QCB 4540 730222 002'],
        ['1112004', 'QCB 4540 730222 003'],
        ['1112005', 'QNB 0170 002020 001'],
        ['1112999', 'BANK ACCOUNTS - TOTAL'],
        ['1113000', 'CREDIT CARD'],
        ['1113001', 'CREDIT CARD - PRO'],
        ['1113002', 'CREDIT CARD - IT'],
        ['1113999', 'CREDIT CARD - TOTAL'],
        ['1114000', 'CREDIT CARD CONTROL ACCOUNT'],
        ['1114001', 'CREDIT CARD - QCB'],
        ['1114002', 'CREDIT CARD - NBAD'],
        ['1114003', 'CREDIT CARD - BBK'],
        ['1114004', 'CREDIT CARD - ABK'],
        ['1114005', 'CREDIT CARD CONTROL A/C'],
        ['1114999', 'CREDIT CARD CONTROL ACCOUNT'],
        ['1119999', 'CASH & BANKS'],
        ['1120000', 'TRADE & OTHER RECEIVABLES'],
        ['1121000', 'TRADE RECEIVABLES - CLIENTS'],
        ['1121001', 'TRADE RECEIVABLES'],
        ['1121005', 'SUNDRY DEBTORS'],
        ['1121999', 'TRADE RECEIVABLES'],
        ['1122000', 'STAFF LOANS & ADVANCE'],
        ['1122001', 'EMPLOYEE STAFF LOANS'],
        ['1122002', 'CASH IMPREST EMPLOYEE'],
        ['1122999', 'STAFF LOANS & ADVANCE'],
        ['1123000', 'OTHER RECEIVABLES'],
        ['1123001', 'PREPAID RENT'],
        ['1123002', 'PREPAID EXPENSE'],
        ['1123003', 'PREPAID INSURANCE'],
        ['1123004', 'Advance given to supplier'],
        ['1123999', 'OTHER RECEIVABLES - TOTAL'],
        ['1124000', 'Purchase Control Account'],
        ['1124001', 'PURCHASE EXP CONTROL A/C - CD'],
        ['1124002', 'PURCHASE EXP CONTROL A/C - Su Ex'],
        ['1124003', 'PURCHASE EXP CONTROL A/C - CF'],
        ['1124004', 'PURCHASE EXP CONTROL A/C - F'],
        ['1124005', 'PURCHASE EXP CONTROL A/C - BC'],
        ['1124999', 'Purchase Control Account'],
        ['1125000', 'ACCOUNTS RECEIVABLE - CLEARING'],
        ['1125001', 'ACCOUNTS RECEIVABLE - BARWA'],
        ['1125002', 'ACCOUNTS RECEIVABLE - ELIXIR'],
        ['1125003', 'ACCOUNTS RECEIVABLE - OMAN'],
        ['1125999', 'ACCOUNTS RECEIVABLE - CLEARING'],
        ['1126000', 'PRODUCTION CONTROL ACCOUNT'],
        ['1126001', 'PCA - Depreciation Expense (Cost)'],
        ['1126002', 'PCA - Operation Expence (Cost)'],
        ['1126003', 'PCA - MASTER CUTTING SECTION'],
        ['1126004', 'PCA - MANUAL EMBROIDERY'],
        ['1126005', 'PCA - HAND EMBROIDERY'],
        ['1126006', 'PCA - ARRY SECTION'],
        ['1126007', 'PCA - COMPUTER EMBROIDERY'],
        ['1126008', 'PCA - LAZER CUTTING SECTION'],
        ['1126009', 'PCA - PAINTING SECTION'],
        ['1126010', 'PCA - CRYSTAL SECTION'],
        ['1126011', 'PCA - SEWING SECTION'],
        ['1126012', 'PCA - CLEANING AND TRIMMING'],
        ['1126013', 'PCA - BUTTON FIXING'],
        ['1126014', 'PCA - IRONING'],
        ['1126015', 'PCA - QUALITY CHEKING SECTION'],
        ['1126016', 'PCA - PACKING'],
        ['1126017', 'PCA - DIGITAL PRINTING'],
        ['1126018', 'PCA - ADMIN EXP (COST)'],
        ['1126999', 'PRODUCTION CONTROL ACCOUNT'],
        ['1129999', 'TRADE & OTHER RECEIVABLES'],
        ['1130000', 'DUE FROM RELATED PARTIES'],
        ['1130001', 'CURRENT ACCT - AL SIDDIQI RETAIL'],
        ['1130002', 'CURRENT ACCT - DARY'],
        ['1130003', 'CURRENT ACCT - MIGATO'],
        ['1130004', 'CURRENT ACCT - KAS'],
        ['1130005', 'CURRENT ACCT - INCH FACTORY'],
        ['1130006', 'CURRENT ACCT - AL MOTAHAJIBA UAE'],
        ['1130007', 'CURRENT ACCT - AL MOTAHAJIBA KUWAIT'],
        ['1130008', 'CURRENT ACCT - AL MOTAHAJIBA BAHRAIN'],
        ['1139999', 'DUE FROM RELATED PARTIES'],
        ['1140000', 'INVENTORY'],
        ['1140001', 'INVENTORY - RM'],
        ['1140002', 'INVENTORY - SRM'],
        ['1140003', 'INVENTORY - READYMADE'],
        ['1140004', 'INVENTORY - FP'],
        ['1140005', 'INVENTORY - SRMADE'],
        ['1140006', 'INVENTORY - WIP'],
        ['1140007', 'PROVISIONS FOR INVENTORY'],
        ['1149999', 'INVENTORY'],
        ['1150000', 'DEPOSITS'],
        ['1151000', 'SHOWROOM RENT DEPOSITS'],
        ['1151001', 'RENT DEPOSIT AL MOTAHAJIBA THE MALL'],
        ['1151002', 'RENT DEPOSIT AL MOTAHAJIBA MARKHIYA'],
        ['1151003', 'RENT DEPOSIT AL MOTAHAJIBA LAGOONA'],
        ['1151004', 'RENT DEPOSIT AL MOTAHAJIBA LANDMARK'],
        ['1151005', 'RENT DEPOSIT AL MOTAHAJIBA CITY CENTER'],
        ['1151006', 'RENT DEPOSIT AL MOTAHAJIBA MALL OF QATAR'],
        ['1151007', 'RENT DEPOSIT AL MOTAHAJIBA DOHA FESTIVAL CITY'],
        ['1151999', 'SHOWROOM RENT DEPOSITS'],
        ['1152000', 'ELECTRICITY DEPOSITS'],
        ['1152001', 'ELECTRICITY DEPOSITS SHOWROOM'],
        ['1152999', 'ELECTRICITY DEPOSITS'],
        ['1153000', 'OTHER DEPOSITS'],
        ['1153001', 'OTHER DEPOSITS'],
        ['1153999', 'OTHER DEPOSITS'],
        ['1159999', 'DEPOSITS - TOTAL'],
        ['1199999', 'CURRENT ASSETS'],
        ['1200000', 'NON CURRENT ASSETS'],
        ['1210000', 'FIXED ASSETS'],
        ['1211000', 'Tangible Asset'],
        ['1211001', 'BUILDING'],
        ['1211002', 'BV ELECTRICAL EQUIPMENTS'],
        ['1211003', 'BV EQUIPMENT & MACHINERY'],
        ['1211004', 'BV IT COMPUTER AND PRINTER'],
        ['1211005', 'BV FURNITURE & FIXTURES'],
        ['1211006', 'BV VEHICLES'],
        ['1211999', 'Tangible Asset'],
        ['1212000', 'Intangible Assets'],
        ['1212001', 'BV SOFTWARE'],
        ['1212999', 'Intangible Assets'],
        ['1213000', 'ACCUMULATED DEPRECIATION'],
        ['1213001', 'AD IT EQUIPMENS & ACCESSORIES'],
        ['1213002', 'AD FURNITURE & FIXTURES'],
        ['1213003', 'AD ELECTRICAL EQUIPMENTS'],
        ['1213004', 'AD EQUIPMENT & MACHINERY'],
        ['1213005', 'AD VEHICLES'],
        // The sheet prints this total as 1212999, already used by Intangible Assets.
        ['1213999', 'ACCUMULATED DEPRECIATION'],
        ['1214000', 'AMORTIZATION'],
        ['1214001', 'SOFTWARE'],
        ['1214999', 'AMORTIZATION'],
        ['1219999', 'FIXED ASSETS'],
        ['1220000', 'INVESTMENTS'],
        ['1221000', 'PROJECT UNDER PROCESS'],
        ['1221001', 'PROJECT UNDER PROCESS'],
        ['1221999', 'PROJECT UNDER PROCESS - TOTAL'],
        ['1222000', 'INVESTMENTS COMPANIES'],
        ['1222001', 'COMPANY - 1'],
        ['1222999', 'INVESTMENTS COMPANIES'],
        ['1229999', 'INVESTMENTS'],
        ['1299999', 'NON CURRENT ASSETS'],
        ['1999999', 'ASSETS'],

        ['2000000', 'LIABILITIES'],
        ['2100000', 'CURRENT LIABILITIES'],
        ['2110000', 'TRADE & OTHER PAYABLES'],
        ['2111000', 'TRADE PAYABLES'],
        ['2111001', 'SUPPLIER-1'],
        ['2111002', 'SUPPLIER-2'],
        ['2111003', 'SUPPLIER-3'],
        ['2111999', 'TRADE PAYABLES'],
        ['2112000', 'OTHER PAYABLES'],
        ['2112001', 'ClIENT RM/SO -OUTLETS'],
        ['2112002', 'NOTES PAYABLE'],
        ['2112003', 'SALARY PAYABLE'],
        ['2112004', 'STAFF GUARANTEE'],
        ['2112005', 'ACCRUED EXPENSES'],
        ['2112006', 'SUNDRY CREDITORS'],
        ['2112007', 'OTHER CREDIT ACCOUNTS'],
        ['2112008', 'Advance received from client'],
        ['2112999', 'OTHER PAYABLES'],
        ['2113000', 'DUE TO RELATED PARTIES'],
        ['2113001', 'RELATED PARTIES A/C'],
        ['2113999', 'DUE TO RELATED PARTIES'],
        ['2114000', 'OTHER PROVISIONS'],
        ['2114001', 'PROVISIONS FOR TICKET'],
        ['2114002', 'PROVISIONS FOR LEAVE SALARY'],
        ['2114999', 'OTHER PROVISIONS'],
        ['2119999', 'TRADE & OTHER PAYABLES'],
        ['2199999', 'CURRENT LIABILITIES'],
        ['2200000', 'NON CURRENT LIABILITIES'],
        ['2210000', 'LOANS & BORROWINGS'],
        // Unnamed on the sheet: it shares a line with 2211001.
        ['2211000', ''],
        ['2211001', 'BANK LOANS LC'],
        ['2211002', 'BANK LOANS OL'],
        ['2211999', 'LOANS & BORROWINGS'],
        ['2212000', 'PROVISIONS FOR EMPLOYEES'],
        ['2212001', 'PROVISIONS FOR GRATUITY'],
        ['2212999', 'PROVISIONS FOR EMPLOYEES'],
        ['2219999', 'LOANS & BORROWINGS - TOTAL'],
        ['2299999', 'NON CURRENT LIABILITIES'],
        ['2999999', 'LIABILITIES'],

        ['3000000', 'EQUITY'],
        ['3100000', 'CAPITAL'],
        ['3100001', 'PAID CAPITAL'],
        ['3100002', 'LEGAL RESERVE'],
        ['3199999', 'CAPITAL'],
        ['3200000', 'RETAINED EARNINGS'],
        ['3200001', 'RETAINED EARNINGS'],
        ['3200002', 'PROFIT & LOSS A/C'],
        ['3299999', 'RETAINED EARNINGS'],
        ['3300000', 'SHARE HOLDERS A/C'],
        ['3300001', 'SHARE HOLDERS CURRENT A/C'],
        ['3399999', 'SHARE HOLDERS A/C'],
        // Unnamed on the sheet.
        ['3999999', ''],

        ['4000000', 'REVENUE'],
        ['4100000', 'REVENUE'],
        ['4100001', 'SALES READY MADES'],
        ['4100002', 'SALES RAW MATERIAL'],
        ['4100003', 'SALES PACKING MA'],
        ['4100004', 'SALES WET WIPES'],
        ['4100005', 'SALES RETURN'],
        ['4100006', 'SALES DISCOUNT'],
        ['4100007', 'SALES GIFT COUPONS'],
        ['4199999', 'REVENUE'],
        ['4200000', 'OTHER INCOME'],
        ['4200001', 'OTHER INCOME'],
        ['4200002', 'GAIN/LOSS SALES OF ASSETS'],
        ['4299999', 'OTHER INCOME'],
        ['4999999', 'REVENUE'],

        ['5000000', 'EXPENSES'],
        ['5100000', 'COST OF SALES / PRODUCTION COST'],
        ['5110000', 'PRODUCTION COST'],
        ['5110001', 'DIRECT MATERIAL - READY MADE'],
        ['5110002', 'DIRECT LABOUR - READY MADE'],
        ['5110003', 'DIRECT OVER HEAD - READY MADE'],
        ['5110004', 'OTHER OVER HEAD- READY MADE'],
        ['5119999', 'PRODUCTION COST TOTAL'],
        // Unnamed on the sheet: it shares a line with 5120001.
        ['5120000', ''],
        ['5120001', 'COST OF SALES OTHERS GROUP'],
        ['5120002', 'COST OF SALES READY MADE'],
        ['5120003', 'COST OF SALES RAW MATERIAL'],
        ['5120004', 'COST OF SALES PACKING M'],
        ['5120005', 'COST OF SALES WET WIPES'],
        ['5120006', 'INVENTORY LOSS/GAIN'],
        ['5129999', 'COST OF SALES'],
        ['5199999', 'COST OF SALES / PRODUCTION COST'],
        ['5200000', 'PACKING & DISPLAY EXP'],
        ['5200001', 'PACKING & DISPLAY EXP'],
        ['5299999', 'PACKING & DISPLAY EXP'],
        ['5300000', 'OPERATION EXPENSES'],
        ['5310000', 'SALARIES & WAGES'],
        ['5310001', 'SALARIES & WAGES'],
        ['5310002', 'OVERTIME'],
        ['5319999', 'SALARIES & WAGES'],
        ['5320000', 'ACCOMMODATION & TRANSPORTATION'],
        ['5320001', 'ACCOMMODATION EXP'],
        ['5320002', 'TRANSPORTATION EXP'],
        ['5329999', 'ACCOMMODATION & TRANSPORTATION'],
        ['5330000', 'EMPLOYEE BENEFITS'],
        ['5330001', 'LEAVE SALARY'],
        ['5330002', 'TICKET'],
        ['5330003', 'GRATUITY'],
        ['5330004', 'ID RENEWAL'],
        ['5330005', 'MEDICAL EXPENSE'],
        ['5330006', 'CONTRACT FEE'],
        ['5330007', 'SOCIAL INSURANCE'],
        ['5330008', 'VISA EXPENSE'],
        ['5330009', 'WELFARE EXPENSE'],
        ['5339999', 'EMPLOYEE BENEFITS'],
        ['5340000', 'UTILITY EXPENSE'],
        ['5340001', 'ELECTRICITY & WATER EXP'],
        ['5340002', 'TELEPHONE CHARGES'],
        ['5340003', 'MOBILE CHARGES'],
        ['5340004', 'INTERNET CHARGES'],
        ['5349999', 'UTILITY EXPENSE'],
        ['5399999', 'OPERATION EXPENSES'],
        ['5400000', 'GENERAL EXPENSE'],
        // Unnamed on the sheet.
        ['5410000', ''],
        // Unnamed on the sheet.
        ['5411000', ''],
        ['5411001', 'SHOWROOM RENT'],
        ['5411002', 'BONUS & INCENTIVES'],
        ['5411003', 'UNIFORMS'],
        ['5411004', 'RECRUITMENT CHARGES'],
        ['5411005', 'TRAINING CHARGES'],
        ['5411006', 'PRINTING & STATIONERY'],
        ['5411007', 'BUSINESS TRAVEL EXPENSE'],
        ['5411008', 'BUSINESS DEVELOPMENT EXPENSE'],
        ['5411009', 'INSURANCE CHARGES'],
        ['5411010', 'AUDIT CHARGES'],
        ['5411011', 'VEHICLE EXPENSE'],
        ['5411012', 'INVENTORY PROVISION EXPENSE'],
        // Unnamed on the sheet.
        ['5411999', ''],
        ['5412000', 'ADVERTISEMENT CHARGES'],
        ['5412001', 'ADVERTISEMENT ONLINE'],
        ['5412002', 'ADVERTISEMENT NEWS PAPER'],
        ['5412003', 'ADVERTISEMENT BANNER'],
        ['5412004', 'ADVERTISMENT - SMS'],
        ['5412005', 'FREEGOODS & COMPLIMENTORY'],
        ['5412006', 'ADVERTISEMENT - LOYALTY'],
        ['5412999', 'ADVERTISEMENT CHARGES'],
        ['5413000', 'CLEANING & MAINTENANCE EXP'],
        ['5413001', 'CLEANING CHARGES'],
        ['5413002', 'MAINTENANCE CHARGES'],
        ['5413999', 'CLEANING & MAINTENANCE EXP'],
        ['5414000', 'FINANCE COST'],
        ['5414001', 'BANK CHARGES'],
        ['5414002', 'BANK LC CHARGES'],
        ['5414003', 'BANK FACILITY PROFIT'],
        ['5414999', 'FINANCE COST'],
        ['5415000', 'LEGAL & GOVT CHARGES'],
        ['5415001', 'LEGAL CHARGES - CR'],
        ['5415002', 'LEGAL CHARGES - PROMOTION FEE'],
        ['5415003', 'LEGAL CHARGES - OTHERS'],
        ['5415999', 'LEGAL & GOVT CHARGES'],
        ['5416000', 'MISCELLANEOUS EXPENSE'],
        ['5416001', 'MISCELLANEOUS EXPENSE'],
        ['5416002', 'HOSPITALITY EXPENSE'],
        ['5416003', 'COMPUTER SUPPLIES'],
        ['5416004', 'POSTAL CHARGES'],
        ['5416005', 'TAILORING SUPPLIES'],
        ['5416006', 'Realized Gain and Loss'],
        ['5416007', 'Unrealized Gain & Loss'],
        ['5416008', 'Penny Difference (Currency Roundoff)'],
        ['5416999', 'MISCELLANEOUS EXPENSE'],
        // Unnamed on the sheet.
        ['5419999', ''],
        ['5499999', 'GENERAL EXPENSE'],
        ['5500000', 'DEPRECIATION'],
        ['5500001', 'DEP IT EQUIPMENS & ACCESSORIES'],
        ['5500002', 'DEP SOFTWARE'],
        ['5500003', 'DEP FURNITURE & FIXTURES'],
        ['5500004', 'DEP ELECTRICAL EQUIPMENTS'],
        ['5500005', 'DEP EQUIPMENT & MACHINERY'],
        ['5500006', 'DEP VEHICLES'],
        ['5500007', 'AMORTIZATION'],
        ['5599999', 'DEPRECIATION'],
        ['5999999', 'EXPENSES'],
    ];
}

/**
 * Load the starting chart into a company that has no accounts yet.
 * Returns how many accounts were added.
 */
function fin_coa_load_sample(PDO $conn, int $companyId, ?int $userId = null): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) FROM fin_coa_accounts WHERE company_id = ?");
    $stmt->execute([$companyId]);
    if ((int)$stmt->fetchColumn() > 0) {
        throw new InvalidArgumentException('This company already has accounts. The starting chart only loads into an empty chart.');
    }

    $ownTx = !$conn->inTransaction();
    if ($ownTx) {
        $conn->beginTransaction();
    }
    try {
        $n = 0;
        foreach (fin_coa_sample_chart() as $a) {
            fin_coa_add_account($conn, $companyId, $a[0], $a[1], $a[2] ?? null, $userId, true);
            $n++;
        }
        if ($ownTx) {
            $conn->commit();
        }
        return $n;
    } catch (Throwable $e) {
        if ($ownTx) {
            $conn->rollBack();
        }
        throw $e;
    }
}
