<?php
$pid = project_id(); $error = null; $action = $_POST['action'] ?? '';
$stagesEnabled = contract_work_stages_enabled();
$workStages = rows('SELECT * FROM work_types ORDER BY name');
$costStage = static function(?int $existing = null) use ($stagesEnabled): ?int {
    $stage = (int)($_POST['cost_work_type_id'] ?? $existing ?? 0) ?: null;
    if (!$stagesEnabled) {
        if ($stage) throw new RuntimeException('Work stages require the database upgrade before they can be saved.');
        return null;
    }
    if ($stage && !one('SELECT id FROM work_types WHERE id=? AND (active=1 OR id=?)',[$stage,$existing??0])) throw new RuntimeException('Choose an active work stage or retain the existing stage.');
    return $stage;
};
$stageOptions = static function(?int $selected = null) use ($workStages): array {
    return array_values(array_filter($workStages,static fn($stage)=>(bool)$stage['active'] || (int)$stage['id']===$selected));
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    try {
        db()->beginTransaction(); project_lock();
        $id = (int)($_POST['id'] ?? 0);
        $contract = $id ? one('SELECT * FROM contracts WHERE id=? AND project_id=? FOR UPDATE',[$id,$pid]) : null;
        if ($id && !$contract) throw new RuntimeException('Contract not found.');
        if ($action === 'save') {
            $title = trim($_POST['title'] ?? '');
            if ($title==='' || mb_strlen($title)>180) throw new RuntimeException('Contract title is required (maximum 180 characters).');
            $contact = (int)($_POST['contact_id'] ?? 0);
            // The agreement spans stages. Retain the legacy field only for schema compatibility.
            $work = $contract ? (int)$contract['work_type_id'] : (int)(one("SELECT id FROM work_types WHERE active=1 ORDER BY (name='Other') DESC,id LIMIT 1")['id']??0);
            if (!one("SELECT id FROM contacts WHERE id=? AND (type <> 'Supplier' OR id=?)",[$contact,$contract['contact_id']??0])) throw new RuntimeException('Select a contractor contact.');
            if (!one('SELECT id FROM work_types WHERE id=? AND (active=1 OR id=?)',[$work,$contract['work_type_id']??0])) throw new RuntimeException('Select an active work type.');
            $date = valid_date($_POST['contract_date'] ?? '');
            $type = $_POST['type'] ?? 'fixed';
            if (!in_array($type,['fixed','quantity'],true)) throw new RuntimeException('Invalid contract type.');
            $quantity = $unit = $unitName = $rate = null;
            if ($type==='quantity') {
                $quantity=decimal($_POST['quantity']??'',4,true); $rate=decimal($_POST['rate']??'');
                $unit=(int)($_POST['unit_id']??0); $unitRow=one('SELECT * FROM units WHERE id=?',[$unit]);
                if (!$unitRow || (!$unitRow['active'] && (!$contract || $contract['unit_id']!=$unit))) throw new RuntimeException('Select an active quantity unit.');
                $unitName = $contract && $contract['unit_id']==$unit ? $contract['unit_name'] : $unitRow['name'];
                $agreed=line_total($quantity,$rate,'1','1');
            } else $agreed=decimal($_POST['agreed_amount']??'');
            decimal($agreed);
            $status=$_POST['status']??'Active';
            if(!in_array($status,['Active','Completed','Cancelled'],true)) throw new RuntimeException('Invalid contract status.');
            if($id) {
                $paid=one('SELECT COALESCE(SUM(amount),0) amount FROM payment_allocations WHERE contract_id=?',[$id])['amount'];
                $recognized=one("SELECT COALESCE(SUM(amount),0) amount FROM contract_work_costs WHERE contract_id=? AND status='active'",[$id])['amount'];
                if(cmp($agreed,$paid)<0 || cmp($agreed,$recognized)<0) throw new RuntimeException('Agreed amount cannot be lower than payments or recognized work cost.');
                if(cmp($paid,'0')>0 && $contact != $contract['contact_id']) throw new RuntimeException('Remove allocations before changing contractor.');
                run('UPDATE contracts SET contact_id=?,work_type_id=?,title=?,description=?,contract_date=?,type=?,quantity=?,unit_id=?,unit_name=?,rate=?,agreed_amount=?,status=?,notes=? WHERE id=?',[$contact,$work,$title,trim($_POST['description']??''),$date,$type,$quantity,$unit,$unitName,$rate,$agreed,$status,trim($_POST['notes']??''),$id]);
            } else {
                run('INSERT INTO contracts(project_id,contact_id,work_type_id,title,description,contract_date,type,quantity,unit_id,unit_name,rate,agreed_amount,status,notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$pid,$contact,$work,$title,trim($_POST['description']??''),$date,$type,$quantity,$unit,$unitName,$rate,$agreed,$status,trim($_POST['notes']??'')]);
                $id=(int)db()->lastInsertId();
            }
        } elseif ($action === 'recognize') {
            if(!$contract || $contract['status']==='Cancelled') throw new RuntimeException('Select an active or completed contract.');
            $recognized=one("SELECT COALESCE(SUM(amount),0) amount FROM contract_work_costs WHERE contract_id=? AND status='active'",[$id])['amount'];
            $remaining=sub($contract['agreed_amount'],$recognized);
            $amount=isset($_POST['full_completion']) ? $remaining : decimal($_POST['amount']??'',2,true);
            if(cmp($amount,'0')<=0 || cmp($amount,$remaining)>0) throw new RuntimeException('Work cost must be positive and within remaining commitment. Adjust agreed value first if necessary.');
            $stage=$costStage();
            if ($stagesEnabled) run('INSERT INTO contract_work_costs(contract_id,cost_date,amount,notes,work_type_id) VALUES(?,?,?,?,?)',[$id,valid_date($_POST['cost_date']??''),$amount,trim($_POST['cost_notes']??''),$stage]);
            else run('INSERT INTO contract_work_costs(contract_id,cost_date,amount,notes) VALUES(?,?,?,?)',[$id,valid_date($_POST['cost_date']??''),$amount,trim($_POST['cost_notes']??'')]);
            if(isset($_POST['full_completion'])) run("UPDATE contracts SET status='Completed' WHERE id=?",[$id]);
        } elseif ($action==='edit_cost') {
            if (!$contract) throw new RuntimeException('Contract not found.');
            $cost=one('SELECT * FROM contract_work_costs WHERE id=? AND contract_id=? FOR UPDATE',[(int)($_POST['cost_id']??0),$id]);
            if (!$cost) throw new RuntimeException('Approved work entry not found in this contract.');
            $amount=decimal($_POST['amount']??'',2,true);
            $costStatus=(string)($_POST['cost_status']??'active');
            if (!in_array($costStatus,['active','cancelled'],true)) throw new RuntimeException('Select a valid work entry status.');
            $otherCosts=one("SELECT COALESCE(SUM(amount),0) amount FROM contract_work_costs WHERE contract_id=? AND status='active' AND id<>?",[$id,$cost['id']])['amount'];
            if ($costStatus==='active' && cmp(add($otherCosts,$amount),$contract['agreed_amount'])>0) throw new RuntimeException('Approved work would exceed the agreed contract amount. Edit the agreement first.');
            $stage=$costStage(isset($cost['work_type_id'])?(int)$cost['work_type_id']:null);
            if ($stagesEnabled) run('UPDATE contract_work_costs SET cost_date=?,amount=?,notes=?,status=?,work_type_id=? WHERE id=? AND contract_id=?',[valid_date($_POST['cost_date']??''),$amount,trim($_POST['cost_notes']??''),$costStatus,$stage,$cost['id'],$id]);
            else run('UPDATE contract_work_costs SET cost_date=?,amount=?,notes=?,status=? WHERE id=? AND contract_id=?',[valid_date($_POST['cost_date']??''),$amount,trim($_POST['cost_notes']??''),$costStatus,$cost['id'],$id]);
        } elseif ($action==='cancel_cost') {
            if(!$contract) throw new RuntimeException('Contract not found.');
            $cost=one('SELECT * FROM contract_work_costs WHERE id=? AND contract_id=? FOR UPDATE',[(int)($_POST['cost_id']??0),$id]);
            if(!$cost) throw new RuntimeException('Work cost not found.');
            run("UPDATE contract_work_costs SET status='cancelled' WHERE id=?",[$cost['id']]);
        } else throw new RuntimeException('Invalid action.');
        db()->commit(); flash('Contract updated.'); redirect('?page=contracts&view='.$id);
    } catch(Throwable $ex) { if(db()->inTransaction())db()->rollBack(); if($ex instanceof PDOException) error_log((string)$ex); $error=$ex instanceof PDOException ? 'Database operation failed. No changes were saved.' : $ex->getMessage(); }
}
$viewId=(int)($_GET['view']??$_GET['id']??($error && in_array($action,['edit_cost','recognize','cancel_cost'],true)?($_POST['id']??0):0)); $editId=(int)($_GET['edit']??0);
$editing=$editId ? one('SELECT * FROM contracts WHERE id=? AND project_id=?',[$editId,$pid]):null;
if($editId && !$editing)throw new RuntimeException('Contract not found.');
$showForm=isset($_GET['add']) || $editing || ($error && $action==='save');
?>
<div class="d-flex justify-content-between align-items-center gap-2 mb-3"><a class="btn btn-outline-secondary" href="?page=contracts">All contracts</a><a class="btn btn-primary" href="?page=contracts&add=1">Add contract</a></div>
<?php if($error): ?><div class="alert alert-danger" role="alert"><?=e($error)?></div><?php endif; ?>
<p class="text-muted">One contract can cover the whole construction. Record approved costs against their stage or milestone, such as Foundation or Grey Structure. Payments stay against the overall contract.</p>
<?php if($viewId):
$c=one('SELECT ct.*,c.name contact_name FROM contracts ct JOIN contacts c ON c.id=ct.contact_id WHERE ct.id=? AND ct.project_id=?',[$viewId,$pid]);
if(!$c)throw new RuntimeException('Contract not found.');
$costs=$stagesEnabled ? rows('SELECT w.*,t.name stage_name FROM contract_work_costs w LEFT JOIN work_types t ON t.id=w.work_type_id WHERE w.contract_id=? ORDER BY w.cost_date DESC,w.id DESC',[$viewId]) : rows('SELECT *,NULL stage_name FROM contract_work_costs WHERE contract_id=? ORDER BY cost_date DESC,id DESC',[$viewId]);
$stageTotals=[]; foreach($costs as $cost) if($cost['status']==='active') { $stageName=$cost['stage_name']??'Unassigned stage'; $stageTotals[$stageName]=add($stageTotals[$stageName]??'0',$cost['amount']); }
$recognized=one("SELECT COALESCE(SUM(amount),0) amount FROM contract_work_costs WHERE contract_id=? AND status='active'",[$viewId])['amount'];
$paid=one('SELECT COALESCE(SUM(amount),0) amount FROM payment_allocations WHERE contract_id=?',[$viewId])['amount'];
?>
<div class="ct-detail"><section class="card mb-3"><div class="card-body"><span class="ct-step">01 · AGREEMENT</span><h2 class="h5"><?=e($c['title'])?> <span class="badge text-bg-secondary"><?=e($c['status'])?></span></h2><p><?=e($c['contact_name'])?> · <?=e($c['contract_date'])?></p><p><?=nl2br(e($c['description']))?></p><?php if($c['type']==='quantity'): ?><p><?=e($c['quantity'].' '.$c['unit_name'])?> × <?=money($c['rate'])?> per <?=e($c['unit_name'])?></p><?php endif; ?>
<div class="ct-metrics"><?php foreach(['Agreed amount'=>$c['agreed_amount'],'Approved work cost'=>$recognized,'Paid to contractor'=>$paid] as $label=>$value): ?><div><span><?=e($label)?></span><strong><?=money($value)?></strong></div><?php endforeach; ?></div>
<details class="ct-balances"><summary>Balances and remaining work</summary><dl><?php foreach(['Work not yet approved'=>sub($c['agreed_amount'],$recognized),'Agreed amount still to pay'=>sub($c['agreed_amount'],$paid),'Approved work still to pay'=>cmp($recognized,$paid)>0?sub($recognized,$paid):'0','Prepaid against future work'=>cmp($paid,$recognized)>0?sub($paid,$recognized):'0'] as $label=>$value): ?><div><dt><?=e($label)?></dt><dd><?=money($value)?></dd></div><?php endforeach; ?></dl></details>
<p><?=nl2br(e($c['notes']))?></p><div class="d-flex gap-2 flex-wrap"><a class="btn btn-outline-primary" href="?page=contracts&edit=<?=$viewId?>">Edit agreement / status</a><?php if($c['status']!=='Cancelled'): ?><a class="btn btn-primary" href="?page=payments&contract_id=<?=$viewId?>">Record contract payment</a><?php endif; ?></div>
<?php if($c['status']==='Cancelled'): ?><p class="small text-muted mt-3 mb-0">This contract is cancelled. Edit its status to reopen it. Existing work entries can still be corrected below.</p><?php endif; ?>
</div></section>
<section class="card mb-3"><div class="card-body"><span class="ct-step">02 · APPROVED WORK</span><h2 class="h5">Work costs by stage</h2><?php if(!$stagesEnabled): ?><p class="alert alert-info small">Work-stage assignment is available after the database upgrade. Existing costs and payments remain available.</p><?php endif ?><p class="small text-muted">Add only work already completed or approved. These entries count toward total expenses. Editing or cancelling work does not change payments; any overpayment remains a contract prepayment.</p>
<?php if($c['status']!=='Cancelled' && cmp($c['agreed_amount'],$recognized)>0): ?><details class="ct-add-work" <?=($error && $action==='recognize')?'open':''?>><summary>Add approved work</summary><form method="post"><?=csrf()?><input type="hidden" name="action" value="recognize"><input type="hidden" name="id" value="<?=$viewId?>"><div class="row g-2"><div class="col-6 col-md-3"><label class="form-label">Work date<input class="form-control" required type="date" name="cost_date" value="<?=e($_POST['cost_date']??date('Y-m-d'))?>"></label></div><div class="col-6 col-md-3"><label class="form-label">Approved cost Rs.<input class="form-control" type="number" min="0.01" step="0.01" name="amount" value="<?=e($_POST['amount']??'')?>"></label></div><?php if($stagesEnabled): ?><div class="col-12 col-md-6"><label class="form-label d-block">Work stage / milestone<select class="form-select" name="cost_work_type_id"><option value="">Unassigned stage</option><?=options($stageOptions(),$_POST['cost_work_type_id']??null)?></select></label></div><?php endif ?><div class="col-12 col-md-6"><label class="form-label">Notes<input class="form-control" name="cost_notes" value="<?=e($_POST['cost_notes']??'')?>"></label></div></div><label class="form-check my-2"><input class="form-check-input" type="checkbox" name="full_completion" value="1"> Recognize full remaining commitment and mark completed</label><button class="btn btn-primary">Save work cost</button></form></details><?php endif; ?>
<?php if($stageTotals): ?><div class="ct-stage-totals mt-3"><h3 class="h6">Approved costs by stage</h3><dl><?php foreach($stageTotals as $stageName=>$stageAmount): ?><div><dt><?=e($stageName)?></dt><dd><?=money($stageAmount)?></dd></div><?php endforeach ?></dl></div><?php endif ?><h3 class="h6 mt-4">Work cost history</h3><div class="table-responsive" role="region" tabindex="0" aria-label="Approved work history, scroll horizontally to view all columns"><table class="table"><thead><tr><th>Date</th><th>Work stage</th><th class="ct-amount">Amount</th><th>Notes</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach($costs as $cost): ?><tr><td><?=e($cost['cost_date'])?></td><td><?=e($cost['stage_name']??'Unassigned stage')?></td><td class="ct-amount"><?=money($cost['amount'])?></td><td><?=e($cost['notes'])?></td><td><?=e($cost['status'])?></td><td><div class="d-flex flex-wrap gap-2"><a class="btn btn-sm btn-outline-primary" href="?page=contracts&amp;view=<?=$viewId?>&amp;edit_cost=<?=(int)$cost['id']?>#edit-work">Edit work</a><?php if($cost['status']==='active'): ?><form method="post" onsubmit="return confirm('Cancel this approved work cost? Payments remain recorded.')"><?=csrf()?><input type="hidden" name="id" value="<?=$viewId?>"><input type="hidden" name="cost_id" value="<?=$cost['id']?>"><button class="btn btn-sm btn-outline-danger" name="action" value="cancel_cost">Cancel cost</button></form><?php endif; ?></div></td></tr><?php endforeach; ?><?php if(!$costs): ?><tr><td colspan="6">No approved work costs yet.</td></tr><?php endif; ?></tbody></table></div>
<?php
$editCostId=(int)($_GET['edit_cost']??($error && $action==='edit_cost'?($_POST['cost_id']??0):0));
$editCost=$editCostId?one('SELECT * FROM contract_work_costs WHERE id=? AND contract_id=?',[$editCostId,$viewId]):null;
if ($editCostId && !$editCost) throw new RuntimeException('Work entry not found in this contract.');
if ($editCost):
$costForm=$error && $action==='edit_cost'?['cost_date'=>$_POST['cost_date']??'','amount'=>$_POST['amount']??'','notes'=>$_POST['cost_notes']??'','status'=>$_POST['cost_status']??'active','work_type_id'=>$_POST['cost_work_type_id']??null]:$editCost;
?>
<div id="edit-work" class="ct-edit-work"><h3 class="h5">Edit work entry #<?=(int)$editCost['id']?></h3><form method="post"><?=csrf()?><input type="hidden" name="action" value="edit_cost"><input type="hidden" name="id" value="<?=$viewId?>"><input type="hidden" name="cost_id" value="<?=(int)$editCost['id']?>"><div class="row g-3"><div class="col-sm-6"><label class="form-label d-block">Work date<input class="form-control" name="cost_date" type="date" required value="<?=e($costForm['cost_date'])?>"></label></div><div class="col-sm-6"><label class="form-label d-block">Approved amount (PKR)<input class="form-control" name="amount" type="number" min="0.01" step="0.01" required value="<?=e($costForm['amount'])?>"></label></div><?php if($stagesEnabled): ?><div class="col-sm-6"><label class="form-label d-block">Work stage / milestone<select class="form-select" name="cost_work_type_id"><option value="">Unassigned stage</option><?=options($stageOptions(isset($editCost['work_type_id'])?(int)$editCost['work_type_id']:null),$costForm['work_type_id']??null)?></select></label></div><?php endif ?><div class="col-sm-6"><label class="form-label d-block">Entry status<select class="form-select" name="cost_status"><option value="active" <?=$costForm['status']==='active'?'selected':''?>>Active — count as expense</option><option value="cancelled" <?=$costForm['status']==='cancelled'?'selected':''?>>Cancelled — exclude from expenses</option></select></label></div><div class="col-12"><label class="form-label d-block">Notes<textarea class="form-control" name="cost_notes" rows="2"><?=e($costForm['notes'])?></textarea></label></div></div><p class="small text-muted">Payment records stay unchanged. Reducing this cost can leave a prepayment on this contract.</p><button class="btn btn-primary">Save work changes</button> <a class="btn btn-outline-secondary" href="?page=contracts&amp;view=<?=$viewId?>">Close editor</a></form></div>
<?php endif; ?>
</div></section>
<section class="card mb-3"><div class="card-body"><div class="d-flex flex-wrap justify-content-between align-items-start gap-3"><div><span class="ct-step">03 · PAYMENTS</span><h2 class="h5">Money paid to the contractor</h2></div><?php if($c['status']!=='Cancelled'): ?><a class="btn btn-primary" href="?page=payments&amp;contract_id=<?=$viewId?>">Record payment</a><?php endif; ?></div><p class="small text-muted">Payments reduce what you owe. They do not add the same expense again. Open a payment to edit its details or change where it is applied.</p><div class="table-responsive" role="region" tabindex="0" aria-label="Contract payments, scroll horizontally to view all columns"><table class="table"><thead><tr><th>Date</th><th>Method</th><th class="ct-amount">Applied to this contract</th><th>Payment notes</th><th>Actions</th></tr></thead><tbody><?php $history=rows("SELECT p.*,a.amount allocated FROM payment_allocations a JOIN payments p ON p.id=a.payment_id WHERE a.contract_id=? AND p.status='active' ORDER BY p.payment_date DESC,p.id DESC",[$viewId]); foreach($history as $payment): ?><tr><td><a href="?page=payments&view=<?=$payment['id']?>"><?=e($payment['payment_date'])?></a></td><td><?=e($payment['method'])?></td><td class="ct-amount"><?=money($payment['allocated'])?></td><td><?=e($payment['notes'])?></td><td><a class="btn btn-sm btn-outline-primary" href="?page=payments&amp;view=<?=(int)$payment['id']?>&amp;edit=1">Edit payment</a></td></tr><?php endforeach; ?><?php if(!$history): ?><tr><td colspan="5">No payments applied to this contract yet.</td></tr><?php endif; ?></tbody></table></div></div></section></div>
<?php endif; ?>
<?php if($showForm):
$form=$error && $action==='save'?$_POST:($editing?:[]);
$contacts=rows("SELECT * FROM contacts WHERE type <> 'Supplier' OR id=? ORDER BY name",[$form['contact_id']??0]); $units=rows('SELECT * FROM units WHERE active=1 OR id=? ORDER BY name',[$editing['unit_id']??0]);
?>
<div class="card mb-3"><div class="card-body"><h2 class="h5"><?=$editing?'Edit / adjust contract':'New contract'?></h2><form method="post"><?=csrf()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e($form['id']??0)?>"><div class="row g-3"><div class="col-12 col-md-6"><label class="form-label d-block">Title<input required maxlength="180" class="form-control" name="title" value="<?=e($form['title']??'')?>"></label></div><div class="col-6 col-md-3"><label class="form-label d-block">Contract date<input required type="date" class="form-control" name="contract_date" value="<?=e($form['contract_date']??date('Y-m-d'))?>"></label></div><div class="col-6 col-md-3"><label class="form-label d-block">Status<select name="status" class="form-select"><?php foreach(['Active','Completed','Cancelled'] as $status): ?><option <?=($form['status']??'Active')===$status?'selected':''?>><?=$status?></option><?php endforeach; ?></select></label></div><div class="col-12 col-md-6"><label class="form-label d-block">Contractor<select class="form-select" required name="contact_id"><option value="">Select contractor</option><?=options($contacts,$form['contact_id']??null)?></select></label><a href="?page=contacts">Manage contacts</a></div>
<div class="col-12"><label class="form-label d-block">Description<textarea class="form-control" name="description" rows="2"><?=e($form['description']??'')?></textarea></label></div>
<div class="col-12 col-md-6"><label class="form-label d-block">Contract type<select class="form-select" name="type" id="contract-type"><option value="fixed" <?=($form['type']??'fixed')==='fixed'?'selected':''?>>Fixed agreed amount</option><option value="quantity" <?=($form['type']??'fixed')==='quantity'?'selected':''?>>Quantity × rate</option></select></label></div><div class="col-12 col-md-6" id="contract-fixed"><label class="form-label d-block">Agreed amount Rs.<input class="form-control" type="number" min="0" step="0.01" name="agreed_amount" value="<?=e($form['agreed_amount']??'0')?>"></label></div>
<div class="col-12" id="contract-quantity"><div class="row g-2"><div class="col-4"><label class="form-label d-block">Quantity<input class="form-control" type="number" step="0.0001" min="0.0001" name="quantity" value="<?=e($form['quantity']??'')?>"></label></div><div class="col-4"><label class="form-label d-block">Unit<select class="form-select" name="unit_id"><?=options($units,$form['unit_id']??null)?></select></label></div><div class="col-4"><label class="form-label d-block">Rate Rs. / unit<input class="form-control" type="number" step="0.01" min="0" name="rate" value="<?=e($form['rate']??'')?>"></label></div></div><p class="small text-muted">Agreed amount calculated from quantity × rate on server.</p></div><div class="col-12"><label class="form-label d-block">Notes / value adjustment reason<textarea class="form-control" name="notes" rows="2"><?=e($form['notes']??'')?></textarea></label></div></div><p class="small text-muted">Marking completed does not recognize work automatically. Record approved work costs separately. Cancellation preserves prior work costs and payment history.</p><button class="btn btn-primary">Save contract</button> <a class="btn btn-outline-secondary" href="?page=contracts">Back</a></form></div></div>
<script>(()=>{const s=document.querySelector('#contract-type');function update(){document.querySelector('#contract-fixed').hidden=s.value!=='fixed';document.querySelector('#contract-quantity').hidden=s.value!=='quantity';}s.addEventListener('change',update);update();})();</script>
<?php elseif(!$viewId):
$list=rows("SELECT ct.*,c.name contact_name,(SELECT COALESCE(SUM(amount),0) FROM contract_work_costs WHERE contract_id=ct.id AND status='active') recognized,(SELECT COALESCE(SUM(amount),0) FROM payment_allocations WHERE contract_id=ct.id) paid FROM contracts ct JOIN contacts c ON c.id=ct.contact_id WHERE ct.project_id=? ORDER BY ct.contract_date DESC,ct.id DESC",[$pid]); ?>
<div class="table-responsive"><table class="table table-striped"><thead><tr><th>Contract</th><th>Contractor</th><th>Agreed</th><th>Recognized</th><th>Paid</th><th>Remaining commitment</th><th>Payment balance</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach($list as $row): ?><tr><td><a href="?page=contracts&view=<?=$row['id']?>"><?=e($row['title'])?></a><small class="d-block text-muted"><?=e($row['contract_date'])?></small></td><td><?=e($row['contact_name'])?></td><td><?=money($row['agreed_amount'])?></td><td><?=money($row['recognized'])?></td><td><?=money($row['paid'])?></td><td><?=money(sub($row['agreed_amount'],$row['recognized']))?></td><td><?=money(sub($row['agreed_amount'],$row['paid']))?></td><td><?=e($row['status'])?></td><td><a class="btn btn-sm btn-outline-primary" href="?page=contracts&amp;edit=<?=(int)$row['id']?>">Edit</a></td></tr><?php endforeach; ?><?php if(!$list): ?><tr><td colspan="9">No contracts yet.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?>
