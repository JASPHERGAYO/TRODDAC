<?php
require __DIR__ . '/config/auth.php';
startSecureSession();

if (currentUser()) {
    redirect(roleHome($_SESSION['role']));
}

$error = '';
$identifier = '';

if (isPost()) {
    $identifier = trim((string)($_POST['identifier'] ?? ''));
    $password   = (string)($_POST['password'] ?? '');
    $ip         = clientIp();

    if (!verifyCsrf()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($identifier === '' || $password === '') {
        $error = 'Enter your username or email and your password.';
    } elseif (isLockedOut($identifier, $ip)) {
        $error = 'Too many failed attempts. Try again in ' . LOCKOUT_MINUTES . ' minutes.';
    } else {
        $st = db()->prepare('SELECT id, username, password, role, is_active FROM users WHERE username = ? OR email = ? LIMIT 1');
        $st->execute([$identifier, $identifier]);
        $user = $st->fetch();

        $valid = password_verify($password, $user['password'] ?? DUMMY_HASH) && $user;
        if (!$valid) {
            recordLoginAttempt($identifier, $ip, false);
            $error = 'Wrong username or password.';
        } elseif ((int)$user['is_active'] !== 1) {
            $error = 'This account is disabled. Contact the Association Officer.';
        } else {
            recordLoginAttempt($identifier, $ip, true);
            if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET password = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            loginUser($user);
            redirect(roleHome($user['role']));
        }
    }
}
$notices = takeFlashes();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in - TRODDAC</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <main class="card">
        <h1>TRODDAC</h1>
        <p class="sub">Log in to continue.</p>

        <?php foreach ($notices as $n): ?>
            <div class="alert <?= $n['type'] === 'success' ? 'ok' : '' ?>" role="status"><?= e($n['message']) ?></div>
        <?php endforeach; ?>
        <?php if ($error): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif; ?>

        <form id="loginForm" method="POST" action="login.php" novalidate>
            <?= csrfField() ?>

            <label for="identifier">Username or email</label>
            <input type="text" id="identifier" name="identifier" value="<?= e($identifier) ?>" autocomplete="username" required>
            <small class="field-error" id="identifierError"></small>

            <label for="password">Password</label>
            <div class="pw-wrap">
                <input type="password" id="password" name="password" autocomplete="current-password" required>
                <button type="button" id="togglePw" aria-label="Show password">Show</button>
            </div>
            <small class="field-error" id="passwordError"></small>

            <button type="submit" class="btn">Log in</button>
        </form>

        <p class="foot">New passenger? <a href="register.php">Create an account</a></p>
    </main>
    <script src="assets/js/script.js"></script>
</body>
</html>
