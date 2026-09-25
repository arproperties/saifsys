<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/vendor_ap_helper.php';
require_login();
if(!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL,$conn)){require_module_access($conn,MODULE_REALESTATE);} 
$brand=getBrandSettings($conn);
$companyId=(int)(current_company_id($conn)?:0);
if($companyId<=0){http_response_code(400);die('Company context is required.');}
$userId=current_user_id(); $id=(int)($_GET['id']??0); $print=!empty($_GET['print']); $success=''; $error='';
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');} function m($n){return number_format((float)$n,2);} 
$bill=re_ap_load_bill($conn,$companyId,$id); if(!$bill){header('Location: vendor_bills.php');exit;}
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        csrf_verify();
        $action=$_POST['action']??'';
        $reason=trim((string)($_POST['reason']??''));
        if($action==='void_bill'){
            if($reason==='')throw new RuntimeException('Please enter a reason for void/reversal.');
            $res=re_ap_void_posted_vendor_bill($conn,$companyId,$id,$reason,$userId);
            if(!empty($res['success']))$success='Bill voided and journal reversed.'.(!empty($res['reversal_journal_id'])?' Reversal journal #'.$res['reversal_journal_id'].'.':''); else $error=$res['error']??'Could not void bill.';
            $bill=re_ap_load_bill($conn,$companyId,$id);
        }elseif($action==='unpost_bill'){
            if($reason==='')throw new RuntimeException('Please enter a reason for unposting this bill.');
            $res=re_ap_unpost_vendor_bill($conn,$companyId,$id,$reason,$userId);
            if(empty($res['success']))throw new RuntimeException($res['error']??'Could not unpost bill.');
            $success='Bill unposted and journal reversed. You can now edit the amount and post it again.'.(!empty($res['reversal_journal_id'])?' Reversal journal #'.$res['reversal_journal_id'].'.':'');
            header('Location: vendor_bill_edit.php?id='.$id.'&unposted=1');
            exit;
        }elseif($action==='delete_bill'){
            if($reason==='')throw new RuntimeException('Please enter a reason for deleting this bill.');
            $res=re_ap_delete_vendor_bill($conn,$companyId,$id,$reason,$userId);
            if(empty($res['success']))throw new RuntimeException($res['error']??'Could not delete bill.');
            header('Location: vendor_bills.php?deleted='.urlencode((string)($res['invoice_number']??'')));
            exit;
        }elseif($action==='reverse_payment'){
            $payId=(int)($_POST['payment_id']??0);
            if($payId<=0)throw new RuntimeException('Payment not found.');
            if($reason==='')throw new RuntimeException('Please enter a reason for reversing this payment.');
            $res=re_ap_reverse_vendor_payment($conn,$companyId,$payId,$reason,$userId,true);
            if(empty($res['success']))throw new RuntimeException($res['error']??'Could not reverse payment.');
            $success='Payment reversed and unallocated from this bill.'.(!empty($res['reversal_journal_id'])?' Reversal journal #'.$res['reversal_journal_id'].'.':'');
            $bill=re_ap_load_bill($conn,$companyId,$id);
        }elseif($action==='amend_bill'){
            if($reason==='')throw new RuntimeException('Please enter a reason for amendment.');
            $void=re_ap_void_posted_vendor_bill($conn,$companyId,$id,$reason,$userId);
            if(empty($void['success']))throw new RuntimeException($void['error']??'Could not void original bill.');
            $copy=re_ap_copy_vendor_bill_as_draft($conn,$companyId,$id,$userId,$reason);
            if(empty($copy['success']))throw new RuntimeException($copy['error']??'Could not create amendment draft.');
            header('Location: vendor_bill_edit.php?id='.(int)$copy['bill_id']);
            exit;
        }elseif($action==='apply_advance'){
            $amt=round((float)($_POST['apply_amount']??0),2);
            $srcRaw=trim((string)($_POST['source_payment_ids']??''));
            $srcIds=null;
            if($srcRaw!==''){
                // Single select or comma list
                $srcIds=array_values(array_filter(array_map('intval',preg_split('/[\s,;]+/',$srcRaw)),static function($id){return $id>0;}));
                if(!$srcIds)$srcIds=null;
            }
            $res=re_ap_apply_vendor_advance($conn,$companyId,(int)$bill['vendor_id'],$id,$amt,$userId,$srcIds);
            if(empty($res['success']))throw new RuntimeException($res['error']??'Could not apply advance.');
            $success='Vendor advance applied.'.(!empty($res['journal_id'])?' Journal #'.$res['journal_id'].'.':'');
            $bill=re_ap_load_bill($conn,$companyId,$id);
        }elseif($action==='unapply_advance'){
            $appId=(int)($_POST['application_id']??0);
            $res=re_ap_unapply_vendor_advance($conn,$companyId,$appId,$reason?:'Unapply advance',$userId);
            if(empty($res['success']))throw new RuntimeException($res['error']??'Could not unapply advance.');
            $success='Vendor advance unapplied.'.(!empty($res['reversal_journal_id'])?' Reversal journal #'.$res['reversal_journal_id'].'.':'');
            $bill=re_ap_load_bill($conn,$companyId,$id);
        }elseif($action==='link_advance_vat'){
            $links=[];
            $raw=$_POST['vat_links']??[];
            if(is_array($raw)){
                foreach($raw as $docId=>$amt){
                    $docId=(int)$docId;
                    $vatAmt=round((float)$amt,2);
                    if($docId<=0||$vatAmt<=0.005)continue;
                    $taxAmt=round((float)($_POST['vat_taxable'][$docId]??0),2);
                    $links[]=['document_id'=>$docId,'vat_amount'=>$vatAmt,'taxable_amount'=>$taxAmt];
                }
            }
            if(!$links)throw new RuntimeException('Select at least one Advance VAT amount to link.');
            $res=re_ap_link_advance_vat_to_bill($conn,$companyId,$id,$links,$userId);
            if(empty($res['success']))throw new RuntimeException($res['error']??'Could not link advance VAT.');
            $success='Advance VAT linked. Remaining bill Input VAT to post: '.number_format((float)$res['remaining_bill_vat'],2).' AED.';
            $bill=re_ap_load_bill($conn,$companyId,$id);
        }elseif($action==='unlink_advance_vat'){
            $linkId=(int)($_POST['link_id']??0);
            $res=re_ap_unlink_advance_vat_from_bill($conn,$companyId,$linkId,$userId);
            if(empty($res['success']))throw new RuntimeException($res['error']??'Could not unlink advance VAT.');
            $success='Advance VAT link removed.';
            $bill=re_ap_load_bill($conn,$companyId,$id);
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
$lines=$conn->prepare("SELECT it.*, coa.account_code, coa.account_name, b.name building_name, u.unit_number, l.lease_number FROM re_vendor_invoice_items it LEFT JOIN re_chart_of_accounts coa ON coa.id=it.expense_account_id LEFT JOIN re_buildings b ON b.id=it.building_id LEFT JOIN re_units u ON u.id=it.unit_id LEFT JOIN re_leases l ON l.id=it.lease_id WHERE it.company_id=? AND it.invoice_id=? ORDER BY it.id");$lines->execute([$companyId,$id]);$lines=$lines->fetchAll(PDO::FETCH_ASSOC)?:[];
$attachments=[];try{$a=$conn->prepare("SELECT * FROM re_vendor_bill_attachments WHERE company_id=? AND vendor_invoice_id=? ORDER BY uploaded_at DESC");$a->execute([$companyId,$id]);$attachments=$a->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){}
$payments=[];try{$p=$conn->prepare("SELECT vp.*, a.amount_allocated FROM re_vendor_payment_allocations a JOIN re_vendor_payments vp ON vp.id=a.vendor_payment_id AND vp.company_id=a.company_id WHERE a.company_id=? AND a.vendor_invoice_id=? ORDER BY vp.payment_date DESC");$p->execute([$companyId,$id]);$payments=$p->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){}
$advanceApps=[];$advanceApplied=0.0;
try{
    $aa=$conn->prepare("SELECT a.*, vp.payment_date, vp.reference_number FROM re_vendor_advance_applications a JOIN re_vendor_payments vp ON vp.id=a.vendor_payment_id AND vp.company_id=a.company_id WHERE a.company_id=? AND a.vendor_invoice_id=? ORDER BY a.id DESC");
    $aa->execute([$companyId,$id]);
    $advanceApps=$aa->fetchAll(PDO::FETCH_ASSOC)?:[];
    foreach($advanceApps as $apRow){ if(($apRow['status']??'')==='posted') $advanceApplied+=(float)$apRow['amount']; }
}catch(Throwable $e){}
$vendorAdvanceBal=function_exists('re_ap_vendor_advance_balance')?re_ap_vendor_advance_balance($conn,$companyId,(int)$bill['vendor_id']):0.0;
$advanceSources=function_exists('re_ap_advance_source_payments')?re_ap_advance_source_payments($conn,$companyId,(int)$bill['vendor_id'],null):[];
$advanceSourcesTotal=0.0;foreach($advanceSources as $srcRow){$advanceSourcesTotal+=(float)$srcRow['remaining'];}
$advanceSourcesTotal=round($advanceSourcesTotal,2);
$allocatedTotal=0;foreach($payments as $p){$allocatedTotal+=(float)($p['amount_allocated']??0);}
$canVoidPosted=(($bill['posting_status']??'')==='posted'||!empty($bill['journal_id']))&&!in_array($bill['status'],['void','cancelled','paid'],true)&&$allocatedTotal<=0.005&&$advanceApplied<=0.005;
$isPostedBill=(($bill['posting_status']??'')==='posted'||!empty($bill['journal_id']));
$canUnpost=$isPostedBill&&!in_array($bill['status'],['void','cancelled'],true)&&$allocatedTotal<=0.005&&$advanceApplied<=0.005;
$billHasJournalHistory=re_ap_bill_has_journal_history($conn,$companyId,$id);
$canDeleteBill=!$isPostedBill&&$allocatedTotal<=0.005&&$advanceApplied<=0.005&&!$billHasJournalHistory;
// Unposted but already in the GL history: it cannot be deleted, only closed off as void.
$canVoidRecord=!$isPostedBill&&$billHasJournalHistory&&!in_array($bill['status'],['void','cancelled'],true)&&$allocatedTotal<=0.005&&$advanceApplied<=0.005;
$livePayments=array_values(array_filter($payments,static function($row){return ($row['status']??'')!=='void'&&(float)($row['amount_allocated']??0)>0.005;}));
$balDue=(float)($bill['balance_due']??max(0,$bill['total_amount']-$bill['paid_amount']));
$applyMax=min($balDue,$vendorAdvanceBal,$advanceSourcesTotal>0?$advanceSourcesTotal:$vendorAdvanceBal);
$billEligibleVat=function_exists('re_ap_bill_eligible_vat')?re_ap_bill_eligible_vat($conn,$companyId,$id):re_ap_money($bill['tax_amount']??0);
$linkedAdvanceVat=function_exists('re_ap_advance_vat_linked_to_bill')?re_ap_advance_vat_linked_to_bill($conn,$companyId,$id):0.0;
$remainingBillInputVat=function_exists('re_ap_bill_remaining_input_vat')?re_ap_bill_remaining_input_vat($conn,$companyId,$id):re_ap_money(max(0,$billEligibleVat-$linkedAdvanceVat));
$eligibleVatDocs=(($bill['posting_status']??'')!=='posted'&&function_exists('re_ap_eligible_advance_vat_docs_for_vendor'))?re_ap_eligible_advance_vat_docs_for_vendor($conn,$companyId,(int)$bill['vendor_id']):[];
$vatBillLinks=[];
if(function_exists('re_ap_advance_vat_table_ready')&&re_ap_advance_vat_table_ready($conn)){
    try{
        $vl=$conn->prepare("SELECT l.*, d.supplier_invoice_number FROM re_vendor_advance_vat_bill_links l JOIN re_vendor_advance_vat_documents d ON d.id=l.advance_vat_document_id AND d.company_id=l.company_id WHERE l.company_id=? AND l.vendor_invoice_id=? ORDER BY l.id DESC");
        $vl->execute([$companyId,$id]);
        $vatBillLinks=$vl->fetchAll(PDO::FETCH_ASSOC)?:[];
    }catch(Throwable $e){}
}
$pageTitle='Vendor Bill '.$bill['invoice_number']; require_once __DIR__ . '/../includes/re_layout_header.php'; ?>
<style>@media print{.no-print,.sidebar,.navbar{display:none!important}.container{max-width:100%!important}}</style>
<?php if($success): ?><div class="alert alert-success no-print"><?=h($success)?></div><?php endif; ?><?php if($error): ?><div class="alert alert-danger no-print"><?=h($error)?></div><?php endif; ?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print"><div class="page-header-label"><i class="bi bi-file-text"></i> Vendor Bill <?=h($bill['invoice_number'])?></div><div><a href="vendor_bills.php" class="btn btn-outline-secondary">Back</a><?php $bal=(float)($bill['balance_due']??max(0,$bill['total_amount']-$bill['paid_amount'])); if($bal>0&&($bill['posting_status']??'')==='posted'): ?><a href="vendor_payment_add.php?vendor_id=<?=$bill['vendor_id']?>&bill_id=<?=$bill['id']?>" class="btn btn-success">Record Payment</a><?php endif; ?><?php if($canUnpost): ?><button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#unpostBillModal">Edit Amount (Unpost)</button><?php endif; ?><?php if($canVoidPosted): ?><button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#amendBillModal">Void / Amend</button><?php endif; ?><?php if($canDeleteBill): ?><button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteBillModal">Delete Bill</button><?php endif; ?><?php if($canVoidRecord): ?><button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#voidRecordModal">Void Bill</button><?php endif; ?><button onclick="window.print()" class="btn btn-outline-dark">Print</button></div></div>
<div class="card card-round mb-4"><div class="card-body"><div class="row"><div class="col-md-6"><h4><?=h($brand['system_name'])?></h4><div class="text-muted">Real Estate Vendor Bill</div></div><div class="col-md-6 text-end"><h3>Bill # <?=h($bill['invoice_number'])?></h3><span class="badge bg-<?= $bill['status']==='paid'?'success':($bill['status']==='draft'?'warning text-dark':'primary')?>"><?=h($bill['status'])?></span> <span class="badge bg-<?=($bill['posting_status']??'not_posted')==='posted'?'success':'secondary'?>"><?=h($bill['posting_status']??'not_posted')?></span></div></div><hr><div class="row"><div class="col-md-4"><strong>Vendor</strong><br><?=h($bill['vendor_name'])?></div><div class="col-md-2"><strong>Bill Date</strong><br><?=h($bill['invoice_date'])?></div><div class="col-md-2"><strong>Due Date</strong><br><?=h($bill['due_date'])?></div><div class="col-md-2"><strong>Place</strong><br><?=h($bill['place_of_supply']??'-')?></div><div class="col-md-2"><strong>VAT</strong><br><?=h($bill['vat_treatment']??'-')?></div></div><?php if(!empty($bill['notes'])): ?><hr><strong>Notes:</strong><br><?=nl2br(h($bill['notes']))?><?php endif; ?></div></div>
<div class="card card-round mb-4"><div class="table-responsive"><table class="table table-sm"><thead class="table-light"><tr><th>Description</th><th>Expense Account</th><th>Cost Center</th><th class="text-end">Qty</th><th class="text-end">Rate</th><th class="text-end">VAT</th><th class="text-end">Total</th></tr></thead><tbody><?php foreach($lines as $ln): ?><tr><td><?=h($ln['service_name'] ?: $ln['line_description'])?></td><td><?=h(trim(($ln['account_code']??'').' '.($ln['account_name']??'')))?></td><td><?=h(($ln['building_name']?:'-').(!empty($ln['unit_number'])?' / '.$ln['unit_number']:'').(!empty($ln['lease_number'])?' / '.$ln['lease_number']:''))?></td><td class="text-end"><?=m($ln['quantity'])?></td><td class="text-end"><?=m($ln['unit_price'])?></td><td class="text-end"><?=m($ln['vat_amount']??0)?></td><td class="text-end"><?=m($ln['line_total']??$ln['total_price'])?></td></tr><?php endforeach; ?></tbody><tfoot><tr><th colspan="6" class="text-end">Subtotal</th><th class="text-end"><?=m($bill['subtotal'])?></th></tr><tr><th colspan="6" class="text-end">VAT</th><th class="text-end"><?=m($bill['tax_amount'])?></th></tr><tr><th colspan="6" class="text-end">Total</th><th class="text-end"><?=m($bill['total_amount'])?></th></tr><tr><th colspan="6" class="text-end">Paid</th><th class="text-end"><?=m($bill['paid_amount']??0)?></th></tr><tr><th colspan="6" class="text-end">Balance</th><th class="text-end"><?=m($bill['balance_due']??0)?></th></tr></tfoot></table></div></div>
<div class="row g-3 no-print">
    <div class="col-md-6">
        <div class="card"><div class="card-header">Payments Made</div>
            <div class="table-responsive"><table class="table table-sm"><tbody>
                <?php foreach($payments as $p): ?>
                    <tr>
                        <td><?=h($p['payment_date'])?></td>
                        <td><?=h($p['reference_number']?:'Payment #'.$p['id'])?><?php if(($p['status']??'')==='void'): ?> <span class="badge bg-secondary">void</span><?php endif; ?></td>
                        <td class="text-end"><?=m($p['amount_allocated'])?></td>
                        <td><?php if($p['journal_id']): ?><a href="journal_entry_view.php?id=<?=$p['journal_id']?>">Journal</a><?php endif; ?></td>
                        <td class="text-end">
                            <?php if(($p['status']??'')!=='void'): ?>
                                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#reversePaymentModal" data-payment-id="<?=(int)$p['id']?>" data-payment-label="<?=h(($p['reference_number']?:'Payment #'.$p['id']).' - '.m($p['amount_allocated']).' AED')?>">Reverse</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if(!$payments): ?><tr><td colspan="5" class="text-muted">No payments allocated.</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>
        <div class="card mt-3">
            <div class="card-header d-flex justify-content-between"><span>Vendor Advances Applied</span><span class="small text-muted">Available: <?=m($vendorAdvanceBal)?> AED</span></div>
            <div class="table-responsive"><table class="table table-sm mb-0"><tbody>
                <?php foreach($advanceApps as $apRow): ?>
                    <tr>
                        <td><?=h($apRow['created_at']??'')?></td>
                        <td>PAY-<?=(int)$apRow['vendor_payment_id']?></td>
                        <td class="text-end"><?=m($apRow['amount'])?></td>
                        <td><span class="badge bg-<?=($apRow['status']??'')==='posted'?'success':'secondary'?>"><?=h($apRow['status'])?></span></td>
                        <td>
                            <?php if(($apRow['status']??'')==='posted'): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Unapply this advance?');">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="unapply_advance">
                                    <input type="hidden" name="application_id" value="<?=(int)$apRow['id']?>">
                                    <input type="hidden" name="reason" value="Unapply from bill view">
                                    <button class="btn btn-sm btn-outline-warning">Unapply</button>
                                </form>
                            <?php endif; ?>
                            <?php if(!empty($apRow['journal_id'])): ?><a class="btn btn-sm btn-outline-primary" href="journal_entry_view.php?id=<?=(int)$apRow['journal_id']?>">Journal</a><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if(!$advanceApps): ?><tr><td class="text-muted">No advances applied to this bill.</td></tr><?php endif; ?>
            </tbody></table></div>
            <?php if($balDue>0.005 && ($bill['posting_status']??'')==='posted' && $vendorAdvanceBal>0.005): ?>
                <div class="card-body border-top">
                    <?php if(!$advanceSources): ?>
                        <div class="alert alert-warning mb-2 py-2">
                            Vendor advance balance is <?=m($vendorAdvanceBal)?> AED, but no payment currently has remaining advance
                            (fully applied and/or reclassified by Advance VAT documents).
                        </div>
                    <?php else: ?>
                    <form method="post" class="row g-2 align-items-end" autocomplete="off">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="apply_advance">
                        <div class="col-md-4">
                            <label class="form-label">Apply Advance (AED)</label>
                            <input type="number" step="0.01" min="0.01" max="<?=h((string)$applyMax)?>" name="apply_amount" class="form-control" value="<?=h((string)$applyMax)?>" required autocomplete="off">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Source payment</label>
                            <select name="source_payment_ids" class="form-select" autocomplete="off">
                                <option value="">FIFO (all remaining payments)</option>
                                <?php foreach($advanceSources as $srcRow): ?>
                                    <option value="<?=(int)$srcRow['id']?>">PAY-<?=(int)$srcRow['id']?> · <?=h($srcRow['payment_date'])?> · rem <?=m($srcRow['remaining'])?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-primary w-100">Apply Advance</button>
                        </div>
                    </form>
                    <div class="form-text">Leave source on FIFO unless you need a specific payment. Remaining after Advance VAT is what can be applied to bills.</div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="card mt-3">
            <div class="card-header">Advance VAT Documents (final bill)</div>
            <div class="card-body">
                <div class="row small mb-2">
                    <div class="col-md-4">Bill VAT: <strong><?=m($billEligibleVat)?></strong></div>
                    <div class="col-md-4">Previously recognized: <strong><?=m($linkedAdvanceVat)?></strong></div>
                    <div class="col-md-4">Remaining Input VAT to post: <strong><?=m($remainingBillInputVat)?></strong></div>
                </div>
                <div class="form-text mb-2">Link posted Advance VAT Documents before posting this bill so Input VAT is not recovered twice. Applying the advance balance remains a separate action.</div>
                <div class="table-responsive mb-2">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Document</th><th class="text-end">VAT linked</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach($vatBillLinks as $vlRow): ?>
                                <tr>
                                    <td><a href="vendor_advance_vat_document_view.php?id=<?=(int)$vlRow['advance_vat_document_id']?>"><?=h($vlRow['supplier_invoice_number'])?></a></td>
                                    <td class="text-end"><?=m($vlRow['vat_amount_linked'])?></td>
                                    <td><span class="badge bg-<?=($vlRow['status']??'')==='posted'?'success':'secondary'?>"><?=h($vlRow['status'])?></span></td>
                                    <td>
                                        <?php if(($vlRow['status']??'')==='posted'&&($bill['posting_status']??'')!=='posted'): ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Unlink this Advance VAT amount?');">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="action" value="unlink_advance_vat">
                                                <input type="hidden" name="link_id" value="<?=(int)$vlRow['id']?>">
                                                <button class="btn btn-sm btn-outline-warning">Unlink</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if(!$vatBillLinks): ?><tr><td colspan="4" class="text-muted">No Advance VAT linked to this bill.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if(($bill['posting_status']??'')!=='posted' && $eligibleVatDocs): ?>
                    <form method="post" class="border-top pt-3">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="link_advance_vat">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead><tr><th>Eligible document</th><th class="text-end">Available VAT</th><th class="text-end">Link VAT</th><th class="text-end">Link taxable</th></tr></thead>
                                <tbody>
                                    <?php foreach($eligibleVatDocs as $ed): ?>
                                        <tr>
                                            <td>
                                                <a href="vendor_advance_vat_document_view.php?id=<?=(int)$ed['id']?>"><?=h($ed['supplier_invoice_number'])?></a>
                                                <div class="small text-muted">PAY-<?=(int)$ed['vendor_payment_id']?> · <?=h($ed['supplier_invoice_date'])?></div>
                                            </td>
                                            <td class="text-end"><?=m($ed['vat_remaining'])?></td>
                                            <td class="text-end" style="max-width:120px"><input type="number" step="0.01" min="0" max="<?=h((string)$ed['vat_remaining'])?>" name="vat_links[<?=(int)$ed['id']?>]" class="form-control form-control-sm text-end" value=""></td>
                                            <td class="text-end" style="max-width:120px"><input type="number" step="0.01" min="0" name="vat_taxable[<?=(int)$ed['id']?>]" class="form-control form-control-sm text-end" value=""></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <button class="btn btn-primary btn-sm">Confirm VAT links</button>
                    </form>
                <?php elseif(($bill['posting_status']??'')!=='posted'): ?>
                    <div class="text-muted">No eligible posted Advance VAT Documents with remaining VAT for this vendor.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6"><div class="card"><div class="card-header">Attachments</div><div class="card-body"><?php foreach($attachments as $a): ?><a href="../../../<?=h($a['file_path'])?>" target="_blank" class="btn btn-sm btn-outline-secondary mb-1"><?=h($a['file_name'])?></a> <?php endforeach; ?><?php if(!$attachments): ?><span class="text-muted">No attachments.</span><?php endif; ?></div></div></div>
</div>
<?php if($canVoidRecord): ?>
<div class="modal fade no-print" id="voidRecordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Void Bill</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <div class="alert alert-warning">
                        Bill <strong><?=h($bill['invoice_number'])?></strong> already has general ledger entries from an earlier posting, so it cannot be deleted. Voiding closes it off and takes it out of the vendor totals while the journal trail stays in place.
                    </div>
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="void_bill">
                    <label class="form-label">Reason</label>
                    <textarea name="reason" class="form-control" rows="2" required placeholder="Example: Entered by mistake, amount belongs on another bill"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-danger" onclick="return confirm('Void this bill?');">Void Bill</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php if($canUnpost): ?>
<div class="modal fade no-print" id="unpostBillModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Amount - Unpost This Bill</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <div class="alert alert-warning">
                        This reverses the posted journal and puts bill <strong><?=h($bill['invoice_number'])?></strong> back to draft with the same bill number, so you can correct the amount and post it again. The original entry and its reversal stay in the general ledger as the audit trail.
                    </div>
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="unpost_bill">
                    <label class="form-label">Reason</label>
                    <textarea name="reason" class="form-control" rows="2" required placeholder="Example: Amount entered as 315.00, actual bill is 318.15"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-warning" onclick="return confirm('Unpost this bill so the amount can be edited?');">Unpost &amp; Edit</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php if($canDeleteBill): ?>
<div class="modal fade no-print" id="deleteBillModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Bill</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <div class="alert alert-danger">
                        Bill <strong><?=h($bill['invoice_number'])?></strong> (<?=m($bill['total_amount'])?> AED) and its lines and attachments will be permanently removed. This bill has nothing in the general ledger, so nothing is reversed. This cannot be undone.
                    </div>
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="delete_bill">
                    <label class="form-label">Reason</label>
                    <textarea name="reason" class="form-control" rows="2" required placeholder="Example: Entered twice by mistake"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-danger" onclick="return confirm('Permanently delete this bill?');">Delete Bill</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php if($livePayments): ?>
<div class="modal fade no-print" id="reversePaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reverse Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <div class="alert alert-warning">
                        Reversing <strong id="reversePaymentLabel"></strong> reverses its journal and removes it from this bill, so the bill goes back to unpaid and can then be unposted, corrected or voided.
                    </div>
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="reverse_payment">
                    <input type="hidden" name="payment_id" id="reversePaymentId" value="">
                    <label class="form-label">Reason</label>
                    <textarea name="reason" class="form-control" rows="2" required placeholder="Example: Wrong amount paid against this bill"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-danger" onclick="return confirm('Reverse this payment and unallocate it from the bill?');">Reverse Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.getElementById('reversePaymentModal')?.addEventListener('show.bs.modal', function(ev){
    var btn = ev.relatedTarget; if(!btn) return;
    document.getElementById('reversePaymentId').value = btn.getAttribute('data-payment-id') || '';
    document.getElementById('reversePaymentLabel').textContent = btn.getAttribute('data-payment-label') || '';
});
</script>
<?php endif; ?>
<?php if($canVoidPosted): ?>
<div class="modal fade no-print" id="amendBillModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Void / Amend Posted Bill</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    Posted bills cannot be edited directly. To correct this bill, reverse the posted journal and either leave the bill voided or create a new draft amendment copy to edit.
                </div>
                <form method="post" id="voidBillForm" class="mb-3">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="void_bill">
                    <label class="form-label">Reason</label>
                    <textarea name="reason" class="form-control" rows="2" required placeholder="Example: Wrong amount entered, replacing with corrected bill"></textarea>
                </form>
                <form method="post" id="amendBillForm">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="amend_bill">
                    <label class="form-label">Reason</label>
                    <textarea name="reason" class="form-control" rows="2" required placeholder="Example: Need to correct line items and repost"></textarea>
                </form>
            </div>
            <div class="modal-footer">
                <button type="submit" form="voidBillForm" class="btn btn-outline-danger" onclick="return confirm('Void this bill and reverse its journal?');">Void / Reverse Only</button>
                <button type="submit" form="amendBillForm" class="btn btn-warning" onclick="return confirm('Void this bill, reverse its journal, and create a draft copy to edit?');">Void & Create Amendment Draft</button>
            </div>
        </div>
    </div>
</div>
<?php elseif((($bill['posting_status']??'')==='posted'||!empty($bill['journal_id']))&&!in_array($bill['status'],['void','cancelled'],true)&&($allocatedTotal>0.005||$advanceApplied>0.005)): ?>
<div class="alert alert-info no-print mt-3">To correct this bill, first press <strong>Reverse</strong> next to its payment in <em>Payments Made</em> (and unapply any advances). The bill then goes back to unpaid and the <strong>Edit Amount (Unpost)</strong>, <strong>Void / Amend</strong> and <strong>Delete</strong> options appear.</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
