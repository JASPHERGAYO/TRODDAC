<?php
// GET  -  everything the driver screen needs in one call: availability, the live booking, next official routes.
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/api.php';
$user = requireApiRole(['driver'], false, true);
$pdo = db();

$driver = driverRowFor((int)$user['id']);
if ($driver === null) {
    apiFail('Your driver profile has not been set up. Ask the Association Officer.', 404);
}

$st = $pdo->prepare(BOOKING_SELECT . " WHERE b.driver_id = ? AND b.status IN ('assigned','en_route','in_progress') LIMIT 1");
$st->execute([$driver['id']]);
$b = $st->fetch();
$booking = null;
if ($b) {
    $booking = bookingPayload($b);
    $booking['passenger'] = ['name' => $b['passenger_name'], 'phone' => $b['passenger_phone']];
    $booking['unit'] = ['plate' => $b['plate_number'], 'body' => $b['body_number']];
}

$routes = $pdo->prepare("SELECT r.code, r.name, ra.start_datetime, ra.end_datetime, ra.status
                           FROM route_assignments ra JOIN routes r ON r.id = ra.route_id
                          WHERE ra.driver_id = ? AND ra.status IN ('scheduled','active') AND ra.end_datetime > NOW()
                          ORDER BY ra.start_datetime LIMIT 5");
$routes->execute([$driver['id']]);

jsonOut([
    'ok'           => true,
    'availability' => $driver['status'],
    'booking'      => $booking,
    'routes'       => $routes->fetchAll(),
    'server_time'  => date('c'),
]);
