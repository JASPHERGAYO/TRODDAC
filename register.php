<?php
session_start();
require 'config/db.php';

$error = '';
$success = '';
$username = $email = '';
$role = 'passenger';
$publicRoles = ['passenger', 'driver', 'operator']; // admin can never be self-registered

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';
    $role     = $_POST['role'] ?? '';

    if ($username === '' || $email === '' || $password === '') {
        $error = 'Fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif (!in_array($role, $publicRoles, true)) {
        $error = 'Choose a valid role.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $check = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
        $check->execute([$username, $email]);
        if ($check->fetch()) {
            $error = 'That username or email is already taken.';
        } else {
            $ins = $pdo->prepare('INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)');
            $ins->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
            $success = 'Account created. You can log in now.';
            $username = $email = '';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <main class="card">
        <h1>Create account</h1>
        <p class="sub">It takes a few seconds.</p>

        <?php if ($error): ?><div class="alert" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

        <form method="POST" action="register.php">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= htmlspecialchars($username) ?>" required>

            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>

            <label for="role">I am a</label>
            <select id="role" name="role" required>
                <option value="passenger" <?= $role === 'passenger' ? 'selected' : '' ?>>Passenger</option>
                <option value="driver" <?= $role === 'driver' ? 'selected' : '' ?>>Driver</option>
                <option value="operator" <?= $role === 'operator' ? 'selected' : '' ?>>Operator</option>
            </select>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>

            <label for="confirm">Confirm password</label>
            <input type="password" id="confirm" name="confirm" required>

            <button type="submit" class="btn">Create account</button>
        </form>

        <p class="foot">Already registered? <a href="login.php">Log in</a></p>
    </main>
</body>
</html>
