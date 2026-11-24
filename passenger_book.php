<?php
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/layout.php';
require __DIR__ . '/lib/Bookings.php';
$user = requireRole(['passenger']);

Bookings::expireStale(db());
$open = db()->prepare("SELECT id FROM bookings WHERE passenger_id = ? AND status IN ('available','assigned','en_route','in_progress') ORDER BY id DESC LIMIT 1");
$open->execute([$user['id']]);
if ($openId = $open->fetchColumn()) {
    flash('info', 'You already have an open booking. Finish or cancel it before booking again.');
    redirect('passenger_trip.php?id=' . (int)$openId);
}

dashHeader('Book a tricycle', 'passenger_book.php', ['maps' => true]);
?>
        <div class="greet"><h2>Book a tricycle</h2><p>Set your pickup with GPS or the map, then choose where you are going.</p></div>

        <div class="book-layout">
            <div style="position:relative">
                <div id="map" class="map"></div>
                <div id="map-tip" class="map-tip">Tap the map to set your pickup point</div>
            </div>

            <div class="panel glass" id="sheet">
                <div id="msg" class="flash glass info" role="status" hidden></div>

                <div class="actions" style="margin-top:0">
                    <button type="button" class="btn" id="btn-gps"><?= icon('pin') ?>Use my location</button>
                </div>

                <div class="pick-row">
                    <span class="dot dot-pickup">A</span>
                    <div class="txt"><b>Pickup</b><span id="pickup-text">Not set yet</span></div>
                    <button type="button" class="small-btn" id="mode-pickup">Set on map</button>
                </div>
                <div class="pick-row">
                    <span class="dot dot-dest">B</span>
                    <div class="txt"><b>Destination</b><span id="dest-text">Not set yet</span></div>
                    <button type="button" class="small-btn ghost" id="mode-dest">Set on map</button>
                </div>

                <label for="search-q">Search a place (optional)</label>
                <div class="search-row">
                    <input type="search" id="search-q" placeholder="e.g. SM Dasmari&ntilde;as" autocomplete="off">
                    <button type="button" class="small-btn" id="search-btn">Search</button>
                </div>
                <ul class="results" id="results"></ul>

                <div class="fare-box" aria-live="polite">
                    <div><b id="f-dist">--</b><span>Road distance</span></div>
                    <div><b id="f-time">--</b><span>Travel time</span></div>
                    <div class="fare"><b id="f-fare">--</b><span>Estimated fare</span></div>
                </div>
                <p class="hint" id="route-note"></p>

                <div class="form-grid">
                    <div><label for="pax">Passengers</label>
                        <select id="pax"><?php for ($i = 1; $i <= 6; $i++): ?><option value="<?= $i ?>"><?= $i ?></option><?php endfor; ?></select></div>
                    <div><label for="notes">Note for the driver (optional)</label>
                        <input type="text" id="notes" maxlength="255" placeholder="Landmark, gate color..."></div>
                </div>

                <div class="actions">
                    <button type="button" class="btn wide" id="submit" disabled>Request tricycle</button>
                </div>
            </div>
        </div>
<?php dashFooter(['passenger_book.js'], [], true);
