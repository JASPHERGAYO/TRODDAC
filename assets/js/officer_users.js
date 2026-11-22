// Officer users screen: confirm sheet, role-based fields, copy password. (Dialog handling is in modals.js.)
(function () {
    const $ = (id) => document.getElementById(id);

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-confirm]');
        if (!btn) return;
        $('cf-action').value = btn.dataset.action;
        $('cf-user').value = btn.dataset.user;
        $('m-confirm-title').textContent = btn.dataset.title || 'Are you sure?';
        $('cf-text').textContent = btn.dataset.text || '';
        const ok = $('cf-ok');
        ok.textContent = btn.dataset.ok || 'Confirm';
        ok.className = 'btn' + (/Disable/.test(ok.textContent) ? ' danger' : '');
        window.TRD.modal.open($('m-confirm'));
    });

    // Show the driver-only fields only for the Driver role.
    const role = $('c_role');
    const driverFields = $('driverFields');
    const license = $('c_license');
    const unit = $('c_unit');
    function syncRole() {
        const isDriver = role.value === 'driver';
        driverFields.hidden = !isDriver;
        license.required = isDriver;
        unit.required = isDriver;
    }
    if (role) { role.addEventListener('change', syncRole); syncRole(); }

    const copy = $('copy-pw');
    if (copy) {
        copy.addEventListener('click', async () => {
            const text = $('secret-pw').textContent;
            try { await navigator.clipboard.writeText(text); copy.textContent = 'Copied'; }
            catch (e) {
                const r = document.createRange(); r.selectNodeContents($('secret-pw'));
                const s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
                copy.textContent = 'Press Ctrl+C';
            }
        });
    }
})();
