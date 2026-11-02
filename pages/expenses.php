<?php
$pid = project_id();
$action = $_POST['action'] ?? '';
$error = null;
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
                $lines[] = [$item['id'], $work, $category, $old && $old['item_id'] == $item['id'] ? $old['description'] : $item['name'], $quantity, $unit, $unitName, $rate, $rateUnit, $rateUnitName, $basis, $factor, $lineTotal, $fixed];
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
        $error = $ex instanceof PDOException ? 'Database operation failed. No changes were saved.' : $ex->getMessage();
    }
}
$viewId = (int)($_GET['view'] ?? $_GET['id'] ?? 0);
$editId = (int)($_GET['edit'] ?? 0);
$editing = $editId ? one('SELECT * FROM expenses WHERE id=? AND project_id=?', [$editId,$pid]) : null;
if ($editId && !$editing) throw new RuntimeException('Expense not found.');
$showForm = isset($_GET['add']) || $editing || ($error && $action === 'save');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><h1 class="h3 mb-0">Expenses</h1><a class="btn btn-primary" href="?page=expenses&add=1">Add expense</a></div>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?=e($error)?></div><?php endif; ?>
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
$form = $error && $action==='save' ? $_POST : ($editing ?: []);
$formLines = $error && $action==='save' ? ($_POST['lines'] ?? []) : ($editing ? rows('SELECT *,id line_id FROM expense_items WHERE expense_id=? ORDER BY id',[$editId]) : []);
if (!$formLines) $formLines = [['item_id'=>'','quantity'=>'1','rate'=>'0','rate_basis'=>'1']];
$renderLine = static function($line,$index) use($items,$units,$works,$categories) {
    $choices = static fn($records,$preserved) => array_values(array_filter($records, static fn($r) => $r['active'] || (string)$r['id'] === (string)$preserved));
    $historical = !empty($line['line_id']);
    $itemChoices = $choices($items, $historical ? ($line['item_id'] ?? null) : null);
    $workChoices = $choices($works, $historical ? ($line['work_type_id'] ?? null) : null);
    $categoryChoices = $choices($categories, $historical ? ($line['category_id'] ?? null) : null);
    $unitChoices = $choices($units, $historical ? ($line['unit_id'] ?? null) : null);
    $rateUnitChoices = $choices($units, $historical ? ($line['rate_unit_id'] ?? null) : null);
    ?>
<div class="expense-line card mb-3"><div class="card-body"><input type="hidden" data-field="line_id" name="lines[<?=$index?>][line_id]" value="<?=e($line['line_id']??'')?>"><div class="row g-2">
<div class="col-12 col-md-4"><label class="form-label">Item<select required class="form-select item-select" data-field="item_id" name="lines[<?=$index?>][item_id]"><option value="">Select item</option><?=options($itemChoices,$line['item_id']??null)?></select></label></div>
<div class="col-6 col-md-4"><label class="form-label">Work type<select required class="form-select" data-field="work_type_id" name="lines[<?=$index?>][work_type_id]"><?=options($workChoices,$line['work_type_id']??null)?></select></label></div>
<div class="col-6 col-md-4"><label class="form-label">Category<select required class="form-select" data-field="category_id" name="lines[<?=$index?>][category_id]"><?=options($categoryChoices,$line['category_id']??null)?></select></label></div>
<div class="col-12"><label class="form-check"><input class="form-check-input fixed-check" type="checkbox" data-field="is_fixed" name="lines[<?=$index?>][is_fixed]" value="1" <?=!empty($line['is_fixed'])?'checked':''?>> Fixed amount (no quantity)</label></div>
<div class="col-6 col-md-2 quantity-field"><label class="form-label">Quantity<input class="form-control" type="number" min="0.0001" step="0.0001" data-field="quantity" name="lines[<?=$index?>][quantity]" value="<?=e($line['quantity']??'1')?>"></label></div>
<div class="col-6 col-md-2 quantity-field"><label class="form-label">Quantity unit<select class="form-select" data-field="unit_id" name="lines[<?=$index?>][unit_id]"><?=options($unitChoices,$line['unit_id']??null)?></select></label></div>
<div class="col-6 col-md-2"><label class="form-label">Rate / fixed Rs.<input required class="form-control" type="number" min="0" step="0.01" data-field="rate" name="lines[<?=$index?>][rate]" value="<?=e($line['rate']??'0')?>"></label></div>
<div class="col-6 col-md-2 quantity-field"><label class="form-label">Rate per quantity<input class="form-control" type="number" min="0.0001" step="0.0001" data-field="rate_basis" name="lines[<?=$index?>][rate_basis]" value="<?=e($line['rate_basis']??'1')?>"></label></div>
<div class="col-6 col-md-2 quantity-field"><label class="form-label">Rate unit<select class="form-select" data-field="rate_unit_id" name="lines[<?=$index?>][rate_unit_id]"><?=options($rateUnitChoices,$line['rate_unit_id']??null)?></select></label></div>
<div class="col-6 col-md-2 d-flex align-items-end"><button class="btn btn-outline-danger remove-line mb-3" type="button">Remove line</button></div></div><div class="line-total fw-semibold" aria-live="polite"></div><input type="hidden" class="snapshot-factor" value="<?=e($line['conversion_factor']??'')?>" data-from="<?=e($line['unit_id']??'')?>" data-to="<?=e($line['rate_unit_id']??'')?>"></div></div>
<?php }; ?>
<div class="card mb-3"><div class="card-body"><h2 class="h5"><?=$editing?'Edit purchase #'.e($editId):'Quick purchase'?></h2>
<?php if($recentItems): ?><div class="mb-3">Recent items: <?php foreach($recentItems as $r): ?><button class="btn btn-sm btn-outline-secondary recent-item my-1" type="button" data-item="<?=e($r['item_id'])?>"><?=e($r['description'])?></button> <?php endforeach; ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" id="expense-form"><?=csrf()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e($form['id']??0)?>"><div class="row g-2 mb-3"><div class="col-12 col-md-4"><label class="form-label">Expense date<input required class="form-control" type="date" name="expense_date" value="<?=e($form['expense_date']??date('Y-m-d'))?>"></label></div><div class="col-12 col-md-8"><label class="form-label">Supplier (optional)<select name="contact_id" class="form-select"><option value="">Not specified</option><?=options($contacts,$form['contact_id']??null)?></select></label></div></div>
<div id="expense-lines"><?php foreach($formLines as $index=>$line)$renderLine($line,$index); ?></div><button class="btn btn-outline-secondary mb-3" id="add-line" type="button">Add another item</button><div class="h5" id="purchase-total" aria-live="polite"></div><p class="small text-muted">Preview only. Final totals calculated on server. Unit conversions require configured rules.</p>
<?php if(!$editing && empty($form['id'])): ?><div class="row g-2 mb-3"><div class="col-12 col-md-4"><label class="form-label">Payment<select class="form-select" name="payment_status" id="payment-status"><?php foreach(['unpaid'=>'Unpaid','full'=>'Full payment','partial'=>'Partial payment'] as $value=>$label): ?><option value="<?=$value?>" <?=($form['payment_status']??'unpaid')===$value?'selected':''?>><?=$label?></option><?php endforeach; ?></select></label></div><div class="col-6 col-md-4" id="partial-field"><label class="form-label">Paid Rs.<input class="form-control" type="number" min="0.01" step="0.01" name="payment_amount" value="<?=e($form['payment_amount']??'')?>"></label></div><div class="col-6 col-md-4"><label class="form-label">Payment method<select class="form-select" name="payment_method"><?php foreach(['Cash','Bank Transfer','JazzCash','Easypaisa','Cheque','Other'] as $method): ?><option <?=($form['payment_method']??'Cash')===$method?'selected':''?>><?=e($method)?></option><?php endforeach; ?></select></label></div></div><?php endif; ?>
<label class="form-label d-block">Notes<textarea class="form-control" name="notes" rows="2"><?=e($form['notes']??'')?></textarea></label><label class="form-label d-block">Bill attachment (optional)<input class="form-control" type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf"></label><div class="d-flex gap-2 flex-wrap"><button class="btn btn-primary">Save expense</button><button class="btn btn-outline-primary" name="another" value="1">Save and add another</button><a class="btn btn-outline-secondary" href="?page=expenses">Back</a></div></form></div></div>
<template id="line-template"><?php $renderLine(['quantity'=>'1','rate'=>'0','rate_basis'=>'1'],'NEW'); ?></template>
<script>window.expenseData=<?=json_encode(['items'=>$items,'lastRates'=>$lastRates,'conversions'=>rows('SELECT * FROM unit_conversions'),'activeWorkIds'=>array_column(array_filter($works,static fn($w)=>(bool)$w['active']),'id')], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;</script><script src="assets/expenses.js" defer></script>
<?php else:
$list = rows("SELECT ex.*,c.name contact_name,(SELECT COALESCE(SUM(a.amount),0) FROM payment_allocations a WHERE a.expense_id=ex.id) paid,(SELECT GROUP_CONCAT(ei.description SEPARATOR ', ') FROM expense_items ei WHERE ei.expense_id=ex.id) item_names FROM expenses ex LEFT JOIN contacts c ON c.id=ex.contact_id WHERE ex.project_id=? ORDER BY ex.expense_date DESC,ex.id DESC LIMIT 250",[$pid]); ?>
<div class="table-responsive"><table class="table table-striped"><thead><tr><th>Date / purchase</th><th>Items</th><th>Supplier</th><th>Total</th><th>Paid</th><th>Outstanding</th><th>Status</th></tr></thead><tbody><?php foreach($list as $row): ?><tr><td><a href="?page=expenses&view=<?=$row['id']?>"><?=e($row['expense_date'])?> #<?=$row['id']?></a></td><td><?=e($row['item_names'])?></td><td><?=e($row['contact_name']??'—')?></td><td><?=money($row['total'])?></td><td><?=money($row['paid'])?></td><td><?=money($row['status']==='active'?sub($row['total'],$row['paid']):'0')?></td><td><?=e($row['status'])?></td></tr><?php endforeach; ?><?php if(!$list): ?><tr><td colspan="7">No expenses yet. Add your first purchase.</td></tr><?php endif; ?></tbody></table></div><p class="small text-muted">Latest 250 purchases. Use Reports for complete history.</p>
<?php endif; ?>
