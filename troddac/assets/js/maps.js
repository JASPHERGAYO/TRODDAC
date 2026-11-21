// Leaflet helpers shared by the passenger, driver and operator screens.
(function () {
    const TRD = window.TRD;
    const cfg = TRD.boot.map;

    const M = {};

    M.create = function (elementId, options = {}) {
        const map = L.map(elementId, { zoomControl: true, tap: true }).setView(options.center || cfg.center, options.zoom || cfg.zoom);
        L.tileLayer(cfg.tileUrl, { maxZoom: 19, attribution: cfg.attribution }).addTo(map);
        // Leaflet measures the box on creation; re-measure once layout has settled (phones rotate, panels resize).
        setTimeout(() => map.invalidateSize(), 250);
        window.addEventListener('resize', () => map.invalidateSize());
        window.addEventListener('orientationchange', () => setTimeout(() => map.invalidateSize(), 300));
        return map;
    };

    M.serviceArea = function (map) {
        return L.circle(cfg.center, { radius: cfg.radiusKm * 1000, color: '#3f8cff', weight: 1, fill: false, dashArray: '6 8', interactive: false }).addTo(map);
    };

    M.icon = function (kind, text) {
        return L.divIcon({
            className: '',
            html: '<div class="dot dot-' + kind + '">' + TRD.esc(text || '') + '</div>',
            iconSize: kind === 'me' ? [18, 18] : [28, 28],
            iconAnchor: kind === 'me' ? [9, 9] : [14, 14],
        });
    };

    M.marker = function (map, kind, text, lat, lng, options = {}) {
        return L.marker([lat, lng], Object.assign({ icon: M.icon(kind, text) }, options)).addTo(map);
    };

    M.fit = function (map, points, maxZoom) {
        const valid = points.filter((p) => p && Number.isFinite(p[0]) && Number.isFinite(p[1]));
        if (valid.length === 0) return;
        if (valid.length === 1) { map.setView(valid[0], maxZoom || 16); return; }
        map.fitBounds(L.latLngBounds(valid), { padding: [36, 36], maxZoom: maxZoom || 17 });
    };

    M.line = function (map, points, color) {
        return L.polyline(points, { color: color || '#1858bd', weight: 5, opacity: 0.85, lineJoin: 'round' }).addTo(map);
    };

    TRD.map = M;
})();
