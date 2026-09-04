# BR-RE-SEPARATE-VAT-001 — Separate VAT cheque → standalone VAT invoice

| Field | Value |
|-------|--------|
| **Rule ID** | BR-RE-SEPARATE-VAT-001 |
| **Module** | Real Estate |
| **Workflow** | Lease create/edit (Invoice Mode); obligation generation; invoice issuance; receipt allocation from VAT cheque |
| **Status** | Confirmed |
| **Evidence** | Product owner (lease 250 / Separate VAT cheque + split chiller fees), 2026-07-14 |
| **Applies when** | `vat_distribution_type = separate_payment` and `total_vat_amount > 0` on an Invoice Mode lease |

## Rule

When VAT is collected on a **separate operational cheque**, Invoice Mode must create **one** accounting document for that VAT (`obligation_type = vat`, then invoice), for the lease `total_vat_amount`.

Fee principal (e.g. chiller) may still be **distributed across rent/fee cheques**. Those fee obligations/invoices must stay **net** (no embedded VAT) under this distribution mode.

Rent VAT, if any, is included in the same separate VAT obligation/cheque face (`total_vat_amount`), not embedded on monthly rent invoices when distribution is `separate_payment`.

## Does not apply

- `first_installment` and `split_installments` VAT distribution (VAT may remain embedded on rent/fee docs as today).
- Legacy accounting mode.
- Construction shop-rental separate VAT (different module rules).

## Implementation notes

- Generator: `re_obligation_preview_generate()` in `obligation_preview_helper.php`
- Issuance: `re_invoice_engine_line_from_obligation()` special-cases `obligation_type=vat`
- Cheque coverage prefers `obligation_type=vat`; embedded fee-VAT fallback is only for unrepaired historical leases
