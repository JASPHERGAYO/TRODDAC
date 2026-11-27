// Monitoring of automatic route assignments (Module B) + officer route management modal.
(function () {
    const TRD = window.TRD;
    const $ = (id) => document.getElementById(id);
    const esc = TRD.esc;

    const time = (s) => String(s || '').slice(11, 16);
    const day = (s) => String(s || '').slice(0, 10);

    function render(data) {
        $('s-active').textContent = data.stats.active_now || 0;
        $('s-upcoming').textContent = data.stats.upcoming || 0;
        $('s-done').textContent = data.stats.completed_today || 0;
        $('s-missed').textContent = data.stats.missed_today || 0;

        $('assignments').innerHTML = data.assignments.map((a) =>
            '<div class="item"><div class="head"><b>' + esc(a.code) + ' &middot; ' + esc(a.driver_name) + '</b>'
            + '<span class="pill ' + (a.status === 'active' ? 'in_progress' : (a.status === 'scheduled' ? 'assigned' : (a.status === 'completed' ? 'completed' : 'cancelled'))) + '">' + esc(a.status) + '</span></div>'
            + '<div class="route">' + esc(a.origin) + ' &rarr; ' + esc(a.destination) + '</div>'
            + '<div class="meta">' + esc(day(a.start_datetime)) + ' ' + esc(time(a.start_datetime)) + '-' + esc(time(a.end_datetime))
            + (a.plate_number ? ' &middot; ' + esc(a.plate_number) : '') + ' &middot; cycle ' + esc(a.rotation_cycle) + ', position ' + esc(a.queue_position)
            + (a.notes && a.status === 'missed' ? '<br>' + esc(a.notes) : '') + '</div></div>').join('');
        $('assignments-empty').hidden = data.assignments.length > 0;

        const run = data.runs[0];
        $('last-run').textContent = run
            ? 'Last run ' + run.started_at + ' (' + run.run_source + '): ' + run.assigned_count + ' assigned, ' + run.skipped_count + ' skipped, '
              + run.unfilled_count + ' unfilled, ' + run.missed_count + ' missed.' + (run.error_message ? ' Error: ' + run.error_message : '')
            : 'The engine has not run yet.';

        $('decisions').innerHTML = data.decisions.map((d) =>
            '<div class="item"><div class="head"><b>' + esc(d.code || '') + (d.driver_name ? ' &middot; ' + esc(d.driver_name) : '') + '</b>'
            + '<span class="pill ' + (d.decision === 'assigned' ? 'completed' : (d.decision === 'skipped' ? 'pending' : 'cancelled')) + '">' + esc(d.decision) + '</span></div>'
            + '<div class="meta">' + esc(d.slot_start ? d.slot_start.slice(0, 16) : '') + ' &middot; ' + esc(d.reason || '') + '</div></div>').join('');
        $('decisions-empty').hidden = data.decisions.length > 0;
    }

    async function load() {
        const data = await TRD.api('assignments_overview.php');
        if (data.ok) render(data);
    }

    const run = $('run-now');
    if (run) {
        run.addEventListener('click', async () => {
            run.disabled = true;
            const res = await TRD.api('assignment_tick.php', { method: 'POST', body: {} });
            run.disabled = false;
            TRD.toast(res.ok ? (res.assigned + ' assigned, ' + res.skipped + ' skipped, ' + res.unfilled + ' unfilled.') : (res.error || 'Could not run.'), res.ok ? 'success' : 'error');
            load();
        });
    }

    // Officer route modal (Add / Edit route + driver selection)
    const modal = $('m-route');
    if (modal && TRD.modal) {
        const titleEl  = $('m-route-title');
        const submitEl = $('r-submit');
        const idEl     = $('r-id');
        const codeEl   = $('r-code');
        const nameEl   = $('r-name');
        const origEl   = $('r-origin');
        const destEl   = $('r-dest');
        const kmEl     = $('r-km');
        const durEl    = $('r-dur');
        const reqEl    = $('r-req');
        const s1El     = $('r-s1');
        const e1El     = $('r-e1');
        const s2El     = $('r-s2');
        const e2El     = $('r-e2');
        const toggleDrvBtn = $('r-toggle-drivers');
        let lastAutoName = '';

        function clearErrors() {
            modal.querySelectorAll('.field-error, .alert').forEach((el) => el.remove());
        }

        function updateToggleLabel() {
            if (!toggleDrvBtn) return;
            const boxes = [...modal.querySelectorAll('#r-drivers input[type="checkbox"]')];
            const allChecked = boxes.length > 0 && boxes.every((b) => b.checked);
            toggleDrvBtn.textContent = allChecked ? 'Clear all' : 'Select all';
        }

        function openCreate() {
            clearErrors();
            titleEl.textContent  = 'Add official route';
            submitEl.textContent = 'Create route';
            idEl.value   = '0';
            codeEl.value = '';
            nameEl.value = '';
            origEl.value = '';
            destEl.value = '';
            kmEl.value   = '4.50';
            durEl.value  = '15';
            reqEl.value  = '1';
            s1El.value   = '06:00';
            e1El.value   = '10:00';
            s2El.value   = '';
            e2El.value   = '';
            lastAutoName = '';
            modal.querySelectorAll('#r-days input[type="checkbox"]').forEach((cb) => {
                cb.checked = Number(cb.value) <= 6;
            });
            modal.querySelectorAll('#r-drivers input[type="checkbox"]').forEach((cb) => {
                cb.checked = true;
            });
            updateToggleLabel();
            TRD.modal.open(modal);
        }

        function openEdit(r) {
            clearErrors();
            titleEl.textContent  = 'Edit official route';
            submitEl.textContent = 'Save changes';
            idEl.value   = String(r.id || 0);
            codeEl.value = r.code || '';
            nameEl.value = r.name || '';
            origEl.value = String(r.origin_terminal_id || '');
            destEl.value = String(r.destination_terminal_id || '');
            kmEl.value   = r.distance_km || '4.50';
            durEl.value  = String(r.est_duration_min || 15);
            reqEl.value  = String(r.drivers_required || 1);
            s1El.value   = r.start_time || '06:00';
            e1El.value   = r.end_time || '10:00';
            s2El.value   = r.start_time_2 || '';
            e2El.value   = r.end_time_2 || '';
            lastAutoName = '';
            const daySet = new Set((r.days || []).map(Number));
            modal.querySelectorAll('#r-days input[type="checkbox"]').forEach((cb) => {
                cb.checked = daySet.has(Number(cb.value));
            });
            const drvSet = new Set((r.driver_ids || []).map(Number));
            modal.querySelectorAll('#r-drivers input[type="checkbox"]').forEach((cb) => {
                cb.checked = drvSet.has(Number(cb.value));
            });
            updateToggleLabel();
            TRD.modal.open(modal);
        }

        function maybeSuggestName() {
            if (idEl.value !== '0') return;
            const oTxt = origEl.options[origEl.selectedIndex]?.text || '';
            const dTxt = destEl.options[destEl.selectedIndex]?.text || '';
            if (!origEl.value || !destEl.value || origEl.value === destEl.value) return;
            const clean = (s) => s.replace(/\s+Terminal$/i, '').trim();
            const suggested = clean(oTxt) + ' - ' + clean(dTxt);
            if (nameEl.value.trim() === '' || nameEl.value === lastAutoName) {
                nameEl.value = suggested;
                lastAutoName = suggested;
            }
        }

        $('new-route')?.addEventListener('click', openCreate);
        $('new-route-inline')?.addEventListener('click', openCreate);
        origEl?.addEventListener('change', maybeSuggestName);
        destEl?.addEventListener('change', maybeSuggestName);

        document.addEventListener('click', (e) => {
            const editBtn = e.target.closest('[data-route-edit]');
            if (!editBtn) return;
            try { openEdit(JSON.parse(editBtn.dataset.routeEdit)); } catch (err) { console.error(err); }
        });

        toggleDrvBtn?.addEventListener('click', () => {
            const boxes = [...modal.querySelectorAll('#r-drivers input[type="checkbox"]')];
            const turnOn = !boxes.every((b) => b.checked);
            boxes.forEach((b) => { b.checked = turnOn; });
            updateToggleLabel();
        });

        modal.addEventListener('change', (e) => {
            if (e.target.closest('#r-drivers')) updateToggleLabel();
        });
        updateToggleLabel();
    }

    TRD.poll(load, 10);
})();
