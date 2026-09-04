# BR-ARS-OPS-004 — Stay credit note / stay refund (not deposit)

| Field | Value |
|-------|-------|
| Rule ID | BR-ARS-OPS-004 |
| Module | ARS (+ shared RE ledger company) |
| Workflow | Booking View Amendment → Credit note / stay refund |
| Business event | Reduce recognized stay (or related) revenue after invoice without rewriting history; optional guest credit |
| Confirmed rule | **Draft — interim from Phase 2D BC-06/08 + BR-ARS-FIN-001.** Stay refunds use a new `credit_note` document (net+VAT split per booking). Default option: create **guest credit** liability for CN total (`to_guest_credit`). Does **not** replace security deposit refund (Deposit tab / BR-ARS-OPS-002). Cancellation full reverse remains the cancel path; this UI is the operator CN / partial stay credit path. Never rewrite original invoice lines. |
| Supported scenarios | Goodwill credit; partial unused nights credit when early-checkout refundable path is used via adapter; manual stay CN |
| Exceptions | Deposit cash refund / deductions stay on Deposit settle. Stripe refund settlement out of scope until BC-12/13 UI. |
| Configuration options | `early_checkout_refundable`, `cancellation_fee_percent` in `ars_financial_policy` (Phase 2D); operator enters CN net amount in UI |
| Operational impact | Quick Action Credit note opens Amendment wizard |
| Accounting impact | CN posts DR revenue + VAT / CR AR (company **2** roles). Guest credit reclass when opted. |
| Permissions | ARS Core (`create_credit_note`) |
| Source of confirmation | Phase 2D BC-06/08 interim; FIN-001; **Needs business confirmation** for refundable defaults and cash-refund vs credit-only |
| Related code paths | `ars_adapter_create_credit_note`, Amendment modal |
| Status | **Draft** |
| Approved by | — |
| Date | 2026-07-20 |

## Explicitly separate from

- **Deposit refund** — already live; do not use this CN for deposit liability release.
- **Cancel booking** — full reverse / fee path via cancel financials, not this button alone.

## Needs business confirmation

1. Default guest-credit vs immediate cash/bank refund for stay CN.
2. Whether CN must always reference a parent financial document id.
