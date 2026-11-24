<?php
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/layout.php';
require __DIR__ . '/lib/Bookings.php';
$user = requireRole(['passenger']);
Bookings::expireStale(db());

$st = db()->prepare("SELECT COUNT(*) total,
                            SUM(status IN ('available','assigned','en_route','in_progress')) open_trips,
                            SUM(status = 'completed') completed
                       FROM bookings WHERE passenger_id = ?");
$st->execute([$user['id']]);
$s = $st->fetch();

$recent = db()->prepare('SELECT booking_code, pickup_label, dest_label, distance_km, estimated_fare, status, requested_at
                           FROM bookings WHERE passenger_id = ? ORDER BY requested_at DESC LIMIT 5');
$recent->execute([$user['id']]);
$recent = $recent->fetchAll();

dashHeader('Passenger Dashboard', 'passenger_dashboard.php');
?>
        <div class="greet"><h2>Hello, <?= e($user['full_name']) ?></h2><p>Where are you going today?</p></div>
        <div class="panel glass" style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap">
            <div><b>Need a tricycle?</b><div class="hint" style="margin:0">Book with your GPS location and see the fare first.</div></div>
            <a class="btn" href="<?= ((int)$s['open_trips'] > 0) ? 'passenger_trip.php' : 'passenger_book.php' ?>"><?= ((int)$s['open_trips'] > 0) ? 'View my current trip' : 'Book a tricycle' ?></a>
        </div>
        <div class="row stats">
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('ticket') ?></span>All bookings</div><div class="stat-body"><b><?= (int)$s['total'] ?></b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('clock') ?></span>Open trips</div><div class="stat-body"><b><?= (int)$s['open_trips'] ?></b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('map') ?></span>Completed</div><div class="stat-body"><b><?= (int)$s['completed'] ?></b></div></div>
        </div>
        <div class="panel glass">
            <h3>Recent bookings</h3>
            <div class="list">
                <?php foreach ($recent as $b): ?>
                    <div class="item">
                        <div class="head"><span class="mono"><?= e($b['booking_code']) ?></span><span class="pill <?= e($b['status']) ?>"><?= e(str_replace('_', ' ', $b['status'])) ?></span></div>
                        <div class="route"><?= e($b['pickup_label']) ?> &rarr; <?= e($b['dest_label']) ?></div>
                        <div class="meta"><?= e($b['requested_at']) ?> &middot; <?= e($b['distance_km']) ?> km &middot; &#8369;<?= e($b['estimated_fare']) ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$recent): ?><div class="empty">You have no bookings yet.</div><?php endif; ?>
            </div>
            <?php if ($recent): ?><p style="margin:12px 0 0"><a href="passenger_history.php">See all trips</a></p><?php endif; ?>
            <p style="margin:12px 0 0" class="hint">Something went wrong on a trip? <a href="passenger_report.php">Send a report</a>.</p>
        </div>
<?php dashFooter();
