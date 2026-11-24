<?php
// Operators no longer dispatch: drivers accept bookings themselves. Old bookmarks land on the dashboard.
require __DIR__ . '/config/auth.php';
$user = requireLogin();
flash('info', 'Bookings are now accepted directly by online drivers. Operators can monitor trips and routes.');
redirect(roleHome($user['role']));
