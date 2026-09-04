# Phase 7 — Expenses, Prepaid, Journal Entries

## Overview

Phase 7 extends the Cleaning / Service Management accounting module with:

1. **Expense workflow** — optional draft → approval → post (backward compatible when approval is off)
2. **Prepaid expenses** — capitalise to asset account, amortize monthly to expense
3. **Manual journal entries** — draft, review, post to GL
4. **Recurring journals** — templates posted by cron

## Migration

```bash
php tools/sm_apply_phase7_schema.php
```

Creates tables: `sm_manual_journals`, `sm_manual_journal_lines`, `sm_prepaid_schedules`, `sm_prepaid_amortization`, `sm_recurring_journals`, `sm_recurring_journal_lines`.

Extends `expenses` with: `expense_type`, `submitted_at`, `approved_by`, `approved_at`, `prepaid_months`, `prepaid_expense_account_no`, and status enum `draft | pending_approval | posted | void`.

Settings (in `settings`):

| Key | Default | Purpose |
|-----|---------|---------|
| `sm_expense_requires_approval` | `0` | When `1`, new expenses save as draft unless Admin/Accountant posts |
| `sm_prepaid_asset_account` | `1310` | COA account for prepaid asset |
| `sm_phase7_enabled` | `1` | Feature flag |

## Expense types

| Type | GL on post |
|------|------------|
| `operating` | Dr expense lines + VAT, Cr bank/AP (existing `gl_post_expense`) |
| `prepaid` | Dr prepaid asset + VAT, Cr bank/AP; creates amortization schedule |
| `payroll` / `other` | Same as operating (classification only) |

## UI pages

| Page | Path |
|------|------|
| Expenses list | `accounts/expenses.php` |
| Add expense | `accounts/expense_add.php` |
| Approve (POST) | `accounts/ajax/expense_approve.php` |
| Journal list | `accounts/journal_entries.php` |
| New journal | `accounts/journal_entry_add.php` |
| View/post journal | `accounts/journal_entry_view.php` |
| Prepaid schedules | `accounts/prepaid_schedules.php` |
| Recurring journals | `accounts/recurring_journals.php` |

Nav links added under **Accounts** (desktop + mobile).

## Services

| File | Role |
|------|------|
| `includes/sm_expense_service.php` | Draft, submit, approve, post, prepaid GL |
| `includes/sm_journal_service.php` | Manual JV draft + post |
| `includes/sm_prepaid_service.php` | Amortization + recurring journal runner |

## Cron

Monthly amortization and due recurring journals:

```bash
php tools/sm_run_prepaid_cron.php          # current month
php tools/sm_run_prepaid_cron.php 2026-07   # specific period
```

Schedule in crontab, e.g. `0 2 1 * *` on the 1st of each month.

## Enabling approval workflow

```sql
UPDATE settings SET value = '1' WHERE `key` = 'sm_expense_requires_approval';
```

When enabled, staff save drafts or submit for approval; Admin/Accountant approves from the expenses list.

## Testing checklist

- [ ] Run migration on dev DB
- [ ] Operating expense: Save & Post → GL journal, status `posted`
- [ ] Prepaid expense: posts to asset 1310, schedule visible in Prepaid Schedules
- [ ] Run amortization for a period → expense recognized, schedule advances
- [ ] Manual JV: create draft, verify balance, post to GL
- [ ] Recurring journal: create template, run cron, verify GL entry
- [ ] With approval on: draft → submit → approve → posted
