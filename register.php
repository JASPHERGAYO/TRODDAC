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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create passenger account - TRODDAC</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <main class="card">
        <h1>Create account</h1>
        <p class="sub">Passenger sign-up. Drivers and operators are added by the Association Officer.</p>

        <?php if ($general): ?><div class="alert" role="alert"><?= e($general) ?></div><?php endif; ?>

        <form method="POST" action="register.php" novalidate>
            <?= csrfField() ?>
            <div class="name-row">
                <div class="grow"><label for="first_name">First name</label>
                    <input type="text" id="first_name" name="first_name" value="<?= e($old['first_name']) ?>" placeholder="e.g. Juan" autocomplete="given-name" maxlength="50" required>
                    <?= fieldError($errors, 'first_name') ?></div>
                <div class="mi"><label for="middle_initial">M.I.</label>
                    <input type="text" id="middle_initial" name="middle_initial" value="<?= e($old['middle_initial']) ?>" placeholder="e.g. D" maxlength="2" autocomplete="additional-name">
                    <?= fieldError($errors, 'middle_initial') ?></div>
            </div>
            <label for="last_name">Last name</label>
            <input type="text" id="last_name" name="last_name" value="<?= e($old['last_name']) ?>" placeholder="e.g. Dela Cruz" autocomplete="family-name" maxlength="50" required>
            <?= fieldError($errors, 'last_name') ?>

            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= e($old['username']) ?>" placeholder="e.g. juan.delacruz" autocomplete="username" maxlength="30" required>
            <?= fieldError($errors, 'username') ?>

            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= e($old['email']) ?>" placeholder="e.g. juan@example.com" autocomplete="email" required>
            <?= fieldError($errors, 'email') ?>

            <label for="phone">Mobile number (optional)</label>
            <input type="tel" id="phone" name="phone" value="<?= e($old['phone']) ?>" placeholder="e.g. 0917 123 4567" autocomplete="tel">
            <?= fieldError($errors, 'phone') ?>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" placeholder="At least 8 characters, with a letter and a number" autocomplete="new-password" required>
            <?= fieldError($errors, 'password') ?>

            <label for="confirm">Confirm password</label>
            <input type="password" id="confirm" name="confirm" placeholder="Type the password again" autocomplete="new-password" required>
            <?= fieldError($errors, 'confirm') ?>

            <button type="submit" class="btn">Create account</button>
        </form>

        <p class="foot">Already registered? <a href="login.php">Log in</a></p>
    </main>
</body>
</html>
