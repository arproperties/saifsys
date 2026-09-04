# BR-ARS-FIN-003 — Stay extension invoice

| Field | Value |
|-------|-------|
| Rule ID | BR-ARS-FIN-003 |
| Module | ARS (+ shared RE ledger company) |
| Workflow | Booking View Amendment → Extend stay / lifecycle extension with money |
| Business event | Guest stays additional nights under the same booking |
| Confirmed rule | **Draft — interim from Phase 2D BC-09 + BR-ARS-FIN-001.** Extension posts a new `extension_invoice` for **added nights only** at `rate_override` else booking `nightly_rate`. Same booking (no duplicate booking). Availability recheck required by adapter. VAT same as booking. On success, ops `check_out` / `nights` update to the new date. Never silent date rewrite without the financial document when money is due. |
| Supported scenarios | One-night late departure; multi-night extension mid-stay |
| Exceptions | Pure lifecycle date request with **no** money still uses lifecycle requests tab; approve may still require amendment when financially locked. Zero-amount edge cases follow adapter validation. |
| Configuration options | Nightly rate on booking; optional rate override on adapter call |
| Operational impact | Extend stay Quick Action → Amendment → extension invoice; journals on company 2 |
| Accounting impact | ROOM_REVENUE (**4130** HH) + VAT; AR_GUEST (**1340**). Original stay invoice unchanged. |
| Permissions | ARS Core (`create_extension_invoice`) |
| Source of confirmation | Phase 2D BC-09 interim; FIN-001 posting company; **Needs business confirmation** for promotional rates / package extensions |
| Related code paths | `ars_adapter_create_extension_invoice`, Amendment modal, lifecycle requests |
| Status | **Draft** |
| Approved by | — |
| Date | 2026-07-20 |

## Needs business confirmation

1. Whether extension always uses stay nightly rate or allows negotiated override in UI.
2. Whether lifecycle approve must always create the invoice before updating dates when locked.
