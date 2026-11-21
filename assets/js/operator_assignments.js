// Read-only monitoring of automatic route assignments (Module B).
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
    TRD.poll(load, 10);
})();
