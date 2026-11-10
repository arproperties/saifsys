<?php
/**
 * Writes the phpMyAdmin install file for Tenancy Contract v2:
 * the column changes plus the v2 template row (HTML from
 * modules/realestate/contract_templates/tenancy_contract_v2.html).
 *
 *     php tools/contract/build_tenancy_contract_v2_sql.php > live_hotfix/2026-11-09_tenancy_contract_v2_install.sql
 *
 * The row is added for the company that owns "2026 Tanacey Contract", is NOT the default,
 * and is only added once (re-running the SQL changes nothing). To refresh the HTML of an
 * existing v2 row, use the UPDATE at the end of the file.
 */
$root = dirname(__DIR__, 2);
$html = file_get_contents($root . '/modules/realestate/contract_templates/tenancy_contract_v2.html');
if ($html === false || strpos($html, '{{CLAUSE_NO}}') === false) {
    fwrite(STDERR, "Template file missing or not a v2 template\n");
    exit(1);
}
$q = function (string $s): string {
    return "'" . strtr($s, ["\\" => "\\\\", "'" => "''", "\0" => "\\0", "\r" => "\\r", "\n" => "\\n", "\x1a" => "\\Z"]) . "'";
};
$migration = file_get_contents($root . '/migrations/re_tenancy_contract_v2.sql');
$pp = file_get_contents($root . '/modules/realestate/contract_templates/tenancy_contract_pp_v2.html');
if ($pp === false || strpos($pp, '{{CHILLER_FEES}}') === false) {
    fwrite(STDERR, "Park Place v2 template file missing\n");
    exit(1);
}
$com = file_get_contents($root . '/modules/realestate/contract_templates/tenancy_contract_com_v2.html');
if ($com === false || strpos($com, '{{COMMERCIAL_USE}}') === false) {
    fwrite(STDERR, "Commercial v2 template file missing\n");
    exit(1);
}
echo "-- Tenancy Contract v2 install (generated " . date('Y-m-d') . ")\n";
echo "-- 1) columns, 2) v2 template rows (not default): Ayla, Park Place, and the commercial and labour contracts, 3) check.\n\n";
echo $migration, "\n";
echo "SET NAMES utf8mb4;\n\n";
$rows = [
    ['2026 Tanacey Contract v2', 'Residential (Ayla standard) v2 - Law 26/2007 review, Annexes A-D. Arabic translation pending.', $html, 'tenancy_contract_v2', '2026 Tanacey Contract'],
    ['2026 Tanacey Contract PP v2', 'Residential (Park Place) v2 - same as Ayla v2, chiller billed by the Landlord on page 1. Arabic translation pending.', $pp, 'tenancy_contract_pp_v2', '2026 Tanacey Contract PP'],
    ['2026 Tanacey Contract Commercial v2.1', 'Commercial (Ayla) v2.1 - shop / office / food court, English master of 10 Nov 2026. Arabic translation pending.', $com, 'tenancy_contract_com_v2', '2026 Tanacey Contract'],
];
$more = [
    ['tenancy_contract_pp_com_v2', '2026 Tanacey Contract PP Commercial v2', 'Commercial (Park Place) v2 - as Ayla Commercial; chiller billed by the Landlord when the lease has chiller fees. Arabic translation pending.', '2026 Tanacey Contract PP'],
    ['tenancy_contract_gc_com_v2', '2026 Tanacey Contract Gents Camp Commercial v2', 'Commercial (Gents Camp) v2 - as Ayla Commercial + camp facilities and camp rules. Arabic translation pending.', '2026 Tanacey Contract'],
    ['tenancy_contract_lc_com_v2', '2026 Tanacey Contract Ladies Camp Commercial v2', 'Commercial (Ladies Camp) v2 - as Ayla Commercial + camp rules; shop staff not in accommodation areas. Arabic translation pending.', '2026 Tanacey Contract'],
    ['tenancy_contract_gc_lab_v2', '2026 Tanacey Contract Gents Camp Labour v2', 'Labour accommodation (Gents Camp) v2 - company renting rooms for its workers; persons per room on the lease; rooms in Annex E. Arabic translation pending.', '2026 Tanacey Contract'],
    ['tenancy_contract_lc_lab_v2', '2026 Tanacey Contract Ladies Camp Labour v2', 'Labour accommodation (Ladies Camp) v2 - female workers only; persons per room on the lease; rooms in Annex E. Arabic translation pending.', '2026 Tanacey Contract'],
];
foreach ($more as [$code, $name, $desc, $from]) {
    $body = file_get_contents($root . '/modules/realestate/contract_templates/' . $code . '.html');
    if ($body === false || strpos($body, 'template_code: ' . $code) === false) {
        fwrite(STDERR, "Template file missing: $code\n");
        exit(1);
    }
    $rows[] = [$name, $desc, $body, $code, $from];
}
foreach ($rows as [$name, $desc, $body, $code, $from]) {
    $hq = $q($body);
    echo "INSERT INTO re_contract_templates\n";
    echo "  (company_id, template_name, template_type, description, template_content, template_html, template_format, is_active, is_default, template_code, created_at, updated_at)\n";
    echo "SELECT src.company_id, " . $q($name) . ", 'standard',\n  " . $q($desc) . ",\n  $hq,\n  $hq,\n";
    echo "  'html', 1, 0, " . $q($code) . ", NOW(), NOW()\n";
    echo "FROM re_contract_templates src\n";
    echo "WHERE src.template_name = " . $q($from) . "\n";
    echo "  AND NOT EXISTS (SELECT 1 FROM re_contract_templates x WHERE x.company_id = src.company_id AND x.template_code = " . $q($code) . ")\n";
    echo "ORDER BY src.id LIMIT 1;\n\n";
}
echo "-- Check: expect eight rows (residential Ayla + PP, commercial Ayla / PP / Gents Camp / Ladies Camp, labour Gents Camp / Ladies Camp), is_default = 0\n";
echo "SELECT id, company_id, template_name, template_code, is_default, is_active, LENGTH(template_html) AS html_bytes\n";
echo "FROM re_contract_templates WHERE template_code LIKE 'tenancy_contract%v2';\n\n";
echo "-- To refresh the HTML of an existing v2 row later (after a translator update), run only this:\n";
echo "-- UPDATE re_contract_templates SET template_html = <new html>, template_content = <new html> WHERE template_code = 'tenancy_contract_v2'; (Park Place: tenancy_contract_pp_v2, Ayla Commercial: tenancy_contract_com_v2)\n";
