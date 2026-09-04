# Database Structure Sync

One-time (or repeatable) tool to align **live MySQL structure** with **localhost structure** without touching business data.

## What was found in `datanew` (localhost inspection)

| Object type | Count | Notes |
|-------------|------:|-------|
| Tables (InnoDB) | ~450 | After repair: 25 empty `v_*` placeholders removed |
| Views | 30 | All `v_*` reporting views (AR, HR, operations, GL) |
| Foreign keys | 84 | Validated before auto-add |
| Triggers | 0 | Supported if added later |
| Stored procedures / functions | — | May require `mysql_upgrade` on MariaDB if `information_schema.ROUTINES` fails |
| Events | 0 | Event scheduler disabled on localhost |
| Generated columns | 2 | Exported via `information_schema.COLUMNS` |

Collations in use: mostly `utf8mb4_general_ci`, some `utf8mb4_unicode_ci`.

### Fix misclassified `v_*` tables (localhost)

If phpMyAdmin shows `v_ar_invoices_open`, `v_workers`, etc. as **InnoDB tables** instead of **Views** (common after a bad mysqldump import):

```bash
php database/fix_v_prefix_views.php          # preview
php database/fix_v_prefix_views.php --apply  # repair (empty placeholder tables only)
```

Definitions live in `database/views/erp_schema_views.sql` (30 views, matching live). Real table `vendors` is never touched.

After repair, re-export: `php database/export_schema_snapshot.php`

## Safety (non-destructive)

This tool **never**:

- Drops tables, columns, indexes, views, procedures, functions, or triggers
- Truncates tables
- Deletes or updates row data
- Renames objects automatically
- Shrinks `VARCHAR` lengths or applies risky type changes automatically

It **may** auto-apply only:

- `CREATE TABLE IF NOT EXISTS`
- `ALTER TABLE … ADD COLUMN`
- `ALTER TABLE … ADD INDEX` / unique index
- `ALTER TABLE … MODIFY COLUMN` for **VARCHAR expansion** or **allow NULL**
- `ALTER TABLE … ADD CONSTRAINT` foreign keys when no orphan rows exist
- `CREATE VIEW` for missing views
- `CREATE TRIGGER` / `CREATE PROCEDURE` / `CREATE FUNCTION` when missing

Risky differences appear under **Manual Review Required**.

---

## Step 1 — Export structure on localhost

From project root:

```bash
php database/export_schema_snapshot.php
```

Or in browser (Owner/Admin login):

`http://localhost/herosysgro/database/export_schema_snapshot.php`

Output file:

`storage/schema_snapshots/schema_snapshot_local.json`

(Legacy path `database/schema_snapshot_local.json` is still read on live if present.)

This file contains **structure only** (CREATE statements + metadata). No table rows.

---

## Step 2 — Upload snapshot to live server

Upload to the same path on live:

`storage/schema_snapshots/schema_snapshot_local.json`

Also deploy these files if not already present:

- `includes/database_structure_sync.php`
- `admin/database_structure_sync.php`
- `database/run_database_structure_sync.php` (CLI preview/apply — use if admin UI times out on Hostinger)
- `database/export_schema_snapshot.php` (optional on live)

Ensure `storage/schema_snapshots/` and `logs/` are writable by the web server.

---

## Step 3 — Configure live access (recommended)

In `includes/config.php` on **live**, set at least one of:

```php
define('DB_STRUCTURE_SYNC_KEY', 'your-long-random-secret');
define('DB_STRUCTURE_SYNC_ALLOWED_IPS', ['203.0.113.10']); // your office IP
```

Access is granted if **any** of these is true:

- Logged in as **Owner** or **Admin**
- Valid `?sync_key=...` matching `DB_STRUCTURE_SYNC_KEY`
- Request from an IP in `DB_STRUCTURE_SYNC_ALLOWED_IPS`

**Hostinger 503 timeout?** The admin page compares ~450 tables and may exceed PHP limits. Use the CLI runner in Hostinger **Terminal** (hPanel → Advanced → Terminal):

```bash
cd ~/domains/yourdomain.com/public_html/sys
php database/run_database_structure_sync.php --preview --sync-key=YOUR_KEY
```

Then open the admin UI — it loads the cached plan instantly from `storage/schema_snapshots/sync_plan_preview.json`.

---

## Step 4 — Preview on live

Open:

`https://your-live-domain/herosysgro/admin/database_structure_sync.php`

Or with secret key:

`…/admin/database_structure_sync.php?sync_key=your-long-random-secret`

Review tabs:

1. **Safe** — SQL that can be applied automatically
2. **Manual review** — type changes, NOT NULL tightening, view replacements, etc.
3. **Skipped** — extra objects on live not in snapshot (left unchanged)
4. **Summary** — counts of missing tables/columns/indexes/FKs

---

## Step 5 — Backup live database

**Required before Apply.**

```bash
mysqldump -u USER -p datanew > backup_datanew_$(date +%Y%m%d_%H%M%S).sql
```

---

## Step 6 — Apply safe changes

On the sync page:

1. Check **I have backed up the live database**
2. Check **Apply safe structure changes**
3. Click **Apply Safe Changes**

A log is written to:

`logs/database_structure_sync_YYYYMMDD_HHMMSS.log`

---

## Step 7 — Verify ERP

- Log in and smoke-test critical modules: Accounting, Real Estate, Cleaning work orders, HR, Inventory
- Re-run **Preview** — safe count should drop toward zero
- Handle **Manual review** items in phpMyAdmin or a controlled maintenance window

---

## Manual review examples

| Change | Why manual |
|--------|------------|
| `INT` → `VARCHAR` | Possible data loss / cast issues |
| `VARCHAR(255)` → `VARCHAR(100)` | May truncate data |
| `NULL` allowed → `NOT NULL` | Existing NULL rows block change |
| View definition differs | Dependencies / permissions |
| FK add with orphan rows | Would fail or corrupt referential integrity |
| Replace procedure/function | Requires DROP + CREATE review |

---

## MariaDB note (localhost)

If export warns about `mysql.proc` / `information_schema.ROUTINES`, run on localhost:

```bash
/Applications/XAMPP/xamppfiles/bin/mysql_upgrade -u root
```

Then re-export the snapshot so procedures/functions are included.

---

## Files

| File | Purpose |
|------|---------|
| `database/export_schema_snapshot.php` | Export localhost structure to JSON |
| `storage/schema_snapshots/schema_snapshot_local.json` | Snapshot uploaded to live |
| `includes/database_structure_sync.php` | Export, compare, apply engine |
| `database/run_database_structure_sync.php` | CLI/browser preview when admin UI times out |
| `admin/database_structure_sync.php` | Preview / Apply UI |
| `logs/database_structure_sync_*.log` | Execution audit trail |

---

## What this does **not** replace

- Application file deployment (PHP/code upload)
- Data migration or seed scripts
- Full schema replacement / fresh install
- Dropping obsolete live columns (must be done manually after backup if ever needed)
