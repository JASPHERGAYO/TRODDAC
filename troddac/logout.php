<?php
require __DIR__ . '/config/auth.php';
startSecureSession();
if (isPost() && verifyCsrf()) {
    logoutUser();
    startSecureSession();
    flash('success', 'You have been logged out.');
    redirect('login.php');
}
$user = currentUser();
redirect($user ? roleHome($user['role']) : 'login.php');
