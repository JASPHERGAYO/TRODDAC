<?php
// GET  -  the dispatch picture: pending requests (with nearest drivers), live trips, free drivers and units.
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/api.php';
require __DIR__ . '/../lib/AssignmentEngine.php';
requireApiRole(['operator', 'officer'], false, true);
$pdo = db();
AssignmentEngine::runIfDue($pdo);

$drivers = $pdo->query("SELECT d.id, u.full_name, u.phone, d.default_unit_id, tu.plate_number, d.last_latitude, d.last_longitude, d.last_seen_at
                          FROM drivers d
                          JOIN users u ON u.id = d.user_id
                          LEFT JOIN tricycle_units tu ON tu.id = d.default_unit_id
                         WHERE d.status = 'online' AND d.is_active = 1 AND u.is_active = 1
                           AND NOT EXISTS (SELECT 1 FROM route_assignments ra
                                            WHERE ra.driver_id = d.id AND ra.status IN ('scheduled','active')
                                              AND ra.start_datetime < DATE_ADD(NOW(), INTERVAL 40 MINUTE) AND ra.end_datetime > NOW())
                         ORDER BY u.full_name")->fetchAll();

$units = $pdo->query("SELECT tu.id, tu.plate_number, tu.body_number
                        FROM tricycle_units tu
                       WHERE tu.status = 'active'
                         AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.unit_id = tu.id AND b.status IN ('assigned','en_route','in_progress'))
                         AND NOT EXISTS (SELECT 1 FROM route_assignments ra
                                          WHERE ra.unit_id = tu.id AND ra.status IN ('scheduled','active')
                                            AND ra.start_datetime < DATE_ADD(NOW(), INTERVAL 40 MINUTE) AND ra.end_datetime > NOW())
                       ORDER BY tu.plate_number")->fetchAll();

$driverOut = array_map(static fn($d) => [
    'id'        => (int)$d['id'],
    'name'      => $d['full_name'],
    'phone'     => $d['phone'],
    'unit_id'   => $d['default_unit_id'] !== null ? (int)$d['default_unit_id'] : null,
    'plate'     => $d['plate_number'],
    'lat'       => $d['last_latitude'] !== null ? (float)$d['last_latitude'] : null,
    'lng'       => $d['last_longitude'] !== null ? (float)$d['last_longitude'] : null,
    'seen_at'   => $d['last_seen_at'],
], $drivers);

$pendingRows = $pdo->query(BOOKING_SELECT . " WHERE b.status = 'pending' ORDER BY b.requested_at ASC LIMIT 50")->fetchAll();
$pending = [];
foreach ($pendingRows as $b) {
    $p = bookingPayload($b, false);
    $p['passenger'] = ['name' => $b['passenger_name'], 'phone' => $b['passenger_phone']];
    $p['waiting_minutes'] = max(0, (int)floor((time() - strtotime($b['requested_at'])) / 60));
    // Nearest online drivers to the pickup point (drivers without a position go last).
    $near = [];
    foreach ($driverOut as $d) {
        $near[] = ['driver_id' => $d['id'], 'km' => $d['lat'] === null ? null
                   : round(haversineKm($d['lat'], $d['lng'], $p['pickup']['lat'], $p['pickup']['lng']), 2)];
    }
    usort($near, static fn($a, $c) => ($a['km'] ?? 9999) <=> ($c['km'] ?? 9999));
    $p['nearest'] = array_slice($near, 0, 3);
    $pending[] = $p;
}

$liveRows = $pdo->query(BOOKING_SELECT . " WHERE b.status IN ('assigned','en_route','in_progress') ORDER BY b.assigned_at DESC LIMIT 50")->fetchAll();
$live = [];
foreach ($liveRows as $b) {
    $p = bookingPayload($b, false);
    $p['passenger'] = ['name' => $b['passenger_name'], 'phone' => $b['passenger_phone']];
    $p['driver'] = driverBlock($b);
    $live[] = $p;
}

jsonOut([
    'ok'      => true,
    'pending' => $pending,
    'live'    => $live,
    'drivers' => $driverOut,
    'units'   => array_map(static fn($u) => ['id' => (int)$u['id'], 'plate' => $u['plate_number'], 'body' => $u['body_number']], $units),
    'server_time' => date('c'),
]);
