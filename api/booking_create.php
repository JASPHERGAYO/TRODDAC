<?php
// POST JSON {pickup:{lat,lng,label}, dest:{lat,lng,label}, passengers, notes}
// The server works out distance, route and fare itself; nothing numeric from the browser is trusted.
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/api.php';
require __DIR__ . '/../lib/Bookings.php';
$user = requireApiRole(['passenger']);
apiMustBePost();

$in   = apiInput();
$from = is_array($in['pickup'] ?? null) ? $in['pickup'] : [];
$to   = is_array($in['dest'] ?? null) ? $in['dest'] : [];
$plat = numberOrNull($from['lat'] ?? null); $plng = numberOrNull($from['lng'] ?? null);
$dlat = numberOrNull($to['lat'] ?? null);   $dlng = numberOrNull($to['lng'] ?? null);

if (!validLatLng($plat, $plng) || !validLatLng($dlat, $dlng)) {
    apiFail('Choose a valid pickup and destination on the map.');
}
if (!insideServiceArea($plat, $plng) || !insideServiceArea($dlat, $dlng)) {
    apiFail('Pickup and destination must be inside the TRODDAC service area.');
}
$pax = (int)($in['passengers'] ?? 1);
if ($pax < 1 || $pax > 6) {
    apiFail('Passengers must be between 1 and 6.');
}
$notes = trim(strip_tags((string)($in['notes'] ?? '')));
$notes = $notes === '' ? null : (preg_match('/^.{1,255}/us', $notes, $m) ? $m[0] : substr($notes, 0, 255));

$cfg = appConfig();
$route = fetchRoute($plat, $plng, $dlat, $dlng);
if ($route['distance_km'] < $cfg['min_trip_km']) {
    apiFail('Pickup and destination are too close together.');
}
if ($route['distance_km'] > $cfg['max_trip_km']) {
    apiFail('That trip is longer than ' . $cfg['max_trip_km'] . ' km, which is outside tricycle service.');
}
if (activeFareMatrix() === null) {
    apiFail('Fares are not set up yet. Please try again later.', 503);
}

$pdo = db();
Bookings::expireStale($pdo);
try {
    $pdo->beginTransaction();
    // Lock this passenger so two quick taps cannot create two open bookings.
    $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$user['id']]);
    $open = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE passenger_id = ? AND status IN ('available','assigned','en_route','in_progress')");
    $open->execute([$user['id']]);
    if ((int)$open->fetchColumn() > 0) {
        $pdo->rollBack();
        apiFail('You already have an open booking. Finish or cancel it first.', 409);
    }

    $pdo->prepare('INSERT INTO bookings (passenger_id, pickup_label, pickup_latitude, pickup_longitude, dest_label, dest_latitude, dest_longitude,
                                         passenger_count, notes, distance_km, route_source, route_geometry, est_duration_min)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([
            $user['id'],
            cleanLabel($from['label'] ?? '', $plat, $plng), $plat, $plng,
            cleanLabel($to['label'] ?? '', $dlat, $dlng), $dlat, $dlng,
            $pax, $notes, $route['distance_km'], $route['source'], json_encode($route['geometry']), $route['duration_min'],
        ]);
    $id = (int)$pdo->lastInsertId();
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    apiFail(dbErrorMessage($e), 422);
}

$row = $pdo->prepare('SELECT booking_code, estimated_fare, distance_km, status FROM bookings WHERE id = ?');
$row->execute([$id]);
$b = $row->fetch();
jsonOut(['ok' => true, 'id' => $id, 'code' => $b['booking_code'], 'fare' => (float)$b['estimated_fare'],
         'distance_km' => (float)$b['distance_km'], 'status' => $b['status']], 201);
