// Shared helpers for every dashboard script: TRD.boot, TRD.api, TRD.esc, TRD.poll, TRD.toast, TRD.beep.
(function () {
    const node = document.getElementById('boot');
    let boot = {};
    try { boot = node ? JSON.parse(node.textContent) : {}; } catch (e) { boot = {}; }

    const TRD = { boot };

    TRD.esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    TRD.money = (n) => '\u20B1' + Number(n || 0).toFixed(2);
    TRD.km = (n) => Number(n || 0).toFixed(2) + ' km';

    TRD.statusLabel = (s) => ({
        pending: 'Waiting for a driver', assigned: 'Driver assigned', en_route: 'Driver on the way',
        in_progress: 'On trip', completed: 'Completed', cancelled: 'Cancelled', rejected: 'No tricycle available',
    }[s] || s);

    /** Calls /api/<path>. Always resolves to an object with ok, status and (on failure) error. */
    TRD.api = async function (path, options = {}) {
        const method = options.method || 'GET';
        const init = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
        if (method !== 'GET') {
            init.headers['Content-Type'] = 'application/json';
            init.headers['X-CSRF-Token'] = boot.csrf || '';
            init.body = JSON.stringify(options.body || {});
        }
        let res;
        try {
            res = await fetch('api/' + path, init);
        } catch (e) {
            return { ok: false, status: 0, error: 'No connection. Check your internet and try again.' };
        }
        if (res.status === 401) {
            window.location.href = 'login.php';
            return { ok: false, status: 401, error: 'Please log in again.' };
        }
        let data;
        try { data = await res.json(); } catch (e) { data = { ok: false, error: 'Unexpected response from the server.' }; }
        data.status = res.status;
        if (res.status === 419) { data.error = 'Your session expired. Reload the page.'; }
        return data;
    };

    /** Runs fn now and then every few seconds while the tab is visible. Returns { stop }. */
    TRD.poll = function (fn, seconds) {
        let timer = null;
        let stopped = false;
        const delay = (seconds || boot.poll || 4) * 1000;
        const run = async () => {
            if (stopped) return;
            if (!document.hidden) {
                try { await fn(); } catch (e) { console.error(e); }
            }
            if (!stopped) timer = setTimeout(run, delay);
        };
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && !stopped) { clearTimeout(timer); run(); }
        });
        run();
        return { stop() { stopped = true; clearTimeout(timer); } };
    };

    TRD.toast = function (message, type) {
        let box = document.getElementById('toasts');
        if (!box) {
            box = document.createElement('div');
            box.id = 'toasts';
            box.setAttribute('aria-live', 'polite');
            box.style.cssText = 'position:fixed;left:12px;right:12px;bottom:calc(84px + env(safe-area-inset-bottom));z-index:2000;display:flex;flex-direction:column;gap:8px;pointer-events:none;align-items:center';
            document.body.appendChild(box);
        }
        const t = document.createElement('div');
        t.className = 'flash glass ' + (type || 'info');
        t.style.cssText = 'pointer-events:auto;max-width:520px;width:100%';
        t.textContent = message;
        box.appendChild(t);
        setTimeout(() => t.remove(), 5000);
    };

    let audio = null;
    TRD.beep = function () {
        try {
            audio = audio || new (window.AudioContext || window.webkitAudioContext)();
            const o = audio.createOscillator();
            const g = audio.createGain();
            o.type = 'sine'; o.frequency.value = 880;
            g.gain.setValueAtTime(0.0001, audio.currentTime);
            g.gain.exponentialRampToValueAtTime(0.25, audio.currentTime + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + 0.6);
            o.connect(g); g.connect(audio.destination);
            o.start(); o.stop(audio.currentTime + 0.65);
        } catch (e) { /* sound is optional */ }
        try { if (navigator.vibrate) navigator.vibrate([200, 100, 200]); } catch (e) { /* optional */ }
    };

    TRD.haversineKm = function (lat1, lng1, lat2, lng2) {
        const rad = (d) => d * Math.PI / 180;
        const a = Math.sin(rad(lat2 - lat1) / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(rad(lng2 - lng1) / 2) ** 2;
        return 2 * 6371.0088 * Math.asin(Math.min(1, Math.sqrt(a)));
    };

    window.TRD = TRD;
})();
