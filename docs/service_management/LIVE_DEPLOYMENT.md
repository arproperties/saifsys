# Live Server Deployment & Data Repair

This runbook covers deploying Service Management Phases 2–8 to production and matching legacy data so System Health and Accounting Health reach zero actionable issues.

## Before deploy

1. **Full MySQL backup**
   ```bash
   mysqldump -u USER -p DATABASE > backup_$(date +%Y%m%d_%H%M).sql
   ```
   Verify you can restore to a test instance.

2. **Record baseline** — open System Health Check and Accounting Health; screenshot or note issue counts.

3. **Maintenance window** (recommended) — warn users that accounting repair may run 5–15 minutes.

## Settings-only (when SM flags are missing)

If live `settings` is missing `sm_*` keys but schema/columns already exist, prefer the settings-only script (no WO backfill):

```bash
mysql -u USER -p DATABASE < migrations/sm_settings_defaults_only.sql
```

Do **not** re-run `sm_all_phases_0_to_8.sql` solely to fill settings — its Phase 2 backfill force-finalizes every WO that has a non-void invoice.

## Deploy code

Upload/sync the application files to the live server.

## Run migrations (single file)

From the project root on the live server, apply **all Service Management phases (0→8)** in one step:

```bash
php tools/sm_apply_all_phases_schema.php
```

Or via MySQL client:

```bash
mysql -u USER -p DATABASE < migrations/sm_all_phases_0_to_8.sql
```

This replaces running individual phase scripts. The SQL file is **idempotent** (safe to re-run).

**Included phases:**

| Phase | Contents |
|-------|----------|
| 0/1 | Accounting service flags (`sm_use_accounting_service`, compare mode) |
| 2 | Finalize gate columns on `make_order` + backfill |
| 3 | Adjustment requests + financial change log |
| 4 | Hybrid batch invoicing (BINV) |
| 5 | Service categories + default Cleaning seed |
| 5b | Materials rate + multi-service `booking_categories` |
| 7 | Expenses workflow, prepaid, manual/recurring JVs |
| 8 | GM dashboard settings |
| — | `sm_health_exclude_orphan_invoices` for go-live |

> **Note:** If your primary company is not `company_id = 1`, edit the Cleaning category seed in `migrations/sm_all_phases_0_to_8.sql` before running, or add Pest Control / other categories via Settings after migrate.

## Data repair (Owner login)

1. Go to **Accounts → Tools → Live Data Repair**  
   (`/accounts/sm_live_data_repair.php`)

2. Click **Preview all steps** — review eligible counts (especially WO sync list).

3. Confirm **DB backup taken**, then **Execute all (ordered)**.

   Steps run in this order:
   | Step | Action |
   |------|--------|
   | 1 | Refresh invoice allocation status |
   | 2 | Backfill missing INV- invoice GL (BINV excluded) |
   | 3 | Post missing expense GL |
   | 4 | Fix duplicate invoice journals |
   | 5 | Sync finalized WO totals from invoice |
   | 6 | Clear financial dashboard cache |

### CLI alternative (SSH)

```bash
php tools/sm_run_live_repair.php --dry-run
php tools/sm_run_live_repair.php --all --execute
```

## Verify

1. **System Health Check** — target **0 critical**, **0 warnings** (except info-only “Accepted Legacy Orphans”).
2. **Accounting Health** — target **0** in all GL/allocation categories.
3. **GM Dashboard** — AR total matches Accounting Health / live AR.
4. Spot-check 2–3 invoices that had missing journals or allocation mismatches.

## Success criteria

| Check | Expected |
|-------|----------|
| Allocation mismatches | 0 |
| Missing invoice journals (INV-) | 0 |
| Expenses missing GL | 0 |
| WO vs invoice amount mismatch | 0 |
| Legacy orphan invoices (no WO) | Info only — not blocking |
| BINV batch summaries | Excluded from missing-journal check |

## After verification

1. Optionally enable the accounting service for dashboards:
   ```sql
   UPDATE settings SET `value` = '1' WHERE `key` = 'sm_use_accounting_service';
   ```
   Start with compare mode off if you no longer need legacy comparison:
   ```sql
   UPDATE settings SET `value` = '0' WHERE `key` = 'sm_accounting_compare_mode';
   ```

2. Keep **Live Data Repair** available for one-off fixes; normal operations should keep health counts at zero.

## Manual review (not bulk auto-fixed)

| Issue | Action |
|-------|--------|
| Unallocated receipts | Allocate via receipt screen |
| Completed WO without invoice | Finalize or create invoice manually |
| Cancelled WO with active invoice | Void invoice or credit note (Phase 3) |
| Legacy standalone invoices | Accepted baseline — no action |

## Risk controls

- Always **preview (dry-run)** before execute on production.
- BINV summaries are never posted to GL individually.
- WO sync aligns operational totals only — **no AR/GL changes**.
- Orphan invoices are never auto-voided or force-linked.
- Work orders with **pending adjustment requests** are skipped in batch WO sync.

## Rollback

If repair causes unexpected results:

1. Stop further repair steps.
2. Restore from the pre-repair MySQL backup.
3. Investigate failed rows in the repair results table / audit log (`action = sm_live_repair`).
