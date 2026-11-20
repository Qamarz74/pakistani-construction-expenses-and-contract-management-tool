(() => {
    const form = document.querySelector('[data-payment-create]');
    if (!form) return;
    const target = form.querySelector('[name="target"]');
    const contact = form.querySelector('[name="contact_id"]');
    const allocation = form.querySelector('[name="allocated"]');
    const choices = [...target.options].filter(option => option.value).map(option => option.cloneNode(true));
    const refresh = () => document.dispatchEvent(new Event('searchable-selects:refresh'));
    function updateRecipient() {
        if (target.value) contact.value = target.selectedOptions[0]?.dataset.contact || '';
        refresh();
    }
    function updatePurpose() {
        const purpose = form.querySelector('[name="purpose"]:checked')?.value || 'expense';
        const advance = purpose === 'advance';
        const selected = target.value;
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = purpose === 'contract' ? 'Choose a contract' : 'Choose a purchase';
        const matching = choices.filter(option => option.value.startsWith(purpose + ':'));
        target.replaceChildren(placeholder,...matching.map(option => option.cloneNode(true)));
        target.value = matching.some(option => option.value === selected) ? selected : '';
        target.disabled = advance;
        target.required = !advance;
        allocation.disabled = advance;
        form.querySelector('[data-payment-target]').hidden = advance;
        form.querySelector('[data-payment-allocation]').hidden = advance;
        form.querySelector('[data-payment-target-label]').textContent = purpose === 'contract' ? 'Contract' : 'Purchase';
        form.querySelector('[data-payment-target-hint]').textContent = matching.length ? 'Choosing a record fills its recipient. Existing payments should be edited or reapplied, not recorded again.' : 'No unpaid records found. Add the purchase or contract first, or record an advance.';
        contact.required = purpose !== 'expense';
        updateRecipient();
        // The global enhancer preserves its initial label while options change.
        // Keep the announced target and search names aligned with this purpose.
        if (target.tomselect) {
            const label = purpose === 'contract' ? 'Contract' : 'Purchase';
            target.tomselect.control.setAttribute('aria-label', label);
            target.tomselect.control_input.setAttribute('aria-label', 'Search ' + label);
        }
    }
    form.addEventListener('change', event => {
        if (event.target.name === 'purpose') updatePurpose();
        else if (event.target === target) updateRecipient();
    });
    updatePurpose();
})();
