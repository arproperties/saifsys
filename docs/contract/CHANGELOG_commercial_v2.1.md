# Ayla Commercial Contract v2.1 - changelog (10 Nov 2026)

Template: `modules/realestate/contract_templates/tenancy_contract_com_v2.html` (code unchanged: `tenancy_contract_com_v2`; DB name "2026 Tanacey Contract Commercial v2.1").
English: the v2.1 master (`Ayla_Commercial_Tenancy_Contract_v2.1_EN.pdf`, 10 Nov 2026), used word for word. The task named `docs/contract/commercial_v2.1_master.md`, but that file does not exist.
Built by `tools/contract/review/build_com_v2_1.py` from the v2 template. Layout, styles and the two-column rows are kept.
Park Place, Gents Camp and Ladies Camp Commercial are still on v2 (not changed).

## Step 0 mapping (v2 row → v2.1 clause)

| v2 | v2.1 | Status in task | Checked |
|---|---|---|---|
| 1 | 1 | amended | amended |
| 2 | 2 | amended | amended |
| 3 | 3 | amended | amended |
| 4 | 4 | amended | amended (4(a)(v), 4(b)(ii)) |
| 5 | 5 | unchanged | **amended** (room → area, last sentence, heading) |
| 6–9 | 6–9 | amended | amended |
| 10 | 10 | unchanged | **amended** (reworded, heading) |
| 11 | 11 | unchanged | **amended** (reworded, heading) |
| 12 | 12 | amended | amended; (c) now "Clause 20" |
| 13 | 13 | replaced | replaced |
| 14 | 14 | amended | amended |
| 15 | 15 | unchanged | **amended** (contract → Agreement) |
| 16 | 16 | amended | amended |
| 17 | 17 | replaced | replaced ((a)–(d)) |
| 18 | 18 | replaced | replaced |
| 19 | 19 [R] | unchanged | **amended** (reworded, heading) |
| 20 | 20 | amended | amended |
| 21–23 | 21–23 | replaced | replaced |
| 24 | 24 | amended | amended |
| 25 | 25 | unchanged | **amended** (heading, "including") |
| 26 | 26 | unchanged | **amended** (reworded, heading) |
| 27 | 27 | amended | amended (new (b)) |
| 28 | 28 | unchanged | **amended** (heading only) |
| 29 | 29 | amended | amended |
| 30–33 | 30–33 | replaced | replaced |
| 34 | 34 | amended | amended |
| 35F | 35 [F] | amended | amended |
| 35* | 36 [G] | amended | amended |
| 35 | 37 | unchanged | **amended** (board resolutions) |
| 36–38 | 38–40 | unchanged | **amended** (reworded) |

No clause is word for word the same, so all 40 Arabic cells are TRANSLATION PENDING (the v2 Arabic is kept in a comment as "old Arabic, guide only").

## What was built
- Clause numbers and cross-references are dynamic: CLAUSE_NO:key / REF:key, resolved at render time. A reference to a clause that is not printed stops the PDF. A test checks that every reference has a clause.
- Page 1: landlord and tenant licence and TRN, signatory, guarantor block (only with a guarantor), premises with community and sq ft, rent in words, service charge, fit-out deposit, parking and remarks.
- Signature page: 3 columns (with a guarantor), contact line and attachments checklist.
- Annex A: Rent / VAT / Total columns. VAT per cheque is taken from the installment record; when the total does not match VAT on rent, a warning is logged (the PDF still prints).
- Annex B: commercial areas (FCUs; kitchen exhaust, grease trap and gas only for a food court), DEWA water meter, "fit-out drawings approved" line and "declined to sign" line. Annex C: reinstatement line and "declined to sign" line. Annex D: commercial list (13 items; grease trap only for a food court), still saved on the lease at first print.
- Guarantor: the Ayla Commercial contract will not print without a guarantor or an Admin "No Guarantor - Reason" (saved with who and when, and audit-logged).
- New lease columns (printed only, never used in an amount): tenant_trn, tenant_signatory_name, tenant_signatory_eid, tenant_bank_account_name, guarantor_phone, guarantor_email, guarantor_waived_reason/_by/_at, service_charge_terms, fit_out_deposit, parking_terms.
- Samples: office with no guarantor (38 clauses), shop with guarantor (39), food court with guarantor and a 30-day fit-out (40).
- Translation: `docs/contract/commercial_translation_sheet.md` and `Ayla Commercial Contract v2.1 - For Translation.pdf`.

## Not done (needs a decision)
- Security deposit default of 10% and per-use fit-out deposit defaults: these touch amounts, so they wait for the owner.
- Permitted Activity / approved-use check: units have no approved-use field yet.
- Upload slots that block printing until documents are in: not built (the checklist prints on the signature page).
- Returned-cheque, legal-hold and deduction-statement workflows: these do not exist in the residential module, so there was nothing to reuse.
- Arabic amount words: the English words print; the Arabic words wait for the Arabic insert.
- Landlord trade licence no., landlord signatory and third-party insurance minimum print as blank lines until they are given (constants in contract_v2_helper.php). The landlord TRN is read from company_settings (empty on local).
