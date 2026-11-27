<?php
declare(strict_types=1);

// Legacy admin_dashboard.php forwards safely to the Association Officer dashboard.
require __DIR__ . '/config/auth.php';
$user = requireRole(['officer']);
redirect('officer_dashboard.php');
