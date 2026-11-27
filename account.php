<?php
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/layout.php';
require __DIR__ . '/lib/Accounts.php';
$user = requireLogin();

if (isPost()) {
    if (!verifyCsrf()) {
        flash('error', 'Your session expired. Please try again.');
        redirect('account.php');
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'profile') {
        $result = Accounts::updateProfile(db(), (int)$user['id'], $_POST);
        if ($result['ok']) {
            flash('success', 'Profile updated.');
        } else {
            flash('error', $result['message'] ?: implode(' ', $result['errors']));
        }
    } elseif ($action === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $st = db()->prepare('SELECT password FROM users WHERE id = ?');
        $st->execute([$user['id']]);
        $hash = (string)$st->fetchColumn();

        if (!password_verify($current, $hash)) {
            flash('error', 'Your current password is wrong.');
        } elseif (($msg = validatePassword($new)) !== null) {
            flash('error', $msg);
        } elseif ($new !== $confirm) {
            flash('error', 'The new passwords do not match.');
        } else {
            db()->prepare('UPDATE users SET password = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            session_regenerate_id(true);
            flash('success', 'Password changed.');
        }
    }
    redirect('account.php');
}

$names = ['first_name' => '', 'last_name' => '', 'middle_initial' => ''];
if (schemaHas('users', 'first_name')) {
    $st = db()->prepare('SELECT first_name, last_name, middle_initial FROM users WHERE id = ?');
    $st->execute([$user['id']]);
    $names = $st->fetch() ?: $names;
}
dashHeader('My account', 'account.php');
?>
        <div class="greet"><h2>My account</h2><p><?= e(ROLE_LABEL[$user['role']]) ?> &middot; <?= e($user['username']) ?> &middot; <?= e($user['email']) ?></p></div>

        <div class="row two">
            <div class="panel glass">
                <h3>Profile</h3>
                <div class="account-avatar-row">
                    <?= userAvatarHtml($user, 'lg') ?>
                    <div class="account-avatar-meta">
                        <b><?= e($user['full_name']) ?></b>
                        <p class="hint">Shown across your dashboard.</p>
                        <button type="button" class="small-btn ghost" data-avatar-open><?= icon('camera') ?><span>Change photo</span></button>
                    </div>
                </div>
                <form method="post" action="account.php">
                    <?= csrfField() ?><input type="hidden" name="action" value="profile">
                    <div class="name-row">
                        <div class="grow"><label for="first_name">First name</label>
                            <input type="text" id="first_name" name="first_name" value="<?= e($names['first_name']) ?>" placeholder="e.g. Juan" maxlength="50" required></div>
                        <div class="mi"><label for="middle_initial">M.I.</label>
                            <input type="text" id="middle_initial" name="middle_initial" value="<?= e($names['middle_initial']) ?>" placeholder="e.g. D" maxlength="2"></div>
                    </div>
                    <label for="last_name">Last name</label>
                    <input type="text" id="last_name" name="last_name" value="<?= e($names['last_name']) ?>" placeholder="e.g. Dela Cruz" maxlength="50" required>
                    <label for="phone">Mobile number</label>
                    <input type="tel" id="phone" name="phone" value="<?= e($user['phone']) ?>" placeholder="e.g. 0917 123 4567">
                    <button class="btn" type="submit">Save profile</button>
                </form>
            </div>
            <div class="panel glass">
                <h3>Change password</h3>
                <form method="post" action="account.php">
                    <?= csrfField() ?><input type="hidden" name="action" value="password">
                    <label for="current_password">Current password</label>
                    <input type="password" id="current_password" name="current_password" placeholder="Your current password" autocomplete="current-password" required>
                    <label for="new_password">New password</label>
                    <input type="password" id="new_password" name="new_password" placeholder="At least 8 characters, with a letter and a number" autocomplete="new-password" required>
                    <label for="confirm_password">Confirm new password</label>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Type the new password again" autocomplete="new-password" required>
                    <button class="btn" type="submit">Change password</button>
                </form>
            </div>
        </div>
<?php dashFooter();
