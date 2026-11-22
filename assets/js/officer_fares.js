// Fare rates: open the form for add / edit / copy and show a live per-km preview.
(function () {
    const $ = (id) => document.getElementById(id);
    const num = (id) => { const n = parseFloat(String($(id).value).replace(/[, \u20B1]/g, '')); return Number.isFinite(n) ? n : null; };

    function preview() {
        const rate = num('f-rate'), base = num('f-base') ?? 0, baseKm = num('f-basekm') ?? 0, min = num('f-min') ?? 0;
        const box = $('preview');
        if (rate === null || rate <= 0) { box.innerHTML = '<span class="hint">Enter the rate per km to preview fares.</span>'; return; }
        box.innerHTML = [1, 2, 3, 5, 10].map((km) => {
            const fare = Math.max(min, base + Math.max(km - baseKm, 0) * rate);
            return '<div><b>\u20B1' + fare.toFixed(2) + '</b><span>' + km + ' km</span></div>';
        }).join('');
    }

    function fill(data, mode) {
        $('m-fare-title').textContent = mode === 'edit' ? 'Edit fare rate' : (mode === 'copy' ? 'New fare rate (copy)' : 'Add fare rate');
        $('f-id').value = mode === 'edit' ? data.id : 0;
        $('f-name').value = mode === 'copy' ? data.name + ' (new)' : (data.name || '');
        $('f-rate').value = data.per_km_rate || '';
        $('f-base').value = data.base_fare ?? '0';
        $('f-basekm').value = data.base_distance_km ?? '0';
        $('f-min').value = data.minimum_fare ?? '20';
        $('f-from').value = mode === 'copy' || !data.effective_from ? new Date().toISOString().slice(0, 10) : data.effective_from;
        $('f-to').value = mode === 'copy' ? '' : (data.effective_to || '');
        preview();
        window.TRD.modal.open($('m-fare'));
    }

    document.addEventListener('click', (e) => {
        const edit = e.target.closest('[data-fare-edit]');
        const copy = e.target.closest('[data-fare-copy]');
        if (edit) fill(JSON.parse(edit.dataset.fareEdit), 'edit');
        else if (copy) fill(JSON.parse(copy.dataset.fareCopy), 'copy');
        else if (e.target.closest('#new-fare')) fill({}, 'new');
    });
    ['f-rate', 'f-base', 'f-basekm', 'f-min'].forEach((id) => $(id).addEventListener('input', preview));
    preview();
})();
