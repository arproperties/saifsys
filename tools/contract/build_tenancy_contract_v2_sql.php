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
$h = $q($html);
echo "-- Tenancy Contract v2 install (generated " . date('Y-m-d') . ")\n";
echo "-- 1) columns, 2) v2 template row (not default), 3) check.\n\n";
echo $migration, "\n";
echo "SET NAMES utf8mb4;\n\n";
echo "INSERT INTO re_contract_templates\n";
echo "  (company_id, template_name, template_type, description, template_content, template_html, template_format, is_active, is_default, template_code, created_at, updated_at)\n";
echo "SELECT src.company_id, '2026 Tanacey Contract v2', 'standard',\n";
echo "  'Residential (Ayla standard) v2 - Law 26/2007 review, Annexes A-C. Arabic translation pending.',\n";
echo "  $h,\n  $h,\n";
echo "  'html', 1, 0, 'tenancy_contract_v2', NOW(), NOW()\n";
echo "FROM re_contract_templates src\n";
echo "WHERE src.template_name = '2026 Tanacey Contract'\n";
echo "  AND NOT EXISTS (SELECT 1 FROM re_contract_templates x WHERE x.company_id = src.company_id AND x.template_code = 'tenancy_contract_v2')\n";
echo "ORDER BY src.id LIMIT 1;\n\n";
echo "-- Check: expect one row, is_default = 0\n";
echo "SELECT id, company_id, template_name, template_code, is_default, is_active, LENGTH(template_html) AS html_bytes\n";
echo "FROM re_contract_templates WHERE template_code = 'tenancy_contract_v2';\n\n";
echo "-- To refresh the HTML of an existing v2 row later (after a translator update), run only this:\n";
echo "-- UPDATE re_contract_templates SET template_html = <new html>, template_content = <new html> WHERE template_code = 'tenancy_contract_v2';\n";
