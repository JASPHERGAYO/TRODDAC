<?php
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/layout.php';
$user = requireRole(['driver']);

$st = db()->prepare('SELECT d.id, d.license_number, d.license_expiry, d.status, d.is_active, tu.plate_number, tu.body_number
                       FROM drivers d LEFT JOIN tricycle_units tu ON tu.id = d.default_unit_id WHERE d.user_id = ?');
$st->execute([$user['id']]);
$driver = $st->fetch();

$upcoming = [];
$trip = null;
if ($driver) {
    $q = db()->prepare("SELECT r.code, r.name, ra.start_datetime, ra.end_datetime, ra.status
                          FROM route_assignments ra JOIN routes r ON r.id = ra.route_id
                         WHERE ra.driver_id = ? AND ra.status IN ('scheduled','active') AND ra.end_datetime > NOW()
                         ORDER BY ra.start_datetime LIMIT 5");
    $q->execute([$driver['id']]);
    $upcoming = $q->fetchAll();

    $q = db()->prepare("SELECT booking_code, pickup_label, dest_label, status FROM bookings
                         WHERE driver_id = ? AND status IN ('assigned','en_route','in_progress') LIMIT 1");
    $q->execute([$driver['id']]);
    $trip = $q->fetch() ?: null;
}

dashHeader('Driver Dashboard', 'driver_dashboard.php');
?>
        <div class="greet"><h2>Hello, <?= e($user['full_name']) ?></h2><p>Your unit, availability and assignments.</p></div>
        <?php if (!$driver): ?>
            <div class="panel glass"><p class="note">Your driver profile has not been set up yet. Ask the Association Officer.</p></div>
        <?php else: ?>
        <div class="row stats">
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('clock') ?></span>Availability</div><div class="stat-body"><b><?= e(ucfirst(str_replace('_', '-', $driver['status']))) ?></b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('car') ?></span>My unit</div><div class="stat-body"><b><?= e($driver['plate_number'] ?? 'None') ?></b></div><span class="trend"><?= e($driver['body_number'] ? 'Body ' . $driver['body_number'] : 'No unit assigned') ?></span></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('user') ?></span>License</div><div class="stat-body"><b class="mono" style="font-size:1.2rem"><?= e($driver['license_number']) ?></b></div><span class="trend"><?= e($driver['license_expiry'] ? 'Expires ' . $driver['license_expiry'] : '') ?></span></div>
        </div>
        <?php if ($trip): ?>
            <div class="panel glass"><h3>Current passenger trip</h3><p class="note"><?= e($trip['booking_code']) ?>: <?= e($trip['pickup_label']) ?> to <?= e($trip['dest_label']) ?> (<?= e($trip['status']) ?>)</p></div>
        <?php endif; ?>
        <div class="panel glass">
            <h3>Upcoming official routes</h3>
            <table>
                <thead><tr><th>Route</th><th>Start</th><th>End</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($upcoming as $a): ?>
                    <tr><td><?= e($a['code'] . ' - ' . $a['name']) ?></td><td><?= e($a['start_datetime']) ?></td><td><?= e($a['end_datetime']) ?></td><td><span class="pill driver"><?= e($a['status']) ?></span></td></tr>
                <?php endforeach; ?>
                <?php if (!$upcoming): ?><tr><td colspan="4" class="hint">No official route assigned yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
<?php dashFooter();
