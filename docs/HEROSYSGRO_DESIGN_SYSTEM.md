# HeroSysgro Design System (Proposed)

**Stage:** 2 — Design proposal only. **Not implemented.**  
**Stack constraint:** Native PHP + Bootstrap 5.3.3 + existing module layouts. **No React/Vue/SPA rewrite.**

## Brand evidence (do not invent)

From `includes/branding.php` defaults (Confirmed from code):

| Token | Default value |
|-------|---------------|
| `--primary` | `#7a0000` |
| `--primary-light` | `#910c0c` |
| `--primary-dark` | `#600000` |
| `--accent` | `#ffd86a` |
| System name | `BMSystem` / short `BM` |

**Requires approval (not brand defaults):** Module-specific palettes (ARS teal, Grocery green, Barber charcoal/gold, Legal navy/gold, Tenant portal blue, Stay teal, select-module purple gradient). Treat as **optional vertical accents** until product approves unification.

Semantic Bootstrap defaults commonly hardcoded in layouts: success `#28a745`, warning `#ffc107`, danger `#dc3545`, info `#17a2b8`.

---

## 1. Color tokens

| Purpose | Token | Usage rules | Prohibited |
|---------|-------|-------------|------------|
| Brand primary | `--hs-primary` ← branding | Chrome, primary buttons, active nav | Random per-page hex |
| Accent | `--hs-accent` | Highlights, badges sparingly | Large text blocks |
| Surface | `--hs-bg`, `--hs-card` | Page/card backgrounds | Low-contrast gray-on-gray |
| Text | `--hs-text`, `--hs-muted` | Body / secondary | Light gray on light bg |
| Status | success/warning/danger/info | Status only + icon/text | Color-only meaning |
| Vertical accent | `--hs-vertical-*` | Module marketing chrome only | Overriding financial status colors |

**Desktop/Mobile:** Same tokens. **A11y:** Contrast ≥ 4.5:1 for text; status never color-only.

---

## 2. Typography

| Role | Approach |
|------|----------|
| UI sans | Prefer one staff font (Inter already on `select-module.php`) — **Needs approval** before global change |
| Sizes | Bootstrap type scale; page title ~1.25–1.5rem semibold |
| Financial figures | Tabular nums (`font-variant-numeric: tabular-nums`); right-align amounts |
| Prohibited | Mixing >2 display fonts on staff chrome |

---

## 3. Spacing & grid

| Rule | Value |
|------|-------|
| Base | 8px scale (Bootstrap spacer) |
| Page padding | `container` or `container-fluid` consistently per module class |
| Card gap | `g-3` / `mb-3` |
| Prohibited | One-off magic margins |

---

## 4–6. Layout, page headers, navigation

| Component | Purpose | Variants | Rules | Mobile |
|-----------|---------|----------|-------|--------|
| App shell | Sidebar + topbar | Full / collapsed | One shell pattern per product area (staff vs portal) | Off-canvas drawer required |
| Page header | Title + actions | With/without breadcrumb | Primary action right; destructive never primary | Stack actions |
| Nav | Module RBAC groups | Collapsible sections | Active state clear; Switch Module available | Hamburger |

**Bootstrap approach:** Reuse Construction drawer pattern; `nav-link active`; sticky sidebar.

---

## 7–10. Cards, tables, filters, forms

| Component | Purpose | Allowed | Prohibited | Mobile |
|-----------|---------|---------|------------|--------|
| Card | Section container | `card` + optional `card-round` | Cards inside hero-like marketing on staff tools | Full width |
| Table | Data density | `table-sm table-hover` + `table-responsive` | Unbounded huge tables without pagination | Card-list optional later |
| Filter bar | Narrow lists | One card; GET; clear button | Filters scattered mid-page | Stack fields |
| Form | Data entry | Bootstrap grid; grouped fieldsets | 20+ fields without section anchors | Full-width inputs |

---

## 11–14. Inputs, selects, dates, currency

| Field | Rules | A11y |
|-------|-------|------|
| Input | `form-control`; visible `<label>` | `for`/`id` |
| Select | `form-select`; Select2 only when search needed | Keep label |
| Date | `type="date"` or consistent picker | Format hint |
| Currency | Right-align; company currency label visible; 2 dp default | `aria-label` includes currency |

**Prohibited:** Placeholder-as-label; unlabeled Select2.

---

## 15–19. Buttons, dropdowns, tabs, modals, confirmations

| Component | Variants | Rules |
|-----------|----------|-------|
| Button | primary / secondary / outline / danger | One primary per view; danger for irreversible |
| Dropdown | Bootstrap dropdown | Destructive items separated |
| Tabs | `nav-tabs` or pills | Don’t hide critical money actions only in tabs without deep link |
| Modal | `modal fade` | Focus trap; Esc; labelled title |
| Confirm | Modal or `confirm` for low risk only | Posted financial actions need typed confirm / permission |

---

## 20–22. Alerts, toasts, badges

| Component | Rules |
|-----------|-------|
| Alert | `alert-dismissible`; server flash OK |
| Toast | Optional for non-blocking success; not for failures needing action |
| Badge | Status + text; map statuses consistently per domain |

---

## 23–26. Empty, loading, errors, pagination

| State | Pattern |
|-------|---------|
| Empty | Icon + message + primary CTA |
| Loading | `spinner-border` + `aria-busy` on region |
| Error | Alert + recovery action |
| Pagination | Bootstrap pagination; show page size; always for lists >50 |

---

## 27–29. Audit timelines, financial summaries, journals

| Component | Purpose | Rules |
|-----------|---------|-------|
| Audit timeline | Chronological events | User, time, action, company |
| Financial summary | KPI strip | Company name; as-of date; mode (IM/Legacy) |
| Journal display | Dr/Cr lines | Balanced totals; posted/reversed badges; link to source |

See also `FINANCIAL_UI_STANDARDS.md`.

---

## 30–32. Mobile, print/PDF, accessibility

| Area | Standard |
|------|----------|
| Mobile | Drawer nav; filters stack; tables scroll; touch ≥44px (tenant portal already defines `--tp-touch`) |
| Print | Hide nav; show company + document title |
| PDF | Brand header from `branding.php`; consistent footer |
| A11y | See `ACCESSIBILITY_AND_RESPONSIVE_STANDARDS.md` |

---

## Example Bootstrap implementation approach (future)

```html
<!-- Page header -->
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <div class="text-muted small">Breadcrumb</div>
    <h1 class="h4 mb-0 page-header-label">Title</h1>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="...">Secondary</a>
    <a class="btn btn-primary btn-sm" href="...">Primary</a>
  </div>
</div>
```

**Do not implement in Stage 2.** Adopt gradually when UI work is approved.
