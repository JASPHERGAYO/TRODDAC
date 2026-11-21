<?php
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/layout.php';
$user = requireRole(['operator']);
$pdo = db();

$pending = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'pending'")->fetchColumn();
$live    = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status IN ('assigned','en_route','in_progress')")->fetchColumn();
$online  = (int)$pdo->query("SELECT COUNT(*) FROM drivers WHERE status = 'online' AND is_active = 1")->fetchColumn();
$board   = $pdo->query('SELECT driver_name, availability, default_unit_plate, active_booking_code, next_route_code, next_route_start FROM v_driver_board ORDER BY driver_name')->fetchAll();

dashHeader('Operator Dashboard', 'operator_dashboard.php');
?>
        <div class="greet"><h2>Hello, <?= e($user['full_name']) ?></h2><p>Dispatch overview.</p></div>
        <div class="panel glass" style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap">
            <div><b><?= $pending ?> request(s) waiting</b><div class="hint" style="margin:0">Open the dispatch screen to assign drivers.</div></div>
            <a class="btn" href="operator_dispatch.php">Open dispatch</a>
        </div>
        <div class="row stats">
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('ticket') ?></span>Pending requests</div><div class="stat-body"><b><?= $pending ?></b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('map') ?></span>Trips in progress</div><div class="stat-body"><b><?= $live ?></b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('car') ?></span>Drivers online</div><div class="stat-body"><b><?= $online ?></b></div></div>
        </div>
        <div class="panel glass scroll">
            <h3>Driver availability board</h3>
            <table>
                <thead><tr><th>Driver</th><th>Availability</th><th>Unit</th><th>Passenger trip</th><th>Next official route</th></tr></thead>
                <tbody>
                <?php foreach ($board as $d): ?>
                    <tr><td><?= e($d['driver_name']) ?></td><td><span class="pill <?= $d['availability'] === 'offline' ? 'off' : 'operator' ?>"><?= e(str_replace('_', '-', $d['availability'])) ?></span></td>
                        <td><?= e($d['default_unit_plate']) ?></td><td class="mono"><?= e($d['active_booking_code']) ?></td>
                        <td><?= e($d['next_route_code'] ? $d['next_route_code'] . ' at ' . $d['next_route_start'] : '') ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$board): ?><tr><td colspan="5" class="hint">No drivers registered.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
<?php dashFooter();
