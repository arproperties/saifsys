# Tenancy Contract v2 – changelog

Template: **2026 Tanacey Contract v2** (`template_code = tenancy_contract_v2`), residential, Ayla standard. Not the default template; pick it on the lease form (Contract Template) to use it.

Old contracts are not affected. Leases on "2026 Tanacey Contract" (v1) and "2026 Tanacey Contract PP" still print exactly as before. The template each PDF was made from is saved on the lease (`re_leases.contract_template_version`).

## Park Place v2

Template **2026 Tanacey Contract PP v2** (`template_code = tenancy_contract_pp_v2`, file `modules/realestate/contract_templates/tenancy_contract_pp_v2.html`), not the default. Same as Ayla v2 except: page 1 Rent = rent + chiller fees = total; clause 18 drops chiller from the tenant's utility list and says chiller is payable to the Landlord; clause 20(b) has no chiller clearance certificate and needs chiller charges paid. The old "2026 Tanacey Contract PP" is unchanged.

## Clause map (old v1 number → v2 number)

Numbers with the default settings: renewal fee clause included, no guarantor. Clause numbers are worked out when the PDF is printed, so a left-out clause never leaves a gap.

| Old | New | Change |
|---|---|---|
| 1 | 1 | amended |
| 2 | 2 | unchanged |
| 3 | 3 | unchanged |
| 4 | 4 | replaced |
| 5 | 5 | amended |
| 6 | 6 | unchanged |
| 7 | 7 | unchanged |
| 8 | 8 | unchanged |
| 9 | 9 | unchanged |
| 10 | 10 | unchanged |
| 11 | 11 | unchanged |
| 12 | 12 | replaced |
| 13 | 13 | amended |
| 14 | 14 | unchanged |
| 15 | 15 | replaced (old 15 + old 18 merged) |
| 16 | 16 | unchanged |
| 17 | 17 | amended (extended) |
| 18 | — | deleted (merged into the new renewal clause) |
| 19 | 18 | amended |
| 20 | 19 | unchanged - LEGAL REVIEW, conditional |
| 21 | 20 | replaced |
| 22 | 21 | unchanged |
| 23 | 22 | unchanged |
| 24 | 23 | unchanged |
| 25 | 24 | unchanged |
| 26 | 25 | unchanged |
| 27 | 26 | amended |
| 28 | 30 | unchanged |
| 29 | 31 | amended |
| 30 | 32 | unchanged |
| 31 | 33 | unchanged |
| new | 27 | new: Rent until actual vacating |
| new | 28 | new: Notices and addresses for service |
| new | 29 | new: Keys and access cards |
| new | 30 (only with a guarantor; later clauses move down one) | new: Guarantor (only when a guarantor is on the lease) |

## Other changes

- Page 1, Terms of Payment now reads "N instalments as per Annex A" (`{{PAYMENT_SUMMARY}}`).
- Signature page: guarantor signature line when a guarantor is on the lease. Signature lines now print (they were missing in v1).
- New pages after the signature page: Annex A Payment Schedule (from the lease's cheques), Annex B Move-In Inspection Report (filled from the move-in record when there is one, otherwise a blank form), Annex C Move-Out Inspection Report (blank).
- Clause 5 wording: the last sentence now says "these reports" instead of "this inventory". The old sentence "This inventory shall serve as an official document…" was removed, because the new text already says the report is the record.
- Clause 12: returned cheque = cheque amount + actual third-party costs with receipts + AED 500 handling fee (no VAT), within 7 days of notice; may be taken from the deposit; survives the end of the lease. Clause 20(d) now allows deducting unpaid clause 12(b) amounts.
- Clause 20 (deposit): (a) deductions use Annex D rates or a contractor's quote/invoice, statement can be signed at the move-out inspection; (b) an objection does not stop repairs or re-letting, disputes decided on Annexes B, C, D and photos; (d) keys and cards at Annex D rates.
- Annex D Schedule of Rates (22 items, fixed list in `contract_v2_schedule_of_rates()`). Blank rate prints "—" and is logged; set `CONTRACT_V2_RATES_REQUIRED` to true once all rates are in. The rates used are saved on the lease (`re_leases.contract_rates_snapshot`) when its contract is first generated, so a later change to the list never changes that lease's Annex D (only rates still blank in the saved copy are filled from the list).
- Clause 19 (renewal fee) is kept unchanged inside `{{#if INCLUDE_RENEWAL_FEE}}` (default on) with `TODO(legal): confirm enforceability of renewal service fee`.

## New lease fields

| Field | Used in | Default when empty |
|---|---|---|
| Max Occupants (`max_occupants`) | Clause 17(b) | 4 |
| Occupancy Type (`occupancy_type`): Single family / Couple / Company staff / Other (free text) | Clause 17(b) | a single family |
| Guarantor Name, Guarantor Emirates ID (`guarantor_name`, `guarantor_eid`) | Guarantor clause and signature line | no guarantor clause |

Guarantor rule: when the tenant is a **company** and the annual rent is **over AED 100,000**, the lease form will not save without both guarantor fields, and the v2 contract will not print without them. For every other lease the guarantor is optional. The rule is checked when a lease is saved, so existing leases are not affected until someone edits them.

## Placeholders added

| Placeholder | Type | Default |
|---|---|---|
| `{{MINOR_REPAIR_THRESHOLD}}` / `{{MINOR_REPAIR_THRESHOLD_WORDS}}` | int / words | 500 / Five Hundred |
| `{{CHEQUE_ADMIN_FEE}}` / `{{CHEQUE_ADMIN_FEE_WORDS}}` | int / words | 500 / Five Hundred (clause 12(b)(iii), no VAT) |
| `{{EJARI_REGISTRATION_DAYS}}` / `_WORDS` | int / words | 7 / seven |
| `{{MAX_OCCUPANTS}}` | int | 4 |
| `{{OCCUPANCY_TYPE}}` | text | a single family |
| `{{LANDLORD_NOTICE_EMAIL}}` | text | ar.properties15@gmail.com |
| `{{GUARANTOR_NAME}}`, `{{GUARANTOR_EID}}` | text | empty |
| `HAS_GUARANTOR` | flag | false (true when a guarantor name is on the lease) |
| `INCLUDE_RENEWAL_FEE` | flag | true |
| `{{PAYMENT_SUMMARY}}`, `{{PAYMENT_SCHEDULE}}`, `{{MOVE_IN_REPORT}}`, `{{MOVE_OUT_REPORT}}` | generated HTML | from the lease |
| `{{CLAUSE_NO}}`, `{{CLAUSE_NO_AR}}`, `{{CLAUSE_REF}}` | numbering | worked out at print time |

Defaults live in `contract_v2_defaults()` in `modules/realestate/includes/contract_v2_helper.php`.

## Render checks (v2 only)

- Any `{{…}}` left after filling stops the PDF with an error that names it. An unknown `{{#if FLAG}}` also stops it.
- Annex A: the first due date should equal the lease start date, and each due date should fall on the start date's day of the month (or the last day of a shorter month). A mismatch is written to the PHP error log and does not stop the PDF. `SCHEDULE_DATE_OVERRIDE` turns the check off.

## Samples

- `docs/contract/samples/tenancy_contract_v2_sample_4_cheques_no_guarantor.pdf`
- `docs/contract/samples/tenancy_contract_v2_sample_with_guarantor.pdf`

Rebuild them with `php tools/contract/render_tenancy_v2_samples.php`. Tests: `php tests/contract_v2_test.php`.

## 2026-11-09 - Ayla Commercial v2 (shop / office / food court)
- New template `tenancy_contract_com_v2.html` ("2026 Tanacey Contract Commercial v2"), built from Ayla residential v2. One template; the lease's **Commercial Use** picks the shop / office / food court wording.
- Kept on the same numbers: 12 (rent, AED 500 cheque fee, no VAT), 20 (deposit), 28 (notices by email).
- Replaced in place: 8 satellite dish → Signage; 9 LPG → Approvals and Civil Defence; 21 pets → Trade licence and Permitted Activity. 17(b) occupancy removed.
- Changed: 3, 4(a)(v), 7, 16, 17(a), 18, 22, 24, 25. New after 29: Handover and fit-out, Fit-out removal, VAT 5%, Insurance, Opening hours; Kitchen and seating (food court only).
- Page 1: Use, Permitted Activity, Trade Licence No., rent excl. VAT / VAT / incl. VAT, Fit-out Period. Annex B: shop rooms, no occupants.
- Refuses to print without Commercial Use, or without VAT on rent. Guarantor rule unchanged.
- New lease columns: commercial_use, permitted_activity, trade_licence_no, fit_out_days (display only).
- Arabic: changed/new clauses and new page-1 labels show TRANSLATION PENDING.

## 2026-11-09 - Park Place Commercial, camp Commercial, camp Labour
- `tenancy_contract_pp_com_v2`: Ayla Commercial + chiller billed by the Landlord, printed only when the lease has chiller fees (HAS_CHILLER): page 1 line, clause 18, clause 20(b).
- `tenancy_contract_gc_com_v2` / `tenancy_contract_lc_com_v2`: Ayla Commercial + clause 25 camp facilities + new Camp rules and security clause (Ladies: staff not in accommodation areas). 39 clauses (shop), 40 food court.
- `tenancy_contract_gc_lab_v2` / `tenancy_contract_lc_lab_v2`: labour accommodation from residential v2. Page 1 rooms / persons per room / total / trade licence; DEWA "As per Annex E"; new Annex E room list. 7, 9, 17, 21, 24, 25 replaced; 7 new clauses after 29 (camp rules with gender line, MOHRE/DM rules, workers' conduct, cleaning, no VAT, end of lease, extra rooms). 40 clauses.
- New lease column persons_per_room (labour contract will not print without it).
- Generated by a one-off script from the Ayla Commercial / Ayla residential files; the templates are the source of truth now.
