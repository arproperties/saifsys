# ERP Decisions Log

**Purpose:** Permanent architectural memory for HeroSysgro ERP.  
**Rule:** New architectural decisions must be appended here before major shared-code or accounting changes.  
**Created:** 2026-07-10 (Stage 1.5)

Status values: `Accepted` | `Proposed` | `Superseded` | `Deprecated`

---

## DEC-001 — Cleaning accounting remains separate
| Field | Value |
|-------|-------|
| Date | 2026-07-10 |
| Status | Accepted |
| Reason | Cleaning uses standalone `chart_of_accounts` + `gl_*` via `includes/gl_posting.php` and `cleaning_accounting_context.php`. Mixing with shared `re_*` would corrupt historical Cleaning books. |
| Consequences | Two ledger stacks forever unless a formal multi-year migration is approved. All Cleaning finance UI under `/accounts`. |
| Related modules | Cleaning, Finance (`accounts/`), Cleaning payroll path |

## DEC-002 — Shared accounting engine for Real Estate, Construction, and ARS
| Field | Value |
|-------|-------|
| Date | 2026-07-10 |
| Status | Accepted |
| Reason | Confirmed: `accounting_engine.php` posts `re_journal_*` / `re_general_ledger` for RE, Construction wrappers, ARS, and non-cleaning payroll. |
| Consequences | Changes to the engine affect multiple modules. Company isolation is mandatory. Impact analysis required before edits. |
| Related modules | Real Estate, Construction, ARS, HR (non-cleaning), ERP expenses |

## DEC-003 — Invoice Mode is the official Real Estate accounting model
| Field | Value |
|-------|-------|
| Date | 2026-07-10 |
| Status | Accepted |
| Reason | Business confirmation: RE has migrated to Invoice Mode. Code defaults new/renewal leases to `invoice` (`accounting_mode_helper.php`). Official AR = invoices, obligations, `re_receipt_allocations`. |
| Consequences | All new RE accounting features must target Invoice Mode. Training and docs must present Invoice Mode as current. |
| Related modules | Real Estate, Tenant Portal (receipts), Legal (cheque escalation data) |

## DEC-004 — Legacy lease accounting is historical architecture only
| Field | Value |
|-------|-------|
| Date | 2026-07-10 |
| Status | Accepted |
| Reason | Legacy installment/payment/deferred paths remain for historical leases and reports but must not be extended. |
| Consequences | Keep code for backward compatibility. Do not remove in audits. Future deprecation candidate after freeze/migration. No new legacy features. |
| Related modules | Real Estate (`payment_add.php`, legacy post_* helpers, installment AR) |

## DEC-005 — HR routes accounting by business type
| Field | Value |
|-------|-------|
| Date | 2026-07-10 |
| Status | Accepted |
| Reason | `hr_payroll_accounting.php` posts Cleaning companies to `gl_*` and others to shared `re_*` — exclusive routing, not dual-write. |
| Consequences | Wrong `business_type` sends payroll to wrong books. Payroll changes must test both stacks. |
| Related modules | HR, Cleaning, Real Estate/Construction companies |

## DEC-006 — Company isolation is mandatory
| Field | Value |
|-------|-------|
| Date | 2026-07-10 |
| Status | Accepted |
| Reason | Multi-company ERP; shared tables keyed by `company_id`. Engine requires company on journal create. |
| Consequences | Every posting/read/report/allocation/reversal must enforce company. Session fallback to company `1` is unacceptable long-term. |
| Related modules | All |

## DEC-007 — Do not unify Cleaning and Shared ledgers without a formal program
| Field | Value |
|-------|-------|
| Date | 2026-07-10 |
| Status | Accepted |
| Reason | Dual stacks are intentional isolation, not accidental duplication of the same books. |
| Consequences | Commercial product may expose “ledger profile” per company type rather than one global COA. |
| Related modules | Cleaning, Shared-engine modules |

## DEC-008 — Shared engine changes require cross-module impact analysis
| Field | Value |
|-------|-------|
| Date | 2026-07-10 |
| Status | Accepted |
| Reason | RE/CO/ARS/HR/ERP expenses share `accounting_engine.php`. |
| Consequences | No drive-by edits to engine, COA helpers, or posting signatures without impact notes and verification plan. |
| Related modules | Real Estate, Construction, ARS, HR, ERP expenses |

## DEC-009 — Idempotent posting is a required control (target state)
| Field | Value |
|-------|-------|
| Date | 2026-07-10 |
| Status | Proposed |
| Reason | Stage 1 found missing/weak duplicate protection on Cleaning journals and no DB unique on shared references. |
| Consequences | Future JournalService must enforce idempotency keys; data cleanup before unique indexes. |
| Related modules | All posting modules |

## DEC-010 — Bank reconciliation may converge behind one service with ledger adapters
| Field | Value |
|-------|-------|
| Date | 2026-07-10 |
| Status | Proposed |
| Reason | Three near-parallel reco implementations (Cleaning, RE, Construction). |
| Consequences | Reduce maintenance; Cleaning remains on its GL adapter. |
| Related modules | Cleaning, Real Estate, Construction |

## DEC-011 — Inventory remains stock-ledger first
| Field | Value |
|-------|-------|
| Date | 2026-07-10 |
| Status | Accepted |
| Reason | `inv_posting.php` manages on-hand/WAC; no direct GL posting found. |
| Consequences | Inventory valuation to GL is a future explicit project if required commercially. |
| Related modules | Inventory, Grocery, material requests |

## DEC-012 — No production logic changes during Cursor intelligence / audit stages
| Field | Value |
|-------|-------|
| Date | 2026-07-10 |
| Status | Accepted |
| Reason | Audit and Cursor configuration must not alter financial behaviour. |
| Consequences | Documentation and `.cursor` guidance only until separately approved implementation work. |
| Related modules | All |

## DEC-013 — Construction shop rental uses three-layer Option B model
| Field | Value |
|-------|-------|
| Date | 2026-07-11 |
| Status | Accepted |
| Reason | Option A (period rent tied to payment frequency) produced invalid cheque plans when frequency ≠ cheque count (e.g. annual rent + 4 PDCs). Aligns Construction shop leasing with ERP four-layer discipline and RE Invoice Mode separation of operational vs accounting documents. |
| Confirmed model | (1) **Commercial:** `rent_amount` = total contract rent (net); monthly equivalent derived from lease duration. (2) **Operational:** payment frequency + cheque counts control cheque/payment plan only. (3) **Accounting:** invoices, deferred revenue (2215), and recognition follow monthly earning periods over the contract term—not payment frequency. VAT remains configurable per contract and independent of cheque planning. |
| Consequences | BR-CO-SHOP-001 superseded by BR-CO-SHOP-007. Cheque generation must not invent amounts by slicing frequency periods. Shop rental stays Construction/Madar Al Wadi–isolated; do not merge with RE lease tables. |
| Related modules | Construction (shop rental), shared `re_*` posting via Construction helpers |
| Related rules | BR-CO-SHOP-007, BR-CO-SHOP-002 |

## DEC-014 — ARS integrates via Financial Adapter; protect accounting engine
| Field | Value |
|-------|-------|
| Date | 2026-07-17 |
| Status | Accepted |
| Reason | Business approved ARS modernization architecture: RE `accounting_engine.php` is production-stable; ARS must not redesign or break it. ARS posts through a dedicated Financial Adapter that emits generic documents (invoice, payment, allocation, journal, credit note, refund, security deposit, adjustment). Engine remains source-agnostic (lease vs booking). |
| Consequences | Do not expand permanent direct `ars_accounting.php` event helpers as the target model. No engine signature/behaviour changes for ARS without DEC-008 impact analysis + human approval. Dual operational/financial booking lifecycles; never rewrite financial history; Booking Amendment creates new child documents. |
| Related modules | ARS, Real Estate accounting, shared engine consumers |
| Related docs | `docs/ars/ARS_MODERNIZATION_AUDIT_AND_ROADMAP.md` |

## DEC-015 — ARS ops independent; shared RE financial environment
| Field | Value |
|-------|-------|
| Date | 2026-07-17 |
| Status | Accepted |
| Reason | Holiday Homes guests typically pay into the same RE bank account; accountants need one bank reco and shared reports, while ARS booking/HK/guest ops stay independent. |
| Consequences | Reuse shared engine, allocation pattern, bank reco, and reporting. Identify HH via source module, booking reference, property/unit, dedicated Holiday Homes GL accounts, and dimensions. Exact posting `company_id` mapping Needs business + database confirmation. Booking Financial Document Hierarchy requires queryable parent links (not description-only). Booking Activity Center is mandatory. Mobile/customer APIs require additive compatibility. |
| Related modules | ARS, Real Estate, Stay / customer API |
| Related docs | `docs/ars/ARS_MODERNIZATION_AUDIT_AND_ROADMAP.md` |

## DEC-016 — ARS invoice storage uses Option B (ARS documents + Financial Adapter)
| Field | Value |
|-------|-------|
| Date | 2026-07-17 |
| Status | Accepted |
| Reason | Explicit business approval 2026-07-17. Phase 0 evidence showed `re_invoices.lease_id` NOT NULL and Invoice Mode posting JOINs `re_leases`/`re_tenants`. Forcing ARS into `re_invoices` / fake leases / nullable `lease_id` would risk Real Estate regression. |
| Consequences | ARS uses ARS-specific financial document tables and a dedicated Financial Adapter into the shared accounting engine, bank accounts, GL, and reporting. Do not store ARS bookings in `re_invoices`, `re_leases`, or `re_tenants`. Do not change RE Invoice Mode posting/receipt/CN/allocation/ageing for ARS. Full adapter tables are **Phase 2B** (after **Phase 2A** design approval). Phase 1 only additive financial-lock foundations; Phase 1B Activity Center. |
| Related modules | ARS, Real Estate Invoice Mode |
| Related docs | `docs/ars/ARS_MODERNIZATION_AUDIT_AND_ROADMAP.md` §7 |

---


## How to add a decision

1. Assign next `DEC-XXX`.  
2. Record Date, Status, Reason, Consequences, Related modules.  
3. Link PRs/commits when implementation follows.  
4. Never silently contradict an `Accepted` decision — supersede it explicitly.
