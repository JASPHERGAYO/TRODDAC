<?php
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/layout.php';
$user = requireRole(['operator', 'officer']);

dashHeader('Dispatch', 'operator_dispatch.php', ['maps' => true]);
?>
        <div class="greet"><h2>Dispatch</h2><p>New requests appear here automatically. Assign a driver and unit.</p></div>

        <div class="row stats">
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('ticket') ?></span>Waiting</div><div class="stat-body"><b id="n-pending">0</b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('map') ?></span>On trips</div><div class="stat-body"><b id="n-live">0</b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('car') ?></span>Drivers free</div><div class="stat-body"><b id="n-drivers">0</b></div></div>
        </div>

        <div class="split">
            <div class="col-main" style="display:flex;flex-direction:column;gap:12px">
                <div class="panel glass">
                    <h3>Booking requests</h3>
                    <div class="list" id="pending"></div>
                    <div class="empty" id="pending-empty">No requests waiting.</div>
                </div>
                <div class="panel glass">
                    <h3>Ongoing passenger trips</h3>
                    <div class="list" id="live"></div>
                    <div class="empty" id="live-empty">No trips in progress.</div>
                </div>
            </div>
            <div class="panel glass">
                <h3>Live map</h3>
                <p class="hint" style="margin:0 0 8px"><span class="dot dot-wait" style="display:inline-grid;width:18px;height:18px;font-size:10px">P</span> waiting pickup &nbsp;
                    <span class="dot dot-driver" style="display:inline-grid;width:18px;height:18px;font-size:10px">D</span> free driver &nbsp;
                    <span class="dot dot-driver" style="display:inline-grid;width:18px;height:18px;font-size:10px">T</span> driver on trip</p>
                <div id="map" class="map"></div>
            </div>
        </div>
<?php dashFooter(['operator_dispatch.js'], [], true);
