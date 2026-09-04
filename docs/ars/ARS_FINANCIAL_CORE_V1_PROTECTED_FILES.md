# ARS Financial Core v1.0 — Protected Files Manifest

**Freeze date:** 2026-07-17  
**Engine SHA (2C/2D/2E):** `9cbb880fc3241615b69d4fe5b3adfbee14e513d03964924214cc9325f9d7c7d6`

Hashes are SHA-256 of file contents at freeze time (localhost workspace). Re-verify with `shasum -a 256 <path>` before any approved change.

---

## Class A — frozen (no modification)

| File | SHA-256 |
|------|---------|
| `modules/realestate/accounting/accounting_engine.php` | `9cbb880fc3241615b69d4fe5b3adfbee14e513d03964924214cc9325f9d7c7d6` |
| `modules/ars/includes/ars_account_roles.php` | `ef65f224471eb6f2372d52ab48b015f52b77351c0a063ca5eccc89bc7f19c5d7` |
| `modules/ars/includes/ars_financial_document_sm.php` | `36c77da79683832bfc5fc26b629bc43e6f0a9b27a877a785943d04d895b9c4d1` |
| `modules/ars/includes/ars_financial_lock.php` | `14ee3c1a6122db59777045743dc416cccca3ea191f3de01939c3f5c079a811d6` |
| `modules/ars/includes/ars_financial_core_version.php` | `23dac9cda3f1238ad949b74c6d64b0409746d4d6f3a057c7612315d72ef818b5` |
| `migrations/ars_phase1_financial_lock_foundations.sql` | `677f7a04fe3c71ba8d5c7173b3065343b50e86db0bedb1bfd4e576725fb1a3e1` |
| `migrations/ars_phase1b_activity_center.sql` | `270b5efc6de8002ed8445819817a9b7af9d30a2e6638d74f90dbe61b78a072e5` |
| `migrations/ars_phase2b_financial_documents.sql` | `f47b0fa5902216f2ee3a45630fd6351b124e81a197ed98e7b286077a9a5514f9` |
| `migrations/ars_phase2d_gated_workflows.sql` | `1aff9b06a0fa46d92690b12b361e61e76a73320fa537b9cfa46c554708f7c8dc` |
| `docs/ars/PHASE2D_FINAL_BUSINESS_RULE_REGISTER.md` | `f7328a92acea6fb7ec5d3f9b0a9980631dee7d15369a74768344c99336183cef` |
| `docs/ars/_ars_financial_core_v1_schema.sql` | `b75f893fe6159866b295a8480804d9c2b9033cb2a9ea18b9c11dd7bc0dfb8037` |

## Class B — protected interface (call, do not change)

| File | SHA-256 |
|------|---------|
| `modules/ars/includes/ars_financial_adapter.php` | `214e8e1da4f00222c5f645129014976699366dce91669ac12de709ac7d4347ff` |
| `modules/ars/includes/ars_financial_adapter_phase2d.php` | `437b7e1e5695390bb661fd933f7746805310833e7d34e58d1b4d02c23ac6b359` |
| `modules/ars/includes/ars_accounting.php` | `5aacadcd3cbc592a33888ca2cdc23c2958eb1b56b4b16863d408070a96f2e87b` |
| `modules/ars/includes/ars_financial_reports.php` | `6eff20a8f7eeb1343c908c6d3a5992b9e8964001a2a351a6dfc32ff59aad4c84` |
| `modules/ars/includes/ars_deposit.php` | `065ed3ec4b5f039139e68b4674a925ce858d39e32185f75abed971553c28851f` |
| `modules/ars/includes/ars_activity.php` (log API) | `96e9c1a9f27d48711284641099ace1e91fd2091c880fa23acf27ef2777cf0e2c` |
| `modules/ars/includes/ars_permissions.php` | `d5edf11363c31617793efa97f69e18ce5d78c01626f4d0d22daf4acbb3143975` |

## Class C / D — presentation (Phase 3 may restyle; must not change financial meaning)

| Path | Notes |
|------|-------|
| `modules/ars/financial_reports.php` | UI shell |
| `modules/ars/financial_document_view.php` | UI shell |
| `modules/ars/booking_view.php` | Ops UI — call adapter, no inline posting math |
| `modules/ars/settings.php` | Flag UI — do not change default-off semantics without approval |
| Activity Center markup in booking view | Presentation only |

## Explicitly out of bounds for ARS Phase 3

| Area | Protection |
|------|------------|
| Real Estate Invoice Mode / `re_invoices` writers | Do not use or alter for ARS |
| `api/customer/v1/*` response contracts | Additive only; no breaking changes |
| `stay/*` portal contracts | Same |
| Cleaning `gl_*` | Never |

## Protected file count (this manifest)

| Category | Count |
|----------|------:|
| Class A listed with hash or artifact | **11** |
| Class B listed with hash | **7** |
| Class C/D path entries | **5** |
| **Total protected entries** | **23** |

Plus engine + migrations + rule docs referenced in freeze register.

## Re-verification command

```bash
shasum -a 256 modules/realestate/accounting/accounting_engine.php \
  modules/ars/includes/ars_financial_adapter.php \
  modules/ars/includes/ars_financial_adapter_phase2d.php \
  modules/ars/includes/ars_account_roles.php \
  modules/ars/includes/ars_financial_document_sm.php
```
