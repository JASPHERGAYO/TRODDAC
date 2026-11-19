<?php
// Shared helpers. Include at the top of every protected page.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const ROLE_HOME = [
    'admin'     => 'admin_dashboard.php',
    'operator'  => 'operator_dashboard.php',
    'driver'    => 'driver_dashboard.php',
    'passenger' => 'passenger_dashboard.php',
];

function roleHome(string $role): string {
    return ROLE_HOME[$role] ?? 'login.php';
}

// Only logged-in users whose role is in $allowed may see the page.
function requireRole(array $allowed): void {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
    if (!in_array($_SESSION['role'], $allowed, true)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><link rel="stylesheet" href="assets/css/style.css"></head><body>'
           . '<main class="card"><h1>Access denied</h1><p class="sub">Your role cannot open this page.</p>'
           . '<a class="btn btn-link" href="' . htmlspecialchars(roleHome($_SESSION['role'])) . '">Go to my dashboard</a></main></body></html>';
        exit;
    }
}
