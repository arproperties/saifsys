# Prompt: re-implement the Move-In / Move-Out changes on the latest live code

Copy everything below the line into a new chat, in the project folder that holds the code downloaded from live.

---

You are working on a PHP 8 / MySQL (PDO) / Bootstrap 5.3 property-management app ("POSsys"), Real Estate module, in `modules/realestate/`. This codebase is the **latest copy downloaded from the live server**. A set of Move-In / Move-Out improvements was built and tested on an older local copy. Your job is to **re-implement the same behaviour here**, merging into the current files.

## Ground rules (read first)

1. **Inspect before you change.** The live files may differ from what is described here: other people may have edited them, and some of these changes may already be partly present. Read each file fully, compare it with the spec below, and **merge**. Never overwrite a whole file with a remembered version. Keep any unrelated live changes.
2. **Do not write test data to the real database.** For anything that writes, test against a throwaway database cloned with `mysqldump --single-transaction` (structure plus data), and drop it afterwards. Read-only page renders against the real DB are fine.
3. **Back up first.** Before editing, copy every file you will touch into `_backups/move_in_out_<date>/` (same relative paths) and add a short `RESTORE.md`. Do not upload `_backups/` to live.
4. **Match the codebase.** Use the existing helpers and idioms: `csrf_field()`, `csrf_verify()` and `csrf_token()` (field name **`_csrf`**); `has_role('Owner'|'Admin', $conn)`; `current_company_id($conn)`; `current_user_id()`; `AuditService::logEvent([...])` (see `docs/AUDIT_INTEGRATION_GUIDE.md`); `re_lease_lifecycle_audit()` in `includes/lease_lifecycle_guard.php`; the layout `includes/re_layout_header.php` / `re_layout_footer.php` with `$pageStyles` / `$pageHead`.
5. After each part, run `php -l` on every changed file and render the affected pages read-only to catch PHP errors. Report what you changed, what you tested, and anything you could not verify.
6. One database migration is needed (Part 4). Create the file, but **ask before running it** on any real database.

## Lease statuses (context)

`re_leases.status` ∈ `draft, active, expired, terminated, renewed, has_legal_case`. Move-ins live in `re_move_ins` (status `pending, in_progress, completed, cancelled`). Move-outs live in `re_move_out_notices` plus `re_move_outs` (status `pending, inspection_scheduled, inspection_completed, deposit_processing, completed, cancelled`).

---

## Part 1: Searchable lease picker on Move-In / Move-Out create pages

Files: `move_in_add.php`, `move_out_add.php`, and a new shared helper `includes/re_lease_picker.php`.

**Lease list (both pages):** show leases of **any status**. Remove every `l.status = 'active'` filter, including in the POST validation query. Select `l.status` too.

**Opened from the side menu (no `?lease_id=`):**
- Turn `<select id="lease_id">` into a **type-to-search dropdown using Select2**, the same build `lease_add.php` already uses: jQuery 3.7.1, `select2@4.1.0-rc.0`, `select2-bootstrap-5-theme@1.3.0`. Load the CSS through `$pageHead`.
- Above the field, add two quick filters: **Status** (with a count per status) and **Building**. Use a custom Select2 `matcher` that applies those filters and matches every typed word against a `data-search` attribute on each option. The searchable text is lease number, building, unit, tenant name, phone, email and status.
- Each option shows `LEASE-NO - Building - Unit - Tenant [Status]` and carries `data-status`, `data-building` and `data-search`.
- Under the field, show a counter: "N available · M not available".
- Select2 fires jQuery-only `change` events. Re-dispatch them as native events, e.g. `$select.on('change', e => { if (e.isTrigger) select.dispatchEvent(new Event('change')); })`, so the page's existing `addEventListener('change')` handlers (Lease Details card, default dates) keep working.

**Opened from the lease page (`?lease_id=X`):**
- Show **no search and no filters**. Show the lease as a **locked read-only field** (lock icon plus the label above) with a **"Choose another lease"** button that links to the page without `lease_id`. Keep a hidden `<select id="lease_id">` with that single option `selected`, so the existing JS fills the Lease Details card.
- `move_out_add.php` previously ignored `?lease_id=`. Make it pre-select the lease the same way, and set the intended move-out date to the lease end date.

**Lease Details card:** also show the lease **Status**.

## Part 2: Block leases that cannot be picked

**Move-In list:** a lease with any non-cancelled move-in (pending, in progress or completed) is listed but **`disabled` (greyed out)**, with a suffix such as `— Move-in Completed`. Sort these to the bottom. The POST already rejects a lease with a non-cancelled move-in; keep that. If opened from a lease that already has a move-in, don't lock it. Show a warning "Lease X already has a move-in (Status). **View move-in**" (link to `move_in_view.php?id=`) and fall back to the searchable picker.

**Move-Out list:** a lease is not selectable when any of these is true, checked in this priority order:
1. It has a non-cancelled `re_move_outs` record. Label: "Move-out <Status>", plus a **View move-out** link when opened from a lease.
2. It has a non-cancelled `re_move_out_notices` record. Label: "Move-out notice exists".
3. **The lease is `draft`.** See Part 3; this one is selectable but refused with a popup.
4. **It has no completed move-in**, i.e. no `re_move_ins` row with `status='completed'`. Label: "No completed move-in". When opened from the lease, show "has no completed move-in… **Go to Move-In**".

**Server side (`move_out_add.php` POST):** refuse draft leases, refuse leases without a completed move-in, and refuse leases with an existing notice **or** move-out record (check both tables).

## Part 3: "Lease status is Draft" popup (no move-out for draft leases)

- Add a shared Bootstrap modal `re_lease_draft_popup()` to `includes/re_lease_picker.php`, plus a JS function `showDraftLeasePopup(leaseNumber, leaseId)`. Title: "Lease status is Draft". Body: the lease is still Draft, a move-out cannot be created, activate it first (Edit Lease → Status: Active). Buttons: **OK** and **Edit Lease** (`lease_add.php?id=`).
- **Move-Out dropdown:** draft options are **not** `disabled`. Mark them `data-blocked="1"` with the label suffix "— Lease is Draft". Selecting one opens the popup and clears the selection. The counter counts them as not available.
- **Move-Out page opened from a draft lease's URL:** show the popup on load, plus a warning with an Edit Lease link.
- **`lease_view.php`:** for a draft lease, the **Move-Out** button opens the popup instead of linking to `move_out_add.php`. Include the modal only for draft leases.
- **Server:** refuse with "Lease X is in Draft status. Activate the lease before creating a move-out."

## Part 4: "Mark as Moved In" (historical move-in)

For tenants who moved in before the Move-In module was used.

**Migration** `migrations/add_re_move_ins_is_historical.sql`. It's idempotent; ask before running it:

```sql
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_move_ins' AND COLUMN_NAME = 'is_historical');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE `re_move_ins` ADD COLUMN `is_historical` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`',
  'SELECT "Column is_historical already exists" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

**Helper** `includes/move_in_historical_helper.php`:
- `re_move_in_historical_ready($conn)` checks that the column exists.
- `re_move_in_open_record($conn, $leaseId)` returns the latest non-cancelled move-in.
- `re_move_in_historical_default_date($lease)` returns the lease `move_in_date`, else the start date.
- `re_move_in_mark_historical($conn, $companyId, $userId, $leaseId, $date, $reason)` saves it. It validates: column exists, reason is required, the date is valid and not in the future, the lease belongs to the company, and the lease is not draft. Inside a transaction it locks the lease row (`SELECT … FOR UPDATE`) and refuses if a non-cancelled move-in exists. It then:
  - inserts a `re_move_ins` row: `status='completed'`, `is_historical=1`, `approved_by` and `approved_at`, and `notes='Marked as moved in (historical entry, no checklist): <reason>'`;
  - updates the lease: `move_in_completed=1`, `move_in_date = COALESCE(move_in_date, ?)`;
  - sets the **unit to occupied only if the lease is `active`**;
  - writes an `AuditService::logEvent` entry with action `move_in_marked_historical`.

**Endpoint** `move_in_mark_historical.php`: POST only, `csrf_verify()`, **Owner/Admin only**. It calls the helper, sets a session success or error message, and redirects to `lease_view.php?id=`.

**`lease_view.php`:** next to the Move-In button, show **Mark as Moved In** only when all of these hold: there is no completed move-in, the lease is not draft, the user is Owner/Admin, the migration has been run, and the lease has no non-cancelled move-in. It opens a modal with:
- the move-in date, pre-filled and `max` today;
- a required reason;
- a warning that no checklist, inspection or meter readings will be recorded;
- a note on whether the unit will be set to Occupied (active leases only).

**Badges:** on `move_in.php` and `move_in_view.php`, show a "Historical" / "Historical entry: no checklist" badge when `is_historical=1`. On the view page, explain that there is no checklist.

## Part 5: Move-Out details page (`move_out_view.php`) fixes

1. **CSRF bug (critical):** the page's JS sent `csrf_token: '<?= $_SESSION['csrf_token'] ?>'`, which is the wrong field and an unset session key. Every action failed with HTTP 419 and showed nothing, because there is no `.catch`. Use `_csrf` with `csrf_token()`. Route every action (schedule inspection, complete inspection, add damage, meter reading, calculate deposit, approve settlement, process refund, complete move-out) through one `postAction()` helper that parses the response text as JSON and **alerts the server message on any failure**, including non-JSON responses.
2. **Complete Move-Out: Expired or Terminated choice.** The button opens a modal:
   - **Lease active:** the user picks **Expired** or **Terminated**. Pre-select Terminated if the move-out date is before the lease end date, with a hint.
     - **Expired:** sets the lease to `expired`.
     - **Terminated:** must go through the existing **Terminate Lease** workflow (`lease_terminate.php`, because `re_lease_change_status_guarded()` forbids a direct `terminated`). Show an **Open Terminate Lease** button linking to `lease_terminate.php?lease_id=X&termination_date=<move-out date clamped to the lease period>&return_move_out=<id>`, and disable Complete.
   - **Lease already expired/terminated/other:** show "status will not be changed".
   - **Server:** require the inspection to be completed, refuse if already completed, validate the choice (`terminated` on an active lease → "Terminate the lease first"), and run it in a transaction. Set the **unit to vacant only if no other active lease holds that unit**. Write `re_lease_lifecycle_audit('move_out_completed', …)`. The catch block must roll back an open transaction (catch `Throwable`).
3. **`lease_terminate.php`:** accept `return_move_out` from GET or POST and validate that it belongs to the same lease and company. Carry it in a hidden field. After a successful termination, redirect to `move_out_view.php?id=<return_move_out>`; otherwise keep the existing redirect.
4. **`move_out_view.php`:** show `$_SESSION['success']` and `$_SESSION['error']` messages at the top.

## Part 6: Security deposit settlement (`includes/security_deposit_helper.php` and `move_out_view.php`)

1. **No deposit received** (expected > 0, allocated to the security-deposit obligation = 0): show a warning box with an **Open Receipts & Allocations** link (`accounting/receipt_allocation.php?lease_id=`). If only part was received, show "Only X of Y received". **Approval is refused** unless the deposit is allocated, **or** the user ticks "I confirm no security deposit was collected" (`confirm_no_deposit`). In that case the settlement is closed with status `cancelled`, received 0, and a note. Treat "cancelled + received 0 + approved_at set" as **settlement complete**, so the move-out can be completed.
2. **Damage larger than the deposit:** compute excess = approved damage − deduction actually covered, and show "Damage not covered by the deposit: X AED — charge the tenant separately". Owner/Admin get an **Add charge to lease** link (`billing_service_charges.php?lease_id=X&apply=1`). Include it in the approval message.
3. **Process button:** only show it when status is `approved`/`deducted`, it hasn't been processed, and refund + deduction > 0. Otherwise explain why (nothing to process / settlement closed). In `re_sd_process_move_out_refund`, refuse if `journal_id` is already set ("already processed"); this prevents double posting.
4. **Recalculate:** add a Recalculate button that confirms first, available while the settlement isn't processed (draft, approved or cancelled). `re_sd_prepare_move_out_settlement` resets an unprocessed settlement to `draft`, refreshes expected and received, and keeps the damage deduction row equal to the current damage total (cancel it if damage is 0). `re_sd_approve_move_out_deductions(…, bool $confirmNoDeposit = false)` only approves a `draft`, uses the **live** received amount from `re_sd_summary()`, and returns `excess` and `closed`.
5. **Implicit-commit bug:** `re_sd_notify_tenant()` reaches push code that runs `CREATE TABLE IF NOT EXISTS`, which implicitly commits the open MySQL transaction, so `commit()` then throws "There is no active transaction" after the data was saved. **Call the notification after `$conn->commit()`.**

## Part 7: Move-In details page (`move_in_view.php`) Quick Verifications and checklist

1. **Checklist data-loss bugs:** `update_checklist_item` wrote `is_completed` and `notes` every time. Ticking a box wiped its notes, and saving a note unticked it. Update **only the fields that were posted** (`isset($_POST['is_completed'])` / `isset($_POST['notes'])`).
2. **Quick Verifications linked to checklist items** (sync both ways), as a constant whose **order is the required sequence**:
   | Step | Field | Linked checklist item(s) |
   |---|---|---|
   | 1 | `contract_verified` | Contract signed and verified |
   | 2 | `payment_confirmed` | Deposit received **and** First Installment rent received (also "First month rent received") |
   | 3 | `inspection_completed` | Initial inspection completed |
   | 4 | `keys_handed_over` | Keys handed over |
   - Marking a verification ticks or unticks its item(s). Ticking items updates the verification, which is done only when **all** its linked items are done. Match item names case-insensitively.
   - **Sequence:** a step can be marked done only after all earlier steps, and undone only when no later step is done. Enforce this on the server for both `update_verification` and linked checklist ticks, with messages like `Complete "Contract Verified" first. Steps must be done in order.` / `Undo "Payment Confirmed" first. Steps must be undone in reverse order.` Unlinked items and notes are always free. Existing out-of-order data must still render and allow undoing the latest done step.
   - **Lock** verifications when the move-in is completed or cancelled.
   - Load who did each step (join `user` on `*_by` for the 4 fields).
3. **Card UI:** a 2 × 2 grid (4 across at ≥1600px, 1 column on phones). Each card shows "Step N of 4", an icon, the title, the status line, and the linked item(s). The action button sits at the **bottom** of the card, so titles never wrap mid-word.
   - **Done:** green, "✓ Done · user · date".
   - **Next:** highlighted border, solid **Mark done**.
   - **Waiting:** greyed out, 🔒 "Complete X first", disabled **Locked**.
   - **Done with later steps done:** "Confirmed"; the latest done step gets **Undo**.
   - The header pill shows "N of 4 done".
   - Linked checklist checkboxes are `disabled` with a 🔒 reason when the step order blocks them.

## Part 8: Modern, responsive design for the 6 pages

Pages: `move_in.php`, `move_in_add.php`, `move_in_view.php`, `move_out.php`, `move_out_add.php`, `move_out_view.php`. Put the design in **one new file**, `includes/re_move_ui.php`, which exposes `re_move_ui_css()`, `re_move_ui_pill($label, $tone)` and `re_move_ui_tone($status)`. **Scope all CSS under `.rm-page`** and wrap each page body in `<div class="rm-page">`, so undoing it is just restoring the files and deleting this one. Match the other refreshed pages, such as `bulk_tenant_email.php`: white panels, 16px radius, soft shadow, brand `var(--primary)`, and support for `body.dark-mode`.
- **Page header:** a back link, the title with an icon, a subtitle or meta (status pill, date, unit, tenant), and action buttons that go full width on phones.
- **Lists:** clickable stat tiles that filter by status (with an active state), a filter bar with a ✕ clear button, soft status pills, a slim checklist progress bar, and tables that become stacked cards under 768px using `data-label` on each `<td>`.
- **Create pages:** a centred narrow form (`max-width: 860px`), Lease Details as a `<dl>` label/value grid, Cancel/Submit actions that stack on phones, and Move-Out's two date fields side by side from `sm` up.
- **Detail pages:** columns `col-lg-8` / `col-lg-4` with the right column `position: sticky` from `lg` up. Lease info and summary become `<dl>` grids, the meter-reading form and table are responsive, and Move-Out gets a **step tracker**: Notice → Inspection scheduled → Inspection done → Deposit settled → Completed. Keep the Move-In main progress bar as the **first `.progress-bar` on the page**, because JS updates it; keep its text readable when the bar is short.
- **Mobile fix, scoped to these pages:** the layout's content column (`.flex-grow-1`) has no `min-width: 0`, so pages overflow sideways on phones. Add `.flex-grow-1:has(.rm-page) { min-width: 0; }`. Under 576px, show the sidebar in its icon-only form for pages containing `.rm-page` (mirror the layout's `.sidebar.collapsed` rules, width about 64px).
- **Do not use `overflow-wrap: anywhere` page-wide.** It splits words mid-word. Allow it only on long values (`dd`, table cells).
- **Keep every element `id`, `onclick` handler name and form field `name`** exactly as they are. Verify by comparing the before and after sets, and keep the `<div>` open/close balance identical to the original files.

## Deliverables

1. Changed and new files, with a list of each and a one-line purpose.
2. The migration file, not run until approved.
3. A backup folder with `RESTORE.md` and a `restore.sh` for the design part.
4. A short test report: what was tested and how (throwaway DB, read-only renders, screenshots at 390 / 820 / 1440px if possible), and anything that was not verified.

---
