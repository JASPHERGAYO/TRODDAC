<?php
require 'config/auth.php';
require 'config/layout.php';
requireRole(['passenger']);

dashHeader('Passenger Dashboard');
?>
        <div class="greet"><h2>Hello, <?= htmlspecialchars($_SESSION['username']) ?></h2><p>Where are you headed today?</p></div>

        <div class="row stats">
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('ticket') ?></span>Upcoming bookings</div><div class="stat-body"><b>0</b></div><span class="trend">Nothing booked</span></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('clock') ?></span>Past trips</div><div class="stat-body"><b>0</b></div><span class="trend">No history yet</span></div>
        </div>

        <div class="row two">
            <div class="panel glass" id="book">
                <h3>Book a ride</h3>
                <p class="note">The booking form will go here.</p>
            </div>
            <div class="panel glass" id="history">
                <h3>My trip history</h3>
                <p class="note">No trips yet.</p>
            </div>
        </div>
<?php dashFooter();
