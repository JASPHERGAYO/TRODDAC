<?php
require 'config/db.php';
require 'config/auth.php';
require 'config/layout.php';
requireRole(['admin']);

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id   = (int)($_POST['user_id'] ?? 0);
    $role = $_POST['role'] ?? '';
    if (in_array($role, ['admin','operator','driver','passenger'], true) && $id !== (int)$_SESSION['user_id']) {
        $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
        $msg = 'Role updated.';
    } else {
        $msg = 'You cannot change your own role.';
    }
}

$counts = ['admin'=>0,'operator'=>0,'driver'=>0,'passenger'=>0];
foreach ($pdo->query('SELECT role, COUNT(*) c FROM users GROUP BY role') as $r) $counts[$r['role']] = (int)$r['c'];
$total = array_sum($counts);
$users = $pdo->query('SELECT id, username, email, role, created_at FROM users ORDER BY id')->fetchAll();

// Sign-ups for the last 7 days (for the sparkline)
$daily = array_fill(0, 7, 0);
$rows = $pdo->query('SELECT DATEDIFF(CURDATE(), DATE(created_at)) d, COUNT(*) c FROM users WHERE created_at >= CURDATE() - INTERVAL 6 DAY GROUP BY d');
foreach ($rows as $r) $daily[6 - (int)$r['d']] = (int)$r['c'];
$thisWeek = array_sum($daily);

dashHeader('Admin Dashboard');
?>
        <div class="greet"><h2>Hello, <?= htmlspecialchars($_SESSION['username']) ?></h2><p>Here is how your users are doing.</p></div>

        <div class="row stats">
            <div class="stat-card glass">
                <div class="stat-top"><span class="chip"><?= icon('users') ?></span>Total users</div>
                <div class="stat-body"><b><?= $total ?></b><?= sparkline($daily) ?></div>
                <span class="trend up">+<?= $thisWeek ?> this week</span>
            </div>
            <?php foreach (['operator'=>'car','driver'=>'map','passenger'=>'ticket'] as $role => $ico): ?>
            <div class="stat-card glass">
                <div class="stat-top"><span class="chip"><?= icon($ico) ?></span><?= ucfirst($role) ?>s</div>
                <div class="stat-body"><b><?= $counts[$role] ?></b></div>
                <span class="trend"><?= $total ? round($counts[$role] / $total * 100) : 0 ?>% of all users</span>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="panel glass" id="users">
            <h3>Manage users</h3>
            <?php if ($msg): ?><p class="note" style="margin-bottom:8px"><?= htmlspecialchars($msg) ?></p><?php endif; ?>
            <table data-filter>
                <thead><tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Joined</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= $u['id'] ?></td>
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td>
                        <select class="glass-select" name="role" form="f<?= $u['id'] ?>">
                            <?php foreach (['admin','operator','driver','passenger'] as $r): ?>
                                <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><?= $u['created_at'] ?></td>
                    <td>
                        <form method="POST" id="f<?= $u['id'] ?>"><input type="hidden" name="user_id" value="<?= $u['id'] ?>"></form>
                        <button class="small-btn" form="f<?= $u['id'] ?>">Save</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="row two">
            <div class="panel glass" id="roles">
                <h3>Role breakdown</h3>
                <div class="bars">
                    <?php foreach ($counts as $role => $n): ?>
                    <div class="bar-row"><span><?= ucfirst($role) ?></span><div class="bar"><i style="width:<?= $total ? round($n / $total * 100) : 0 ?>%"></i></div><b><?= $n ?></b></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="panel glass">
                <h3>Recent sign-ups</h3>
                <table>
                    <tbody>
                    <?php foreach (array_slice(array_reverse($users), 0, 4) as $u): ?>
                        <tr><td><?= htmlspecialchars($u['username']) ?></td><td><span class="pill <?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
<?php dashFooter();
