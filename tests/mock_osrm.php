<?php
// Tiny stand-in for the OSRM routing service, used by the automated tests (php -S 127.0.0.1:8081 tests/mock_osrm.php).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if (!preg_match('#/route/v1/driving/(-?[\d.]+),(-?[\d.]+);(-?[\d.]+),(-?[\d.]+)#', (string)$path, $m)) {
    http_response_code(400);
    echo json_encode(['code' => 'InvalidUrl']);
    exit;
}
[, $lng1, $lat1, $lng2, $lat2] = array_map('floatval', $m);
$rad = static fn(float $d) => $d * M_PI / 180;
$a = sin($rad($lat2 - $lat1) / 2) ** 2 + cos($rad($lat1)) * cos($rad($lat2)) * sin($rad($lng2 - $lng1) / 2) ** 2;
$straight = 2 * 6371008.8 * asin(min(1, sqrt($a)));       // metres
if ($straight < 1) {
    echo json_encode(['code' => 'NoRoute', 'message' => 'Impossible route']);
    exit;
}
$points = [];
for ($i = 0; $i <= 12; $i++) {
    $t = $i / 12;
    $points[] = [$lng1 + ($lng2 - $lng1) * $t + sin($t * M_PI) * 0.001, $lat1 + ($lat2 - $lat1) * $t];
}
echo json_encode(['code' => 'Ok', 'routes' => [[
    'distance' => $straight * 1.4,
    'duration' => $straight * 1.4 / 5.5,
    'geometry' => ['type' => 'LineString', 'coordinates' => $points],
]]]);
