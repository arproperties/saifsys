<?php
/**
 * Retail / grocery POS cashier screen (Phase 5).
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
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/url_helper.php';
require_once __DIR__ . '/../../includes/inventory/inv_company_settings.php';
require_once __DIR__ . '/../../includes/inventory/inv_pos_retail_profiles.php';

require_login();
require_module_access($conn, MODULE_GROCERY);
require_grocery_pos_department($conn);
require_permission('grocery_pos.view', MODULE_GROCERY, $conn);

ensure_current_company_supports_module($conn, MODULE_GROCERY);

if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}
$appBase = get_application_web_root();

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 0;
$posRetailCompanyName = '';
if ($companyId) {
    $cn = get_company($conn, $companyId);
    $posRetailCompanyName = $cn['name'] ?? '';
}
$canPost = has_permission('grocery_pos.post', MODULE_GROCERY, $conn);

$U = !empty($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : [];
$posUserDisplay = trim((string)($U['fullname'] ?? $U['full_name'] ?? $U['username'] ?? ''));
if ($posUserDisplay === '') {
    $posUserDisplay = 'Cashier';
}

$locations = [];
if ($companyId) {
    $st = $conn->prepare("SELECT id, name FROM inv_locations WHERE company_id = ? AND is_active = 1 ORDER BY name ASC");
    $st->execute([$companyId]);
    $locations = $st->fetchAll(PDO::FETCH_ASSOC);
}

$posProfileCode = inv_pos_profile_normalize_code((string)($_GET['pos'] ?? ''));
$posProfileLabel = '';
$posProfileError = '';
$posProfileRow = null;
if ($posProfileCode !== '' && $companyId) {
    $posProfileRow = inv_pos_profile_get_by_code($conn, $companyId, $posProfileCode);
    if (!$posProfileRow) {
        $posProfileError = 'Unknown or inactive POS profile <code>' . h($posProfileCode) . '</code>. Check the link or <a href="' . h($appBase) . '/modules/inventory/locations.php#pos-profiles" style="color:inherit;text-decoration:underline">Inventory → Locations</a>.';
    } else {
        $posProfileLabel = (string)$posProfileRow['label'];
    }
}

$invCo = $companyId ? inv_get_company_settings($conn, $companyId) : [];
$defaultPosId = 0;
$defaultPosName = '';
$posLocationLocked = false;

if ($posProfileRow) {
    $defaultPosId = (int)$posProfileRow['default_location_id'];
    $defaultPosName = (string)($posProfileRow['location_name'] ?? '');
    foreach ($locations as $loc) {
        if ((int)$loc['id'] === $defaultPosId) {
            $defaultPosName = (string)$loc['name'];
            break;
        }
    }
    if ($defaultPosName !== '') {
        $posLocationLocked = true;
    } else {
        $defaultPosId = 0;
        $posProfileError = 'This POS profile’s location is missing or inactive. Update it under Inventory → Locations.';
    }
}

if (!$posLocationLocked) {
    $defaultPosId = isset($invCo['default_pos_location_id']) ? (int)$invCo['default_pos_location_id'] : 0;
    if ($defaultPosId > 0) {
        foreach ($locations as $loc) {
            if ((int)$loc['id'] === $defaultPosId) {
                $defaultPosName = (string)$loc['name'];
                $posLocationLocked = true;
                break;
            }
        }
        if ($defaultPosName === '') {
            $defaultPosId = 0;
        }
    }
}

// One active location: treat as fixed selling store (same effect as saving default in Locations admin)
if (!$posLocationLocked && count($locations) === 1) {
    $defaultPosId = (int)$locations[0]['id'];
    $defaultPosName = (string)$locations[0]['name'];
    $posLocationLocked = true;
}

$apiPos = ($appBase !== '' ? $appBase : '') . '/api/pos/index.php';
$receiptBase = ($appBase !== '' ? $appBase : '') . '/modules/grocery/pos_receipt.php';

$pageTitle = 'Retail POS';
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?> | <?= h($brand['system_name'] ?? 'Grocery') ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root {
      --pos-primary: <?= h($brand['primary_color'] ?? '#0d6efd') ?>;
      --pos-bg: #0f1419;
      --pos-panel: #1a2332;
      --pos-border: #2d3a4d;
      --pos-text: #e8eef7;
      --pos-muted: #8b9cb3;
      --pos-highlight: rgba(13, 110, 253, 0.35);
      --pos-cart-bg: #121a26;
    }
    html, body { height: 100%; }
    body.pos-body {
      margin: 0;
      background: var(--pos-bg);
      color: var(--pos-text);
      font-family: 'Segoe UI', system-ui, sans-serif;
      overflow: hidden;
    }
    .pos-app { display: flex; flex-direction: column; height: 100vh; height: 100dvh; min-height: 0; }
    .pos-header {
      flex-shrink: 0;
      background: var(--pos-panel);
      border-bottom: 1px solid var(--pos-border);
      padding: 8px 14px 10px;
    }
    .pos-header-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .pos-header a { color: var(--pos-muted); text-decoration: none; }
    .pos-header a:hover { color: #fff; }
    .pos-header-titles strong { color: #fff; font-size: 1.05rem; }
    .pos-header-titles .sub { color: var(--pos-muted); font-size: 12px; max-width: 220px; }
    .pos-header-mid { text-align: center; flex: 1 1 160px; min-width: 0; }
    .pos-header-mid .loc-name { color: #fff; font-weight: 600; font-size: 14px; }
    .pos-header-mid .loc-hint { font-size: 11px; color: var(--pos-muted); }
    .pos-header-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .pos-header-actions .user-pill {
      font-size: 13px; color: var(--pos-text); background: #0c1018; padding: 6px 12px; border-radius: 8px; border: 1px solid var(--pos-border);
    }
    .pos-btn-icon {
      min-width: 44px; min-height: 44px; padding: 0 12px; border-radius: 10px; border: 1px solid var(--pos-border);
      background: #0c1018; color: #fff; font-size: 14px; font-weight: 600; touch-action: manipulation;
    }
    .pos-btn-icon:disabled { opacity: 0.35; cursor: not-allowed; }
    .pos-barcode-wrap { margin-top: 10px; }
    .pos-barcode-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 6px; }
    .pos-barcode-head label {
      margin: 0;
      font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--pos-muted);
    }
    .pos-kb-toggle {
      font-size: 12px; font-weight: 700; padding: 6px 10px; border-radius: 999px;
      border: 1px solid var(--pos-border); background: #0c1018; color: #fff; touch-action: manipulation;
    }
    .pos-kb-toggle--on { border-color: var(--pos-primary); background: color-mix(in srgb, var(--pos-primary) 28%, #0c1018); }
    #pos-barcode {
      font-size: 1.5rem; letter-spacing: .06em; width: 100%; box-sizing: border-box;
      background: #05080d; color: #fff; border: 3px solid var(--pos-primary); border-radius: 12px;
      padding: 14px 16px;
      box-shadow: 0 0 0 1px rgba(255,255,255,.06), 0 0 24px color-mix(in srgb, var(--pos-primary) 45%, transparent);
    }
    #pos-barcode:focus {
      outline: none;
      box-shadow: 0 0 0 1px rgba(255,255,255,.1), 0 0 32px color-mix(in srgb, var(--pos-primary) 55%, transparent);
    }
    .pos-category-strip {
      display: flex; gap: 8px; overflow-x: auto; padding: 10px 0 2px; margin-top: 4px;
      flex-wrap: nowrap; -webkit-overflow-scrolling: touch; scrollbar-width: thin;
    }
    .pos-cat-tab {
      flex: 0 0 auto; padding: 10px 20px; border-radius: 999px; border: 1px solid var(--pos-border);
      background: #0c1018; color: var(--pos-muted); font-size: 14px; font-weight: 600; cursor: pointer; touch-action: manipulation;
      white-space: nowrap;
    }
    .pos-cat-tab:hover { color: #fff; border-color: #4a5f7a; }
    .pos-cat-tab--active {
      background: color-mix(in srgb, var(--pos-primary) 35%, #0c1018);
      color: #fff; border-color: var(--pos-primary);
    }
    .pos-work {
      flex: 1; min-height: 0; display: grid;
      grid-template-columns: minmax(0, 68fr) minmax(240px, 32fr);
    }
    @media (max-width: 900px) {
      .pos-work { grid-template-columns: 1fr; grid-template-rows: 1fr minmax(200px, 40vh); }
    }
    @media (max-width: 1280px) and (orientation: landscape) {
      .pos-work { grid-template-columns: minmax(0, 72fr) minmax(220px, 28fr); }
    }
    .pos-browse {
      display: flex; flex-direction: column; min-height: 0; min-width: 0;
      border-right: 1px solid var(--pos-border);
    }
    .pos-browse-toolbar {
      flex-shrink: 0; padding: 10px 12px; display: flex; flex-direction: column; gap: 8px;
      background: #0c1018; border-bottom: 1px solid var(--pos-border);
    }
    #pos-grid-search {
      width: 100%; box-sizing: border-box; font-size: 16px; padding: 12px 14px; border-radius: 10px;
      border: 1px solid var(--pos-border); background: #05080d; color: #fff;
    }
    #pos-msg { min-height: 0; }
    .pos-product-grid {
      flex: 1; min-height: 0; overflow-y: auto; overflow-x: hidden;
      display: grid; grid-template-columns: repeat(auto-fill, minmax(132px, 1fr)); gap: 12px; padding: 12px;
      align-content: start; -webkit-overflow-scrolling: touch; touch-action: pan-y;
    }
    @media (min-width: 1400px) {
      .pos-product-grid { grid-template-columns: repeat(auto-fill, minmax(148px, 1fr)); }
    }
    .pos-product-card {
      background: #0c1018; border: 1px solid var(--pos-border); border-radius: 14px; padding: 10px 8px 12px;
      display: flex; flex-direction: column; align-items: center; text-align: center; cursor: pointer; touch-action: manipulation;
      min-height: 168px; transition: transform 0.08s ease, border-color 0.15s, box-shadow 0.15s;
    }
    .pos-product-card:hover { border-color: #4a6a8a; box-shadow: 0 4px 16px rgba(0,0,0,.35); }
    .pos-product-card:active { transform: scale(0.97); }
    .pos-product-card .p-img {
      width: 100%; aspect-ratio: 1; max-height: 88px; object-fit: contain; border-radius: 8px; background: #151a22; margin-bottom: 8px;
    }
    .pos-product-card .p-img-ph {
      width: 100%; aspect-ratio: 1; max-height: 88px; border-radius: 8px; background: #1e2633; border: 1px dashed var(--pos-border); margin-bottom: 8px;
    }
    .pos-product-card .p-name {
      font-size: 13px; font-weight: 600; line-height: 1.25; color: #fff;
      display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
      min-height: 2.5em; width: 100%;
    }
    .pos-product-card .p-badge {
      font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em;
      padding: 4px 8px; border-radius: 6px; margin-bottom: 6px; background: rgba(255, 193, 7, 0.2); color: #ffd966; border: 1px solid rgba(255,193,7,.35);
    }
    .pos-product-card .p-price-row { margin-top: auto; padding-top: 8px; width: 100%; text-align: center; }
    .pos-product-card .p-price-was {
      font-size: 0.95rem; color: var(--pos-muted); text-decoration: line-through; font-variant-numeric: tabular-nums;
    }
    .pos-product-card .p-price {
      font-size: 1.25rem; font-weight: 800; color: #7dffb3; font-variant-numeric: tabular-nums;
    }
    .pos-cart-line .offer-badge {
      display: inline-block; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em;
      padding: 2px 6px; border-radius: 4px; margin-left: 6px; vertical-align: middle;
      background: rgba(255, 193, 7, 0.2); color: #ffd966; border: 1px solid rgba(255,193,7,.35);
    }
    .pos-grid-empty { grid-column: 1 / -1; text-align: center; color: var(--pos-muted); padding: 32px 16px; font-size: 15px; }
    .pos-cart-panel {
      display: flex; flex-direction: column; min-height: 0; background: var(--pos-cart-bg); padding: 10px 12px 12px; gap: 8px;
      overflow: hidden;
    }
    .pos-cart-panel .pos-label { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--pos-muted); }
    .pos-cart-lines { flex: 1; min-height: 0; overflow-y: auto; margin: 0 -4px; padding: 0 4px; -webkit-overflow-scrolling: touch; touch-action: pan-y; }
    .pos-cart-line {
      display: grid;
      grid-template-columns: 72px 1fr;
      grid-template-rows: auto auto;
      gap: 6px 12px;
      align-items: center;
      padding: 12px 10px; margin-bottom: 8px; border-radius: 12px;
      border: 1px solid transparent; background: rgba(255,255,255,.02);
    }
    .pos-cart-line--highlight {
      border-color: var(--pos-primary);
      background: var(--pos-highlight);
      animation: posLineFlash 1.5s ease-out forwards;
    }
    @keyframes posLineFlash {
      0% { background: color-mix(in srgb, var(--pos-primary) 50%, transparent); }
      100% { background: rgba(255,255,255,.02); border-color: transparent; }
    }
    .pos-cart-line .thumb { grid-row: 1 / span 2; align-self: center; }
    .pos-cart-line .thumb img {
      width: 72px; height: 72px; object-fit: contain; border-radius: 10px; background: #222; display: block;
    }
    .pos-cart-line .thumb-empty {
      width: 72px; height: 72px; border-radius: 10px; background: #252d3a; border: 1px dashed var(--pos-border);
    }
    .pos-cart-line .nm {
      grid-column: 2; font-weight: 700; font-size: 15px; line-height: 1.25; color: #fff;
      padding-right: 8px;
    }
    .pos-cart-line .row2 {
      grid-column: 2; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
    }
    .pos-cart-line .row2-right { display: flex; align-items: center; gap: 12px; margin-left: auto; }
    .pos-cart-line .qtyctl { display: flex; align-items: center; gap: 10px; }
    .pos-cart-line .qtyctl button {
      min-width: 48px; min-height: 48px; padding: 0; border-radius: 10px; font-size: 1.35rem; font-weight: 700; line-height: 1;
      border: 2px solid var(--pos-border); background: #0c1018; color: #fff; touch-action: manipulation;
    }
    .pos-cart-line .qtyctl span.qty-val { min-width: 36px; text-align: center; font-size: 1.1rem; font-weight: 700; font-variant-numeric: tabular-nums; }
    .pos-cart-line .ln-total { font-size: 1.1rem; font-weight: 800; font-variant-numeric: tabular-nums; color: #7dffb3; }
    .pos-cart-line .btn-rm {
      min-width: 48px; min-height: 48px; border-radius: 10px; border: 1px solid rgba(220,53,69,.5); background: rgba(220,53,69,.15); color: #ff8a94;
      display: inline-flex; align-items: center; justify-content: center; touch-action: manipulation;
    }
    .pos-cart-line .btn-rm:hover { background: rgba(220,53,69,.3); color: #fff; }
    .pos-totals {
      flex-shrink: 0; border-top: 2px solid var(--pos-border); padding-top: 12px; margin-top: 4px;
    }
    .pos-totals .row-line { display: flex; justify-content: space-between; padding: 6px 0; color: var(--pos-muted); font-size: 1rem; }
    .pos-totals .row-line span:last-child { font-variant-numeric: tabular-nums; font-weight: 600; color: #dce4ee; }
    .pos-totals .row-line.grand {
      margin-top: 8px; padding-top: 12px; border-top: 2px solid var(--pos-border);
      color: #fff; font-size: clamp(1.5rem, 4vw, 2.25rem); font-weight: 900; align-items: baseline;
    }
    .pos-totals .row-line.grand span:last-child { color: #fff; font-size: inherit; }
    .pos-pay { display: flex; gap: 12px; margin-top: 10px; }
    .pos-pay button {
      flex: 1; min-height: 56px; padding: 16px 12px; font-size: 1.2rem; font-weight: 800; border: 0; border-radius: 12px; touch-action: manipulation;
    }
    .pos-pay .btn-cash { background: #198754; color: #fff; }
    .pos-pay .btn-card { background: #6f42c1; color: #fff; }
    .pos-pay button:disabled { opacity: .45; }
    .pos-alert { font-size: 14px; padding: 10px 12px; border-radius: 10px; }
    .pos-alert.err { background: rgba(220,53,69,.2); color: #f8a5af; }
    .pos-alert.ok { background: rgba(25,135,84,.2); color: #75f0a6; }
    .pos-loc select { background: #0c1018; color: #fff; border: 1px solid var(--pos-border); border-radius: 8px; padding: 6px 10px; max-width: 220px; font-size: 13px; }
    .pos-loc a { color: var(--pos-muted); font-size: 12px; }
    .pos-loc a:hover { color: #fff; }

    /* Tablet tuning: keep payment buttons visible + show more products. */
    @media (max-width: 1280px) {
      .pos-header { padding: 6px 10px 8px; }
      .pos-barcode-wrap { margin-top: 8px; }
      #pos-barcode { font-size: 1.15rem; padding: 10px 12px; }
      .pos-category-strip { padding-top: 8px; }
      .pos-cat-tab { padding: 8px 14px; font-size: 13px; }
      .pos-browse-toolbar { padding: 8px 10px; gap: 6px; }
      #pos-grid-search { padding: 10px 12px; font-size: 15px; }
      .pos-product-grid { grid-template-columns: repeat(auto-fill, minmax(118px, 1fr)); gap: 10px; padding: 10px; }
      .pos-product-card { min-height: 144px; padding: 8px 6px 10px; }
      .pos-product-card .p-img,
      .pos-product-card .p-img-ph { max-height: 72px; margin-bottom: 6px; }
      .pos-product-card .p-name { font-size: 12px; min-height: 2.3em; }
      .pos-product-card .p-price { font-size: 1.05rem; }
      .pos-cart-line { padding: 8px; gap: 6px 8px; }
      .pos-cart-line .thumb img,
      .pos-cart-line .thumb-empty { width: 56px; height: 56px; }
      .pos-cart-line .nm { font-size: 13px; }
      .pos-cart-line .qtyctl button,
      .pos-cart-line .btn-rm { min-width: 38px; min-height: 38px; }
      .pos-cart-line .qtyctl span.qty-val { font-size: 1rem; }
      .pos-cart-line .ln-total { font-size: 1rem; }
      .pos-totals { padding-top: 8px; }
      .pos-totals .row-line { padding: 3px 0; font-size: .92rem; }
      .pos-totals .row-line.grand { margin-top: 4px; padding-top: 8px; font-size: clamp(1.05rem, 2.5vw, 1.45rem); }
      .pos-pay { gap: 8px; margin-top: 6px; }
      .pos-pay button { min-height: 44px; padding: 8px 10px; font-size: 1rem; border-radius: 10px; }
    }

    @media (max-width: 1024px) and (orientation: landscape) {
      .pos-work { grid-template-columns: minmax(0, 74fr) minmax(210px, 26fr); }
      .pos-product-grid { grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); }
    }

    /* On short screens, allow page-level vertical movement instead of clipping. */
    @media (max-height: 700px) {
      body.pos-body { overflow: auto; }
      .pos-app { height: auto; min-height: 100vh; min-height: 100dvh; }
    }
  </style>
</head>
<body class="pos-body">
<div class="pos-app">
  <?php if ($posProfileError !== ''): ?>
  <div style="padding:10px 16px;background:#3d1a1a;color:#ffc9c9;font-size:13px;border-bottom:1px solid #662222;">
    <?= $posProfileError ?>
  </div>
  <?php endif; ?>

  <?php if ($companyId && count($locations) === 0): ?>
  <div style="padding:10px 16px;background:#3d2a00;color:#ffecb3;font-size:13px;border-bottom:1px solid #554400;">
    No active <strong>locations</strong> for <?= h($posRetailCompanyName) ?>. Open <strong>Inventory → Locations</strong>, use <strong>Switch company</strong> in the sidebar to select this supermarket, add a store, then set <strong>Retail POS — default selling location</strong> (saved per company).
  </div>
  <?php endif; ?>

  <header class="pos-header">
    <div class="pos-header-row">
      <div class="pos-header-titles">
        <a href="<?= h($appBase) ?>/modules/grocery/pos_dashboard.php"><i class="bi bi-arrow-left"></i> Grocery</a>
        <div class="mt-1">
          <strong>Retail POS<?= $posProfileLabel !== '' ? ' — ' . h($posProfileLabel) : '' ?></strong>
          <?php if ($posRetailCompanyName !== ''): ?>
            <div class="sub"><?= h($posRetailCompanyName) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="pos-header-mid">
        <?php if ($posLocationLocked && $defaultPosId > 0): ?>
          <div class="loc-name"><?= h($defaultPosName) ?></div>
          <div class="loc-hint">Selling location<?php if ($posProfileCode !== ''): ?> · POS profile<?php endif; ?></div>
          <input type="hidden" id="pos-location" value="<?= (int)$defaultPosId ?>" aria-label="Selling location">
        <?php else: ?>
          <div class="pos-loc d-flex flex-column align-items-center gap-1">
            <select id="pos-location" aria-label="Selling location" class="form-select form-select-sm" style="max-width:240px;background:#0c1018;color:#fff;border-color:var(--pos-border);">
              <option value="">— Choose location —</option>
              <?php foreach ($locations as $idx => $loc): ?>
                <option value="<?= (int)$loc['id'] ?>"<?= $idx === 0 ? ' selected' : '' ?>><?= h($loc['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($companyId): ?>
              <a href="<?= h($appBase) ?>/modules/inventory/locations.php">Set default in Locations</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if ($posLocationLocked && $defaultPosId > 0 && $companyId): ?>
          <div class="loc-hint mt-1"><a href="<?= h($appBase) ?>/modules/inventory/locations.php<?= $posProfileCode !== '' ? '#pos-profiles' : '' ?>"><?= $posProfileCode !== '' ? 'POS profiles' : 'Change default' ?></a></div>
        <?php endif; ?>
      </div>
      <div class="pos-header-actions">
        <span class="user-pill" title="Signed in"><?= h($posUserDisplay) ?></span>
        <button type="button" class="pos-btn-icon" id="pos-hold-sale" disabled title="Coming soon">Hold</button>
        <button type="button" class="pos-btn-icon" id="pos-cancel-sale" disabled title="Coming soon">Cancel</button>
        <button type="button" class="pos-btn-icon" id="pos-clear-cart" title="Clear cart"><i class="bi bi-trash"></i></button>
      </div>
    </div>
    <div class="pos-barcode-wrap">
      <div class="pos-barcode-head">
        <label for="pos-barcode">Barcode / scan</label>
        <button type="button" class="pos-kb-toggle" id="pos-barcode-kb" title="Show on-screen keyboard for manual typing">Keyboard</button>
      </div>
      <input type="text" id="pos-barcode" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" placeholder="Scan or type barcode, then Enter" enterkeyhint="done">
    </div>
    <div class="pos-category-strip" id="pos-category-tabs" role="tablist" aria-label="Product categories"></div>
  </header>

  <div class="pos-work">
    <main class="pos-browse">
      <div class="pos-browse-toolbar">
        <label class="pos-label mb-0" for="pos-grid-search">Filter products</label>
        <input type="search" id="pos-grid-search" autocomplete="off" placeholder="Name or SKU…">
        <div id="pos-msg"></div>
      </div>
      <div class="pos-product-grid" id="pos-product-grid"></div>
    </main>
    <aside class="pos-cart-panel">
      <div class="pos-label">Cart</div>
      <div class="pos-cart-lines" id="pos-cart"></div>
      <div class="pos-totals" id="pos-totals-wrap">
        <div class="row-line"><span>Subtotal (ex VAT)</span><span id="tot-excl">0.00</span></div>
        <div class="row-line"><span>VAT</span><span id="tot-tax">0.00</span></div>
        <div class="row-line grand"><span>Total (incl. VAT)</span><span id="tot-incl">0.00</span></div>
      </div>
      <div class="pos-pay">
        <button type="button" class="btn-cash" id="pay-cash" <?= $canPost ? '' : 'disabled title="No permission"' ?>>Cash</button>
        <button type="button" class="btn-card" id="pay-card" <?= $canPost ? '' : 'disabled title="No permission"' ?>>Card</button>
      </div>
      <?php if (!$canPost): ?>
        <div class="small text-warning">Posting requires <code>grocery_pos.post</code>.</div>
      <?php endif; ?>
    </aside>
  </div>
</div>

  <script>
(function () {
  const API = <?= json_encode($apiPos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const RECEIPT = <?= json_encode($receiptBase, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const CLIENT = 'retail';
  const POS_PROFILE = <?= json_encode($posProfileCode, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

  const el = (id) => document.getElementById(id);
  const msg = el('pos-msg');
  const barcodeEl = el('pos-barcode');
  const gridSearchEl = el('pos-grid-search');
  const gridEl = el('pos-product-grid');
  const tabsEl = el('pos-category-tabs');
  const cartEl = el('pos-cart');
  const locEl = el('pos-location');
  const kbToggleEl = el('pos-barcode-kb');

  let activeSpecial = '';
  let activeCategoryId = null;
  let gridTimer = null;
  let highlightTimer = null;

  function isCoarsePointer() {
    try {
      return !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
    } catch (e) {
      return false;
    }
  }

  const touchPos = isCoarsePointer() || ('ontouchstart' in window) || ((navigator.maxTouchPoints || 0) > 0);
  let barcodeKbOn = false;

  function syncBarcodeKbUi() {
    if (!kbToggleEl) return;
    kbToggleEl.classList.toggle('pos-kb-toggle--on', barcodeKbOn);
    kbToggleEl.textContent = barcodeKbOn ? 'Keyboard: on' : 'Keyboard';
  }

  function applyBarcodeInputMode() {
    if (touchPos && !barcodeKbOn) {
      barcodeEl.setAttribute('readonly', 'readonly');
      barcodeEl.setAttribute('inputmode', 'none');
      barcodeEl.setAttribute('enterkeyhint', 'done');
    } else {
      barcodeEl.removeAttribute('readonly');
      barcodeEl.setAttribute('inputmode', 'text');
      barcodeEl.setAttribute('enterkeyhint', 'done');
    }
    syncBarcodeKbUi();
  }

  function blurBarcodeIfTouch() {
    if (!touchPos) return;
    if (!barcodeKbOn) return;
    if (document.activeElement === barcodeEl) {
      try { barcodeEl.blur(); } catch (e) {}
    }
  }

  function showMsg(text, ok) {
    msg.innerHTML = text ? '<div class="pos-alert ' + (ok ? 'ok' : 'err') + '">' + text + '</div>' : '';
  }

  function focusBarcode(opts) {
    opts = opts || {};
    const force = !!opts.force;
    if (document.activeElement === gridSearchEl) return;
    if (touchPos && !force && barcodeKbOn) return;
    requestAnimationFrame(function () {
      if (touchPos && !force && barcodeKbOn) return;
      barcodeEl.focus();
      try { barcodeEl.select(); } catch (e) {}
    });
  }

  function url(action, extra) {
    const u = new URL(API, window.location.origin);
    u.searchParams.set('action', action);
    u.searchParams.set('client', CLIENT);
    if (POS_PROFILE) {
      u.searchParams.set('pos', POS_PROFILE);
    }
    if (extra && typeof extra === 'object') {
      Object.keys(extra).forEach(function (k) {
        if (extra[k] !== undefined && extra[k] !== null && extra[k] !== '') {
          u.searchParams.set(k, String(extra[k]));
        }
      });
    }
    return u.toString();
  }

  async function apiGet(action, extra) {
    const r = await fetch(url(action, extra), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
    return r.json();
  }

  async function apiPost(action, fields) {
    const fd = new FormData();
    fd.set('action', action);
    fd.set('client', CLIENT);
    if (POS_PROFILE) {
      fd.set('pos', POS_PROFILE);
    }
    Object.keys(fields || {}).forEach(function (k) {
      fd.set(k, fields[k]);
    });
    const r = await fetch(API, { method: 'POST', credentials: 'same-origin', body: fd, headers: { 'Accept': 'application/json' } });
    return r.json();
  }

  function fmtMoney(n) {
    const x = Number(n);
    if (Number.isNaN(x)) return '0.00';
    return x.toFixed(2);
  }

  function fmtQty(q) {
    const n = Number(q);
    if (Number.isNaN(n)) return String(q);
    if (Math.abs(n - Math.round(n)) < 1e-6) return String(Math.round(n));
    return n.toFixed(3).replace(/\.?0+$/, '');
  }

  function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  async function setLocation() {
    const id = parseInt(locEl.value, 10) || 0;
    await apiPost('cart_set_location', { location_id: id });
    focusBarcode();
  }

  if (locEl && locEl.tagName === 'SELECT') {
    locEl.addEventListener('change', async function () {
      await setLocation();
      scheduleLoadGrid();
    });
  }

  function syncTabStyles() {
    tabsEl.querySelectorAll('.pos-cat-tab').forEach(function (b) {
      const sp = b.getAttribute('data-special') || '';
      const isAll = b.getAttribute('data-all') === '1';
      const rawCat = b.getAttribute('data-category-id');
      const catId = rawCat === null || rawCat === '' ? null : parseInt(rawCat, 10);
      let on = false;
      if (sp) {
        on = activeSpecial === sp;
      } else if (isAll) {
        on = activeSpecial === '' && activeCategoryId === null;
      } else if (catId !== null && !Number.isNaN(catId)) {
        on = activeSpecial === '' && activeCategoryId === catId;
      }
      b.classList.toggle('pos-cat-tab--active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
  }

  function renderCategoryTabs(categories) {
    tabsEl.innerHTML = '';
    function addTab(opts) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'pos-cat-tab';
      btn.setAttribute('role', 'tab');
      if (opts.special) {
        btn.setAttribute('data-special', opts.special);
      }
      if (opts.all) {
        btn.setAttribute('data-all', '1');
      }
      if (opts.categoryId != null) {
        btn.setAttribute('data-category-id', String(opts.categoryId));
      }
      btn.textContent = opts.label;
      btn.addEventListener('click', function () {
        if (opts.special) {
          activeSpecial = opts.special;
          activeCategoryId = null;
        } else if (opts.all) {
          activeSpecial = '';
          activeCategoryId = null;
        } else if (opts.categoryId != null) {
          activeSpecial = '';
          activeCategoryId = opts.categoryId;
        }
        syncTabStyles();
        scheduleLoadGrid();
      });
      tabsEl.appendChild(btn);
    }
    addTab({ special: 'favorites', label: 'Favorites' });
    addTab({ special: 'top_selling', label: 'Top Selling' });
    addTab({ special: 'offers', label: 'Offers' });
    addTab({ all: true, label: 'All' });
    categories.forEach(function (c) {
      addTab({ categoryId: c.id, label: c.name });
    });
    syncTabStyles();
  }

  async function loadCategories() {
    const data = await apiGet('categories', {});
    const cats = (data.ok && data.categories) ? data.categories : [];
    renderCategoryTabs(cats);
  }

  function scheduleLoadGrid() {
    clearTimeout(gridTimer);
    gridTimer = setTimeout(loadProductGrid, 220);
  }

  async function loadProductGrid() {
    const q = (gridSearchEl.value || '').trim();
    const loc = parseInt(locEl.value, 10) || 0;
    const extra = { limit: 48, q: q };
    if (loc > 0) {
      extra.location_id = loc;
    }
    if (activeSpecial === 'favorites') {
      extra.mode = 'favorites';
    } else if (activeSpecial === 'top_selling') {
      extra.mode = 'top_selling';
    } else if (activeSpecial === 'offers') {
      extra.mode = 'offers';
    } else if (activeCategoryId !== null && activeCategoryId > 0) {
      extra.category_id = activeCategoryId;
    }
    const data = await apiGet('search', extra);
    if (!data.ok) {
      gridEl.innerHTML = '<div class="pos-grid-empty">Could not load products.</div>';
      return;
    }
    const items = data.items || [];
    gridEl.innerHTML = '';
    if (!items.length) {
      let hint = 'No products match this filter.';
      if (activeSpecial === 'top_selling' && loc <= 0) {
        hint = 'Choose a selling location for top-selling items.';
      }
      gridEl.innerHTML = '<div class="pos-grid-empty">' + hint + '</div>';
      return;
    }
    const frag = document.createDocumentFragment();
    items.forEach(function (it) {
      const card = document.createElement('button');
      card.type = 'button';
      card.className = 'pos-product-card';
      card.dataset.itemId = String(it.id);
      const imgHtml = it.thumb_url
        ? '<img class="p-img" src="' + escapeHtml(it.thumb_url) + '" alt="" loading="lazy">'
        : '<div class="p-img-ph" aria-hidden="true"></div>';
      const off = it.offer_active && it.list_unit_price_incl != null && Number(it.list_unit_price_incl) > Number(it.unit_price_incl);
      const badge = it.offer_active && it.offer_label
        ? '<div class="p-badge">' + escapeHtml(it.offer_label) + '</div>'
        : '';
      const priceBlock = off
        ? '<div class="p-price-row"><div class="p-price-was">' + fmtMoney(it.list_unit_price_incl) + '</div><div class="p-price">' + fmtMoney(it.unit_price_incl) + '</div></div>'
        : '<div class="p-price-row"><div class="p-price">' + fmtMoney(it.unit_price_incl) + '</div></div>';
      card.innerHTML =
        imgHtml +
        badge +
        '<div class="p-name">' + escapeHtml(it.name) + '</div>' +
        priceBlock;
      frag.appendChild(card);
    });
    gridEl.appendChild(frag);
  }

  gridEl.addEventListener('click', function (ev) {
    const card = ev.target.closest('.pos-product-card');
    if (!card) return;
    const id = parseInt(card.dataset.itemId, 10);
    if (id) addItem(id, 1);
  });

  gridEl.addEventListener('pointerdown', function () {
    blurBarcodeIfTouch();
  }, { passive: true });

  cartEl.addEventListener('pointerdown', function () {
    blurBarcodeIfTouch();
  }, { passive: true });

  if (kbToggleEl) {
    kbToggleEl.addEventListener('click', function () {
      barcodeKbOn = !barcodeKbOn;
      applyBarcodeInputMode();
      if (barcodeKbOn) {
        focusBarcode({ force: true });
      } else {
        blurBarcodeIfTouch();
      }
    });
  }

  gridSearchEl.addEventListener('input', function () {
    scheduleLoadGrid();
  });

  async function refreshTotals(highlightItemId) {
    if (highlightTimer) {
      clearTimeout(highlightTimer);
      highlightTimer = null;
    }
    const data = await apiGet('cart_totals');
    if (!data.ok) {
      showMsg(data.error || 'Totals failed', false);
      cartEl.innerHTML = '';
      el('tot-excl').textContent = '0.00';
      el('tot-tax').textContent = '0.00';
      el('tot-incl').textContent = '0.00';
      focusBarcode();
      return;
    }
    showMsg('', true);
    el('tot-excl').textContent = fmtMoney(data.subtotal_excl);
    el('tot-tax').textContent = fmtMoney(data.tax_total);
    el('tot-incl').textContent = fmtMoney(data.grand_incl);

    const lines = data.lines || [];
    cartEl.innerHTML = '';
    lines.forEach(function (ln) {
      const row = document.createElement('div');
      row.className = 'pos-cart-line';
      row.dataset.itemId = String(ln.item_id);
      if (highlightItemId && Number(ln.item_id) === Number(highlightItemId)) {
        row.classList.add('pos-cart-line--highlight');
      }
      const thumb = ln.thumb_url
        ? '<div class="thumb"><img src="' + escapeHtml(ln.thumb_url) + '" alt=""></div>'
        : '<div class="thumb thumb-empty" aria-hidden="true"></div>';
      const ob = ln.offer_active && ln.offer_label
        ? '<span class="offer-badge">' + escapeHtml(ln.offer_label) + '</span>'
        : '';
      row.innerHTML =
        thumb +
        '<div class="nm">' + escapeHtml(ln.name) + ob + '</div>' +
        '<div class="row2">' +
          '<div class="qtyctl">' +
            '<button type="button" data-d="-1" aria-label="Decrease quantity">−</button>' +
            '<span class="qty-val">' + fmtQty(ln.qty) + '</span>' +
            '<button type="button" data-d="1" aria-label="Increase quantity">+</button>' +
          '</div>' +
          '<div class="row2-right">' +
            '<div class="ln-total">' + fmtMoney(ln.gross_incl) + '</div>' +
            '<button type="button" class="btn-rm" data-rm="1" aria-label="Remove line"><i class="bi bi-x-lg"></i></button>' +
          '</div>' +
        '</div>';
      row.querySelectorAll('button[data-d]').forEach(function (b) {
        b.addEventListener('click', async function () {
          const delta = parseInt(b.getAttribute('data-d'), 10);
          let q = Number(ln.qty) + delta;
          if (q < 0.0001) q = 0;
          await apiPost('cart_set_line_qty', { line_index: ln.line_index, qty: q });
          await refreshTotals();
          blurBarcodeIfTouch();
        });
      });
      row.querySelector('button[data-rm]').addEventListener('click', async function () {
        await apiPost('cart_remove_line', { line_index: ln.line_index });
        await refreshTotals();
        blurBarcodeIfTouch();
      });
      cartEl.appendChild(row);
    });

    if (highlightItemId) {
      const hi = cartEl.querySelector('.pos-cart-line--highlight');
      if (hi) {
        try { hi.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (e) {}
        highlightTimer = setTimeout(function () {
          cartEl.querySelectorAll('.pos-cart-line--highlight').forEach(function (n) {
            n.classList.remove('pos-cart-line--highlight');
          });
        }, 1600);
      }
    }

    focusBarcode();
  }

  async function addItem(itemId, qty) {
    const data = await apiPost('cart_add', { item_id: itemId, qty: qty || 1, uom_id: 0 });
    if (!data.ok) {
      showMsg(data.error || 'Could not add', false);
      focusBarcode();
      return false;
    }
    await refreshTotals(itemId);
    return true;
  }

  async function handleBarcodeSubmit() {
    const code = (barcodeEl.value || '').trim();
    if (!code) return;
    showMsg('', true);
    const loc = parseInt(locEl.value, 10) || 0;
    const bcExtra = { code: code };
    if (loc > 0) {
      bcExtra.location_id = loc;
    }
    const data = await apiGet('barcode', bcExtra);
    barcodeEl.value = '';
    if (!data.ok || !data.item) {
      showMsg('Product not found', false);
      focusBarcode({ force: true });
      return;
    }
    await addItem(data.item.id, 1);
  }

  barcodeEl.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter') {
      ev.preventDefault();
      handleBarcodeSubmit();
    }
  });

  el('pos-clear-cart').addEventListener('click', async function () {
    if (!confirm('Clear all lines from the cart?')) return;
    await apiPost('cart_clear', {});
    gridSearchEl.value = '';
    await refreshTotals();
    scheduleLoadGrid();
    showMsg('Cart cleared', true);
    focusBarcode();
  });

  async function pay(method) {
    const loc = parseInt(locEl.value, 10) || 0;
    const tot = parseFloat(el('tot-incl').textContent);
    if (!tot || tot <= 0) {
      showMsg('Cart is empty', false);
      focusBarcode();
      return;
    }
    const data = await apiPost('sale_post', {
      location_id: loc,
      payment_method: method
    });
    if (!data.ok) {
      showMsg(data.error || 'Payment failed', false);
      focusBarcode();
      return;
    }
    const sid = data.pos_sale_id;
    showMsg('Sale completed', true);
    await refreshTotals();
    if (sid) {
      window.open(RECEIPT + '?id=' + encodeURIComponent(sid), '_blank', 'noopener,width=420,height=720');
    }
    focusBarcode();
  }

  el('pay-cash').addEventListener('click', function () { pay('cash'); });
  el('pay-card').addEventListener('click', function () { pay('card'); });

  window.addEventListener('load', async function () {
    applyBarcodeInputMode();
    await setLocation();
    await loadCategories();
    await loadProductGrid();
    await refreshTotals();
    focusBarcode();
  });
})();
  </script>
</body>
</html>
