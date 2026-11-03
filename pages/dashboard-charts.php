<?php
// Chart geometry uses floats only for display; accounting values stay decimal strings.
$chartEnd = new DateTimeImmutable('first day of next month');
$chartStart = $chartEnd->modify('-6 months');
$chartMonths = [];
for ($date=$chartStart; $date<$chartEnd; $date=$date->modify('+1 month')) {
    $chartMonths[$date->format('Y-m')] = ['label'=>$date->format('M'), 'full'=>$date->format('F Y'), 'expense'=>'0.00', 'paid'=>'0.00'];
}
$chartBounds = [$pid,$chartStart->format('Y-m-d'),$chartEnd->format('Y-m-d')];
$monthlyActivity = rows("SELECT month,SUM(expense) expense,SUM(paid) paid FROM (
 SELECT DATE_FORMAT(expense_date,'%Y-%m') month,total expense,0 paid FROM expenses WHERE project_id=? AND expense_date>=? AND expense_date<? AND status='active'
 UNION ALL SELECT DATE_FORMAT(w.cost_date,'%Y-%m') month,w.amount expense,0 paid FROM contract_work_costs w JOIN contracts c ON c.id=w.contract_id WHERE c.project_id=? AND w.cost_date>=? AND w.cost_date<? AND w.status='active'
 UNION ALL SELECT DATE_FORMAT(payment_date,'%Y-%m') month,0 expense,amount paid FROM payments WHERE project_id=? AND payment_date>=? AND payment_date<? AND status='active'
) activity GROUP BY month",array_merge($chartBounds,$chartBounds,$chartBounds));
foreach ($monthlyActivity as $row) if (isset($chartMonths[$row['month']])) {
    $chartMonths[$row['month']]['expense']=$row['expense'];
    $chartMonths[$row['month']]['paid']=$row['paid'];
}
$chartMax = 0.0; $chartExpense='0.00'; $chartPaid='0.00';
foreach ($chartMonths as $row) {
    $chartMax=max($chartMax,(float)$row['expense'],(float)$row['paid']);
    $chartExpense=bcadd($chartExpense,$row['expense'],2);
    $chartPaid=bcadd($chartPaid,$row['paid'],2);
}
$chartScale = $chartMax>0 ? pow(10,max(0,floor(log10($chartMax)))) : 1;
$chartCeiling = $chartMax>0 ? ceil($chartMax/$chartScale)*$chartScale : 1;
$axisLabel = static function(float $amount): string {
    if ($amount>=1000000) return rtrim(rtrim(number_format($amount/1000000,1,'.',''),'0'),'.').'m';
    if ($amount>=1000) return rtrim(rtrim(number_format($amount/1000,1,'.',''),'0'),'.').'k';
    return rtrim(rtrim(number_format($amount,$amount>0 && $amount<1?4:2,'.',','),'0'),'.');
};
$chartCategories=$categoryBreakdown;
if (bccomp($work,'0',2)>0) $chartCategories[]=['name'=>'Approved Theka work','amount'=>$work];
usort($chartCategories,static fn($a,$b)=>bccomp($b['amount'],$a['amount'],2));
$chartCategories=array_values(array_filter($chartCategories,static fn($r)=>bccomp($r['amount'],'0',2)>0));
if (count($chartCategories)>5) {
    $remainder='0.00'; foreach(array_slice($chartCategories,5) as $row) $remainder=bcadd($remainder,$row['amount'],2);
    $chartCategories=array_slice($chartCategories,0,5);
    $chartCategories[]=['name'=>'Remaining categories','amount'=>$remainder];
}
?>
<div class="db-charts">
<section class="db-chart" aria-labelledby="db-trend-title">
    <div class="db-chart-heading"><div><span class="db-eyebrow">LAST SIX MONTHS</span><h2 id="db-trend-title">Expenses &amp; payments</h2></div><span class="db-chart-currency">PKR</span></div>
    <p class="db-chart-description">See when costs were recorded and money was paid.</p>
    <div class="db-chart-legend"><span><i class="db-key-expense" aria-hidden="true"></i>Expenses <strong><?=money($chartExpense)?></strong></span><span><i class="db-key-paid" aria-hidden="true"></i>Payments <strong><?=money($chartPaid)?></strong></span></div>
    <?php if($chartMax>0): ?>
    <svg class="db-trend-svg" viewBox="0 0 600 250" role="img" aria-labelledby="db-trend-svg-title db-trend-svg-desc">
        <title id="db-trend-svg-title">Monthly expenses and payments, <?=e($chartStart->format('M Y'))?> to <?=e($chartEnd->modify('-1 month')->format('M Y'))?></title>
        <desc id="db-trend-svg-desc">Grouped bars share a zero baseline. Expenses include purchases and approved work; payments include advances. Exact monthly amounts are available in View monthly figures.</desc>
        <?php for($tick=0;$tick<=4;$tick++): $y=20+$tick*48; ?><line x1="54" x2="590" y1="<?=$y?>" y2="<?=$y?>" class="db-chart-grid"/><text x="44" y="<?=$y+4?>" text-anchor="end" class="db-chart-axis"><?=e($axisLabel($chartCeiling*(4-$tick)/4))?></text><?php endfor ?>
        <?php $index=0; foreach($chartMonths as $row): $x=72+$index++*88; $expenseHeight=round((float)$row['expense']/$chartCeiling*192,3); $paidHeight=round((float)$row['paid']/$chartCeiling*192,3); ?>
        <g><title><?=e($row['full'])?>: expenses <?=e(money($row['expense']))?>; payments <?=e(money($row['paid']))?></title><rect x="<?=$x?>" y="<?=212-$expenseHeight?>" width="23" height="<?=$expenseHeight?>" rx="2" class="db-bar-expense"/><rect x="<?=$x+27?>" y="<?=212-$paidHeight?>" width="23" height="<?=$paidHeight?>" rx="2" class="db-bar-paid"/><text x="<?=$x+25?>" y="237" text-anchor="middle" class="db-chart-axis db-chart-month"><?=e($row['label'])?></text></g>
        <?php endforeach ?>
    </svg>
    <?php else: ?><div class="db-chart-empty"><strong>No activity in these six months</strong><p>Recorded expenses and payments will appear here.</p></div><?php endif ?>
    <details class="db-chart-data"><summary>View monthly figures</summary><div class="db-chart-table-wrap" tabindex="0" role="region" aria-label="Monthly figures, scroll horizontally to view all columns"><table><caption class="db-sr-only">Monthly expense and payment amounts in PKR</caption><thead><tr><th scope="col">Month</th><th scope="col">Expenses</th><th scope="col">Payments</th></tr></thead><tbody><?php foreach($chartMonths as $row): ?><tr><th scope="row"><?=e($row['full'])?></th><td><?=money($row['expense'])?></td><td><?=money($row['paid'])?></td></tr><?php endforeach ?></tbody></table></div></details>
    <p class="db-chart-footnote">Expense and payment dates differ. Payments include advances.</p>
</section>
<section class="db-chart" aria-labelledby="db-category-title">
    <div class="db-chart-heading"><div><span class="db-eyebrow">ALL TIME</span><h2 id="db-category-title">Where the money goes</h2></div><a class="db-text-link" href="index.php?page=reports&amp;tab=categories">Details ↗</a></div>
    <p class="db-chart-description">Share of total expenses, including approved Theka work.</p>
    <?php if($chartCategories && bccomp($recorded,'0',2)>0): ?><ul class="db-category-bars">
        <?php foreach($chartCategories as $row): $share=max(0,min(100,(float)bcdiv(bcmul($row['amount'],'100',4),$recorded,2))); ?>
        <li><div class="db-category-label"><span><?=e($row['name'])?></span><strong><?=money($row['amount'])?></strong></div><div class="db-category-track" aria-hidden="true"><span style="width:<?=$share?>%"></span></div><small><?=number_format($share,1)?>% of expenses</small></li>
        <?php endforeach ?></ul>
    <?php else: ?><div class="db-chart-empty"><strong>No expenses yet</strong><p>Your expense categories will appear after your first purchase or approved work.</p></div><?php endif ?>
</section>
</div>
