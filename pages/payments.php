<?php
require_once __DIR__.'/../app/contact-links.php';
$id = max(0, (int)($_POST['id'] ?? $_GET['id'] ?? $_GET['view'] ?? 0));
$methods = ['Cash','Bank Transfer','JazzCash','Easypaisa','Cheque','Other'];
$formError = null;
$action = (string)($_POST['action'] ?? '');
$editingPayment = isset($_GET['edit']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_post_csrf();
        db()->beginTransaction();
        project_lock();
        if ($action === 'create') {
            $contact = (int)($_POST['contact_id'] ?? 0) ?: null;
            $purpose = (string)($_POST['purpose'] ?? 'expense');
            $target = (string)($_POST['target'] ?? '');
            if (!in_array($purpose, ['expense','contract','advance'], true)) throw new InvalidArgumentException('Choose what this payment is for.');
            if ($purpose === 'advance') $target = '';
            elseif (!str_starts_with($target, $purpose.':')) throw new InvalidArgumentException('Choose a matching purchase or contract for this payment.');
            if (!$contact && !str_starts_with($target, 'expense:')) throw new InvalidArgumentException('Choose a recipient for an advance or contract payment.');
            $id = create_payment($contact, valid_date($_POST['payment_date'] ?? ''), decimal($_POST['amount'] ?? '',2,true), (string)($_POST['method'] ?? ''), trim((string)($_POST['notes'] ?? '')));
            if ($target !== '') {
                [$type,$targetId] = array_pad(explode(':',$target,2),2,'0');
                $allocated = trim((string)($_POST['allocated'] ?? ''));
                if ($allocated === '') $allocated = (string)$_POST['amount'];
                allocate_payment($id,$type,(int)$targetId,$allocated);
            }
            upload_attachment('attachment',null,$id);
        } else {
            $payment = one('SELECT * FROM payments WHERE id=? AND project_id=? FOR UPDATE',[$id,project_id()]);
            if (!$payment || $payment['status'] !== 'active') throw new InvalidArgumentException('Active payment not found.');
            if ($action === 'update') {
                $contact = (int)($_POST['contact_id'] ?? 0) ?: null;
                if ($contact && !one('SELECT id FROM contacts WHERE id=?',[$contact])) throw new InvalidArgumentException('Choose a valid recipient.');
                $amount = decimal($_POST['amount'] ?? '',2,true);
                $allocated = one('SELECT COALESCE(SUM(amount),0) amount FROM payment_allocations WHERE payment_id=?',[$id])['amount'];
                if (cmp($amount,$allocated)<0) throw new InvalidArgumentException('Payment amount cannot be less than the amount already applied. Release an allocation below before reducing the payment.');
                if ($payment['contact_id'] === null && $contact && isset($_POST['attach_supplier'])) attach_missing_supplier('payment', $id, $contact);
                $allocations = rows('SELECT a.id,e.contact_id expense_contact,c.contact_id contract_contact,a.expense_id FROM payment_allocations a LEFT JOIN expenses e ON e.id=a.expense_id LEFT JOIN contracts c ON c.id=a.contract_id WHERE a.payment_id=?',[$id]);
                foreach ($allocations as $allocation) {
                    $targetContact = $allocation['expense_id'] ? $allocation['expense_contact'] : $allocation['contract_contact'];
                    if ($payment['contact_id'] === null && $contact && $targetContact === null) throw new InvalidArgumentException('Select the option to also assign this recipient to linked unassigned purchases and payments.');
                    if ((string)($targetContact ?? '') !== (string)($contact ?? '')) throw new InvalidArgumentException('This recipient does not match the purchases or contracts already paid. Release those allocations below, change the recipient, then apply this same payment again.');
                }
                if (!$contact && !$allocations) throw new InvalidArgumentException('Choose a recipient for an unapplied payment.');
                $method = (string)($_POST['method'] ?? '');
                if (!in_array($method,$methods,true)) throw new InvalidArgumentException('Choose a valid payment method.');
                run('UPDATE payments SET contact_id=?,payment_date=?,amount=?,method=?,notes=? WHERE id=? AND project_id=?',[$contact,valid_date($_POST['payment_date'] ?? ''),$amount,$method,trim((string)($_POST['notes'] ?? '')),$id,project_id()]);
            } elseif ($action === 'allocate') {
                [$type,$targetId] = array_pad(explode(':',(string)($_POST['target'] ?? ''),2),2,'0');
                allocate_payment($id,$type,(int)$targetId,(string)($_POST['allocated'] ?? ''));
            } elseif ($action === 'release') {
                run('DELETE FROM payment_allocations WHERE id=? AND payment_id=?',[(int)($_POST['allocation_id'] ?? 0),$id]);
            } elseif ($action === 'cancel') {
                run('DELETE FROM payment_allocations WHERE payment_id=?',[$id]);
                run("UPDATE payments SET status='cancelled' WHERE id=?",[$id]);
            } elseif ($action === 'upload') upload_attachment('attachment',null,$id);
            else throw new InvalidArgumentException('Unknown payment operation.');
        }
        db()->commit();
        flash($action === 'update' ? 'Payment updated. No new payment was created.' : 'Payment saved.');
        redirect('index.php?page=payments&id='.$id);
    } catch (Throwable $ex) {
        if (db()->inTransaction()) db()->rollBack();
        cleanup_failed_uploads();
        error_log((string)$ex);
        $formError = $ex instanceof PDOException ? 'Unable to save payment. No changes saved.' : $ex->getMessage();
        if ($action === 'create') $id = 0;
        if ($action === 'update') $editingPayment = true;
    }
}
$contacts = rows('SELECT id,name FROM contacts ORDER BY name');
function payment_targets(?int $contact=null, bool $restrict=false): array {
    $params=[project_id()]; $where='';
    if ($restrict) { $where=' AND e.contact_id <=> ?'; $params[]=$contact; }
    $targets=rows("SELECT e.id,e.contact_id,e.expense_date date,COALESCE(c.name,'No supplier') name,e.total-COALESCE((SELECT SUM(a.amount) FROM payment_allocations a JOIN payments p ON p.id=a.payment_id AND p.status='active' WHERE a.expense_id=e.id),0) balance FROM expenses e LEFT JOIN contacts c ON c.id=e.contact_id WHERE e.project_id=? AND e.status='active' $where HAVING balance>0 ORDER BY e.expense_date DESC,e.id DESC",$params);
    foreach ($targets as &$target) { $target['value']='expense:'.$target['id']; $target['label']='Purchase #'.$target['id'].' · '.$target['name'].' · '.money($target['balance']).' due'; } unset($target);
    $params=[project_id()]; $where='';
    if ($restrict) { $where=' AND e.contact_id <=> ?'; $params[]=$contact; }
    $contracts=rows("SELECT e.id,e.contact_id,e.title name,e.agreed_amount-COALESCE((SELECT SUM(a.amount) FROM payment_allocations a JOIN payments p ON p.id=a.payment_id AND p.status='active' WHERE a.contract_id=e.id),0) balance FROM contracts e WHERE e.project_id=? AND e.status<>'Cancelled' $where HAVING balance>0 ORDER BY e.contract_date DESC,e.id DESC",$params);
    foreach ($contracts as &$target) { $target['value']='contract:'.$target['id']; $target['label']='Contract #'.$target['id'].' · '.$target['name'].' · '.money($target['balance']).' balance'; } unset($target);
    return array_merge($targets,$contracts);
}
?>
<div class="py-payments">
<?php if ($formError): ?><div class="alert alert-danger" role="alert"><?=e($formError)?></div><?php endif ?>
<p class="py-intro">Record money paid here once. Apply it to a purchase or a contract, or keep it as an advance. Approved contract work records the cost separately.</p>
<?php if ($id):
    $payment=one('SELECT p.*,c.name contact_name FROM payments p LEFT JOIN contacts c ON c.id=p.contact_id WHERE p.id=? AND p.project_id=?',[$id,project_id()]);
    if (!$payment) throw new InvalidArgumentException('Payment not found in this project.');
    $allocations=rows('SELECT a.*,e.expense_date,c.title FROM payment_allocations a LEFT JOIN expenses e ON e.id=a.expense_id LEFT JOIN contracts c ON c.id=a.contract_id WHERE a.payment_id=? ORDER BY a.id',[$id]);
    $allocated='0.00'; foreach ($allocations as $allocation) $allocated=add($allocated,$allocation['amount']);
    $available=$payment['status']==='active'?sub($payment['amount'],$allocated):'0.00';
    $form=$formError && $action==='update'?$_POST:$payment;
    ?>
    <div class="py-actions"><a class="btn btn-outline-secondary" href="index.php?page=payments">All payments</a><a class="btn btn-primary" href="index.php?page=payments&new=1">Record a payment</a></div>
    <section class="py-panel" aria-labelledby="payment-heading">
        <div class="py-panel-heading"><div><h2 id="payment-heading">Payment #<?=$id?></h2><p><?=e($payment['payment_date'])?> · <?=e($payment['contact_name']??'No supplier')?> · <?=e($payment['method'])?> · <?=e(ucfirst($payment['status']))?></p></div><?php if ($payment['status']==='active'&&!$editingPayment): ?><a class="btn btn-outline-primary" href="index.php?page=payments&amp;id=<?=$id?>&amp;edit=1">Edit payment</a><?php endif ?></div>
        <dl class="py-balances"><div><dt>Payment amount</dt><dd><?=money($payment['amount'])?></dd></div><div><dt>Applied to records</dt><dd><?=money($allocated)?></dd></div><div><dt>Not yet applied</dt><dd><?=money($available)?></dd></div></dl>
        <?php if ($payment['notes']): ?><p class="text-break"><?=nl2br(e($payment['notes']))?></p><?php endif ?>
        <?=attachments_html(null,$id)?>
    </section>
    <?php if ($editingPayment && $payment['status']==='active'): ?>
    <form method="post" class="py-panel"><?=csrf()?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?=$id?>">
        <h2>Edit payment details</h2><p class="py-help">Changes update this payment. To correct what it pays for, release and reapply the existing payment below.</p>
        <div class="py-form-grid">
            <label class="py-field" for="edit-recipient"><span>Recipient</span><select class="form-select" id="edit-recipient" name="contact_id"><option value="">No supplier (purchase only)</option><?=options($contacts,$form['contact_id']??null)?></select></label>
            <?php if ($payment['contact_id'] === null): ?><div class="py-full"><label><input class="form-check-input" type="checkbox" name="attach_supplier" value="1" <?=isset($_POST['attach_supplier'])?'checked':''?>> Also assign this recipient to linked unassigned purchases and payments</label><p class="py-help">Required when linked purchases have no supplier. Includes other unassigned records connected through shared payments. Existing suppliers must match; amounts and allocations stay unchanged.</p></div><?php endif ?>
            <label class="py-field" for="edit-date"><span>Payment date</span><input class="form-control" id="edit-date" type="date" name="payment_date" value="<?=e($form['payment_date']??'')?>" required></label>
            <label class="py-field" for="edit-amount"><span>Payment amount (PKR)</span><input class="form-control" id="edit-amount" type="number" inputmode="decimal" min="0.01" step="0.01" name="amount" value="<?=e($form['amount']??'')?>" required><small>Already applied: <?=money($allocated)?>. Release allocations before entering a lower amount.</small></label>
            <label class="py-field" for="edit-method"><span>Payment method</span><select class="form-select" id="edit-method" name="method"><?php foreach ($methods as $method): ?><option <?=($form['method']??'')===$method?'selected':''?>><?=e($method)?></option><?php endforeach ?></select></label>
            <label class="py-field py-full" for="edit-notes"><span>Notes</span><textarea class="form-control" id="edit-notes" name="notes"><?=e($form['notes']??'')?></textarea></label>
        </div><div class="py-actions"><button class="btn btn-primary">Save changes</button><a class="btn btn-outline-secondary" href="index.php?page=payments&amp;id=<?=$id?>">Close editor</a></div>
    </form>
    <?php endif ?>
    <section class="py-panel" aria-labelledby="payment-links-heading"><h2 id="payment-links-heading">What this payment pays for</h2><p class="py-help">Applied to the wrong record? Release that amount, then apply it to the correct purchase or contract. This does not record another payment.</p>
        <div class="table-responsive" tabindex="0" role="region" aria-label="Applied payment amounts"><table class="table py-table"><thead><tr><th>Purchase or contract</th><th class="py-number">Amount applied</th><th>Correct allocation</th></tr></thead><tbody>
        <?php foreach ($allocations as $allocation): ?><tr><td><a href="index.php?page=<?=$allocation['expense_id']?'expenses':'contracts'?>&amp;view=<?=(int)($allocation['expense_id']??$allocation['contract_id'])?>"><?=e($allocation['expense_id']?'Purchase #'.$allocation['expense_id']:'Contract: '.$allocation['title'])?></a></td><td class="py-number"><?=money($allocation['amount'])?></td><td><?php if ($payment['status']==='active'): ?><form method="post"><?=csrf()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="allocation_id" value="<?=(int)$allocation['id']?>"><button class="btn btn-sm btn-outline-secondary" name="action" value="release" onclick="return confirm('Release this amount so the same payment can be applied elsewhere?')">Release amount</button></form><?php endif ?></td></tr><?php endforeach ?>
        <?php if (!$allocations): ?><tr><td colspan="3">No records paid yet. This payment is available as an advance.</td></tr><?php endif ?></tbody></table></div>
    </section>
    <?php if ($payment['status']==='active'): ?>
        <?php if (cmp($available,'0')>0): ?><form method="post" class="py-panel"><?=csrf()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="allocate"><h2>Apply this existing payment</h2><p class="py-help"><?=money($available)?> is available. Choose a purchase or contract for the same recipient.</p><div class="py-form-grid"><label class="py-field" for="target"><span>Purchase or contract</span><select class="form-select" id="target" name="target" required><option value="">Choose a record</option><?php foreach (payment_targets($payment['contact_id']?(int)$payment['contact_id']:null,true) as $target): ?><option value="<?=e($target['value'])?>"><?=e($target['label'])?></option><?php endforeach ?></select></label><label class="py-field" for="allocated"><span>Amount to apply (PKR)</span><input class="form-control" id="allocated" name="allocated" type="number" inputmode="decimal" min="0.01" step="0.01" max="<?=e($available)?>" value="<?=e($formError&&$action==='allocate'?($_POST['allocated']??''):'')?>" required></label></div><button class="btn btn-primary">Apply payment</button></form><?php endif ?>
        <details class="py-panel py-secondary"><summary>Receipt and cancellation</summary><form method="post" enctype="multipart/form-data" class="py-receipt"><?=csrf()?><input type="hidden" name="id" value="<?=$id?>"><label class="py-field" for="receipt"><span>Add receipt (max 5 MB)</span><input id="receipt" class="form-control" type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf" required></label><button class="btn btn-outline-primary" name="action" value="upload">Upload receipt</button></form><form method="post"><?=csrf()?><input type="hidden" name="id" value="<?=$id?>"><button name="action" value="cancel" class="btn btn-outline-danger" onclick="return confirm('Cancel this payment and release all allocations?')">Cancel payment</button></form></details>
    <?php endif ?>
</div>
<?php return; endif;
$prefill=(int)($_GET['expense_id']??0)?'expense:'.(int)$_GET['expense_id']:((int)($_GET['contract_id']??0)?'contract:'.(int)$_GET['contract_id']:'');
$targets=payment_targets();
$chosenTarget=$formError?(string)($_POST['target']??''):$prefill;
$requestedPurpose=in_array($_GET['purpose']??'', ['expense','contract','advance'],true)?$_GET['purpose']:'expense';
$purpose=$formError?(string)($_POST['purpose']??'expense'):($prefill!==''?(str_starts_with($prefill,'contract:')?'contract':'expense'):$requestedPurpose);
if (!in_array($purpose,['expense','contract','advance'],true)) $purpose='expense';
$chosenContact=$_POST['contact_id']??'';
if (!$formError) foreach ($targets as $target) if ($target['value']===$prefill) $chosenContact=$target['contact_id'];
if (isset($_GET['new']) || $prefill!=='' || $formError): ?>
<form method="post" enctype="multipart/form-data" class="py-panel" data-payment-create><?=csrf()?><input type="hidden" name="action" value="create"><h2>Record a payment</h2>
<fieldset class="py-purpose"><legend>What is this payment for?</legend><?php foreach (['expense'=>['Purchase payment','Pay an expense already recorded.'],'contract'=>['Contract payment','Advance or installment against a contract.'],'advance'=>['Advance','Pay a person now; link the cost later.']] as $value=>[$label,$hint]): ?><label><input type="radio" name="purpose" value="<?=$value?>" <?=$purpose===$value?'checked':''?>><span><strong><?=e($label)?></strong><small><?=e($hint)?></small></span></label><?php endforeach ?></fieldset>
<div class="py-form-grid">
    <label class="py-field py-full" for="target" data-payment-target><span data-payment-target-label><?=$purpose==='contract'?'Contract':'Purchase'?></span><select class="form-select" id="target" name="target" <?=$purpose==='advance'?'':'required'?>><option value="">Choose a record</option><?php foreach ($targets as $target): ?><option data-contact="<?=e($target['contact_id'])?>" value="<?=e($target['value'])?>" <?=$chosenTarget===$target['value']?'selected':''?>><?=e($target['label'])?></option><?php endforeach ?></select><small data-payment-target-hint>Only unpaid purchases or contracts with a payment balance appear.</small></label>
    <label class="py-field" for="contact"><span>Recipient</span><select class="form-select" id="contact" name="contact_id"><option value="">No supplier (purchase only)</option><?=options($contacts,$chosenContact)?></select><small>The recipient matches the selected purchase or contract.</small></label>
    <label class="py-field" for="date"><span>Payment date</span><input class="form-control" id="date" type="date" name="payment_date" value="<?=e($_POST['payment_date']??date('Y-m-d'))?>" required></label>
    <label class="py-field" for="amount"><span>Payment amount (PKR)</span><input class="form-control" id="amount" type="number" inputmode="decimal" name="amount" min="0.01" step="0.01" value="<?=e($_POST['amount']??'')?>" required></label>
    <label class="py-field" for="method"><span>Payment method</span><select class="form-select" id="method" name="method"><?php foreach ($methods as $method): ?><option <?=($_POST['method']??'Cash')===$method?'selected':''?>><?=e($method)?></option><?php endforeach ?></select></label>
    <label class="py-field" for="allocation-amount" data-payment-allocation><span>Amount to apply to this record (PKR)</span><input class="form-control" id="allocation-amount" name="allocated" type="number" min="0.01" step="0.01" inputmode="decimal" placeholder="Full payment if blank" value="<?=e($_POST['allocated']??'')?>"><small>Any remainder stays available as an advance.</small></label>
    <label class="py-field py-full" for="notes"><span>Notes</span><textarea class="form-control" id="notes" name="notes"><?=e($_POST['notes']??'')?></textarea></label>
    <label class="py-field py-full" for="attachment"><span>Receipt (optional, max 5 MB)</span><input class="form-control" id="attachment" type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf"></label>
</div><div class="py-actions"><button class="btn btn-primary">Save payment</button><a class="btn btn-outline-secondary" href="index.php?page=payments">Back to payments</a></div>
</form>
<?php else: ?><div class="py-actions"><a class="btn btn-primary" href="index.php?page=payments&new=1">Record a payment</a></div><?php endif;
$payments=rows('SELECT p.*,c.name contact_name,COALESCE(a.allocated,0) allocated,COALESCE(a.purchases,0) purchase_count,COALESCE(a.contracts,0) contract_count FROM payments p LEFT JOIN contacts c ON c.id=p.contact_id LEFT JOIN (SELECT payment_id,SUM(amount) allocated,SUM(expense_id IS NOT NULL) purchases,SUM(contract_id IS NOT NULL) contracts FROM payment_allocations GROUP BY payment_id) a ON a.payment_id=p.id WHERE p.project_id=? ORDER BY p.payment_date DESC,p.id DESC',[project_id()]); ?>
<section class="py-panel"><h2>Payment history</h2><div class="table-responsive" tabindex="0" role="region" aria-label="Payment history"><table class="table py-table"><thead><tr><th>Date</th><th>Recipient</th><th>Payment for</th><th>Method</th><th class="py-number">Amount</th><th class="py-number">Not yet applied</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach ($payments as $payment): ?><tr><td><?=e($payment['payment_date'])?></td><td><?=e($payment['contact_name']??'No supplier')?></td><td><?= $payment['status']!=='active'?'—':($payment['purchase_count']&&$payment['contract_count']?'Purchases & contracts':($payment['purchase_count']?'Purchase':($payment['contract_count']?'Contract':'Advance'))) ?></td><td><?=e($payment['method'])?></td><td class="py-number"><?=money($payment['amount'])?></td><td class="py-number"><?=money($payment['status']==='active'?sub($payment['amount'],$payment['allocated']):0)?></td><td><?=e(ucfirst($payment['status']))?></td><td><a href="index.php?page=payments&amp;id=<?=(int)$payment['id']?>">View / apply</a><?php if ($payment['status']==='active'): ?><a class="py-edit-link" href="index.php?page=payments&amp;id=<?=(int)$payment['id']?>&amp;edit=1">Edit</a><?php endif ?></td></tr><?php endforeach ?><?php if (!$payments): ?><tr><td colspan="8">No payments recorded for this project.</td></tr><?php endif ?></tbody></table></div></section>
</div>
