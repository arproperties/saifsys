<?php
// php final_pdf_any.php <in.html> <out.pdf> "<footer text>"   (landscape translator table)
require dirname(__DIR__, 3) . '/vendor/autoload.php';
$m = new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'A4-L','margin_top'=>10,'margin_bottom'=>12,'margin_left'=>8,'margin_right'=>8,'tempDir'=>sys_get_temp_dir(),'default_font'=>'dejavusans','autoScriptToLang'=>true,'autoLangToFont'=>true]);
$m->SetHTMLFooter('<div style="font-size:7px;text-align:center;color:#666">' . htmlspecialchars($argv[3] ?? '') . ' - page {PAGENO} of {nbpg}</div>');
$m->WriteHTML(file_get_contents($argv[1])); $m->Output($argv[2], 'F'); echo count($m->pages), " pages\n";
