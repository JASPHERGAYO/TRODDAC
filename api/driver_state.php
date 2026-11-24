<?php
// GET  -  everything the driver screen needs in one call: availability, the live booking, next official routes.
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/api.php';
require __DIR__ . '/../lib/AssignmentEngine.php';
require __DIR__ . '/../lib/Bookings.php';
$user = requireApiRole(['driver'], false, true);
$pdo = db();
AssignmentEngine::runIfDue($pdo);   // keeps official routes moving while drivers use the app
Bookings::expireStale($pdo);        // closes bookings nobody accepted in time

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

$blocked   = Bookings::blockedReason($pdo, $driver);
$available = $blocked === null ? Bookings::availableFor($pdo, $driver) : [];

jsonOut([
    'ok'           => true,
    'available'    => $available,
    'blocked_reason' => $blocked,
    'availability' => $driver['status'],
    'booking'      => $booking,
    'official'     => driverOfficialRoutes((int)$driver['id']),
    'server_time'  => date('c'),
]);
