<?php
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/layout.php';
require __DIR__ . '/lib/Accounts.php';
$user = requireRole(['officer']);
requireSchemaOrExplain(['passenger_reports' => []], 'Passenger reports', 'officer_reports.php');
$pdo = db();

const R_CATEGORIES = [
    'driver_conduct' => 'Driver behaviour', 'fare_dispute' => 'Fare or overcharging', 'safety' => 'Safety concern',
    'lost_item' => 'Lost item', 'vehicle' => 'Tricycle condition', 'app_problem' => 'Problem with the app', 'other' => 'Something else',
];
const R_STATUS = ['new' => 'New', 'in_review' => 'In review', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed'];

if (isPost()) {
    if (!verifyCsrf()) {
        flash('error', 'Your session expired. Please try again.');
        redirect('officer_reports.php');
    }
    $id     = (int)($_POST['report_id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    $note   = trim((string)($_POST['officer_note'] ?? ''));
    if (!isset(R_STATUS[$status])) {
        flash('error', 'Choose a status.');
    } elseif (charLength($note) > 1000) {
        flash('error', 'The note can be up to 1000 characters.');
    } else {
        try {
            $st = $pdo->prepare('UPDATE passenger_reports SET status = ?, officer_note = ?, handled_by = ?, resolved_at = IF(? IN (\'resolved\',\'dismissed\'), NOW(), NULL) WHERE id = ?');
            $st->execute([$status, $note !== '' ? $note : null, $user['id'], $status, $id]);
            $exists = $pdo->prepare('SELECT COUNT(*) FROM passenger_reports WHERE id = ?');
            $exists->execute([$id]);
            $found = (int)$exists->fetchColumn() > 0;
            flash($found ? 'success' : 'error', $found ? 'Report updated.' : 'Report not found.');
        } catch (PDOException $e) {
            error_log('TRODDAC reports: ' . $e->getMessage());
            flash('error', isSchemaError($e) ? SCHEMA_HELP : 'The report could not be updated. Please try again.');
        }
    }
    redirect('officer_reports.php' . (!empty($_POST['back']) ? '?' . http_build_query(['status' => (string)$_POST['back']]) : ''));
}

$filter = (string)($_GET['status'] ?? 'open');
$cat    = (string)($_GET['category'] ?? '');
$where = []; $params = [];
if ($filter === 'open') { $where[] = "r.status IN ('new','in_review')"; }
elseif (isset(R_STATUS[$filter])) { $where[] = 'r.status = ?'; $params[] = $filter; }
if (isset(R_CATEGORIES[$cat])) { $where[] = 'r.category = ?'; $params[] = $cat; }

$st = $pdo->prepare('SELECT r.id, r.report_code, r.category, r.subject, r.message, r.status, r.officer_note, r.created_at, r.resolved_at,
                            p.full_name AS passenger_name, p.phone AS passenger_phone, p.email AS passenger_email,
                            b.booking_code, b.pickup_label, b.dest_label, du.full_name AS driver_name
                       FROM passenger_reports r
                       JOIN users p ON p.id = r.passenger_id
                       LEFT JOIN bookings b ON b.id = r.booking_id
                       LEFT JOIN drivers d ON d.id = b.driver_id
                       LEFT JOIN users du ON du.id = d.user_id'
                    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                    . ' ORDER BY FIELD(r.status, "new","in_review","resolved","dismissed"), r.created_at DESC LIMIT 200');
$st->execute($params);
$reports = $st->fetchAll();

$counts = array_fill_keys(array_keys(R_STATUS), 0);
foreach ($pdo->query('SELECT status, COUNT(*) c FROM passenger_reports GROUP BY status') as $c) { $counts[$c['status']] = (int)$c['c']; }

dashHeader('Passenger reports', 'officer_reports.php');
?>
        <div class="greet"><h2>Passenger reports</h2><p>Complaints and concerns sent by passengers.</p></div>

        <div class="row stats">
            <?php foreach (R_STATUS as $k => $label): ?>
                <a class="stat-card glass" style="text-decoration:none;color:inherit" href="officer_reports.php?status=<?= e($k) ?>">
                    <div class="stat-top"><span class="chip"><?= icon('flag') ?></span><?= e($label) ?></div>
                    <div class="stat-body"><b><?= $counts[$k] ?></b></div>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="panel glass">
            <form method="get" action="officer_reports.php" class="filter-bar">
                <select name="status" aria-label="Status">
                    <option value="open" <?= $filter === 'open' ? 'selected' : '' ?>>Open (new and in review)</option>
                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All reports</option>
                    <?php foreach (R_STATUS as $k => $label): ?><option value="<?= e($k) ?>" <?= $filter === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                </select>
                <select name="category" aria-label="Category">
                    <option value="">All categories</option>
                    <?php foreach (R_CATEGORIES as $k => $label): ?><option value="<?= e($k) ?>" <?= $cat === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                </select>
                <button class="btn" type="submit">Filter</button>
            </form>
        </div>

        <div class="user-grid">
            <?php foreach ($reports as $r): ?>
                <article class="item user-card">
                    <div class="head"><span class="mono"><?= e($r['report_code']) ?></span>
                        <span class="pill <?= $r['status'] === 'resolved' ? 'completed' : ($r['status'] === 'in_review' ? 'assigned' : ($r['status'] === 'dismissed' ? 'cancelled' : 'pending')) ?>"><?= e(R_STATUS[$r['status']]) ?></span></div>
                    <div class="route"><b><?= e($r['subject']) ?></b><br><span class="muted"><?= e(R_CATEGORIES[$r['category']] ?? $r['category']) ?> &middot; <?= e(substr($r['created_at'], 0, 16)) ?></span></div>
                    <p class="report-text"><?= nl2br(e($r['message'])) ?></p>
                    <dl class="kv compact">
                        <dt>From</dt><dd><?= e($r['passenger_name']) ?><?= $r['passenger_phone'] ? ' &middot; <a href="tel:' . e($r['passenger_phone']) . '">' . e($r['passenger_phone']) . '</a>' : '' ?></dd>
                        <?php if ($r['booking_code']): ?><dt>Trip</dt><dd><span class="mono"><?= e($r['booking_code']) ?></span> &middot; <?= e($r['pickup_label']) ?> &rarr; <?= e($r['dest_label']) ?></dd><?php endif; ?>
                        <?php if ($r['driver_name']): ?><dt>Driver</dt><dd><?= e($r['driver_name']) ?></dd><?php endif; ?>
                        <?php if ($r['officer_note']): ?><dt>Note</dt><dd><?= e($r['officer_note']) ?></dd><?php endif; ?>
                    </dl>
                    <div class="actions">
                        <button type="button" class="small-btn" data-report="<?= (int)$r['id'] ?>" data-status="<?= e($r['status']) ?>"
                                data-note="<?= e((string)$r['officer_note']) ?>" data-title="<?= e($r['report_code']) ?>">Update status</button>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if (!$reports): ?><div class="panel glass empty">No reports match this filter.</div><?php endif; ?>

        <dialog class="modal small" id="m-report" aria-labelledby="m-report-title">
            <form method="post" action="officer_reports.php" class="modal-body">
                <div class="modal-head"><h3 id="m-report-title">Update report</h3><button type="button" class="x" data-close aria-label="Close">&times;</button></div>
                <?= csrfField() ?><input type="hidden" name="report_id" id="rp-id" value=""><input type="hidden" name="back" value="<?= e($filter) ?>">
                <label for="rp-status">Status</label>
                <select id="rp-status" name="status">
                    <?php foreach (R_STATUS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
                </select>
                <label for="rp-note">Reply to the passenger (optional)</label>
                <textarea id="rp-note" name="officer_note" rows="4" maxlength="1000" placeholder="e.g. We spoke with the driver and reminded him of the fare matrix."></textarea>
                <div class="modal-foot">
                    <button type="button" class="btn ghost" data-close>Cancel</button>
                    <button type="submit" class="btn">Save</button>
                </div>
            </form>
        </dialog>
        <script>
            document.addEventListener('click', function (e) {
                var b = e.target.closest('[data-report]');
                if (!b) return;
                document.getElementById('rp-id').value = b.dataset.report;
                document.getElementById('rp-status').value = b.dataset.status;
                document.getElementById('rp-note').value = b.dataset.note || '';
                document.getElementById('m-report-title').textContent = 'Update ' + (b.dataset.title || 'report');
                window.TRD.modal.open(document.getElementById('m-report'));
            });
        </script>
<?php dashFooter(['modals.js']);
