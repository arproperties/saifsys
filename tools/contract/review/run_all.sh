#!/bin/zsh
# Rebuild every contract-v2 review file into ~/Downloads (nothing touches the database or live).
#   zsh tools/contract/review/run_all.sh
# Sources: the template HTML files in modules/realestate/contract_templates/ and data/*.json here.
# Update data/map.json / map_pp.json "en" texts first if a clause changed (see SKILL notes).
set -e
R="$(cd "$(dirname "$0")" && pwd)"; ROOT="$(cd "$R/../../.." && pwd)"; D="$HOME/Downloads"; T="$(mktemp -d)"
cd "$R/data"
hl() { python3 -c "
import sys;p=sys.argv[1];s=open(p,encoding='utf-8').read()
a='administrative handling fee of AED 500 (Five Hundred Dirhams) per returned cheque'
s=s.replace(a,'administrative handling fee of <span style=\"background:#ffe600;color:#000;font-weight:bold;\">'+a[len('administrative handling fee of '):]+'</span>');open(p,'w',encoding='utf-8').write(s)" "$1"; }
# 1) Ayla old vs v2 comparison (two tables, kept as the user asked for Ayla)
python3 -I "$R/cmp.py" map.json "$T/cmp.html" && hl "$T/cmp.html" && php "$R/cmp.php" "$T/cmp.html" "$D/Tenancy Contract - Old vs New (v2).pdf"
# 2) Park Place old vs PP v2 (one table) and PP v2 vs Ayla v2 (differences only)
python3 -I "$R/cmp_pp.py" map_pp.json "$T/cmp_pp.html" && hl "$T/cmp_pp.html" && php "$R/cmp_pp.php" "$T/cmp_pp.html" "$D/Park Place Contract - Old vs New (PP v2).pdf"
php "$R/cmp_pa.php" pp_vs_ayla.html "$D/Park Place v2 - Only Differences from Ayla v2.pdf"
# 3) Arabic draft for translator review (uses data/ar_draft.json as corrected)
python3 -I "$R/ar_draft.py" map.json "$T/ar_draft.html" ar_draft.json use_fixed && php "$R/ar.php" "$T/ar_draft.html" "$D/Tenancy Contract v2 - Arabic Draft for Translator.pdf"
# 4) Final translator files (PDF only - user does not want Word files) for Ayla and Park Place
python3 -I "$R/final_tr.py" "$T/final_tr.html" && php "$R/final_pdf.php" "$T/final_tr.html" "$D/Tenancy Contract v2 - For Translation.pdf"
python3 -I "$R/final_tr_pp.py" "$T/final_tr_pp.html" && php "$R/final_pdf_pp.php" "$T/final_tr_pp.html" "$D/Park Place Tenancy Contract v2 - For Translation.pdf"
# 4b) Commercial (Ayla, PP, Gents, Ladies) and Labour (Gents, Ladies) translator files, read from the templates
python3 -I "$R/final_tr_com_lab.py" "$ROOT/modules/realestate/contract_templates" "$R/data" "$T/tr_com.html" "$T/tr_lab.html"
php "$R/final_pdf_any.php" "$T/tr_com.html" "$D/Commercial Contracts v2 - For Translation.pdf" "Commercial contracts v2 - for translation"
php "$R/final_pdf_any.php" "$T/tr_lab.html" "$D/Labour Accommodation Contracts v2 - For Translation.pdf" "Labour accommodation contracts v2 - for translation"
# 5) Sample contracts
cd "$ROOT" && php tools/contract/render_tenancy_v2_samples.php >/dev/null 2>&1 && cp docs/contract/samples/*.pdf "$D/"
echo "Done. Files in $D"
