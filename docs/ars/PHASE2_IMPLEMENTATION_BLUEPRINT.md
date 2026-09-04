# Phase 2A — Phase 2B Implementation Blueprint

**Status:** Executable plan for Phase 2B — **do not start until Phase 2A approved**  
**Hard stops:** No engine changes; no RE Invoice Mode changes; no live migrate without separate approval; resolve BC items that affect posting before enabling those events.

---

## 1. Recommended implementation order

1. **Confirm BC-01** (posting company) at minimum for mirror path; freeze COA checklist.  
2. Additive **schema migrations** (empty tables + payment link columns) — local first.  
3. **Feature flag** + adapter skeleton returning “not enabled”.  
4. **Mirror path:** original invoice + payment + deposit receive/refund (parity with `ars_accounting.php`).  
5. **Allocations** to original invoice.  
6. **Activity Center** deep links to new document view.  
7. **Amendment previews** return real doc types (still no auto-post until BC for fees).  
8. Extension / service / CN / refund / Stripe settlement **only after** related BC Confirmed.  
9. Legacy backfill (optional, conservative).  
10. Deprecate direct `ars_post_*` call sites behind adapter.  
11. Reporting views / filters.  
12. Local regression → human approval → live migration plan (separate).

---

## 2. Migration order (additive)

| # | Migration (names proposed) | Content |
|---|----------------------------|---------|
| M1 | `ars_phase2_financial_documents.sql` | documents + lines + indexes |
| M2 | `ars_phase2_doc_satellites.sql` | extension/cn/adjustment/refunds/deposits/allocations |
| M3 | `ars_phase2_payment_links.sql` | nullable FKs on payments/charges/bookings |
| M4 | `ars_phase2_settings_flags.sql` | adapter enabled flag |
| M5 | `ars_phase2_backfill_*.sql` | optional; after M1–M4; idempotent |

Each: IF NOT EXISTS / information_schema guards; documented rollback.

**Phase 2A does not run these.**

---

## 3. Rollback order

1. Disable adapter flag  
2. Stop new backfill jobs  
3. Do **not** drop tables with posted rows  
4. Restore bridge call sites if needed  
5. Only DROP empty unused tables in non-prod  

Journal rows never deleted — reverse if required.

---

## 4. Testing order

1. Unit: idempotency, validation, company fail-closed  
2. Integration: confirm → invoice+JV; pay → allocation; deposit receive/refund  
3. Failure: force journal fail → no orphan document  
4. Lock: charge on locked booking → amendment required, no silent total rewrite  
5. Double-submit confirm/payment  
6. Cross-company ID manipulation  
7. Activity feed links  
8. Mobile API shape snapshot  
9. Engine SHA unchanged  
10. RE invoice/lease/tenant counts unchanged  
11. Sample TB/P&L/bank reco visibility  

---

## 5. Regression checklist

- [ ] Existing bookings  amounts/journals unchanged by migrations  
- [ ] Phase 1 lock behaviour intact  
- [ ] Phase 1B Activity Center filters still work  
- [ ] Guest portal payment-intent flow unchanged  
- [ ] Cleaning / Construction posting untouched  
- [ ] CSRF on new endpoints  

---

## 6. Financial verification checklist

- [ ] Sample original invoice balances (DR=CR)  
- [ ] VAT lines match booking vat_mode  
- [ ] Payment reduces document `balance_due` and booking `paid_amount` consistently  
- [ ] Deposit 2200 subledger = open deposit docs  
- [ ] Cancel reverse/CN leaves audit trail  
- [ ] No postings into Cleaning `gl_*`  

---

## 7. Deployment strategy (non-live until approved)

| Stage | Action |
|-------|--------|
| Local `datanew` | Run M1–M4; enable flag; mirror tests |
| Staging (if any) | Repeat; finance UAT |
| Live | Separate written approval; backup; M1–M4; flag off; smoke; enable for new bookings only |

---

## 8. Production rollout strategy

1. Announce maintenance window if needed  
2. Backup  
3. Deploy code with flag **off**  
4. Run additive migrations  
5. Smoke read-only  
6. Enable adapter for **new** confirms only  
7. Monitor journals + error_log  
8. Schedule backfill later  
9. Disable bridge call sites after soak  

---

## 9. Team / skill gates

- `accounting-review`, `invoice-mode-review`, `module-impact-analysis`, `financial-report-validation`, `security-review` on adapter endpoints  
- Human approval before any engine touch or live migration  

---

## 10. Exit criteria for Phase 2B start

- [ ] Phase 2A human approval  
- [ ] BC-01 decided (or explicit accept of current ARS company interim)  
- [ ] This blueprint accepted without open architecture questions  
- [ ] No requirement to invent fee/tourism/Stripe rules in MVP mirror path  
