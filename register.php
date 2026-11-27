<?php
require __DIR__ . '/config/auth.php';
require __DIR__ . '/lib/Accounts.php';
startSecureSession();

if (currentUser()) {
    redirect(roleHome($_SESSION['role']));
}

$old = ['first_name' => '', 'last_name' => '', 'middle_initial' => '', 'username' => '', 'email' => '', 'phone' => ''];
$errors = [];
$general = '';

if (isPost()) {
    foreach ($old as $key => $_) {
        $old[$key] = Accounts::clean($_POST[$key] ?? '');
    }
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm'] ?? '');

    if (!verifyCsrf()) {
        $general = 'Your session expired. Please try again.';
    } elseif ($password !== $confirm) {
        $errors['confirm'] = 'The two passwords do not match.';
    } else {
        // Public sign-up always creates a PASSENGER; the role is never read from the form.
        $result = Accounts::create(db(), $old, 'passenger', $password, false);
        if ($result['ok']) {
            flash('success', 'Account created. You can log in now.');
            redirect('login.php');
        }
        $errors  = $result['errors'];
        $general = (string)$result['message'];
    }
}

function fieldError(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<small class="field-error" role="alert">' . e($errors[$key]) . '</small>' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0b6b23">
    <title>Create passenger account - TRODDAC</title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link rel="preload" href="assets/fonts/public-sans-latin-wght.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="auth-shell wide">
    <aside class="auth-aside">
        <div class="aside-brand">
            <img src="logo/logo.png" alt="TRODDAC Seal">
            <div><strong>TRODDAC</strong><span>Dasmari&ntilde;as City</span></div>
        </div>
        <div class="aside-copy">
            <h2>Ride with a registered tricycle.</h2>
            <p>Create a passenger account to book with your GPS location, see the fare first and follow your tricycle on the map.</p>
        </div>
        <ul class="aside-list">
            <li>Free passenger sign-up</li>
            <li>Drivers and operators are added by the Association Officer</li>
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
            <h1>Create your account</h1>
            <p class="sub">Passenger sign-up. Drivers and operators are added by the Association Officer.</p>
        </header>

        <?php if ($general): ?><div class="alert" role="alert"><?= e($general) ?></div><?php endif; ?>

        <form method="POST" action="register.php" novalidate>
            <?= csrfField() ?>

            <h2 class="sec-head">Personal information</h2>

            <div class="grid-name">
                <div class="field">
                    <label for="first_name">First name</label>
                    <input type="text" id="first_name" name="first_name" value="<?= e($old['first_name']) ?>" placeholder="e.g. Juan" autocomplete="given-name" maxlength="50" required>
                    <?= fieldError($errors, 'first_name') ?>
                </div>
                <div class="field">
                    <label for="middle_initial">M.I.</label>
                    <input type="text" id="middle_initial" name="middle_initial" value="<?= e($old['middle_initial']) ?>" placeholder="e.g. D" maxlength="2" autocomplete="additional-name">
                    <?= fieldError($errors, 'middle_initial') ?>
                </div>
                <div class="field">
                    <label for="last_name">Last name</label>
                    <input type="text" id="last_name" name="last_name" value="<?= e($old['last_name']) ?>" placeholder="e.g. Dela Cruz" autocomplete="family-name" maxlength="50" required>
                    <?= fieldError($errors, 'last_name') ?>
                </div>
            </div>

            <div class="grid-two">
                <div class="field">
                    <label for="email">Email</label>
                    <div class="in-group">
                        <span class="in-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        </span>
                        <input type="email" id="email" name="email" value="<?= e($old['email']) ?>" placeholder="e.g. juan@example.com" autocomplete="email" required>
                    </div>
                    <?= fieldError($errors, 'email') ?>
                </div>

                <div class="field">
                    <label for="phone">Mobile number (optional)</label>
                    <div class="in-group">
                        <span class="in-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        </span>
                        <input type="tel" id="phone" name="phone" value="<?= e($old['phone']) ?>" placeholder="e.g. 0917 123 4567" autocomplete="tel">
                    </div>
                    <?= fieldError($errors, 'phone') ?>
                </div>
            </div>

            <h2 class="sec-head">Account details</h2>

            <div class="field">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="<?= e($old['username']) ?>" placeholder="e.g. juan.delacruz" autocomplete="username" maxlength="30" required>
                <?= fieldError($errors, 'username') ?>
            </div>

            <div class="grid-two">
                <div class="field">
                    <label for="password">Password</label>
                    <div class="in-group">
                        <span class="in-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        </span>
                        <input type="password" id="password" name="password" placeholder="Create a password" aria-describedby="pwHint" autocomplete="new-password" required>
                    </div>
                    <?= fieldError($errors, 'password') ?>
                </div>
    
                <div class="field">
                    <label for="confirm">Confirm password</label>
                    <div class="in-group">
                        <span class="in-ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        </span>
                        <input type="password" id="confirm" name="confirm" placeholder="Type the password again" autocomplete="new-password" required>
                    </div>
                    <?= fieldError($errors, 'confirm') ?>
                </div>
            </div>
            <p class="form-hint" id="pwHint">Use at least 8 characters, with a letter and a number.</p>

            <div class="btn-row">
                <a href="login.php" class="btn btn-back">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                    Back
                </a>
                <button type="submit" class="btn btn-submit">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    Create Account
                </button>
            </div>
        </form>
    </main>
</div>
</body>
</html>
