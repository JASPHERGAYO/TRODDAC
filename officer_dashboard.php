<?php
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/layout.php';
$user = requireRole(['officer']);
$pdo = db();

$counts = ['passenger' => 0, 'driver' => 0, 'operator' => 0, 'officer' => 0];
foreach ($pdo->query('SELECT role, COUNT(*) c FROM users WHERE is_active = 1 GROUP BY role') as $r) {
    $counts[$r['role']] = (int)$r['c'];
}
$routes    = (int)$pdo->query('SELECT COUNT(*) FROM routes WHERE is_active = 1')->fetchColumn();
$terminals = (int)$pdo->query('SELECT COUNT(*) FROM terminals WHERE is_active = 1')->fetchColumn();
$fare      = $pdo->query('SELECT name, base_fare, base_distance_km, per_km_rate, minimum_fare FROM fare_matrices WHERE is_active = 1')->fetch();

dashHeader('Officer Dashboard', 'officer_dashboard.php');
?>
        <div class="greet"><h2>Hello, <?= e($user['full_name']) ?></h2><p>Association overview.</p></div>
        <div class="row stats">
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('car') ?></span>Active drivers</div><div class="stat-body"><b><?= $counts['driver'] ?></b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('users') ?></span>Operators</div><div class="stat-body"><b><?= $counts['operator'] ?></b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('ticket') ?></span>Passengers</div><div class="stat-body"><b><?= $counts['passenger'] ?></b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('map') ?></span>Routes / terminals</div><div class="stat-body"><b><?= $routes ?> / <?= $terminals ?></b></div></div>
        </div>
        <div class="row two">
            <div class="panel glass">
                <h3>Active fare matrix</h3>
                <?php if ($fare): ?>
                    <p class="note"><b><?= e($fare['name']) ?></b>: &#8369;<?= e($fare['base_fare']) ?> covers the first <?= e($fare['base_distance_km']) ?> km, then &#8369;<?= e($fare['per_km_rate']) ?> per km. Minimum &#8369;<?= e($fare['minimum_fare']) ?>.</p>
                <?php else: ?>
                    <p class="note">No fare matrix is active. Passengers cannot book until one is activated.</p>
                <?php endif; ?>
            </div>
            <div class="panel glass">
                <h3>Accounts</h3>
                <p class="note">Add drivers and operators, disable access or reset passwords.</p>
                <p><a class="small-btn" style="text-decoration:none;display:inline-block;margin-top:10px" href="officer_users.php">Manage users</a></p>
            </div>
        </div>
<?php dashFooter();
