# Phase 3A — ARS Design System

**Stack approach:** Tailwind utility tokens + Alpine interactions + Lucide + PHP partials under `modules/ars/views/components/` (proposed).  
**No business logic in browser.** Amounts from server JSON / PHP-rendered summaries.

---

## Shell & chrome

| Component | Purpose | Variants | Alpine | Notes |
|-----------|---------|----------|--------|-------|
| App shell | Layout frame | Collapsed / expanded sidebar | `x-data` sidebar | Replace ars-sidebar gradually |
| Sidebar | Primary nav | Role-filtered | — | Sections match IA |
| Top bar | Search, alerts, quick-create, user | Dense | Dropdowns | |
| Page header | Title, breadcrumbs, primary CTA | With/without meta | — | |
| Action toolbar | Contextual actions | Sticky | — | Danger actions separated |

## Data display

| Component | Purpose | States |
|-----------|---------|--------|
| KPI card | Metric + delta | Loading / empty / alert |
| Alert card | Actionable urgency | Critical / warn / info |
| Status badge | Booking / payment / HK / maint | Icon+label; locked |
| Table / data grid | Lists | Sort, sticky header, row actions |
| Filters bar | Facets | Collapsed mobile |
| Timeline / activity item | Chronology | Expand note |

## Forms & selectors

| Component | Purpose |
|-----------|---------|
| Form field / group | Labels, errors, help |
| Guest selector | Search + create |
| Unit selector | Availability-aware |
| Date range | Accessible; conflict warn |
| Price summary | Server totals only |
| Payment summary | Due / paid / credit |
| Financial document summary | Doc #, type, status, JV link |

## Overlays

| Component | Purpose |
|-----------|---------|
| Drawer | Primary for pay/deposit/services on desktop |
| Modal | Confirmations & short forms |
| Confirm dialog | Destructive / financial post |
| Toast | Non-blocking success |
| Command palette | Global search |

## Feedback

Empty states, skeletons, error banners, permission-disabled (tooltip why), financially-locked (lock + amend path).

## Sizes

`sm` / `md` / `lg` for buttons, badges, inputs. Touch targets ≥ 44px on mobile.

## Tailwind approach

- Scoped ARS entry CSS with `@tailwind` layers OR CDN for prototype only; production prefer build.  
- Prefix utilities with `ars-` custom classes where Bootstrap coexistence needed.  
- Design tokens in `modules/ars/assets/ars-tokens.css`.

## Legacy replacement map

| Legacy | Future |
|--------|--------|
| `.ars-stat-card` | KPI card |
| Bootstrap modals for money | Drawer + confirm |
| Dense booking cards | Workspace regions |
| BI icons | Lucide (gradual) |

## Accessibility

See `PHASE3A_ACCESSIBILITY_STANDARD.md`. Every interactive component documents focus, aria, keyboard.
