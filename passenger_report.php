<?php
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/layout.php';
require __DIR__ . '/lib/Accounts.php';
$user = requireRole(['passenger']);
requireSchemaOrExplain(['passenger_reports' => []], 'Report a problem', 'passenger_report.php');
$pdo = db();

const REPORT_CATEGORIES = [
    'driver_conduct' => 'Driver behaviour',
    'fare_dispute'   => 'Fare or overcharging',
    'safety'         => 'Safety concern',
    'lost_item'      => 'Lost item',
    'vehicle'        => 'Tricycle condition',
    'app_problem'    => 'Problem with the app',
    'other'          => 'Something else',
];
const REPORTS_PER_DAY = 5;

$errors = [];
$old = ['category' => 'driver_conduct', 'booking_id' => '', 'subject' => '', 'message' => ''];

if (isPost()) {
    foreach ($old as $k => $_) {
        $old[$k] = $k === 'message' ? trim((string)($_POST[$k] ?? '')) : Accounts::clean($_POST[$k] ?? '');
    }
    $subjectLen = charLength($old['subject']);
    $messageLen = charLength($old['message']);
    $bookingId = (int)$old['booking_id'];

    if (!verifyCsrf()) {
        $errors['general'] = 'Your session expired. Please try again.';
    }
    if (!isset(REPORT_CATEGORIES[$old['category']])) {
        $errors['category'] = 'Choose what your report is about.';
    }
    if ($subjectLen < 5 || $subjectLen > 120) {
        $errors['subject'] = 'Write a short title of 5 to 120 characters.';
    }
    if ($messageLen < 10 || $messageLen > 2000) {
        $errors['message'] = 'Describe what happened in 10 to 2000 characters. You wrote ' . $messageLen . '.';
    }
    if (!$errors && $bookingId > 0) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE id = ? AND passenger_id = ?');
        $st->execute([$bookingId, $user['id']]);
        if ((int)$st->fetchColumn() === 0) {
            $errors['booking_id'] = 'Choose one of your own trips.';
        }
    }
    if (!$errors) {
        $hash = md5(textLower($old['category'] . '|' . $old['subject'] . '|' . $old['message']));
        $st = $pdo->prepare('SELECT COUNT(*) FROM passenger_reports WHERE passenger_id = ? AND content_hash = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)');
        $st->execute([$user['id'], $hash]);
        $recent = $pdo->prepare('SELECT COUNT(*) FROM passenger_reports WHERE passenger_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)');
        $recent->execute([$user['id']]);
        if ((int)$st->fetchColumn() > 0) {
            $errors['general'] = 'You already sent this exact report. The Association Officer will review it soon.';
        } elseif ((int)$recent->fetchColumn() >= REPORTS_PER_DAY) {
            $errors['general'] = 'You can send up to ' . REPORTS_PER_DAY . ' reports a day. Please try again tomorrow.';
        } else {
            $saved = false;
            for ($try = 0; $try < 3 && !$saved; $try++) {
                try {
                    $pdo->prepare('INSERT INTO passenger_reports (report_code, passenger_id, booking_id, category, subject, message, content_hash) VALUES (?,?,?,?,?,?,?)')
                        ->execute(['RPT-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3))), $user['id'], $bookingId > 0 ? $bookingId : null,
                                   $old['category'], $old['subject'], $old['message'], $hash]);
                    $saved = true;
                } catch (PDOException $e) {
                    if (($e->errorInfo[1] ?? 0) !== 1062) {
                        error_log('TRODDAC report: ' . $e->getMessage());
                        $errors['general'] = isSchemaError($e) ? SCHEMA_HELP : 'Your report could not be saved. Please try again.';
                        break;
                    }
                }
            }
            if ($saved) {
                flash('success', 'Thank you. Your report was sent to the Association Officer.');
                redirect('passenger_report.php');
            }
        }
    }
}

$trips = $pdo->prepare('SELECT id, booking_code, pickup_label, dest_label, requested_at FROM bookings WHERE passenger_id = ? ORDER BY id DESC LIMIT 20');
$trips->execute([$user['id']]);
$trips = $trips->fetchAll();

$mine = $pdo->prepare('SELECT report_code, category, subject, message, status, officer_note, created_at, resolved_at FROM passenger_reports WHERE passenger_id = ? ORDER BY id DESC LIMIT 30');
$mine->execute([$user['id']]);
$mine = $mine->fetchAll();

$fe = static fn(string $k) => isset($errors[$k]) ? '<small class="field-error" role="alert">' . e($errors[$k]) . '</small>' : '';
$statusLabel = ['new' => 'Sent', 'in_review' => 'Being reviewed', 'resolved' => 'Resolved', 'dismissed' => 'Closed'];

dashHeader('Report a problem', 'passenger_report.php');
?>
        <div class="greet"><h2>Report a problem</h2><p>Tell the Association Officer about a trip, a driver or the app.</p></div>

        <div class="split">
            <div class="panel glass">
                <h3>New report</h3>
                <?php if (!empty($errors['general'])): ?><div class="flash error glass" role="alert"><?= e($errors['general']) ?></div><?php endif; ?>
                <form method="post" action="passenger_report.php" novalidate>
                    <?= csrfField() ?>
                    <label for="category">What is it about?</label>
                    <select id="category" name="category">
                        <?php foreach (REPORT_CATEGORIES as $k => $label): ?><option value="<?= e($k) ?>" <?= $old['category'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                    <?= $fe('category') ?>

                    <label for="booking_id">Which trip? (optional)</label>
                    <select id="booking_id" name="booking_id">
                        <option value="">Not about a specific trip</option>
                        <?php foreach ($trips as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= (string)$old['booking_id'] === (string)$t['id'] ? 'selected' : '' ?>><?= e($t['booking_code'] . ' - ' . substr($t['requested_at'], 0, 10) . ' - ' . textCut($t['dest_label'], 30)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $fe('booking_id') ?>

                    <label for="subject">Title</label>
                    <input type="text" id="subject" name="subject" value="<?= e($old['subject']) ?>" placeholder="e.g. Driver asked for more than the app fare" maxlength="120" required>
                    <?= $fe('subject') ?>

                    <label for="message">What happened?</label>
                    <textarea id="message" name="message" rows="6" maxlength="2000" placeholder="Tell us when and where it happened and who was involved. Do not include your password." required><?= e($old['message']) ?></textarea>
                    <?= $fe('message') ?>

                    <div class="actions"><button type="submit" class="btn wide">Send report</button></div>
                    <p class="hint">You can send up to <?= REPORTS_PER_DAY ?> reports a day.</p>
                </form>
            </div>

            <div class="panel glass">
                <h3>My reports</h3>
                <div class="list">
                    <?php foreach ($mine as $r): ?>
                        <div class="item">
                            <div class="head"><span class="mono"><?= e($r['report_code']) ?></span>
                                <span class="pill <?= $r['status'] === 'resolved' ? 'completed' : ($r['status'] === 'in_review' ? 'assigned' : ($r['status'] === 'dismissed' ? 'cancelled' : 'pending')) ?>"><?= e($statusLabel[$r['status']] ?? $r['status']) ?></span></div>
                            <div class="route"><b><?= e($r['subject']) ?></b><br><span class="muted"><?= e(REPORT_CATEGORIES[$r['category']] ?? $r['category']) ?> &middot; <?= e(substr($r['created_at'], 0, 16)) ?></span></div>
                            <?php if ($r['officer_note']): ?><div class="meta"><b>Reply from the officer:</b> <?= e($r['officer_note']) ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$mine): ?><div class="empty">You have not sent any reports.</div><?php endif; ?>
                </div>
            </div>
        </div>
<?php dashFooter();
