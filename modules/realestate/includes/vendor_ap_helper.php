<?php
/** Phase 9.5 Vendor/AP helper. */
declare(strict_types=1);
require_once __DIR__ . '/../accounting/accounting_integration.php';
require_once __DIR__ . '/vendor_advance_helper.php';
require_once __DIR__ . '/vendor_advance_vat_helper.php';
require_once __DIR__ . '/vendor_advance_refund_helper.php';

function re_ap_money($v): float { return round((float)$v, 2); }
function re_ap_setting(PDO $conn, string $key, string $default=''): string { try{$s=$conn->prepare("SELECT `value` FROM settings WHERE `key`=? LIMIT 1");$s->execute([$key]);$v=$s->fetchColumn();return $v===false?$default:(string)$v;}catch(Throwable $e){return $default;} }
function re_ap_audit(PDO $conn,int $companyId,?int $vendorId,?int $billId,?int $paymentId,string $action,?string $old=null,?string $new=null,?float $amount=null,string $reason='',?int $userId=null,string $source='system',?int $journalId=null): void { try{$st=$conn->prepare("INSERT INTO re_vendor_ap_audit (company_id,vendor_id,vendor_invoice_id,vendor_payment_id,action_type,old_value,new_value,amount,reason,changed_by,source,related_journal_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");$st->execute([$companyId,$vendorId,$billId,$paymentId,$action,$old,$new,$amount,$reason?:null,$userId,$source,$journalId]);}catch(Throwable $e){error_log('Vendor AP audit failed: '.$e->getMessage());} }
function re_ap_input_vat_account(PDO $conn,int $companyId): ?array { $code=re_ap_setting($conn,'re_vendor_input_vat_account_code','2320'); return find_account_by_code($code,$companyId) ?: find_account_by_code('1260',$companyId); }
function re_ap_account(PDO $conn,int $companyId): ?array { $code=re_ap_setting($conn,'re_vendor_ap_account_code','2130'); return find_account_by_code($code,$companyId) ?: find_account_by_code('2100',$companyId) ?: find_account_by_code('2000',$companyId); }
function re_ap_default_expense_account(PDO $conn,int $companyId): ?array { $code=re_ap_setting($conn,'re_vendor_default_expense_account_code','5100'); return find_account_by_code($code,$companyId) ?: find_account_by_code('5000',$companyId); }
function re_ap_bill_paid_amount(PDO $conn,int $companyId,int $billId): float {
    try {
        $st = $conn->prepare("SELECT COALESCE(SUM(amount_allocated),0) FROM re_vendor_payment_allocations WHERE company_id=? AND vendor_invoice_id=?");
        $st->execute([$companyId, $billId]);
        $alloc = re_ap_money($st->fetchColumn());
        $adv = function_exists('re_ap_advance_applied_on_bill') ? re_ap_advance_applied_on_bill($conn, $companyId, $billId) : 0.0;
        return re_ap_money($alloc + $adv);
    } catch (Throwable $e) {
        return 0.0;
    }
}

/** Linked advance VAT already recognized — reduces bill outstanding (not a cash payment). */
function re_ap_bill_linked_advance_vat_credit(PDO $conn, int $companyId, int $billId): float
{
    if (!function_exists('re_ap_advance_vat_linked_to_bill')) {
        return 0.0;
    }
    return re_ap_advance_vat_linked_to_bill($conn, $companyId, $billId);
}

/** Amount still payable on the bill after cash/advance apply and linked advance VAT. */
function re_ap_bill_outstanding(PDO $conn, int $companyId, int $billId): float
{
    $st = $conn->prepare("SELECT total_amount FROM re_vendor_invoices WHERE id=? AND company_id=?");
    $st->execute([$billId, $companyId]);
    $total = re_ap_money($st->fetchColumn());
    $paid = re_ap_bill_paid_amount($conn, $companyId, $billId);
    $linkedVat = re_ap_bill_linked_advance_vat_credit($conn, $companyId, $billId);
    return re_ap_money(max(0, $total - $linkedVat - $paid));
}

function re_ap_refresh_bill_status(PDO $conn,int $companyId,int $billId): void {
    $st=$conn->prepare("SELECT total_amount,status FROM re_vendor_invoices WHERE id=? AND company_id=?");
    $st->execute([$billId,$companyId]);
    $bill=$st->fetch(PDO::FETCH_ASSOC);
    if(!$bill)return;
    $paid=re_ap_bill_paid_amount($conn,$companyId,$billId);
    $total=(float)$bill['total_amount'];
    $linkedVat=re_ap_bill_linked_advance_vat_credit($conn,$companyId,$billId);
    $bal=re_ap_money(max(0,$total-$linkedVat-$paid));
    $status=(string)$bill['status'];
    if($status!=='void'&&$status!=='cancelled'&&$status!=='draft'){
        if($bal<=0.005&&$total>0)$status='paid';
        elseif($paid>0)$status='partially_paid';
        else $status='open';
    }
    $conn->prepare("UPDATE re_vendor_invoices SET paid_amount=?, balance_due=?, status=? WHERE id=? AND company_id=?")
        ->execute([$paid,$bal,$status,$billId,$companyId]);
}

function re_ap_load_bill(PDO $conn,int $companyId,int $billId): ?array { $st=$conn->prepare("SELECT vi.*,v.vendor_name FROM re_vendor_invoices vi JOIN re_vendors v ON v.id=vi.vendor_id AND v.company_id=vi.company_id WHERE vi.id=? AND vi.company_id=? LIMIT 1");$st->execute([$billId,$companyId]);$r=$st->fetch(PDO::FETCH_ASSOC);return $r?:null; }
function re_ap_recurring_table_exists(PDO $conn): bool { try{$st=$conn->prepare("SHOW TABLES LIKE 're_vendor_recurring_bills'");$st->execute();return (bool)$st->fetchColumn();}catch(Throwable $e){return false;} }
function re_ap_recurring_column_exists(PDO $conn,string $column): bool { $st=$conn->prepare("SHOW COLUMNS FROM re_vendor_recurring_bills LIKE ?");$st->execute([$column]);return (bool)$st->fetch(PDO::FETCH_ASSOC); }
function re_ap_recurring_add_column(PDO $conn,string $column,string $definition): void { if(!re_ap_recurring_column_exists($conn,$column))$conn->exec("ALTER TABLE re_vendor_recurring_bills ADD COLUMN `$column` $definition"); }
function re_ap_recurring_frequency_supports_weekly(PDO $conn): bool { $st=$conn->prepare("SHOW COLUMNS FROM re_vendor_recurring_bills LIKE 'frequency'");$st->execute();$col=$st->fetch(PDO::FETCH_ASSOC);return $col&&strpos((string)$col['Type'],"'weekly'")!==false; }
function re_ap_recurring_ensure_schema(PDO $conn): bool { if(!re_ap_recurring_table_exists($conn))return false;if(!re_ap_recurring_frequency_supports_weekly($conn))$conn->exec("ALTER TABLE re_vendor_recurring_bills MODIFY COLUMN frequency ENUM('weekly','monthly','quarterly','semi_annual','annual') NOT NULL DEFAULT 'monthly'");re_ap_recurring_add_column($conn,'invoice_number_prefix',"VARCHAR(100) DEFAULT NULL AFTER `template_name`");re_ap_recurring_add_column($conn,'due_days',"INT(11) NOT NULL DEFAULT 0 AFTER `end_date`");re_ap_recurring_add_column($conn,'payment_terms',"VARCHAR(100) DEFAULT NULL AFTER `due_days`");re_ap_recurring_add_column($conn,'place_of_supply',"VARCHAR(100) DEFAULT 'Dubai' AFTER `payment_terms`");re_ap_recurring_add_column($conn,'vat_treatment',"ENUM('vat_registered','non_vat','exempt','out_of_scope') NOT NULL DEFAULT 'vat_registered' AFTER `place_of_supply`");re_ap_recurring_add_column($conn,'order_number',"VARCHAR(100) DEFAULT NULL AFTER `vat_treatment`");re_ap_recurring_add_column($conn,'permit_number',"VARCHAR(100) DEFAULT NULL AFTER `order_number`");re_ap_recurring_add_column($conn,'notes',"TEXT DEFAULT NULL AFTER `permit_number`");re_ap_recurring_add_column($conn,'subtotal',"DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `notes`");re_ap_recurring_add_column($conn,'tax_amount',"DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `subtotal`");re_ap_recurring_add_column($conn,'total_amount',"DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `tax_amount`");re_ap_recurring_add_column($conn,'lines_json',"LONGTEXT DEFAULT NULL AFTER `total_amount`");re_ap_recurring_add_column($conn,'last_generated_bill_date',"DATE DEFAULT NULL AFTER `lines_json`");re_ap_recurring_add_column($conn,'last_generated_invoice_id',"INT(11) DEFAULT NULL AFTER `last_generated_bill_date`");return true; }
function re_ap_recurring_next_bill_date(string $billDate,string $frequency): string { $step=['weekly'=>'+1 week','quarterly'=>'+3 months','semi_annual'=>'+6 months','annual'=>'+1 year','monthly'=>'+1 month'][$frequency]??'+1 month';return date('Y-m-d',strtotime($step,strtotime($billDate))); }
function re_ap_recurring_unique_invoice_number(PDO $conn,int $companyId,array $rec,string $billDate): string { $prefix=trim((string)($rec['invoice_number_prefix']??''));if($prefix==='')$prefix='REC-'.$rec['id'];$prefix=preg_replace('/[^A-Za-z0-9_-]+/','-',$prefix)?:('REC-'.$rec['id']);$base=substr($prefix,0,70).'-'.date('Ymd',strtotime($billDate));$candidate=$base;$i=2;$st=$conn->prepare("SELECT id FROM re_vendor_invoices WHERE company_id=? AND invoice_number=? LIMIT 1");while(true){$st->execute([$companyId,$candidate]);if(!$st->fetchColumn())return $candidate;$candidate=substr($base,0,92).'-'.$i;$i++;} }
function re_ap_recurring_template_lines(PDO $conn,int $companyId,array $rec): array { $lines=json_decode((string)($rec['lines_json']??''),true);if(is_array($lines)&&$lines)return $lines;$sourceId=(int)($rec['vendor_invoice_id']??0);if($sourceId<=0)return [];$st=$conn->prepare("SELECT * FROM re_vendor_invoice_items WHERE company_id=? AND invoice_id=? ORDER BY id");$st->execute([$companyId,$sourceId]);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];$out=[];foreach($rows as $r){$out[]=['expense_account_id'=>(int)($r['expense_account_id']??0),'description'=>$r['service_name']?:($r['line_description']?:($r['description']??'')),'quantity'=>(float)($r['quantity']??1),'unit_price'=>(float)($r['unit_price']??0),'subtotal'=>(float)($r['subtotal']??0),'vat_treatment'=>$r['vat_treatment']??'standard','vat_rate'=>(float)($r['vat_rate']??0),'vat_amount'=>(float)($r['vat_amount']??0),'line_total'=>(float)($r['line_total']??$r['total_price']??0),'building_id'=>(int)($r['building_id']??0),'unit_id'=>(int)($r['unit_id']??0),'lease_id'=>(int)($r['lease_id']??0)];}return $out; }
function re_ap_recurring_totals(array $lines): array { $subtotal=0.0;$tax=0.0;$total=0.0;$valid=[];foreach($lines as $ln){$acct=(int)($ln['expense_account_id']??0);$qty=(float)($ln['quantity']??1);$rate=(float)($ln['unit_price']??0);if($acct<=0||$qty<=0||$rate<0)continue;$vt=(string)($ln['vat_treatment']??'standard');if(!in_array($vt,['standard','exempt','zero_rated','out_of_scope'],true))$vt='standard';$vr=$vt==='standard'?(float)($ln['vat_rate']??5):0.0;$sub=round($qty*$rate,2);$vat=$vt==='standard'&&$vr>0?round($sub*$vr/100,2):0.0;$lineTotal=$sub+$vat;$valid[]=['expense_account_id'=>$acct,'description'=>trim((string)($ln['description']??'')),'quantity'=>$qty,'unit_price'=>$rate,'subtotal'=>$sub,'vat_treatment'=>$vt,'vat_rate'=>$vr,'vat_amount'=>$vat,'line_total'=>$lineTotal,'building_id'=>(int)($ln['building_id']??0),'unit_id'=>(int)($ln['unit_id']??0),'lease_id'=>(int)($ln['lease_id']??0)];$subtotal+=$sub;$tax+=$vat;$total+=$lineTotal;}return ['lines'=>$valid,'subtotal'=>re_ap_money($subtotal),'tax_amount'=>re_ap_money($tax),'total_amount'=>re_ap_money($total)]; }
function re_ap_generate_due_recurring_bills(PDO $conn,int $companyId,?int $userId=null): array { $result=['generated'=>0,'invoice_ids'=>[],'errors'=>[]];try{if(!re_ap_recurring_ensure_schema($conn))return $result;$due=$conn->prepare("SELECT id FROM re_vendor_recurring_bills WHERE company_id=? AND status='active' AND next_bill_date IS NOT NULL AND next_bill_date<=CURDATE() AND (end_date IS NULL OR next_bill_date<=end_date) ORDER BY next_bill_date ASC,id ASC LIMIT 50");$due->execute([$companyId]);$ids=array_map('intval',$due->fetchAll(PDO::FETCH_COLUMN)?:[]);foreach($ids as $id){try{$conn->beginTransaction();$lock=$conn->prepare("SELECT * FROM re_vendor_recurring_bills WHERE id=? AND company_id=? FOR UPDATE");$lock->execute([$id,$companyId]);$rec=$lock->fetch(PDO::FETCH_ASSOC);if(!$rec||$rec['status']!=='active'||empty($rec['next_bill_date'])||$rec['next_bill_date']>date('Y-m-d')||(!empty($rec['end_date'])&&$rec['next_bill_date']>$rec['end_date'])){$conn->commit();continue;}$billDate=(string)$rec['next_bill_date'];if(!empty($rec['last_generated_bill_date'])&&$rec['last_generated_bill_date']===$billDate){$next=re_ap_recurring_next_bill_date($billDate,(string)$rec['frequency']);$newStatus=(!empty($rec['end_date'])&&$next>$rec['end_date'])?'paused':'active';$nextValue=$newStatus==='paused'?null:$next;$conn->prepare("UPDATE re_vendor_recurring_bills SET next_bill_date=?,status=? WHERE id=? AND company_id=?")->execute([$nextValue,$newStatus,$id,$companyId]);$conn->commit();continue;}$lineData=re_ap_recurring_totals(re_ap_recurring_template_lines($conn,$companyId,$rec));if(!$lineData['lines'])throw new RuntimeException('No valid lines on recurring template #'.$id);$invoiceNumber=re_ap_recurring_unique_invoice_number($conn,$companyId,$rec,$billDate);$dueDate=date('Y-m-d',strtotime('+'.max(0,(int)($rec['due_days']??0)).' days',strtotime($billDate)));$notes=trim((string)($rec['notes']??''));$notes=trim($notes."\nGenerated from recurring bill template #".$id.' - '.($rec['template_name']??''));$ins=$conn->prepare("INSERT INTO re_vendor_invoices (company_id,vendor_id,invoice_number,order_number,permit_number,place_of_supply,vat_treatment,invoice_date,due_date,payment_terms,subtotal,tax_amount,discount_amount,total_amount,paid_amount,balance_due,status,posting_status,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,'not_posted',?,?)");$ins->execute([$companyId,(int)$rec['vendor_id'],$invoiceNumber,$rec['order_number']?:null,$rec['permit_number']?:null,$rec['place_of_supply']?:'Dubai',$rec['vat_treatment']?:'vat_registered',$billDate,$dueDate,$rec['payment_terms']?:null,$lineData['subtotal'],$lineData['tax_amount'],$lineData['total_amount'],0,$lineData['total_amount'],'open',$notes?:null,$userId]);$billId=(int)$conn->lastInsertId();$lineStmt=$conn->prepare("INSERT INTO re_vendor_invoice_items (company_id,invoice_id,expense_account_id,building_id,unit_id,lease_id,service_name,description,line_description,quantity,unit_price,subtotal,vat_treatment,vat_rate,vat_amount,line_total,total_price) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");foreach($lineData['lines'] as $ln){$desc=$ln['description']?:'Recurring bill line';$lineStmt->execute([$companyId,$billId,$ln['expense_account_id'],$ln['building_id']?:null,$ln['unit_id']?:null,$ln['lease_id']?:null,$desc,$desc,$desc,$ln['quantity'],$ln['unit_price'],$ln['subtotal'],$ln['vat_treatment'],$ln['vat_rate'],$ln['vat_amount'],$ln['line_total'],$ln['line_total']]);}$next=re_ap_recurring_next_bill_date($billDate,(string)$rec['frequency']);$newStatus=(!empty($rec['end_date'])&&$next>$rec['end_date'])?'paused':'active';$nextValue=$newStatus==='paused'?null:$next;$conn->prepare("UPDATE re_vendor_recurring_bills SET last_generated_bill_date=?,last_generated_invoice_id=?,next_bill_date=?,status=? WHERE id=? AND company_id=?")->execute([$billDate,$billId,$nextValue,$newStatus,$id,$companyId]);re_ap_audit($conn,$companyId,(int)$rec['vendor_id'],$billId,null,'recurring_bill_generated',null,$billDate,(float)$lineData['total_amount'],'Vendor bill generated from recurring template #'.$id,$userId,'vendor_recurring_bills');$conn->commit();$result['generated']++;$result['invoice_ids'][]=$billId;}catch(Throwable $e){if($conn->inTransaction())$conn->rollBack();$result['errors'][]=$e->getMessage();}}}catch(Throwable $e){$result['errors'][]=$e->getMessage();}return $result; }
function re_ap_unique_vendor_bill_number(PDO $conn,int $companyId,string $base): string { $base=trim($base)?:('BILL-'.date('Ymd'));$base=substr(preg_replace('/[^A-Za-z0-9_-]+/','-',$base),0,88);$candidate=$base;$i=2;$st=$conn->prepare("SELECT id FROM re_vendor_invoices WHERE company_id=? AND invoice_number=? LIMIT 1");while(true){$st->execute([$companyId,$candidate]);if(!$st->fetchColumn())return $candidate;$candidate=substr($base,0,92).'-'.$i;$i++;} }
function re_ap_copy_vendor_bill_as_draft(PDO $conn,int $companyId,int $billId,?int $userId=null,string $reason='Amendment copy'): array { try{$bill=re_ap_load_bill($conn,$companyId,$billId);if(!$bill)return ['success'=>false,'bill_id'=>null,'error'=>'Bill not found'];$lines=$conn->prepare("SELECT * FROM re_vendor_invoice_items WHERE company_id=? AND invoice_id=? ORDER BY id");$lines->execute([$companyId,$billId]);$rows=$lines->fetchAll(PDO::FETCH_ASSOC)?:[];if(!$rows)return ['success'=>false,'bill_id'=>null,'error'=>'Original bill has no lines to copy'];$invoiceNumber=re_ap_unique_vendor_bill_number($conn,$companyId,$bill['invoice_number'].'-AMEND-'.date('Ymd'));$notes=trim((string)($bill['notes']??''));$notes=trim($notes."\nAmendment copy of bill #".$bill['invoice_number'].". ".$reason);$conn->beginTransaction();$ins=$conn->prepare("INSERT INTO re_vendor_invoices (company_id,vendor_id,invoice_number,order_number,permit_number,place_of_supply,vat_treatment,invoice_date,due_date,payment_terms,subtotal,tax_amount,discount_amount,total_amount,paid_amount,balance_due,status,posting_status,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,'not_posted',?,?)");$ins->execute([$companyId,(int)$bill['vendor_id'],$invoiceNumber,$bill['order_number']?:null,$bill['permit_number']?:null,$bill['place_of_supply']?:'Dubai',$bill['vat_treatment']?:'vat_registered',date('Y-m-d'),$bill['due_date']?:date('Y-m-d'),$bill['payment_terms']?:null,(float)$bill['subtotal'],(float)$bill['tax_amount'],(float)$bill['total_amount'],0,(float)$bill['total_amount'],'draft',$notes?:null,$userId]);$newBillId=(int)$conn->lastInsertId();$lineStmt=$conn->prepare("INSERT INTO re_vendor_invoice_items (company_id,invoice_id,expense_account_id,building_id,unit_id,lease_id,service_name,description,line_description,quantity,unit_price,subtotal,vat_treatment,vat_rate,vat_amount,line_total,total_price) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");foreach($rows as $ln){$desc=$ln['service_name']?:($ln['line_description']?:($ln['description']?:'Bill line'));$lineStmt->execute([$companyId,$newBillId,$ln['expense_account_id']?:null,$ln['building_id']?:null,$ln['unit_id']?:null,$ln['lease_id']?:null,$desc,$ln['description']?:$desc,$ln['line_description']?:$desc,$ln['quantity'],$ln['unit_price'],$ln['subtotal']??0,$ln['vat_treatment']??'standard',$ln['vat_rate']??0,$ln['vat_amount']??0,$ln['line_total']??$ln['total_price']??0,$ln['total_price']??$ln['line_total']??0]);}re_ap_audit($conn,$companyId,(int)$bill['vendor_id'],$newBillId,null,'bill_amendment_created',(string)$billId,(string)$newBillId,(float)$bill['total_amount'],'Draft amendment created from bill #'.$bill['invoice_number'],$userId,'vendor_bill_amendment');$conn->commit();return ['success'=>true,'bill_id'=>$newBillId,'error'=>null];}catch(Throwable $e){if($conn->inTransaction())$conn->rollBack();return ['success'=>false,'bill_id'=>null,'error'=>$e->getMessage()];} }
function re_ap_void_posted_vendor_bill(PDO $conn,int $companyId,int $billId,string $reason,?int $userId=null): array { try{$bill=re_ap_load_bill($conn,$companyId,$billId);if(!$bill)return ['success'=>false,'reversal_journal_id'=>null,'error'=>'Bill not found'];if(in_array($bill['status'],['void','cancelled'],true)||($bill['posting_status']??'')==='reversed')return ['success'=>true,'reversal_journal_id'=>null,'already_void'=>true,'error'=>null];$payments=$conn->prepare("SELECT COALESCE(SUM(amount_allocated),0) FROM re_vendor_payment_allocations WHERE company_id=? AND vendor_invoice_id=?");$payments->execute([$companyId,$billId]);if((float)$payments->fetchColumn()>0.005)return ['success'=>false,'reversal_journal_id'=>null,'error'=>'This bill has payments allocated. Reverse/unallocate the payments before voiding the bill.'];if(function_exists('re_ap_advance_applied_on_bill')&&re_ap_advance_applied_on_bill($conn,$companyId,$billId)>0.005)return ['success'=>false,'reversal_journal_id'=>null,'error'=>'This bill has vendor advances applied. Unapply the advances before voiding the bill.'];$journalId=(int)($bill['journal_id']??0);if($journalId<=0){$jh=$conn->prepare("SELECT id FROM re_journal_headers WHERE company_id=? AND reference_type='vendor_invoice' AND reference_id=? AND is_posted=1 AND is_reversed=0 ORDER BY id DESC LIMIT 1");$jh->execute([$companyId,$billId]);$journalId=(int)($jh->fetchColumn()?:0);}if($journalId<=0)return ['success'=>false,'reversal_journal_id'=>null,'error'=>'Posted journal was not found for this bill.'];$ownTx=!$conn->inTransaction();if($ownTx)$conn->beginTransaction();try{if(function_exists('re_ap_reverse_advance_vat_links_on_bill')){re_ap_reverse_advance_vat_links_on_bill($conn,$companyId,$billId,$userId);}$reverse=reverse_journal($journalId,$reason?:'Vendor bill voided',$userId);if(empty($reverse['success']))throw new RuntimeException($reverse['error']??'Journal reversal failed');$reversalJournalId=(int)$reverse['reversal_journal_id'];$ap=re_ap_account($conn,$companyId);if($ap){$ledger=get_or_create_vendor_ledger((int)$bill['vendor_id'],(int)$ap['id'],$companyId);$jl=$conn->prepare("SELECT id FROM re_journal_lines WHERE journal_id=? AND account_id=? ORDER BY line_number LIMIT 1");$jl->execute([$reversalJournalId,(int)$ap['id']]);$journalLineId=(int)($jl->fetchColumn()?:0);post_to_vendor_ledger($ledger['id'],date('Y-m-d'),(float)$bill['total_amount'],0,'Void vendor bill: '.$bill['invoice_number'],$bill['invoice_number'],$companyId,$reversalJournalId,$journalLineId?:null);}$note=trim((string)($bill['notes']??''));$note=trim($note."\nVoided/reversed on ".date('Y-m-d').($reason?': '.$reason:''));$conn->prepare("UPDATE re_vendor_invoices SET status='void', posting_status='reversed', paid_amount=0, balance_due=0, notes=?, updated_at=NOW() WHERE id=? AND company_id=?")->execute([$note?:null,$billId,$companyId]);re_ap_audit($conn,$companyId,(int)$bill['vendor_id'],$billId,null,'bill_voided',(string)$journalId,(string)$reversalJournalId,(float)$bill['total_amount'],$reason?:'Vendor bill voided',$userId,'vendor_bill_void',$reversalJournalId);if($ownTx)$conn->commit();return ['success'=>true,'reversal_journal_id'=>$reversalJournalId,'error'=>null];}catch(Throwable $e){if($ownTx&&$conn->inTransaction())$conn->rollBack();return ['success'=>false,'reversal_journal_id'=>null,'error'=>$e->getMessage()];}}catch(Throwable $e){return ['success'=>false,'reversal_journal_id'=>null,'error'=>$e->getMessage()];} }
function re_ap_post_vendor_bill(PDO $conn,int $companyId,int $billId,?int $userId=null): array { $bill=re_ap_load_bill($conn,$companyId,$billId); if(!$bill)return ['success'=>false,'error'=>'Bill not found']; if(($bill['posting_status']??'not_posted')==='posted'&&!empty($bill['journal_id']))return ['success'=>true,'journal_id'=>(int)$bill['journal_id'],'already_posted'=>true,'error'=>null];
$existingJournalStmt=$conn->prepare("SELECT id FROM re_journal_headers WHERE company_id=? AND reference_type='vendor_invoice' AND reference_id=? AND is_posted=1 AND is_reversed=0 ORDER BY id DESC LIMIT 1");
$existingJournalStmt->execute([$companyId,$billId]);
$existingJournalId=(int)($existingJournalStmt->fetchColumn()?:0);
if($existingJournalId>0){
    $conn->prepare("UPDATE re_vendor_invoices SET posting_status='posted', journal_id=COALESCE(journal_id, ?) WHERE id=? AND company_id=?")->execute([$existingJournalId,$billId,$companyId]);
    re_ap_refresh_bill_status($conn,$companyId,$billId);
    return ['success'=>true,'journal_id'=>$existingJournalId,'already_posted'=>true,'error'=>null];
} $items=$conn->prepare("SELECT * FROM re_vendor_invoice_items WHERE invoice_id=? AND company_id=? ORDER BY id");$items->execute([$billId,$companyId]);$rows=$items->fetchAll(PDO::FETCH_ASSOC)?:[]; if(!$rows)return ['success'=>false,'error'=>'Bill has no lines']; $ap=re_ap_account($conn,$companyId); if(!$ap)return ['success'=>false,'error'=>'Accounts Payable account not found']; $lines=[];$vatTotal=0;$netTotal=0; foreach($rows as $r){$net=re_ap_money($r['subtotal']??($r['total_price']??0)); if($net<=0)$net=re_ap_money(((float)($r['line_total']??$r['total_price']??0))-((float)($r['vat_amount']??0))); $acctId=(int)($r['expense_account_id']??0); if($acctId<=0){$default=re_ap_default_expense_account($conn,$companyId); if(!$default)return ['success'=>false,'error'=>'Expense account missing on line and no default expense account found']; $acctId=(int)$default['id'];} if($net>0){$lines[]=['account_id'=>$acctId,'debit'=>$net,'credit'=>0,'description'=>$r['service_name']?:($r['line_description']?:'Vendor bill line'),'reference'=>$bill['invoice_number']];$netTotal+=$net;} $vat=(float)($r['vat_amount']??0); if($vat>0)$vatTotal+=$vat; }
$vatTotal=re_ap_money($vatTotal);
$linkedAdvanceVat=function_exists('re_ap_advance_vat_linked_to_bill')?re_ap_advance_vat_linked_to_bill($conn,$companyId,$billId):0.0;
if($linkedAdvanceVat>$vatTotal+0.005)return ['success'=>false,'error'=>'Linked advance VAT exceeds bill VAT. Adjust VAT document links before posting.'];
$remainingInputVat=re_ap_money(max(0,$vatTotal-$linkedAdvanceVat));
if($remainingInputVat>0.005){$vatAcc=re_ap_input_vat_account($conn,$companyId); if(!$vatAcc)return ['success'=>false,'error'=>'Input VAT account not found'];$lines[]=['account_id'=>(int)$vatAcc['id'],'debit'=>$remainingInputVat,'credit'=>0,'description'=>'Input VAT - '.$bill['invoice_number'].($linkedAdvanceVat>0.005?' (net of advance VAT)':''),'reference'=>$bill['invoice_number']];}
$total=re_ap_money($netTotal+$vatTotal); $apCredit=re_ap_money($netTotal+$remainingInputVat); $lines[]=['account_id'=>(int)$ap['id'],'debit'=>0,'credit'=>$apCredit,'description'=>'AP: '.$bill['vendor_name'].($linkedAdvanceVat>0.005?' (net of advance VAT)':''),'reference'=>$bill['invoice_number']]; $desc='Vendor bill '.$bill['invoice_number'].' - '.$bill['vendor_name']; $res=create_and_post_journal($companyId,'manual','vendor_invoice',$billId,$lines,$desc,$bill['invoice_date'],$userId); if(empty($res['success']))return $res; $journalId=(int)$res['journal_id']; $ledger=get_or_create_vendor_ledger((int)$bill['vendor_id'],(int)$ap['id'],$companyId); $jl=$conn->prepare("SELECT id FROM re_journal_lines WHERE journal_id=? AND account_id=? ORDER BY line_number LIMIT 1");$jl->execute([$journalId,(int)$ap['id']]);$journalLineId=(int)($jl->fetchColumn()?:0); post_to_vendor_ledger($ledger['id'],$bill['invoice_date'],0,$apCredit,$desc,$bill['invoice_number'],$companyId,$journalId,$journalLineId?:null); $conn->prepare("UPDATE re_vendor_invoices SET posting_status='posted', journal_id=?, status=CASE WHEN status='draft' THEN 'open' ELSE status END, balance_due=CASE WHEN balance_due=0 THEN total_amount-paid_amount ELSE balance_due END WHERE id=? AND company_id=?")->execute([$journalId,$billId,$companyId]); re_ap_refresh_bill_status($conn,$companyId,$billId); re_ap_audit($conn,$companyId,(int)$bill['vendor_id'],$billId,null,'bill_posted',null,null,$total,'Vendor bill posted',$userId,'bill_entry',$journalId); return ['success'=>true,'journal_id'=>$journalId,'error'=>null]; }
/** True once migrations/re_vendor_payment_gl_account.sql has run. */
function re_ap_payment_gl_column_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $conn->query('SELECT gl_account_id FROM re_vendor_payments LIMIT 1');
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function re_ap_post_vendor_payment(PDO $conn, int $companyId, int $paymentId, ?int $userId = null): array
{
    if ($companyId <= 0) {
        return ['success' => false, 'error' => 'Company context is required.', 'journal_id' => null];
    }
    $ownTx = !$conn->inTransaction();
    try {
        if ($ownTx) {
            $conn->beginTransaction();
        }
        $st = $conn->prepare("
            SELECT vp.*, v.vendor_name
            FROM re_vendor_payments vp
            JOIN re_vendors v ON v.id = vp.vendor_id AND v.company_id = vp.company_id
            WHERE vp.id = ? AND vp.company_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $st->execute([$paymentId, $companyId]);
        $pay = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pay) {
            throw new RuntimeException('Vendor payment not found');
        }
        if (!empty($pay['journal_id'])) {
            if ($ownTx) {
                $conn->commit();
            }
            return ['success' => false, 'error' => 'Vendor payment already posted', 'journal_id' => null];
        }

        $amount = re_ap_money($pay['amount']);
        $advanceAmount = re_ap_money($pay['advance_amount'] ?? 0);
        if ($advanceAmount < 0) {
            throw new RuntimeException('Invalid advance_amount on payment.');
        }

        $allocSumStmt = $conn->prepare("
            SELECT COALESCE(SUM(amount_allocated), 0)
            FROM re_vendor_payment_allocations
            WHERE company_id = ? AND vendor_payment_id = ?
        ");
        $allocSumStmt->execute([$companyId, $paymentId]);
        $allocated = re_ap_money($allocSumStmt->fetchColumn());
        if (abs(($allocated + $advanceAmount) - $amount) > 0.005) {
            throw new RuntimeException('Payment amount must equal bill allocations plus advance_amount.');
        }

        $ap = re_ap_account($conn, $companyId);
        if (!$ap) {
            throw new RuntimeException('AP account not found');
        }
        $bank = null;
        // Explicit cash/bank GL account chosen on the payment wins; it covers
        // cash accounts that are not registered in re_bank_accounts.
        if (!empty($pay['gl_account_id'])) {
            $g = $conn->prepare("
                SELECT coa.* FROM re_chart_of_accounts coa
                WHERE coa.id = ? AND coa.company_id = ? AND coa.is_active = 1
            ");
            $g->execute([(int)$pay['gl_account_id'], $companyId]);
            $bank = $g->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$bank && !empty($pay['bank_account_id'])) {
            $b = $conn->prepare("
                SELECT coa.* FROM re_bank_accounts ba
                JOIN re_chart_of_accounts coa ON coa.id = ba.gl_account_id
                WHERE ba.id = ? AND ba.company_id = ?
            ");
            $b->execute([(int)$pay['bank_account_id'], $companyId]);
            $bank = $b->fetch(PDO::FETCH_ASSOC);
        }
        if (!$bank) {
            $bank = find_account_by_code($pay['payment_method'] === 'cash' ? '1110' : '1210', $companyId);
        }
        if (!$bank) {
            throw new RuntimeException('Bank/Cash account not found');
        }

        $vendorId = (int)$pay['vendor_id'];
        $desc = 'Vendor payment - ' . $pay['vendor_name'];
        $ref = $pay['reference_number'] ?? null;

        // Legacy path: fully allocated, no advance — identical journal shape to pre-change behaviour.
        if ($advanceAmount <= 0.005) {
            $lines = [
                ['account_id' => (int)$ap['id'], 'debit' => $amount, 'credit' => 0, 'description' => $desc, 'reference' => $ref],
                ['account_id' => (int)$bank['id'], 'debit' => 0, 'credit' => $amount, 'description' => $desc, 'reference' => $ref],
            ];
            $res = create_and_post_journal($companyId, 'payment', 'vendor_payment', $paymentId, $lines, $desc, $pay['payment_date'], $userId);
            if (empty($res['success'])) {
                throw new RuntimeException($res['error'] ?? 'Payment posting failed');
            }
            $journalId = (int)$res['journal_id'];
            $ledger = get_or_create_vendor_ledger($vendorId, (int)$ap['id'], $companyId);
            re_ap_post_to_vendor_ledger_required(
                $conn,
                (int)$ledger['id'],
                $pay['payment_date'],
                $amount,
                0,
                $desc,
                $ref,
                $companyId,
                $journalId,
                (int)$ap['id']
            );
            $conn->prepare("UPDATE re_vendor_payments SET journal_id = ?, status = 'posted' WHERE id = ? AND company_id = ?")
                ->execute([$journalId, $paymentId, $companyId]);
            $alloc = $conn->prepare("SELECT vendor_invoice_id FROM re_vendor_payment_allocations WHERE company_id = ? AND vendor_payment_id = ?");
            $alloc->execute([$companyId, $paymentId]);
            foreach ($alloc->fetchAll(PDO::FETCH_COLUMN) as $billId) {
                re_ap_refresh_bill_status($conn, $companyId, (int)$billId);
            }
            re_ap_audit($conn, $companyId, $vendorId, null, $paymentId, 'payment_posted', null, null, $amount, 'Vendor payment posted', $userId, 'vendor_payment', $journalId);
            if ($ownTx) {
                $conn->commit();
            }
            return ['success' => true, 'journal_id' => $journalId, 'error' => null];
        }

        // Advance path (partial or full unallocated)
        $adv = re_ap_advance_account($conn, $companyId);
        if (!$adv) {
            throw new RuntimeException('Vendor Advances account (1410) not found. Run migration re_vendor_advances.sql.');
        }
        re_ap_lock_vendor_advance_balance($conn, $companyId, $vendorId);

        $lines = [];
        if ($allocated > 0.005) {
            $lines[] = ['account_id' => (int)$ap['id'], 'debit' => $allocated, 'credit' => 0, 'description' => $desc . ' (AP)', 'reference' => $ref];
        }
        $lines[] = ['account_id' => (int)$adv['id'], 'debit' => $advanceAmount, 'credit' => 0, 'description' => $desc . ' (Advance)', 'reference' => $ref];
        $lines[] = ['account_id' => (int)$bank['id'], 'debit' => 0, 'credit' => $amount, 'description' => $desc, 'reference' => $ref];

        $res = create_and_post_journal($companyId, 'payment', 'vendor_payment', $paymentId, $lines, $desc, $pay['payment_date'], $userId);
        if (empty($res['success'])) {
            throw new RuntimeException($res['error'] ?? 'Payment posting failed');
        }
        $journalId = (int)$res['journal_id'];

        if ($allocated > 0.005) {
            $apLedger = get_or_create_vendor_ledger($vendorId, (int)$ap['id'], $companyId);
            re_ap_post_to_vendor_ledger_required(
                $conn,
                (int)$apLedger['id'],
                $pay['payment_date'],
                $allocated,
                0,
                $desc,
                $ref,
                $companyId,
                $journalId,
                (int)$ap['id']
            );
        }
        $advLedger = get_or_create_vendor_ledger($vendorId, (int)$adv['id'], $companyId);
        re_ap_post_to_vendor_ledger_required(
            $conn,
            (int)$advLedger['id'],
            $pay['payment_date'],
            $advanceAmount,
            0,
            $desc . ' (Advance)',
            $ref,
            $companyId,
            $journalId,
            (int)$adv['id']
        );

        re_ap_adjust_vendor_advance_balance($conn, $companyId, $vendorId, $advanceAmount);
        $conn->prepare("UPDATE re_vendor_payments SET journal_id = ?, status = 'posted' WHERE id = ? AND company_id = ?")
            ->execute([$journalId, $paymentId, $companyId]);
        $alloc = $conn->prepare("SELECT vendor_invoice_id FROM re_vendor_payment_allocations WHERE company_id = ? AND vendor_payment_id = ?");
        $alloc->execute([$companyId, $paymentId]);
        foreach ($alloc->fetchAll(PDO::FETCH_COLUMN) as $billId) {
            re_ap_refresh_bill_status($conn, $companyId, (int)$billId);
        }
        re_ap_audit($conn, $companyId, $vendorId, null, $paymentId, 'payment_posted', null, (string)$advanceAmount, $amount, 'Vendor payment posted with advance', $userId, 'vendor_payment', $journalId);
        if ($advanceAmount > 0.005) {
            re_ap_audit($conn, $companyId, $vendorId, null, $paymentId, 'advance_created', null, null, $advanceAmount, 'Vendor advance created from payment', $userId, 'vendor_payment', $journalId);
        }

        if ($ownTx) {
            $conn->commit();
        }
        return ['success' => true, 'journal_id' => $journalId, 'error' => null];
    } catch (Throwable $e) {
        if ($ownTx && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage(), 'journal_id' => null];
    }
}
