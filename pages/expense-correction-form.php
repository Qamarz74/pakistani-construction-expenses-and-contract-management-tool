<?php
$expensePayments=rows('SELECT p.id,p.payment_date,p.amount,a.amount applied,p.method FROM payment_allocations a JOIN payments p ON p.id=a.payment_id WHERE a.expense_id=? AND p.project_id=? AND p.status=? ORDER BY p.payment_date DESC,p.id DESC',[$viewId,$pid,'active']);
?>
<section class="mt-4" aria-labelledby="expense-payment-heading"><h3 class="h5" id="expense-payment-heading">Payments for this purchase</h3>
<p class="small text-muted">Edit money paid from its payment record. To use it elsewhere, open the payment, release its allocation and apply it to another purchase or contract.</p>
<?php if($expensePayments): ?><ul class="list-group mb-3"><?php foreach($expensePayments as $expensePayment): ?><li class="list-group-item d-flex flex-wrap justify-content-between gap-2"><span><?=e($expensePayment['payment_date'])?> · <?=money($expensePayment['applied'])?> applied · <?=e($expensePayment['method'])?></span><span><a href="?page=payments&amp;id=<?=(int)$expensePayment['id']?>">Payment #<?=(int)$expensePayment['id']?></a> · <a href="?page=payments&amp;id=<?=(int)$expensePayment['id']?>&amp;edit=1">Edit payment</a></span></li><?php endforeach ?></ul><?php else: ?><p class="small text-muted">No payment is currently applied to this purchase.</p><?php endif ?>
</section>
<?php if($expense['status']==='active'):
$correctionContracts=rows("SELECT c.id,c.title,n.name FROM contracts c JOIN contacts n ON n.id=c.contact_id WHERE c.project_id=? AND c.status<>'Cancelled' ORDER BY c.contract_date DESC,c.id DESC",[$pid]);
?>
<details class="expense-correction border p-3 mt-3" <?= $action==='move_contract'?'open':'' ?>><summary class="fw-semibold">Entered a contract payment as an expense? Correct it here</summary>
<p class="small text-muted mt-3">Move this entire purchase to a contract. Its original record and bill remain available in cancelled expenses. Existing payment amounts stay unchanged.</p>
<?php if($correctionContracts): ?><form method="post"><?=csrf()?><input type="hidden" name="action" value="move_contract"><input type="hidden" name="id" value="<?=$viewId?>">
<div class="row g-3"><div class="col-md-6"><label for="correction-contract" class="form-label">Destination contract</label><select class="form-select" name="contract_id" id="correction-contract" required><option value="">Select a contract</option><?php foreach($correctionContracts as $correctionContract): ?><option value="<?=(int)$correctionContract['id']?>" <?=(string)($_POST['contract_id']??'')===(string)$correctionContract['id']?'selected':''?>><?=e($correctionContract['title'].' · '.$correctionContract['name'])?></option><?php endforeach ?></select></div>
<div class="col-md-6"><label for="correction-mode" class="form-label">What does this entry represent?</label><select class="form-select" name="correction_mode" id="correction-mode" required><option value="">Choose how to correct it</option><option value="approved" <?=($_POST['correction_mode']??'')==='approved'?'selected':''?>>Completed work — move cost and payments</option><option value="advance" <?=cmp($paid,'0')<=0?'disabled':''?> <?=($_POST['correction_mode']??'')==='advance'?'selected':''?>>Advance only — move payments, remove expense cost</option></select></div></div>
<p class="small mt-3">Purchase cost: <strong><?=money($expense['total'])?></strong> · Existing payments to move: <strong><?=money($paid)?></strong>. Completed work keeps the same total project expense. Advance only removes this purchase cost until work is approved.</p>
<p class="small text-muted">Payments must match the contract contact. To split a payment across different records, use the payment’s Release and Apply actions instead.</p>
<button class="btn btn-outline-primary" type="submit">Move to selected contract</button>
</form><?php else: ?><p>Create a contract first, then return here to move this expense.</p><?php endif ?><a class="d-inline-block mt-3" href="?page=contracts&amp;add=1" target="_blank" rel="noopener">Add a contract ↗</a>
</details>
<?php endif ?>
