---
name: tenancy-contract-v2
description: Continue work on the residential Tenancy Contract v2 for Ayla ("2026 Tanacey Contract v2") and Park Place ("2026 Tanacey Contract PP v2") - clause wording changes, Arabic translation insert, comparison / translator PDFs, sample contracts, the live install package. Use when the user mentions the v2 contract, Ayla or Park Place contract, contract template, clause numbers, Annex A/B/C/D, translation of the contract, chiller wording, guarantor, max occupants, or asks to "commit" / deploy the contract work.
---

# Tenancy Contract v2 (Ayla + Park Place, residential)

Built 2026-11-09. Branch `tenancy-contract-v2`, PR #28 (NOT merged). **Nothing is on live.**

## Rules the user set (follow every time)
- **Never change a contract template until he has seen the wording.** Show old vs new (table) first, wait for "go" / "add it". Show the diff before committing.
- **Do not deploy.** Do not upload or run SQL on live until he says so. Reading live files to compare is fine.
- **One template per building.** Never present "with guarantor / without guarantor" as separate tables or files. Guarantor clause = one row "30* printed only when the lease has a guarantor".
- **Arabic is on hold.** Do not keep reminding him about Arabic or the 22 Annex D rates — he knows. Do not insert Arabic into the templates until he brings the translator's text and says so.
- **Wording must favour the landlord** (he objected to tenant-protective phrasing). Keep it simple ("may increase").
- Short, plain answers.

## Where things are
| What | Where |
|---|---|
| Ayla v2 template (source of truth) | `modules/realestate/contract_templates/tenancy_contract_v2.html` |
| Park Place v2 template | `modules/realestate/contract_templates/tenancy_contract_pp_v2.html` |
| DB rows (local) | `re_contract_templates` id 5 `tenancy_contract_v2`, id 6 `tenancy_contract_pp_v2`; both is_default 0. Old v1: id 3 Ayla, id 4 Park Place (untouched) |
| Render rules / data model | `modules/realestate/includes/contract_v2_helper.php` (defaults, {{#if}}, clause numbering, unfilled-placeholder check, Annex A-D, guarantor rule, occupancy calc, Annex D rates) |
| Hook into PDF generator | `modules/realestate/includes/contract_pdf_generator.php` (v2 only when template_code is v2/pp_v2; saves template version, rates snapshot, max-occupants snapshot on lease) |
| Lease form / view | `lease_add.php` (occupancy calc + Admin override, occupancy type, guarantor), `lease_view.php` |
| Unit form | `units_add.php` (Bedroom area sqm) |
| Migration (columns) | `migrations/re_tenancy_contract_v2.sql` |
| Live install SQL builder | `php tools/contract/build_tenancy_contract_v2_sql.php > live_hotfix/2026-11-09_tenancy_contract_v2_install.sql` (adds both template rows) |
| Tests | `php tests/contract_v2_test.php` |
| Samples | `php tools/contract/render_tenancy_v2_samples.php` → `docs/contract/samples/` |
| Docs | `docs/contract/translation_sheet.md`, `docs/contract/CHANGELOG_v2.md` |
| Review PDFs (comparison, Arabic draft, translator files) | `zsh tools/contract/review/run_all.sh` → ~/Downloads. Data in `tools/contract/review/data/` (map.json = Ayla clause map + old v1 text; map_pp.json = Park Place; ar_draft.json = corrected Arabic draft; pp_vs_ayla.html = PP differences page) |
| Live package | `~/Downloads/contract_v2_deploy_2026-11-09/` (files/, SQL, live_backup_before/, README.txt with sizes) |
| PDF page → PNG for checking | `tools/contract/review/pdfpng.swift` (compile with swiftc) |

After any template edit: refresh the local DB row (`UPDATE re_contract_templates SET template_html=?, template_content=? WHERE template_code=...` with the file contents), rerun tests, samples, rebuild install SQL, copy SQL + changed files into the live package and fix README sizes. If a clause's English changed, also update `en` in `data/map.json` / `map_pp.json` and the translation sheet (mark RE-TRANSLATE if the Arabic draft no longer matches).

## Decisions already made (do not re-ask)
- Clause 12: AED 500 returned-cheque handling fee per cheque, **no VAT**, no deposit top-up line. Billing workflow for it NOT built (he said stick to clause 12).
- Guarantor: required when tenant is a company AND annual rent > AED 100,000; otherwise optional. Lease form blocks save; v2 PDF refuses to print.
- Max occupants: computed from unit (studio 2; else min(2b+2, floor(bedroom_area/5), cap {1:4,2:6,3:9,4:11} else 2b+3)); snapshot on lease; Admin/Owner override with reason (logged). Unit without bedrooms → contract refuses to print. Cap table and 5 sqm are fixed constants (settings page = later task). No `is_villa` field.
- Annex D: 22 items, rates blank → print "—" and log; `CONTRACT_V2_RATES_REQUIRED` switch to true once rates arrive. Rates copied onto the lease at first generation (`contract_rates_snapshot`).
- Clause 28: landlord notices by email only, default `tenantcare@ainalreempro.com`; no office address.
- Park Place v2 = Ayla v2 + chiller billed by Landlord: page-1 rent + chiller line; clause 18 "Chiller charges are payable to the Landlord as stated on page 1 and in Annex A. The chiller charges are as stated on page 1. The Landlord may increase the chiller charges at renewal by giving the Tenant written notice at least ninety (90) days before the expiry date."; clause 20(b) no chiller clearance, "has paid all chiller charges due to the Landlord".
- Arabic draft: 9 browser-Claude fixes + bracket rule + clause 31 «بين المؤجر والمستأجر» applied in `data/ar_draft.json` only. When the Arabic is inserted, code still needed: `MINOR_REPAIR_THRESHOLD_WORDS_AR`, `CHEQUE_ADMIN_FEE_WORDS_AR`, `EJARI_REGISTRATION_DAYS_WORDS_AR` (Arabic number words), `OCCUPANCY_TYPE_AR` (default «عائلة واحدة», check both set), `LANDLORD_OFFICE_ADDRESS_AR` (now unused after clause 28 change), parties label «المؤجر (المالك):», English guarantor "jointly and severally with the Tenant". Clauses 17(b), 28, and PP 18/20 need fresh Arabic.
- Old v1 contract's clause 13 Arabic says the opposite of the English (missing «لا»). User told; old contract NOT changed.

## Open (waiting on the user)
- **"commit"**: uncommitted on the branch = Annex D + deposit edits, clause 28, occupancy (units_add, lease_add, migration, helper), Park Place v2, tests, samples, docs, tools/contract/review. Do NOT stage the user's own `food_court` edits in `lease_add.php` (2 JS lines) and `lease_vat_calculator.php` — they are already live; commit by temporarily reverting those 2 lines, then restore.
- Translator's Arabic, then insert + code above, show diff.
- 22 Annex D rates from maintenance team.
- 3 units with bedrooms not matching type: Mr. Amran's Test Building 105 (2br, 3 bedrooms), Villa 267 Dubai South (penthouse, blank — its contract won't print), Villa 279 Damac Hills (3br, 4 bedrooms).
- Deploy when told: compare live sizes first (lease_add live = main + food_court; lease_view live = main + 40-line stand-in block, already back-ported; generator and units_add identical to main), then upload the package files and run the SQL in phpMyAdmin (he does SQL).
- Later: make v2 default after translation; settings page for occupancy limits; returned-cheque billing workflow.

## Commercial v2 (started 2026-11-09)
- User decided: ONE commercial template per building covering shop / office / food court; lease field **Commercial Use** picks the wording ({{#if USE_SHOP/USE_OFFICE/USE_FOOD_COURT}}). Ayla/PP have only shops today (unit_type 'commercial'); office/food court templates are for future units.
- Ayla Commercial: `modules/realestate/contract_templates/tenancy_contract_com_v2.html`, code `tenancy_contract_com_v2`, local DB id 7, not default. Built by copying Ayla residential v2 and editing; 12/20/28 kept on same numbers; 8 Signage, 9 Approvals, 21 Trade licence replace dish/LPG/pets; new 30-34 (+35 food court kitchen) before guarantor. 38 clauses shop/office, 39 food court.
- Helper: `contract_v2_commercial_codes()`, `contract_v2_commercial_values()` (refuses without Commercial Use or without VAT on rent), commercial Annex B rooms, no occupants. New lease columns commercial_use, permitted_activity, trade_licence_no, fit_out_days (display only) in the v2 migration; lease_add "Commercial Contract" block shown for commercial unit types.
- NEXT: Park Place Commercial (ask: chiller billed by Landlord like PP v2?). Then add its code to contract_v2_commercial_codes(), install SQL, tests, samples.
- 2026-11-09 later: ALL 4 built (PP Commercial with HAS_CHILLER flag, Gents/Ladies Camp Commercial, Gents/Ladies Camp Labour). Local DB ids 8-12, not default. Codes in contract_v2_commercial_codes() / contract_v2_labour_codes(). Labour: persons_per_room on lease (required), rooms from re_lease_units into Annex E. Defaults used without user answer: persons per room typed per lease; common areas cleaned by Landlord; Ladies shop staff not in accommodation areas.
- 2026-11-10: translator files for commercial + labour: `final_tr_com_lab.py` (reads templates; Part 1 full Ayla Commercial / Gents Labour, later parts = differing rows only), `final_pdf_any.php`, `final_docx_any.py`; in run_all.sh step 4b. Fixed a builder bug (old Arabic kept on commercial 25 / labour 24); check: every clause with English different from residential v2 must show PENDING.
- 2026-11-10 Ayla Commercial is now **v2.1** (same file/code tenancy_contract_com_v2): English from Downloads/Ayla_Commercial_Tenancy_Contract_v2.1_EN.pdf; builder tools/contract/review/build_com_v2_1.py (refuses to run on a v2.1 file); dynamic refs {{CLAUSE_NO:key}} / {{REF:key}}; helper contract_v2_commercial_v21_values(); guarantor or Admin waiver required; see docs/contract/CHANGELOG_commercial_v2.1.md for mapping and open items. Translator PDF: final_tr_com_v21.py → Downloads/Commercial Contract/ (only Ayla v2.1 file there). PP/camp commercial still v2.
