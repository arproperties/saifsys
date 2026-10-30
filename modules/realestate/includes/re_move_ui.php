<?php
/**
 * Shared design for the Move-In / Move-Out pages (move_in*.php, move_out*.php).
 *
 * Everything is scoped under <div class="rm-page">, so no other page is
 * affected. Follows the same look as the other refreshed RE pages (white
 * panels, 16px radius, soft shadow, brand --primary colours) and supports the
 * layout's dark mode.
 *
 * Usage, before including re_layout_header.php:
 *   require_once __DIR__ . '/includes/re_move_ui.php';
 *   $pageStyles = ($pageStyles ?? '') . re_move_ui_css();
 * then wrap the page body in <div class="rm-page"> … </div>.
 *
 * Undo: see _backups/move_ui_redesign_2026-10-21/RESTORE.md
 */

/**
 * Soft status pill. $tone: success|warning|info|primary|danger|secondary.
 */
function re_move_ui_pill(string $label, string $tone = 'secondary'): string
{
    return '<span class="rm-pill rm-pill-' . htmlspecialchars($tone, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * Tone for a move-in / move-out / deposit status value.
 */
function re_move_ui_tone(?string $status): string
{
    switch ((string)$status) {
        case 'completed':
        case 'refunded':
            return 'success';
        case 'in_progress':
        case 'inspection_scheduled':
        case 'processing':
            return 'info';
        case 'inspection_completed':
            return 'primary';
        case 'deposit_processing':
        case 'partial':
            return 'secondary';
        case 'forfeited':
        case 'cancelled':
            return 'danger';
        case 'pending':
        default:
            return 'warning';
    }
}

function re_move_ui_css(): string
{
    return <<<'CSS'
/* ===== Move-In / Move-Out design (scoped) ===== */
/* Let the layout's content column shrink on phones (otherwise it overflows sideways).
   Scoped with :has() so only pages that contain .rm-page are affected. */
.flex-grow-1:has(.rm-page) { min-width: 0; }
.rm-page { min-width: 0; }
/* Only long unbroken values (emails, references) may wrap mid-word */
.rm-page .rm-dl dd, .rm-page .rm-cell-sub, .rm-page .rm-cell-main { overflow-wrap: anywhere; }
/* Phones: a little less page padding (the menu is a drawer there) */
@media (max-width: 575.98px) {
  body:has(.rm-page) .flex-grow-1 > .navbar .navbar-brand { font-size: 1rem; white-space: normal; }
  body:has(.rm-page) .flex-grow-1 > div.container,
  body:has(.rm-page) .flex-grow-1 > div.container-fluid { padding-left: .75rem; padding-right: .75rem; }
}
.rm-page {
  --rm-radius: 16px;
  --rm-radius-sm: 10px;
  --rm-surface: #ffffff;
  --rm-surface-2: #f8fafc;
  --rm-border: rgba(15, 23, 42, .08);
  --rm-text: #0f172a;
  --rm-muted: #64748b;
  color: var(--rm-text);
}
body.dark-mode .rm-page {
  --rm-surface: #1e293b;
  --rm-surface-2: #172033;
  --rm-border: #334155;
  --rm-text: #e4e4e7;
  --rm-muted: #94a3b8;
}

/* Page header */
.rm-hero {
  display: flex; justify-content: space-between; align-items: flex-start;
  gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem;
}
.rm-hero h1 {
  font-size: clamp(1.35rem, 1.1rem + 1vw, 1.75rem);
  font-weight: 700; letter-spacing: -.02em; margin: 0 0 .2rem;
}
.rm-hero h1 .bi { color: var(--primary); margin-right: .4rem; }
.rm-hero .rm-sub { color: var(--rm-muted); font-size: .95rem; }
.rm-hero .rm-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem .6rem; color: var(--rm-muted); font-size: .9rem; }
.rm-actions { display: flex; gap: .5rem; flex-wrap: wrap; }
.rm-back { display: inline-flex; align-items: center; gap: .3rem; color: var(--rm-muted); text-decoration: none; font-size: .875rem; margin-bottom: .35rem; }
.rm-back:hover { color: var(--primary); }

/* Stat tiles */
.rm-stats {
  display: grid; gap: .75rem; margin-bottom: 1.25rem;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
}
.rm-stat {
  position: relative; overflow: hidden;
  background: var(--rm-surface); border: 1px solid var(--rm-border);
  border-radius: 14px; padding: .8rem 1rem .8rem 1.15rem; box-shadow: var(--shadow-sm);
  text-decoration: none; color: inherit; display: block;
  transition: transform .12s ease, box-shadow .12s ease;
}
a.rm-stat:hover { transform: translateY(-1px); box-shadow: var(--shadow); color: inherit; }
.rm-stat::before { content: ""; position: absolute; inset: 0 auto 0 0; width: 4px; background: var(--rm-accent, var(--primary)); }
.rm-stat.is-active { box-shadow: 0 0 0 2px var(--rm-accent, var(--primary)); }
.rm-stat .lbl { font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; color: var(--rm-muted); }
.rm-stat .val { font-size: 1.5rem; font-weight: 700; line-height: 1.2; color: var(--rm-text); }
.rm-tone-warning   { --rm-accent: #f59e0b; }
.rm-tone-info      { --rm-accent: #0ea5e9; }
.rm-tone-primary   { --rm-accent: var(--primary); }
.rm-tone-secondary { --rm-accent: #64748b; }
.rm-tone-success   { --rm-accent: #16a34a; }
.rm-tone-danger    { --rm-accent: #dc2626; }

/* Panels: every Bootstrap card on these pages */
.rm-page .card {
  background: var(--rm-surface); color: var(--rm-text);
  border: 1px solid var(--rm-border); border-radius: var(--rm-radius);
  box-shadow: var(--shadow-sm); overflow: hidden;
}
.rm-page .card + .card { margin-top: 0; }
.rm-page .card-header {
  display: flex; justify-content: space-between; align-items: center; gap: .75rem; flex-wrap: wrap;
  padding: .85rem 1.1rem; border-bottom: 1px solid var(--rm-border);
  background: linear-gradient(180deg, var(--rm-surface), var(--rm-surface-2)) !important;
  color: var(--rm-text) !important;
}
.rm-page .card-header h5, .rm-page .card-header h6, .rm-page .card-header .h5 {
  font-size: .98rem; font-weight: 650; margin: 0;
}
.rm-page .card-header .bi { color: var(--primary); }
.rm-page .card.border-info .card-header .bi { color: #0ea5e9; }
.rm-page .card-body { padding: 1.1rem; }
.rm-page .card-footer { background: var(--rm-surface-2); border-top: 1px solid var(--rm-border); }
.rm-page .card.border-info, .rm-page .card.border-warning, .rm-page .card.border-success,
.rm-page .card.border-danger, .rm-page .card.border-primary, .rm-page .card.border-secondary {
  border-color: var(--rm-border) !important;
}
.rm-section-title { font-size: .78rem; text-transform: uppercase; letter-spacing: .06em; color: var(--rm-muted); font-weight: 600; margin: 0 0 .6rem; }

/* Filter bar */
.rm-filter { background: var(--rm-surface); border: 1px solid var(--rm-border); border-radius: var(--rm-radius); box-shadow: var(--shadow-sm); padding: 1rem 1.1rem; margin-bottom: 1.25rem; }
.rm-filter .form-label { font-size: .78rem; color: var(--rm-muted); margin-bottom: .25rem; }

/* Forms & buttons */
.rm-page .form-control, .rm-page .form-select { border-radius: var(--rm-radius-sm); }
.rm-page .form-label { font-weight: 600; font-size: .875rem; }
.rm-page .form-text { color: var(--rm-muted); }
.rm-page .btn { border-radius: var(--rm-radius-sm); font-weight: 550; }
.rm-page .btn-primary { background: var(--primary); border-color: var(--primary); }
.rm-page .btn-primary:hover, .rm-page .btn-primary:focus { background: var(--primary-dark); border-color: var(--primary-dark); }
.rm-page .input-group > .form-control, .rm-page .input-group > .btn, .rm-page .input-group > .input-group-text { border-radius: 0; }
.rm-page .input-group > :first-child { border-top-left-radius: var(--rm-radius-sm); border-bottom-left-radius: var(--rm-radius-sm); }
.rm-page .input-group > :last-child { border-top-right-radius: var(--rm-radius-sm); border-bottom-right-radius: var(--rm-radius-sm); }
.rm-page .select2-container--bootstrap-5 .select2-selection { border-radius: var(--rm-radius-sm); min-height: 42px; }
.rm-page .alert { border-radius: 12px; }
body.dark-mode .rm-page .form-control, body.dark-mode .rm-page .form-select {
  background-color: #0f172a; color: #e4e4e7; border-color: #334155;
}

/* Status pills (and softer Bootstrap badges) */
.rm-pill {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .22rem .6rem; border-radius: 999px; font-size: .75rem; font-weight: 600; line-height: 1.2; white-space: nowrap;
}
.rm-pill-success   { background: #dcfce7; color: #166534; }
.rm-pill-warning   { background: #fef3c7; color: #92400e; }
.rm-pill-info      { background: #e0f2fe; color: #075985; }
.rm-pill-primary   { background: #e0e7ff; color: #3730a3; }
.rm-pill-secondary { background: #f1f5f9; color: #334155; }
.rm-pill-danger    { background: #fee2e2; color: #991b1b; }
body.dark-mode .rm-pill-success   { background: rgba(22,163,74,.2);  color: #86efac; }
body.dark-mode .rm-pill-warning   { background: rgba(245,158,11,.2); color: #fcd34d; }
body.dark-mode .rm-pill-info      { background: rgba(14,165,233,.2); color: #7dd3fc; }
body.dark-mode .rm-pill-primary   { background: rgba(99,102,241,.25); color: #c7d2fe; }
body.dark-mode .rm-pill-secondary { background: rgba(148,163,184,.2); color: #cbd5e1; }
body.dark-mode .rm-pill-danger    { background: rgba(220,38,38,.2);  color: #fca5a5; }
.rm-page .badge { border-radius: 999px; font-weight: 600; padding: .35em .65em; }

/* Tables: cards on phones */
.rm-page .table { margin-bottom: 0; color: var(--rm-text); --bs-table-bg: transparent; --bs-table-color: var(--rm-text); }
.rm-page .table > thead th {
  font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: var(--rm-muted);
  background: var(--rm-surface-2); border-bottom: 1px solid var(--rm-border); white-space: nowrap; font-weight: 600;
}
.rm-page .table > :not(caption) > * > * { padding: .7rem .8rem; border-color: var(--rm-border); vertical-align: middle; }
.rm-page .table-hover > tbody > tr:hover > * { --bs-table-hover-bg: rgba(13,110,253,.04); }
.rm-page .card-body > .table-responsive:only-child { margin: -1.1rem; }
.rm-cell-main { font-weight: 600; }
.rm-cell-sub { font-size: .8rem; color: var(--rm-muted); }
.rm-empty { text-align: center; color: var(--rm-muted); padding: 2.5rem 1rem !important; }
.rm-empty .bi { display: block; font-size: 2rem; opacity: .5; margin-bottom: .35rem; }
.rm-page .progress { height: 8px; border-radius: 999px; background: var(--rm-surface-2); min-width: 80px; }
.rm-progress-label { font-size: .75rem; color: var(--rm-muted); margin-top: .2rem; }
/* Big progress bar with its text: keep the text readable even when the bar is short */
.rm-page .progress.rm-progress-main { overflow: visible; }
.rm-page .rm-progress-main .progress-bar {
  overflow: visible; white-space: nowrap; justify-content: center; align-items: flex-start;
  padding-left: .6rem; color: #0f172a; font-weight: 600; font-size: .78rem; border-radius: 999px;
  display: flex; flex-direction: row; align-items: center; justify-content: flex-start;
}
body.dark-mode .rm-page .rm-progress-main .progress-bar { color: #e4e4e7; }

@media (max-width: 767.98px) {
  .rm-table-stack thead { display: none; }
  .rm-table-stack, .rm-table-stack tbody, .rm-table-stack tr, .rm-table-stack td { display: block; width: 100%; }
  .rm-table-stack tr {
    background: var(--rm-surface); border: 1px solid var(--rm-border); border-radius: 14px;
    margin: 0 0 .75rem; padding: .35rem .25rem; box-shadow: var(--shadow-sm);
  }
  .rm-table-stack > :not(caption) > * > * { border: 0 !important; padding: .4rem .8rem; }
  .rm-table-stack td[data-label] { display: flex; justify-content: space-between; align-items: center; gap: 1rem; text-align: right; }
  .rm-table-stack td[data-label]::before {
    content: attr(data-label); font-size: .72rem; text-transform: uppercase; letter-spacing: .04em;
    color: var(--rm-muted); font-weight: 600; text-align: left; flex-shrink: 0;
  }
  .rm-table-stack td.rm-td-actions { padding-top: .6rem; }
  .rm-table-stack td.rm-td-actions .btn { width: 100%; }
  .rm-table-stack .progress { flex: 1; max-width: 140px; }
  .rm-page .card-body > .table-responsive:only-child { margin: 0; }
  .rm-page .card-body:has(> .table-responsive > .rm-table-stack) { padding: .75rem; background: var(--rm-surface-2); }
}

/* Detail lists (label / value) */
.rm-dl { display: grid; grid-template-columns: minmax(110px, 38%) 1fr; gap: .45rem 1rem; margin: 0; }
.rm-dl dt { color: var(--rm-muted); font-weight: 500; font-size: .875rem; }
.rm-dl dd { margin: 0; font-weight: 600; word-break: break-word; }
@media (max-width: 575.98px) { .rm-dl { grid-template-columns: 1fr; gap: .1rem; } .rm-dl dd { margin-bottom: .45rem; } }

/* Checklist / damage items (view pages) */
.rm-page .checklist-item {
  border: 1px solid var(--rm-border); border-left: 4px solid #cbd5e1; border-radius: 12px;
  background: var(--rm-surface-2); padding: .8rem 1rem; margin-bottom: .6rem;
}
.rm-page .checklist-item.required { border-left-color: #f59e0b; }
.rm-page .checklist-item.completed { border-left-color: #16a34a; background: #f0fdf4; }
body.dark-mode .rm-page .checklist-item.completed { background: rgba(22,163,74,.12); }
.rm-page .damage-item {
  border: 1px solid var(--rm-border); border-left: 4px solid #dc2626; border-radius: 12px;
  background: var(--rm-surface-2); padding: .8rem 1rem; margin-bottom: .6rem;
}
.rm-page .photo-thumb, .rm-page .img-thumbnail { border-radius: 10px; }

/* Sticky side column on wide screens */
@media (min-width: 992px) { .rm-sticky { position: sticky; top: 1rem; } }

/* Narrow-form pages (add) */
.rm-narrow { max-width: 860px; margin: 0 auto; }

/* Phones & small tablets */
@media (max-width: 575.98px) {
  .rm-hero { flex-direction: column; align-items: stretch; }
  .rm-actions, .rm-actions .btn { width: 100%; }
  .rm-actions .btn { justify-content: center; }
  .rm-page .card-body { padding: .9rem; }
  .rm-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .rm-stat .val { font-size: 1.3rem; }
  .rm-page .d-flex.justify-content-between > .btn,
  .rm-form-actions .btn { flex: 1 1 auto; }
}
.rm-form-actions { display: flex; justify-content: space-between; gap: .5rem; flex-wrap: wrap; }
@media (max-width: 575.98px) { .rm-form-actions { flex-direction: column-reverse; } .rm-form-actions .btn { width: 100%; } }

@media print {
  .rm-actions, .rm-filter { display: none !important; }
  .rm-page .card { box-shadow: none; }
}
CSS;
}

/**
 * Milestone bar for the Move-In / Move-Out pages: Started -> steps -> Completed.
 *
 * $items: [ ['label' => .., 'icon' => 'bi-..', 'state' => done|current|todo|warn, 'sub' => short status], ... ]
 * Page sections opt in with data-ms="<index>" (several: data-ms="3 5"); only the
 * sections of the selected milestone are shown. The selection follows #step-<n>
 * in the URL, so a reload after saving stays on the same milestone.
 */
/**
 * Furthest milestone the user may open: the first unfinished required one.
 * Items marked 'optional' (not needed to finish) do not block the way on.
 */
function re_milestones_reach(array $items): int
{
    foreach ($items as $i => $it) {
        if ($it['state'] !== 'done' && empty($it['optional'])) {
            return $i;
        }
    }
    return count($items) - 1;
}

function re_milestones_html(array $items, int $selected, string $ariaLabel): string
{
    $last = count($items) - 1;
    $reach = re_milestones_reach($items);
    $selected = min($selected, $reach);
    $reached = 0; // furthest milestone reached without a gap, for the progress line
    foreach ($items as $i => $it) {
        if ($it['state'] === 'done' || $it['state'] === 'current' || $it['state'] === 'warn') {
            $reached = $i;
        }
        if ($it['state'] === 'todo') {
            break;
        }
    }
    $frac = $last > 0 ? round($reached / $last, 4) : 1;
    $h = '<nav class="rmm" data-rmm data-default="' . $selected . '" data-reach="' . $reach . '" aria-label="' . htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8') . '">'
        . '<ol class="rmm-track" style="--rmm-steps:' . count($items) . ';--rmm-frac:' . $frac . '">';
    foreach ($items as $i => $it) {
        $state = $it['state'];
        $icon = $state === 'done' ? 'bi-check-lg' : $it['icon'];
        $locked = $i > $reach;
        $h .= '<li class="rmm-step is-' . $state . ($locked ? ' is-locked' : '') . '" style="--rmm-i:' . $i . '">'
            . '<button type="button" class="rmm-btn" data-rmm-go="' . $i . '" aria-controls="rmmPanels" aria-pressed="false"'
            . ($locked ? ' aria-disabled="true" title="Finish the earlier steps first"' : '') . '>'
            . '<span class="rmm-dot"><i class="bi ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i></span>'
            . '<span class="rmm-label">' . htmlspecialchars($it['label'], ENT_QUOTES, 'UTF-8') . '</span>'
            . (!empty($it['sub']) ? '<span class="rmm-sub">' . htmlspecialchars($it['sub'], ENT_QUOTES, 'UTF-8') . '</span>' : '')
            . '</button></li>';
    }
    return $h . '</ol></nav>';
}

/**
 * Heading shown above the sections of the selected milestone: status next to the
 * title, and Previous / Next. Previous only changes the view. Next opens the next
 * milestone only when it is reachable ($reach from re_milestones_reach), so a user
 * cannot skip an unfinished step; $nextHint says why when it is blocked.
 */
function re_milestone_head(int $index, int $count, string $icon, string $title, string $text, string $state, ?int $reach = null, string $nextHint = 'Finish this step to continue'): string
{
    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $tone = ['done' => 'success', 'current' => 'primary', 'warn' => 'warning', 'todo' => 'secondary'][$state] ?? 'secondary';
    $label = ['done' => 'Done', 'current' => 'In progress', 'warn' => 'Pending', 'todo' => 'Not started'][$state] ?? '';
    $reach = $reach ?? $count - 1;
    $nav = '<div class="rmm-head-nav">';
    // Step label under Previous / Next: "Start", "Step n" or "Finish"
    $stepName = static fn(int $i) => $i === 0 ? 'Start' : ($i === $count - 1 ? 'Finish' : 'Step ' . $i);
    if ($index > 0) {
        $nav .= '<button type="button" class="rmm-nb rmm-nb-prev" data-rmm-nav="' . ($index - 1) . '">'
            . '<span class="rmm-nb-ico"><i class="bi bi-arrow-left"></i></span>'
            . '<span class="rmm-nb-txt"><small>' . $e($stepName($index - 1)) . '</small>Previous</span></button>';
    }
    if ($index < $count - 1) {
        $canNext = $index + 1 <= $reach;
        $nav .= '<button type="button" class="rmm-nb rmm-nb-next' . ($canNext ? '' : ' is-locked') . '" data-rmm-nav="' . ($index + 1) . '"'
            . ($canNext ? '' : ' disabled aria-disabled="true" title="' . $e($nextHint) . '"') . '>'
            . '<span class="rmm-nb-txt"><small>' . $e($stepName($index + 1)) . '</small>Next</span>'
            . '<span class="rmm-nb-ico"><i class="bi ' . ($canNext ? 'bi-arrow-right' : 'bi-lock-fill') . '"></i></span></button>';
        if (!$canNext) {
            $nav .= '<div class="rmm-head-hint"><span class="rmm-hint-dot" aria-hidden="true"></span>' . $e($nextHint) . '</div>';
        }
    }
    $nav .= '</div>';
    return '<div class="rmm-head is-' . $state . '" data-ms="' . $index . '">'
        . '<div class="rmm-art" aria-hidden="true"><span class="rmm-art-ring"></span><i class="bi ' . $e($icon) . '"></i></div>'
        . '<div class="rmm-head-txt"><div class="rmm-head-step">' . ($index === 0 ? 'Start' : ($index === $count - 1 ? 'Finish' : 'Step ' . $index . ' of ' . ($count - 2))) . '</div>'
        . '<div class="rmm-head-title">' . $e($title) . ' ' . re_move_ui_pill($label, $tone) . '</div>'
        . '<div class="rmm-head-text">' . $e($text) . '</div></div>'
        . $nav . '</div>';
}

function re_milestones_css(): string
{
    return <<<'CSS'
.rmm { margin: 0 0 1.25rem; background: var(--rm-surface); border: 1px solid var(--rm-border); border-radius: var(--rm-radius); box-shadow: var(--shadow-sm); padding: 1.1rem .75rem .9rem; overflow-x: auto; scrollbar-width: thin; }
.rmm-track { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(var(--rmm-steps), minmax(96px, 1fr)); position: relative; min-width: calc(var(--rmm-steps) * 96px); }
/* Line through the dots: grey rail + coloured progress that grows on load */
.rmm-track::before, .rmm-track::after { content: ""; position: absolute; top: 21px; height: 4px; border-radius: 4px; left: calc(50% / var(--rmm-steps)); }
.rmm-track::before { right: calc(50% / var(--rmm-steps)); background: var(--rm-surface-2); box-shadow: inset 0 0 0 1px var(--rm-border); }
.rmm-track::after { width: 0; max-width: calc(100% - 100% / var(--rmm-steps)); background: linear-gradient(90deg, #16a34a, var(--primary)); animation: rmm-grow 1s cubic-bezier(.2,.7,.2,1) .15s forwards; }
@keyframes rmm-grow { to { width: calc((100% - 100% / var(--rmm-steps)) * var(--rmm-frac)); } }
.rmm-step { position: relative; z-index: 1; display: flex; justify-content: center; }
.rmm-btn { display: flex; flex-direction: column; align-items: center; gap: .3rem; background: none; border: 0; padding: 0 .25rem .35rem; color: var(--rm-muted); cursor: pointer; border-radius: 12px; min-width: 0; max-width: 140px; }
.rmm-btn:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.rmm-dot { width: 46px; height: 46px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 1.15rem; background: var(--rm-surface); border: 2px solid var(--rm-border); color: var(--rm-muted); position: relative; transition: transform .2s ease, box-shadow .2s ease; opacity: 0; transform: scale(.6); animation: rmm-pop .45s cubic-bezier(.3,1.4,.5,1) forwards; animation-delay: calc(.1s + var(--rmm-i) * .08s); }
@keyframes rmm-pop { to { opacity: 1; transform: scale(1); } }
.rmm-label { font-size: .8rem; font-weight: 650; line-height: 1.2; text-align: center; color: var(--rm-text); }
.rmm-sub { font-size: .7rem; line-height: 1.2; text-align: center; }
.is-done .rmm-dot { background: #16a34a; border-color: #16a34a; color: #fff; }
.is-current .rmm-dot { background: var(--primary); border-color: var(--primary); color: #fff; }
.is-current .rmm-dot::after { content: ""; position: absolute; inset: -6px; border-radius: 50%; border: 2px solid var(--primary); opacity: .5; animation: rmm-pulse 1.8s ease-out infinite; }
@keyframes rmm-pulse { 0% { transform: scale(.85); opacity: .6; } 100% { transform: scale(1.35); opacity: 0; } }
.is-warn .rmm-dot { background: #fef3c7; border-color: #f59e0b; color: #b45309; }
.is-todo .rmm-label { color: var(--rm-muted); font-weight: 600; }
.rmm-btn:hover .rmm-dot { transform: translateY(-2px); box-shadow: 0 6px 14px rgba(15,23,42,.12); }
.rmm-btn[aria-pressed="true"] .rmm-dot { box-shadow: 0 0 0 4px rgba(13,110,253,.18); }
.rmm-btn[aria-pressed="true"] .rmm-label { color: var(--primary); }
.rmm-btn[aria-pressed="true"]::after { content: ""; width: 26px; height: 3px; border-radius: 3px; background: var(--primary); margin-top: .1rem; }
body.dark-mode .is-warn .rmm-dot { background: rgba(245,158,11,.18); color: #fbbf24; }

/* Milestone heading with a small animated illustration */
.rmm-head { display: flex; align-items: center; gap: 1rem; padding: 1rem 1.1rem; margin-bottom: 1rem; border-radius: var(--rm-radius); border: 1px solid var(--rm-border); background: linear-gradient(120deg, rgba(13,110,253,.07), rgba(22,163,74,.05) 60%, var(--rm-surface)); }
.rmm-head.is-done { background: linear-gradient(120deg, rgba(22,163,74,.12), var(--rm-surface) 70%); }
.rmm-head.is-warn { background: linear-gradient(120deg, rgba(245,158,11,.14), var(--rm-surface) 70%); }
.rmm-head-title .rm-pill { vertical-align: middle; margin-left: .35rem; font-size: .72rem; }
.rmm-head-nav { margin-left: auto; display: flex; flex-wrap: wrap; justify-content: flex-end; align-items: center; gap: .4rem; flex-shrink: 0; max-width: 46%; }
.rmm-head-nav .btn { white-space: nowrap; }

/* Previous / Next: glass pill + gradient pill with animated arrow badges */
.rmm-nb { --nb-h: 50px; position: relative; display: inline-flex; align-items: center; gap: .65rem; height: var(--nb-h); padding: 0 .45rem 0 1.1rem; border-radius: 999px; border: 1px solid transparent; font-weight: 700; font-size: .92rem; line-height: 1; white-space: nowrap; cursor: pointer; overflow: hidden; isolation: isolate; transition: transform .25s cubic-bezier(.2,.8,.2,1), box-shadow .25s ease, background-color .25s ease, border-color .25s ease, color .25s ease; }
.rmm-nb:focus-visible { outline: 3px solid rgba(99,102,241,.45); outline-offset: 3px; }
.rmm-nb-txt { display: flex; flex-direction: column; align-items: flex-start; gap: .18rem; }
.rmm-nb-txt small { font-size: .62rem; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; opacity: .7; }
.rmm-nb-ico { width: 36px; height: 36px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; transition: transform .3s cubic-bezier(.3,1.5,.5,1), background-color .25s ease; }

.rmm-nb-prev { padding: 0 1.1rem 0 .45rem; color: var(--rm-text); background: rgba(255,255,255,.65); border-color: var(--rm-border); box-shadow: 0 1px 2px rgba(15,23,42,.05), inset 0 1px 0 rgba(255,255,255,.7); -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px); }
.rmm-nb-prev .rmm-nb-ico { background: var(--rm-surface-2); color: var(--rm-muted); }
.rmm-nb-prev:hover { transform: translateY(-2px); border-color: rgba(99,102,241,.35); box-shadow: 0 10px 22px -10px rgba(15,23,42,.25); }
.rmm-nb-prev:hover .rmm-nb-ico { transform: translateX(-3px); background: rgba(99,102,241,.12); color: #4f46e5; }

.rmm-nb-next:not(.is-locked) { color: #fff; background: linear-gradient(135deg, #2563eb 0%, #4f46e5 55%, #7c3aed 100%); background-size: 160% 160%; box-shadow: 0 10px 24px -10px rgba(79,70,229,.75), inset 0 1px 0 rgba(255,255,255,.25); animation: rmm-nb-sheen-bg 6s ease infinite; }
.rmm-nb-next:not(.is-locked) .rmm-nb-ico { background: rgba(255,255,255,.18); box-shadow: inset 0 0 0 1px rgba(255,255,255,.25); animation: rmm-nb-nudge 1.8s ease-in-out infinite; }
.rmm-nb-next:not(.is-locked)::before { content: ""; position: absolute; inset: 0; z-index: -1; background: linear-gradient(110deg, transparent 30%, rgba(255,255,255,.35) 50%, transparent 70%); transform: translateX(-120%); transition: transform .7s ease; }
.rmm-nb-next:not(.is-locked):hover { transform: translateY(-2px); box-shadow: 0 16px 30px -12px rgba(79,70,229,.85), inset 0 1px 0 rgba(255,255,255,.25); }
.rmm-nb-next:not(.is-locked):hover::before { transform: translateX(120%); }
.rmm-nb-next:not(.is-locked):hover .rmm-nb-ico { animation: none; transform: translateX(3px) scale(1.06); background: rgba(255,255,255,.28); }
.rmm-nb-next:not(.is-locked):active, .rmm-nb-prev:active { transform: translateY(0) scale(.98); }
@keyframes rmm-nb-nudge { 0%, 60%, 100% { transform: translateX(0); } 30% { transform: translateX(3px); } }
@keyframes rmm-nb-sheen-bg { 0%, 100% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } }

.rmm-nb-next.is-locked { cursor: not-allowed; color: var(--rm-muted); background: repeating-linear-gradient(135deg, var(--rm-surface-2) 0 8px, var(--rm-surface) 8px 16px); border: 1px dashed var(--rm-border); box-shadow: none; }
.rmm-nb-next.is-locked .rmm-nb-ico { background: var(--rm-surface); color: #94a3b8; box-shadow: inset 0 0 0 1px var(--rm-border); }
.rmm-nb-next.is-locked:hover .rmm-nb-ico { animation: rmm-nb-wiggle .5s ease; color: #d97706; }
@keyframes rmm-nb-wiggle { 0%, 100% { transform: rotate(0); } 25% { transform: rotate(-12deg); } 50% { transform: rotate(10deg); } 75% { transform: rotate(-6deg); } }

/* Why Next is locked: amber chip with a soft pulsing dot */
.rmm-head-hint { flex: 0 1 auto; margin-left: auto; margin-top: .15rem; display: inline-flex; align-items: center; gap: .45rem; padding: .35rem .75rem; border-radius: 999px; font-size: .76rem; font-weight: 600; color: #92400e; background: linear-gradient(90deg, rgba(254,243,199,.95), rgba(255,251,235,.9)); border: 1px solid rgba(245,158,11,.35); animation: rmm-hint-in .45s cubic-bezier(.2,.8,.2,1) both .1s; }
.rmm-hint-dot { width: 8px; height: 8px; border-radius: 50%; background: #f59e0b; flex-shrink: 0; animation: rmm-hint-pulse 1.8s ease-out infinite; }
@keyframes rmm-hint-pulse { 0% { box-shadow: 0 0 0 0 rgba(245,158,11,.55); } 80%, 100% { box-shadow: 0 0 0 8px rgba(245,158,11,0); } }
@keyframes rmm-hint-in { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }

body.dark-mode .rmm-nb-prev { background: rgba(30,41,59,.6); }
body.dark-mode .rmm-head-hint { background: rgba(245,158,11,.14); color: #fbbf24; border-color: rgba(245,158,11,.35); }
@media (prefers-reduced-motion: reduce) {
  .rmm-nb, .rmm-nb-ico, .rmm-nb-next::before, .rmm-head-hint, .rmm-hint-dot { animation: none !important; transition: none !important; }
}
.rmm-step.is-locked .rmm-btn { cursor: not-allowed; }
.rmm-step.is-locked .rmm-dot { opacity: .55; }
.rmm-step.is-locked .rmm-btn:hover .rmm-dot { transform: none; box-shadow: none; }
.rmm-art { position: relative; width: 58px; height: 58px; flex-shrink: 0; border-radius: 18px; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; color: var(--primary); background: var(--rm-surface); box-shadow: 0 8px 20px rgba(15,23,42,.08); animation: rmm-float 3.2s ease-in-out infinite; }
.rmm-head.is-done .rmm-art { color: #16a34a; }
.rmm-head.is-warn .rmm-art { color: #d97706; }
.rmm-art-ring { position: absolute; inset: -5px; border-radius: 22px; border: 2px dashed currentColor; opacity: .25; animation: rmm-spin 14s linear infinite; }
@keyframes rmm-float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-4px); } }
@keyframes rmm-spin { to { transform: rotate(360deg); } }
.rmm-head-txt { min-width: 0; }
.rmm-head-step { font-size: .7rem; text-transform: uppercase; letter-spacing: .07em; color: var(--rm-muted); font-weight: 700; }
.rmm-head-title { font-size: 1.15rem; font-weight: 750; line-height: 1.25; }
.rmm-head-text { font-size: .85rem; color: var(--rm-muted); }

/* Finish milestone: what is left, and a small celebration once done */
.rmm-finish-hero { display: flex; align-items: center; gap: 1rem; padding: .25rem 0 1rem; margin-bottom: .75rem; border-bottom: 1px dashed var(--rm-border); }
.rmm-finish-art { position: relative; width: 72px; height: 72px; border-radius: 22px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 2.1rem; color: #fff; background: linear-gradient(135deg, #16a34a, var(--primary)); box-shadow: 0 10px 24px rgba(22,163,74,.3); animation: rmm-float 3.2s ease-in-out infinite; }
.rmm-finish-art span { position: absolute; width: 8px; height: 8px; border-radius: 2px; background: #f59e0b; animation: rmm-confetti 2.4s ease-in-out infinite; }
.rmm-finish-art span:nth-child(2) { top: -6px; left: 8px; }
.rmm-finish-art span:nth-child(3) { top: 4px; right: -8px; background: #0ea5e9; animation-delay: .5s; }
.rmm-finish-art span:nth-child(4) { bottom: -6px; left: -6px; background: #ec4899; animation-delay: 1s; }
@keyframes rmm-confetti { 0%, 100% { transform: translateY(0) rotate(0); opacity: 1; } 50% { transform: translateY(-8px) rotate(90deg); opacity: .6; } }
@media (prefers-reduced-motion: reduce) { .rmm-finish-art, .rmm-finish-art span { animation: none; } }
.rmm-finish-list { display: flex; flex-direction: column; gap: .4rem; }
.rmm-finish-row { display: flex; align-items: center; gap: .65rem; padding: .6rem .8rem; border: 1px solid var(--rm-border); border-radius: 12px; color: var(--rm-text); text-decoration: none; }
.rmm-finish-row:hover { border-color: var(--primary); }
.rmm-finish-row > .bi:first-child { color: var(--rm-muted); font-size: 1.1rem; }
.rmm-finish-row.ok > .bi:first-child { color: #16a34a; }

/* Only the sections of the selected milestone are shown */
[data-rmm-ready] [data-ms]:not(.rmm-on) { display: none !important; }
[data-rmm-ready] .rmm-on { animation: rmm-in .35s ease both; }
@keyframes rmm-in { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }

@media (max-width: 575.98px) {
  .rmm { padding: .9rem .5rem .75rem; }
  .rmm-dot { width: 40px; height: 40px; font-size: 1rem; }
  .rmm-track::before, .rmm-track::after { top: 18px; }
  .rmm-sub { display: none; }
  .rmm-head { flex-wrap: wrap; }
  .rmm-head-nav { margin-left: 0; max-width: none; width: 100%; justify-content: stretch; }
  .rmm-head-nav .btn, .rmm-head-nav .rmm-nb { flex: 1 1 0; justify-content: space-between; }
  .rmm-head-hint { margin-left: 0; }
  .rmm-head-hint { text-align: left; }
}
@media (prefers-reduced-motion: reduce) {
  .rmm-dot, .rmm-track::after, .rmm-art, .rmm-art-ring, .is-current .rmm-dot::after, [data-rmm-ready] .rmm-on { animation: none !important; }
  .rmm-dot { opacity: 1; transform: none; }
  .rmm-track::after { width: calc((100% - 100% / var(--rmm-steps)) * var(--rmm-frac)); }
}
@media print { .rmm { display: none; } [data-rmm-ready] [data-ms] { display: block !important; } }
CSS;
}

/** Script: switch milestones, keep #step-<n>, follow in-page links into hidden sections. */
function re_milestones_js(): string
{
    return <<<'JS'
<script>
(function () {
    const nav = document.querySelector('[data-rmm]');
    if (!nav) return;
    const root = document.querySelector('.rm-page') || document.body;
    const count = nav.querySelectorAll('[data-rmm-go]').length;
    const parts = el => (el.getAttribute('data-ms') || '').split(/\s+/).filter(Boolean).map(Number);
    const reach = nav.dataset.reach !== undefined ? parseInt(nav.dataset.reach, 10) : count - 1;
    function select(n, push) {
        if (!(n >= 0 && n < count) || n > reach) n = parseInt(nav.dataset.default, 10) || 0;
        root.querySelectorAll('[data-ms]').forEach(el => el.classList.toggle('rmm-on', parts(el).includes(n)));
        nav.querySelectorAll('[data-rmm-go]').forEach(b => b.setAttribute('aria-pressed', String(+b.dataset.rmmGo === n)));
        const btn = nav.querySelector('[data-rmm-go="' + n + '"]');
        if (btn && btn.scrollIntoView) btn.scrollIntoView({ block: 'nearest', inline: 'center' });
        if (push !== false) { try { history.replaceState(null, '', '#step-' + n); } catch (e) {} }
    }
    nav.addEventListener('click', e => {
        const b = e.target.closest('[data-rmm-go]');
        if (b && b.getAttribute('aria-disabled') !== 'true') select(+b.dataset.rmmGo);
    });
    // Previous / Next in the milestone heading
    document.addEventListener('click', e => {
        const b = e.target.closest('[data-rmm-nav]');
        if (!b || b.disabled) return;
        select(+b.dataset.rmmNav);
        window.scrollTo({ top: Math.max(0, nav.getBoundingClientRect().top + window.scrollY - 12), behavior: 'smooth' });
    });
    // Links to a section of another milestone (e.g. "Upload photos" -> Inspection)
    document.addEventListener('click', e => {
        const a = e.target.closest('a[href^="#"]');
        if (!a || a.getAttribute('href').length < 2 || a.matches('[data-bs-toggle]')) return;
        const t = document.getElementById(a.getAttribute('href').slice(1));
        const box = t && t.closest('[data-ms]');
        if (!box || box.classList.contains('rmm-on')) return;
        e.preventDefault();
        if (parts(box)[0] > reach) { select(NaN); return; }
        select(parts(box)[0]);
        setTimeout(() => t.scrollIntoView({ behavior: 'smooth', block: 'start' }), 60);
    });
    const m = /^#step-(\d+)$/.exec(location.hash);
    root.setAttribute('data-rmm-ready', '');
    select(m ? +m[1] : NaN, false);
    window.rmmSelect = select;
})();
</script>
JS;
}

/**
 * Themed date picker for the Move-In / Move-Out pages (flatpickr 4.6.13).
 * Every <input type="date"> / "datetime-local" on the page becomes a picker that
 * shows a friendly date ("Tue, 27 Oct 2026") and still submits Y-m-d (or
 * Y-m-dTH:i), so forms and server code are unchanged. min / max / required are
 * kept; selecting a date fires "change" on the original input.
 * Set a value from script with rmSetDate(input, 'Y-m-d').
 * Echo once near the end of the page, after the page's own scripts.
 */
function re_datepicker_assets(): string
{
    return <<<'HTML'
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<style>
.rm-date-input { cursor: pointer; padding-left: 2.6rem !important; min-height: 44px; font-weight: 600; border-radius: 12px !important;
  background: var(--rm-surface, #fff) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='18' height='18' fill='%230d6efd' viewBox='0 0 16 16'%3E%3Cpath d='M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5M2 2a1 1 0 0 0-1 1v1h14V3a1 1 0 0 0-1-1zm13 3H1v9a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1z'/%3E%3Cpath d='M11 7.5a.5.5 0 0 1 .5-.5h1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-1a.5.5 0 0 1-.5-.5z'/%3E%3C/svg%3E") no-repeat .9rem center !important;
  transition: border-color .15s ease, box-shadow .15s ease; }
.rm-date-input:hover { border-color: var(--primary, #0d6efd) !important; }
.rm-date-input:focus, .rm-date-input.active { border-color: var(--primary, #0d6efd) !important; box-shadow: 0 0 0 4px rgba(13,110,253,.14) !important; }
.rm-date-input[disabled] { cursor: not-allowed; opacity: .65; }
.flatpickr-calendar.rm-dp { border-radius: 18px; border: 1px solid rgba(15,23,42,.08); box-shadow: 0 22px 48px rgba(15,23,42,.18), 0 4px 12px rgba(15,23,42,.06);
  font-family: inherit; padding: 10px 10px 6px; width: 328px; }
.flatpickr-calendar.rm-dp.open { animation: rmDpIn .18s cubic-bezier(.2,.8,.2,1); }
@keyframes rmDpIn { from { opacity: 0; transform: translateY(-6px) scale(.98); } to { opacity: 1; transform: none; } }
.flatpickr-calendar.rm-dp::before, .flatpickr-calendar.rm-dp::after { display: none; }
.rm-dp .flatpickr-months { padding: 2px 2px 8px; align-items: center; }
.rm-dp .flatpickr-months .flatpickr-month { height: 40px; color: #0f172a; }
.rm-dp .flatpickr-current-month { font-size: 1.02rem; font-weight: 750; padding-top: 8px; }
.rm-dp .flatpickr-current-month .cur-month { font-weight: 750; }
.rm-dp .flatpickr-current-month input.cur-year { font-weight: 700; }
.rm-dp .flatpickr-prev-month, .rm-dp .flatpickr-next-month { position: relative !important; height: 34px; width: 34px; padding: 0 !important; margin: 0 2px;
  display: flex; align-items: center; justify-content: center; border-radius: 10px; color: #334155; top: 0 !important; }
.rm-dp .flatpickr-prev-month:hover, .rm-dp .flatpickr-next-month:hover { background: rgba(13,110,253,.08); color: var(--primary, #0d6efd); }
.rm-dp .flatpickr-prev-month svg, .rm-dp .flatpickr-next-month svg { width: 12px; height: 12px; fill: currentColor; }
.rm-dp .flatpickr-prev-month.flatpickr-disabled, .rm-dp .flatpickr-next-month.flatpickr-disabled { opacity: .25; }
.rm-dp span.flatpickr-weekday { color: #94a3b8; font-weight: 700; font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; }
.rm-dp .flatpickr-innerContainer, .rm-dp .flatpickr-rContainer, .rm-dp .flatpickr-days, .rm-dp .dayContainer { width: 306px; min-width: 306px; max-width: 306px; }
.rm-dp .flatpickr-day { border-radius: 12px; height: 40px; line-height: 38px; max-width: 42px; margin: 1px 0; font-weight: 600; color: #1e293b; border: 1px solid transparent; }
.rm-dp .flatpickr-day:hover, .rm-dp .flatpickr-day:focus { background: rgba(13,110,253,.08); border-color: transparent; }
.rm-dp .flatpickr-day.today { border-color: var(--primary, #0d6efd); color: var(--primary, #0d6efd); }
.rm-dp .flatpickr-day.selected, .rm-dp .flatpickr-day.selected:hover { background: var(--primary, #0d6efd); border-color: var(--primary, #0d6efd); color: #fff;
  box-shadow: 0 6px 14px rgba(13,110,253,.35); }
.rm-dp .flatpickr-day.prevMonthDay, .rm-dp .flatpickr-day.nextMonthDay { color: #cbd5e1; }
.rm-dp .flatpickr-day.flatpickr-disabled, .rm-dp .flatpickr-day.flatpickr-disabled:hover { color: #d7dde5; background: transparent; cursor: not-allowed; text-decoration: line-through; text-decoration-color: rgba(148,163,184,.5); }
.rm-dp .flatpickr-time { border-top: 1px solid rgba(15,23,42,.06); margin-top: 6px; border-radius: 0 0 12px 12px; }
.rm-dp .flatpickr-time input, .rm-dp .flatpickr-time .flatpickr-am-pm { font-weight: 700; }
.rm-dp-foot { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: 8px 4px 2px; border-top: 1px solid rgba(15,23,42,.06); margin-top: 6px; }
.rm-dp-foot .rm-dp-sel { font-size: .78rem; color: #64748b; }
.rm-dp-foot button { border: 0; background: rgba(13,110,253,.08); color: var(--primary, #0d6efd); font-weight: 700; font-size: .8rem; padding: .35rem .8rem; border-radius: 999px; }
.rm-dp-foot button:hover { background: var(--primary, #0d6efd); color: #fff; }
.rm-dp-foot button:disabled { opacity: .4; pointer-events: none; }
body.dark-mode .flatpickr-calendar.rm-dp { background: #1e293b; border-color: #334155; box-shadow: 0 22px 48px rgba(0,0,0,.45); }
body.dark-mode .rm-dp .flatpickr-months .flatpickr-month, body.dark-mode .rm-dp .flatpickr-current-month, body.dark-mode .rm-dp .flatpickr-day { color: #e2e8f0; fill: #e2e8f0; }
body.dark-mode .rm-dp .flatpickr-prev-month, body.dark-mode .rm-dp .flatpickr-next-month { color: #cbd5e1; }
body.dark-mode .rm-dp .flatpickr-day.prevMonthDay, body.dark-mode .rm-dp .flatpickr-day.nextMonthDay, body.dark-mode .rm-dp .flatpickr-day.flatpickr-disabled { color: #475569; }
body.dark-mode .rm-dp .flatpickr-time input, body.dark-mode .rm-dp .flatpickr-time .flatpickr-am-pm, body.dark-mode .rm-dp .flatpickr-time .flatpickr-time-separator { color: #e2e8f0; }
@media (prefers-reduced-motion: reduce) { .flatpickr-calendar.rm-dp.open { animation: none; } }
</style>
<script>
// Set a date from script and keep the picker in step (falls back to the plain field)
window.rmSetDate = function (el, value) {
    if (!el) return;
    if (el._flatpickr) { el._flatpickr.setDate(value || null, true); } else { el.value = value || ''; }
};
(function () {
    if (!window.flatpickr) return; // CDN blocked: the browser's own date field stays
    const pad = n => String(n).padStart(2, '0');
    const ymd = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    function init(el) {
        if (el._flatpickr || el.dataset.noPicker !== undefined) return;
        const withTime = el.type === 'datetime-local';
        const req = el.required, style = el.style.cssText, cls = el.className, dis = el.disabled;
        const fp = flatpickr(el, {
            altInput: true,
            altFormat: withTime ? 'D, d M Y · h:i K' : 'D, d M Y',
            dateFormat: withTime ? 'Y-m-d\\TH:i' : 'Y-m-d',
            enableTime: withTime,
            minDate: el.min || null,
            maxDate: el.max || null,
            defaultDate: el.value || null,
            disableMobile: true,
            monthSelectorType: 'static',
            locale: { firstDayOfWeek: 1 },
            prevArrow: '<svg viewBox="0 0 16 16"><path d="M11.4 1.6 4.9 8l6.5 6.4-1.2 1.2L2.5 8l7.7-7.6z"/></svg>',
            nextArrow: '<svg viewBox="0 0 16 16"><path d="M4.6 14.4 11.1 8 4.6 1.6 5.8.4 13.5 8l-7.7 7.6z"/></svg>',
            onReady: function (sel, str, inst) {
                inst.calendarContainer.classList.add('rm-dp');
                const alt = inst.altInput;
                alt.className = cls + ' rm-date-input';
                alt.style.cssText = style;
                alt.required = req;
                alt.disabled = dis;
                alt.placeholder = withTime ? 'Choose date & time' : 'Choose a date';
                alt.setAttribute('aria-label', (document.querySelector('label[for="' + el.id + '"]') || {}).textContent || 'Date');
                const foot = document.createElement('div');
                foot.className = 'rm-dp-foot';
                foot.innerHTML = '<span class="rm-dp-sel"></span><button type="button">Today</button>';
                const today = ymd(new Date());
                const btn = foot.querySelector('button');
                btn.disabled = (el.min && today < el.min) || (el.max && today > el.max);
                btn.addEventListener('click', () => { inst.setDate(new Date(), true); inst.close(); });
                inst.calendarContainer.appendChild(foot);
                const show = () => { foot.querySelector('.rm-dp-sel').textContent = inst.selectedDates[0] ? inst.formatDate(inst.selectedDates[0], 'l, j F Y') : ''; };
                show();
                inst.config.onChange.push(show);
            }
        });
        return fp;
    }
    document.querySelectorAll('input[type="date"], input[type="datetime-local"]').forEach(init);
})();
</script>
HTML;
}


/** Inspection form / report card (Move-In step 5, Move-Out Inspection Report): one shared look. */
function re_form_card_css(): string
{
    return <<<'CSS'
/* Inspection form card */
.rmf-row { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
.rmf-link { font-size: .82rem; text-decoration: none; }
.rmf-chips { display: flex; flex-wrap: wrap; gap: .4rem; margin-bottom: .7rem; }
.rmf-chip { display: inline-flex; align-items: center; gap: .35rem; padding: .32rem .75rem; border-radius: 999px; border: 1px solid var(--rm-border); background: var(--rm-surface); font-size: .82rem; font-weight: 600; cursor: pointer; user-select: none; transition: all .15s ease; }
.rmf-chip input { position: absolute; opacity: 0; pointer-events: none; }
.rmf-chip .bi { display: none; }
.rmf-chip em { font-style: normal; font-size: .72rem; padding: 0 .4rem; border-radius: 999px; background: var(--rm-surface-2); color: var(--rm-muted); }
.rmf-chip:has(input:checked) { background: rgba(13,110,253,.08); border-color: var(--primary); color: var(--primary); }
.rmf-chip:has(input:checked) .bi { display: inline; }
.rmf-chip:has(input:focus-visible) { outline: 2px solid var(--primary); outline-offset: 2px; }
.rmf-chip.is-off { opacity: .45; cursor: not-allowed; }
.rmf-step { display: flex; gap: .85rem; padding: .9rem 0; border-top: 1px dashed var(--rm-border); }
.rmf-step:first-of-type { border-top: 0; padding-top: .1rem; }
.rmf-step-n { width: 26px; height: 26px; border-radius: 50%; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; font-size: .78rem; font-weight: 700; background: rgba(13,110,253,.1); color: var(--primary); }
.rmf-step-n.is-or { background: var(--rm-surface-2); color: var(--rm-muted); font-size: .66rem; text-transform: uppercase; }
.rmf-step-b { flex: 1; min-width: 0; }
.rmf-step-t { font-weight: 650; margin-bottom: .5rem; }
.rmf-signed { display: flex; align-items: center; gap: .9rem; flex-wrap: wrap; padding: 1rem 1.1rem; border-radius: 14px; background: linear-gradient(120deg, #f0fdf4, var(--rm-surface)); border: 1px solid #bbf7d0; }
.rmf-signed-ic { width: 46px; height: 46px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: #16a34a; background: #fff; box-shadow: 0 6px 14px rgba(22,163,74,.15); flex-shrink: 0; }
.rmf-signed-txt { min-width: 0; }
.rmf-signed-t { font-weight: 700; }
body.dark-mode .rmf-signed { background: rgba(22,163,74,.12); border-color: rgba(22,163,74,.4); }
body.dark-mode .rmf-signed-ic { background: var(--rm-surface); }
.rmf-send { margin-top: 1rem; }
.rmf-send-t { font-weight: 650; margin-bottom: .45rem; }
.rmf-more { margin-top: 1rem; border: 1px solid var(--rm-border); border-radius: 12px; }
.rmf-more > summary { list-style: none; cursor: pointer; display: flex; align-items: center; gap: .5rem; padding: .65rem .9rem; font-weight: 600; font-size: .9rem; color: var(--rm-muted); }
.rmf-more > summary::-webkit-details-marker { display: none; }
.rmf-more[open] > summary .bi-chevron-down { transform: rotate(180deg); }
.rmf-more-b { padding: 0 .9rem .9rem; }
.rmf-sub { font-size: .7rem; text-transform: uppercase; letter-spacing: .06em; font-weight: 700; color: var(--rm-muted); margin: .9rem 0 .45rem; }
.rmf-file { display: flex; align-items: center; gap: .55rem; padding: .45rem 0; border-top: 1px dashed var(--rm-border); font-size: .88rem; }
.rmf-file > .bi { color: #16a34a; }
@media (max-width: 575.98px) { .rmf-signed .rmf-row { margin-left: 0 !important; width: 100%; } .rmf-signed .rmf-row .btn-success { flex: 1; } }
CSS;
}
