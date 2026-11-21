// Driver screen: availability switch, GPS sharing, incoming booking from dispatch, trip actions.
(function () {
    const TRD = window.TRD;
    const M = TRD.map;
    const $ = (id) => document.getElementById(id);
    const esc = TRD.esc;

    const map = M.create('map');
    const layers = { pickup: null, dest: null, route: null, me: null, key: '', okey: '', oOrigin: null, oDest: null, oRoute: null };
    const state = { availability: 'offline', booking: null, lastBookingId: null, watchId: null, lastSent: 0, lastPos: null, busy: false };

    /* ---------- GPS sharing ---------- */
    function gpsStatus(text, cls) {
        $('gps-text').textContent = text;
        $('gps-dot').className = 'live-dot ' + (cls || '');
    }

    function startGps() {
        if (state.watchId !== null) return;
        if (!navigator.geolocation) { gpsStatus('This device cannot share GPS', 'warn'); return; }
        if (!window.isSecureContext && !['localhost', '127.0.0.1'].includes(location.hostname)) {
            gpsStatus('GPS needs a secure (https) page', 'warn');
            return;
        }
        gpsStatus('Waiting for GPS...', 'warn');
        state.watchId = navigator.geolocation.watchPosition((pos) => {
            const { latitude: lat, longitude: lng, speed, heading } = pos.coords;
            state.lastPos = [lat, lng];
            if (layers.me) { layers.me.setLatLng([lat, lng]); } else { layers.me = M.marker(map, 'me', '', lat, lng, { interactive: false }); }
            gpsStatus('Sharing your location', 'on');
            const now = Date.now();
            if (now - state.lastSent >= 5000 && state.availability !== 'offline') {
                state.lastSent = now;
                TRD.api('driver_location.php', { method: 'POST', body: { lat, lng, speed, heading } });
            }
        }, (err) => {
            gpsStatus(err.code === 1 ? 'Location blocked. Allow it in your browser settings.' : 'GPS signal lost. Retrying...', 'warn');
        }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 });
    }

    function stopGps() {
        if (state.watchId !== null) { navigator.geolocation.clearWatch(state.watchId); state.watchId = null; }
        gpsStatus('Not sharing (you are offline)', '');
    }

    /* ---------- availability ---------- */
    function renderAvailability() {
        const a = state.availability;
        $('avail-state').textContent = a === 'online' ? 'Online' : (a === 'on_trip' ? 'On trip' : 'Offline');
        const btn = $('avail-btn');
        btn.textContent = a === 'offline' ? 'Go online' : 'Go offline';
        btn.className = 'btn ' + (a === 'offline' ? 'ok' : 'ghost');
        btn.disabled = a === 'on_trip';
        btn.title = a === 'on_trip' ? 'Finish your passenger trip first' : '';
        if (a === 'offline') { stopGps(); } else { startGps(); }
    }

    $('avail-btn').addEventListener('click', async () => {
        const target = state.availability === 'offline' ? 'online' : 'offline';
        $('avail-btn').disabled = true;
        const data = await TRD.api('driver_availability.php', { method: 'POST', body: { status: target } });
        if (!data.ok) { TRD.toast(data.error || 'Could not change your status.', 'error'); }
        else { state.availability = data.availability; renderAvailability(); }
        load();
    });

    /* ---------- booking card ---------- */
    function renderBookingCard(b, isNew) {
        const card = $('booking');
        if (!b) {
            card.hidden = true;
            $('waiting').hidden = false;
            $('waiting').textContent = state.availability === 'offline'
                ? 'You are offline. Go online to receive bookings from dispatch.'
                : 'Waiting for dispatch to send you a booking...';
            return;
        }
        $('waiting').hidden = true;
        card.hidden = false;
        card.classList.toggle('alert-new', Boolean(isNew));

        $('b-pill').className = 'pill ' + b.status;
        $('b-pill').textContent = TRD.statusLabel(b.status);
        $('b-code').textContent = b.code;
        const nav = (p) => 'https://www.google.com/maps/dir/?api=1&destination=' + p.lat + ',' + p.lng + '&travelmode=driving';
        const target = b.status === 'in_progress' ? b.dest : b.pickup;
        $('b-kv').innerHTML =
            '<dt>Passenger</dt><dd>' + esc(b.passenger.name) + (b.passengers > 1 ? ' (' + b.passengers + ' riders)' : '') + '</dd>' +
            (b.passenger.phone ? '<dt>Call</dt><dd><a href="tel:' + esc(b.passenger.phone) + '">' + esc(b.passenger.phone) + '</a></dd>' : '') +
            '<dt>Pickup</dt><dd>' + esc(b.pickup.label) + '</dd>' +
            '<dt>Drop-off</dt><dd>' + esc(b.dest.label) + '</dd>' +
            '<dt>Trip</dt><dd>' + TRD.km(b.distance_km) + ', about ' + b.est_minutes + ' min</dd>' +
            '<dt>Fare</dt><dd><b>' + TRD.money(b.fare) + '</b></dd>' +
            (b.unit && b.unit.plate ? '<dt>Unit</dt><dd>' + esc(b.unit.plate) + '</dd>' : '') +
            (b.notes ? '<dt>Note</dt><dd>' + esc(b.notes) + '</dd>' : '');

        const actions = [];
        if (b.status === 'assigned') {
            actions.push(['on_the_way', 'On my way', 'btn']);
            actions.push(['picked_up', 'Picked up', 'btn ok']);
            actions.push(['decline', 'Decline', 'btn ghost']);
        } else if (b.status === 'en_route') {
            actions.push(['picked_up', 'Passenger picked up', 'btn ok']);
        } else if (b.status === 'in_progress') {
            actions.push(['complete', 'Complete trip', 'btn ok']);
        }
        $('b-actions').innerHTML = actions.map(([a, label, cls]) => '<button type="button" class="' + cls + '" data-action="' + a + '">' + esc(label) + '</button>').join('')
            + '<a class="btn ghost" target="_blank" rel="noopener" href="' + esc(nav(target)) + '">Open in Maps</a>';
    }

    $('b-actions').addEventListener('click', async (e) => {
        const btn = e.target.closest('button[data-action]');
        if (!btn || !state.booking || state.busy) return;
        const action = btn.dataset.action;
        if (action === 'decline' && !window.confirm('Decline this booking? Dispatch will send it to another driver.')) return;
        if (action === 'complete' && !window.confirm('Mark this trip as completed?')) return;
        state.busy = true;
        btn.disabled = true;
        const data = await TRD.api('driver_action.php', { method: 'POST', body: { id: state.booking.id, action } });
        state.busy = false;
        if (!data.ok) { TRD.toast(data.error || 'That did not work.', 'error'); }
        else if (action === 'complete') { TRD.toast('Trip completed. You are back online.', 'success'); }
        else if (action === 'decline') { TRD.toast('Booking declined.', 'info'); }
        load();
    });

    /* ---------- map ---------- */
    function renderMap(b) {
        const key = b ? b.id + ':' + b.status : '';
        if (key === layers.key) return;
        layers.key = key;
        ['pickup', 'dest', 'route'].forEach((k) => { if (layers[k]) { map.removeLayer(layers[k]); layers[k] = null; } });
        if (!b) {
            if (state.lastPos) map.setView(state.lastPos, 16);
            return;
        }
        layers.pickup = M.marker(map, 'pickup', 'A', b.pickup.lat, b.pickup.lng, { interactive: false });
        layers.dest = M.marker(map, 'dest', 'B', b.dest.lat, b.dest.lng, { interactive: false });
        if (b.geometry.length > 1) layers.route = M.line(map, b.geometry, '#1858bd');
        const pts = (b.geometry.length > 1 ? b.geometry.slice() : [[b.pickup.lat, b.pickup.lng], [b.dest.lat, b.dest.lng]]);
        if (state.lastPos) pts.push(state.lastPos);
        M.fit(map, pts, 17);
    }

    /* ---------- official routes (Module B) ---------- */
    function renderOfficial(list) {
        const when = (a) => a.start.slice(0, 10) + ' ' + a.start.slice(11, 16) + ' to ' + a.end.slice(11, 16);
        $('official').innerHTML = list.length
            ? list.map((a, i) => '<div class="item' + (i === 0 ? ' alert-new' : '') + '" style="' + (i === 0 ? '' : 'opacity:.85') + '">'
                + '<div class="head"><b>' + esc(a.route.code + ' - ' + a.route.name) + '</b><span class="pill ' + (a.status === 'active' ? 'in_progress' : 'assigned') + '">' + (a.status === 'active' ? 'On route now' : 'Scheduled') + '</span></div>'
                + '<div class="route"><b>Terminal:</b> ' + esc(a.origin.name) + ' &rarr; ' + esc(a.dest.name) + '</div>'
                + '<div class="meta">' + esc(when(a)) + ' &middot; ' + a.route.distance_km.toFixed(1) + ' km' + (a.plate ? ' &middot; ' + esc(a.plate) : '') + '</div></div>').join('')
            : '<div class="empty">No official route assigned yet. Assignments are made automatically in rotation.</div>';
    }

    function renderOfficialMap(a) {
        const key = a ? 'o' + a.id + ':' + a.status : '';
        if (key === layers.okey) return;
        layers.okey = key;
        ['oOrigin', 'oDest', 'oRoute'].forEach((k) => { if (layers[k]) { map.removeLayer(layers[k]); layers[k] = null; } });
        if (!a) return;
        layers.oOrigin = M.marker(map, 'pickup', 'T1', a.origin.lat, a.origin.lng, { interactive: false }).bindTooltip(esc(a.origin.name));
        layers.oDest = M.marker(map, 'dest', 'T2', a.dest.lat, a.dest.lng, { interactive: false }).bindTooltip(esc(a.dest.name));
        if (a.geometry.length > 1) layers.oRoute = L.polyline(a.geometry, { color: '#d97706', weight: 5, opacity: 0.85, dashArray: '10 8' }).addTo(map);
        M.fit(map, (a.geometry.length > 1 ? a.geometry : [[a.origin.lat, a.origin.lng], [a.dest.lat, a.dest.lng]]), 16);
    }

    /* ---------- polling ---------- */
    async function load() {
        const data = await TRD.api('driver_state.php');
        if (!data.ok) {
            $('waiting').hidden = false;
            $('waiting').textContent = data.error || 'Could not load your dashboard.';
            return;
        }
        state.availability = data.availability;
        renderAvailability();

        const b = data.booking;
        const isNew = Boolean(b) && b.status === 'assigned' && b.id !== state.lastBookingId;
        if (isNew) {
            TRD.beep();
            TRD.toast('New booking from dispatch: ' + b.pickup.label, 'success');
            document.title = '(1) New booking - TRODDAC';
        } else if (!b) {
            document.title = 'Driver Dashboard - TRODDAC';
        }
        state.lastBookingId = b ? b.id : state.lastBookingId;
        state.booking = b;
        renderBookingCard(b, isNew);
        renderMap(b);
        renderOfficial(data.official || []);
        renderOfficialMap(b ? null : (data.official && data.official[0]) || null);
    }

    TRD.poll(load);
})();
