<?php

// Caller holds the project lock and commits/rolls back the complete form update.
function attach_missing_supplier(string $type, int $id, int $contactId): void
{
    if (!db()->inTransaction()) throw new LogicException('Supplier assignment requires a transaction.');
    if (!in_array($type, ['expense','payment'], true)) throw new InvalidArgumentException('Invalid supplier record.');
    if (!one('SELECT id FROM contacts WHERE id=? FOR UPDATE', [$contactId])) throw new InvalidArgumentException('Choose a valid supplier.');
    $pending = [[$type, $id]];
    $seen = [];
    $updates = [];
    while ($pending) {
        [$kind, $recordId] = array_pop($pending);
        $key = $kind.':'.$recordId;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $table = $kind === 'expense' ? 'expenses' : ($kind === 'payment' ? 'payments' : 'contracts');
        $record = one("SELECT contact_id,status FROM $table WHERE id=? AND project_id=? FOR UPDATE", [$recordId, project_id()]);
        if (!$record) throw new RuntimeException('A linked record is outside this project or no longer exists. No supplier was changed.');
        if ($record['contact_id'] !== null) {
            if ((int)$record['contact_id'] !== $contactId) throw new RuntimeException('A linked purchase, payment or contract already belongs to another supplier. Release the conflicting allocation before assigning this supplier.');
            continue;
        }
        if ($kind === 'contract' || $record['status'] !== 'active') throw new RuntimeException('A linked record cannot be changed. No supplier was changed.');
        $updates[] = [$table, $recordId];
        if ($kind === 'expense') {
            foreach (rows('SELECT payment_id FROM payment_allocations WHERE expense_id=?', [$recordId]) as $link) $pending[] = ['payment', (int)$link['payment_id']];
        } else {
            foreach (rows('SELECT expense_id,contract_id FROM payment_allocations WHERE payment_id=?', [$recordId]) as $link) {
                $pending[] = $link['expense_id'] !== null ? ['expense', (int)$link['expense_id']] : ['contract', (int)$link['contract_id']];
            }
        }
    }
    // Validate the whole connected group before changing any record.
    foreach ($updates as [$table, $recordId]) run("UPDATE $table SET contact_id=? WHERE id=? AND project_id=? AND contact_id IS NULL", [$contactId, $recordId, project_id()]);
}

function contact_history_count(int $id): int
{
    return (int)one('SELECT (SELECT COUNT(*) FROM expenses WHERE contact_id=?) + (SELECT COUNT(*) FROM payments WHERE contact_id=?) + (SELECT COUNT(*) FROM contracts WHERE contact_id=?) records', [$id, $id, $id])['records'];
}

function remove_unused_contact(int $id): void
{
    if (!db()->inTransaction()) throw new LogicException('Contact removal requires a transaction.');
    if (!one('SELECT id FROM contacts WHERE id=? FOR UPDATE', [$id])) throw new RuntimeException('Contact not found.');
    // Contacts are shared across projects. Cancelled records still belong to history.
    if (contact_history_count($id)) throw new RuntimeException('This person has purchases, payments or contracts in one or more projects, including history. They cannot be removed.');
    run('DELETE FROM contacts WHERE id=?', [$id]);
}
