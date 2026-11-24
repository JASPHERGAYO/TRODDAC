// Read-only monitoring of passenger trips (operators no longer dispatch).
(function () {
    const TRD = window.TRD;
    const $ = (id) => document.getElementById(id);
    const esc = TRD.esc;

    function card(b) {
        return '<div class="item"><div class="head"><b>' + esc(b.passenger.name) + '</b><span class="pill ' + esc(b.status) + '">' + esc(TRD.statusLabel(b.status)) + '</span></div>'
            + '<div class="route">' + esc(b.pickup.label) + ' &rarr; ' + esc(b.dest.label) + '</div>'
            + '<div class="meta"><span class="mono">' + esc(b.code) + '</span> &middot; ' + TRD.km(b.distance_km) + ' &middot; ' + TRD.money(b.final_fare !== null ? b.final_fare : b.fare)
            + (b.driver ? ' &middot; ' + esc(b.driver.name) + (b.driver.plate ? ' (' + esc(b.driver.plate) + ')' : '') : '')
            + (b.cancel_reason && ['cancelled', 'rejected'].includes(b.status) ? '<br>' + esc(b.cancel_reason) : '') + '</div></div>';
    }
    function fill(id, list) {
        $(id).innerHTML = list.map(card).join('');
        $(id + '-empty').hidden = list.length > 0;
    }
    async function load() {
        const d = await TRD.api('trips_overview.php');
        if (!d.ok) return;
        $('n-open').textContent = d.open.length;
        $('n-live').textContent = d.live.length;
        $('n-online').textContent = d.online_drivers;
        fill('open', d.open); fill('live', d.live); fill('recent', d.recent);
    }
    TRD.poll(load, 6);
})();
