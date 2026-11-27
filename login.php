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
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0b6b23">
    <title>Log in - TRODDAC</title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link rel="preload" href="assets/fonts/public-sans-latin-wght.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="auth-shell">
    <aside class="auth-aside">
        <div class="aside-brand">
            <img src="logo/logo.png" alt="TRODDAC Seal">
            <div><strong>TRODDAC</strong><span>Dasmari&ntilde;as City</span></div>
        </div>
        <div class="aside-copy">
            <h2>One portal for every tricycle ride in Dasmari&ntilde;as.</h2>
            <p>Tricycle Operators and Drivers Dasmari&ntilde;as Association: booking, live trips and fair route rotation in one place.</p>
        </div>
        <ul class="aside-list">
            <li>See the fare before you book</li>
            <li>Drivers accept bookings live</li>
            <li>Automatic, fair route rotation</li>
        </ul>
    </aside>

    <main class="card">
        <div class="letterhead">
            <img src="logo/logo.png" alt="TRODDAC Seal" class="auth-seal">
            <div class="lh-text">
                <div class="lh-title">TRODDAC</div>
                <p class="lh-sub">Tricycle Operators and Drivers Dasmari&ntilde;as Association</p>
            </div>
        </div>

        <header class="auth-head">
            <h1>Welcome back</h1>
            <p class="sub">Sign in with your account to continue.</p>
        </header>

        <?php foreach ($notices as $n): ?>
            <div class="alert <?= $n['type'] === 'success' ? 'ok' : '' ?>" role="status"><?= e($n['message']) ?></div>
        <?php endforeach; ?>
        <?php if ($error): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif; ?>

        <form id="loginForm" method="POST" action="login.php" novalidate>
            <?= csrfField() ?>

            <div class="field">
                <label for="identifier">Username or email</label>
                <div class="in-group">
                    <span class="in-ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </span>
                    <input type="text" id="identifier" name="identifier" value="<?= e($identifier) ?>" placeholder="Enter your username or email" autocomplete="username" required>
                </div>
                <small class="field-error" id="identifierError"></small>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="in-group">
                    <span class="in-ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </span>
                    <div class="pw-wrap">
                        <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                        <button type="button" id="togglePw" aria-label="Show password">Show</button>
                    </div>
                </div>
                <small class="field-error" id="passwordError"></small>
            </div>

            <button type="submit" class="btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                Log in
            </button>
        </form>

        <p class="foot">New passenger? <a href="register.php">Create an account</a></p>
    </main>
</div>
    <script src="assets/js/script.js"></script>
</body>
</html>
