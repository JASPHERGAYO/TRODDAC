<?php
// Central configuration for the TRODDAC system (XAMPP defaults).
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'troddac',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
    // The browser version of database/migrate.php only answers to these addresses.
    'tools_allowed_ips' => ['127.0.0.1', '::1'],
];
