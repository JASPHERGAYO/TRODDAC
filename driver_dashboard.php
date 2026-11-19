<?php
require 'config/auth.php';
require 'config/layout.php';
requireRole(['driver']);

dashHeader('Driver Dashboard');
?>
        <div class="greet"><h2>Hello, <?= htmlspecialchars($_SESSION['username']) ?></h2><p>Your trips for today.</p></div>

        <div class="row stats">
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('map') ?></span>Trips today</div><div class="stat-body"><b>0</b></div><span class="trend">None assigned</span></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('users') ?></span>Passengers</div><div class="stat-body"><b>0</b></div><span class="trend">None assigned</span></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('clock') ?></span>Status</div><div class="stat-body"><b>Offline</b></div><span class="trend">Not on duty</span></div>
        </div>

        <div class="panel glass" id="trips">
            <h3>My assigned trips</h3>
            <p class="note">No trips yet. Assigned trips will be listed here once a trips table exists.</p>
        </div>
<?php dashFooter();
