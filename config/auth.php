<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const ROLE_HOME = [
    'passenger' => 'passenger_dashboard.php',
    'driver'    => 'driver_dashboard.php',
    'operator'  => 'operator_dashboard.php',
    'officer'   => 'officer_dashboard.php',
];
const ROLE_LABEL = [
    'passenger' => 'Passenger',
    'driver'    => 'Driver',
    'operator'  => 'Operator',
    'officer'   => 'Association Officer',
];
const SESSION_IDLE_SECONDS = 1800;   // log out after 30 idle minutes
const MAX_FAILED_LOGINS    = 5;      // per username + IP
const MAX_FAILED_PER_IP    = 20;     // per IP
const LOCKOUT_MINUTES      = 15;
// Used so a wrong username costs the same time as a wrong password.
const DUMMY_HASH = '$2y$10$vbNRROH2A5ixOkdgnk53bOWfk9kkJ0of.9pnoFsFbH9zuu0hxreOy';

/* ------------------------------------------------------------------ helpers */

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** First character of a string, safe for UTF-8 names even without the mbstring extension. */
function firstChar(string $text): string
{
    return preg_match('/^./us', $text, $m) ? $m[0] : substr($text, 0, 1);
}

function textUpper(string $text): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
}

function textLower(string $text): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

/** First $max characters of a UTF-8 string. */
function textCut(string $text, int $max): string
{
    return preg_match('/^.{0,' . $max . '}/us', $text, $m) ? $m[0] : substr($text, 0, $max);
}

/** Number of characters (not bytes) in a UTF-8 string. */
function charLength(string $text): int
{
    return (int)preg_match_all('/./us', $text);
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function isPost(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function clientIp(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function roleHome(string $role): string
{
    return ROLE_HOME[$role] ?? 'login.php';
}

/* ------------------------------------------------------------------ session */

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('TRODDAC_SESSION');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    if (isset($_SESSION['user_id'], $_SESSION['last_activity'])
        && time() - (int)$_SESSION['last_activity'] > SESSION_IDLE_SECONDS) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('error', 'You were logged out after 30 minutes of inactivity.');
    }
    if (isset($_SESSION['user_id'])) {
        $_SESSION['last_activity'] = time();
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function takeFlashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

/* --------------------------------------------------------------------- CSRF */

function csrfToken(): string
{
    return $_SESSION['csrf'] ?? '';
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

/** Checks the form field (or the X-CSRF-Token header for fetch/AJAX calls). */
function verifyCsrf(): bool
{
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) && $sent !== '' && hash_equals(csrfToken(), $sent);
}

/* ------------------------------------------------------------ login / logout */

function loginUser(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']       = (int)$user['id'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf']          = bin2hex(random_bytes(32));
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
}

function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}

/** The logged-in user, re-read from the database on every request so disabling or changes apply at once. */
function currentUser(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    if (!empty($_SESSION['user_id'])) {
        $st = db()->prepare('SELECT id, username, email, full_name, phone, role, is_active, last_login_at FROM users WHERE id = ?');
        $st->execute([$_SESSION['user_id']]);
        $row = $st->fetch();
        if ($row && (int)$row['is_active'] === 1) {
            $user = $row;
            $_SESSION['role'] = $row['role'];
            // Lets the database triggers record who made each change.
            db()->prepare('SET @app_user_id = ?')->execute([(int)$row['id']]);
        }
    }
    return $user;
}

function requireLogin(): array
{
    startSecureSession();
    $user = currentUser();
    if ($user === null) {
        if (!empty($_SESSION['user_id'])) {
            logoutUser();
            startSecureSession();
            flash('error', 'Your session ended. Please log in again.');
        }
        redirect('login.php');
    }
    return $user;
}

function requireRole(array $roles): array
{
    $user = requireLogin();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        $home = e(roleHome($user['role']));
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<title>Access denied</title><link rel="stylesheet" href="assets/css/style.css"></head><body>'
           . '<main class="card"><h1>Access denied</h1><p class="sub">Your role (' . e(ROLE_LABEL[$user['role']] ?? $user['role'])
           . ') cannot open this page.</p><a class="btn btn-link" href="' . $home . '">Go to my dashboard</a></main></body></html>';
        exit;
    }
    return $user;
}

/* --------------------------------------------------------- login throttling */

function isLockedOut(string $identifier, string $ip): bool
{
    $pdo = db();
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts
                          WHERE success = 0 AND username = ? AND ip_address = ?
                            AND attempted_at > DATE_SUB(NOW(), INTERVAL ' . LOCKOUT_MINUTES . ' MINUTE)');
    $st->execute([$identifier, $ip]);
    if ((int)$st->fetchColumn() >= MAX_FAILED_LOGINS) {
        return true;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts
                          WHERE success = 0 AND ip_address = ?
                            AND attempted_at > DATE_SUB(NOW(), INTERVAL ' . LOCKOUT_MINUTES . ' MINUTE)');
    $st->execute([$ip]);
    return (int)$st->fetchColumn() >= MAX_FAILED_PER_IP;
}

function recordLoginAttempt(string $identifier, string $ip, bool $success): void
{
    $pdo = db();
    $pdo->prepare('INSERT INTO login_attempts (username, ip_address, success) VALUES (?,?,?)')
        ->execute([substr($identifier, 0, 100), $ip, $success ? 1 : 0]);
    if ($success) {
        $pdo->prepare('DELETE FROM login_attempts WHERE success = 0 AND username = ? AND ip_address = ?')->execute([$identifier, $ip]);
    }
    $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 2 DAY)');
}

/* ------------------------------------------------------------- JSON (API) */

function jsonOut(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** For API endpoints: answers 401/403/419 as JSON instead of redirecting. */
function requireApiRole(array $roles, bool $checkCsrf = true, bool $releaseSession = false): array
{
    startSecureSession();
    $user = currentUser();
    if ($user === null) {
        jsonOut(['ok' => false, 'error' => 'Not logged in.'], 401);
    }
    if (!in_array($user['role'], $roles, true)) {
        jsonOut(['ok' => false, 'error' => 'Your role cannot use this endpoint.'], 403);
    }
    if ($checkCsrf && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && !verifyCsrf()) {
        jsonOut(['ok' => false, 'error' => 'Invalid or missing CSRF token.'], 419);
    }
    if ($releaseSession) {
        // Read-only polling endpoints must not hold the session lock, or they queue behind each other.
        session_write_close();
    }
    return $user;
}

/* ------------------------------------------------------------- validation */

function validatePassword(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must contain at least one letter and one number.';
    }
    return null;
}

function validateNewAccount(string $fullName, string $username, string $email, string $phone): ?string
{
    if ($fullName === '' || charLength($fullName) > 100) {
        return 'Enter the full name (up to 100 characters).';
    }
    if (!preg_match('/^[A-Za-z0-9_.]{3,30}$/', $username)) {
        return 'Username must be 3-30 characters: letters, numbers, dot or underscore.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        return 'Enter a valid email address.';
    }
    if ($phone !== '' && !preg_match('/^[0-9+\- ]{7,20}$/', $phone)) {
        return 'Phone number may only contain digits, spaces, + and -.';
    }
    return null;
}

/** Turns a duplicate-key error into a friendly message; returns null for other errors. */
function duplicateMessage(PDOException $e): ?string
{
    if (($e->errorInfo[1] ?? 0) !== 1062) {
        return null;
    }
    return str_contains($e->getMessage(), 'uq_users_email') ? 'That email address is already registered.'
         : (str_contains($e->getMessage(), 'uq_users_username') ? 'That username is already taken.'
         : (str_contains($e->getMessage(), 'uq_drivers_license') ? 'That license number is already registered.'
         : 'That value is already in use.'));
}
