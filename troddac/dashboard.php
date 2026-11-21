<?php
require __DIR__ . '/config/auth.php';
$user = requireLogin();
redirect(roleHome($user['role']));
