(() => {
    const form = document.querySelector('#expense-form');
    if (!form) return;
    const data = window.expenseData, container = document.querySelector('#expense-lines');
    const get = (line, field) => line.querySelector(`[data-field="${field}"]`);
    const focusField = (line, field) => { const input = get(line,field); input.tomselect ? input.tomselect.focus() : input.focus(); };
    const number = value => Number(value).toLocaleString('en-PK', {maximumFractionDigits:4});
    const money = value => 'Rs. ' + Number(value).toLocaleString('en-PK', {minimumFractionDigits:2,maximumFractionDigits:2});
    let next = Math.max(0,...[...container.querySelectorAll('[data-field="item_id"]')].map(el => Number(el.name.match(/\[(\d+)\]/)?.[1] || 0))) + 1;
    const originalUnitOptions = new WeakMap();
    function factor(from, to) {
        if (!from || !to) return null;
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
    function currentFactor(line) {
        const snapshot = line.querySelector('.snapshot-factor');
        const from = get(line,'unit_id').value, to = get(line,'rate_unit_id').value;
        return snapshot.value && snapshot.dataset.from === from && snapshot.dataset.to === to ? Number(snapshot.value) : factor(from,to);
    }
    function unitName(line, field) {
        return get(line,field).selectedOptions[0]?.textContent || '';
    }
    function readableUnit(name, quantity = 1) {
        // Keep local market names; only pluralize familiar English units.
        const plurals = {'Piece':'pieces','Bag':'bags','Box':'boxes','Trip':'trips','Point':'points','Foot':'feet','Coil':'coils','Roll':'rolls','Bucket':'buckets','Gallon':'gallons','Trolley':'trolleys'};
        return quantity === 1 ? ({'Piece':'piece','Bag':'bag','Box':'box','Trip':'trip','Point':'point','Foot':'foot','Coil':'coil','Roll':'roll','Bucket':'bucket','Gallon':'gallon'}[name] || name) : (plurals[name] || name);
    }
    function filterUnits(line) {
        const select = get(line,'unit_id'), all = line.querySelector('[data-all-units]').checked;
        const snapshot = line.querySelector('.snapshot-factor');
        const target = get(line,'rate_unit_id').value, selected = select.value;
        if (!originalUnitOptions.has(line)) originalUnitOptions.set(line,[...select.options].map(option => option.cloneNode(true)));
        const available = originalUnitOptions.get(line).filter(option => {
            const snapshotCompatible = snapshot.value && snapshot.dataset.from === option.value && snapshot.dataset.to === target;
            return all || option.value === selected || factor(option.value,target) !== null || snapshotCompatible;
        });
        const fragment = document.createDocumentFragment();
        // A deliberately empty selection stays empty; never pick a replacement unit.
        if (!selected) fragment.append(new Option('Choose unit',''));
        available.forEach(option => fragment.append(option.cloneNode(true)));
        select.replaceChildren(fragment);
        select.value = selected;
    }
    function calculate() {
        let total = 0, valid = true;
        const lines = [...container.querySelectorAll('.expense-line')];
        lines.forEach((line,index) => {
            line.querySelector('.ef-line-number').textContent = index + 1;
            const item = get(line,'item_id'), selected = !!item.value;
            line.querySelector('.ef-line-title').textContent = selected ? item.selectedOptions[0].textContent : 'Purchase item';
            const remove = line.querySelector('.remove-line');
            remove.disabled = lines.length === 1;
            remove.setAttribute('aria-label',`Remove item ${index+1}${selected ? ': '+item.selectedOptions[0].textContent : ''}`);
            const fixed = get(line,'is_fixed').checked;
            line.querySelectorAll('.quantity-field').forEach(el => el.hidden = fixed);
            for (const field of ['quantity','unit_id','rate_basis','rate_unit_id']) {
                get(line,field).disabled = fixed;
                get(line,field).required = !fixed;
            }
            get(line,'rate_basis').closest('label').hidden = fixed;
            get(line,'rate_unit_id').closest('label').hidden = fixed;
            line.querySelector('.ef-all-units').hidden = fixed;
            filterUnits(line);
            const basis = Number(get(line,'rate_basis').value), qty = Number(get(line,'quantity').value), rate = Number(get(line,'rate').value);
            const multiplier = currentFactor(line);
            const rateUnit = unitName(line,'rate_unit_id'), quantityUnit = unitName(line,'unit_id');
            line.querySelector('.ef-price-label').textContent = fixed ? 'Fixed amount' : selected ? `Price per ${basis === 1 ? '' : number(basis)+' '}${readableUnit(rateUnit,basis)}` : 'Supplier’s quoted price';
            const hasPrice = get(line,'rate').value.trim() !== '';
            const amount = fixed ? rate : qty * rate * multiplier / basis;
            const badConversion = !fixed && multiplier === null;
            let error = '';
            if (selected && !fixed && !(qty > 0)) error = 'Enter a quantity greater than zero.';
            if (selected && !hasPrice) error = 'Enter your supplier’s quoted price.';
            if (selected && badConversion) error = `${quantityUnit} cannot be priced in ${rateUnit} without a configured conversion. Choose a compatible quantity unit or update the quoted price unit in More options.`;
            if (selected && !fixed && !(basis > 0)) error = 'Enter how many units the supplier’s price covers in More options.';
            const ok = selected && hasPrice && !error && (fixed || qty > 0) && Number.isFinite(amount) && amount >= 0;
            get(line,'unit_id').setCustomValidity(badConversion && selected ? error : '');
            const errorEl = line.querySelector('.ef-line-error');
            errorEl.hidden = !error;
            errorEl.textContent = error;
            line.classList.toggle('ef-has-error',!!error);
            line.querySelector('.line-total').textContent = ok ? money(Math.round((amount+Number.EPSILON)*100)/100) : '—';
            const equation = line.querySelector('.ef-equation');
            if (!selected) equation.textContent = 'Choose an item to see the calculation.';
            else if (fixed) equation.textContent = 'One fixed charge. No quantity calculation.';
            else if (ok) {
                const convertedQty = qty * multiplier;
                equation.textContent = String(get(line,'unit_id').value) === String(get(line,'rate_unit_id').value)
                    ? `${number(qty)} ${readableUnit(quantityUnit,qty)} × ${money(rate)}${basis===1 ? '' : ' ÷ '+number(basis)}`
                    : `${number(qty)} ${readableUnit(quantityUnit,qty)} = ${number(convertedQty)} ${readableUnit(rateUnit,convertedQty)} · ${number(convertedQty)} × ${money(rate)}${basis===1 ? '' : ' ÷ '+number(basis)}`;
            } else equation.textContent = error ? 'Check the quantity and quoted price units.' : 'Enter quantity and supplier’s price.';
            if (ok) total += Math.round((amount+Number.EPSILON)*100)/100; else valid = false;
        });
        document.querySelector('#purchase-total').textContent = valid ? money(total) : '—';
        document.querySelector('#ef-item-count').textContent = lines.length + (lines.length===1 ? ' item' : ' items');
        document.querySelector('#ef-summary-caption').textContent = valid ? `Total for ${lines.length === 1 ? '1 item' : lines.length+' items'}` : 'Complete each item to see your total';
        const status = form.querySelector('[name="payment_status"]:checked')?.value;
        const partial = document.querySelector('#partial-field');
        const amountInput = form.querySelector('[name="payment_amount"]');
        if (partial) {
            partial.hidden = status !== 'partial';
            amountInput.disabled = status !== 'partial';
            amountInput.required = status === 'partial';
            if (valid) amountInput.max = total.toFixed(2); else amountInput.removeAttribute('max');
            const method = document.querySelector('#ef-method-field');
            method.hidden = status === 'unpaid';
            method.querySelector('select').disabled = status === 'unpaid';
        }
        const paid = status === 'full' ? total : status === 'partial' ? Number(amountInput.value || 0) : Number(form.dataset.paid || 0);
        document.querySelector('#ef-remaining').textContent = valid ? money(Math.max(0,total-paid)) : '—';
        let paymentError = '';
        if (valid && status === 'full' && total <= 0) paymentError = 'A full payment needs a purchase total greater than zero.';
        if (valid && paid > total) paymentError = status ? 'Amount paid cannot exceed this purchase total.' : 'Purchase total cannot be lower than payments already allocated.';
        if (amountInput) amountInput.setCustomValidity(status==='partial' && paid>total ? paymentError : '');
        const message = document.querySelector('#ef-form-error');
        message.hidden = !paymentError;
        message.textContent = paymentError;
        document.querySelector('#ef-payment-note').textContent = status === 'full' ? 'A payment will be recorded with this purchase.' : status === 'partial' ? 'The unpaid balance stays outstanding.' : status === 'unpaid' ? 'No payment recorded now. You can add one later.' : 'Existing payments stay linked to this purchase.';
        document.dispatchEvent(new Event('searchable-selects:refresh'));
        return valid && !paymentError;
    }
    function defaults(line, itemId) {
        const item = data.items.find(i => String(i.id) === String(itemId));
        if (!item) { calculate(); return; }
        const last = data.lastRates[itemId];
        const previousQuantityUnit = get(line,'unit_id').value;
        get(line,'item_id').value = itemId;
        const originalOptions = originalUnitOptions.get(line);
        if (originalOptions) get(line,'unit_id').replaceChildren(...originalOptions.map(option => option.cloneNode(true)));
        for (const field of ['category_id','unit_id','rate_unit_id','rate_basis']) get(line,field).value = item[field];
        if (previousQuantityUnit && String(previousQuantityUnit) !== String(item.unit_id)) get(line,'quantity').value = '1';
        const compatibleRate = last && String(last.rate_unit_id) === String(item.rate_unit_id) && Number(last.rate_basis) === Number(item.rate_basis) && !Number(last.is_fixed);
        get(line,'rate').value = compatibleRate ? last.rate : Number(item.default_rate) > 0 ? item.default_rate : '';
        line.querySelector('.ef-rate-source').textContent = compatibleRate ? 'Last recorded price · you can change it' : Number(item.default_rate)>0 ? 'Item’s default price · you can change it' : 'Enter the price quoted by your supplier';
        if (last && data.activeWorkIds.some(id => String(id) === String(last.work_type_id))) get(line,'work_type_id').value = last.work_type_id;
        get(line,'is_fixed').checked = false;
        // Keep an existing line's authoritative snapshot ID. Server decides whether
        // its units are unchanged; changing the item must not reset unrelated history.
        line.querySelector('[data-all-units]').checked = false;
        calculate();
    }
    function addLine(itemId) {
        if (container.children.length >= 100) return;
        const index = next++;
        const html = document.querySelector('#line-template').innerHTML.replaceAll('[NEW]',`[${index}]`).replaceAll('ef-line-NEW',`ef-line-${index}`);
        container.insertAdjacentHTML('beforeend',html);
        const line = container.lastElementChild;
        if (itemId) defaults(line,itemId);
        calculate();
        focusField(line,itemId?'quantity':'item_id');
        return line;
    }
    document.querySelector('#add-line').addEventListener('click',() => addLine());
    container.addEventListener('change',event => {
        if (event.target.closest('.ts-wrapper')) return;
        const line = event.target.closest('.expense-line');
        if (event.target.matches('.item-select')) defaults(line,event.target.value);
        if (event.target.matches('[data-field="rate"], [data-field="rate_unit_id"], [data-field="rate_basis"]')) line.querySelector('.ef-rate-source').textContent='';
        calculate();
    });
    form.addEventListener('input',event => { if (!event.target.closest('.ts-wrapper')) calculate(); });
    form.addEventListener('change',event => { if (!event.target.closest('.ts-wrapper')) calculate(); });
    container.addEventListener('click',event => {
        const button = event.target.closest('.remove-line');
        if (!button || container.children.length < 2) return;
        const line = button.closest('.expense-line'), following = line.nextElementSibling || line.previousElementSibling;
        line.remove(); calculate(); focusField(following,'item_id');
    });
    document.querySelectorAll('.recent-item').forEach(button => button.addEventListener('click',() => {
        const empty = [...container.querySelectorAll('.expense-line')].find(line => !get(line,'item_id').value);
        if (empty) { defaults(empty,button.dataset.item); get(empty,'quantity').focus(); } else addLine(button.dataset.item);
    }));
    // Native validation can target an advanced field: reveal it before focus.
    form.addEventListener('invalid',event => {
        const details = event.target.closest('details');
        if (details) details.open = true;
        if (event.target.closest('.expense-line')) event.target.closest('.expense-line').classList.add('ef-has-error');
    },true);
    form.addEventListener('submit',event => {
        if (!calculate()) {
            event.preventDefault();
            const invalid = form.querySelector(':invalid');
            if (invalid) { invalid.closest('details')?.setAttribute('open',''); invalid.reportValidity(); invalid.tomselect ? invalid.tomselect.focus() : invalid.focus(); }
            else document.querySelector('#ef-form-error').scrollIntoView({block:'center'});
        }
    });
    calculate();
    document.querySelector('.alert-danger')?.focus();
})();
