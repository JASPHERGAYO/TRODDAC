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

// Passenger reports (the table exists only after migration 012; the dashboard still loads without it).
$hasReports = schemaHas('passenger_reports');
$reportCounts = ['new' => 0, 'in_review' => 0];
$latestReports = [];
if ($hasReports) {
    foreach ($pdo->query("SELECT status, COUNT(*) c FROM passenger_reports WHERE status IN ('new','in_review') GROUP BY status") as $r) {
        $reportCounts[$r['status']] = (int)$r['c'];
    }
    $latestReports = $pdo->query("SELECT r.report_code, r.category, r.subject, r.status, r.created_at, p.full_name
                                    FROM passenger_reports r JOIN users p ON p.id = r.passenger_id
                                   WHERE r.status IN ('new','in_review') ORDER BY r.status = 'new' DESC, r.created_at DESC LIMIT 5")->fetchAll();
}

dashHeader('Officer Dashboard', 'officer_dashboard.php');
?>
        <div class="greet"><h2>Hello, <?= e($user['full_name']) ?></h2><p>Association overview.</p></div>
        <div class="row stats">
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('car') ?></span>Active drivers</div><div class="stat-body"><b><?= $counts['driver'] ?></b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('users') ?></span>Operators</div><div class="stat-body"><b><?= $counts['operator'] ?></b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('ticket') ?></span>Passengers</div><div class="stat-body"><b><?= $counts['passenger'] ?></b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('map') ?></span>Routes / terminals</div><div class="stat-body"><b><?= $routes ?> / <?= $terminals ?></b></div></div>
            <a class="stat-card glass" href="#reports" style="text-decoration:none;color:inherit"><div class="stat-top"><span class="chip"><?= icon('flag') ?></span>Open reports</div><div class="stat-body"><b><?= $reportCounts['new'] + $reportCounts['in_review'] ?></b></div><span class="trend"><?= $reportCounts['new'] ?> new</span></a>
        </div>
        <div class="row two">
            <div class="panel glass">
                <h3>Active fare rate</h3>
                <?php if ($fare): ?>
                    <p class="note"><b><?= e($fare['name']) ?></b><br><span style="font-size:1.4rem;color:var(--accent)"><b>&#8369;<?= e($fare['per_km_rate']) ?></b></span> per km
                        <?php if ((float)$fare['base_fare'] > 0): ?><br>Starting fare &#8369;<?= e($fare['base_fare']) ?> covers the first <?= e(rtrim(rtrim((string)$fare['base_distance_km'], '0'), '.')) ?> km.<?php endif; ?>
                        <br>Minimum fare &#8369;<?= e($fare['minimum_fare']) ?>.</p>
                <?php else: ?>
                    <p class="note">No fare rate is active. Passengers cannot book until one is activated.</p>
                <?php endif; ?>
                <p style="margin:10px 0 0"><a class="small-btn" style="text-decoration:none;display:inline-flex" href="officer_fares.php">Manage fare rates</a></p>
            </div>
            <div class="panel glass">
                <h3>Accounts</h3>
                <p class="note">Add drivers and operators, disable access or reset passwords.</p>
                <p><a class="small-btn" style="text-decoration:none;display:inline-block;margin-top:10px" href="officer_users.php">Manage users</a></p>
            </div>
        </div>
        <div class="panel glass" id="reports">
            <div class="head" style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
                <h3 style="margin:0">Passenger reports</h3>
                <?php if ($hasReports): ?><span class="pill pending"><?= $reportCounts['new'] ?> new</span><?php endif; ?>
            </div>
            <?php if (!$hasReports): ?>
                <p class="note"><?= e(SCHEMA_HELP) ?></p>
            <?php else: ?>
                <div class="list" style="margin-top:10px">
                    <?php foreach ($latestReports as $r): ?>
                        <a class="item" href="officer_reports.php" style="text-decoration:none;color:inherit">
                            <div class="head"><span class="mono"><?= e($r['report_code']) ?></span><span class="pill <?= $r['status'] === 'new' ? 'pending' : 'assigned' ?>"><?= $r['status'] === 'new' ? 'New' : 'In review' ?></span></div>
                            <div class="route"><b><?= e($r['subject']) ?></b></div>
                            <div class="meta"><?= e($r['full_name']) ?> &middot; <?= e(substr($r['created_at'], 0, 16)) ?></div>
                        </a>
                    <?php endforeach; ?>
                    <?php if (!$latestReports): ?><div class="empty">No open reports. Nice and quiet.</div><?php endif; ?>
                </div>
                <p style="margin:12px 0 0"><a href="officer_reports.php">Open all reports (<?= $reportCounts['new'] + $reportCounts['in_review'] ?> open)</a></p>
            <?php endif; ?>
        </div>
<?php dashFooter();
