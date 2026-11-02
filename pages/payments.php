<?php
$id=(int)($_GET['id']??$_GET['view']??0);
$methods=['Cash','Bank Transfer','JazzCash','Easypaisa','Cheque','Other'];
$formError=null;
if($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        require_post_csrf(); db()->beginTransaction(); project_lock();
        $action=(string)($_POST['action']??'create');
        if($action==='create') {
            $contact=(int)($_POST['contact_id']??0)?:null;
            $target=(string)($_POST['target']??'');
            if(!$contact && !str_starts_with($target,'expense:'))throw new InvalidArgumentException('Choose a recipient for an advance or contract payment.');
            $id=create_payment($contact,valid_date($_POST['payment_date']??''),decimal($_POST['amount']??'',2,true),(string)($_POST['method']??''),trim((string)($_POST['notes']??'')));
            if($target!=='') { [$type,$targetId]=array_pad(explode(':',$target,2),2,'0'); $allocated=trim((string)($_POST['allocated']??'')); if($allocated==='')$allocated=(string)$_POST['amount']; allocate_payment($id,$type,(int)$targetId,$allocated); }
            upload_attachment('attachment',null,$id);
        } else {
            $payment=one('SELECT * FROM payments WHERE id=? AND project_id=? FOR UPDATE',[$id,project_id()]);
            if(!$payment || $payment['status']!=='active')throw new InvalidArgumentException('Active payment not found.');
            if($action==='allocate') { [$type,$targetId]=array_pad(explode(':',(string)($_POST['target']??''),2),2,'0'); allocate_payment($id,$type,(int)$targetId,(string)($_POST['allocated']??'')); }
            elseif($action==='release') run('DELETE FROM payment_allocations WHERE id=? AND payment_id=?',[(int)($_POST['allocation_id']??0),$id]);
            elseif($action==='cancel') { run('DELETE FROM payment_allocations WHERE payment_id=?',[$id]); run("UPDATE payments SET status='cancelled' WHERE id=?",[$id]); }
            elseif($action==='upload') upload_attachment('attachment',null,$id);
            else throw new InvalidArgumentException('Unknown payment operation.');
        }
        db()->commit(); flash('Payment saved.'); redirect('index.php?page=payments&id='.$id);
    } catch(Throwable $ex) { if(db()->inTransaction())db()->rollBack(); cleanup_failed_uploads(); error_log((string)$ex); $formError=$ex instanceof PDOException?'Unable to save payment. No changes saved.':$ex->getMessage(); if(($_POST['action']??'create')==='create')$id=0; }
}
if($formError)echo '<div class="alert alert-danger">'.e($formError).'</div>';
$contacts=rows('SELECT id,name FROM contacts ORDER BY name');
function payment_targets(?int $contact=null,bool $restrict=false): array {
    $p=[project_id()]; $where=''; if($restrict) { $where=' AND e.contact_id <=> ?'; $p[]=$contact; }
    $targets=rows("SELECT e.id, e.contact_id, e.expense_date date, e.total, COALESCE(c.name,'No supplier') name, e.total-COALESCE((SELECT SUM(a.amount) FROM payment_allocations a JOIN payments p ON p.id=a.payment_id AND p.status='active' WHERE a.expense_id=e.id),0) balance FROM expenses e LEFT JOIN contacts c ON c.id=e.contact_id WHERE e.project_id=? AND e.status='active' $where HAVING balance>0 ORDER BY e.expense_date DESC",$p);
    foreach($targets as &$t) {$t['value']='expense:'.$t['id'];$t['label']='Purchase #'.$t['id'].' · '.$t['name'].' · '.money($t['balance']).' due';} unset($t);
    $p=[project_id()];$where='';if($restrict){$where=' AND e.contact_id <=> ?';$p[]=$contact;}
    $contracts=rows("SELECT e.id,e.contact_id,e.title name,e.agreed_amount-COALESCE((SELECT SUM(a.amount) FROM payment_allocations a JOIN payments p ON p.id=a.payment_id AND p.status='active' WHERE a.contract_id=e.id),0) balance FROM contracts e WHERE e.project_id=? AND e.status<>'Cancelled' $where HAVING balance>0 ORDER BY e.contract_date DESC",$p);
    foreach($contracts as &$t){$t['value']='contract:'.$t['id'];$t['label']='Theka #'.$t['id'].' · '.$t['name'].' · '.money($t['balance']).' balance';}unset($t);
    return array_merge($targets,$contracts);
}
if($id) {
    $payment=one('SELECT p.*,c.name contact_name FROM payments p LEFT JOIN contacts c ON c.id=p.contact_id WHERE p.id=? AND p.project_id=?',[$id,project_id()]);
    if(!$payment)throw new InvalidArgumentException('Payment not found in this project.');
    $allocations=rows('SELECT a.*,e.expense_date,c.title FROM payment_allocations a LEFT JOIN expenses e ON e.id=a.expense_id LEFT JOIN contracts c ON c.id=a.contract_id WHERE a.payment_id=? ORDER BY a.id',[$id]);
    $allocated='0.00';foreach($allocations as $a)$allocated=add($allocated,$a['amount']);
    ?>
    <div class="d-flex flex-wrap gap-2 mb-3"><a class="btn btn-outline-secondary" href="index.php?page=payments">All payments</a><a class="btn btn-primary" href="index.php?page=payments&new=1">New payment / advance</a></div>
    <div class="card mb-3"><div class="card-body"><h2 class="h5">Payment #<?=$id?> · <?=e($payment['status'])?></h2><p><?=e($payment['payment_date'])?> · <?=e($payment['contact_name']??'No supplier')?> · <?=e($payment['method'])?></p><p><strong><?=money($payment['amount'])?></strong> · Allocated <?=money($allocated)?> · Unallocated <?=money($payment['status']==='active'?sub($payment['amount'],$allocated):0)?></p><p class="text-break"><?=nl2br(e($payment['notes']))?></p><?=attachments_html(null,$id)?></div></div>
    <h2 class="h5">Allocations</h2><div class="table-responsive"><table class="table table-bordered bg-white"><thead><tr><th>Purchase / contract</th><th>Amount</th><th>Action</th></tr></thead><tbody><?php foreach($allocations as $a): ?><tr><td><a href="index.php?page=<?=$a['expense_id']?'expenses':'contracts'?>&id=<?=(int)($a['expense_id']??$a['contract_id'])?>"><?=e($a['expense_id']?'Purchase #'.$a['expense_id']:$a['title'])?></a></td><td><?=money($a['amount'])?></td><td><form method="post"><?=csrf()?><input type="hidden" name="allocation_id" value="<?=(int)$a['id']?>"><button class="btn btn-sm btn-outline-danger" name="action" value="release" onclick="return confirm('Return this allocation to the advance balance?')">Release</button></form></td></tr><?php endforeach; ?><?php if(!$allocations): ?><tr><td colspan="3">No allocations. This payment is an advance until applied.</td></tr><?php endif; ?></tbody></table></div>
    <?php if($payment['status']==='active'): ?>
    <?php if(cmp(sub($payment['amount'],$allocated),'0')>0): ?><form method="post" class="card card-body mb-3"><?=csrf()?><h2 class="h5">Apply existing advance</h2><div class="row g-2"><div class="col-md-7"><label class="form-label" for="target">Purchase / contract</label><select class="form-select" id="target" name="target" required><option value="">Select</option><?php foreach(payment_targets($payment['contact_id']?(int)$payment['contact_id']:null,true) as $t): ?><option value="<?=e($t['value'])?>"><?=e($t['label'])?></option><?php endforeach; ?></select></div><div class="col-md-3"><label for="allocated" class="form-label">Amount to allocate</label><input id="allocated" class="form-control" name="allocated" type="number" step="0.01" min="0.01" max="<?=e(sub($payment['amount'],$allocated))?>" required></div><div class="col-md-2 align-self-end"><button class="btn btn-primary w-100" name="action" value="allocate">Apply</button></div></div></form><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap mb-3"><?=csrf()?><label for="receipt" class="form-label align-self-center">Add receipt (max 5 MB)</label><input id="receipt" class="form-control w-auto" type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf" required><button class="btn btn-outline-primary" name="action" value="upload">Upload</button></form>
    <form method="post"><?=csrf()?><button name="action" value="cancel" class="btn btn-outline-danger" onclick="return confirm('Cancel this payment and release all allocations?')">Cancel payment</button></form>
    <?php endif; return;
}
$prefill=(int)($_GET['expense_id']??0)?'expense:'.(int)$_GET['expense_id']:((int)($_GET['contract_id']??0)?'contract:'.(int)$_GET['contract_id']:'');
$targets=payment_targets();$chosenContact=$_POST['contact_id']??'';foreach($targets as $t)if($t['value']===$prefill)$chosenContact=$t['contact_id'];
?>
<?php if(isset($_GET['new']) || $prefill!=='' || $formError): ?>
<form method="post" enctype="multipart/form-data" class="card card-body mb-4"><?=csrf()?><input type="hidden" name="action" value="create"><h2 class="h5">Record payment or advance</h2><div class="row g-3">
<div class="col-md-4"><label for="contact" class="form-label">Recipient</label><select class="form-select" id="contact" name="contact_id"><option value="">No supplier (purchase only)</option><?=options($contacts,$chosenContact)?></select></div>
<div class="col-md-4"><label for="date" class="form-label">Date</label><input id="date" class="form-control" type="date" name="payment_date" value="<?=e($_POST['payment_date']??date('Y-m-d'))?>" required></div>
<div class="col-md-4"><label for="amount" class="form-label">Payment amount (PKR)</label><input id="amount" class="form-control" type="number" inputmode="decimal" name="amount" min="0.01" step="0.01" value="<?=e($_POST['amount']??'')?>" required></div>
<div class="col-md-4"><label for="method" class="form-label">Payment method</label><select id="method" name="method" class="form-select"><?php foreach($methods as $m): ?><option <?=($_POST['method']??'')===$m?'selected':''?>><?=e($m)?></option><?php endforeach; ?></select></div>
<div class="col-md-5"><label for="target" class="form-label">Allocate to (optional)</label><select id="target" class="form-select" name="target"><option value="">Keep as unallocated advance</option><?php foreach($targets as $t): ?><option data-contact="<?=e($t['contact_id'])?>" value="<?=e($t['value'])?>" <?=($_POST['target']??$prefill)===$t['value']?'selected':''?>><?=e($t['label'])?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label for="allocation-amount" class="form-label">Allocation amount</label><input id="allocation-amount" name="allocated" type="number" min="0.01" step="0.01" inputmode="decimal" class="form-control" placeholder="Full payment if blank" value="<?=e($_POST['allocated']??'')?>"></div>
<div class="col-md-6"><label for="notes" class="form-label">Notes</label><textarea class="form-control" id="notes" name="notes"><?=e($_POST['notes']??'')?></textarea></div>
<div class="col-md-6"><label for="attachment" class="form-label">Receipt (JPG, PNG, WebP, PDF; max 5 MB)</label><input id="attachment" class="form-control" type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf"></div>
</div><p class="small text-muted mt-2">Choose a matching recipient. Existing advances can be allocated from their payment details without recording a second payment.</p><div><button class="btn btn-primary">Save payment</button> <a href="index.php?page=payments" class="btn btn-outline-secondary">Back</a></div></form>
<script>document.getElementById('target').addEventListener('change',function(){if(this.value)document.getElementById('contact').value=this.selectedOptions[0].dataset.contact||'';});</script>
<?php else: ?><a class="btn btn-primary mb-3" href="index.php?page=payments&new=1">New payment / advance</a><?php endif; ?>
<?php $payments=rows('SELECT p.*,c.name contact_name,COALESCE((SELECT SUM(amount) FROM payment_allocations a WHERE a.payment_id=p.id),0) allocated FROM payments p LEFT JOIN contacts c ON c.id=p.contact_id WHERE p.project_id=? ORDER BY p.payment_date DESC,p.id DESC',[project_id()]); ?>
<div class="table-responsive"><table class="table table-striped bg-white"><thead><tr><th>Date</th><th>Recipient</th><th>Method</th><th>Amount</th><th>Unallocated</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($payments as $p): ?><tr><td><?=e($p['payment_date'])?></td><td><?=e($p['contact_name']??'No supplier')?></td><td><?=e($p['method'])?></td><td><?=money($p['amount'])?></td><td><?=money($p['status']==='active'?sub($p['amount'],$p['allocated']):0)?></td><td><?=e($p['status'])?></td><td><a href="index.php?page=payments&id=<?=(int)$p['id']?>">Details / allocate</a></td></tr><?php endforeach; ?><?php if(!$payments): ?><tr><td colspan="7">No payments recorded for this project.</td></tr><?php endif; ?></tbody></table></div>
