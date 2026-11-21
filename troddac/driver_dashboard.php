<?php
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/layout.php';
$user = requireRole(['driver']);

$st = db()->prepare('SELECT d.id, d.license_number, d.license_expiry, tu.plate_number, tu.body_number
                       FROM drivers d LEFT JOIN tricycle_units tu ON tu.id = d.default_unit_id WHERE d.user_id = ?');
$st->execute([$user['id']]);
$driver = $st->fetch();

dashHeader('Driver Dashboard', 'driver_dashboard.php', ['maps' => (bool)$driver]);
if (!$driver): ?>
        <div class="greet"><h2>Hello, <?= e($user['full_name']) ?></h2></div>
        <div class="panel glass"><p class="note">Your driver profile has not been set up yet. Ask the Association Officer.</p></div>
<?php dashFooter(); return; endif; ?>
        <div class="greet"><h2>Hello, <?= e($user['full_name']) ?></h2><p>Unit <?= e($driver['plate_number'] ?? 'not assigned') ?> &middot; License <span class="mono"><?= e($driver['license_number']) ?></span></p></div>

        <div class="panel glass switch-card">
            <div>
                <div class="muted" style="font-size:.8rem">Your status</div>
                <div class="state" id="avail-state">...</div>
                <div class="hint"><span class="live-dot" id="gps-dot"></span><span id="gps-text">GPS idle</span></div>
            </div>
            <button type="button" class="btn" id="avail-btn" disabled>...</button>
        </div>

        <div class="panel glass empty" id="waiting">Loading...</div>

        <div class="panel glass item" id="booking" hidden>
            <div class="head"><h3 style="margin:0">Booking <span class="mono" id="b-code"></span></h3><span class="pill" id="b-pill"></span></div>
            <dl class="kv" id="b-kv" style="margin-top:.6rem"></dl>
            <div class="actions" id="b-actions"></div>
        </div>

        <div class="split">
            <div id="map" class="map"></div>
            <div class="panel glass">
                <h3>Upcoming official routes</h3>
                <div class="list" id="routes"></div>
            </div>
        </div>
<?php dashFooter(['driver_app.js'], [], true);
