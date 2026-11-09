# Tenancy Contract v2 – changelog

Template: **2026 Tanacey Contract v2** (`template_code = tenancy_contract_v2`), residential, Ayla standard. Not the default template; pick it on the lease form (Contract Template) to use it.

Old contracts are not affected. Leases on "2026 Tanacey Contract" (v1) and "2026 Tanacey Contract PP" still print exactly as before. The template each PDF was made from is saved on the lease (`re_leases.contract_template_version`).

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
