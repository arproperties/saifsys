---
name: refresh-local-db
description: Refresh the local MariaDB from a production dump downloaded from Hostinger phpMyAdmin, archiving the current local database first. Use when asked to import a live/production database locally, set up a downloaded .sql on local, shift or archive the current local database into an old one, or restore a local database from a backup.
---

# Refreshing the local database from a production dump

Production is Hostinger. Dumps are exported from phpMyAdmin and land in
`~/Downloads/` as `u385648797_Mainsys.sql` (dated variants like
`u385648797_Mainsys.20260902050538.sql` are older downloads — check the
timestamp, the undated name is usually the newest).

The job is always the same shape: keep the current local database as an archive,
then load the fresh dump into `u385648797_Mainsys` so the app picks it up with no
config change. [includes/config.php](../../../includes/config.php) already points
local at `127.0.0.1` / `u385648797_Mainsys`, so nothing needs editing.

## Access

MariaDB 11.8 runs under `brew services`. **Admin access is `mysql -u ainalreem`**
— socket auth. `mysql -u root` fails with *ERROR 1698 (28000) Access denied*;
don't waste a turn on it, and don't reach for `sudo`.

The app connects as `u385648797_Usersys`, which exists at both `127.0.0.1` and
`localhost`. Its password is in `includes/config.php`.

Backups go in `~/Sites/db_backups/`.

## Back up first, always

Not optional, and not just good hygiene — the archive step below restores *from
this file*, so it is an input to the process, not only a safety net.

```bash
mysqldump -u ainalreem --single-transaction --routines --triggers --events \
  u385648797_Mainsys > ~/Sites/db_backups/local_Mainsys_before_YYYYMMDD.sql
```

Run it in the background. It is a few hundred MB.

## Archiving the current local database

Ask the user what to call it if they haven't said; dated
(`u385648797_Mainsys_old_YYYYMMDD`) is the better default, because an undated
name gets clobbered by the next refresh.

**Restore from the dump file. Do not `RENAME TABLE` the 530 tables across
schemas** — the database carries ~448 foreign keys and moving tables out from
under them is not worth the risk when a file restore is clean.

```bash
mysql -u ainalreem -e "CREATE DATABASE \`u385648797_Mainsys_old_YYYYMMDD\` \
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

{ echo "SET FOREIGN_KEY_CHECKS=0; SET UNIQUE_CHECKS=0;"
  cat ~/Sites/db_backups/local_Mainsys_before_YYYYMMDD.sql
} | mysql -u ainalreem u385648797_Mainsys_old_YYYYMMDD
```

One view will fail with *ERROR 1050 Table 'v_ar_ageing' already exists*.
`mysqldump` writes view DDL with the **source** schema name baked in
(``CREATE ... VIEW `u385648797_mainsys`.`v_ar_ageing` ...``), so it tries to
create the view back in the live database, where it already exists. Most views
survive because MariaDB rewrites them to the current schema; this one doesn't.
Recreate it by hand afterwards:

```bash
mysql -u ainalreem -N --batch -e "SELECT VIEW_DEFINITION FROM information_schema.VIEWS
  WHERE TABLE_SCHEMA='u385648797_Mainsys' AND TABLE_NAME='v_ar_ageing';" \
 | sed 's/\\n/\n/g' > /tmp/v.sql
{ echo "CREATE OR REPLACE VIEW \`v_ar_ageing\` AS"; cat /tmp/v.sql; } \
 | sed 's/`u385648797_mainsys`\./`u385648797_Mainsys_old_YYYYMMDD`./g' \
 | mysql -u ainalreem u385648797_Mainsys_old_YYYYMMDD
```

Confirm the archive holds the full object count and matching row counts on a few
real tables **before** dropping anything.

## Loading the production dump

The phpMyAdmin export has no `CREATE DATABASE` and no `USE`, so you pick the
target. It also has no `SET FOREIGN_KEY_CHECKS=0` of its own.

```bash
mysql -u ainalreem -e "DROP DATABASE \`u385648797_Mainsys\`;
  CREATE DATABASE \`u385648797_Mainsys\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

cd ~/Downloads
{ echo 'SET FOREIGN_KEY_CHECKS=0; SET UNIQUE_CHECKS=0; SET SESSION innodb_strict_mode=0; SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";'
  sed 's/ROW_FORMAT=COMPACT/ROW_FORMAT=DYNAMIC/' u385648797_Mainsys.sql
} | mysql -u ainalreem u385648797_Mainsys
```

Background it and wait on the process, don't poll in a loop.

### The two things that abort this import every time

Patch both **before** running, not after it dies.

**1. `make_order` exports as `ROW_FORMAT=COMPACT`.** Local rejects it:

> ERROR 1118 (42000): Row size too large (> 8126)

Production's server tolerates the format; MariaDB 11.8 with
`innodb_strict_mode=1` does not. The `sed` above converts it to `DYNAMIC` —
same columns, modern off-page storage. It is the only table in the dump using
an explicit row format, so the substitution is safe as written.

**2. phpMyAdmin corrupts the `v_ar_client_credit` view.** It emits stray `END`
tokens around a nested `CASE`:

```
... ELSE `c`.`credit_limit`- coalesce(`ar`.`ar_total`,0) end END AS `available_credit` END FROM ...
```

There should be one `END`, then straight to `FROM`. Check for it up front with
`grep -c "end END" u385648797_Mainsys.sql` and fix it in the piped stream:

```bash
sed 's/ end END AS `available_credit` END FROM / END AS `available_credit` FROM /'
```

If a new corrupt view ever appears, this gives the view count the database
should end up with:

```bash
grep -oE 'VIEW `[a-z_]+`' u385648797_Mainsys.sql | wc -l
```

### Why the view error is the dangerous one

phpMyAdmin orders the file: tables and data → index/`AUTO_INCREMENT` `ALTER`s →
views → **foreign-key `ALTER TABLE` block last**. `mysql` stops at the first
error, so a syntax error in a view silently costs all ~448 foreign keys while
the table count still looks perfect.

**Never sign off on table count alone.** Check all four:

```bash
mysql -u ainalreem -N -e "
SELECT TABLE_TYPE, COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA='u385648797_Mainsys' GROUP BY TABLE_TYPE;
SELECT 'fks', COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA='u385648797_Mainsys';
SELECT 'indexes', COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA='u385648797_Mainsys';"
```

As of the 2026-09-09 dump that is 529 base tables, 30 views, 448 FKs, 3126
indexes. The numbers drift with the schema; what matters is that FKs and views
are non-zero and the table list matches the dump:

```bash
grep -oE "^CREATE TABLE \`[A-Za-z0-9_]+\`" u385648797_Mainsys.sql \
  | sed 's/CREATE TABLE //; s/`//g' | sort > /tmp/dump_tables.txt
mysql -u ainalreem -N -e "SELECT TABLE_NAME FROM information_schema.TABLES
  WHERE TABLE_SCHEMA='u385648797_Mainsys';" | sort > /tmp/db_objects.txt
comm -23 /tmp/dump_tables.txt /tmp/db_objects.txt   # must be empty
```

The dump's `CREATE TABLE` count includes phpMyAdmin's stand-in tables for views,
so it runs 30 higher than the final base-table count. That is expected.

### Recovering a part-way import

If it died mid-file, don't restart from zero. Find the failing line, slice the
remainder, patch it, and pipe just that:

```bash
sed -n '<failing_line>,$p' u385648797_Mainsys.sql | sed '<the fix>' | \
  mysql -u ainalreem u385648797_Mainsys
```

A trailing *ERROR 1231 `character_set_client` can't be set to the value of
'NULL'* on such a slice is harmless — it's the dump restoring a session variable
that was never set in the new connection.

## Finish

Re-grant, since the database was dropped and recreated:

```bash
mysql -u ainalreem -e "
GRANT ALL PRIVILEGES ON \`u385648797_Mainsys\`.* TO 'u385648797_Usersys'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`u385648797_Mainsys\`.* TO 'u385648797_Usersys'@'localhost';
GRANT ALL PRIVILEGES ON \`u385648797_Mainsys_old_YYYYMMDD\`.* TO 'u385648797_Usersys'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`u385648797_Mainsys_old_YYYYMMDD\`.* TO 'u385648797_Usersys'@'localhost';
FLUSH PRIVILEGES;"
```

Then prove it end to end as the app's own user, not as admin — this catches both
a missing grant and a view whose `DEFINER` didn't resolve:

```bash
mysql -h 127.0.0.1 -u u385648797_Usersys -p'<pass from config.php>' u385648797_Mainsys \
  -e "SELECT COUNT(*) FROM v_ar_invoices_open; SELECT * FROM v_ar_client_credit LIMIT 2;"
```

Finally, report row counts on a handful of live tables (`make_order`, `ops_jobs`,
`audit_log`, `client`) new-vs-archive. It is the only quick evidence that the
dump really was newer than what was replaced.

## Don't

- Don't drop the archive database to save disk. It is roughly the same size as
  the live one and the user keeps it deliberately.
- Don't edit `includes/config.php` to point at the archive to "check something".
  Query it by name instead; the archive is fully readable as
  `u385648797_Mainsys_old_YYYYMMDD.<table>`.
