<?php
/**
 * Real Estate - Compliance pages: shared look (styles + page header + tab bar).
 * Used by compliance.php, compliance_document_types.php, compliance_send_alerts.php.
 */

if (!function_exists('re_cx_styles')) {
    function re_cx_styles(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        ?>
<style>
.cx { --cx-ink:#101828; --cx-muted:#667085; --cx-line:#eaecf0; --cx-soft:#f9fafb; --cx-card:#ffffff;
      --cx-red:#d92d20; --cx-red-bg:#fef3f2; --cx-amber:#b54708; --cx-amber-bg:#fffaeb;
      --cx-green:#067647; --cx-green-bg:#ecfdf3; --cx-blue:#1849a9; --cx-blue-bg:#eff8ff;
      --cx-grey-bg:#f2f4f7; color:var(--cx-ink); }
body.dark-mode .cx { --cx-ink:#f2f4f7; --cx-muted:#98a2b3; --cx-line:#2b3245; --cx-soft:#1b2132; --cx-card:#151b2b;
      --cx-red:#f97066; --cx-red-bg:#3a1d1d; --cx-amber:#fdb022; --cx-amber-bg:#3a2a12; --cx-green:#47cd89;
      --cx-green-bg:#123524; --cx-blue:#84caff; --cx-blue-bg:#14284a; --cx-grey-bg:#222a3d; }

.cx-head { display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end; justify-content:space-between; margin-bottom:18px; }
.cx-eyebrow { font-size:.75rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--cx-muted); }
.cx-title { font-size:1.6rem; font-weight:700; margin:2px 0 4px; color:var(--cx-ink); line-height:1.2; }
.cx-sub { color:var(--cx-muted); font-size:.95rem; margin:0; max-width:720px; }
.cx-actions { display:flex; flex-wrap:wrap; gap:8px; }

.cx-btn { display:inline-flex; align-items:center; gap:8px; border-radius:8px; padding:9px 14px; font-weight:600; font-size:.9rem;
          border:1px solid var(--cx-line); background:var(--cx-card); color:var(--cx-ink); text-decoration:none; cursor:pointer;
          transition:background .15s, border-color .15s, box-shadow .15s; }
.cx-btn:hover { background:var(--cx-soft); color:var(--cx-ink); }
.cx-btn-primary { background:var(--primary); border-color:var(--primary); color:#fff; }
.cx-btn-primary:hover { background:var(--primary-dark, var(--primary)); color:#fff; box-shadow:0 4px 12px rgba(16,24,40,.15); }
.cx-btn[disabled] { opacity:.45; cursor:not-allowed; box-shadow:none; }
.cx-btn-sm { padding:5px 10px; font-size:.8rem; border-radius:6px; }
.cx-btn:focus-visible { outline:2px solid var(--primary); outline-offset:2px; }

.cx-tabs { display:flex; gap:4px; border-bottom:1px solid var(--cx-line); margin-bottom:22px; overflow-x:auto; }
.cx-tab { padding:10px 14px; font-weight:600; font-size:.9rem; color:var(--cx-muted); text-decoration:none; white-space:nowrap;
          border-bottom:2px solid transparent; margin-bottom:-1px; display:inline-flex; gap:8px; align-items:center; }
.cx-tab:hover { color:var(--cx-ink); }
.cx-tab.is-active { color:var(--primary); border-bottom-color:var(--primary); }
body.dark-mode .cx-tab.is-active { color:var(--accent); border-bottom-color:var(--accent); }

.cx-kpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(min(210px, 100%), 1fr)); gap:14px; margin-bottom:22px; }
.cx-kpi { background:var(--cx-card); border:1px solid var(--cx-line); border-radius:12px; padding:16px 18px;
          box-shadow:0 1px 2px rgba(16,24,40,.05); display:flex; gap:14px; align-items:flex-start; }
.cx-kpi-icon { width:40px; height:40px; border-radius:10px; display:grid; place-items:center; font-size:1.15rem; flex:none; }
.cx-kpi-label { font-size:.85rem; color:var(--cx-muted); font-weight:600; }
.cx-kpi-value { font-size:1.9rem; font-weight:700; line-height:1.1; margin-top:2px; font-variant-numeric:tabular-nums; }
.cx-kpi-note { font-size:.78rem; color:var(--cx-muted); margin-top:2px; }
.t-red  { color:var(--cx-red); }   .bg-red  { background:var(--cx-red-bg);  color:var(--cx-red); }
.t-amber{ color:var(--cx-amber); } .bg-amber{ background:var(--cx-amber-bg);color:var(--cx-amber); }
.t-green{ color:var(--cx-green); } .bg-green{ background:var(--cx-green-bg);color:var(--cx-green); }
.t-blue { color:var(--cx-blue); }  .bg-blue { background:var(--cx-blue-bg); color:var(--cx-blue); }
.bg-grey{ background:var(--cx-grey-bg); color:var(--cx-muted); }

.cx-card { background:var(--cx-card); border:1px solid var(--cx-line); border-radius:12px; box-shadow:0 1px 2px rgba(16,24,40,.05); margin-bottom:20px; overflow:hidden; }
.cx-card-head { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid var(--cx-line); }
.cx-card-title { font-size:1rem; font-weight:700; margin:0; display:flex; gap:8px; align-items:center; }
.cx-card-sub { font-size:.82rem; color:var(--cx-muted); margin:2px 0 0; }
.cx-card-body { padding:16px 18px; }

.cx-table { width:100%; border-collapse:collapse; font-size:.9rem; }
.cx-table th { font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; color:var(--cx-muted); font-weight:700;
               background:var(--cx-soft); padding:10px 14px; border-bottom:1px solid var(--cx-line); text-align:left; white-space:nowrap; }
.cx-table td { padding:12px 14px; border-bottom:1px solid var(--cx-line); vertical-align:middle; }
.cx-table tr:last-child td { border-bottom:0; }
.cx-table tbody tr:hover td { background:var(--cx-soft); }
.cx-table .num { text-align:right; font-variant-numeric:tabular-nums; }
.cx-strong { font-weight:600; }
.cx-table td:first-child { white-space:nowrap; }
.cx-dim { color:var(--cx-muted); font-size:.8rem; }
.cx-code { font-family:ui-monospace, SFMono-Regular, Menlo, monospace; font-size:.78rem; background:var(--cx-grey-bg); color:var(--cx-muted);
           padding:2px 6px; border-radius:4px; }

.cx-pill { display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:999px; font-size:.78rem; font-weight:600; white-space:nowrap; }
.cx-pill::before { content:""; width:6px; height:6px; border-radius:50%; background:currentColor; }
.cx-pill.no-dot::before { display:none; }

.cx-chips { display:flex; flex-wrap:wrap; gap:6px; }
.cx-chip { border:1px solid var(--cx-line); background:var(--cx-card); color:var(--cx-muted); border-radius:999px; padding:5px 12px;
           font-size:.82rem; font-weight:600; cursor:pointer; }
.cx-chip.is-active { background:var(--cx-ink); color:var(--cx-card); border-color:var(--cx-ink); }
.cx-search { border:1px solid var(--cx-line); border-radius:8px; padding:7px 12px; font-size:.88rem; min-width:220px;
             background:var(--cx-card); color:var(--cx-ink); }
.cx-search:focus { outline:2px solid var(--primary); outline-offset:0; border-color:transparent; }

.cx-bar { height:8px; border-radius:999px; background:var(--cx-grey-bg); overflow:hidden; display:flex; }
.cx-bar span { display:block; height:100%; }
.cx-list { list-style:none; margin:0; padding:0; }
.cx-list li { display:flex; gap:12px; padding:10px 0; border-bottom:1px solid var(--cx-line); align-items:flex-start; }
.cx-list li:last-child { border-bottom:0; }
.cx-list .k { color:var(--cx-muted); font-size:.85rem; min-width:120px; }
.cx-empty { text-align:center; padding:36px 16px; color:var(--cx-muted); }
.cx-empty i { font-size:1.8rem; display:block; margin-bottom:6px; color:var(--cx-green); }
.cx-note { display:flex; gap:10px; align-items:flex-start; padding:12px 14px; border-radius:10px; font-size:.88rem; margin-bottom:16px; }
.cx-steps { counter-reset:s; list-style:none; padding:0; margin:0; }
.cx-steps li { counter-increment:s; display:flex; gap:12px; padding:8px 0; font-size:.9rem; }
.cx-steps li::before { content:counter(s); flex:none; width:24px; height:24px; border-radius:50%; background:var(--cx-blue-bg); color:var(--cx-blue);
                       font-weight:700; font-size:.78rem; display:grid; place-items:center; }
.cx-grid-2 { display:grid; grid-template-columns:minmax(0, 2fr) minmax(280px, 1fr); gap:20px; align-items:start; }
.cx-grid-2 > * { min-width:0; }
.cx { min-width:0; max-width:100%; }
@media (max-width: 1100px) { .cx-grid-2 { grid-template-columns:minmax(0, 1fr); } }
@media (max-width: 640px) {
  .cx-title { font-size:1.35rem; }
  .cx-hide-sm { display:none; }
  .cx-search { min-width:0; width:100%; }
  .cx-kpis { grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px; }
  .cx-kpi { padding:12px; gap:10px; }
  .cx-kpi-icon { width:32px; height:32px; font-size:1rem; }
  .cx-kpi-value { font-size:1.5rem; }
  .cx-actions { width:100%; }
  .cx-table td:first-child { white-space:normal; }
}
</style>
        <?php
    }
}

if (!function_exists('re_cx_header')) {
    /**
     * Page header + tab bar.
     * @param string $active  overview|types|alerts
     * @param string $actionsHtml  trusted HTML for the right-hand buttons
     */
    function re_cx_header(string $active, string $title, string $subtitle, string $actionsHtml = ''): void
    {
        re_cx_styles();
        $tabs = [
            'overview' => ['compliance.php', 'bi-shield-check', 'Overview'],
            'types'    => ['compliance_document_types.php', 'bi-tags', 'Document Types'],
            'ejari'    => ['compliance_ejari.php', 'bi-file-earmark-text', 'Ejari Tracking'],
            'alerts'   => ['compliance_send_alerts.php', 'bi-envelope', 'Expiry Alerts'],
        ];
        $e = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        echo '<div class="cx">';
        echo '<div class="cx-head"><div><div class="cx-eyebrow">Compliance</div>'
            . '<h1 class="cx-title">' . $e($title) . '</h1>'
            . '<p class="cx-sub">' . $e($subtitle) . '</p></div>'
            . ($actionsHtml !== '' ? '<div class="cx-actions">' . $actionsHtml . '</div>' : '')
            . '</div>';
        echo '<nav class="cx-tabs" aria-label="Compliance sections">';
        foreach ($tabs as $key => [$href, $icon, $label]) {
            $cls = $key === $active ? 'cx-tab is-active' : 'cx-tab';
            $cur = $key === $active ? ' aria-current="page"' : '';
            echo '<a class="' . $cls . '" href="' . $e($href) . '"' . $cur . '><i class="bi ' . $e($icon) . '"></i>' . $e($label) . '</a>';
        }
        echo '</nav>';
    }
}

if (!function_exists('re_cx_footer')) {
    function re_cx_footer(): void
    {
        echo '</div>';
    }
}

if (!function_exists('re_cx_flash')) {
    /** Print and clear $_SESSION['success'] / ['error'] in the compliance style. */
    function re_cx_flash(): void
    {
        $e = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        foreach (['success' => ['bg-green', 'bi-check-circle'], 'error' => ['bg-red', 'bi-exclamation-octagon']] as $k => [$cls, $icon]) {
            if (!empty($_SESSION[$k])) {
                echo '<div class="cx-note ' . $cls . '" role="status"><i class="bi ' . $icon . '"></i><div>' . $e($_SESSION[$k]) . '</div></div>';
                unset($_SESSION[$k]);
            }
        }
    }
}

if (!function_exists('re_cx_status_pill')) {
    /** Status pill for a document's days left. */
    function re_cx_status_pill(int $days): string
    {
        if ($days < 0) {
            $n = -$days;
            return '<span class="cx-pill bg-red">Expired ' . $n . ' day' . ($n === 1 ? '' : 's') . ' ago</span>';
        }
        if ($days === 0) {
            return '<span class="cx-pill bg-red">Expires today</span>';
        }
        $cls = $days <= 7 ? 'bg-red' : 'bg-amber';
        return '<span class="cx-pill ' . $cls . '">In ' . $days . ' day' . ($days === 1 ? '' : 's') . '</span>';
    }
}

if (!function_exists('re_doc_digest_last_sent')) {
    /** Date the last expiry summary went out (null if never). */
    function re_doc_digest_last_sent(PDO $conn, int $companyId): ?string
    {
        $s = $conn->prepare("SELECT MAX(alert_sent_date) FROM re_document_expiry_alerts WHERE company_id = ? AND status = 'sent'");
        $s->execute([$companyId]);
        $v = $s->fetchColumn();
        return $v ?: null;
    }
}

if (!function_exists('re_doc_digest_all_recipients')) {
    /** Everyone the summary goes to (both recipient sources). */
    function re_doc_digest_all_recipients(PDO $conn, int $companyId): array
    {
        $list = function_exists('re_doc_digest_recipients') ? re_doc_digest_recipients($conn, $companyId) : [];
        $s = $conn->prepare("SELECT DISTINCT recipient_email FROM re_email_notifications
                             WHERE company_id = ? AND notification_type = 'document_expiry' AND is_enabled = 1");
        $s->execute([$companyId]);
        return array_values(array_unique(array_filter(array_merge($list, $s->fetchAll(PDO::FETCH_COLUMN)))));
    }
}
