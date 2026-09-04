# Invoice Mode — Early Lease Termination Guide

**Example lease:** AYLARESI-301-0001 (Lease #202)  
**Scenario:** Tenant paid through **CHQ-202-7** (Feb 2026). **CHQ-202-8** is kept and paid as **early termination penalty**. Remaining cheques are returned.

---

## 1. What must happen (accounting view)

| Item | Action |
|------|--------|
| Rent through Feb 2026 (CHQ-1 … CHQ-7) | **No change** — already collected and invoiced |
| Mar–Jul rent obligations | **Cancel / waive** |
| Unpaid invoices INV-2026-00196 … 00200 | **Void** + reverse revenue journals |
| CHQ-202-8 (Mar row) | **Keep pending** — collect as **penalty**, not rent |
| CHQ-202-9 … CHQ-202-12 | **Return** to tenant |
| Apr–Jul schedule rows | **Cancel** |
| Penalty billing item | **Create** (10,750 AED if using full cheque amount) |
| Penalty obligation + candidate | **Auto-generated** after termination |

---

## 2. Owner / Admin — Termination steps

**URL:** `lease_terminate.php?lease_id=202`

### Step 1 — Effective termination date

Choose the **contractual** termination date (e.g. **2026-02-28** or **2026-03-01**).

- This is **not** today’s system date.
- The field is labelled **Effective Termination Date**.
- After save, lease view shows this date; “Recorded in system on …” is only when the action was logged.

Click **Preview Impact**.

### Step 2 — Cheques and installments

| Row | Cheque | Default action |
|-----|--------|----------------|
| Mar 2026 | CHQ-202-8 | **Do NOT return** — select as penalty cheque (Step 3) |
| Apr–Jul | CHQ-202-9 … 12 | **Return** (checked) |
| Apr–Jul installments | — | **Cancel** (checked) |

Paid/cleared rows (CHQ-1 … 7) appear under **Protected** and cannot be changed.

### Step 3 — Penalty collection cheque

Under **Penalty collection cheque**, select:

> **CHQ-202-8 — 10,750.00 AED on 2026-03-10**

Effects:

- Cheque stays **pending** (not returned)
- Installment row reclassified as **penalty**
- Penalty billing item created for **10,750 AED** (unless you choose fixed/months penalty instead)
- System generates **penalty obligation** and **invoice candidate**

Leave **Penalty mode** = “No separate penalty billing item” when the cheque amount *is* the penalty.

### Step 4 — Invoice Mode voids (auto-selected on preview)

Confirm these are checked:

- **Void invoices:** INV-2026-00196 through INV-2026-00200
- **Cancel obligations:** Mar–Jul rent obligations

System reverses each invoice’s **Dr AR / Cr Revenue / Cr VAT** journal.

### Step 5 — Apply

1. Tick confirmation (shows your effective date)
2. Click **Apply Termination**

Success message example:

> Lease terminated effective 2026-02-28. 4 installment(s) cancelled, 4 PDC cheque(s) returned, 5 invoice(s) voided, 5 obligation(s) cancelled, penalty cheque retained for collection, termination penalty created.

---

## 3. Accountant — After termination

### Collect penalty on CHQ-202-8

1. Open lease view → Payment Schedule
2. Find **CHQ-202-8** (penalty row, still pending)
3. Click **Allocate Payment**
4. Enter receipt details (cleared cheque / bank transfer)
5. Save

Receipt should allocate to the **penalty obligation** (issue penalty invoice from **Invoice Preview** first if your process requires an official invoice before collection).

### Do not

- Re-allocate to voided rent invoices (196–200)
- Record payment against cancelled Apr–Jul rows
- Re-issue rent invoices for Mar–Jul

---

## 4. Verification checklist

- [ ] Lease status = **Terminated**
- [ ] Termination date = chosen effective date (not today unless intended)
- [ ] CHQ-202-1 … 7 = **Cleared** (unchanged)
- [ ] CHQ-202-8 = **Pending** → then **Collected** after penalty payment
- [ ] CHQ-202-9 … 12 = **Returned**
- [ ] Invoices 196–200 = **Cancelled**
- [ ] Obligations Mar–Jul = **Cancelled**
- [ ] Penalty billing item exists (10,750 AED)
- [ ] GL: reversal journals exist for voided invoices
- [ ] Unit status = **Vacant** (if no other active lease on unit)

---

## 5. Fixes included in this release

| Issue | Fix |
|-------|-----|
| Termination date ignored on Apply | Single form — date field is submitted with Apply |
| “Applied” showed system timestamp | Lease view now says **Recorded in system on …** separately from effective date |
| Cleared cheques still offered for cancel | Installments with cleared cheques / cheque-linked payments are **protected** |
| Invoice Mode ignored on termination | Voids unpaid invoices, cancels obligations, reverses revenue journals |
| Penalty on existing cheque | **Penalty collection cheque** dropdown keeps one PDC for collection |

---

*For standard rebook workflow after full financial reset, see `INVOICE_MODE_REBOOK_STAFF_GUIDE.md`.*
