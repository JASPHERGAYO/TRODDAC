<?php
// Run once: http://localhost/practice-login/setup.php  then DELETE this file.
require 'config/db.php';

$accounts = [
    ['admin',     'admin@example.com',     'admin123',     'admin'],
    ['operator1', 'operator@example.com',  'operator123',  'operator'],
    ['driver1',   'driver@example.com',    'driver123',    'driver'],
    ['passenger1','passenger@example.com', 'passenger123', 'passenger'],
];

$stmt = $pdo->prepare(
    "INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE password = VALUES(password), role = VALUES(role)"
);
foreach ($accounts as [$u, $e, $p, $r]) {
    $stmt->execute([$u, $e, password_hash($p, PASSWORD_DEFAULT), $r]);
    echo "Created/updated: $u / $p ($r)<br>";
}
echo '<br><strong>Delete setup.php now.</strong>';
