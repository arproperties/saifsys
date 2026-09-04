<?php
/**
 * Construction Theme Settings — Owner only, company-scoped appearance.
 * Presentation only; does not affect accounting or global brand_* settings.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/includes/construction_theme_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
require_role(['Owner'], $conn);

$brand = getBrandSettings($conn);
$cid = (int)(current_company_id($conn) ?: 0);
if ($cid <= 0) {
    http_response_code(400);
    die('Company context is required to manage Construction theme settings.');
}

$msg = '';
$err = '';
$warn = [];
$swalSuccess = '';
$defaults = co_theme_defaults();
$theme = co_theme_get($conn, $cid);
$tableReady = erp_theme_table_ready($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? 'save';
    if ($action === 'reset') {
        if (!$tableReady) {
            $err = 'Run migrations/erp_module_themes.sql before resetting.';
        } else {
            co_theme_reset($conn, $cid);
            $theme = $defaults;
            $msg = 'Theme reset to Cloud Blue (Construction default) for this company.';
            $swalSuccess = $msg;
        }
    } elseif ($action === 'apply_template') {
        $tplId = trim((string)($_POST['template_id'] ?? ''));
        $tplTheme = co_theme_template_by_id($tplId);
        $tplName = '';
        foreach (co_theme_templates() as $t) {
            if (($t['id'] ?? '') === $tplId) {
                $tplName = (string)$t['name'];
                break;
            }
        }
        if ($tplTheme === null) {
            $err = 'Unknown theme template.';
        } elseif (!$tableReady) {
            $err = 'Run migrations/erp_module_themes.sql before saving a template.';
        } else {
            $result = co_theme_save($conn, $cid, $tplTheme, current_user_id() ?: null);
            $theme = $result['theme'];
            if (!$result['ok']) {
                $err = implode(' ', $result['errors']);
            } else {
                $msg = ($tplName !== '' ? $tplName : 'Theme') . ' applied and saved for this company.';
                $swalSuccess = $msg;
                if (!empty($result['errors'])) {
                    $warn = $result['errors'];
                }
            }
        }
    } else {
        $payload = [];
        foreach (array_keys($defaults) as $key) {
            if (isset($_POST[$key])) {
                $payload[$key] = trim((string)$_POST[$key]);
            }
        }
        $result = co_theme_save($conn, $cid, $payload, current_user_id() ?: null);
        $theme = $result['theme'];
        if (!$result['ok']) {
            $err = implode(' ', $result['errors']);
        } else {
            $msg = 'Theme saved for this company.';
            $swalSuccess = $msg;
            if (!empty($result['errors'])) {
                $warn = $result['errors'];
            }
        }
    }
}

$pageTitle = 'Construction Theme Settings';
$pageHead = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.min.css">';
require_once __DIR__ . '/includes/construction_layout_header.php';

$colorFields = [
    'primary' => 'Primary',
    'primary_hover' => 'Primary hover',
    'secondary' => 'Secondary',
    'accent' => 'Accent',
    'button_primary' => 'Primary button',
    'sidebar_bg' => 'Sidebar background',
    'sidebar_text' => 'Sidebar text',
    'sidebar_active' => 'Sidebar active',
    'page_bg' => 'Page background',
    'card_bg' => 'Card background',
    'heading' => 'Heading',
    'body_text' => 'Body text',
    'muted_text' => 'Muted text',
    'border' => 'Border',
    'success' => 'Success',
    'warning' => 'Warning',
    'danger' => 'Danger',
    'info' => 'Info',
    'link' => 'Link',
    'chart_1' => 'Chart 1',
    'chart_2' => 'Chart 2',
    'chart_3' => 'Chart 3',
    'chart_4' => 'Chart 4',
    'chart_5' => 'Chart 5',
];
$lightTemplates = co_theme_templates_by_mode('light');
$darkTemplates = co_theme_templates_by_mode('dark');
$allTemplates = co_theme_templates();
$templatesJson = [];
$templateNames = [];
foreach ($allTemplates as $tpl) {
    $templatesJson[$tpl['id']] = $tpl['theme'];
    $templateNames[$tpl['id']] = $tpl['name'];
}

$btnText = erp_theme_contrasting_text($theme['button_primary'] ?? '#2563eb');
?>
<?= co_ui_page_header(
    'Construction Theme Settings',
    'Company-scoped visual identity for the Construction module. Does not change ERP global branding or accounting.',
    [
        ['label' => 'Construction', 'href' => 'index.php'],
        ['label' => 'Theme Settings'],
    ],
    '<a href="index.php" class="btn btn-outline-secondary btn-sm"><i data-lucide="arrow-left" style="width:14px;height:14px"></i> Dashboard</a>'
) ?>

<?php if (!$tableReady): ?>
<div class="alert alert-warning">Run <code>migrations/erp_module_themes.sql</code> so theme preferences can be stored per company. Preview and defaults still work.</div>
<?php endif; ?>
<?php if ($msg && !$swalSuccess): ?><?= co_ui_alert(h($msg), 'success') ?><?php endif; ?>
<?php if ($err): ?><?= co_ui_alert(h($err), 'danger') ?><?php endif; ?>
<?php foreach ($warn as $w): ?><?= co_ui_alert(h($w), 'warning') ?><?php endforeach; ?>

<div id="previewModeBanner" class="alert alert-warning py-2 mb-3 d-none" role="status">
    <i data-lucide="eye" style="width:16px;height:16px" class="me-1"></i>
    <strong>Preview mode</strong> — changes are not saved. Use Apply &amp; Save or Save theme to persist.
</div>

<form method="post" id="themeForm" class="row g-3">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" id="themeAction" value="save">
    <input type="hidden" name="template_id" id="templateId" value="">

    <?php
    $renderTemplateCards = static function (array $templates, bool $canSave): void {
        foreach ($templates as $tpl):
            $mode = ($tpl['mode'] ?? 'light') === 'dark' ? 'dark' : 'light';
            $recommended = !empty($tpl['recommended']);
            ?>
            <div class="col-md-6 col-xl-4">
                <div class="co-theme-tpl border rounded p-3 h-100" data-template-id="<?= h($tpl['id']) ?>" style="border-color:var(--co-border)!important;background:var(--co-bg-elevated)">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                        <div class="d-flex align-items-center gap-2">
                            <?php foreach ($tpl['swatches'] as $sw): ?>
                            <span class="rounded-circle border" style="width:18px;height:18px;background:<?= h($sw) ?>;border-color:rgba(0,0,0,.15)!important"></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="d-flex flex-wrap gap-1 justify-content-end">
                            <?php if ($recommended): ?>
                            <span class="badge bg-primary">Recommended</span>
                            <?php endif; ?>
                            <span class="badge <?= $mode === 'light' ? 'bg-info' : 'bg-secondary' ?>"><?= $mode === 'light' ? 'Light' : 'Dark' ?></span>
                        </div>
                    </div>
                    <div class="fw-semibold mb-1"><?= h($tpl['name']) ?></div>
                    <div class="small text-muted mb-3"><?= h($tpl['description']) ?></div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-outline-primary btn-tpl-preview" data-template-id="<?= h($tpl['id']) ?>">Preview</button>
                        <button type="button" class="btn btn-sm btn-primary btn-tpl-save" data-template-id="<?= h($tpl['id']) ?>" <?= $canSave ? '' : 'disabled' ?>>Apply &amp; Save</button>
                    </div>
                </div>
            </div>
        <?php
        endforeach;
    };
    ?>

    <div class="col-12">
        <div class="card card-round mb-0">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <strong>Recommended Light Themes</strong>
                <span class="text-muted small">Bright, professional presets for long working sessions.</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <?php $renderTemplateCards($lightTemplates, $tableReady); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card card-round mb-0">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <strong>Dark Themes</strong>
                <span class="text-muted small">Kept available for teams that prefer high-contrast dark surfaces.</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <?php $renderTemplateCards($darkTemplates, $tableReady); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card card-round mb-3">
            <div class="card-header"><strong>Colors</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <?php foreach ($colorFields as $key => $label):
                        $val = $theme[$key] ?? $defaults[$key];
                    ?>
                    <div class="col-md-6 col-xl-4">
                        <label class="form-label small mb-1" for="c_<?= h($key) ?>"><?= h($label) ?></label>
                        <div class="input-group input-group-sm">
                            <input type="color" class="form-control form-control-color theme-color" id="c_<?= h($key) ?>" value="<?= h($val) ?>" data-hex="hex_<?= h($key) ?>" title="<?= h($label) ?>">
                            <input type="text" name="<?= h($key) ?>" id="hex_<?= h($key) ?>" class="form-control theme-hex" value="<?= h($val) ?>" pattern="^#?[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$" maxlength="7" data-color="c_<?= h($key) ?>">
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="card card-round mb-3">
            <div class="card-header"><strong>Shape</strong></div>
            <div class="card-body row g-3">
                <div class="col-md-6">
                    <label class="form-label">Border radius</label>
                    <select name="border_radius" class="form-select" id="borderRadius">
                        <?php foreach (['8' => 'Compact (8px)', '10' => 'Soft (10px)', '12' => 'Default (12px)', '16' => 'Rounded (16px)'] as $rv => $rl): ?>
                            <option value="<?= h($rv) ?>" <?= ($theme['border_radius'] ?? '10') === $rv ? 'selected' : '' ?>><?= h($rl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Shadow intensity</label>
                    <select name="shadow_intensity" class="form-select" id="shadowIntensity">
                        <?php foreach (['low' => 'Soft', 'medium' => 'Medium', 'high' => 'High'] as $sv => $sl): ?>
                            <option value="<?= h($sv) ?>" <?= ($theme['shadow_intensity'] ?? 'low') === $sv ? 'selected' : '' ?>><?= h($sl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary" <?= $tableReady ? '' : 'disabled' ?>>Save theme</button>
            <a href="theme_settings.php" class="btn btn-outline-secondary">Discard</a>
            <button type="button" class="btn btn-outline-warning" id="btnReset" <?= $tableReady ? '' : 'disabled' ?>>Reset to default</button>
        </div>
        <p class="form-text mt-2">Changes apply to this company only (<code>company_id=<?= (int)$cid ?></code>). Reset restores <strong>Cloud Blue</strong>. Other modules are not affected.</p>
    </div>

    <div class="col-lg-5">
        <div class="card card-round sticky-top" style="top:1rem" id="themePreview">
            <div class="card-header"><strong>Live preview</strong></div>
            <div class="card-body">
                <div class="mb-3 p-3 rounded" id="pvSidebar" style="background:<?= h($theme['sidebar_bg']) ?>;color:<?= h($theme['sidebar_text']) ?>;border:1px solid <?= h($theme['border']) ?>">
                    <div class="small mb-2 opacity-75">SIDEBAR</div>
                    <div class="py-1 px-2 rounded mb-1">Dashboard</div>
                    <div class="py-1 px-2 rounded" id="pvSidebarActive" style="color:<?= h($theme['heading']) ?>;background:<?= h($theme['sidebar_active']) ?>;border-left:3px solid <?= h($theme['primary']) ?>">Projects (active)</div>
                </div>
                <div class="mb-3 p-3 rounded" id="pvCard" style="background:<?= h($theme['card_bg']) ?>;color:<?= h($theme['body_text']) ?>;border:1px solid <?= h($theme['border']) ?>;border-radius:<?= (int)$theme['border_radius'] ?>px">
                    <h6 id="pvHeading" style="color:<?= h($theme['heading']) ?>">Sample card</h6>
                    <p class="small mb-2" id="pvMuted" style="color:<?= h($theme['muted_text']) ?>">Muted supporting text for forms and tables.</p>
                    <button type="button" class="btn btn-sm" id="pvBtn" style="background:<?= h($theme['button_primary']) ?>;color:<?= h($btnText) ?>;border:0">Primary action</button>
                    <a href="#" class="ms-2 small" id="pvLink" style="color:<?= h($theme['link']) ?>">Link</a>
                </div>
                <div class="d-flex flex-wrap gap-1 mb-3">
                    <span class="badge" id="pvSuccess" style="background:<?= h($theme['success']) ?>;color:#fff">Success</span>
                    <span class="badge" id="pvWarn" style="background:<?= h($theme['warning']) ?>;color:#0f172a">Warning</span>
                    <span class="badge" id="pvDanger" style="background:<?= h($theme['danger']) ?>;color:#fff">Danger</span>
                    <span class="badge" id="pvInfo" style="background:<?= h($theme['info']) ?>;color:#fff">Info</span>
                </div>
                <div class="table-responsive border rounded" id="pvTableWrap" style="background:<?= h($theme['card_bg']) ?>;border-color:<?= h($theme['border']) ?>!important">
                    <table class="table table-sm mb-0" id="pvTable" style="color:<?= h($theme['body_text']) ?>">
                        <thead><tr><th>Item</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                            <tr><td>Sample row</td><td class="text-end">1,250.00</td></tr>
                            <tr><td>Another row</td><td class="text-end">480.00</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex flex-wrap gap-1 mt-3" id="pvCharts">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                    <span class="rounded" style="width:28px;height:10px;background:<?= h($theme['chart_' . $i]) ?>" title="Chart <?= $i ?>"></span>
                    <?php endfor; ?>
                </div>
                <div class="alert alert-info py-2 small mt-3 mb-0">Preview updates as you edit. Apply &amp; Save or Save theme to apply across Construction for this company.</div>
            </div>
        </div>
    </div>
</form>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>
<script>
(function () {
  var TEMPLATES = <?= json_encode($templatesJson, JSON_UNESCAPED_SLASHES) ?>;
  var TEMPLATE_NAMES = <?= json_encode($templateNames, JSON_UNESCAPED_SLASHES) ?>;
  var previewMode = false;
  var savedSnapshot = null;

  function luminance(hex) {
    hex = (hex || '').replace('#', '');
    if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
    if (hex.length !== 6) return 0;
    var r = parseInt(hex.slice(0,2), 16) / 255;
    var g = parseInt(hex.slice(2,4), 16) / 255;
    var b = parseInt(hex.slice(4,6), 16) / 255;
    var c = function (x) { return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); };
    return 0.2126 * c(r) + 0.7152 * c(g) + 0.0722 * c(b);
  }
  function contrastRatio(a, b) {
    var l1 = luminance(a), l2 = luminance(b);
    var hi = Math.max(l1, l2), lo = Math.min(l1, l2);
    return (hi + 0.05) / (lo + 0.05);
  }
  function onColor(bg) {
    return contrastRatio('#0f172a', bg) >= contrastRatio('#ffffff', bg) ? '#0f172a' : '#ffffff';
  }
  function setPreviewMode(on) {
    previewMode = !!on;
    var banner = document.getElementById('previewModeBanner');
    if (banner) banner.classList.toggle('d-none', !previewMode);
    if (window.lucide) lucide.createIcons();
  }
  function captureSnapshot() {
    var data = {};
    document.querySelectorAll('#themeForm [name]').forEach(function (el) {
      if (el.name === 'action' || el.name === 'template_id' || el.name === 'csrf_token' || !el.name) return;
      data[el.name] = el.value;
    });
    return data;
  }
  function applyThemeObject(theme, asPreview) {
    if (!theme) return;
    Object.keys(theme).forEach(function (key) {
      var hexInput = document.getElementById('hex_' + key);
      var colorInput = document.getElementById('c_' + key);
      var select = document.querySelector('[name="'+key+'"]');
      var val = theme[key];
      if (hexInput) {
        hexInput.value = val;
        if (colorInput) colorInput.value = val;
      } else if (select && (select.tagName === 'SELECT' || select.type === 'text')) {
        select.value = val;
      }
    });
    preview();
    if (asPreview) setPreviewMode(true);
  }

  function syncFromColor(el) {
    var hex = document.getElementById(el.getAttribute('data-hex'));
    if (hex) hex.value = el.value;
    setPreviewMode(true);
    preview();
  }
  function syncFromHex(el) {
    var v = el.value.trim();
    if (v && v[0] !== '#') v = '#' + v;
    if (/^#[0-9A-Fa-f]{6}$/.test(v) || /^#[0-9A-Fa-f]{3}$/.test(v)) {
      var c = document.getElementById(el.getAttribute('data-color'));
      if (c) {
        if (v.length === 4) {
          v = '#' + v[1]+v[1]+v[2]+v[2]+v[3]+v[3];
        }
        c.value = v.toLowerCase();
        el.value = v.toLowerCase();
      }
    }
    setPreviewMode(true);
    preview();
  }
  function val(name) {
    var el = document.querySelector('[name="'+name+'"]');
    return el ? el.value : '';
  }
  function preview() {
    var sb = document.getElementById('pvSidebar');
    if (sb) {
      sb.style.background = val('sidebar_bg');
      sb.style.color = val('sidebar_text');
      sb.style.borderColor = val('border');
    }
    var sa = document.getElementById('pvSidebarActive');
    if (sa) {
      sa.style.background = val('sidebar_active');
      sa.style.color = val('heading');
      sa.style.borderLeft = '3px solid ' + val('primary');
    }
    var card = document.getElementById('pvCard');
    if (card) {
      card.style.background = val('card_bg');
      card.style.color = val('body_text');
      card.style.borderColor = val('border');
      card.style.borderRadius = (val('border_radius') || '10') + 'px';
    }
    var h = document.getElementById('pvHeading'); if (h) h.style.color = val('heading');
    var m = document.getElementById('pvMuted'); if (m) m.style.color = val('muted_text');
    var b = document.getElementById('pvBtn');
    if (b) {
      b.style.background = val('button_primary');
      b.style.color = onColor(val('button_primary'));
    }
    var l = document.getElementById('pvLink'); if (l) l.style.color = val('link');
    var statusMap = { success: 'pvSuccess', warning: 'pvWarn', danger: 'pvDanger', info: 'pvInfo' };
    Object.keys(statusMap).forEach(function (k) {
      var el = document.getElementById(statusMap[k]);
      if (!el) return;
      el.style.background = val(k);
      el.style.color = k === 'warning' ? '#0f172a' : '#ffffff';
    });
    var wrap = document.getElementById('pvTableWrap');
    if (wrap) {
      wrap.style.background = val('card_bg');
      wrap.style.borderColor = val('border');
    }
    var table = document.getElementById('pvTable');
    if (table) table.style.color = val('body_text');
    var charts = document.getElementById('pvCharts');
    if (charts) {
      charts.innerHTML = '';
      for (var i = 1; i <= 5; i++) {
        var span = document.createElement('span');
        span.className = 'rounded';
        span.style.width = '28px';
        span.style.height = '10px';
        span.style.background = val('chart_' + i);
        span.title = 'Chart ' + i;
        charts.appendChild(span);
      }
    }
  }
  document.querySelectorAll('.theme-color').forEach(function (el) {
    el.addEventListener('input', function () { syncFromColor(el); });
  });
  document.querySelectorAll('.theme-hex').forEach(function (el) {
    el.addEventListener('change', function () { syncFromHex(el); });
    el.addEventListener('input', function () { syncFromHex(el); });
  });
  var br = document.getElementById('borderRadius');
  if (br) br.addEventListener('change', function () { setPreviewMode(true); preview(); });
  var sh = document.getElementById('shadowIntensity');
  if (sh) sh.addEventListener('change', function () { setPreviewMode(true); preview(); });

  function confirmDialog(opts) {
    if (window.Swal) {
      return Swal.fire(opts);
    }
    return Promise.resolve({ isConfirmed: window.confirm(opts.text || opts.title || 'Continue?') });
  }

  var btn = document.getElementById('btnReset');
  if (btn) btn.addEventListener('click', function () {
    confirmDialog({
      title: 'Reset theme?',
      text: 'Reset Construction theme for this company to Cloud Blue (recommended default)?',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Reset to Cloud Blue',
      cancelButtonText: 'Cancel'
    }).then(function (result) {
      if (!result.isConfirmed) return;
      document.getElementById('themeAction').value = 'reset';
      document.getElementById('templateId').value = '';
      document.getElementById('themeForm').requestSubmit();
    });
  });
  document.querySelectorAll('.btn-tpl-preview').forEach(function (btnEl) {
    btnEl.addEventListener('click', function () {
      var id = btnEl.getAttribute('data-template-id');
      applyThemeObject(TEMPLATES[id], true);
    });
  });
  document.querySelectorAll('.btn-tpl-save').forEach(function (btnEl) {
    btnEl.addEventListener('click', function () {
      var id = btnEl.getAttribute('data-template-id');
      if (!TEMPLATES[id]) return;
      var label = TEMPLATE_NAMES[id] || id;
      confirmDialog({
        title: 'Apply theme?',
        text: 'Apply this theme to the Construction module for the current company?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Apply & Save',
        cancelButtonText: 'Cancel',
        footer: label
      }).then(function (result) {
        if (!result.isConfirmed) return;
        applyThemeObject(TEMPLATES[id], false);
        setPreviewMode(false);
        document.getElementById('themeAction').value = 'apply_template';
        document.getElementById('templateId').value = id;
        document.getElementById('themeForm').requestSubmit();
      });
    });
  });

  savedSnapshot = captureSnapshot();
  preview();

  <?php if ($swalSuccess): ?>
  if (window.Swal) {
    Swal.fire({
      icon: 'success',
      title: 'Theme applied',
      text: <?= json_encode($swalSuccess) ?>,
      timer: 2200,
      showConfirmButton: false
    });
  }
  <?php endif; ?>
})();
</script>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
