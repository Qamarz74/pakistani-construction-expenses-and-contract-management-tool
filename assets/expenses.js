(() => {
    const form = document.querySelector('#expense-form');
    if (!form) return;
    const data = window.expenseData, container = document.querySelector('#expense-lines');
    let next = Math.max(0, ...[...container.querySelectorAll('[data-field="item_id"]')].map(el => Number(el.name.match(/\[(\d+)\]/)?.[1] || 0))) + 1;
    const get = (line, field) => line.querySelector(`[data-field="${field}"]`);
    function factor(from, to) {
        if (String(from) === String(to)) return 1;
        const queue = [[String(from),1]], seen = new Set();
        while (queue.length) {
            const [unit, value] = queue.shift();
            if (seen.has(unit)) continue;
            seen.add(unit);
            for (const rule of data.conversions) {
                let target, multiplier;
                if (String(rule.from_unit_id) === unit) { target = String(rule.to_unit_id); multiplier = Number(rule.factor); }
                else if (String(rule.to_unit_id) === unit) { target = String(rule.from_unit_id); multiplier = 1 / Number(rule.factor); }
                else continue;
                if (target === String(to)) return Math.round(value * multiplier * 1e8) / 1e8;
                queue.push([target,value*multiplier]);
            }
        }
        return null;
    }
    function calculate() {
        let total = 0, valid = true;
        container.querySelectorAll('.expense-line').forEach(line => {
            const fixed = get(line,'is_fixed').checked;
            line.querySelectorAll('.quantity-field').forEach(el => el.hidden = fixed);
            const snapshot = line.querySelector('.snapshot-factor'), from = get(line,'unit_id').value, to = get(line,'rate_unit_id').value;
            const multiplier = snapshot.value && snapshot.dataset.from === from && snapshot.dataset.to === to ? Number(snapshot.value) : factor(from,to);
            const amount = fixed ? Number(get(line,'rate').value) : Number(get(line,'quantity').value) * Number(get(line,'rate').value) * multiplier / Number(get(line,'rate_basis').value);
            const ok = (fixed || multiplier !== null) && Number.isFinite(amount) && amount >= 0;
            line.querySelector('.line-total').textContent = ok ? 'Line: Rs. ' + amount.toLocaleString('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2}) : 'No valid unit conversion / quantity. Check this line.';
            if (ok) total += Math.round((amount + Number.EPSILON)*100)/100; else valid = false;
        });
        document.querySelector('#purchase-total').textContent = valid ? 'Purchase total: Rs. ' + total.toLocaleString('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2}) : 'Total unavailable: check line units / quantities.';
        const partial = document.querySelector('#partial-field');
        if (partial) partial.hidden = document.querySelector('#payment-status').value !== 'partial';
    }
    function defaults(line, itemId) {
        const item = data.items.find(i => String(i.id) === String(itemId));
        if (!item) return;
        const last = data.lastRates[itemId];
        get(line,'item_id').value = itemId;
        for (const field of ['category_id','unit_id','rate_unit_id','rate_basis']) get(line,field).value = item[field];
        const compatibleRate = last && String(last.rate_unit_id) === String(item.rate_unit_id) && Number(last.rate_basis) === Number(item.rate_basis) && !Number(last.is_fixed);
        get(line,'rate').value = compatibleRate ? last.rate : item.default_rate;
        if (last && data.activeWorkIds.some(id => String(id) === String(last.work_type_id))) get(line,'work_type_id').value = last.work_type_id;
        get(line,'is_fixed').checked = false;
        get(line,'line_id').value = '';
        line.querySelector('.snapshot-factor').value = '';
        calculate();
    }
    function addLine(itemId) {
        const html = document.querySelector('#line-template').innerHTML.replaceAll('[NEW]',`[${next++}]`);
        container.insertAdjacentHTML('beforeend',html);
        const line = container.lastElementChild;
        if (itemId) defaults(line,itemId);
        calculate();
        return line;
    }
    document.querySelector('#add-line').addEventListener('click',() => addLine());
    container.addEventListener('change',event => {
        if (event.target.matches('.item-select')) defaults(event.target.closest('.expense-line'),event.target.value);
        calculate();
    });
    form.addEventListener('input',calculate);
    form.addEventListener('change',calculate);
    container.addEventListener('click',event => {
        if (!event.target.matches('.remove-line')) return;
        if (container.children.length > 1) event.target.closest('.expense-line').remove();
        calculate();
    });
    document.querySelectorAll('.recent-item').forEach(button => button.addEventListener('click',() => {
        const empty = [...container.querySelectorAll('.expense-line')].find(line => !get(line,'item_id').value);
        if (empty) defaults(empty,button.dataset.item); else addLine(button.dataset.item);
    }));
    calculate();
})();
