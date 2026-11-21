// Operator dispatch: live queue of passenger requests, assign a driver and unit, watch trips on the map.
(function () {
    const TRD = window.TRD;
    const M = TRD.map;
    const $ = (id) => document.getElementById(id);
    const esc = TRD.esc;

    const map = M.create('map', { zoom: 13 });
    M.serviceArea(map);
    const markers = { pending: new Map(), drivers: new Map(), live: new Map() };
    const cards = new Map();            // booking id -> element (kept between polls so open dropdowns are not reset)
    let data = { pending: [], live: [], drivers: [], units: [] };
    let firstLoad = true;
    let knownPending = new Set();
    let loaded = false;

    function syncMarkers(store, items, build) {
        const seen = new Set();
        items.forEach((it) => {
            seen.add(it.key);
            const have = store.get(it.key);
            if (have) { have.setLatLng([it.lat, it.lng]); } else { store.set(it.key, build(it)); }
        });
        store.forEach((m, k) => { if (!seen.has(k)) { map.removeLayer(m); store.delete(k); } });
    }

    function renderMap() {
        syncMarkers(markers.pending, data.pending.map((b) => ({ key: b.id, lat: b.pickup.lat, lng: b.pickup.lng, b })),
            (it) => M.marker(map, 'wait', 'P', it.lat, it.lng).bindTooltip(esc(it.b.passenger.name) + ' (' + esc(it.b.code) + ')'));
        syncMarkers(markers.drivers, data.drivers.filter((d) => d.lat !== null).map((d) => ({ key: d.id, lat: d.lat, lng: d.lng, d })),
            (it) => M.marker(map, 'driver', 'D', it.lat, it.lng).bindTooltip(esc(it.d.name)));
        syncMarkers(markers.live, data.live.filter((b) => b.driver && b.driver.lat !== null).map((b) => ({ key: b.id, lat: b.driver.lat, lng: b.driver.lng, b })),
            (it) => M.marker(map, 'driver', 'T', it.lat, it.lng).bindTooltip(esc(it.b.driver.name) + ' - ' + esc(it.b.code)));
        if (firstLoad) {
            const pts = [];
            markers.pending.forEach((m) => pts.push(m.getLatLng()));
            markers.drivers.forEach((m) => pts.push(m.getLatLng()));
            if (pts.length) { M.fit(map, pts.map((p) => [p.lat, p.lng]), 15); firstLoad = false; }
        }
    }

    function driverOptions(b) {
        const withKm = data.drivers.map((d) => ({ d, km: d.lat === null ? null : TRD.haversineKm(d.lat, d.lng, b.pickup.lat, b.pickup.lng) }));
        withKm.sort((a, c) => (a.km === null ? 9999 : a.km) - (c.km === null ? 9999 : c.km));
        return withKm;
    }

    function fillSelects(card, b) {
        const dsel = card.querySelector('.sel-driver');
        const usel = card.querySelector('.sel-unit');
        const active = document.activeElement;
        const options = driverOptions(b);
        const key = JSON.stringify(options.map((o) => [o.d.id, o.km === null ? null : Math.round(o.km * 10)])) + '|' + data.units.map((u) => u.id).join(',');
        if (card.dataset.optKey === key || active === dsel || active === usel) return;
        card.dataset.optKey = key;

        const prevD = dsel.value, prevU = usel.value;
        dsel.innerHTML = options.length
            ? options.map((o) => '<option value="' + o.d.id + '">' + esc(o.d.name) + (o.d.plate ? ' - ' + esc(o.d.plate) : '') + (o.km !== null ? ' - ' + o.km.toFixed(1) + ' km away' : ' - no GPS yet') + '</option>').join('')
            : '<option value="">No driver is online</option>';
        if (prevD && options.some((o) => String(o.d.id) === prevD)) dsel.value = prevD;
        updateUnitSelect(card, b, usel, dsel, prevU);
        card.querySelector('.btn-assign').disabled = options.length === 0;
    }

    function updateUnitSelect(card, b, usel, dsel, keepValue) {
        const d = data.drivers.find((x) => String(x.id) === dsel.value);
        const list = data.units.slice();
        if (d && d.unit_id && !list.some((u) => u.id === d.unit_id)) list.unshift({ id: d.unit_id, plate: (d.plate || 'Default unit') + ' (busy)', body: '' });
        usel.innerHTML = list.map((u) => '<option value="' + u.id + '">' + esc(u.plate) + (u.body ? ' / ' + esc(u.body) : '') + '</option>').join('') || '<option value="">No unit free</option>';
        const want = keepValue && list.some((u) => String(u.id) === keepValue) ? keepValue : (d && d.unit_id ? String(d.unit_id) : '');
        if (want && list.some((u) => String(u.id) === want)) usel.value = want;
    }

    function buildCard(b) {
        const el = document.createElement('div');
        el.className = 'item alert-new';
        el.dataset.id = b.id;
        el.innerHTML =
            '<div class="head"><b>' + esc(b.passenger.name) + '</b><span class="pill pending">New request</span></div>' +
            '<div class="route"><b>From:</b> ' + esc(b.pickup.label) + '<br><b>To:</b> ' + esc(b.dest.label) + '</div>' +
            '<div class="meta"><span class="mono">' + esc(b.code) + '</span> &middot; ' + TRD.km(b.distance_km) + ' &middot; <b>' + TRD.money(b.fare) + '</b> &middot; '
            + b.passengers + ' rider(s)' + (b.passenger.phone ? ' &middot; <a href="tel:' + esc(b.passenger.phone) + '">' + esc(b.passenger.phone) + '</a>' : '') + '</div>' +
            (b.notes ? '<div class="meta">Note: ' + esc(b.notes) + '</div>' : '') +
            '<div class="meta wait"></div>' +
            '<label>Driver</label><select class="sel-driver"></select>' +
            '<label>Tricycle unit</label><select class="sel-unit"></select>' +
            '<div class="actions"><button type="button" class="btn btn-assign" data-act="assign">Assign driver</button>' +
            '<button type="button" class="btn ghost" data-act="show">Show on map</button>' +
            '<button type="button" class="btn danger" data-act="reject">Reject</button></div>';
        el.querySelector('.sel-driver').addEventListener('change', () => {
            updateUnitSelect(el, b, el.querySelector('.sel-unit'), el.querySelector('.sel-driver'), '');
        });
        setTimeout(() => el.classList.remove('alert-new'), 4000);
        return el;
    }

    function renderPending() {
        const box = $('pending');
        const ids = new Set(data.pending.map((b) => b.id));
        cards.forEach((el, id) => { if (!ids.has(id)) { el.remove(); cards.delete(id); } });
        data.pending.forEach((b) => {
            let el = cards.get(b.id);
            if (!el) { el = buildCard(b); cards.set(b.id, el); }
            el.querySelector('.wait').textContent = 'Waiting ' + b.waiting_minutes + ' min';
            fillSelects(el, b);
            box.appendChild(el);                       // appendChild also keeps the oldest-first order
        });
        $('pending-empty').hidden = data.pending.length > 0;
        $('n-pending').textContent = data.pending.length;
    }

    function renderLive() {
        $('live').innerHTML = data.live.map((b) =>
            '<div class="item" data-id="' + b.id + '"><div class="head"><b>' + esc(b.driver ? b.driver.name : '') + '</b><span class="pill ' + esc(b.status) + '">' + esc(TRD.statusLabel(b.status)) + '</span></div>'
            + '<div class="route">' + esc(b.passenger.name) + ': ' + esc(b.pickup.label) + ' to ' + esc(b.dest.label) + '</div>'
            + '<div class="meta"><span class="mono">' + esc(b.code) + '</span> &middot; ' + (b.driver && b.driver.plate ? esc(b.driver.plate) + ' &middot; ' : '')
            + (b.driver && b.driver.fresh ? 'GPS live' : 'no live GPS') + '</div>'
            + (b.status === 'assigned' ? '<div class="actions"><button type="button" class="btn ghost" data-act="unassign" data-id="' + b.id + '">Take back</button></div>' : '')
            + '</div>').join('');
        $('live-empty').hidden = data.live.length > 0;
        $('n-live').textContent = data.live.length;
    }

    async function load() {
        const res = await TRD.api('operator_queue.php');
        if (!res.ok) { TRD.toast(res.error || 'Could not refresh dispatch.', 'error'); return; }
        data = res;
        const incoming = data.pending.filter((b) => !knownPending.has(b.id));
        if (loaded && incoming.length) { TRD.beep(); TRD.toast(incoming.length + ' new booking request(s)', 'success'); }
        loaded = true;
        knownPending = new Set(data.pending.map((b) => b.id));
        $('n-drivers').textContent = data.drivers.length;
        renderPending();
        renderLive();
        renderMap();
    }

    async function act(body) {
        const res = await TRD.api('operator_action.php', { method: 'POST', body });
        TRD.toast(res.ok ? 'Done.' : (res.error || 'That did not work.'), res.ok ? 'success' : 'error');
        load();
        return res;
    }

    $('pending').addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-act]');
        if (!btn) return;
        const card = btn.closest('.item');
        const id = parseInt(card.dataset.id, 10);
        if (btn.dataset.act === 'assign') {
            const driver = parseInt(card.querySelector('.sel-driver').value, 10);
            const unit = parseInt(card.querySelector('.sel-unit').value, 10);
            if (!driver) { TRD.toast('Choose a driver.', 'error'); return; }
            btn.disabled = true;
            act({ action: 'assign', booking_id: id, driver_id: driver, unit_id: unit || 0 }).then(() => { btn.disabled = false; });
        } else if (btn.dataset.act === 'reject') {
            const reason = window.prompt('Reason for rejecting (shown to the passenger):', 'No tricycle available');
            if (reason !== null) act({ action: 'reject', booking_id: id, reason });
        } else if (btn.dataset.act === 'show') {
            const b = data.pending.find((x) => x.id === id);
            if (b) map.setView([b.pickup.lat, b.pickup.lng], 16);
            $('map').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

    $('live').addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-act="unassign"]');
        if (btn && window.confirm('Take this booking back from the driver and return it to the queue?')) {
            act({ action: 'unassign', booking_id: parseInt(btn.dataset.id, 10) });
        }
    });

    TRD.poll(load);
})();
