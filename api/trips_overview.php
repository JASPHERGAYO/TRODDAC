<?php
// GET  -  read-only view of passenger trips for operators and officers (no dispatching).
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/api.php';
require __DIR__ . '/../lib/Bookings.php';
requireApiRole(['operator', 'officer'], false, true);
$pdo = db();
Bookings::expireStale($pdo);

$map = static function (array $b): array {
    $p = bookingPayload($b, false);
    $p['passenger'] = ['name' => $b['passenger_name'], 'phone' => $b['passenger_phone']];
    $p['driver'] = driverBlock($b);
    return $p;
};
$live  = $pdo->query(BOOKING_SELECT . " WHERE b.status IN ('assigned','en_route','in_progress') ORDER BY b.assigned_at DESC LIMIT 50")->fetchAll();
$open  = $pdo->query(BOOKING_SELECT . " WHERE b.status = 'available' ORDER BY b.requested_at ASC LIMIT 50")->fetchAll();
$recent = $pdo->query(BOOKING_SELECT . " WHERE b.status IN ('completed','cancelled','rejected') ORDER BY b.updated_at DESC, b.id DESC LIMIT 30")->fetchAll();
$online = (int)$pdo->query("SELECT COUNT(*) FROM drivers d JOIN users u ON u.id = d.user_id WHERE d.status = 'online' AND d.is_active = 1 AND u.is_active = 1")->fetchColumn();

jsonOut([
    'ok'     => true,
    'online_drivers' => $online,
    'open'   => array_map($map, $open),
    'live'   => array_map($map, $live),
    'recent' => array_map($map, $recent),
]);
