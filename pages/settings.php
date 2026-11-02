<?php
$tabs = ['projects'=>'Projects','work_types'=>'Work types','categories'=>'Categories','units'=>'Units','items'=>'Items','conversions'=>'Conversions'];
$tab = $_GET['tab'] ?? 'projects';
if (!isset($tabs[$tab])) $tab = 'projects';
$table = $tab === 'conversions' ? 'unit_conversions' : $tab;
$id = max(0, (int)($_GET['edit'] ?? 0));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $id = max(0, (int)($_POST['id'] ?? 0));
    $existingRecord = $id ? one("SELECT * FROM $table WHERE id=?", [$id]) : null;
    if ($id && !$existingRecord) throw new RuntimeException('Record not found.');
    if ($tab === 'conversions') {
        $from = (int)($_POST['from_unit_id'] ?? 0);
        $to = (int)($_POST['to_unit_id'] ?? 0);
        $factor = decimal($_POST['factor'] ?? '', 8, true);
        if (bccomp($factor, '9999999999.99999999', 8) > 0) throw new RuntimeException('Conversion factor is too large.');
        if ($from === $to || !one('SELECT id FROM units WHERE id=?', [$from]) || !one('SELECT id FROM units WHERE id=?', [$to])) throw new RuntimeException('Choose two different valid units.');
        db()->beginTransaction();
        try {
            // Lock this small shared graph while checking and updating it.
            $edges = rows('SELECT * FROM unit_conversions FOR UPDATE');
            $graph = [];
            foreach ($edges as $edge) {
                if ((int)$edge['id'] === $id) continue;
                $graph[$edge['from_unit_id']][] = [(int)$edge['to_unit_id'], $edge['factor']];
                $graph[$edge['to_unit_id']][] = [(int)$edge['from_unit_id'], bcdiv('1', $edge['factor'], 12)];
            }
            $queue = [[$from, '1']]; $seen = [$from=>true]; $existing = null;
            for ($i=0; $i<count($queue); $i++) {
                [$unit, $multiplier] = $queue[$i];
                if ($unit === $to) { $existing = $multiplier; break; }
                foreach ($graph[$unit] ?? [] as [$next, $ratio]) {
                    if (isset($seen[$next])) continue;
                    $seen[$next] = true; $queue[] = [$next, bcmul($multiplier, $ratio, 12)];
                }
            }
            if ($existing !== null && bccomp(rounded($existing, 8), $factor, 8) !== 0) throw new RuntimeException('Conversion conflicts with an existing conversion path.');
            if ($id) run('UPDATE unit_conversions SET from_unit_id=?,to_unit_id=?,factor=? WHERE id=?', [$from,$to,$factor,$id]);
            else run('INSERT INTO unit_conversions (from_unit_id,to_unit_id,factor) VALUES (?,?,?)', [$from,$to,$factor]);
            db()->commit();
        } catch (Throwable $error) { if (db()->inTransaction()) db()->rollBack(); throw $error; }
    } else {
        $name = trim($_POST['name'] ?? '');
        $max = ['projects'=>150,'work_types'=>120,'categories'=>120,'units'=>80,'items'=>120][$tab];
        if ($name === '' || mb_strlen($name) > $max) throw new RuntimeException("Name required (maximum $max characters).");
        $data = ['name'=>$name,'active'=>isset($_POST['active']) ? 1 : 0];
        if ($tab === 'projects') $data['description'] = trim($_POST['description'] ?? '');
        if ($tab === 'categories') {
            $kind = $_POST['kind'] ?? '';
            if (!in_array($kind, ['material','labour','other'], true)) throw new RuntimeException('Invalid category kind.');
            $data['kind'] = $kind;
        }
        if ($tab === 'items') {
            foreach (['category_id'=>'categories','unit_id'=>'units','rate_unit_id'=>'units'] as $field=>$ref) {
                $value = (int)($_POST[$field] ?? 0);
                $reference = one("SELECT id,active FROM $ref WHERE id=?", [$value]);
                if (!$reference || (!$reference['active'] && $value !== (int)($existingRecord[$field] ?? 0))) throw new RuntimeException('Choose an active category and units. Unchanged existing defaults can be retained.');
                $data[$field] = $value;
            }
            $data['rate_basis'] = decimal($_POST['rate_basis'] ?? '',4,true);
            $data['default_rate'] = decimal($_POST['default_rate'] ?? '',2);
            conversion((int)$data['unit_id'], (int)$data['rate_unit_id']);
        }
        if ($id) {
            $assign = implode(',',array_map(fn($key)=>"$key=?",array_keys($data)));
            run("UPDATE $table SET $assign WHERE id=?", [...array_values($data),$id]);
        } else {
            $columns = implode(',',array_keys($data));
            $marks = implode(',',array_fill(0,count($data),'?'));
            run("INSERT INTO $table ($columns) VALUES ($marks)",array_values($data));
        }
    }
    flash('Settings saved. Historical expense units and rates are preserved.');
    redirect('index.php?page=settings&tab='.$tab);
}
$edit = $id ? one("SELECT * FROM $table WHERE id=?",[$id]) : null;
if ($id && !$edit) throw new RuntimeException('Record not found.');
$records = rows("SELECT * FROM $table ORDER BY id DESC");
$units = rows('SELECT * FROM units ORDER BY active DESC,name');
$categories = rows('SELECT * FROM categories ORDER BY active DESC,name');
$unitNames = array_column($units,'name','id');
$categoryNames = array_column($categories,'name','id');
$itemCategories = array_values(array_filter($categories, fn($row) => $row['active'] || $row['id'] == ($edit['category_id'] ?? 0)));
$itemQuantityUnits = array_values(array_filter($units, fn($row) => $row['active'] || $row['id'] == ($edit['unit_id'] ?? 0)));
$itemRateUnits = array_values(array_filter($units, fn($row) => $row['active'] || $row['id'] == ($edit['rate_unit_id'] ?? 0)));
?>
<h1 class="h3">Settings</h1>
<ul class="nav nav-tabs mb-3 flex-wrap"><?php foreach ($tabs as $key=>$label): ?><li class="nav-item"><a class="nav-link <?= $tab===$key?'active':'' ?>" href="index.php?page=settings&amp;tab=<?= e($key) ?>"><?= e($label) ?></a></li><?php endforeach ?></ul>
<div class="row g-4"><div class="col-lg-5"><div class="card"><div class="card-body">
<h2 class="h5"><?= $edit?'Edit':'Add' ?> <?= e($tabs[$tab]) ?></h2>
<form method="post"><?= csrf() ?><input type="hidden" name="id" value="<?= e($edit['id']??0) ?>">
<?php if ($tab==='conversions'): ?>
<p class="small text-muted">1 source unit = factor × destination unit. Reciprocal conversions work automatically. Only add known capacities; trolley and dumper sizes vary.</p>
<label class="form-label" for="from_unit_id">Source unit</label><select class="form-select mb-3" id="from_unit_id" name="from_unit_id" required><?= options($units,$edit['from_unit_id']??null) ?></select>
<label class="form-label" for="to_unit_id">Destination unit</label><select class="form-select mb-3" id="to_unit_id" name="to_unit_id" required><?= options($units,$edit['to_unit_id']??null) ?></select>
<label class="form-label" for="factor">Conversion factor</label><input class="form-control mb-3" id="factor" name="factor" type="number" max="9999999999.99999999" min="0.00000001" step="0.00000001" value="<?= e($edit['factor']??'1') ?>" required>
<?php else: ?>
<label class="form-label" for="name">Name</label><input class="form-control mb-3" id="name" name="name" value="<?= e($edit['name']??'') ?>" required maxlength="150">
<?php if ($tab==='projects'): ?><label class="form-label" for="description">Description</label><textarea class="form-control mb-3" id="description" name="description"><?= e($edit['description']??'') ?></textarea><?php endif ?>
<?php if ($tab==='categories'): ?><label class="form-label" for="kind">Kind</label><select class="form-select mb-3" id="kind" name="kind"><?php foreach (['material'=>'Material','labour'=>'Labour','other'=>'Other'] as $key=>$label): ?><option value="<?= $key ?>" <?= ($edit['kind']??'material')===$key?'selected':'' ?>><?= $label ?></option><?php endforeach ?></select><?php endif ?>
<?php if ($tab==='items'): ?>
<label class="form-label" for="category_id">Category</label><select class="form-select mb-3" id="category_id" name="category_id" required><?= options($itemCategories,$edit['category_id']??null) ?></select>
<label class="form-label" for="unit_id">Default quantity unit</label><select class="form-select mb-3" id="unit_id" name="unit_id" required><?= options($itemQuantityUnits,$edit['unit_id']??null) ?></select>
<label class="form-label" for="rate_unit_id">Default rate unit</label><select class="form-select mb-3" id="rate_unit_id" name="rate_unit_id" required><?= options($itemRateUnits,$edit['rate_unit_id']??null) ?></select>
<label class="form-label" for="rate_basis">Rate per quantity (e.g. 1,000 bricks)</label><input class="form-control mb-3" id="rate_basis" name="rate_basis" type="number" min="0.0001" step="0.0001" value="<?= e($edit['rate_basis']??'1') ?>" required>
<label class="form-label" for="default_rate">Default rate (Rs.)</label><input class="form-control mb-3" id="default_rate" name="default_rate" type="number" min="0" step="0.01" value="<?= e($edit['default_rate']??'0') ?>" required>
<?php endif ?>
<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="active" id="active" <?= (!$edit||$edit['active'])?'checked':'' ?>><label class="form-check-label" for="active">Active</label></div>
<?php endif ?>
<button class="btn btn-primary">Save</button> <a class="btn btn-outline-secondary" href="index.php?page=settings&amp;tab=<?= e($tab) ?>">Clear</a>
</form></div></div></div>
<div class="col-lg-7"><div class="table-responsive"><table class="table table-striped"><thead><tr><th><?= $tab==='conversions'?'Conversion':'Name' ?></th><th>Details</th><th></th></tr></thead><tbody>
<?php foreach ($records as $record): ?><tr><td><?= e($tab==='conversions'?($unitNames[$record['from_unit_id']].' → '.$unitNames[$record['to_unit_id']]):$record['name']) ?></td><td>
<?php if ($tab==='conversions'): ?>1 = <?= e($record['factor']) ?>
<?php elseif ($tab==='items'): ?><?= e($categoryNames[$record['category_id']]) ?><br><?= money($record['default_rate']) ?> / <?= e($record['rate_basis'].' '.$unitNames[$record['rate_unit_id']]) ?><br><small>Quantity: <?= e($unitNames[$record['unit_id']]) ?></small>
<?php elseif ($tab==='categories'): ?><?= e(ucfirst($record['kind'])) ?>
<?php elseif ($tab==='projects'): ?><?= e($record['description']) ?>
<?php endif ?>
<?php if (isset($record['active'])&&!$record['active']): ?><span class="badge text-bg-secondary">Inactive</span><?php endif ?>
</td><td><a class="btn btn-sm btn-outline-primary" href="index.php?page=settings&amp;tab=<?= e($tab) ?>&amp;edit=<?= (int)$record['id'] ?>">Edit</a></td></tr><?php endforeach ?>
<?php if (!$records): ?><tr><td colspan="3">No records yet.</td></tr><?php endif ?>
</tbody></table></div></div></div>
