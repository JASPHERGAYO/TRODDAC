<?php
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/layout.php';
$user = requireRole(['passenger']);

$st = db()->prepare("SELECT COUNT(*) total,
                            SUM(status IN ('pending','assigned','en_route','in_progress')) open_trips,
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
        <div class="greet"><h2>Hello, <?= e($user['full_name']) ?></h2><p>Your bookings at a glance.</p></div>
        <div class="row stats">
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('ticket') ?></span>All bookings</div><div class="stat-body"><b><?= (int)$s['total'] ?></b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('clock') ?></span>Open trips</div><div class="stat-body"><b><?= (int)$s['open_trips'] ?></b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('map') ?></span>Completed</div><div class="stat-body"><b><?= (int)$s['completed'] ?></b></div></div>
        </div>
        <div class="panel glass">
            <h3>Recent bookings</h3>
            <table>
                <thead><tr><th>Code</th><th>From</th><th>To</th><th>km</th><th>Fare</th><th>Status</th><th>Requested</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $b): ?>
                    <tr><td class="mono"><?= e($b['booking_code']) ?></td><td><?= e($b['pickup_label']) ?></td><td><?= e($b['dest_label']) ?></td>
                        <td><?= e($b['distance_km']) ?></td><td>&#8369;<?= e($b['estimated_fare']) ?></td><td><span class="pill passenger"><?= e($b['status']) ?></span></td><td><?= e($b['requested_at']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$recent): ?><tr><td colspan="7" class="hint">You have no bookings yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
<?php dashFooter();
