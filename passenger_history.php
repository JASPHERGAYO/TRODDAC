<?php
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/layout.php';
$user = requireRole(['passenger']);

$st = db()->prepare('SELECT id, booking_code, pickup_label, dest_label, distance_km, estimated_fare, final_fare, status, requested_at
                       FROM bookings WHERE passenger_id = ? ORDER BY requested_at DESC, id DESC LIMIT 100');
$st->execute([$user['id']]);
$rows = $st->fetchAll();

dashHeader('My trips', 'passenger_history.php');
?>
        <div class="greet">
            <div><h2>My trips</h2><p>Your booking history.</p></div>
            <a class="small-btn" href="passenger_book.php">Book a ride</a>
        </div>
        <div class="panel glass">
            <div class="list">
                <?php foreach ($rows as $b): ?>
                    <a class="item" href="passenger_trip.php?id=<?= (int)$b['id'] ?>">
                        <div class="head"><span class="mono"><?= e($b['booking_code']) ?></span><span class="pill <?= e($b['status']) ?>"><?= e(str_replace('_', ' ', $b['status'])) ?></span></div>
                        <div class="route"><?= routeStops((string)$b['pickup_label'], (string)$b['dest_label']) ?></div>
                        <div class="meta"><span><?= timeTag($b['requested_at']) ?> &middot; <?= e($b['distance_km']) ?> km</span><strong>&#8369;<?= e($b['final_fare'] ?? $b['estimated_fare']) ?></strong></div>
                    </a>
                <?php endforeach; ?>
                <?php if (!$rows): ?><div class="empty">No trips yet. <a href="passenger_book.php">Book your first ride</a>.</div><?php endif; ?>
            </div>
        </div>
<?php dashFooter();
