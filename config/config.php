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

    // Road routing. The public OSRM demo server is fine for testing; for real use host your own
    // (https://project-osrm.org) or change this URL. If the service cannot be reached the system
    // falls back to a straight-line estimate x fallback_road_factor and marks it as an estimate.
    'routing' => [
        'osrm_base'            => 'https://router.project-osrm.org',
        'timeout_seconds'      => 6,
        'fallback_road_factor' => 1.3,
        'avg_speed_kmh'        => 20,
    ],

    // Bookings must start and end inside this circle (Dasmarinas City centre).
    'service_area' => [
        'center_lat' => 14.3294,
        'center_lng' => 120.9367,
        'radius_km'  => 25,
    ],
    'min_trip_km' => 0.1,
    'max_trip_km' => 30,

    'map' => [
        'tile_url'     => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        'attribution'  => '&copy; OpenStreetMap contributors',
        'default_zoom' => 14,
    ],

    // How often the browser asks the server for updates.
    'poll_seconds' => 4,
];
