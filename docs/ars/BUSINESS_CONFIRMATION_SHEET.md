# ARS Business Confirmation Sheet (pre–Phase 2)

**Status:** Superseded by [`PHASE2_BUSINESS_CONFIRMATION_SHEET.md`](PHASE2_BUSINESS_CONFIRMATION_SHEET.md) for Phase 2A.  
**Created:** 2026-07-17  
**Note:** Row 7 (Option B) remains Approved. Rows 1–6 remain open and are expanded in the Phase 2A sheet.

| # | Question | Options / notes | Owner answer | Date |
|---|----------|-----------------|--------------|------|
| 1 | Which `company_id` is the shared Holiday Homes financial environment (bank reco / GL)? | Current `companies.code='ARS'` vs primary Real Estate company vs other | | |
| 2 | Revenue recognition timing? | Confirm (current legacy bridge) / Check-in / Nightly deferred (COA 2400) | | |
| 3 | Tourism / municipality fees required? | Yes (rate/account) / No | | |
| 4 | Cancellation / no-show fee policy? | Fee formula + when charged | | |
| 5 | Stripe clearing / settlement account mapping? | Immediate bank vs clearing account codes | | |
| 6 | Guests remain exclusively in `ars_guests`? | Yes / Map to `re_tenants` later | | |
| 7 | Confirm Option B remains in force for Phase 2 document tables? | Accepted 2026-07-17 (DEC-016) | Approved | 2026-07-17 |

Phase 1 does not depend on rows 1–6. Phase 2 Financial Adapter design must capture confirmed answers via Business Rule Capture Standard before posting behaviour changes.
