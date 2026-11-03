(() => {
    if (!window.TomSelect) return;
    const managed = new Map();
    const forced = '.item-select,select[name="contact_id"],select[name="target"]';
    let queued = false;
    const optionKey = select => JSON.stringify([...select.options].map(o => [o.value,o.text,o.disabled]));
    const shouldEnhance = select => !select.multiple && !select.hasAttribute('data-native-select') && (select.options.length >= 8 || select.matches(forced));
    function destroy(select, preserve = true) {
        const nodes = [...select.childNodes].map(node => node.cloneNode(true));
        const value = select.value, disabled = select.disabled, required = select.required;
        const previous = managed.get(select);
        select.tomselect.destroy();
        if (previous.labelElement) {
            if (previous.labelFor === null) previous.labelElement.removeAttribute('for');
            else previous.labelElement.setAttribute('for',previous.labelFor);
        }
        if (previous.hidden === null) select.removeAttribute('aria-hidden');
        else select.setAttribute('aria-hidden',previous.hidden);
        if (preserve) {
            select.replaceChildren(...nodes);
            select.value = value;
            select.disabled = disabled;
            select.required = required;
        }
        managed.delete(select);
    }
    function refresh() {
        for (const select of managed.keys()) if (!select.isConnected) destroy(select,false);
        document.querySelectorAll('select').forEach(select => {
            if (!shouldEnhance(select)) {
                if (managed.has(select)) destroy(select);
                return;
            }
            let state = managed.get(select);
            if (!state) {
                const labelElement = select.labels?.[0];
                const label = labelElement?.querySelector(':scope > span')?.textContent.trim() || [...(labelElement?.childNodes || [])].filter(n => n.nodeType === Node.TEXT_NODE).map(n => n.textContent).join(' ').trim() || 'Options';
                const hidden = select.getAttribute('aria-hidden');
                const labelFor = labelElement?.getAttribute('for') ?? null;
                const control = new TomSelect(select, {
                    plugins: ['dropdown_input'],
                    create: false,
                    allowEmptyOption: true,
                    maxOptions: null,
                    closeAfterSelect: true,
                    searchField: ['text'],
                    placeholder: select.options[0]?.value === '' ? select.options[0].text : 'Type to search…',
                });
                control.wrapper.classList.remove('form-select','form-control');
                select.setAttribute('aria-hidden','true');
                control.control.removeAttribute('aria-labelledby');
                control.control.setAttribute('aria-label',label);
                control.control_input.removeAttribute('aria-labelledby');
                control.control_input.placeholder = 'Type to search…';
                control.control_input.setAttribute('aria-label','Search '+label);
                state = {key: optionKey(select),hidden,labelElement,labelFor};
                managed.set(select,state);
            }
            const control = select.tomselect, value = select.value, key = optionKey(select);
            if (key !== state.key) {
                // The expense form rebuilds compatible units. Drop stale options too.
                const nodes = [...select.childNodes].map(node => node.cloneNode(true));
                control.clear(true);
                control.clearOptions(() => false);
                select.replaceChildren(...nodes);
                select.value = value;
                control.sync();
                state.key = optionKey(select);
            } else if (String(control.getValue()) !== value) {
                control.setValue(value,true);
                state.key = optionKey(select);
            }
            if (select.disabled !== control.isDisabled) select.disabled ? control.disable() : control.enable();
        });
    }
    function schedule() {
        if (queued) return;
        queued = true;
        queueMicrotask(() => { queued = false; refresh(); });
    }
    document.addEventListener('searchable-selects:refresh',refresh);
    document.addEventListener('change',event => { if (event.target instanceof HTMLSelectElement) schedule(); });
    document.addEventListener('invalid',event => {
        if (!event.target.tomselect) return;
        let details = event.target.closest('details');
        while (details) { details.open = true; details = details.parentElement.closest('details'); }
        event.target.tomselect.focus();
    },true);
    document.addEventListener('reset',() => setTimeout(refresh,0));
    new MutationObserver(records => {
        if (records.some(record => [...record.addedNodes,...record.removedNodes].some(node => node.nodeType===1 && (node.matches('select') || node.querySelector('select'))))) schedule();
    }).observe(document.body,{childList:true,subtree:true});
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',refresh,{once:true});
    else refresh();
})();
