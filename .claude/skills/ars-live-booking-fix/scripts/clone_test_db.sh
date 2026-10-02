#!/bin/zsh
# Clone the local main database into one or more throwaway test_* databases.
# Usage (from the repo root): clone_test_db.sh test_fix_a [test_fix_b ...]
# The app user may only create databases named test_*; ~30 old views fail harmlessly.
set -e
CFG=includes/config.php
val() { grep -E "define\('$1'" $CFG | sed -E "s/.*define\('$1', *'([^']*)'.*/\1/" | head -1; }
SRC=$(val DB_NAME)
U=(-h127.0.0.1 "-u$(val DB_USER)" "-p$(val DB_PASS)")
TMP=$(mktemp -t clone_XXXX).sql
mariadb-dump $U --single-transaction --skip-triggers --no-tablespaces $SRC 2>/dev/null | sed 's/DEFINER=[^ ]* //g' > $TMP
for d in "$@"; do
  case "$d" in test_*) ;; *) echo "refusing: $d is not a test_* name"; exit 1;; esac
  mariadb $U -e "DROP DATABASE IF EXISTS \`$d\`; CREATE DATABASE \`$d\`;"
  n=$(mariadb $U --force "$d" < $TMP 2>&1 | grep -c ERROR || true)
  echo "cloned -> $d ($n view errors, expected about 30)"
done
rm -f $TMP
