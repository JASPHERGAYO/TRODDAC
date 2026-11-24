<?php
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/layout.php';
$user = requireRole(['operator', 'officer']);

dashHeader('Passenger trips', 'operator_trips.php');
?>
        <div class="greet"><h2>Passenger trips</h2><p>Live view of bookings. Drivers accept bookings themselves, so there is nothing to dispatch here.</p></div>

        <div class="row stats">
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('ticket') ?></span>Open bookings</div><div class="stat-body"><b id="n-open">0</b></div><span class="trend">Waiting for a driver</span></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('map') ?></span>On trips</div><div class="stat-body"><b id="n-live">0</b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('car') ?></span>Drivers online</div><div class="stat-body"><b id="n-online">0</b></div></div>
        </div>

        <div class="split">
            <div class="col-main" style="display:flex;flex-direction:column;gap:12px">
                <div class="panel glass"><h3>Waiting for a driver</h3><div class="list" id="open"></div><div class="empty" id="open-empty" hidden>No open bookings.</div></div>
                <div class="panel glass"><h3>Ongoing trips</h3><div class="list" id="live"></div><div class="empty" id="live-empty" hidden>No trips in progress.</div></div>
            </div>
            <div class="panel glass"><h3>Recent history</h3><div class="list" id="recent"></div><div class="empty" id="recent-empty" hidden>No finished trips yet.</div></div>
        </div>
<?php dashFooter(['operator_trips.js']);
