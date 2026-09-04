# UI / UX Architecture Audit

**Stage:** 2  
**Date:** 2026-07-10  
**Scope:** Read-only. No UI implementation.

## Evidence labels

Confirmed from code | Strong inference | Needs business confirmation | Not found

---

## 1. Global architecture

| Finding | Detail | Evidence |
|---------|--------|----------|
| Current pattern | Per-module layout PHP (`*_layout_header.php` / footer), not one shared design system | Confirmed from code |
| Bootstrap | **5.3.3** CDN + Bootstrap Icons 1.11.x on most staff modules; tenant portal uses `bootstrap@5.3` | Confirmed from code |
| Branding source | `includes/branding.php` — defaults: primary `#7a0000`, light `#910c0c`, dark `#600000`, accent `#ffd86a`, system name `BMSystem` | Confirmed from code |
| Root chrome | `includes/header.php` / `footer.php` empty | Confirmed from code |
| Module picker | `select-module.php` — Inter font; **purple gradient** `#667eea→#764ba2` (not brand maroon) | Confirmed from code |

---

## 2. Findings catalogue

### F-UI-01 — Fragmented layout packages
| Field | Content |
|-------|---------|
| Current pattern | Each module copies sidebar/CSS/nav |
| Paths | `modules/*/includes/*_layout_*.php`, `hr/includes/hr_layout_*.php`; Accounts chrome in `account.php`; Operation partials `operation/includes/cleaning_ui_*.php` |
| Modules | All staff modules + portals |
| Strength | Module autonomy; RBAC-aware nav |
| Weakness | Drift in spacing, mobile nav, title classes, card styles |
| User impact | Inconsistent “feel” across modules; harder training |
| Recommendation | Shared layout partial + module theme tokens (later); keep module nav content |
| Risk of change | Medium (wide touch) |
| Priority | P1 |

### F-UI-02 — Divergent color systems
| Field | Content |
|-------|---------|
| Current pattern | Brand maroon vs Grocery green `#1e5f3f` vs Barber `#1e2430/#c9a227` vs ARS teal `#0d9488` vs Legal navy/gold vs Tenant `#0f4c75` vs Stay `#00897B` vs select-module purple |
| Paths | `branding.php`, `ars_styles.css`, `legal.css`, `tenant_portal.css`, `portal_styles.css`, grocery/barber headers, `select-module.php` |
| Strength | Vertical product identity (POS/hospitality) |
| Weakness | Weak commercial “one ERP” brand |
| User impact | Brand confusion when switching modules |
| Recommendation | Keep vertical accents as **secondary** tokens; unify chrome primary from branding; approve portal tokens separately |
| Risk of change | Medium (branding approval needed) |
| Priority | P2 |

### F-UI-03 — Mobile navigation gaps
| Field | Content |
|-------|---------|
| Current pattern | Construction/ARS/Legal/portals have drawers or responsive nav; **RE, HR, Grocery, Barber, Inventory, account.php** weak or missing off-canvas |
| Paths | `re_layout_header.php` (no hamburger found); `construction_layout_header.php` (mobile drawer); `ars_layout_header.php` |
| Strength | Some modules already solved mobile |
| Weakness | RE (largest module) poor on phone |
| User impact | Field/manager mobile use painful for RE |
| Recommendation | Port Construction-style drawer into RE/HR/accounts shells |
| Risk of change | Low–Medium |
| Priority | P0 (RE), P1 (others) |

### F-UI-04 — Inconsistent page headers
| Field | Content |
|-------|---------|
| Current pattern | Mix of `page-header-label`, `h1`, `h4`; title suffixes vary |
| Paths | RE layouts + `billing_cheque_view.php` (`h1`); grocery/inventory `page-header-label` |
| Strength | Pages usually have a title |
| Weakness | No standard breadcrumb/actions row |
| User impact | Harder to scan actions (Back / New / Export) |
| Recommendation | Standard page header: title + optional breadcrumb + primary/secondary actions |
| Risk of change | Low |
| Priority | P1 |

### F-UI-05 — Cards / tables / filters mostly Bootstrap-native
| Field | Content |
|-------|---------|
| Current pattern | `card` / `card-round`, `table table-sm table-hover`, `table-responsive`, GET filter forms `row g-3` |
| Paths | Widespread RE/Construction/accounts |
| Strength | Familiar, fast to build |
| Weakness | Filter bars and empty states ad hoc; `card-round` not universal |
| User impact | Uneven density and empty messaging |
| Recommendation | Design-system filter bar + empty state partial |
| Risk of change | Low |
| Priority | P2 |

### F-UI-06 — Empty / loading / error states ad hoc
| Field | Content |
|-------|---------|
| Current pattern | “No … found” table rows; occasional `spinner-border` (accounts AJAX) |
| Paths | `tenants.php`, accounts reports AJAX |
| Strength | Something shown |
| Weakness | No shared empty/loading/error components |
| User impact | Uncertainty during slow reports |
| Recommendation | Shared empty/loading/error patterns in design system |
| Risk of change | Low |
| Priority | P2 |

### F-UI-07 — Accounts/Operation chrome not reusable
| Field | Content |
|-------|---------|
| Current pattern | `account.php` hosts tabs; many `accounts/*` fragments; standalone pages re-declare Bootstrap |
| Paths | `account.php`, `accounts/expense_add.php`, `accounts/coa.php`, `operation/workorder_list.php` |
| Strength | Dense finance/ops tooling |
| Weakness | Duplicate asset loads; harder consistency |
| User impact | Slightly slower loads; visual drift |
| Recommendation | Extract cleaning layout header/footer like other modules |
| Risk of change | Medium |
| Priority | P1 |

### F-UI-08 — Portal UX separate (appropriate) but token drift
| Field | Content |
|-------|---------|
| Current pattern | Tenant top-nav; Stay hospitality navbar; touch target `--tp-touch: 44px` |
| Paths | `tenant_portal/includes/*`, `stay/includes/*` |
| Strength | Customer-facing simplicity |
| Weakness | Not aligned with staff brand tokens |
| User impact | Acceptable if intentional |
| Recommendation | Document portal brand as approved variants; Needs business confirmation for unification |
| Risk of change | Medium |
| Priority | P3 |

### F-UI-09 — Real Estate dual-mode UI complexity
| Field | Content |
|-------|---------|
| Current pattern | Invoice Mode vs Legacy badges/sections on lease, tenant, cheque, payments, outstandings |
| Paths | `lease_add.php`, `tenant_view.php`, `billing_cheque_view.php`, `lease_payments_manage.php`, `outstandings_report.php` |
| Strength | Supports historical leases without data loss |
| Weakness | Cognitive load; risk of using wrong path |
| User impact | Training burden; error risk on payments/PDC |
| Recommendation | Official UI: Invoice Mode primary; Legacy clearly labeled Historical; do not extend legacy UI |
| Risk of change | Low–Medium (policy/UI labeling) |
| Priority | P0 |

### F-UI-10 — Long single-scroll detail pages
| Field | Content |
|-------|---------|
| Current pattern | `tenant_view.php`, `lease_add.php` — many cards, few tabs |
| Strength | All data visible |
| Weakness | Overwhelming; easy to miss sections |
| User impact | Missed fields; slow completion |
| Recommendation | Future: sticky section nav / tabs without removing fields; Needs business confirmation on grouping |
| Risk of change | Medium |
| Priority | P1 |

### F-UI-11 — Print/PDF consistency
| Field | Content |
|-------|---------|
| Current pattern | RE layout hides sidebar on print; mPDF/Dompdf for contracts/invoices |
| Paths | `re_layout_header.php` print CSS; `contract_pdf_generator.php`; `accounts/invoice_print_pdf.php` |
| Strength | Printable staff screens; dedicated PDFs |
| Weakness | Template styling varies by document |
| User impact | Customer-facing docs look uneven |
| Recommendation | Shared PDF header/footer tokens from branding |
| Risk of change | Medium |
| Priority | P2 |

### F-UI-12 — Accessibility baseline weak
| Field | Content |
|-------|---------|
| Current pattern | Bootstrap defaults; status often color-only badges; modals present |
| Paths | Module layouts generally |
| Strength | Semantic Bootstrap components available |
| Weakness | Labels/focus/ARIA inconsistent (see accessibility doc) |
| User impact | Keyboard/screen-reader friction |
| Recommendation | Adopt accessibility standards doc |
| Risk of change | Low per page |
| Priority | P1 |

---

## 3. Module shell summary

| Module | Layout files | Shell type | Mobile nav | Brand source |
|--------|--------------|------------|------------|--------------|
| Real Estate | `re_layout_*` | Sidebar + top | Weak | Branding |
| Construction | `construction_layout_*` | Sidebar + top | Drawer | Branding + module CSS |
| ARS | `ars_layout_*` | Custom sidebar | Hamburger | ARS tokens |
| Grocery / Barber / Inventory | `*_layout_*` | Sidebar-only | Weak | Module overrides |
| Legal | `legal_layout_*` | Dark sidebar | Toggle | Legal CSS |
| Tasks | `tasks_layout_*` | Topbar only | Stack | Brand / fallback blue |
| HR | `hr_layout_*` | Sidebar + pills | Weak | Branding |
| Accounts | `account.php` | Inline | Tab dropdown | Branding |
| Operation | Partial / inline | Mixed | Mixed | Branding |
| Tenant / Stay | Portal layouts | Top nav | Better | Portal tokens |

---

## 4. Explicit non-changes

No screens redesigned or implemented in Stage 2.
