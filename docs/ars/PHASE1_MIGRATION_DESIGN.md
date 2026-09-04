# ARS Phase 1 Migration Design — Financial Lock Foundations

**Migration file:** [`migrations/ars_phase1_financial_lock_foundations.sql`](../../migrations/ars_phase1_financial_lock_foundations.sql)  
**Status:** Created — execute on local only after this review; do not run on live until separately approved.  
**Date:** 2026-07-17

## Purpose

Additive foundations for Draft vs Financial Lock and Activity Center event writers.  
Does **not** create Option B invoice/CN/allocation document tables (Phase 2).  
Does **not** touch `re_invoices`, `re_leases`, `re_tenants`, or `accounting_engine.php`.

## Tables / columns affected

### `ars_bookings` (ALTER — additive)

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `financial_status` | ENUM(draft,invoice_created,partially_paid,paid,refunded,closed) | `draft` | Independent of operational `status` |
| `is_financially_locked` | TINYINT(1) | `0` | Financial lock flag |
| `financial_locked_at` | DATETIME NULL | NULL | When lock engaged |
| `financial_locked_by` | INT NULL | NULL | User id |
| `financial_lock_reason` | VARCHAR(255) NULL | NULL | Reason / backfill note |

Index: `idx_ars_bookings_financial (company_id, is_financially_locked, financial_status)`

### Backfill (data UPDATE, non-destructive)

Sets lock + status for bookings that already have `journal_id`, deposit journals, or `ars_booking_payments` rows. Does not modify journal amounts or payment rows.

### `ars_booking_activities` (CREATE IF NOT EXISTS)

New event store for Activity Center writers (UI in Phase 1B).

## Backward compatibility

- New columns have defaults → existing SELECT * / INSERT paths that omit new columns continue to work.
- Mobile/customer API booking payloads do not currently expose these columns → **unchanged**.
- No renames/drops.

## Rollback / recovery

1. Stop writing activities; optional `DROP TABLE IF EXISTS ars_booking_activities`.
2. Drop index `idx_ars_bookings_financial` if present.
3. `ALTER TABLE ars_bookings DROP COLUMN` the five new columns (order: index first, then columns).
4. Historical journals/payments untouched by rollback.

## Mobile / API impact

None for response field shapes. Additive DB only.

## Local execution (after approval of this design)

```bash
# Example — adjust credentials/DB name for local XAMPP
/Applications/XAMPP/xamppfiles/bin/mysql -u root herosysgro < migrations/ars_phase1_financial_lock_foundations.sql
```

Live/production: **do not execute** until separate human approval.
