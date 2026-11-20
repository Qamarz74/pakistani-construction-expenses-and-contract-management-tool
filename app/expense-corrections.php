<?php
// Caller owns the transaction and project lock, shared with all financial writes.
function move_expense_to_contract(int $expenseId, int $contractId, string $mode): void
{
    if (!db()->inTransaction()) throw new LogicException('Correction requires a transaction.');
    if (!in_array($mode,['approved','advance'],true)) throw new InvalidArgumentException('Choose approved work or advance payment.');
    $expense=one('SELECT * FROM expenses WHERE id=? AND project_id=? FOR UPDATE',[$expenseId,project_id()]);
    $contract=one('SELECT * FROM contracts WHERE id=? AND project_id=? FOR UPDATE',[$contractId,project_id()]);
    if (!$expense || $expense['status']!=='active') throw new InvalidArgumentException('Select an active expense in this project.');
    if (!$contract || $contract['status']==='Cancelled') throw new InvalidArgumentException('Select an active or completed contract in this project.');
    $allocations=rows('SELECT a.*,p.contact_id,p.project_id,p.status FROM payment_allocations a JOIN payments p ON p.id=a.payment_id WHERE a.expense_id=? FOR UPDATE',[$expenseId]);
    $paid='0.00';
    foreach($allocations as $allocation) {
        if ($allocation['status']!=='active' || (int)$allocation['project_id']!==project_id()) throw new InvalidArgumentException('An attached payment is unavailable. Review the expense payments first.');
        if ((string)($allocation['contact_id']??'')!==(string)$contract['contact_id']) throw new InvalidArgumentException('The payment recipient must match the contract contact. Open the payment, release its purchase allocation, then correct the recipient before applying it to the contract.');
        $paid=add($paid,$allocation['amount']);
    }
    if ($mode==='advance' && cmp($paid,'0')<=0) throw new InvalidArgumentException('This expense has no recorded payment to move. Record the actual payment first, or move it as approved work.');
    if ($mode==='approved') {
        $recognized=one("SELECT COALESCE(SUM(amount),0) amount FROM contract_work_costs WHERE contract_id=? AND status='active'",[$contractId])['amount'];
        if (cmp($expense['total'],'0')<=0 || cmp(add($recognized,$expense['total']),$contract['agreed_amount'])>0) throw new InvalidArgumentException('This cost exceeds the contract’s remaining agreed amount. Adjust the contract agreement first, or choose advance payment if no work cost was incurred.');
        run('INSERT INTO contract_work_costs(contract_id,cost_date,amount,notes) VALUES(?,?,?,?)',[$contractId,$expense['expense_date'],$expense['total'],'Moved from purchase #'.$expenseId.'. '.$expense['notes']]);
    }
    foreach($allocations as $allocation) {
        run('DELETE FROM payment_allocations WHERE id=? AND expense_id=?',[$allocation['id'],$expenseId]);
        allocate_payment((int)$allocation['payment_id'],'contract',$contractId,$allocation['amount']);
    }
    $paymentHistory=[];
    foreach($allocations as $allocation) $paymentHistory[]='Payment #'.$allocation['payment_id'].': '.money($allocation['amount']);
    $note='Moved to contract #'.$contractId.' as '.($mode==='approved'?'approved work and existing payments':'an advance payment only').'. Original expense excluded from costs.';
    if($paymentHistory) $note.=' Moved payments: '.implode('; ',$paymentHistory).'.';
    run("UPDATE expenses SET status='cancelled',notes=CONCAT(COALESCE(notes,''),?,?) WHERE id=?",["\n",$note,$expenseId]);
}
