<?php
$pid = project_id();
$purchase = one("SELECT COALESCE(SUM(total),0) amount FROM expenses WHERE project_id=? AND status='active'", [$pid])['amount'];
$work = one("SELECT COALESCE(SUM(w.amount),0) amount FROM contract_work_costs w JOIN contracts c ON c.id=w.contract_id WHERE c.project_id=? AND w.status='active'", [$pid])['amount'];
$paid = one("SELECT COALESCE(SUM(amount),0) amount FROM payments WHERE project_id=? AND status='active'", [$pid])['amount'];
$purchaseDue = one("SELECT COALESCE(SUM(GREATEST(0,e.total-COALESCE(a.amount,0))),0) amount FROM expenses e LEFT JOIN (SELECT a.expense_id,SUM(a.amount) amount FROM payment_allocations a JOIN payments p ON p.id=a.payment_id AND p.status='active' GROUP BY a.expense_id) a ON a.expense_id=e.id WHERE e.project_id=? AND e.status='active'",[$pid])['amount'];
$contractBalance = one("SELECT COALESCE(SUM(GREATEST(0,COALESCE(w.amount,0)-COALESCE(a.amount,0))),0) due, COALESCE(SUM(GREATEST(0,COALESCE(a.amount,0)-COALESCE(w.amount,0))),0) prepaid FROM contracts c LEFT JOIN (SELECT contract_id,SUM(amount) amount FROM contract_work_costs WHERE status='active' GROUP BY contract_id) w ON w.contract_id=c.id LEFT JOIN (SELECT a.contract_id,SUM(a.amount) amount FROM payment_allocations a JOIN payments p ON p.id=a.payment_id AND p.status='active' GROUP BY a.contract_id) a ON a.contract_id=c.id WHERE c.project_id=?",[$pid]);
$advance = one("SELECT COALESCE(SUM(GREATEST(0,p.amount-COALESCE(a.amount,0))),0) amount FROM payments p LEFT JOIN (SELECT payment_id,SUM(amount) amount FROM payment_allocations GROUP BY payment_id) a ON a.payment_id=p.id WHERE p.project_id=? AND p.status='active'",[$pid])['amount'];
$kinds = rows("SELECT c.kind, SUM(l.total) amount FROM expense_items l JOIN expenses e ON e.id=l.expense_id JOIN categories c ON c.id=l.category_id WHERE e.project_id=? AND e.status='active' GROUP BY c.kind",[$pid]);
$kindTotals = array_column($kinds,'amount','kind');
$monthStart = date('Y-m-01'); $monthEnd = date('Y-m-t');
$month = one("SELECT (SELECT COALESCE(SUM(total),0) FROM expenses WHERE project_id=? AND status='active' AND expense_date BETWEEN ? AND ?) + (SELECT COALESCE(SUM(w.amount),0) FROM contract_work_costs w JOIN contracts c ON c.id=w.contract_id WHERE c.project_id=? AND w.status='active' AND w.cost_date BETWEEN ? AND ?) amount",[$pid,$monthStart,$monthEnd,$pid,$monthStart,$monthEnd])['amount'];
$recorded = bcadd($purchase,$work,2);
$outstanding = bcadd($purchaseDue,$contractBalance['due'],2);
$labour = bcadd($kindTotals['labour']??'0',$work,2);
$recent = rows("SELECT e.id,e.id sort_id,e.expense_date date,COALESCE(l.title,'Purchase') title,COALESCE(l.item_count,0) item_count,c.name,e.total amount,'expenses' page,CASE WHEN COALESCE(a.paid,0)>=e.total THEN 'Paid' WHEN COALESCE(a.paid,0)>0 THEN 'Part paid' ELSE 'Unpaid' END record_state FROM expenses e LEFT JOIN contacts c ON c.id=e.contact_id LEFT JOIN (SELECT expense_id,SUBSTRING_INDEX(GROUP_CONCAT(description ORDER BY id SEPARATOR ' · '),' · ',2) title,COUNT(*) item_count FROM expense_items GROUP BY expense_id) l ON l.expense_id=e.id LEFT JOIN (SELECT a.expense_id,SUM(a.amount) paid FROM payment_allocations a JOIN payments p ON p.id=a.payment_id AND p.status='active' GROUP BY a.expense_id) a ON a.expense_id=e.id WHERE e.project_id=? AND e.status='active' UNION ALL SELECT c.id,w.id sort_id,w.cost_date date,c.title,0,n.name,w.amount,'contracts' page,'Approved work' record_state FROM contract_work_costs w JOIN contracts c ON c.id=w.contract_id JOIN contacts n ON n.id=c.contact_id WHERE c.project_id=? AND w.status='active' ORDER BY date DESC,sort_id DESC LIMIT 6",[$pid,$pid]);
$recentPayments = rows("SELECT p.*,c.name,COALESCE(a.allocated,0) allocated FROM payments p LEFT JOIN contacts c ON c.id=p.contact_id LEFT JOIN (SELECT payment_id,SUM(amount) allocated FROM payment_allocations GROUP BY payment_id) a ON a.payment_id=p.id WHERE p.project_id=? AND p.status='active' ORDER BY p.payment_date DESC,p.id DESC LIMIT 6",[$pid]);
$categoryBreakdown = rows("SELECT c.name,SUM(l.total) amount FROM expense_items l JOIN expenses e ON e.id=l.expense_id JOIN categories c ON c.id=l.category_id WHERE e.project_id=? AND e.status='active' GROUP BY c.id,c.name ORDER BY amount DESC",[$pid]);
$workBreakdown = rows("SELECT name,SUM(amount) amount FROM (SELECT t.name,SUM(l.total) amount FROM expense_items l JOIN expenses e ON e.id=l.expense_id JOIN work_types t ON t.id=l.work_type_id WHERE e.project_id=? AND e.status='active' GROUP BY t.id,t.name UNION ALL SELECT t.name,SUM(w.amount) amount FROM contract_work_costs w JOIN contracts c ON c.id=w.contract_id JOIN work_types t ON t.id=c.work_type_id WHERE c.project_id=? AND w.status='active' GROUP BY t.id,t.name) x GROUP BY name ORDER BY amount DESC",[$pid,$pid]);
?>
<div class="db-dashboard">
<section class="db-overview" aria-labelledby="db-recorded-heading">
    <div class="db-overview-main">
        <div class="db-overview-top"><span class="db-eyebrow">PROJECT OVERVIEW</span><a class="btn btn-primary db-add" href="index.php?page=expenses&add=1"><span aria-hidden="true">+</span> Add expense</a></div>
        <h2 id="db-recorded-heading" class="db-total-label">Total expenses</h2>
        <p class="db-total"><?=money($recorded)?></p>
        <p class="db-explanation">Cost of purchases and approved Theka work.</p>
    </div>
    <dl class="db-balances">
        <div><dt>Total paid out</dt><dd><?=money($paid)?></dd><p>All recorded payments, including advances.</p></div>
        <div><dt>Still owed</dt><dd class="db-owed"><?=money($outstanding)?></dd><p>Unpaid purchases and approved work.</p></div>
    </dl>
    <div class="db-advance-row">
        <div class="db-advance-main"><div><strong><?php if(bccomp($advance,'0',2)>0): ?><?=money($advance)?> advance available<?php else: ?>No unapplied advances<?php endif ?></strong><?php if(bccomp($advance,'0',2)>0): ?><p>Paid but not yet linked to a purchase or Theka.</p><?php endif ?></div><a class="db-text-link" href="index.php?page=payments">View payments <span aria-hidden="true">↗</span></a></div>
        <?php if(bccomp($contractBalance['prepaid'],'0',2)>0): ?><p class="db-contract-prepaid">Contract prepayments: <strong><?=money($contractBalance['prepaid'])?></strong> · Applied to a Theka, ahead of its approved work.</p><?php endif ?>
    </div>
</section>
<section class="db-cost-overview" aria-label="Cost overview">
    <div><span><?=e(date('F'))?> expenses</span><strong><?=money($month)?></strong><small>Recorded this month</small></div>
    <div><span>Materials</span><strong><?=money($kindTotals['material']??'0')?></strong><small>Materials purchased</small></div>
    <div><span>Labour &amp; Theka</span><strong><?=money($labour)?></strong><small>Approved work and labour</small></div>
</section>
<div class="db-activity-grid">
<section class="db-activity" aria-labelledby="db-expenses-heading">
    <div class="db-section-heading"><div><span class="db-eyebrow">YOUR RECORDS</span><h2 id="db-expenses-heading">Recent expenses</h2></div><a class="db-text-link" href="index.php?page=expenses">View all<span class="db-sr-only"> expenses</span> <span aria-hidden="true">↗</span></a></div>
    <?php if($recent): ?><ul class="db-records">
    <?php foreach($recent as $r): $title=$r['title'].((int)$r['item_count']>2?' +'.((int)$r['item_count']-2).' more':''); ?>
        <li class="db-record"><div class="db-record-main"><a class="db-record-title" href="index.php?page=<?=e($r['page'])?>&view=<?=(int)$r['id']?>"><?=e($title)?><span class="db-sr-only">, <?=$r['page']==='expenses'?'purchase':'contract'?> #<?=(int)$r['id']?></span></a><p class="db-record-subtitle"><?=e($r['name']??'Supplier not specified')?></p><span class="db-record-state"><?=e($r['record_state'])?></span></div><div class="db-record-side"><strong><?=money($r['amount'])?></strong><time datetime="<?=e($r['date'])?>"><?=e(date('j M Y',strtotime($r['date'])))?></time></div></li>
    <?php endforeach ?></ul>
    <?php else: ?><div class="db-empty"><span class="db-empty-mark" aria-hidden="true">↗</span><h3>Your first expense starts here</h3><p>Add a purchase using the button above. Materials and approved Theka work will appear here as you record them.</p></div><?php endif ?>
</section>
<section class="db-activity" aria-labelledby="db-payments-heading">
    <div class="db-section-heading"><div><span class="db-eyebrow">MONEY OUT</span><h2 id="db-payments-heading">Recent payments</h2></div><a class="db-text-link" href="index.php?page=payments">View all<span class="db-sr-only"> payments</span> <span aria-hidden="true">↗</span></a></div>
    <?php if($recentPayments): ?><ul class="db-records">
    <?php foreach($recentPayments as $r): $available=bcsub($r['amount'],$r['allocated'],2); ?>
        <li class="db-record"><div class="db-record-main"><a class="db-record-title" href="index.php?page=payments&view=<?=(int)$r['id']?>"><?=e($r['name']??'Recipient not specified')?><span class="db-sr-only">, payment #<?=(int)$r['id']?></span></a><p class="db-record-subtitle"><?=e($r['method'])?> · Payment #<?=(int)$r['id']?></p><span class="db-record-state"><?=bccomp($available,'0',2)>0?e(money($available).' unallocated'):'Fully allocated'?></span></div><div class="db-record-side"><strong><?=money($r['amount'])?></strong><time datetime="<?=e($r['payment_date'])?>"><?=e(date('j M Y',strtotime($r['payment_date'])))?></time></div></li>
    <?php endforeach ?></ul>
    <?php else: ?><div class="db-empty"><span class="db-empty-mark" aria-hidden="true">↗</span><h3>No payments recorded yet</h3><p>Payments stay separate from expense costs, so you can see what you have paid and what is still owed.</p><a class="db-text-link" href="index.php?page=payments">Record a payment <span aria-hidden="true">↗</span></a></div><?php endif ?>
</section>
</div>
<details class="db-breakdowns">
    <summary><span><strong>Where the money went</strong><span>Expenses by category and work type</span></span><span class="db-disclosure-icon" aria-hidden="true">+</span></summary>
    <div class="db-breakdown-grid">
        <section aria-labelledby="db-categories-heading"><h2 id="db-categories-heading">By category</h2><dl class="db-breakdown-list"><?php foreach($categoryBreakdown as $r): ?><div><dt><?=e($r['name'])?></dt><dd><?=money($r['amount'])?></dd></div><?php endforeach ?><div><dt>Approved Theka work</dt><dd><?=money($work)?></dd></div></dl></section>
        <section aria-labelledby="db-work-heading"><h2 id="db-work-heading">By work type</h2><?php if($workBreakdown): ?><dl class="db-breakdown-list"><?php foreach($workBreakdown as $r): ?><div><dt><?=e($r['name'])?></dt><dd><?=money($r['amount'])?></dd></div><?php endforeach ?></dl><?php else: ?><p class="db-explanation">Work types appear here when you record an expense.</p><?php endif ?></section>
    </div>
    <p class="db-commitment-note">Agreed contract values stay separate from total expenses until work is approved.</p>
    <a class="db-text-link db-report-link" href="index.php?page=reports">Open detailed reports <span aria-hidden="true">↗</span></a>
</details>
</div>
