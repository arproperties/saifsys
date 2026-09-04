# Phase 3A — Workflow Audit

**Financial rule:** UI must call Financial Core v1.0 contracts; no calculation rewrite.

| Workflow | Current steps (approx) | Pages | Clicks (est.) | Confusion / risk | Future |
|----------|------------------------|-------|---------------|------------------|--------|
| New reservation | List → Add → fill long form → save | bookings, booking_add | 15–40 | Price preview easy to miss; availability separate | Wizard 7 steps |
| Quote → booking | Manual; no first-class quote | — | — | No quote artifact | Optional quote draft in wizard |
| Confirm | Open booking → Confirm | booking_view | 2–3 | Lock engages silently for some | Confirm with financial preview modal |
| Guest registration | Guests or inline on add | guests / add | 5–15 | Duplicate guests | Lookup + create in wizard |
| Check-in / out / complete | Header buttons | booking_view | 1–2 | No readiness checklist | Workspace checklist |
| Extension | Lifecycle request / manual dates when unlocked | booking_view | 8–20 | Locked path unclear | Extension action → adapter contract |
| Shorten / early CO | Limited UI | booking_view | High | Financial CN path not surfaced | Guided shorten → adapter |
| Cancellation | Cancel confirm | booking_view | 2 | Fee policy not visible | Cancel wizard with policy summary |
| No-show | Manual cancel/status | — | — | No dedicated UX | No-show action → adapter |
| Additional services | Charge modal | booking_view | 5–10 | Locked disables without guidance | Service drawer → adapter |
| Payment / partial | Payment modal | booking_view | 5–8 | Methods OK; credit obscure | Payment drawer + allocation feedback |
| Guest credit | No dedicated UI | — | — | Overpay creates credit invisibly | Credit panel on guest/booking |
| Refund | Deposit refund modal; stay refund weak UI | booking_view | 5–10 | Sources unclear | Refund wizard → adapter |
| Deposit collect/refund | Deposit cards/modals | booking_view | 5–12 | Mixed with stay pay | Deposit section clarity |
| Damage / forfeit | Charge type / no forfeit UI | booking_view | High | Forfeit not exposed | Damage + forfeit flows |
| Housekeeping | Separate page | housekeeping | 3–8 | Not linked to checkout strongly | Ops board + booking chip |
| Unit block / maint | Separate pages | blocked/maint | 5–15 | Calendar not source of truth | Calendar + boards |
| Stripe pay (guest) | Stay/portal + intents | stay / stripe | — | Staff settlement UX missing | Finance settlement UI |
| Fin doc review | Deep link / hidden reports | financial_* | 3–10 | Not in nav | Finance hub |
| Management reporting | revenue + RE reports | revenue, COA | 5–15 | Fragmented | Finance + Reports IA |

## Cross-cutting opportunities

1. Reduce booking_view density via workspace regions.  
2. Surface financial document numbers after confirm.  
3. Always show lock + “why disabled”.  
4. One Occupancy calendar as availability source of truth.  
5. Command Center for today triage.
