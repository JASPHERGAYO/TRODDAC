<?php
require __DIR__ . '/config/auth.php';
startSecureSession();
$user = currentUser();
redirect($user ? roleHome($user['role']) : 'login.php');
