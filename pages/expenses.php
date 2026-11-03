<?php
$pid = project_id();
$action = $_POST['action'] ?? '';
$expenseError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    try {
        db()->beginTransaction();
        project_lock();
        $id = (int) ($_POST['id'] ?? 0);
        $existing = $id ? one('SELECT * FROM expenses WHERE id=? AND project_id=? FOR UPDATE', [$id, $pid]) : null;
        if ($id && !$existing) throw new RuntimeException('Expense not found.');
        if ($existing && $existing['status'] !== 'active') throw new RuntimeException('Cancelled expenses cannot be changed.');
        if (in_array($action, ['cancel','repeat'], true) && !$existing) throw new RuntimeException('Expense not found.');
        if ($action === 'cancel') {
            run('DELETE FROM payment_allocations WHERE expense_id=?', [$id]);
            run("UPDATE expenses SET status='cancelled' WHERE id=?", [$id]);
        } elseif ($action === 'repeat') {
            run('INSERT INTO expenses(project_id,contact_id,expense_date,notes,total) VALUES(?,?,?,?,?)', [$pid, $existing['contact_id'], date('Y-m-d'), $existing['notes'], $existing['total']]);
            $newId = (int) db()->lastInsertId();
            run('INSERT INTO expense_items(expense_id,item_id,work_type_id,category_id,description,quantity,unit_id,unit_name,rate,rate_unit_id,rate_unit_name,rate_basis,conversion_factor,total,is_fixed) SELECT ?,item_id,work_type_id,category_id,description,quantity,unit_id,unit_name,rate,rate_unit_id,rate_unit_name,rate_basis,conversion_factor,total,is_fixed FROM expense_items WHERE expense_id=?', [$newId, $id]);
            db()->commit();
            flash('New unpaid expense created from previous purchase.');
            redirect('?page=expenses&view=' . $newId);
        } elseif ($action === 'save') {
            $date = valid_date($_POST['expense_date'] ?? '');
            $contact = (int) ($_POST['contact_id'] ?? 0) ?: null;
            if ($contact && !one('SELECT id FROM contacts WHERE id=?', [$contact])) throw new RuntimeException('Select a valid supplier.');
            $allocated = $id ? one('SELECT COALESCE(SUM(amount),0) amount FROM payment_allocations WHERE expense_id=?', [$id])['amount'] : '0';
            if ($existing && cmp($allocated, '0') > 0 && $contact !== ($existing['contact_id'] === null ? null : (int)$existing['contact_id'])) throw new RuntimeException('Remove payment allocations before changing supplier.');
            $oldLines = [];
            if ($id) foreach (rows('SELECT * FROM expense_items WHERE expense_id=?', [$id]) as $old) $oldLines[$old['id']] = $old;
            $lines = [];
            $total = '0.00';
            $posted = $_POST['lines'] ?? [];
            if (!is_array($posted) || count($posted) < 1 || count($posted) > 100) throw new RuntimeException('Add between 1 and 100 expense lines.');
            foreach ($posted as $line) {
                $item = one('SELECT * FROM items WHERE id=?', [(int)($line['item_id'] ?? 0)]);
                if (!$item) throw new RuntimeException('Select an item for every line.');
                $old = $oldLines[(int)($line['line_id'] ?? 0)] ?? null;
                if (!$item['active'] && (!$old || $old['item_id'] != $item['id'])) throw new RuntimeException('Select an active item.');
                $work = (int)($line['work_type_id'] ?? 0);
                $category = (int)($line['category_id'] ?? 0);
                if (!one('SELECT id FROM work_types WHERE id=? AND (active=1 OR id=?)', [$work, $old['work_type_id'] ?? 0]) || !one('SELECT id FROM categories WHERE id=? AND (active=1 OR id=?)', [$category, $old['category_id'] ?? 0])) throw new RuntimeException('Select active work type and category.');
                $description = trim($line['description'] ?? ($old && $old['item_id'] == $item['id'] ? $old['description'] : ''));
                if (mb_strlen($description)>150) throw new RuntimeException('Item description must be 150 characters or fewer.');
                if ($description==='') $description = $item['name'];
                $fixed = isset($line['is_fixed']) ? 1 : 0;
                $rate = decimal($line['rate'] ?? '0');
                $unit = $rateUnit = $unitName = $rateUnitName = $quantity = null;
                $basis = '1';
                $factor = '1';
                if (!$fixed) {
                    $quantity = decimal($line['quantity'] ?? '', 4, true);
                    $basis = decimal($line['rate_basis'] ?? '', 4, true);
                    $unit = (int)($line['unit_id'] ?? 0);
                    $rateUnit = (int)($line['rate_unit_id'] ?? 0);
                    $u = one('SELECT * FROM units WHERE id=?', [$unit]);
                    $r = one('SELECT * FROM units WHERE id=?', [$rateUnit]);
                    if (!$u || !$r || (!$u['active'] && (!$old || $old['unit_id'] != $unit)) || (!$r['active'] && (!$old || $old['rate_unit_id'] != $rateUnit))) throw new RuntimeException('Select active quantity and rate units.');
                    $same = $old && !$old['is_fixed'] && $old['unit_id'] == $unit && $old['rate_unit_id'] == $rateUnit;
                    $factor = $same ? $old['conversion_factor'] : conversion($unit, $rateUnit);
                    $unitName = $same ? $old['unit_name'] : $u['name'];
                    $rateUnitName = $same ? $old['rate_unit_name'] : $r['name'];
                }
                $lineTotal = $fixed ? $rate : line_total($quantity, $rate, $basis, $factor);
                $total = add($total, $lineTotal);
                $lines[] = [$item['id'], $work, $category, $description, $quantity, $unit, $unitName, $rate, $rateUnit, $rateUnitName, $basis, $factor, $lineTotal, $fixed];
            }
            decimal($total);
            if (cmp($total, $allocated) < 0) throw new RuntimeException('Expense total cannot be less than allocated payments.');
            if ($id) {
                run('UPDATE expenses SET contact_id=?,expense_date=?,notes=?,total=? WHERE id=?', [$contact, $date, trim($_POST['notes'] ?? ''), $total, $id]);
                run('DELETE FROM expense_items WHERE expense_id=?', [$id]);
            } else {
                run('INSERT INTO expenses(project_id,contact_id,expense_date,notes,total) VALUES(?,?,?,?,?)', [$pid, $contact, $date, trim($_POST['notes'] ?? ''), $total]);
                $id = (int)db()->lastInsertId();
            }
            foreach ($lines as $line) run('INSERT INTO expense_items(expense_id,item_id,work_type_id,category_id,description,quantity,unit_id,unit_name,rate,rate_unit_id,rate_unit_name,rate_basis,conversion_factor,total,is_fixed) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge([$id], $line));
            $paymentStatus = $_POST['payment_status'] ?? 'unpaid';
            if (!$existing && $paymentStatus !== 'unpaid') {
                if (!in_array($paymentStatus, ['full','partial'], true)) throw new RuntimeException('Invalid payment choice.');
                $paid = $paymentStatus === 'full' ? $total : decimal($_POST['payment_amount'] ?? '', 2, true);
                if (cmp($paid, '0') <= 0 || cmp($paid, $total) > 0) throw new RuntimeException('Payment must be positive and no greater than purchase total.');
                $paymentId = create_payment($contact, $date, $paid, $_POST['payment_method'] ?? 'Cash', 'Payment for expense #' . $id);
                allocate_payment($paymentId, 'expense', $id, $paid);
            }
            upload_attachment('attachment', $id);
        } else throw new RuntimeException('Invalid action.');
        db()->commit();
        flash($action === 'cancel' ? 'Expense cancelled. Payments remain available for allocation.' : 'Expense saved.');
        redirect($action === 'save' && isset($_POST['another']) ? '?page=expenses&add=1' : '?page=expenses' . ($action === 'save' ? '&view=' . $id : ''));
    } catch (Throwable $ex) {
        if (db()->inTransaction()) db()->rollBack();
        cleanup_failed_uploads();
        if ($ex instanceof PDOException) error_log((string)$ex);
        $expenseError = $ex instanceof PDOException ? 'Database operation failed. No changes were saved.' : $ex->getMessage();
    }
}
$viewId = (int)($_GET['view'] ?? $_GET['id'] ?? 0);
$editId = (int)($_GET['edit'] ?? 0);
$editing = $editId ? one('SELECT * FROM expenses WHERE id=? AND project_id=?', [$editId,$pid]) : null;
if ($editId && !$editing) throw new RuntimeException('Expense not found.');
$showForm = isset($_GET['add']) || $editing || ($expenseError && $action === 'save');
?>

<?php if (!$showForm): ?><div class="ef-list-actions"><a class="btn btn-primary" href="?page=expenses&add=1">Add expense</a></div><?php endif; ?>
<?php if ($expenseError): ?><div class="alert alert-danger" role="alert" tabindex="-1"><?=e($expenseError)?></div><?php endif; ?>
<?php if ($viewId):
$expense = one('SELECT e.*, c.name contact_name FROM expenses e LEFT JOIN contacts c ON c.id=e.contact_id WHERE e.id=? AND e.project_id=?',[$viewId,$pid]);
if (!$expense) throw new RuntimeException('Expense not found.');
$detailLines = rows('SELECT ei.*, w.name work_name,c.name category_name FROM expense_items ei JOIN work_types w ON w.id=ei.work_type_id JOIN categories c ON c.id=ei.category_id WHERE expense_id=? ORDER BY ei.id',[$viewId]);
$paid = one('SELECT COALESCE(SUM(amount),0) amount FROM payment_allocations WHERE expense_id=?',[$viewId])['amount'];
?>
<div class="card mb-3"><div class="card-body"><h2 class="h5">Purchase #<?=e($viewId)?> · <?=e($expense['expense_date'])?> <span class="badge text-bg-secondary"><?=e($expense['status'])?></span></h2><p>Supplier: <?=e($expense['contact_name'] ?? 'Not specified')?></p><div class="table-responsive"><table class="table"><thead><tr><th>Item / work</th><th>Quantity</th><th>Original rate</th><th>Amount</th></tr></thead><tbody><?php foreach($detailLines as $line): ?><tr><td><?=e($line['description'])?><small class="d-block text-muted"><?=e($line['work_name'])?> · <?=e($line['category_name'])?></small></td><td><?=$line['is_fixed'] ? 'Fixed charge' : e($line['quantity'].' '.$line['unit_name'])?></td><td><?=money($line['rate'])?><?=$line['is_fixed'] ? '' : ' / '.e($line['rate_basis'].' '.$line['rate_unit_name'])?></td><td><?=money($line['total'])?></td></tr><?php endforeach; ?></tbody></table></div><p><strong>Total: <?=money($expense['total'])?></strong> · Paid: <?=money($paid)?> · Outstanding: <?=money($expense['status']==='active' ? sub($expense['total'],$paid) : '0')?></p><p><?=nl2br(e($expense['notes']))?></p><?=attachments_html($viewId)?>
<?php if($expense['status']==='active'): ?><div class="d-flex gap-2 flex-wrap"><a class="btn btn-outline-primary" href="?page=expenses&edit=<?=$viewId?>">Edit</a><a class="btn btn-outline-primary" href="?page=payments&expense_id=<?=$viewId?>">Record / allocate payment</a><form method="post"><?=csrf()?><input type="hidden" name="id" value="<?=$viewId?>"><button class="btn btn-outline-secondary" name="action" value="repeat">Repeat today (unpaid)</button></form><form method="post" onsubmit="return confirm('Cancel this expense? Its payments will become unallocated.')"><?=csrf()?><input type="hidden" name="id" value="<?=$viewId?>"><button class="btn btn-outline-danger" name="action" value="cancel">Cancel</button></form></div><?php endif; ?>
<h3 class="h6 mt-3">Allocated payments</h3><?php foreach(rows('SELECT p.id,p.payment_date,p.method,a.amount FROM payment_allocations a JOIN payments p ON p.id=a.payment_id WHERE a.expense_id=? ORDER BY p.payment_date DESC',[$viewId]) as $p): ?><div><?=e($p['payment_date'])?> · <?=e($p['method'])?> · <a href="?page=payments&view=<?=$p['id']?>"><?=money($p['amount'])?></a></div><?php endforeach; ?></div></div>
<?php endif; ?>
<?php if ($showForm):
$items = rows('SELECT * FROM items ORDER BY name');
$units = rows('SELECT * FROM units ORDER BY name');
$works = rows('SELECT * FROM work_types ORDER BY name');
$categories = rows('SELECT * FROM categories ORDER BY name');
$contacts = rows('SELECT * FROM contacts ORDER BY name');
$recent = rows("SELECT ei.* FROM expense_items ei JOIN expenses ex ON ex.id=ei.expense_id WHERE ex.project_id=? AND ex.status='active' ORDER BY ex.expense_date DESC,ex.id DESC,ei.id DESC LIMIT 150",[$pid]);
$lastRates = []; $recentItems = [];
$activeItemIds = array_column(array_filter($items,static fn($item)=>(bool)$item['active']),'id');
foreach ($recent as $r) if (in_array($r['item_id'],$activeItemIds) && !isset($lastRates[$r['item_id']])) { $lastRates[$r['item_id']]=$r; if(count($recentItems)<8)$recentItems[]=$r; }
$form = $expenseError && $action==='save' ? $_POST : ($editing ?: []);
$formLines = $expenseError && $action==='save' ? ($_POST['lines'] ?? []) : ($editing ? rows('SELECT *,id line_id FROM expense_items WHERE expense_id=? ORDER BY id',[$editId]) : []);
if (!$formLines) $formLines = [['item_id'=>'','quantity'=>'1','rate'=>'','rate_basis'=>'1']];
// Restore historical metadata from the database, including after an invalid submission.
$snapshots = [];
$editingExpenseId = (int)($form['id'] ?? 0);
$editingExpense = $editingExpenseId ? one('SELECT id FROM expenses WHERE id=? AND project_id=?',[$editingExpenseId,$pid]) : null;
if ($editingExpenseId && !$editingExpense) throw new RuntimeException('Expense not found in this project.');
if ($editingExpense) {
    foreach (rows('SELECT * FROM expense_items WHERE expense_id=?',[$editingExpenseId]) as $snapshot) $snapshots[$snapshot['id']] = $snapshot;
}
$alreadyPaid = $editingExpenseId ? one('SELECT COALESCE(SUM(amount),0) amount FROM payment_allocations WHERE expense_id=?',[$editingExpenseId])['amount'] : '0';
$renderLine = static function($line,$index) use($items,$units,$works,$categories,$snapshots) {
    $snapshot = $snapshots[(int)($line['line_id'] ?? 0)] ?? null;
    $choices = static fn($records,$preserved) => array_values(array_filter($records, static fn($r) => $r['active'] || (string)$r['id'] === (string)$preserved));
    $itemChoices = $choices($items, $snapshot['item_id'] ?? null);
    $workChoices = $choices($works, $snapshot['work_type_id'] ?? null);
    $categoryChoices = $choices($categories, $snapshot['category_id'] ?? null);
    $unitChoices = $choices($units, $snapshot['unit_id'] ?? null);
    $rateUnitChoices = $choices($units, $snapshot['rate_unit_id'] ?? null);
    $lineUnits = $units;
    if ($snapshot) foreach ($lineUnits as &$u) {
        if ($u['id']==$snapshot['unit_id']) $u['name']=$snapshot['unit_name'];
        if ($u['id']==$snapshot['rate_unit_id']) $u['name']=$snapshot['rate_unit_name'];
    }
    unset($u);
    $unitChoices = $choices($lineUnits,$snapshot['unit_id']??null);
    $rateUnitChoices = $choices($lineUnits,$snapshot['rate_unit_id']??null);
    $uid = 'ef-line-' . $index;
    $fixed = !empty($line['is_fixed']);
    $basis = (float)($line['rate_basis'] ?? 1);
    $rateUnit = '';
    foreach ($rateUnitChoices as $unitOption) if ($unitOption['id']==($line['rate_unit_id']??0)) $rateUnit=$unitOption['name'];
    $quotedLabel = $fixed ? 'Amount' : ($rateUnit ? 'Price per ' . ($basis==1?'':rtrim(rtrim(number_format($basis,4,'.',','),'0'),'.').' ') . $rateUnit : 'Supplier’s quoted price');
    ?>
<article class="ef-line expense-line" aria-labelledby="<?=$uid?>-title">
    <div class="ef-line-head"><h3 id="<?=$uid?>-title"><span class="ef-line-number"><?=is_numeric($index)?(int)$index+1:'1'?></span> <span class="ef-line-title">Purchase item</span></h3><button class="ef-remove remove-line" type="button" aria-label="Remove this item">Remove</button></div>
    <input type="hidden" data-field="line_id" name="lines[<?=$index?>][line_id]" value="<?=e($line['line_id']??'')?>">
    <div class="ef-line-inputs">
        <label class="ef-field ef-item-field" for="<?=$uid?>-item"><span>Item</span><select required id="<?=$uid?>-item" class="form-select item-select" data-field="item_id" name="lines[<?=$index?>][item_id]"><option value="">Choose material or service</option><?=options($itemChoices,$line['item_id']??null)?></select></label>
        <div class="ef-quantity-group quantity-field"><label class="ef-field" for="<?=$uid?>-quantity"><span>Quantity</span><input class="form-control" id="<?=$uid?>-quantity" type="number" inputmode="decimal" min="0.0001" step="0.0001" data-field="quantity" name="lines[<?=$index?>][quantity]" value="<?=e($line['quantity']??'1')?>"></label><label class="ef-field" for="<?=$uid?>-unit"><span>Unit</span><select id="<?=$uid?>-unit" class="form-select" data-field="unit_id" name="lines[<?=$index?>][unit_id]"><?=options($unitChoices,$line['unit_id']??null)?></select></label></div>
        <label class="ef-field ef-price-field" for="<?=$uid?>-rate"><span class="ef-price-label"><?=e($quotedLabel)?></span><div class="ef-money-input"><span aria-hidden="true">Rs.</span><input required class="form-control" id="<?=$uid?>-rate" type="number" inputmode="decimal" min="0" step="0.01" data-field="rate" name="lines[<?=$index?>][rate]" value="<?=e($line['rate']??'')?>"></div><small class="ef-rate-source"></small></label>
        <label class="ef-field ef-description-field" for="<?=$uid?>-description"><span>What did you buy? <small>(optional)</small></span><input class="form-control" id="<?=$uid?>-description" data-field="description" name="lines[<?=$index?>][description]" maxlength="150" placeholder="e.g. Measurement tape, 10-foot pipe" value="<?=e($line['description']??'')?>"></label>
        <label class="ef-field ef-work-field" for="<?=$uid?>-work"><span>Work type</span><select required id="<?=$uid?>-work" class="form-select" data-field="work_type_id" name="lines[<?=$index?>][work_type_id]"><?=options($workChoices,$line['work_type_id']??null)?></select></label>
    </div>
    <div class="ef-line-meta"><label class="ef-check"><input class="form-check-input fixed-check" type="checkbox" data-field="is_fixed" name="lines[<?=$index?>][is_fixed]" value="1" <?=$fixed?'checked':''?>> Fixed amount / service charge</label><details class="ef-options"><summary>More options</summary><div class="ef-options-content"><p class="ef-help">Change the supplier’s pricing basis only when their quote uses a different unit or quantity.</p><div class="ef-advanced-grid"><label class="ef-field" for="<?=$uid?>-basis"><span>Price covers this quantity</span><input id="<?=$uid?>-basis" class="form-control" type="number" inputmode="decimal" min="0.0001" step="0.0001" data-field="rate_basis" name="lines[<?=$index?>][rate_basis]" value="<?=e($line['rate_basis']??'1')?>"></label><label class="ef-field" for="<?=$uid?>-rate-unit"><span>Quoted price unit</span><select id="<?=$uid?>-rate-unit" class="form-select" data-field="rate_unit_id" name="lines[<?=$index?>][rate_unit_id]"><?=options($rateUnitChoices,$line['rate_unit_id']??null)?></select></label><label class="ef-field" for="<?=$uid?>-category"><span>Category</span><select required id="<?=$uid?>-category" class="form-select" data-field="category_id" name="lines[<?=$index?>][category_id]"><?=options($categoryChoices,$line['category_id']??null)?></select></label></div><label class="ef-check ef-all-units"><input class="form-check-input" type="checkbox" data-all-units> Show all quantity units</label><p class="ef-help">Only configured conversions are accepted. Trolley and dumper sizes are never assumed.</p></div></details></div>
    <div class="ef-line-result" aria-live="polite"><span class="ef-equation">Choose an item to see the calculation.</span><div><span class="ef-total-caption">Line total</span><strong class="line-total">—</strong></div></div>
    <p class="ef-line-error" role="status" hidden></p>
    <input type="hidden" class="snapshot-factor" value="<?=e($snapshot['conversion_factor']??'')?>" data-from="<?=e($snapshot['unit_id']??'')?>" data-to="<?=e($snapshot['rate_unit_id']??'')?>">
</article>
<?php }; ?>
<form method="post" enctype="multipart/form-data" id="expense-form" class="ef-form" data-paid="<?=e($alreadyPaid)?>"><?=csrf()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e($form['id']??0)?>">
<div class="ef-main">
    <section class="ef-items" aria-labelledby="ef-items-title"><div class="ef-section-heading"><div><span class="ef-eyebrow">01 · PURCHASE</span><h2 id="ef-items-title">What did you buy?</h2></div><span id="ef-item-count" class="ef-item-count">1 item</span></div>
    <?php if($recentItems): ?><div class="ef-recent"><span>Recent</span><div><?php foreach($recentItems as $r): ?><button class="ef-recent-button recent-item" type="button" data-item="<?=e($r['item_id'])?>"><?=e($r['description'])?></button><?php endforeach; ?></div></div><?php endif; ?>
    <div id="expense-lines"><?php foreach($formLines as $index=>$line)$renderLine($line,$index); ?></div><button class="ef-add-line" id="add-line" type="button"><span aria-hidden="true">＋</span> Add another item</button></section>
    <section class="ef-panel" aria-labelledby="ef-purchase-info"><div class="ef-section-heading"><div><span class="ef-eyebrow">02 · DETAILS</span><h2 id="ef-purchase-info">Supplier & date</h2></div></div><div class="ef-info-grid"><label class="ef-field" for="ef-supplier"><span>Supplier <small>optional</small></span><select id="ef-supplier" name="contact_id" class="form-select"><option value="">No supplier selected</option><?=options($contacts,$form['contact_id']??null)?></select></label><label class="ef-field" for="ef-date"><span>Purchase date</span><input id="ef-date" required class="form-control" type="date" name="expense_date" value="<?=e($form['expense_date']??date('Y-m-d'))?>"></label></div>
    <details class="ef-optional" <?=!empty($form['notes'])?'open':''?>><summary>Notes & bill attachment <span>optional</span></summary><div class="ef-optional-content"><label class="ef-field" for="ef-notes"><span>Notes</span><textarea id="ef-notes" class="form-control" name="notes" rows="3" placeholder="Delivery, invoice number, or anything to remember"><?=e($form['notes']??'')?></textarea></label><label class="ef-field" for="ef-attachment"><span>Attach bill or receipt</span><input id="ef-attachment" class="form-control" type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf"><small>JPG, PNG, WebP or PDF · up to 5 MB</small></label><?php if($expenseError): ?><p class="ef-help">If you selected a bill, choose it again before saving.</p><?php endif; ?></div></details></section>
    <a class="ef-back" href="?page=expenses">← Back to expenses</a>
</div>
<aside class="ef-summary-column"><section class="ef-summary" aria-labelledby="ef-summary-title"><span class="ef-eyebrow">PURCHASE SUMMARY</span><h2 id="ef-summary-title" class="ef-sr-only">Purchase summary</h2><div id="purchase-total" class="ef-grand-total" aria-live="polite">Rs. 0.00</div><p class="ef-summary-caption" id="ef-summary-caption">Total for this purchase</p><div class="ef-summary-divider"></div>
<?php if(!$editingExpenseId): ?><fieldset class="ef-payment"><legend>Payment</legend><div class="ef-payment-choices"><?php foreach(['unpaid'=>'Unpaid','full'=>'Paid in full','partial'=>'Part paid'] as $value=>$label): ?><label><input type="radio" name="payment_status" value="<?=$value?>" <?=($form['payment_status']??'unpaid')===$value?'checked':''?>><span><?=$label?></span></label><?php endforeach; ?></div></fieldset><div id="partial-field" class="ef-payment-field"><label class="ef-field" for="ef-payment-amount"><span>Amount paid</span><div class="ef-money-input"><span aria-hidden="true">Rs.</span><input id="ef-payment-amount" class="form-control" type="number" inputmode="decimal" min="0.01" step="0.01" name="payment_amount" value="<?=e($form['payment_amount']??'')?>"></div></label></div><div id="ef-method-field" class="ef-payment-field"><label class="ef-field" for="ef-method"><span>Payment method</span><select id="ef-method" class="form-select" name="payment_method"><?php foreach(['Cash','Bank Transfer','JazzCash','Easypaisa','Cheque','Other'] as $method): ?><option <?=($form['payment_method']??'Cash')===$method?'selected':''?>><?=e($method)?></option><?php endforeach; ?></select></label></div><?php else: ?><p class="ef-help">Payments already allocated to this purchase</p><div class="ef-summary-row"><span>Paid so far</span><strong><?=money($alreadyPaid)?></strong></div><a class="ef-manage-payments" href="?page=payments&expense_id=<?=$editingExpenseId?>">Manage payments ↗</a><?php endif; ?>
<div class="ef-summary-row ef-outstanding"><span>Remaining to pay</span><strong id="ef-remaining" aria-live="polite">Rs. 0.00</strong></div><p id="ef-payment-note" class="ef-help">You can record a payment later.</p><div id="ef-form-error" class="ef-summary-error" role="status" hidden></div><div class="ef-save-actions"><button class="btn btn-primary" type="submit">Save expense <span aria-hidden="true">→</span></button><button class="btn btn-outline-secondary" type="submit" name="another" value="1">Save & add another</button></div><p class="ef-summary-footnote">Recorded in your selected project.</p></section></aside>
</form>
<template id="line-template"><?php $renderLine(['quantity'=>'1','rate'=>'','rate_basis'=>'1'],'NEW'); ?></template>
<script>window.expenseData=<?=json_encode(['items'=>$items,'lastRates'=>$lastRates,'units'=>$units,'conversions'=>rows('SELECT * FROM unit_conversions'),'activeWorkIds'=>array_column(array_filter($works,static fn($w)=>(bool)$w['active']),'id')], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;</script><script src="assets/expenses.js?v=<?=filemtime(__DIR__ . '/../assets/expenses.js')?>" defer></script>
<?php else:
$list = rows("SELECT ex.*,c.name contact_name,(SELECT COALESCE(SUM(a.amount),0) FROM payment_allocations a WHERE a.expense_id=ex.id) paid,(SELECT GROUP_CONCAT(ei.description SEPARATOR ', ') FROM expense_items ei WHERE ei.expense_id=ex.id) item_names FROM expenses ex LEFT JOIN contacts c ON c.id=ex.contact_id WHERE ex.project_id=? ORDER BY ex.expense_date DESC,ex.id DESC LIMIT 250",[$pid]); ?>
<div class="table-responsive"><table class="table table-striped"><thead><tr><th>Date / purchase</th><th>Items</th><th>Supplier</th><th>Total</th><th>Paid</th><th>Outstanding</th><th>Status</th></tr></thead><tbody><?php foreach($list as $row): ?><tr><td><a href="?page=expenses&view=<?=$row['id']?>"><?=e($row['expense_date'])?> #<?=$row['id']?></a></td><td><?=e($row['item_names'])?></td><td><?=e($row['contact_name']??'—')?></td><td><?=money($row['total'])?></td><td><?=money($row['paid'])?></td><td><?=money($row['status']==='active'?sub($row['total'],$row['paid']):'0')?></td><td><?=e($row['status'])?></td></tr><?php endforeach; ?><?php if(!$list): ?><tr><td colspan="7">No expenses yet. Add your first purchase.</td></tr><?php endif; ?></tbody></table></div><p class="small text-muted">Latest 250 purchases. Use Reports for complete history.</p>
<?php endif; ?>
