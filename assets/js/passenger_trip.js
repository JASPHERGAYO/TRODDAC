// Passenger trip screen: live status, driver position on the map, cancel.
(function () {
    const TRD = window.TRD;
    const M = TRD.map;
    const $ = (id) => document.getElementById(id);
    const esc = TRD.esc;

    const map = M.create('map');
    const layers = { pickup: null, dest: null, driver: null, route: null, fitted: false, geoKey: '' };
    let current = null;
    let poller = null;
    const STEP_INDEX = { available: 0, assigned: 1, en_route: 2, in_progress: 3, completed: 4 };
    const STEP_LABELS = ['Requested', 'Accepted', 'On the way', 'On trip', 'Arrived'];

    function renderSteps(status) {
        const box = $('steps');
        if (!(status in STEP_INDEX)) { box.hidden = true; return; }
        box.hidden = false;
        const idx = STEP_INDEX[status];
        box.innerHTML = STEP_LABELS.map((label, i) =>
            '<li class="' + (status === 'completed' || i < idx ? 'done' : (i === idx ? 'now' : '')) + '">' + esc(label) + '</li>').join('');
    }

    function renderDriver(b) {
        const box = $('driver-card');
        const d = b.driver;
        if (!d || !['assigned', 'en_route', 'in_progress', 'completed'].includes(b.status)) { box.hidden = true; return; }
        box.hidden = false;
        let away = '';
        if (b.live && d.distance_km !== undefined) {
            const mins = Math.max(1, Math.ceil(d.distance_km / 20 * 60));
            away = '<dt>Distance</dt><dd>' + d.distance_km.toFixed(1) + ' km ' + (b.status === 'in_progress' ? 'to your destination' : 'from your pickup') + ' (about ' + mins + ' min)</dd>';
        }
        const gps = b.live ? '<dt>GPS</dt><dd>' + (d.fresh ? 'Live' : 'Waiting for the driver\'s location') + '</dd>' : '';
        box.querySelector('dl').innerHTML =
            '<dt>Driver</dt><dd>' + esc(d.name) + '</dd>' +
            '<dt>Tricycle</dt><dd>' + esc(d.plate || '') + (d.color ? ' (' + esc(d.color) + ')' : '') + '</dd>' +
            (d.phone ? '<dt>Call</dt><dd><a href="tel:' + esc(d.phone) + '">' + esc(d.phone) + '</a></dd>' : '') + away + gps;
    }

    function drawMap(b) {
        if (!layers.pickup) {
            layers.pickup = M.marker(map, 'pickup', 'A', b.pickup.lat, b.pickup.lng, { interactive: false });
            layers.dest = M.marker(map, 'dest', 'B', b.dest.lat, b.dest.lng, { interactive: false });
        }
        const key = b.geometry.length + ':' + (b.geometry[0] || []).join(',');
        if (layers.geoKey !== key) {
            if (layers.route) map.removeLayer(layers.route);
            layers.route = b.geometry.length > 1 ? M.line(map, b.geometry, '#1858bd') : null;
            layers.geoKey = key;
        }
        const d = b.driver;
        if (b.live && d && d.lat !== null) {
            if (layers.driver) { layers.driver.setLatLng([d.lat, d.lng]); } else { layers.driver = M.marker(map, 'driver', 'T', d.lat, d.lng, { interactive: false, zIndexOffset: 500 }); }
        } else if (layers.driver) {
            map.removeLayer(layers.driver); layers.driver = null;
        }
        if (!layers.fitted) {
            M.fit(map, b.geometry.length > 1 ? b.geometry : [[b.pickup.lat, b.pickup.lng], [b.dest.lat, b.dest.lng]], 17);
            layers.fitted = true;
        }
    }

    function render(b) {
        current = b;
        $('status-pill').className = 'pill ' + b.status;
        $('status-pill').textContent = TRD.statusLabel(b.status);
        $('code').textContent = b.code;
        renderSteps(b.status);

        const headline = {
            available: 'Your request is now visible to every online driver. The first driver to accept it will come to you.',
            assigned: 'A driver has accepted your booking and will head to your pickup.',
            en_route: 'Your driver is on the way.',
            in_progress: 'Enjoy your ride. You will arrive soon.',
            completed: 'You have arrived. Thank you for riding with TRODDAC.',
            cancelled: 'This booking was cancelled.',
            rejected: 'Sorry, no driver was available. ' + (b.cancel_reason || ''),
        }[b.status] || '';
        $('headline').textContent = headline + (b.status === 'available' && b.online_drivers !== undefined
            ? ' ' + b.online_drivers + ' driver' + (b.online_drivers === 1 ? ' is' : 's are') + ' online now. This request closes in ' + b.expires_in_min + ' min if nobody accepts it.' : '');

        $('kv').innerHTML =
            '<dt>From</dt><dd>' + esc(b.pickup.label) + '</dd>' +
            '<dt>To</dt><dd>' + esc(b.dest.label) + '</dd>' +
            '<dt>Distance</dt><dd>' + TRD.km(b.distance_km) + (b.route_source === 'estimate' ? ' (estimate)' : '') + '</dd>' +
            '<dt>' + (b.final_fare !== null ? 'Fare paid' : 'Estimated fare') + '</dt><dd><b>' + TRD.money(b.final_fare !== null ? b.final_fare : b.fare) + '</b></dd>' +
            '<dt>Passengers</dt><dd>' + b.passengers + '</dd>' + (b.notes ? '<dt>Note</dt><dd>' + esc(b.notes) + '</dd>' : '');

        renderDriver(b);
        drawMap(b);

        const cancellable = ['available', 'assigned', 'en_route'].includes(b.status);
        $('cancel').hidden = !cancellable;
        $('again').hidden = b.live || b.status === 'available';

        if (!['available', 'assigned', 'en_route', 'in_progress'].includes(b.status) && poller) { poller.stop(); poller = null; }
    }

    async function load() {
        const id = TRD.boot.bookingId;
        const data = await TRD.api('booking_status.php?id=' + encodeURIComponent(id));
        if (!data.ok) { $('headline').textContent = data.error || 'Could not load this trip.'; return; }
        const previous = current ? current.status : null;
        render(data.booking);
        if (previous && previous !== data.booking.status) {
            if (data.booking.status === 'assigned') TRD.beep();
            TRD.toast(TRD.statusLabel(data.booking.status), data.booking.status === 'rejected' ? 'error' : 'info');
        }
    }

    $('cancel').addEventListener('click', async () => {
        if (!current || !window.confirm('Cancel this booking?')) return;
        $('cancel').disabled = true;
        const data = await TRD.api('booking_cancel.php', { method: 'POST', body: { id: current.id } });
        $('cancel').disabled = false;
        if (!data.ok) { TRD.toast(data.error || 'Could not cancel.', 'error'); }
        load();
    });

    poller = TRD.poll(load);
})();
