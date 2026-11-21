// Passenger booking screen: pick pickup (GPS or map tap) and destination, see road route + fare, send the request.
(function () {
    const TRD = window.TRD;
    const M = TRD.map;
    const $ = (id) => document.getElementById(id);
    const area = TRD.boot.map;

    const state = { pickup: null, dest: null, mode: 'pickup', routeToken: 0, route: null, busy: false };
    const markers = { pickup: null, dest: null, me: null };
    let routeLine = null;

    const map = M.create('map');
    M.serviceArea(map);
    TRD.bookMap = map;                 // exposed so automated UI tests can tap the map

    const els = {
        pickupText: $('pickup-text'), destText: $('dest-text'), msg: $('msg'), submit: $('submit'),
        dist: $('f-dist'), time: $('f-time'), fare: $('f-fare'), note: $('route-note'),
        modePickup: $('mode-pickup'), modeDest: $('mode-dest'), gps: $('btn-gps'),
        q: $('search-q'), searchBtn: $('search-btn'), results: $('results'),
        pax: $('pax'), notes: $('notes'), tip: $('map-tip'),
    };

    function say(text, type) {
        els.msg.textContent = text || '';
        els.msg.className = 'flash glass ' + (type || 'info');
        els.msg.hidden = !text;
    }

    function inArea(lat, lng) {
        return TRD.haversineKm(area.center[0], area.center[1], lat, lng) <= area.radiusKm;
    }

    function setMode(mode) {
        state.mode = mode;
        els.modePickup.classList.toggle('ghost', mode !== 'pickup');
        els.modeDest.classList.toggle('ghost', mode !== 'dest');
        els.tip.textContent = mode === 'pickup' ? 'Tap the map to set your pickup point' : 'Tap the map to set your destination';
    }

    function shortName(data) {
        if (!data) return null;
        const name = data.name || '';
        const parts = String(data.display_name || '').split(',').map((s) => s.trim()).filter(Boolean);
        const text = (name && parts[0] !== name ? name + ', ' : '') + parts.slice(0, 3).join(', ');
        return text.slice(0, 140) || null;
    }

    async function reverse(kind, token) {
        const p = state[kind];
        if (!p) return;
        const ctl = new AbortController();
        const timer = setTimeout(() => ctl.abort(), 6000);
        try {
            const res = await fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=18&lat=' + p.lat + '&lon=' + p.lng,
                { signal: ctl.signal, headers: { Accept: 'application/json' } });
            const data = await res.json();
            if (state[kind] === p && p.token === token) { p.label = shortName(data); renderPoints(); }
        } catch (e) { /* the coordinates remain as the label */ } finally { clearTimeout(timer); }
    }

    function renderPoints() {
        const fmt = (p) => p ? (p.label || ('Pinned location (' + p.lat.toFixed(5) + ', ' + p.lng.toFixed(5) + ')')) : 'Not set yet';
        els.pickupText.textContent = fmt(state.pickup);
        els.destText.textContent = fmt(state.dest);
    }

    function setPoint(kind, lat, lng, label) {
        if (!inArea(lat, lng)) {
            say('That spot is outside the TRODDAC service area. Choose a place closer to the city.', 'error');
            return false;
        }
        say('');
        const token = (state[kind] ? state[kind].token : 0) + 1;
        state[kind] = { lat, lng, label: label || null, token };
        if (markers[kind]) {
            markers[kind].setLatLng([lat, lng]);
        } else {
            markers[kind] = M.marker(map, kind === 'pickup' ? 'pickup' : 'dest', kind === 'pickup' ? 'A' : 'B', lat, lng, { draggable: true });
            markers[kind].on('dragend', (e) => { const ll = e.target.getLatLng(); setPoint(kind, ll.lat, ll.lng); });
        }
        renderPoints();
        if (!label) reverse(kind, token);
        if (kind === 'pickup' && !state.dest) setMode('dest');
        refreshRoute();
        return true;
    }

    function clearRoute() {
        if (routeLine) { map.removeLayer(routeLine); routeLine = null; }
        state.route = null;
        els.dist.textContent = els.time.textContent = els.fare.textContent = '--';
        els.note.textContent = '';
        els.submit.disabled = true;
    }

    async function refreshRoute() {
        clearRoute();
        if (!state.pickup || !state.dest) {
            M.fit(map, [state.pickup && [state.pickup.lat, state.pickup.lng], state.dest && [state.dest.lat, state.dest.lng]], 16);
            return;
        }
        const token = ++state.routeToken;
        els.note.textContent = 'Finding the best road route...';
        const q = 'route.php?plat=' + state.pickup.lat + '&plng=' + state.pickup.lng + '&dlat=' + state.dest.lat + '&dlng=' + state.dest.lng;
        const data = await TRD.api(q);
        if (token !== state.routeToken) return;           // a newer request replaced this one
        if (!data.ok) {
            els.note.textContent = '';
            say(data.error || 'Could not work out the route.', 'error');
            return;
        }
        say('');
        state.route = data;
        routeLine = M.line(map, data.geometry, '#1858bd');
        M.fit(map, data.geometry, 17);
        els.dist.textContent = data.distance_km.toFixed(2) + ' km';
        els.time.textContent = '~' + data.duration_min + ' min';
        els.fare.textContent = TRD.money(data.fare);
        els.note.textContent = data.source === 'estimate'
            ? 'Road service is unavailable, so distance and fare are estimates. The final fare is confirmed by the system.'
            : 'Fare: ' + TRD.money(data.fare_matrix.base_fare) + ' for the first ' + data.fare_matrix.base_distance_km + ' km, then '
              + TRD.money(data.fare_matrix.per_km_rate) + ' per km.';
        els.submit.disabled = false;
    }

    /* ---- GPS ---- */
    function locate() {
        if (!navigator.geolocation) { say('This device cannot share its location. Tap the map to set your pickup.', 'error'); return; }
        if (!window.isSecureContext && !['localhost', '127.0.0.1'].includes(location.hostname)) {
            say('Browsers only allow GPS on secure (https) pages. Tap the map to set your pickup instead.', 'error');
            return;
        }
        els.gps.disabled = true;
        els.gps.textContent = 'Finding you...';
        navigator.geolocation.getCurrentPosition((pos) => {
            els.gps.disabled = false;
            els.gps.textContent = 'Use my location';
            const { latitude: lat, longitude: lng, accuracy } = pos.coords;
            if (markers.me) { markers.me.setLatLng([lat, lng]); } else { markers.me = M.marker(map, 'me', '', lat, lng, { interactive: false }); }
            if (setPoint('pickup', lat, lng)) {
                map.setView([lat, lng], 17);
                if (accuracy > 150) say('Your location is only accurate to about ' + Math.round(accuracy) + ' m. Drag the A pin to the exact spot.', 'info');
            }
        }, (err) => {
            els.gps.disabled = false;
            els.gps.textContent = 'Use my location';
            const reasons = { 1: 'Location permission was denied. Allow it in your browser settings, or tap the map.', 2: 'Your location could not be found. Tap the map to set your pickup.', 3: 'Finding your location took too long. Try again or tap the map.' };
            say(reasons[err.code] || 'Could not get your location.', 'error');
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 10000 });
    }

    /* ---- address search (button or Enter only, to respect the free geocoder's usage policy) ---- */
    async function search() {
        const text = els.q.value.trim();
        if (text.length < 3) { say('Type at least 3 letters to search.', 'error'); return; }
        els.results.innerHTML = '<li class="hint">Searching...</li>';
        const lat = area.center[0], lng = area.center[1], d = 0.3;
        const url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&countrycodes=ph&q=' + encodeURIComponent(text)
            + '&viewbox=' + (lng - d) + ',' + (lat + d) + ',' + (lng + d) + ',' + (lat - d);
        try {
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            const list = await res.json();
            els.results.innerHTML = '';
            if (!list.length) { els.results.innerHTML = '<li class="hint">No places found. Try another name or tap the map.</li>'; return; }
            list.forEach((item) => {
                const li = document.createElement('li');
                const b = document.createElement('button');
                b.type = 'button';
                b.textContent = shortName(item) || item.display_name;
                b.addEventListener('click', () => {
                    const kind = state.mode;
                    if (setPoint(kind, parseFloat(item.lat), parseFloat(item.lon), shortName(item))) {
                        map.setView([parseFloat(item.lat), parseFloat(item.lon)], 17);
                        els.results.innerHTML = '';
                        els.q.value = '';
                    }
                });
                li.appendChild(b);
                els.results.appendChild(li);
            });
        } catch (e) {
            els.results.innerHTML = '<li class="hint">Search is unavailable. Tap the map instead.</li>';
        }
    }

    /* ---- submit ---- */
    async function submit() {
        if (state.busy || !state.route) return;
        state.busy = true;
        els.submit.disabled = true;
        els.submit.textContent = 'Sending request...';
        const data = await TRD.api('booking_create.php', {
            method: 'POST',
            body: {
                pickup: { lat: state.pickup.lat, lng: state.pickup.lng, label: state.pickup.label || '' },
                dest: { lat: state.dest.lat, lng: state.dest.lng, label: state.dest.label || '' },
                passengers: parseInt(els.pax.value, 10) || 1,
                notes: els.notes.value,
            },
        });
        if (data.ok) {
            window.location.href = 'passenger_trip.php?id=' + data.id;
            return;
        }
        state.busy = false;
        els.submit.disabled = false;
        els.submit.textContent = 'Request tricycle';
        if (data.status === 409) {
            say(data.error + ' ', 'error');
            const a = document.createElement('a');
            a.href = 'passenger_trip.php'; a.textContent = 'View my current trip';
            els.msg.appendChild(a);
        } else {
            say(data.error || 'Could not send the request.', 'error');
        }
    }

    /* ---- wiring ---- */
    map.on('click', (e) => setPoint(state.mode, e.latlng.lat, e.latlng.lng));
    els.modePickup.addEventListener('click', () => setMode('pickup'));
    els.modeDest.addEventListener('click', () => setMode('dest'));
    els.gps.addEventListener('click', locate);
    els.searchBtn.addEventListener('click', search);
    els.q.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); search(); } });
    els.submit.addEventListener('click', submit);

    setMode('pickup');
    renderPoints();
    clearRoute();
    // If location permission was already granted, start from the passenger's position automatically.
    if (navigator.permissions && navigator.permissions.query) {
        navigator.permissions.query({ name: 'geolocation' }).then((p) => { if (p.state === 'granted') locate(); }).catch(() => {});
    }
})();
