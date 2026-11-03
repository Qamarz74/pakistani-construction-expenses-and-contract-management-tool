<?php
$pid = project_id();
$id = max(0,(int)($_GET['id']??0));
$editId = max(0,(int)($_GET['edit']??0));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $editId = max(0,(int)($_POST['id']??0));
    $name = trim($_POST['name']??'');
    $phone = trim($_POST['phone']??'');
    $type = $_POST['type']??'';
    $notes = trim($_POST['notes']??'');
    if ($name===''||strlen($name)>150||strlen($phone)>50) throw new RuntimeException('Name required (maximum 150 characters); phone maximum 50 characters.');
    if (!in_array($type,contact_types(),true)) throw new RuntimeException('Invalid contact type.');
    if ($editId) {
        if (!one('SELECT id FROM contacts WHERE id=?',[$editId])) throw new RuntimeException('Contact not found.');
        run('UPDATE contacts SET name=?,phone=?,type=?,notes=? WHERE id=?',[$name,$phone,$type,$notes,$editId]);
    } else {
        run('INSERT INTO contacts (name,phone,type,notes) VALUES (?,?,?,?)',[$name,$phone,$type,$notes]);
        $editId = (int)db()->lastInsertId();
    }
    flash('Contact saved.');
    redirect('index.php?page=contacts&id='.$editId);
}
$edit = $editId ? one('SELECT * FROM contacts WHERE id=?',[$editId]) : null;
if ($editId&&!$edit) throw new RuntimeException('Contact not found.');
$contact = $id ? one('SELECT * FROM contacts WHERE id=?',[$id]) : null;
if ($id&&!$contact) throw new RuntimeException('Contact not found.');
$contacts = rows('SELECT * FROM contacts ORDER BY name');
?>
<div class="row g-4"><div class="col-lg-4"><div class="card"><div class="card-body"><h2 class="h5"><?= $edit?'Edit contact':'Add contact' ?></h2>
<form method="post"><?= csrf() ?><input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>">
<label class="form-label" for="name">Name</label><input class="form-control mb-3" id="name" name="name" value="<?= e($edit['name']??'') ?>" required maxlength="150">
<label class="form-label" for="phone">Phone</label><input class="form-control mb-3" id="phone" name="phone" type="tel" maxlength="50" value="<?= e($edit['phone']??'') ?>">
<label class="form-label" for="type">Person / business type</label><select class="form-select mb-3" id="type" name="type"><?php foreach (contact_types() as $type): ?><option value="<?= e($type) ?>" <?= ($edit['type']??'Supplier')===$type?'selected':'' ?>><?= e($type==='Both'?'Supplier & contractor':$type) ?></option><?php endforeach ?></select>
<p class="small text-muted">Choose Other when none fit. Add their role or trade in Notes.</p>
<label class="form-label" for="notes">Notes</label><textarea class="form-control mb-3" id="notes" name="notes"><?= e($edit['notes']??'') ?></textarea>
<button class="btn btn-primary">Save contact</button> <?php if ($edit): ?><a class="btn btn-outline-secondary" href="index.php?page=contacts">Clear</a><?php endif ?>
</form></div></div></div>
<div class="col-lg-8"><div class="table-responsive"><table class="table table-striped"><thead><tr><th>Name</th><th>Phone</th><th>Type</th><th></th></tr></thead><tbody>
<?php foreach ($contacts as $entry): ?><tr><td><a href="index.php?page=contacts&amp;id=<?= (int)$entry['id'] ?>"><?= e($entry['name']) ?></a></td><td><?= e($entry['phone']) ?></td><td><?= e($entry['type']) ?></td><td><a class="btn btn-sm btn-outline-primary" href="index.php?page=contacts&amp;edit=<?= (int)$entry['id'] ?>">Edit</a></td></tr><?php endforeach ?>
<?php if (!$contacts): ?><tr><td colspan="4">No contacts yet.</td></tr><?php endif ?>
</tbody></table></div></div></div>
<?php if ($contact):
$purchases = rows("SELECT e.*, COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa JOIN payments p ON p.id=pa.payment_id WHERE pa.expense_id=e.id AND p.status='active'),0) paid FROM expenses e WHERE e.project_id=? AND e.contact_id=? ORDER BY e.expense_date DESC,e.id DESC",[$pid,$id]);
$contracts = rows("SELECT c.*, w.name work_type, COALESCE((SELECT SUM(cw.amount) FROM contract_work_costs cw WHERE cw.contract_id=c.id AND cw.status='active'),0) recognized, COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa JOIN payments p ON p.id=pa.payment_id WHERE pa.contract_id=c.id AND p.status='active'),0) paid FROM contracts c JOIN work_types w ON w.id=c.work_type_id WHERE c.project_id=? AND c.contact_id=? ORDER BY c.contract_date DESC,c.id DESC",[$pid,$id]);
$payments = rows("SELECT p.*,COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa WHERE pa.payment_id=p.id),0) allocated FROM payments p WHERE p.project_id=? AND p.contact_id=? ORDER BY p.payment_date DESC,p.id DESC",[$pid,$id]);
$summary = one("SELECT
(SELECT COALESCE(SUM(e.total),0) FROM expenses e WHERE e.project_id=? AND e.contact_id=? AND e.status='active') purchases,
(SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.project_id=? AND p.contact_id=? AND p.status='active') paid,
(SELECT COALESCE(SUM(p.amount-COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa WHERE pa.payment_id=p.id),0)),0) FROM payments p WHERE p.project_id=? AND p.contact_id=? AND p.status='active') advances,
(SELECT COALESCE(SUM(GREATEST(0,e.total-COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa JOIN payments p ON p.id=pa.payment_id WHERE pa.expense_id=e.id AND p.status='active'),0))),0) FROM expenses e WHERE e.project_id=? AND e.contact_id=? AND e.status='active') purchase_due,
(SELECT COALESCE(SUM(GREATEST(0,COALESCE((SELECT SUM(cw.amount) FROM contract_work_costs cw WHERE cw.contract_id=c.id AND cw.status='active'),0)-COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa JOIN payments p ON p.id=pa.payment_id WHERE pa.contract_id=c.id AND p.status='active'),0))),0) FROM contracts c WHERE c.project_id=? AND c.contact_id=?) contract_due",[$pid,$id,$pid,$id,$pid,$id,$pid,$id,$pid,$id]);
$summary['outstanding'] = add($summary['purchase_due'],$summary['contract_due']);
?>
<hr><h2 class="h4 mt-4"><?= e($contact['name']) ?> — selected project ledger</h2>
<p><?= e($contact['phone']) ?> · <?= e($contact['type']) ?><br><?= nl2br(e($contact['notes'])) ?></p>
<div class="row g-3 mb-4"><?php foreach (['purchases'=>'Purchases','paid'=>'Total paid','outstanding'=>'Total outstanding','purchase_due'=>'Purchase outstanding','contract_due'=>'Recognized work outstanding','advances'=>'Unallocated advances'] as $key=>$label): ?><div class="col-6 col-md-4"><div class="card h-100"><div class="card-body"><div class="small text-muted"><?= $label ?></div><strong><?= money($summary[$key]) ?></strong></div></div></div><?php endforeach ?></div>
<h3 class="h5">Purchases</h3><div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Purchase</th><th>Status</th><th>Total</th><th>Paid</th><th>Outstanding</th></tr></thead><tbody>
<?php foreach ($purchases as $purchase): ?><tr><td><?= e($purchase['expense_date']) ?></td><td><a href="index.php?page=expenses&amp;view=<?= (int)$purchase['id'] ?>">#<?= (int)$purchase['id'] ?></a></td><td><?= e($purchase['status']) ?></td><td><?= money($purchase['total']) ?></td><td><?= money($purchase['paid']) ?></td><td><?= $purchase['status']==='active'?money(bcsub($purchase['total'],$purchase['paid'],2)):'—' ?></td></tr><?php endforeach ?>
<?php if (!$purchases): ?><tr><td colspan="6">No purchases.</td></tr><?php endif ?>
</tbody></table></div>
<h3 class="h5">Thekas / contracts</h3><p class="small text-muted">Commitments are separate from recorded expenses. Payment balance is agreed amount less allocated payments.</p><div class="table-responsive"><table class="table"><thead><tr><th>Contract</th><th>Work type</th><th>Status</th><th>Agreed</th><th>Recognized cost</th><th>Paid</th><th>Remaining commitment</th><th>Payment balance</th></tr></thead><tbody>
<?php foreach ($contracts as $contract): ?><tr><td><a href="index.php?page=contracts&amp;view=<?= (int)$contract['id'] ?>"><?= e($contract['title']) ?></a><br><?= e($contract['contract_date']) ?></td><td><?= e($contract['work_type']) ?></td><td><?= e($contract['status']) ?></td><td><?= money($contract['agreed_amount']) ?></td><td><?= money($contract['recognized']) ?></td><td><?= money($contract['paid']) ?></td><td><?= money(bcsub($contract['agreed_amount'],$contract['recognized'],2)) ?></td><td><?= money(bcsub($contract['agreed_amount'],$contract['paid'],2)) ?></td></tr><?php endforeach ?>
<?php if (!$contracts): ?><tr><td colspan="8">No contracts.</td></tr><?php endif ?>
</tbody></table></div>
<h3 class="h5">Payments</h3><div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Method</th><th>Status</th><th>Amount</th><th>Allocations</th><th>Unallocated</th></tr></thead><tbody>
<?php foreach ($payments as $payment): $allocations=rows('SELECT expense_id,contract_id,amount FROM payment_allocations WHERE payment_id=?',[$payment['id']]); ?><tr><td><?= e($payment['payment_date']) ?></td><td><?= e($payment['method']) ?></td><td><?= e($payment['status']) ?></td><td><?= money($payment['amount']) ?></td><td><?php foreach ($allocations as $allocation): ?><?= $allocation['expense_id']?'Purchase #'.(int)$allocation['expense_id']:'Contract #'.(int)$allocation['contract_id'] ?>: <?= money($allocation['amount']) ?><br><?php endforeach ?><?= e($payment['notes']) ?></td><td><?= $payment['status']==='active'?money(bcsub($payment['amount'],$payment['allocated'],2)):'—' ?></td></tr><?php endforeach ?>
<?php if (!$payments): ?><tr><td colspan="6">No payments.</td></tr><?php endif ?>
</tbody></table></div>
<?php endif ?>
