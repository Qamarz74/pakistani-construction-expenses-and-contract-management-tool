<?php
$pid = project_id(); $error = null; $action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    try {
        db()->beginTransaction(); project_lock();
        $id = (int)($_POST['id'] ?? 0);
        $contract = $id ? one('SELECT * FROM contracts WHERE id=? AND project_id=? FOR UPDATE',[$id,$pid]) : null;
        if ($id && !$contract) throw new RuntimeException('Contract not found.');
        if ($action === 'save') {
            if ($contract && $contract['status']==='Cancelled') throw new RuntimeException('Cancelled contracts cannot be edited.');
            $title = trim($_POST['title'] ?? '');
            if ($title==='' || mb_strlen($title)>180) throw new RuntimeException('Contract title is required (maximum 180 characters).');
            $contact = (int)($_POST['contact_id'] ?? 0); $work = (int)($_POST['work_type_id'] ?? 0);
            if (!one("SELECT id FROM contacts WHERE id=? AND type IN ('Contractor','Both')",[$contact])) throw new RuntimeException('Select a contractor contact.');
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
            run('INSERT INTO contract_work_costs(contract_id,cost_date,amount,notes) VALUES(?,?,?,?)',[$id,valid_date($_POST['cost_date']??''),$amount,trim($_POST['cost_notes']??'')]);
            if(isset($_POST['full_completion'])) run("UPDATE contracts SET status='Completed' WHERE id=?",[$id]);
        } elseif ($action==='cancel_cost') {
            if(!$contract) throw new RuntimeException('Contract not found.');
            $cost=one('SELECT * FROM contract_work_costs WHERE id=? AND contract_id=? FOR UPDATE',[(int)($_POST['cost_id']??0),$id]);
            if(!$cost) throw new RuntimeException('Work cost not found.');
            run("UPDATE contract_work_costs SET status='cancelled' WHERE id=?",[$cost['id']]);
        } else throw new RuntimeException('Invalid action.');
        db()->commit(); flash('Contract updated.'); redirect('?page=contracts&view='.$id);
    } catch(Throwable $ex) { if(db()->inTransaction())db()->rollBack(); if($ex instanceof PDOException) error_log((string)$ex); $error=$ex instanceof PDOException ? 'Database operation failed. No changes were saved.' : $ex->getMessage(); }
}
$viewId=(int)($_GET['view']??$_GET['id']??0); $editId=(int)($_GET['edit']??0);
$editing=$editId ? one('SELECT * FROM contracts WHERE id=? AND project_id=?',[$editId,$pid]):null;
if($editId && !$editing)throw new RuntimeException('Contract not found.');
$showForm=isset($_GET['add']) || $editing || ($error && $action==='save');
?>
<div class="d-flex justify-content-between align-items-center gap-2 mb-3"><h1 class="h3 mb-0">Thekas / contracts</h1><a class="btn btn-primary" href="?page=contracts&add=1">Add Theka</a></div>
<?php if($error): ?><div class="alert alert-danger" role="alert"><?=e($error)?></div><?php endif; ?>
<p class="text-muted">Agreed amounts are commitments. Only approved work costs count as recorded expenses. Payments are tracked separately.</p>
<?php if($viewId):
$c=one('SELECT ct.*,c.name contact_name,w.name work_name FROM contracts ct JOIN contacts c ON c.id=ct.contact_id JOIN work_types w ON w.id=ct.work_type_id WHERE ct.id=? AND ct.project_id=?',[$viewId,$pid]);
if(!$c)throw new RuntimeException('Contract not found.');
$costs=rows('SELECT * FROM contract_work_costs WHERE contract_id=? ORDER BY cost_date DESC,id DESC',[$viewId]);
$recognized=one("SELECT COALESCE(SUM(amount),0) amount FROM contract_work_costs WHERE contract_id=? AND status='active'",[$viewId])['amount'];
$paid=one('SELECT COALESCE(SUM(amount),0) amount FROM payment_allocations WHERE contract_id=?',[$viewId])['amount'];
?>
<div class="card mb-3"><div class="card-body"><h2 class="h5"><?=e($c['title'])?> <span class="badge text-bg-secondary"><?=e($c['status'])?></span></h2><p><?=e($c['contact_name'])?> · <?=e($c['work_name'])?> · <?=e($c['contract_date'])?></p><p><?=nl2br(e($c['description']))?></p><?php if($c['type']==='quantity'): ?><p><?=e($c['quantity'].' '.$c['unit_name'])?> × <?=money($c['rate'])?> per <?=e($c['unit_name'])?></p><?php endif; ?>
<div class="row g-2 mb-3"><?php foreach(['Agreed commitment'=>$c['agreed_amount'],'Recognized work cost'=>$recognized,'Allocated payments'=>$paid,'Remaining commitment'=>sub($c['agreed_amount'],$recognized),'Payment balance'=>sub($c['agreed_amount'],$paid),'Unpaid approved work'=>cmp($recognized,$paid)>0?sub($recognized,$paid):'0','Prepaid against work'=>cmp($paid,$recognized)>0?sub($paid,$recognized):'0'] as $label=>$value): ?><div class="col-6 col-md-3"><div class="border rounded p-2"><small><?=e($label)?></small><div class="fw-bold"><?=money($value)?></div></div></div><?php endforeach; ?></div>
<p><?=nl2br(e($c['notes']))?></p><?php if($c['status']!=='Cancelled'): ?><div class="d-flex gap-2 flex-wrap"><a class="btn btn-outline-primary" href="?page=contracts&edit=<?=$viewId?>">Edit / adjust agreed value</a><a class="btn btn-primary" href="?page=payments&contract_id=<?=$viewId?>">Advance / installment payment</a></div><?php endif; ?>
</div></div>
<?php if($c['status']!=='Cancelled' && cmp($c['agreed_amount'],$recognized)>0): ?><div class="card mb-3"><div class="card-body"><h3 class="h5">Record completed / approved work cost</h3><form method="post"><?=csrf()?><input type="hidden" name="action" value="recognize"><input type="hidden" name="id" value="<?=$viewId?>"><div class="row g-2"><div class="col-6 col-md-3"><label class="form-label">Work date<input class="form-control" required type="date" name="cost_date" value="<?=e($_POST['cost_date']??date('Y-m-d'))?>"></label></div><div class="col-6 col-md-3"><label class="form-label">Approved cost Rs.<input class="form-control" type="number" min="0.01" step="0.01" name="amount" value="<?=e($_POST['amount']??'')?>"></label></div><div class="col-12 col-md-6"><label class="form-label">Notes<input class="form-control" name="cost_notes" value="<?=e($_POST['cost_notes']??'')?>"></label></div></div><label class="form-check my-2"><input class="form-check-input" type="checkbox" name="full_completion" value="1"> Recognize full remaining commitment and mark completed</label><button class="btn btn-primary">Record work cost</button></form></div></div><?php endif; ?>
<h3 class="h5">Recognized work history</h3><div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Amount</th><th>Notes</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($costs as $cost): ?><tr><td><?=e($cost['cost_date'])?></td><td><?=money($cost['amount'])?></td><td><?=e($cost['notes'])?></td><td><?=e($cost['status'])?></td><td><?php if($cost['status']==='active'): ?><form method="post" onsubmit="return confirm('Cancel this approved work cost? Payments remain recorded.')"><?=csrf()?><input type="hidden" name="id" value="<?=$viewId?>"><input type="hidden" name="cost_id" value="<?=$cost['id']?>"><button class="btn btn-sm btn-outline-danger" name="action" value="cancel_cost">Cancel cost</button></form><?php endif; ?></td></tr><?php endforeach; ?><?php if(!$costs): ?><tr><td colspan="5">No work cost recognized yet.</td></tr><?php endif; ?></tbody></table></div>
<h3 class="h5">Payment history</h3><div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Method</th><th>Allocated amount</th><th>Payment notes</th></tr></thead><tbody><?php $history=rows('SELECT p.*,a.amount allocated FROM payment_allocations a JOIN payments p ON p.id=a.payment_id WHERE a.contract_id=? ORDER BY p.payment_date DESC,p.id DESC',[$viewId]); foreach($history as $payment): ?><tr><td><a href="?page=payments&view=<?=$payment['id']?>"><?=e($payment['payment_date'])?></a></td><td><?=e($payment['method'])?></td><td><?=money($payment['allocated'])?></td><td><?=e($payment['notes'])?></td></tr><?php endforeach; ?><?php if(!$history): ?><tr><td colspan="4">No allocated payments yet.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?>
<?php if($showForm):
$form=$error && $action==='save'?$_POST:($editing?:[]);
$contacts=rows("SELECT * FROM contacts WHERE type IN ('Contractor','Both') ORDER BY name"); $works=rows('SELECT * FROM work_types WHERE active=1 OR id=? ORDER BY name',[$editing['work_type_id']??0]); $units=rows('SELECT * FROM units WHERE active=1 OR id=? ORDER BY name',[$editing['unit_id']??0]);
?>
<div class="card mb-3"><div class="card-body"><h2 class="h5"><?=$editing?'Edit / adjust contract':'New contract'?></h2><form method="post"><?=csrf()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e($form['id']??0)?>"><div class="row g-3"><div class="col-12 col-md-6"><label class="form-label d-block">Title<input required maxlength="180" class="form-control" name="title" value="<?=e($form['title']??'')?>"></label></div><div class="col-6 col-md-3"><label class="form-label d-block">Contract date<input required type="date" class="form-control" name="contract_date" value="<?=e($form['contract_date']??date('Y-m-d'))?>"></label></div><div class="col-6 col-md-3"><label class="form-label d-block">Status<select name="status" class="form-select"><?php foreach(['Active','Completed','Cancelled'] as $status): ?><option <?=($form['status']??'Active')===$status?'selected':''?>><?=$status?></option><?php endforeach; ?></select></label></div><div class="col-12 col-md-6"><label class="form-label d-block">Contractor<select class="form-select" required name="contact_id"><option value="">Select contractor</option><?=options($contacts,$form['contact_id']??null)?></select></label><a href="?page=contacts">Manage contacts</a></div><div class="col-12 col-md-6"><label class="form-label d-block">Work type<select required class="form-select" name="work_type_id"><?=options($works,$form['work_type_id']??null)?></select></label></div>
<div class="col-12"><label class="form-label d-block">Description<textarea class="form-control" name="description" rows="2"><?=e($form['description']??'')?></textarea></label></div>
<div class="col-12 col-md-6"><label class="form-label d-block">Contract type<select class="form-select" name="type" id="contract-type"><option value="fixed" <?=($form['type']??'fixed')==='fixed'?'selected':''?>>Fixed agreed amount</option><option value="quantity" <?=($form['type']??'fixed')==='quantity'?'selected':''?>>Quantity × rate</option></select></label></div><div class="col-12 col-md-6" id="contract-fixed"><label class="form-label d-block">Agreed amount Rs.<input class="form-control" type="number" min="0" step="0.01" name="agreed_amount" value="<?=e($form['agreed_amount']??'0')?>"></label></div>
<div class="col-12" id="contract-quantity"><div class="row g-2"><div class="col-4"><label class="form-label d-block">Quantity<input class="form-control" type="number" step="0.0001" min="0.0001" name="quantity" value="<?=e($form['quantity']??'')?>"></label></div><div class="col-4"><label class="form-label d-block">Unit<select class="form-select" name="unit_id"><?=options($units,$form['unit_id']??null)?></select></label></div><div class="col-4"><label class="form-label d-block">Rate Rs. / unit<input class="form-control" type="number" step="0.01" min="0" name="rate" value="<?=e($form['rate']??'')?>"></label></div></div><p class="small text-muted">Agreed amount calculated from quantity × rate on server.</p></div><div class="col-12"><label class="form-label d-block">Notes / value adjustment reason<textarea class="form-control" name="notes" rows="2"><?=e($form['notes']??'')?></textarea></label></div></div><p class="small text-muted">Marking completed does not recognize work automatically. Record approved work costs separately. Cancellation preserves prior work costs and payment history.</p><button class="btn btn-primary">Save contract</button> <a class="btn btn-outline-secondary" href="?page=contracts">Back</a></form></div></div>
<script>(()=>{const s=document.querySelector('#contract-type');function update(){document.querySelector('#contract-fixed').hidden=s.value!=='fixed';document.querySelector('#contract-quantity').hidden=s.value!=='quantity';}s.addEventListener('change',update);update();})();</script>
<?php else:
$list=rows("SELECT ct.*,c.name contact_name,w.name work_name,(SELECT COALESCE(SUM(amount),0) FROM contract_work_costs WHERE contract_id=ct.id AND status='active') recognized,(SELECT COALESCE(SUM(amount),0) FROM payment_allocations WHERE contract_id=ct.id) paid FROM contracts ct JOIN contacts c ON c.id=ct.contact_id JOIN work_types w ON w.id=ct.work_type_id WHERE ct.project_id=? ORDER BY ct.contract_date DESC,ct.id DESC",[$pid]); ?>
<div class="table-responsive"><table class="table table-striped"><thead><tr><th>Theka</th><th>Contractor / work</th><th>Agreed</th><th>Recognized</th><th>Paid</th><th>Remaining commitment</th><th>Payment balance</th><th>Status</th></tr></thead><tbody><?php foreach($list as $row): ?><tr><td><a href="?page=contracts&view=<?=$row['id']?>"><?=e($row['title'])?></a><small class="d-block text-muted"><?=e($row['contract_date'])?></small></td><td><?=e($row['contact_name'])?><small class="d-block text-muted"><?=e($row['work_name'])?></small></td><td><?=money($row['agreed_amount'])?></td><td><?=money($row['recognized'])?></td><td><?=money($row['paid'])?></td><td><?=money(sub($row['agreed_amount'],$row['recognized']))?></td><td><?=money(sub($row['agreed_amount'],$row['paid']))?></td><td><?=e($row['status'])?></td></tr><?php endforeach; ?><?php if(!$list): ?><tr><td colspan="8">No contracts yet.</td></tr><?php endif; ?></tbody></table></div>
<?php endif; ?>
