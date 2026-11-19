<?php
session_start();
require 'config/db.php';
require 'config/auth.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . roleHome($_SESSION['role']));
    exit;
}

// CSRF token
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = 'Session expired. Reload the page and try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } else {
        $stmt = $pdo->prepare('SELECT id, username, password, role FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];
            header('Location: ' . roleHome($user['role']));
            exit;
        }
        $error = 'Wrong username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <main class="card">
        <h1>Welcome back</h1>
        <p class="sub">Log in to continue.</p>

        <?php if ($error): ?>
            <div class="alert" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form id="loginForm" method="POST" action="login.php" novalidate>
            <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">

            <label for="username">Username or email</label>
            <input type="text" id="username" name="username" value="<?= htmlspecialchars($username) ?>" autocomplete="username" required>
            <small class="field-error" id="usernameError"></small>

            <label for="password">Password</label>
            <div class="pw-wrap">
                <input type="password" id="password" name="password" autocomplete="current-password" required>
                <button type="button" id="togglePw" aria-label="Show password">Show</button>
            </div>
            <small class="field-error" id="passwordError"></small>

            <button type="submit" class="btn">Log in</button>
        </form>

        <p class="foot">No account yet? <a href="register.php">Create one</a></p>
    </main>
    <script src="assets/js/script.js"></script>
</body>
</html>
