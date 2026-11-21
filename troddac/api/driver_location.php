<?php
// POST JSON {lat, lng, speed?, heading?}  -  the driver's phone shares its position.
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/api.php';
$user = requireApiRole(['driver']);
apiMustBePost();

$in  = apiInput();
$lat = numberOrNull($in['lat'] ?? null);
$lng = numberOrNull($in['lng'] ?? null);
if (!validLatLng($lat, $lng)) {
    apiFail('Invalid position.');
}
$speed   = numberOrNull($in['speed'] ?? null);          // metres per second from the browser
$speedKm = $speed !== null && $speed >= 0 && $speed < 70 ? round($speed * 3.6, 1) : null;
$heading = numberOrNull($in['heading'] ?? null);
$heading = $heading !== null && $heading >= 0 && $heading <= 360 ? (int)round($heading) : null;

$driver = driverRowFor((int)$user['id']);
if ($driver === null) {
    apiFail('Driver profile not found.', 404);
}
if ($driver['status'] === 'offline') {
    jsonOut(['ok' => true, 'ignored' => true]);     // positions are only kept while the driver is online
}

$pdo = db();
$last = $pdo->prepare('SELECT MAX(recorded_at) FROM driver_locations WHERE driver_id = ?');
$last->execute([$driver['id']]);
$lastAt = $last->fetchColumn();
if ($lastAt !== null && $lastAt !== false && (time() - strtotime((string)$lastAt)) < 2) {
    jsonOut(['ok' => true, 'ignored' => true]);     // at most one stored ping every 2 seconds
}

$live = $pdo->prepare("SELECT id FROM bookings WHERE driver_id = ? AND status IN ('assigned','en_route','in_progress') LIMIT 1");
$live->execute([$driver['id']]);
$bookingId = $live->fetchColumn();

$pdo->prepare('INSERT INTO driver_locations (driver_id, booking_id, latitude, longitude, speed_kmh, heading_deg) VALUES (?,?,?,?,?,?)')
    ->execute([$driver['id'], $bookingId ?: null, $lat, $lng, $speedKm, $heading]);
jsonOut(['ok' => true]);
