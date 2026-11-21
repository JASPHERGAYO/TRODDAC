<?php
// GET ?id=  or  ?current=1   -  one booking with driver, unit and live driver position.
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/api.php';
$user = requireApiRole(['passenger', 'driver', 'operator', 'officer'], false, true);
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
if ($id === 0 && $user['role'] === 'passenger' && isset($_GET['current'])) {
    $st = $pdo->prepare("SELECT id FROM bookings WHERE passenger_id = ? AND status IN ('pending','assigned','en_route','in_progress') ORDER BY id DESC LIMIT 1");
    $st->execute([$user['id']]);
    $id = (int)$st->fetchColumn();
    if ($id === 0) {
        jsonOut(['ok' => true, 'booking' => null]);
    }
}

$st = $pdo->prepare(BOOKING_SELECT . ' WHERE b.id = ?');
$st->execute([$id]);
$b = $st->fetch();
if (!$b) {
    apiFail('Booking not found.', 404);
}

$allowed = match ($user['role']) {
    'passenger' => (int)$b['passenger_id'] === (int)$user['id'],
    'driver'    => ($d = driverRowFor((int)$user['id'])) !== null && (int)$b['driver_id'] === (int)$d['id'],
    default     => true,
};
if (!$allowed) {
    apiFail('Booking not found.', 404);
}

$payload = bookingPayload($b);
$payload['driver'] = driverBlock($b);
if ($user['role'] !== 'passenger') {
    $payload['passenger'] = ['name' => $b['passenger_name'], 'phone' => $b['passenger_phone']];
}
if ($payload['driver'] !== null && $payload['live'] && $payload['driver']['lat'] !== null) {
    $target = $b['status'] === 'in_progress' ? [(float)$b['dest_latitude'], (float)$b['dest_longitude']] : [(float)$b['pickup_latitude'], (float)$b['pickup_longitude']];
    $payload['driver']['distance_km'] = round(haversineKm($payload['driver']['lat'], $payload['driver']['lng'], $target[0], $target[1]), 2);
}
if (!$payload['live']) {
    // The tricycle's position is only shared while the trip is live.
    if ($payload['driver'] !== null) {
        $payload['driver']['lat'] = $payload['driver']['lng'] = null;
    }
}
jsonOut(['ok' => true, 'booking' => $payload]);
