<?php
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/layout.php';
$user = requireRole(['operator', 'officer']);

dashHeader('Official routes', 'operator_assignments.php');
?>
        <div class="greet"><h2>Official route assignments</h2><p>Drivers are assigned to routes automatically in a fair rotation. This view is read-only.</p></div>

        <div class="row stats">
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('map') ?></span>On route now</div><div class="stat-body"><b id="s-active">0</b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('clock') ?></span>Upcoming</div><div class="stat-body"><b id="s-upcoming">0</b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('car') ?></span>Completed today</div><div class="stat-body"><b id="s-done">0</b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('users') ?></span>Missed today</div><div class="stat-body"><b id="s-missed">0</b></div></div>
        </div>

        <div class="split">
            <div class="panel glass">
                <h3>Assignments</h3>
                <div class="list" id="assignments"></div>
                <div class="empty" id="assignments-empty" hidden>No assignments yet.</div>
            </div>
            <div class="col-main" style="display:flex;flex-direction:column;gap:12px">
                <div class="panel glass">
                    <h3>Assignment engine</h3>
                    <p class="hint" id="last-run" style="margin:0 0 8px">Loading...</p>
                    <?php if ($user['role'] === 'officer'): ?>
                        <button type="button" class="btn" id="run-now">Run assignment now</button>
                    <?php endif; ?>
                </div>
                <div class="panel glass">
                    <h3>Why the engine did what it did</h3>
                    <div class="list" id="decisions"></div>
                    <div class="empty" id="decisions-empty" hidden>No decisions recorded yet.</div>
                </div>
            </div>
        </div>
<?php dashFooter(['operator_assignments.js']);
