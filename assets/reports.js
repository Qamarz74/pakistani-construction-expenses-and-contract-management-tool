(() => {
    const form = document.querySelector('[data-report-filters]');
    if (!form) return;
    const period = form.querySelector('[name="period"]');
    const updateDates = () => {
        form.querySelectorAll('[data-custom-date]').forEach(field => {
            field.hidden = period.value !== 'custom';
        });
    };
    period.addEventListener('change', updateDates);
    updateDates();
})();
