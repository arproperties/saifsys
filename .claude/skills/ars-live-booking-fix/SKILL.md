---
name: ars-live-booking-fix
description: Fix a wrong ARS (Holiday Homes) booking on local and on live - remove a billed extension row from the Extend tab, reverse an extension/service invoice or credit note the page refuses to delete, correct the check-out date and nights, or undo any posted ARS financial document. Use whenever the user sends a booking_view screenshot and says things like "delete this entry", "remove this extension", "make it 7 nights", "checkout date is 22 sep", "fix on live", "help me fix this booking", or the page says "This period is billed" / "cannot be deleted; reverse it with a credit note". Covers the local investigation, the guarded phpMyAdmin SQL file for live, and proving it before handing over.
---

# Fixing an ARS booking the page won't let you fix

The office bills something on a booking (usually an extension on the Extend tab),
then finds it was wrong. The page blocks the delete because a posted invoice and
a journal sit behind the row. The user wants it gone, first on local, then on
live. Live has real books, so the two are handled differently:

| | Local | Live |
|---|---|---|
| Who runs it | you, directly in the DB | the user, in Hostinger phpMyAdmin |
| Invoice + journal | may be hard-deleted if he picks that | reversed, never deleted |
| Safety | snapshot rows to `backups/` first | guarded SQL file, proven on test copies |

He wants short answers and does the deploy steps himself. Don't offer to commit.

## 1. Read the screenshot

- `localhost:8000` in the address bar = local. No address bar / SAIF logo / a staff
  name like Shrinkhala Rai = live.
- `booking_view.php?id=N` gives the local booking id. On live, never rely on ids;
  use the booking number (`ARS-26-00213`) and document number (`ARS-EXT-2026-00041`).
- Note check-in, check-out, nights, the Extend rows, Invoiced total and Paid.
  You will compare the database against these.

## 2. Find what is behind the row

Connection is in `includes/config.php` (app user, `127.0.0.1`). In zsh a plain
string of flags is not word-split, so keep them in an array:

```zsh
U=(-h127.0.0.1 -uu385648797_Usersys '-p<pass from config.php>')
mariadb $U u385648797_Mainsys -e "..."
```

What hangs off a billed extension:

| Table | Link |
|---|---|
| `ars_booking_extension_log` | the Extend row; `document_id` set = billed |
| `ars_financial_documents` | the invoice (`extension_invoice`), `journal_id`, `idempotency_key` |
| `ars_financial_document_lines`, `_transitions`, `ars_extension_documents` | by `document_id` |
| `ars_payment_allocations` | by `document_id`; `status='reversed'` once the payment is deleted |
| `re_journal_headers`, `re_journal_lines`, `re_general_ledger` | by `journal_id` (ARS posts to the `re_` tables, not `gl_`) |
| `ars_bookings` | `check_out`, `nights` |
| `ars_booking_activities` | timeline |

Also check `ars_credit_notes`, `ars_adjustments`, `ars_guest_credit_applications`,
`ars_booking_charges.financial_document_id` and child documents
(`parent_document_id`) so nothing is left pointing at the invoice.

Things that surprise:

- **Payments first.** The app refuses to reverse an invoice with a live payment
  on it. If `amount_allocated > 0`, he deletes the payments in the app first.
- **An empty Extend log does not roll the check-out back.** The original
  check-out is stored nowhere (`ars_sync_checkout_to_extension_log`), so after
  the last row goes the booking keeps the extended date. Work out the real
  original stay from the original invoice: `subtotal / nightly_rate` = nights.
  Tell him if nights and invoice disagree; he will usually ask for the date fix.
- **Local may not be what live is.** He re-imports production dumps often, and
  also changes live after the dump. Before trusting local as a stand-in for live,
  compare it with his live screenshot and check
  `SELECT MAX(created_at) FROM ars_booking_activities`. If they differ, ask him
  to download a fresh database (the `refresh-local-db` skill imports it).
- The app's own path for this is `delete_extension_bill` in
  `modules/ars/ajax_booking_actions.php` (Money tab trash icon). It only shows
  on some bookings, which is why he ends up asking you.

## 3. Local fix

There is a real choice here, so ask once (AskUserQuestion), recommended first:

1. Remove entry + invoice + journal (snapshot first) - usual pick on local
2. Reverse and keep the invoice as reversed
3. Entry only (invoice stays due)

Then snapshot every row you will touch, because a real record was lost once:

```zsh
F=backups/ars_booking<ID>_<what>_before_delete_$(date +%Y%m%d_%H%M%S).sql
mysqldump $U --no-create-info --skip-triggers --complete-insert --skip-add-locks \
  --skip-comments --no-tablespaces u385648797_Mainsys <table> --where="<cond>" \
  2>/dev/null | grep '^INSERT' >> $F
```

Run the deletes in one transaction, children before parents, each with a narrow
`WHERE` (id plus booking id plus type), and read back the booking afterwards.
Don't touch the running `balance` column of other ledger rows; it is a per-insert
snapshot and already not a strict running total.

## 4. Live fix: one SQL file in `live_hotfix/`

He runs SQL himself in phpMyAdmin. Browser PHP scripts hit Owner-role and
`tools/` blocks on live, so don't write one.

Start from [assets/remove_billed_extension.example.sql](assets/remove_billed_extension.example.sql)
(the fix run on live on 2026-10-02). For a credit note with a guest credit, see
`live_hotfix/reverse_ars_cn_2026_00002.sql`. Keep this shape:

- **Header comment** in plain words: what it does, and the three run steps.
- **PART 1: check.** One read-only `SELECT`, followed by an `-- Expect:` line
  with the exact values he should see.
- **PART 2: fix.** `SET` the booking/document numbers and reason at the top.
  `START TRANSACTION`, look every id up into variables (`... FOR UPDATE`),
  compute `@ok`, show it, then every write carries `AND @ok = 1`. `COMMIT`, then
  a final `SELECT IF(@ok = 1, 'DONE: ...', 'NOTHING CHANGED: ...')`.
- **PART 3: verify.** Read-back queries, each with an `-- Expect:` line.

Why this shape: phpMyAdmin runs every statement regardless of earlier results,
so the guard has to live inside each write. A second accidental run must change
nothing.

`@ok` should require at least: booking found and `check_out` equals the value in
his screenshot; document found, `status = 'posted'`, `amount_allocated = 0`,
`balance_due = total_amount`; journal posted and not reversed with the expected
line count; no non-reversed allocations; no child documents; the Extend log has
exactly the rows you expect; fiscal year not closed; the new journal number not
already used; sequence row found.

The writes must match what the app does (`ars_adapter_reverse_document` +
`reverse_journal` + `delete_extension_bill`):

1. bump `re_journal_sequences`, build `JRN-<year>-<seq>` (4-digit pad)
2. reversal `re_journal_headers` row: type `reversal`, same date as the original,
   totals swapped, `is_posted = 1`
3. `re_journal_lines` with debit/credit swapped, description `Reversal: ...`
4. `re_general_ledger`, one insert per line in order, balance = last balance for
   that account +/- by `normal_balance`
5. original header `is_reversed = 1`, `reversal_journal_id`
6. two `re_accounting_audit_log` rows (`post_journal`, `reverse_journal`)
7. document: `status = 'reversed'`, `reversal_journal_id`, `balance_due = 0`,
   `idempotency_key` suffixed `:rev<id>` so the period can be billed again
8. `ars_financial_document_transitions` row
9. delete the Extend row; set `check_out` and `nights = DATEDIFF(...)` if asked
10. `ars_booking_activities` timeline rows

Live MySQL is UTC; `NOW()` is fine for these stamps.

## 5. Prove it before handing over

Run the app's path on one throwaway copy and the SQL on another, then diff. This
is what catches a missing ledger row or a forgotten column.

```zsh
.claude/skills/ars-live-booking-fix/scripts/clone_test_db.sh test_fix_a test_fix_b
```

- If local is not already in the state of his live screenshot, put **both**
  copies into it with the same prep SQL first.
- Copy A: a small PHP script in the scratchpad that sets
  `define('DB_NAME', 'test_fix_a')` before `@require 'includes/config.php'`,
  requires `includes/db_connect.php`, `modules/ars/includes/ars_helpers.php`,
  `ars_accounting.php`, `ars_financial_adapter.php`, `ars_pricing.php`
  (`ars_recalc_booking_totals` lives there), and makes the same calls as the app
  action. Print `SELECT DATABASE()` first and confirm the main DB is untouched
  afterwards.
- Copy B: `mariadb $U test_fix_b < live_hotfix/<file>.sql`
- Dump the affected tables from both to text and `diff`. Only timestamps should
  differ.
- Run the SQL on copy B a second time: it must say `NOTHING CHANGED` and the
  journal sequence must not move.
- If he downloads a fresh database later, re-run the file on one fresh copy.
- Drop the `test_*` databases when done.

## 6. Hand over and follow the run

Give him the file link and the three steps, with the exact values PART 1 should
show. He will send a screenshot after each part:

- **PART 1:** compare every column with the Expect line before saying go.
- **PART 2:** look for `ok_to_run = 1`, then a row count on every write
  ("1 row inserted", "2 rows inserted", "1 row deleted") and the `COMMIT`. The
  new journal number will differ from your test run if live posted in between;
  that is normal.
- **PART 3:** confirm against the Expect lines, then tell him what is left for
  him to do (usually re-entering receipts, and that local is now behind live).

Say plainly that you did not touch live; he did.
