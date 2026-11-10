<?php
require '/Users/ainalreem/Sites/saifsys/vendor/autoload.php';
$m = new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'A4-L','margin_top'=>10,'margin_bottom'=>12,'margin_left'=>8,'margin_right'=>8,'tempDir'=>sys_get_temp_dir(),'default_font'=>'dejavusans']);
$m->SetHTMLFooter('<div style="font-size:7px;text-align:center;color:#666">Tenancy Contract v1 vs v2 - for review, not final - page {PAGENO} of {nbpg}</div>');
$m->WriteHTML(file_get_contents($argv[1]));
$m->Output($argv[2], 'F'); echo count($m->pages), " pages\n";
