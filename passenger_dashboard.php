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
        <div class="greet"><div><h2>Hello, <?= e($user['full_name']) ?></h2><p>Where are you going today?</p></div></div>
        <div class="panel glass cta-panel">
            <div class="cta-lead">
                <span class="cta-ico"><?= icon('car') ?></span>
                <div><b><?= ((int)$s['open_trips'] > 0) ? 'Your trip is in progress' : 'Need a tricycle?' ?></b><p class="hint"><?= ((int)$s['open_trips'] > 0) ? 'Follow your tricycle on the map.' : 'Book with your GPS location and see the fare first.' ?></p></div>
            </div>
            <a class="btn" href="<?= ((int)$s['open_trips'] > 0) ? 'passenger_trip.php' : 'passenger_book.php' ?>"><?= ((int)$s['open_trips'] > 0) ? 'View my current trip' : 'Book a tricycle' ?></a>
        </div>
        <div class="row stats">
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('ticket') ?></span>All bookings</div><div class="stat-body"><b><?= (int)$s['total'] ?></b></div></div>
            <div class="stat-card glass<?= ((int)$s['open_trips'] > 0) ? ' attn' : '' ?>"><div class="stat-top"><span class="chip"><?= icon('clock') ?></span>Open trips</div><div class="stat-body"><b><?= (int)$s['open_trips'] ?></b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('map') ?></span>Completed</div><div class="stat-body"><b><?= (int)$s['completed'] ?></b></div></div>
        </div>
        <div class="panel glass">
            <div class="panel-head">
                <h3>Recent bookings</h3>
                <?php if ($recent): ?><a href="passenger_history.php">See all trips</a><?php endif; ?>
            </div>
            <div class="list">
                <?php foreach ($recent as $b): ?>
                    <div class="item">
                        <div class="head"><span class="mono"><?= e($b['booking_code']) ?></span><span class="pill <?= e($b['status']) ?>"><?= e(str_replace('_', ' ', $b['status'])) ?></span></div>
                        <div class="route"><?= routeStops((string)$b['pickup_label'], (string)$b['dest_label']) ?></div>
                        <div class="meta"><span><?= timeTag($b['requested_at']) ?> &middot; <?= e($b['distance_km']) ?> km</span><strong>&#8369;<?= e($b['estimated_fare']) ?></strong></div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$recent): ?><div class="empty">You have no bookings yet. <a href="passenger_book.php">Book your first ride</a>.</div><?php endif; ?>
            </div>
            <p class="hint panel-foot">Something went wrong on a trip? <a href="passenger_report.php">Send a report</a>.</p>
        </div>
<?php dashFooter();
