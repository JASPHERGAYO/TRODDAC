<?php
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/layout.php';
require __DIR__ . '/lib/Bookings.php';
$user = requireRole(['passenger']);
$pdo  = db();
Bookings::expireStale($pdo);

$id = (int)($_GET['id'] ?? 0);
if ($id === 0) {
    $st = $pdo->prepare("SELECT id FROM bookings WHERE passenger_id = ? AND status IN ('available','assigned','en_route','in_progress') ORDER BY id DESC LIMIT 1");
    $st->execute([$user['id']]);
    $id = (int)$st->fetchColumn();
    if ($id === 0) {
        flash('info', 'You have no open booking.');
        redirect('passenger_book.php');
    }
}
$st = $pdo->prepare('SELECT id FROM bookings WHERE id = ? AND passenger_id = ?');
$st->execute([$id, $user['id']]);
if (!$st->fetchColumn()) {
    flash('error', 'Booking not found.');
    redirect('passenger_history.php');
}

dashHeader('My trip', 'passenger_history.php', ['maps' => true]);
?>
        <div class="panel glass">
            <div class="item head" style="background:none;padding:0">
                <h3 style="margin:0">Trip <span id="code" class="mono"></span></h3>
                <span id="status-pill" class="pill">Loading</span>
            </div>
            <p id="headline" class="note" style="margin-top:.6rem">Loading your trip...</p>
            <ol class="steps" id="steps" hidden></ol>
        </div>

        <div class="split">
            <div class="col-main" style="display:flex;flex-direction:column;gap:12px">
                <div class="panel glass" id="driver-card" hidden>
                    <h3>Your driver</h3>
                    <dl class="kv"></dl>
                </div>
                <div class="panel glass">
                    <h3>Trip details</h3>
                    <dl class="kv" id="kv"></dl>
                    <div class="actions">
                        <button type="button" class="btn danger" id="cancel" hidden>Cancel booking</button>
                        <a class="btn" id="again" href="passenger_book.php" hidden>Book another trip</a>
                    </div>
                </div>
            </div>
            <div id="map" class="map"></div>
        </div>
<?php dashFooter(['passenger_trip.js'], ['bookingId' => $id], true);
