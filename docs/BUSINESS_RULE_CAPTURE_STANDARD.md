# Business Rule Capture Standard

**Stage:** 2  
**Purpose:** Define **how** confirmed business rules will be recorded in the future.  
**This file does not contain speculative business rules.**

## Why this exists

HeroSysgro supports many valid commercial scenarios (multiple leases per tenant, flexible cheque counts, varied fee treatments, operational schedules distinct from accounting recognition, etc.). Agents and developers must **not** invent universal rules from incomplete code reading.

When behaviour is unclear:

1. Document current code behaviour.  
2. Use evidence labels.  
3. List supported scenarios observed.  
4. Mark policy as Needs business confirmation.  
5. Do not declare a universal rule.  
6. Do not remove flexibility without evidence.

Invoice Mode is the official RE **accounting** architecture; Legacy is historical. Cleaning accounting remains separate. These are architectural decisions (`docs/ERP_DECISIONS.md`), not substitutes for operational commercial rules.

---

## Where rules will live (future)

Suggested path (create only when first rule is confirmed):

`docs/business-rules/` with one file per domain or a register `docs/BUSINESS_RULES_REGISTER.md`.

**Do not create a populated BUSINESS_RULES.md in Stage 2.**

---

## Required fields for every confirmed rule

| Field | Description |
|-------|-------------|
| **Rule ID** | e.g. `BR-RE-014` |
| **Module** | Real Estate / Construction / Cleaning / HR / … |
| **Workflow** | Named workflow (lease create, receipt allocation, …) |
| **Business event** | Event from accounting/ops catalog |
| **Confirmed rule** | Precise normative statement |
| **Supported scenarios** | Explicit list of allowed cases |
| **Exceptions** | Documented exceptions |
| **Configuration options** | Settings keys / flags if any |
| **Operational impact** | What staff must do |
| **Accounting impact** | Documents/journals affected |
| **Permissions** | Who may perform |
| **Source of confirmation** | Person/role/meeting/ticket |
| **Related code paths** | Files/functions |
| **Status** | `Draft` / `Confirmed` / `Deprecated` |
| **Approved by** | Name/role |
| **Date** | ISO date |

---

## Template

```markdown
## BR-XX-000 — Title
| Field | Value |
|-------|-------|
| Rule ID | BR-XX-000 |
| Module | |
| Workflow | |
| Business event | |
| Confirmed rule | |
| Supported scenarios | |
| Exceptions | |
| Configuration options | |
| Operational impact | |
| Accounting impact | |
| Permissions | |
| Source of confirmation | |
| Related code paths | |
| Status | Draft |
| Approved by | |
| Date | |
```

---

## Status definitions

| Status | Meaning |
|--------|---------|
| Draft | Proposed from workshop; not binding |
| Confirmed | Approved; agents may treat as normative |
| Deprecated | Superseded; keep for history |

---

## Agent instructions

- Never promote Strong inference to Confirmed rule without human approval.  
- Never collapse multiple supported scenarios into one mandatory path.  
- Link accounting impacts to Invoice Mode vs Legacy explicitly when relevant.
