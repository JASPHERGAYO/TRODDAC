<?php
declare(strict_types=1);

/** Shared helpers for the JSON endpoints in /api. */

function apiInput(): array
{
    static $input = null;
    if ($input === null) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        $input = is_array($decoded) ? $decoded : $_POST;
    }
    return $input;
}

function apiFail(string $message, int $status = 422): never
{
    jsonOut(['ok' => false, 'error' => $message], $status);
}

function apiMustBePost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        apiFail('Use POST.', 405);
    }
}

/** Database trigger errors carry a friendly "TRD-Exxx: message"; show that, hide everything else. */
function dbErrorMessage(PDOException $e): string
{
    if (isSchemaError($e)) {
        return SCHEMA_HELP;
    }
    if (preg_match('/TRD-E\d+:\s*([^"\']+?)(?:\s*\(\d+\))?$/m', $e->getMessage(), $m)) {
        return trim($m[1]);
    }
    error_log('TRODDAC database error: ' . $e->getMessage());
    return 'Something went wrong. Please try again.';
}

function numberOrNull(mixed $value): ?float
{
    return is_numeric($value) ? (float)$value : null;
}

function validLatLng(?float $lat, ?float $lng): bool
{
    return $lat !== null && $lng !== null && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
}

function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371.0088;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

function insideServiceArea(float $lat, float $lng): bool
{
    $a = appConfig()['service_area'];
    return haversineKm($a['center_lat'], $a['center_lng'], $lat, $lng) <= $a['radius_km'];
}

/** Cleans a place label typed or looked up by the browser. */
function cleanLabel(mixed $label, float $lat, float $lng): string
{
    $text = trim(strip_tags((string)$label));
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    if ($text === '') {
        return sprintf('Pinned location (%.5f, %.5f)', $lat, $lng);
    }
    return preg_match('/^.{1,150}/us', $text, $m) ? $m[0] : substr($text, 0, 150);
}

function httpGetJson(string $url, int $timeoutSeconds): ?array
{
    $body = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(4, $timeoutSeconds),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'TRODDAC/1.0 (tricycle booking system)',
        ]);
        $result = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $body = ($result !== false && $status === 200) ? $result : null;
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => $timeoutSeconds, 'user_agent' => 'TRODDAC/1.0']]);
        $result = @file_get_contents($url, false, $ctx);
        $body = $result === false ? null : $result;
    }
    if ($body === null) {
        return null;
    }
    $json = json_decode($body, true);
    return is_array($json) ? $json : null;
}

/** Keeps at most $max points (always the first and last) so stored routes stay small. */
function simplifyLine(array $points, int $max = 300): array
{
    $n = count($points);
    if ($n <= $max) {
        return array_values($points);
    }
    $out = [];
    $step = ($n - 1) / ($max - 1);
    for ($i = 0; $i < $max; $i++) {
        $out[] = $points[(int)round($i * $step)];
    }
    return $out;
}

/**
 * Road route between two points: ['source' => 'road'|'estimate', 'distance_km', 'duration_min', 'geometry' => [[lat,lng],...]].
 * Uses OSRM; if it cannot be reached, falls back to straight line x road factor.
 */
function fetchRoute(float $fromLat, float $fromLng, float $toLat, float $toLng): array
{
    $cfg = appConfig()['routing'];
    $url = rtrim($cfg['osrm_base'], '/') . sprintf('/route/v1/driving/%.6f,%.6f;%.6f,%.6f?overview=full&geometries=geojson&steps=false', $fromLng, $fromLat, $toLng, $toLat);
    $json = httpGetJson($url, (int)$cfg['timeout_seconds']);

    if ($json !== null && ($json['code'] ?? '') === 'Ok' && !empty($json['routes'][0]['distance'])) {
        $route = $json['routes'][0];
        $line = [];
        foreach (($route['geometry']['coordinates'] ?? []) as $c) {
            if (is_array($c) && count($c) >= 2) {
                $line[] = [round((float)$c[1], 6), round((float)$c[0], 6)];
            }
        }
        if (count($line) >= 2) {
            return [
                'source'       => 'road',
                'distance_km'  => round($route['distance'] / 1000, 2),
                'duration_min' => max(1, (int)ceil(($route['duration'] ?? 0) / 60)),
                'geometry'     => simplifyLine($line),
            ];
        }
    }

    $km = round(haversineKm($fromLat, $fromLng, $toLat, $toLng) * (float)$cfg['fallback_road_factor'], 2);
    return [
        'source'       => 'estimate',
        'distance_km'  => $km,
        'duration_min' => max(1, (int)ceil($km / max(1, (float)$cfg['avg_speed_kmh']) * 60)),
        'geometry'     => [[round($fromLat, 6), round($fromLng, 6)], [round($toLat, 6), round($toLng, 6)]],
    ];
}

function activeFareMatrix(): ?array
{
    $row = db()->query('SELECT id, name, base_fare, base_distance_km, per_km_rate, minimum_fare FROM fare_matrices
                         WHERE is_active = 1 AND effective_from <= CURDATE() AND (effective_to IS NULL OR effective_to >= CURDATE()) LIMIT 1')->fetch();
    return $row ?: null;
}

function fareFor(float $distanceKm, int $matrixId): float
{
    $st = db()->prepare('SELECT fn_calculate_fare(?, ?)');
    $st->execute([$distanceKm, $matrixId]);
    return (float)$st->fetchColumn();
}

function driverRowFor(int $userId): ?array
{
    $st = db()->prepare('SELECT d.id, d.status, d.is_active, d.default_unit_id, d.last_latitude, d.last_longitude
                           FROM drivers d WHERE d.user_id = ?');
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}

/** Turns a bookings row (plus joined columns) into the JSON shape the screens use. */
function bookingPayload(array $b, bool $withGeometry = true): array
{
    $live = in_array($b['status'], ['assigned', 'en_route', 'in_progress'], true);
    $out = [
        'id'             => (int)$b['id'],
        'code'           => $b['booking_code'],
        'status'         => $b['status'],
        'live'           => $live,
        'pickup'         => ['label' => $b['pickup_label'], 'lat' => (float)$b['pickup_latitude'], 'lng' => (float)$b['pickup_longitude']],
        'dest'           => ['label' => $b['dest_label'], 'lat' => (float)$b['dest_latitude'], 'lng' => (float)$b['dest_longitude']],
        'distance_km'    => (float)$b['distance_km'],
        'route_source'   => $b['route_source'] ?? 'road',
        'est_minutes'    => (int)$b['est_duration_min'],
        'fare'           => (float)$b['estimated_fare'],
        'final_fare'     => $b['final_fare'] !== null ? (float)$b['final_fare'] : null,
        'passengers'     => (int)($b['passenger_count'] ?? 1),
        'notes'          => $b['notes'] ?? null,
        'requested_at'   => $b['requested_at'],
        'assigned_at'    => $b['assigned_at'],
        'started_at'     => $b['started_at'],
        'completed_at'   => $b['completed_at'],
        'cancel_reason'  => $b['cancel_reason'],
    ];
    if ($withGeometry) {
        $geo = json_decode((string)($b['route_geometry'] ?? ''), true);
        $out['geometry'] = is_array($geo) ? $geo : [];
    }
    return $out;
}

/** SQL used by several endpoints: a booking with its passenger, driver and unit. */
const BOOKING_SELECT = 'SELECT b.*, p.full_name AS passenger_name, p.phone AS passenger_phone,
                               du.full_name AS driver_name, du.phone AS driver_phone,
                               d.last_latitude, d.last_longitude, d.last_seen_at,
                               tu.plate_number, tu.body_number, tu.color AS unit_color
                          FROM bookings b
                          JOIN users p ON p.id = b.passenger_id
                          LEFT JOIN drivers d ON d.id = b.driver_id
                          LEFT JOIN users du ON du.id = d.user_id
                          LEFT JOIN tricycle_units tu ON tu.id = b.unit_id';

function driverBlock(array $b): ?array
{
    if ($b['driver_id'] === null) {
        return null;
    }
    $recent = $b['last_seen_at'] !== null && (time() - strtotime($b['last_seen_at'])) < 120;
    return [
        'id'      => (int)$b['driver_id'],
        'name'    => $b['driver_name'],
        'phone'   => $b['driver_phone'],
        'plate'   => $b['plate_number'],
        'body'    => $b['body_number'],
        'color'   => $b['unit_color'],
        'lat'     => $b['last_latitude'] !== null ? (float)$b['last_latitude'] : null,
        'lng'     => $b['last_longitude'] !== null ? (float)$b['last_longitude'] : null,
        'seen_at' => $b['last_seen_at'],
        'fresh'   => $recent,
    ];
}

/** A route's drawn path: the stored one, or a road route between its terminals (cached once found). */
function routePath(array $route): array
{
    $stored = json_decode((string)($route['path_json'] ?? ''), true);
    if (is_array($stored) && count($stored) >= 2) {
        return $stored;
    }
    $found = fetchRoute((float)$route['o_lat'], (float)$route['o_lng'], (float)$route['d_lat'], (float)$route['d_lng']);
    if ($found['source'] === 'road') {
        db()->prepare('UPDATE routes SET path_json = ? WHERE id = ?')->execute([json_encode($found['geometry']), $route['route_id']]);
    }
    return $found['geometry'];
}

/** Official route assignments of a driver (current and upcoming) with terminals and the route line. */
function driverOfficialRoutes(int $driverId, int $limit = 5): array
{
    $st = db()->prepare("SELECT ra.id, ra.status, ra.start_datetime, ra.end_datetime, ra.rotation_cycle, ra.queue_position,
                                r.id AS route_id, r.code, r.name, r.distance_km, r.path_json,
                                ot.name AS o_name, ot.latitude AS o_lat, ot.longitude AS o_lng,
                                dt.name AS d_name, dt.latitude AS d_lat, dt.longitude AS d_lng,
                                tu.plate_number
                           FROM route_assignments ra
                           JOIN routes r     ON r.id = ra.route_id
                           JOIN terminals ot ON ot.id = r.origin_terminal_id
                           JOIN terminals dt ON dt.id = r.destination_terminal_id
                           LEFT JOIN tricycle_units tu ON tu.id = ra.unit_id
                          WHERE ra.driver_id = ? AND ra.status IN ('scheduled','active') AND ra.end_datetime > NOW()
                          ORDER BY ra.start_datetime LIMIT " . (int)$limit);
    $st->execute([$driverId]);
    $out = [];
    foreach ($st->fetchAll() as $i => $a) {
        $out[] = [
            'id'       => (int)$a['id'],
            'status'   => $a['status'],
            'start'    => $a['start_datetime'],
            'end'      => $a['end_datetime'],
            'plate'    => $a['plate_number'],
            'route'    => ['code' => $a['code'], 'name' => $a['name'], 'distance_km' => (float)$a['distance_km']],
            'origin'   => ['name' => $a['o_name'], 'lat' => (float)$a['o_lat'], 'lng' => (float)$a['o_lng']],
            'terminal' => ['name' => $a['o_name']],
            'dest'     => ['name' => $a['d_name'], 'lat' => (float)$a['d_lat'], 'lng' => (float)$a['d_lng']],
            'geometry' => $i === 0 ? routePath($a) : [],     // only the first one needs the line drawn
        ];
    }
    return $out;
}
