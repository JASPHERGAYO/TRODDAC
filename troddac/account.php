<?php
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/layout.php';
$user = requireLogin();

if (isPost()) {
    if (!verifyCsrf()) {
        flash('error', 'Your session expired. Please try again.');
        redirect('account.php');
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'profile') {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $phone    = trim((string)($_POST['phone'] ?? ''));
        $msg = validateNewAccount($fullName, $user['username'], $user['email'], $phone);
        if ($msg !== null) {
            flash('error', $msg);
        } else {
            db()->prepare('UPDATE users SET full_name = ?, phone = ? WHERE id = ?')
                ->execute([$fullName, $phone !== '' ? $phone : null, $user['id']]);
            flash('success', 'Profile updated.');
        }
    } elseif ($action === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $hash = (string)db()->query('SELECT password FROM users WHERE id = ' . (int)$user['id'])->fetchColumn();

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

dashHeader('My account', 'account.php');
?>
        <div class="greet"><h2>My account</h2><p><?= e(ROLE_LABEL[$user['role']]) ?> &middot; <?= e($user['username']) ?> &middot; <?= e($user['email']) ?></p></div>

        <div class="row two">
            <div class="panel glass">
                <h3>Profile</h3>
                <form method="post" action="account.php">
                    <?= csrfField() ?><input type="hidden" name="action" value="profile">
                    <label for="full_name">Full name</label>
                    <input type="text" id="full_name" name="full_name" value="<?= e($user['full_name']) ?>" required>
                    <label for="phone">Phone</label>
                    <input type="text" id="phone" name="phone" value="<?= e($user['phone']) ?>">
                    <button class="btn" type="submit">Save profile</button>
                </form>
            </div>
            <div class="panel glass">
                <h3>Change password</h3>
                <form method="post" action="account.php">
                    <?= csrfField() ?><input type="hidden" name="action" value="password">
                    <label for="current_password">Current password</label>
                    <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                    <label for="new_password">New password</label>
                    <input type="password" id="new_password" name="new_password" autocomplete="new-password" required>
                    <label for="confirm_password">Confirm new password</label>
                    <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                    <button class="btn" type="submit">Change password</button>
                </form>
            </div>
        </div>
<?php dashFooter();
