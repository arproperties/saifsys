# Workflow UX Review

**Stage:** 2  
**Date:** 2026-07-10  
**Policy:** Invoice Mode = official RE accounting. Legacy = historical only (do not extend).  
**Safeguard:** Do not invent universal business rules. Unclear policy → Needs business confirmation.

## Layer model for Real Estate money flows

For payment-related RE workflows, these layers are **not** assumed identical:

1. Commercial agreement (lease terms, fees, units)  
2. Cheque / operational payment schedule  
3. Invoice or obligation (accounting recognition documents)  
4. Receipt  
5. Allocation  
6. Accounting journal  
7. Bank reconciliation  
8. Reporting effect  

---

## Real Estate workflows

### RE-W01 — Tenant creation and selection
| Field | Content |
|-------|---------|
| User goal | Create/find tenant for leasing |
| Current steps | Tenants list → add/view → select on lease form (often Select2) |
| Major interactions | ~3–6 |
| Confusion | Long tenant_view mixes IM + legacy financials |
| Duplicate entry | Contact/ID may reappear on lease |
| Error risk | Medium — wrong tenant selected |
| Accounting | Indirect |
| Permissions | RE module/department |
| Future workflow | Clear search → tenant card → “New lease” CTA; financial summary mode-aware |
| Acceptance | Can create tenant and start lease without opening unrelated tabs |
| Needs confirmation | Required KYC fields; multi-company tenant uniqueness |

### RE-W02 — Unit / property navigation
| Field | Content |
|-------|---------|
| User goal | Find building/unit availability |
| Current steps | Buildings → floors/units lists (often unbounded) |
| Major interactions | ~3–5 |
| Confusion | Occupancy vs lease vs ARS booking overlays |
| Error risk | Medium — wrong unit on multi-unit leases |
| Future | Paginated property tree + availability badge |
| Needs confirmation | Rules for multi-unit / parking / shop on one lease vs multiple leases |

### RE-W03 — Lease creation (Invoice Mode official)
| Field | Content |
|-------|---------|
| User goal | Capture commercial agreement + schedules |
| Current steps | `lease_add.php` multi-card: mode → basic → rent → fees → extras → **operational cheque schedule** → contract → preview |
| Major interactions | **15+** (very high) |
| Confusion | Accounting mode banner; deferred disabled in IM; fee distribution vs cheque schedule |
| Duplicate entry | Fees may appear in commercial terms and cheque schedule |
| Error risk | **High** — long form; mode override |
| Accounting consequences | Mode drives later invoice/receipt paths |
| Permissions | RE + possible mode override policy (`accounting_mode_helper`) |
| Future | Wizard: (1) parties/units (2) commercial terms (3) fee treatment options (4) operational schedule (5) review — without removing supported flexibility |
| Acceptance | New leases default Invoice Mode; user can complete without legacy concepts |
| Needs confirmation | Which fee treatments are allowed (included in rent cheque / separate cheque / separate invoice / other); allowed cheque counts (1/4/6/12/other) — **do not freeze to one rule** |

### RE-W04 — Multiple leases per tenant
| Field | Content |
|-------|---------|
| User goal | Manage several active leases (units/shops/parking/etc.) |
| Current steps | `tenant_view.php` leases table with mode badges |
| Supported scenarios | Multiple leases appear supported in UI/data model | Confirmed from code / Strong inference |
| Confusion | Combined financial widgets |
| Error risk | Applying receipt to wrong lease |
| Future | Lease switcher on receipt/allocation; per-lease AR cards |
| Needs confirmation | Cross-lease allocation / credit sharing policy — **do not invent** |

### RE-W05 — Invoice Mode lease setup vs operational schedule
| Field | Content |
|-------|---------|
| Distinction | Commercial + cheque schedule ≠ automatic accounting recognition |
| Current | Obligations/candidates/invoices separate from cheque UI |
| Confusion | Staff may think clearing a cheque = invoice posted |
| Future | Explicit UI copy: “Operational schedule” vs “Accounting documents” |
| Needs confirmation | When obligations are generated relative to lease activation |

### RE-W06 — Invoice generation (IM)
| Field | Content |
|-------|---------|
| User goal | Issue AR invoice from candidates/obligations |
| Current steps | Preview/generate → `post_invoice_to_accounting` |
| Layers | Obligation → Invoice → Journal |
| Confusion | Candidate vs issued vs paid |
| Error risk | Medium |
| Future | Status pipeline visible on lease financial tab |
| Needs confirmation | Auto-issue vs manual issue policies |

### RE-W07 — Receipt creation and allocation (IM)
| Field | Content |
|-------|---------|
| User goal | Record cash/cheque clear and allocate |
| Current steps | `receipt_allocation.php` → preview → confirm → journal |
| Layers | Receipt → Allocation → Journal (+ optional tenant credit / SD) |
| Major interactions | ~5–8 |
| Confusion | Targets mix invoices/obligations/credit |
| Error risk | **High** if wrong lease/targets |
| Future | Lease-locked allocation; clearer remaining-to-allocate |
| Needs confirmation | Partial allocation rules; overpay → credit always? |

### RE-W08 — Tenant credit
| Field | Content |
|-------|---------|
| User goal | Track overpayments / apply to invoices |
| Current | Credit tables + apply helper; refund path has known technical debt |
| Layers | Credit balance ≠ bank reco |
| Needs confirmation | Refund approval; cross-lease apply |

### RE-W09 — Security deposits
| Field | Content |
|-------|---------|
| User goal | Collect/hold/settle deposit |
| Current | IM obligation+receipt liability path; lease-create post exists (historical/alternate) |
| Layers | Agreement amount → obligation/receipt → journal 2200 → move-out settlement |
| Confusion | Multiple posting paths |
| Needs confirmation | When deposit is due; cash vs cheque; deductions policy |

### RE-W10 — PDC register / clear / bounce / replace
| Field | Content |
|-------|---------|
| User goal | Manage cheques operationally and clear into accounting when appropriate |
| Current | Lifecycle helper; IM clear → receipt allocation; legacy clear may lack GL |
| Layers | Schedule (2) → Clear → Receipt (4) → Allocation (5) → Journal (6) → later Bank reco (7) |
| Confusion | Status machine vs accounting documents |
| Error risk | **High** on legacy clear |
| Future | IM-only clear via receipt; legacy labeled Historical |
| Needs confirmation | Bounce penalty amounts/timing; replacement vs rebook |

### RE-W11 — Renewal / move-out / collections / maintenance / legal
| Workflow | UX note | Needs confirmation |
|----------|---------|-------------------|
| Renewal | Mode defaults for renewals in settings | What carries forward (fees, deposits, cheques) |
| Move-out + deposit | Settlement helper + refunds | Deduction approval roles |
| Outstandings / collections | Combined IM+legacy report; alert crons | Which balances drive collections |
| Maintenance | Request lists/modals | SLA / billing link to IM |
| Legal escalation | Legal module + cheque links | When escalation is mandatory |

---

## Construction workflows

| ID | Workflow | Steps (approx) | Confusion / risk | Accounting | Needs confirmation |
|----|----------|----------------|------------------|------------|-------------------|
| CO-W01 | Supplier invoice | Add → lines → open/post | VAT/account selection | `co_post_supplier_invoice_*` | Approval before post? |
| CO-W02 | Supplier payment | Pay → allocate bills | Partial alloc | Payment journal | — |
| CO-W03 | Contractor payment | Add → post | GL may fail while row saves | CIP/retention | Fail-closed policy |
| CO-W04 | Project expenses / ERP expense | Separate stacks | Which screen to use | Shared/ERP vs CO | When to use which |
| CO-W05 | Petty cash | **Not found** as module | Informal | COA only | Is petty cash required? |
| CO-W06 | Bank reconciliation | Import → match/create → confirm | Parallel to RE reco UX | Flags / create JV | Approval to create JV |

---

## Cleaning workflows

| ID | Workflow | Notes | Accounting | Needs confirmation |
|----|----------|-------|------------|-------------------|
| CL-W01 | Booking → WO → assign → complete | Ops-heavy lists; session filters | Later invoice | Cancellation rules |
| CL-W02 | Invoice → receipt | Accounts tabs; role-gated | `gl_post_*` | Edit after post |
| CL-W03 | Cancellation / adjustment | Adjustment approve services exist | CN / adjustments | Who can approve |

---

## HR workflows

| ID | Workflow | Notes | Accounting | Needs confirmation |
|----|----------|-------|------------|-------------------|
| HR-W01 | Employee setup | HR layout + pills | — | — |
| HR-W02 | Attendance | Lists/reports | — | — |
| HR-W03 | Payroll prepare → approve → post | Dual GL routing by company type | `hr_payroll_post_accounting` | Maker/checker for post |

---

## Inventory workflows

| ID | Workflow | Notes | Accounting | Needs confirmation |
|----|----------|-------|------------|-------------------|
| INV-W01 | MR → approve → issue/return/transfer/adjust | Cross-module MR entry points | Stock ledger only (no GL found) | Valuation to GL needed? |

---

## Cross-cutting UX risks

1. **Operational schedule confused with accounting documents** (RE).  
2. **Unbounded list pages** slow workflows (leases/units/tenants).  
3. **Permission models differ** (role vs module vs permission) — users see/hide inconsistently.  
4. **Legacy paths still visible** — must be labeled Historical, not removed in this stage.

---

## Explicit non-changes

No workflows redesigned or implemented. No business rules declared as universal.
