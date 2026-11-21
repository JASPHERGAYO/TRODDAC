<?php
// GET ?plat=&plng=&dlat=&dlng=  ->  road route, distance and fare preview for the booking screen.
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/api.php';
requireApiRole(['passenger'], false, true);

$plat = numberOrNull($_GET['plat'] ?? null); $plng = numberOrNull($_GET['plng'] ?? null);
$dlat = numberOrNull($_GET['dlat'] ?? null); $dlng = numberOrNull($_GET['dlng'] ?? null);

if (!validLatLng($plat, $plng) || !validLatLng($dlat, $dlng)) {
    apiFail('Choose a valid pickup and destination on the map.');
}
if (!insideServiceArea($plat, $plng) || !insideServiceArea($dlat, $dlng)) {
    apiFail('Pickup and destination must be inside the TRODDAC service area.');
}

$cfg = appConfig();
$route = fetchRoute($plat, $plng, $dlat, $dlng);
if ($route['distance_km'] < $cfg['min_trip_km']) {
    apiFail('Pickup and destination are too close together.');
}
if ($route['distance_km'] > $cfg['max_trip_km']) {
    apiFail('That trip is longer than ' . $cfg['max_trip_km'] . ' km, which is outside tricycle service.');
}
$matrix = activeFareMatrix();
if ($matrix === null) {
    apiFail('Fares are not set up yet. Please try again later.', 503);
}

jsonOut([
    'ok'           => true,
    'source'       => $route['source'],
    'distance_km'  => $route['distance_km'],
    'duration_min' => $route['duration_min'],
    'geometry'     => $route['geometry'],
    'fare'         => fareFor($route['distance_km'], (int)$matrix['id']),
    'fare_matrix'  => [
        'name'             => $matrix['name'],
        'base_fare'        => (float)$matrix['base_fare'],
        'base_distance_km' => (float)$matrix['base_distance_km'],
        'per_km_rate'      => (float)$matrix['per_km_rate'],
        'minimum_fare'     => (float)$matrix['minimum_fare'],
    ],
]);
