# Phase 2D — Accounting Validation

**Ledger:** Shared RE `re_*` via Financial Adapter  
**Engine SHA:** `9cbb880fc3241615b69d4fe5b3adfbee14e513d03964924214cc9325f9d7c7d6` (unchanged)  

| Event | Dr / Cr roles | UAT |
|-------|---------------|-----|
| Original invoice | AR / Room+VAT | PASS (prior + 2D) |
| Overpay reclass | AR / Guest Credit | PASS |
| Apply credit | Guest Credit / AR | PASS |
| Refund credit | Guest Credit / Cash | PASS |
| Extension | AR / Room+VAT | PASS balanced |
| Service | AR / Extra Charges+VAT | PASS |
| Damage | AR / Damage rev+VAT | PASS |
| Forfeit | Deposit liab / Forfeit rev | PASS |
| Credit note | Room+VAT / AR | PASS |
| Adjustment | AR / Late fee or Room | PASS |
| Stay refund | AR / Cash | PASS |
| Stripe card | Clearing / AR | PASS |
| Stripe settle | Bank+Fee / Clearing | PASS |
| Cancel reverse | reverse_journal | PASS |
| No-show keep revenue | ops only | PASS |
| Deferred 2400 | unused (BC-02/03 A) | Confirmed by design |
| Tourism | none | N/A |

All exercised journals balanced Dr=Cr in UAT.
