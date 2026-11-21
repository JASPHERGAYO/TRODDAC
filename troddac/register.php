<?php
require __DIR__ . '/config/auth.php';
startSecureSession();

if (currentUser()) {
    redirect(roleHome($_SESSION['role']));
}

$error = '';
$old = ['full_name' => '', 'username' => '', 'email' => '', 'phone' => ''];

if (isPost()) {
    foreach ($old as $key => $_) {
        $old[$key] = trim((string)($_POST[$key] ?? ''));
    }
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm'] ?? '');

    if (!verifyCsrf()) {
        $error = 'Your session expired. Please try again.';
    } elseif (($msg = validateNewAccount($old['full_name'], $old['username'], $old['email'], $old['phone'])) !== null) {
        $error = $msg;
    } elseif (($msg = validatePassword($password)) !== null) {
        $error = $msg;
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            // Self-registration always creates a PASSENGER. The role is never read from the form.
            db()->prepare('INSERT INTO users (username, email, password, full_name, phone, role) VALUES (?,?,?,?,?,?)')
                ->execute([$old['username'], $old['email'], password_hash($password, PASSWORD_DEFAULT),
                           $old['full_name'], $old['phone'] !== '' ? $old['phone'] : null, 'passenger']);
            flash('success', 'Account created. You can log in now.');
            redirect('login.php');
        } catch (PDOException $e) {
            $error = duplicateMessage($e) ?? 'Could not create the account. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account - TRODDAC</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <main class="card">
        <h1>Create account</h1>
        <p class="sub">Passenger registration. Drivers and operators are added by the Association Officer.</p>

        <?php if ($error): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif; ?>

        <form method="POST" action="register.php">
            <?= csrfField() ?>
            <label for="full_name">Full name</label>
            <input type="text" id="full_name" name="full_name" value="<?= e($old['full_name']) ?>" required>

            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= e($old['username']) ?>" autocomplete="username" required>

            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= e($old['email']) ?>" autocomplete="email" required>

            <label for="phone">Phone (optional)</label>
            <input type="text" id="phone" name="phone" value="<?= e($old['phone']) ?>" autocomplete="tel">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="new-password" required>

            <label for="confirm">Confirm password</label>
            <input type="password" id="confirm" name="confirm" autocomplete="new-password" required>

            <button type="submit" class="btn">Create account</button>
        </form>

        <p class="foot">Already registered? <a href="login.php">Log in</a></p>
    </main>
</body>
</html>
