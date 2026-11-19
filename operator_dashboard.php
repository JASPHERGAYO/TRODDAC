<?php
require 'config/db.php';
require 'config/auth.php';
require 'config/layout.php';
requireRole(['operator', 'admin']);

$drivers    = $pdo->query("SELECT username, email, created_at FROM users WHERE role = 'driver' ORDER BY username")->fetchAll();
$passengers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'passenger'")->fetchColumn();

dashHeader('Operator Dashboard');
?>
        <div class="greet"><h2>Hello, <?= htmlspecialchars($_SESSION['username']) ?></h2><p>Your drivers and passengers at a glance.</p></div>

        <div class="row stats">
            <div class="stat-card glass">
                <div class="stat-top"><span class="chip"><?= icon('car') ?></span>Drivers</div>
                <div class="stat-body"><b><?= count($drivers) ?></b></div>
                <span class="trend">Registered</span>
            </div>
            <div class="stat-card glass">
                <div class="stat-top"><span class="chip"><?= icon('ticket') ?></span>Passengers</div>
                <div class="stat-body"><b><?= $passengers ?></b></div>
                <span class="trend">Registered</span>
            </div>
        </div>

        <div class="panel glass" id="drivers">
            <h3>Drivers</h3>
            <table data-filter>
                <thead><tr><th>Username</th><th>Email</th><th>Role</th><th>Joined</th></tr></thead>
                <tbody>
                <?php foreach ($drivers as $d): ?>
                    <tr><td><?= htmlspecialchars($d['username']) ?></td><td><?= htmlspecialchars($d['email']) ?></td><td><span class="pill driver">Driver</span></td><td><?= $d['created_at'] ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$drivers): ?><tr><td colspan="4" class="note">No drivers yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="panel glass" id="dispatch">
            <h3>Dispatch</h3>
            <p class="note">Trips, vehicles and routes will appear here once you add those tables.</p>
        </div>
<?php dashFooter();
