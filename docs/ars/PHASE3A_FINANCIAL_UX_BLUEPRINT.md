# Phase 3A — Financial UX Blueprint (No Core Rewrite)

**Constraint:** Financial Core v1.0 frozen. UI presents state and calls contracts only. Adapter remains **OFF by default**.

---

## Presentation surfaces

| Surface | Shows | Actions (via contracts) |
|---------|-------|-------------------------|
| Booking financial summary | Due, paid, credit, deposit, lock | Collect pay, apply credit (when wired) |
| Financial documents | Type, #, status, JV | View only + reverse if contract allows |
| Payments | Allocations | Record / partial |
| Guest credit | Balance, history | Apply / refund |
| Refunds | Source, amount | Refund wizard |
| Credit notes | CN docs | Create via adapter when UI wave |
| Deposits | Held / released | Receive / refund / forfeit / damage |
| Stripe | Intents, clearing | Settlement UI (sim/tools carefully) |
| AR / revenue / VAT | Reports | Read |

## Visual separation

| Lane | Meaning | UI treatment |
|------|---------|--------------|
| Operational | CI/CO, notes, HK | Neutral |
| Financial | Money mutation | Accent + confirm |
| Posted | Immutable record | Lock icon, read-only |
| Reversible | Reverse path exists | Secondary “Reverse” |
| Locked | Financial lock on | Disabled + explanation |
| Approval-required | Future maker/checker | Badge; do not invent thresholds |

## Trust patterns

- Confirm copy: “This will post journal … / create document …” when posting.  
- Show document number after success.  
- Never show editable “recalculated” totals that disagree with server.  
- Settings: adapter toggle = danger zone, confirm + reason log (UX only).

## Finance hub IA

Single Finance home with tiles linking reports + document search — fixes UX-004/005.
