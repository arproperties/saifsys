<?php
/**
 * Finance (new chart) page shell: left menu, page title, company switcher.
 * Needs includes/fin_context.php first. The page sets $pageTitle and $finActive
 * ('coa', 'tb' or a voucher type), and optionally $pageSubtitle and $pageStyles.
 */
require_once __DIR__ . '/fin_voucher_helper.php';
$finNav = [
    'coa' => ['Chart of Accounts', 'fin_chart_of_accounts.php', 'bi-journal-bookmark-fill'],
    'tb'  => ['Trial Balance', 'fin_trial_balance.php', 'bi-list-columns-reverse'],
    'bs'  => ['Balance Sheet', 'fin_balance_sheet.php', 'bi-clipboard-data'],
    'pl'  => ['Profit & Loss', 'fin_profit_loss.php', 'bi-graph-up-arrow'],
    'gl'  => ['General Ledger', 'fin_general_ledger.php', 'bi-book'],
    'pbal' => ['Partner Balances', 'fin_partner_balances.php', 'bi-people'],
    'pled' => ['Partner Ledger', 'fin_partner_ledger.php', 'bi-person-lines-fill'],
];
$finVoucherNav = [];
// This file shares the page's variables, so everything here is named $fin...:
// a plain $vt or $key would overwrite the page's own.
foreach (FIN_VOUCHER_TYPES as $finNavKey => $finNavType) {
    $finVoucherNav[$finNavKey] = [$finNavType['plural'], $finNavType['list'], $finNavType['icon']];
}
// Where the company switcher comes back to. A page showing one company's record
// (a voucher, an account) sets $finReturnPath to a page that exists for any company.
$finSelfPath = $finReturnPath ?? strtok($_SERVER['REQUEST_URI'] ?? '', '?');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle) ?> | <?= h($brand['system_name']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<!-- Searchable dropdowns: the same Select2 build the other modules use. -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<?php require dirname(__DIR__) . '/operation/includes/cleaning_ui_styles.php'; ?>
<style>
  @media (max-width: 767.98px){ .sidebar{ display:none; } }
  @media print { .sidebar, .fin-noprint { display:none !important; } }
  .select2-container { width: 100% !important; }
  .fin-company-switch { min-width: 280px; }
  <?= $pageStyles ?? '' ?>
</style>
</head>
<body<?= $brand['dark_mode_enabled'] ? ' class="dark-mode"' : '' ?>>
<div class="d-flex">
  <aside id="sb" class="sidebar d-flex flex-column p-3">
    <div class="d-flex align-items-center gap-2 mb-2">
      <?php if (!empty($brand['logo_path']) && file_exists(dirname(__DIR__) . '/' . $brand['logo_path'])): ?>
        <img src="<?= h($appBase . '/' . $brand['logo_path']) ?>" alt="<?= h($brand['system_name']) ?>" style="width:40px;height:40px;object-fit:contain;border-radius:8px;">
      <?php endif; ?>
      <span class="brand ms-1"><?= h($brand['system_name']) ?></span>
    </div>
    <div class="collapse-btn" id="sbToggle" title="Collapse/Expand"><i class="bi bi-chevron-left"></i></div>

    <div class="nav-sect mt-3">Finance</div>
    <?php foreach ($finNav as $finNavKey => [$finNavLabel, $finNavFile, $finNavIcon]): ?>
    <a href="<?= h($appBase) ?>/accounts/<?= h($finNavFile) ?>" class="slink <?= $finActive === $finNavKey ? 'active' : '' ?>"><span class="sicon"><i class="bi <?= h($finNavIcon) ?>"></i></span><span class="slabel"><?= h($finNavLabel) ?></span></a>
    <?php endforeach; ?>

    <div class="nav-sect">Vouchers</div>
    <?php foreach ($finVoucherNav as $finNavKey => [$finNavLabel, $finNavFile, $finNavIcon]): ?>
    <a href="<?= h($appBase) ?>/accounts/<?= h($finNavFile) ?>" class="slink <?= $finActive === $finNavKey ? 'active' : '' ?>"><span class="sicon"><i class="bi <?= h($finNavIcon) ?>"></i></span><span class="slabel"><?= h($finNavLabel) ?></span></a>
    <?php endforeach; ?>
    <a href="<?= h($appBase) ?>/accounts/fin_lease_posting.php" class="slink <?= $finActive === 'lease' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-building-check"></i></span><span class="slabel">Lease Posting</span></a>
    <a href="<?= h($appBase) ?>/accounts/fin_voucher_history.php" class="slink <?= $finActive === 'history' ? 'active' : '' ?>"><span class="sicon"><i class="bi bi-clock-history"></i></span><span class="slabel">Voucher History</span></a>

    <div class="nav-sect">Other</div>
    <a href="<?= h($appBase) ?>/select-module.php" class="slink"><span class="sicon"><i class="bi bi-grid"></i></span><span class="slabel">Switch Module</span></a>
  </aside>

  <div class="flex-grow-1" style="min-width:0">
<div class="container-fluid px-3 px-lg-4 py-4">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <div class="me-auto">
      <h3 class="mb-0"><?= h($pageTitle) ?></h3>
      <div class="text-muted small"><?= $company ? h($company['name']) : 'No company selected' ?><?= !empty($pageSubtitle) ? ' · ' . h($pageSubtitle) : '' ?></div>
    </div>
    <?php if (count($userCompanies) > 1): ?>
    <form method="post" action="<?= h($appBase) ?>/switch_company.php" class="fin-noprint fin-company-switch">
      <?php csrf_field(); ?>
      <input type="hidden" name="return" value="<?= h($finSelfPath) ?>">
      <select name="company_id" id="finCompanySwitch" class="form-select form-select-sm" aria-label="Company">
        <?php foreach ($userCompanies as $finNavCompany): ?>
          <option value="<?= (int)$finNavCompany['id'] ?>" <?= (int)$finNavCompany['id'] === $companyId ? 'selected' : '' ?>><?= h($finNavCompany['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
  </div>
