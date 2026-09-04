# Invoice Mode — Staff Guide After Financial Rebook

**Audience:** Data Entry team and Accounting team  
**Purpose:** Rebuild lease schedules and record payments correctly after the system financial reset  
**Last updated:** June 2026

---

## 1. What happened?

Management ran a **Financial Rebook** on all leases. This was intentional.

| **Kept (unchanged)** | **Removed (must be rebuilt / re-entered)** |
|----------------------|--------------------------------------------|
| Lease terms (rent, dates, fees, VAT settings) | Old payments and receipts |
| Tenant, unit, building links | Old receipt allocations |
| Lease documents and contract files | Old invoices and invoice items |
| Accounting mode = **Invoice Mode** | Old obligations and invoice candidates |
| | Old payment schedule (installments + cheques) |
| | Old GL journals linked to the lease |

**Important:** The lease still exists, but its **payment schedule and financial history are empty**. Each lease must be opened, reviewed, and saved again before accounting can record payments.

---

## 2. Big picture — who does what?

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   DATA ENTRY    │     │     SYSTEM      │     │   ACCOUNTANT    │
│  Edit + Save    │ ──► │ Schedule +      │ ──► │ Allocate        │
│  each lease     │     │ Obligations +   │     │ Payment per row │
│                 │     │ Invoice Cands   │     │                 │
└─────────────────┘     └─────────────────┘     └─────────────────┘
```

1. **Data Entry** — Opens each lease in **Edit Lease**, checks terms and schedule, clicks **Save**.
2. **System** — Automatically creates the operational schedule (installments/cheques), **Lease Obligations**, and **Invoice Candidates**.
3. **Accountant** — When money is received, uses **Allocate Payment** on each schedule row (never the old generic “Record Payment” flow without linking to a row).

---

## 3. Data Entry — step-by-step

### 3.1 Your job (summary)

For **every active Invoice Mode lease**:

1. Open the lease → click **Edit Lease**
2. Review **Lease Terms** tab (dates, rent, fees, VAT — usually no change needed)
3. Open the **Installments / Payment Schedule** tab
4. Confirm cheque numbers, dates, and amounts match the tenant’s contract / PDC pack
5. Click **Save**
6. Confirm the green success message mentions obligations and invoice candidates were synced

You do **not** need to open Obligations or Invoice Candidates screens manually — Save handles that.

### 3.2 Where to go

| Action | Menu / link |
|--------|-------------|
| Find leases | Real Estate → **Leases** |
| Edit a lease | Lease view → **Edit Lease** (or Leases list → Edit) |
| Jump to schedule | Edit Lease URL with `#installments` at the end |

### 3.3 What to check on the Installments tab

- **Number of installments** matches the contract
- **Cheque / reference numbers** are correct (do not leave blank if tenant provided PDCs)
- **Dates** match due dates on cheques or bank transfer schedule
- **Amounts** match the lease total (system shows expected total vs schedule total)
- **Payment method** per row: Cheque, Bank Transfer, etc. — must match how tenant pays
- **Separate fee rows** (security deposit, admin, VAT separate payment) appear if configured on the lease

If the schedule total does not match the lease total, fix the rows or contact your supervisor — do not save with a mismatch unless approved.

### 3.4 What Save creates automatically

After Save on an **Invoice Mode** lease, the system:

- Rebuilds **installments** and **schedule rows** (cheques / bank transfer lines)
- Creates or updates **Lease Obligations** (accounting expectations per period/fee)
- Creates **Invoice Candidates** (ready for the accountant to issue invoices when due)

Success message example:

> Lease updated successfully. Obligations synced (8 created, 0 updated, 0 unchanged). Invoice candidates prepared (8 new, 0 already existed).

### 3.5 What Data Entry must NOT do

- Do **not** record payments — that is Accounting’s job
- Do **not** use **Record Payment** or legacy payment screens for Invoice Mode leases
- Do **not** skip Save thinking the old schedule still exists — it was deleted
- Do **not** change **Accounting Mode** unless Owner/Admin instructs you

---

## 4. Data Entry — full worked example

### Example lease

| Field | Value |
|-------|-------|
| Lease number | **111-0001** |
| Building / Unit | AYLA RESIDENCE — Unit **111** |
| Tenant | Jawad Ahmed Inqulab Ahmed |
| Annual rent | **25,000.00 AED** |
| Installments | **3** (≈ 8,333.33 AED rent each) |
| Security deposit | **1,250.00 AED** (separate row) |
| Admin fees | **0.00** |
| Payment method | **Cheque** |
| Mode | **Invoice Mode** |

### Steps

**Step 1 — Open the lease**

- Go to **Leases** → search **111-0001** → open lease view  
- Confirm badge shows **Invoice Mode**

**Step 2 — Edit Lease**

- Click **Edit Lease**
- **Lease Terms tab:** Confirm start/end dates, annual rent 25,000, 3 installments, deposit 1,250 — adjust only if contract differs

**Step 3 — Installments tab**

- You should see rows similar to:

| # | Type | Due date | Amount | Cheque # | Method |
|---|------|----------|--------|----------|--------|
| 1 | Security deposit | (lease start) | 1,250.00 | *(enter tenant cheque #)* | Cheque |
| 2 | Rent period 1 | *(date)* | 8,333.33 | *(enter)* | Cheque |
| 3 | Rent period 2 | *(date)* | 8,333.33 | *(enter)* | Cheque |
| 4 | Rent period 3 | *(lease end)* | 8,333.34 | *(enter)* | Cheque |

- Enter real cheque numbers from the tenant’s PDC pack (e.g. `000045`, `000046`, …)
- Adjust dates only if they differ from the generated schedule **and** match the contract

**Step 4 — Save**

- Click **Save**
- You are redirected to **Lease View**
- Green banner should confirm obligations and invoice candidates were synced

**Step 5 — Quick verification (Data Entry)**

On lease view, check:

- **Payment Schedule** table shows all rows as **Pending** (nothing paid yet)
- **Invoice Mode** summary cards show Obligations > 0 and Invoice Candidates > 0

**Done for this lease.** Repeat for the next lease in your list.

---

## 5. Accountant — step-by-step

### 5.1 Your job (summary)

After Data Entry has **Saved** a lease:

1. When tenant payment is received (cheque cleared, bank transfer confirmed, cash deposit, etc.)
2. Go to the **lease view** → **Payment Schedule**
3. Find the matching schedule row
4. Click **Allocate Payment** on that row
5. Enter receipt details and amount → confirm allocation

**Golden rule:** Always allocate **from the schedule row** (`Allocate Payment` button). This links the receipt to the correct cheque/row. Do not use unrelated payment entry that skips the schedule row.

### 5.2 Invoice Mode vs Legacy

| | Legacy Mode | Invoice Mode (current standard) |
|---|-------------|----------------------------------|
| Schedule | Accounting + operational | Operational only |
| Record payment | Old payment screens | **Allocate Payment** per row |
| Invoices | Manual / varied | **Invoice Candidates** → issue when eligible |
| Partial payments | Limited | **Supported** — multiple receipts on one row |

### 5.3 Allocate Payment screen

Opened from lease view → schedule row → **Allocate Payment**.

| Field | Guidance |
|-------|----------|
| **Receipt source** | Cleared Cheque / Bank Transfer / Cash Deposit / Cash / Card |
| **Amount** | Defaults to **remaining** on that row — change only for partial payment |
| **Cleared date** | Bank/value date when money is confirmed |
| **Reference** | Cheque number or bank reference |
| **Receipt account** | GL bank/cash account (as per company chart) |

After save:

- Row shows **Partially Collected** until fully paid, then **Collected**
- **View Receipt** or **View Receipts (2)** if multiple partial payments on same row

### 5.4 Issuing invoices (separate step)

Obligations and **Invoice Candidates** are created when Data Entry saves the lease.

When a candidate is **eligible** (due date reached):

1. Real Estate → Accounting → **Invoice Preview** (or lease view → **Invoice Candidates**)
2. Select the lease
3. **Issue** the eligible candidate → system assigns official invoice number and posts AR/Revenue/VAT

Issuing invoices is **not** the same as receiving payment. Both may be needed on Invoice Mode leases.

### 5.5 What Accountant must NOT do

- Do **not** allocate before Data Entry has saved the lease (no schedule rows exist)
- Do **not** use legacy **Record Payment** / **Collect Balance** on Invoice Mode leases
- Do **not** use generic receipt allocation **without** selecting the schedule row (cheque link)
- Do **not** mark cheques “cleared” from the cheque dropdown expecting full accounting — use **Allocate Payment**

---

## 6. Accountant — full worked example

Same lease as above: **111-0001**, 25,000 AED / 3 installments + 1,250 deposit. Data Entry has already saved; schedule is pending.

### Scenario A — Full cheque payment (one row)

**Situation:** Tenant’s rent cheque **#000046** for **8,333.33 AED** (period 1) cleared at the bank.

| Step | Action |
|------|--------|
| 1 | Open lease **111-0001** → Payment Schedule |
| 2 | Find rent row 1 — amount 8,333.33, cheque #000046, status **Pending** |
| 3 | Click **Allocate Payment** |
| 4 | Receipt source: **Cleared Cheque** |
| 5 | Amount: **8,333.33** (remaining) |
| 6 | Cleared date: actual bank date |
| 7 | Reference: `000046` |
| 8 | Select correct **Receipt account** → Save |

**Result:** Row status → **Collected**. **View Receipt** available. Obligation allocation updated.

---

### Scenario B — Partial payments on one row (bank transfer)

**Situation:** Rent row 2 total **8,333.33 AED**. Tenant sends **5,000.00** by bank transfer today; **3,333.33** will come next week.

**First receipt — 5,000.00**

| Step | Action |
|------|--------|
| 1 | Lease view → row 2 → **Allocate Payment** |
| 2 | Receipt source: **Bank Transfer** |
| 3 | Amount: **5,000.00** |
| 4 | Reference: tenant bank transfer ref |
| 5 | Save |

**Result:** Row → **Partially Collected**. Remaining **3,333.33**. Button still shows **Allocate Payment**.

**Second receipt — 3,333.33**

| Step | Action |
|------|--------|
| 1 | Same row → **Allocate Payment** again |
| 2 | Amount defaults to **3,333.33** (remaining) |
| 3 | Receipt source: **Bank Transfer** |
| 4 | Save |

**Result:** Row → **Collected**. Lease view shows **View Receipts (2)**.

---

### Scenario C — Security deposit via cash deposit

**Situation:** Tenant pays deposit **1,250.00** in cash deposited to office bank.

| Step | Action |
|------|--------|
| 1 | Find **Security deposit** row (1,250.00) |
| 2 | **Allocate Payment** |
| 3 | Receipt source: **Cash Deposit** |
| 4 | Amount: **1,250.00** |
| 5 | Cleared date + receipt account → Save |

**Result:** Deposit row collected; security deposit obligation allocated per Invoice Mode rules.

---

## 7. Daily checklist

### Data Entry (per lease)

- [ ] Lease opened in Edit Lease
- [ ] Terms reviewed against contract
- [ ] Schedule tab: dates, amounts, cheque numbers, payment methods correct
- [ ] Save completed
- [ ] Success message shows obligations + candidates synced
- [ ] Lease view shows schedule rows (pending)

### Accountant (when money received)

- [ ] Data Entry confirmed lease was saved first
- [ ] Correct schedule row identified
- [ ] **Allocate Payment** used (not legacy payment)
- [ ] Receipt source and reference correct
- [ ] Partial vs full amount correct
- [ ] Row status updated (Partially Collected / Collected)
- [ ] Issue invoice from **Invoice Preview** when candidate is eligible (if required by your process)

---

## 8. Troubleshooting

| Problem | Likely cause | What to do |
|---------|--------------|------------|
| No schedule rows on lease view | Data Entry has not saved after rebook | Data Entry: Edit Lease → Installments → Save |
| No **Allocate Payment** button | Row has no cheque/schedule link, or already fully collected | Refresh after save; check row not already Collected |
| Obligations / candidates = 0 | Save failed or lease not Invoice Mode | Re-save; confirm Invoice Mode badge on lease view |
| “Cannot save” / schedule mismatch | Totals don’t match lease | Fix schedule amounts or get supervisor approval |
| Receipt not linked to row | Payment recorded outside Allocate Payment | Use **Allocate Payment** only; contact admin to fix orphan receipts |

---

## 9. Quick reference — menu paths

| Task | Path |
|------|------|
| List leases | Real Estate → Leases |
| Edit lease / schedule | Lease view → Edit Lease → Installments tab |
| Allocate payment | Lease view → Payment Schedule → **Allocate Payment** |
| View obligations | Lease view → **Obligations** (or Accounting → Obligation Preview) |
| View / issue invoices | Lease view → **Invoice Candidates** (or Accounting → Invoice Preview) |
| Receipt diagnostics | Accounting → Receipts & Allocations |

---

## 10. One-line reminders

**Data Entry:** *Open → Review schedule → Save → next lease.*

**Accountant:** *Find the row → Allocate Payment → match amount and reference → issue invoice when due.*

---

*Questions or blocked leases: contact your Real Estate system administrator or Owner.*
