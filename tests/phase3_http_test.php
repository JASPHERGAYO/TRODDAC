<?php
/**
 * Phase 3 test: booking, dispatch and driver endpoints over real HTTP.
 *
 *   C:\xampp\php\php.exe tests\phase3_http_test.php [base-url] [fallback-base-url]
 *
 * Works with the real OSRM service or without internet (the route then falls back to an estimate).
 * The optional second URL points at a copy of the site whose routing service is unreachable; it is
 * only used by the fallback check. Needs the seed data; cleans up all bookings it makes.
 */
declare(strict_types=1);

$base     = rtrim($argv[1] ?? 'http://localhost/troddac', '/');
$fallback = isset($argv[2]) ? rtrim($argv[2], '/') : null;
$cfg = (require __DIR__ . '/../config/config.php')['db'];
$pdo = new PDO("mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset={$cfg['charset']}", $cfg['user'], $cfg['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

final class Client
{
    private string $jar;
    private ?string $token = null;
    public function __construct(private string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'trd'); }

    public function raw(string $method, string $path, ?string $body = null, array $headers = []): array
    {
        $ch = curl_init($this->base . '/' . ltrim($path, '/'));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 40,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $raw = curl_exec($ch);
        if ($raw === false) { fwrite(STDERR, "Cannot reach {$this->base}: " . curl_error($ch) . PHP_EOL); exit(2); }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $size), $m);
        return ['status' => $status, 'body' => substr($raw, $size), 'location' => $m[1] ?? ''];
    }
    public function page(string $path): array { return $this->raw('GET', $path); }
    public function login(string $user, string $pw): void
    {
        $r = $this->page('login.php');
        preg_match('/name="csrf_token" value="([^"]+)"/', $r['body'], $m);
        $this->raw('POST', 'login.php', http_build_query(['csrf_token' => $m[1] ?? '', 'identifier' => $user, 'password' => $pw]));
        $acc = $this->page('account.php');
        preg_match('/name="csrf-token" content="([^"]+)"/', $acc['body'], $t);
        $this->token = $t[1] ?? null;
    }
    public function api(string $path, string $method = 'GET', ?array $body = null, bool $withToken = true): array
    {
        $headers = ['Accept: application/json'];
        $payload = null;
        if ($method !== 'GET') {
            $headers[] = 'Content-Type: application/json';
            if ($withToken && $this->token) { $headers[] = 'X-CSRF-Token: ' . $this->token; }
            $payload = json_encode($body ?? []);
        }
        $r = $this->raw($method, 'api/' . ltrim($path, '/'), $payload, $headers);
        $j = json_decode($r['body'], true);
        $j = is_array($j) ? $j : ['ok' => false, 'error' => 'not json: ' . substr($r['body'], 0, 120)];
        $j['http'] = $r['status'];
        return $j;
    }
}

$pass = 0; $fail = 0;
function check(bool $ok, string $label, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . ($ok || $detail === '' ? '' : "  -> $detail") . PHP_EOL;
}
function val(PDO $pdo, string $sql, array $p = []): mixed { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); }
function row(PDO $pdo, string $sql, array $p = []): array { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetch() ?: []; }

$started = (string)val($pdo, 'SELECT NOW()');
// The automatic route engine would give drivers official routes depending on the time of day; switch it off for this test.
$autoBefore = $pdo->query('SELECT route_id, auto_assign_enabled FROM rotation_settings')->fetchAll();
$pdo->exec('UPDATE rotation_settings SET auto_assign_enabled = 0');
$pdo->exec('DELETE FROM login_attempts');
$pdo->exec('DELETE FROM driver_locations');
$pdo->exec("UPDATE drivers SET status = 'offline'");
if ((int)val($pdo, "SELECT COUNT(*) FROM bookings WHERE status IN ('available','assigned','en_route','in_progress')") > 0) {
    fwrite(STDERR, "There are open bookings in the database. Finish or delete them before running this test." . PHP_EOL);
    exit(2);
}

$P1 = new Client($base); $P1->login('passenger1', 'passenger123');
$P2 = new Client($base); $P2->login('passenger2', 'passenger123');
$D1 = new Client($base); $D1->login('driver1', 'driver123');
$D2 = new Client($base); $D2->login('driver2', 'driver123');
$OP = new Client($base); $OP->login('operator1', 'operator123');
$anon = new Client($base);

$d1 = (int)val($pdo, 'SELECT d.id FROM drivers d JOIN users u ON u.id = d.user_id WHERE u.username = "driver1"');
$d2 = (int)val($pdo, 'SELECT d.id FROM drivers d JOIN users u ON u.id = d.user_id WHERE u.username = "driver2"');
$pickup = ['lat' => 14.3294, 'lng' => 120.9367, 'label' => 'Poblacion'];
$dest   = ['lat' => 14.3400, 'lng' => 120.9700, 'label' => 'Salawag'];
$route  = "route.php?plat={$pickup['lat']}&plng={$pickup['lng']}&dlat={$dest['lat']}&dlng={$dest['lng']}";

echo "== Endpoint guards ==" . PHP_EOL;
check($anon->api($route)['http'] === 401, 'an anonymous visitor gets 401 from the API');
check($P1->api('driver_claim.php', 'POST', ['id' => 1])['http'] === 403 && $OP->api('driver_claim.php', 'POST', ['id' => 1])['http'] === 403, 'passengers and operators cannot accept bookings (403)');
check($OP->api('operator_action.php', 'POST', ['action' => 'assign', 'booking_id' => 1, 'driver_id' => 1])['http'] === 404 && $OP->api('operator_queue.php')['http'] === 404, 'the operator dispatch endpoints no longer exist (404)');
check($D1->api($route)['http'] === 403, 'a driver cannot use the passenger route endpoint (403)');
check($P1->api('booking_create.php', 'POST', ['pickup' => $pickup, 'dest' => $dest], false)['http'] === 419, 'a booking without a CSRF token is refused (419)');
check($P1->api('booking_create.php')['http'] === 405, 'booking_create only accepts POST (405)');

echo PHP_EOL . "== Pages, maps and mobile layout ==" . PHP_EOL;
$page = $P1->page('passenger_book.php');
check($page['status'] === 200 && str_contains($page['body'], 'assets/vendor/leaflet/leaflet.js') && str_contains($page['body'], 'passenger_book.js'), 'booking page loads Leaflet and its script');
check(str_contains($page['body'], 'width=device-width, initial-scale=1'), 'pages declare the mobile viewport');
check($D1->page('driver_dashboard.php')['status'] === 200 && str_contains($D1->page('driver_dashboard.php')['body'], 'driver_app.js'), 'driver dashboard loads its live script');
$old = $OP->page('operator_dispatch.php');
check($old['status'] === 302 && str_contains($old['location'], 'operator_dashboard.php'), 'the old dispatch page just forwards operators to their dashboard');
$od = $OP->page('operator_dashboard.php');
check($od['status'] === 200 && !str_contains($od['body'], 'Assign driver') && !str_contains($od['body'], 'operator_dispatch.php') && str_contains($od['body'], 'operator_trips.php'), 'the operator dashboard has no dispatch or assignment controls, only trip monitoring');
check($OP->page('operator_trips.php')['status'] === 200 && $P1->page('operator_trips.php')['status'] === 403 && $D1->page('passenger_book.php')['status'] === 403, 'trip monitoring and booking screens are role-locked');
$css = (string)file_get_contents(__DIR__ . '/../assets/css/dashboard.css');
check(str_contains($css, '@media (min-width: 640px)') && str_contains($css, '@media (min-width: 1024px)') && str_contains($css, 'min-height: 44px'), 'CSS is mobile-first with tablet and desktop breakpoints and 44px touch targets');
check(is_file(__DIR__ . '/../assets/vendor/leaflet/leaflet.js') && is_file(__DIR__ . '/../assets/vendor/leaflet/images/marker-icon.png'), 'Leaflet is bundled locally');

echo PHP_EOL . "== Route and fare preview ==" . PHP_EOL;
$r = $P1->api($route);
check($r['ok'] === true && $r['distance_km'] > 0 && count($r['geometry']) >= 2 && in_array($r['source'], ['road', 'estimate'], true), 'a valid trip returns distance, geometry and source', json_encode($r));
$expected = (float)val($pdo, 'SELECT fn_calculate_fare(?, (SELECT id FROM fare_matrices WHERE is_active = 1))', [$r['distance_km']]);
check(abs($r['fare'] - $expected) < 0.005, 'the previewed fare matches the database fare function');
check($P1->api("route.php?plat=14.3294&plng=120.9367&dlat=15.5&dlng=120.97")['http'] === 422, 'a destination outside the service area is refused');
check($P1->api("route.php?plat=14.3294&plng=120.9367&dlat=14.3294&dlng=120.9367")['http'] === 422, 'identical pickup and destination are refused');
check($P1->api("route.php?plat=200&plng=120.9&dlat=14.3&dlng=120.9")['http'] === 422, 'invalid coordinates are refused');

echo PHP_EOL . "== Passenger booking ==" . PHP_EOL;
$forged = ['pickup' => $pickup, 'dest' => ['lat' => $dest['lat'], 'lng' => $dest['lng'], 'label' => '<script>alert(1)</script>Salawag'],
           'passengers' => 2, 'notes' => 'phase3-test <b>gate</b>', 'distance_km' => 0.1, 'fare' => 1, 'estimated_fare' => 1];
$c = $P1->api('booking_create.php', 'POST', $forged);
check(($c['ok'] ?? false) === true && $c['http'] === 201, 'a passenger can create a booking', json_encode($c));
$b1 = (int)($c['id'] ?? 0);
$db = row($pdo, 'SELECT * FROM bookings WHERE id = ?', [$b1]);
check(abs((float)$db['distance_km'] - $r['distance_km']) < 0.01, 'distance is calculated by the server, not taken from the browser');
check(abs((float)$db['estimated_fare'] - $expected) < 0.005, 'the fare is calculated by the server, not taken from the browser');
check(!str_contains($db['dest_label'], '<') && !str_contains((string)$db['notes'], '<'), 'labels and notes are stripped of HTML tags');
check($db['status'] === 'available' && $db['driver_id'] === null && (int)$db['passenger_count'] === 2, 'new bookings are pending with no driver');
check($P1->api('booking_create.php', 'POST', ['pickup' => $pickup, 'dest' => $dest])['http'] === 409, 'a second open booking is refused');
$cur = $P1->api('booking_status.php?current=1');
check(($cur['booking']['id'] ?? 0) === $b1 && $cur['booking']['status'] === 'available', 'the passenger can read their current booking');
check($P2->api('booking_status.php?id=' . $b1)['http'] === 404, "another passenger cannot read someone else's booking");
check($P2->api('booking_cancel.php', 'POST', ['id' => $b1])['http'] === 404, "another passenger cannot cancel someone else's booking");

echo PHP_EOL . "== Drivers see available bookings and accept them ==" . PHP_EOL;
$s0 = $D1->api('driver_state.php');
check(($s0['available'] ?? null) === [] && str_contains($s0['blocked_reason'] ?? '', 'offline'), 'an offline driver sees no bookings and is told to go online', json_encode($s0));
$c0 = $D1->api('driver_claim.php', 'POST', ['id' => $b1]);
check($c0['http'] === 409 && ($c0['code'] ?? '') === 'offline', 'an offline driver cannot accept a booking', json_encode($c0));
check($D1->api('driver_availability.php', 'POST', ['status' => 'online'])['ok'] === true && $D2->api('driver_availability.php', 'POST', ['status' => 'online'])['ok'] === true, 'two drivers go online');
$ping = $D1->api('driver_location.php', 'POST', ['lat' => 14.3300, 'lng' => 120.9380, 'speed' => 3.2, 'heading' => 90]);
check($ping['ok'] === true && empty($ping['ignored']), 'a driver position ping is stored');
check(($D1->api('driver_location.php', 'POST', ['lat' => 14.3301, 'lng' => 120.9381])['ignored'] ?? false) === true, 'pings faster than every 2 seconds are ignored');
check($D1->api('driver_location.php', 'POST', ['lat' => 999, 'lng' => 0])['http'] === 422, 'an impossible position is refused');
$D2->api('driver_location.php', 'POST', ['lat' => 14.3500, 'lng' => 120.9800]);

$s1 = $D1->api('driver_state.php');
$s2 = $D2->api('driver_state.php');
$av = $s1['available'][0] ?? [];
check(count($s1['available']) === 1 && $av['id'] === $b1 && $av['passenger'] === 'Passenger' && $av['fare'] > 0 && count($av['pickup']) === 3, 'AN ONLINE DRIVER SEES THE NEW BOOKING RIGHT AWAY (first name only, fare, pickup, drop-off)', json_encode($s1['available'] ?? null));
check(count($s2['available']) === 1 && $s2['available'][0]['id'] === $b1, 'EVERY online driver sees the same available booking');
check(!isset($av['phone']) && !str_contains(json_encode($av), 'Passenger One') && !str_contains(json_encode($av), '0917'), 'the passenger\'s surname and phone stay hidden until a driver accepts');
check(($av['away_km'] ?? 99) < 1 && ($s2['available'][0]['away_km'] ?? 0) > 3, 'each driver sees how far the pickup is from their own position');
$vis = $OP->api('trips_overview.php');
check(count($vis['open'] ?? []) === 1 && ($vis['online_drivers'] ?? 0) === 2, 'the operator can see the open booking and online drivers (read-only)');
$ps = $P1->api('booking_status.php?id=' . $b1);
check($ps['booking']['status'] === 'available' && $ps['booking']['online_drivers'] === 2 && $ps['booking']['expires_in_min'] > 0, 'the passenger sees that online drivers can see the request');

$cl = $D1->api('driver_claim.php', 'POST', ['id' => $b1]);
check(($cl['ok'] ?? false) === true && $cl['http'] === 200, 'the first driver accepts the booking', json_encode($cl));
$db = row($pdo, 'SELECT status, driver_id, unit_id, assigned_by, assigned_at, estimated_fare FROM bookings WHERE id = ?', [$b1]);
check($db['status'] === 'assigned' && (int)$db['driver_id'] === $d1 && (int)$db['unit_id'] === (int)val($pdo, 'SELECT default_unit_id FROM drivers WHERE id = ?', [$d1])
      && (int)$db['assigned_by'] === (int)val($pdo, 'SELECT user_id FROM drivers WHERE id = ?', [$d1]) && $db['assigned_at'] !== null, 'it is assigned to that driver with their unit, instantly');
check(val($pdo, 'SELECT status FROM drivers WHERE id = ?', [$d1]) === 'on_trip', 'the driver becomes on_trip automatically');
$s2 = $D2->api('driver_state.php');
check($s2['available'] === [], 'the booking disappears from the other driver\'s list');
$cl2 = $D2->api('driver_claim.php', 'POST', ['id' => $b1]);
check($cl2['http'] === 409 && ($cl2['code'] ?? '') === 'taken' && str_contains($cl2['error'] ?? '', 'another driver just accepted'), 'a second driver pressing Accept gets a clean "just taken" message', json_encode($cl2));
check((int)val($pdo, 'SELECT driver_id FROM bookings WHERE id = ?', [$b1]) === $d1 && val($pdo, 'SELECT status FROM drivers WHERE id = ?', [$d2]) === 'online', 'the loser changed nothing and stays online');
$again = $D1->api('driver_claim.php', 'POST', ['id' => $b1]);
check(($again['ok'] ?? false) === true && ($again['code'] ?? '') === 'already_yours', 'pressing Accept twice is harmless (it stays with the same driver)');
foreach ([0, 999999, -3] as $bad) {
    $r = $D2->api('driver_claim.php', 'POST', ['id' => $bad]);
    check($r['http'] === 404 && ($r['ok'] ?? true) === false, "accepting a booking that does not exist ($bad) is a clean 404");
}
$r = $D2->api('driver_claim.php', 'POST', ['id' => '1 OR 1=1']);
check(($r['ok'] ?? true) === false && $r['http'] === 404, 'an injected id is treated as a number and refused');

$ds = $D1->api('driver_state.php');
check(($ds['booking']['id'] ?? 0) === $b1 && $ds['booking']['status'] === 'assigned' && $ds['booking']['passenger']['name'] === 'Passenger One', 'THE ACCEPTED BOOKING IS NOW ON THE DRIVER\'S DASHBOARD (full passenger details)');
check(count($ds['booking']['geometry'] ?? []) >= 2 && $ds['availability'] === 'on_trip', 'the driver gets the route line and an on_trip status');
check($D2->api('driver_action.php', 'POST', ['id' => $b1, 'action' => 'on_the_way'])['http'] === 404, 'another driver cannot act on the booking');
$ps = $P1->api('booking_status.php?id=' . $b1);
check($ps['booking']['status'] === 'assigned' && $ps['booking']['driver']['plate'] === 'TRC-0001' && $ps['booking']['driver']['lat'] !== null && $ps['booking']['driver']['fresh'] === true, 'the passenger sees the driver, unit plate and live position');
check(isset($ps['booking']['driver']['distance_km']), 'the passenger sees how far the driver is');

echo PHP_EOL . "== Driver actions ==" . PHP_EOL;
check($D1->api('driver_action.php', 'POST', ['id' => $b1, 'action' => 'complete'])['http'] === 409, 'a trip cannot be completed before pickup');
check($D1->api('driver_action.php', 'POST', ['id' => $b1, 'action' => 'decline'])['ok'] === true, 'a driver can hand an accepted booking back');
check(val($pdo, 'SELECT status FROM bookings WHERE id = ?', [$b1]) === 'available' && val($pdo, 'SELECT driver_id FROM bookings WHERE id = ?', [$b1]) === null
      && val($pdo, 'SELECT status FROM drivers WHERE id = ?', [$d1]) === 'online', 'handing back makes the booking available again and frees the driver');
check(count($D2->api('driver_state.php')['available']) === 1 && $D1->api('driver_state.php')['available'] === [], 'the other driver sees it again; the driver who handed it back is not offered it');
check(($D1->api('driver_claim.php', 'POST', ['id' => $b1])['ok'] ?? false) === true, 'driver 1 accepts it again to continue the trip');
check($D1->api('driver_action.php', 'POST', ['id' => $b1, 'action' => 'on_the_way'])['ok'] === true, 'driver: on my way');
check($P1->api('booking_status.php?id=' . $b1)['booking']['status'] === 'en_route', 'the passenger sees "on the way"');
check($D1->api('driver_action.php', 'POST', ['id' => $b1, 'action' => 'decline'])['http'] === 409, 'a booking cannot be handed back once the driver is on the way');
$off = $D1->api('driver_availability.php', 'POST', ['status' => 'offline']);
check(($off['ok'] ?? true) === false && str_contains($off['error'] ?? '', 'live passenger trip'), 'a driver cannot go offline in the middle of a trip', json_encode($off));
check($D1->api('driver_action.php', 'POST', ['id' => $b1, 'action' => 'picked_up'])['ok'] === true, 'driver: passenger picked up');
$cx = $P1->api('booking_cancel.php', 'POST', ['id' => $b1]);
check($cx['http'] === 409 && str_contains($cx['error'], 'already started'), 'a trip in progress cannot be cancelled');
check($D1->api('driver_action.php', 'POST', ['id' => $b1, 'action' => 'complete'])['ok'] === true, 'driver: trip completed');
$done = row($pdo, 'SELECT status, final_fare, completed_at FROM bookings WHERE id = ?', [$b1]);
check($done['status'] === 'completed' && (float)$done['final_fare'] === (float)$db['estimated_fare'] && $done['completed_at'] !== null, 'completion records the final fare and time');
check(val($pdo, 'SELECT status FROM drivers WHERE id = ?', [$d1]) === 'online', 'the driver is online again after the trip');
$after = $P1->api('booking_status.php?id=' . $b1)['booking']['driver'];
check(array_key_exists('lat', $after) && $after['lat'] === null && $after['lng'] === null, 'the driver\'s position is no longer shared once the trip is over');
check((int)val($pdo, 'SELECT COUNT(*) FROM booking_status_history WHERE booking_id = ?', [$b1]) >= 7, 'every status change is in the booking history');

echo PHP_EOL . "== Cancel and expiry ==" . PHP_EOL;
$c2 = $P1->api('booking_create.php', 'POST', ['pickup' => $pickup, 'dest' => $dest]);
check(($c2['ok'] ?? false) === true, 'the passenger can book again after finishing');
check(count($D1->api('driver_state.php')['available']) === 1, 'the new booking reaches online drivers immediately');
check($P1->api('booking_cancel.php', 'POST', ['id' => $c2['id']])['ok'] === true && val($pdo, 'SELECT status FROM bookings WHERE id = ?', [$c2['id']]) === 'cancelled', 'an available booking can be cancelled by its passenger');
$gone = $D1->api('driver_claim.php', 'POST', ['id' => $c2['id']]);
check($gone['http'] === 409 && ($gone['code'] ?? '') === 'cancelled' && $D1->api('driver_state.php')['available'] === [], 'a cancelled booking disappears from drivers and cannot be accepted ("the passenger cancelled")');
$pdo->prepare("INSERT INTO bookings (passenger_id, pickup_label, pickup_latitude, pickup_longitude, dest_label, dest_latitude, dest_longitude, distance_km, requested_at)
               VALUES ((SELECT id FROM users WHERE username = 'passenger2'), 'Old', 14.33, 120.94, 'Older', 14.34, 120.97, 3.5, DATE_SUB(NOW(), INTERVAL 30 MINUTE))")->execute();
$stale = (int)$pdo->lastInsertId();
check($D1->api('driver_state.php')['available'] === [], 'a booking nobody accepted for 15+ minutes is not offered any more');
$rs = $P2->api('booking_status.php?id=' . $stale);
check($rs['booking']['status'] === 'rejected' && $rs['booking']['cancel_reason'] === 'No driver accepted in time', 'the passenger is told that no driver accepted in time');
check(($P2->api('booking_create.php', 'POST', ['pickup' => $pickup, 'dest' => $dest])['ok'] ?? false) === true, 'and can book again');
$P2->api('booking_cancel.php', 'POST', ['id' => (int)val($pdo, "SELECT id FROM bookings WHERE passenger_id = (SELECT id FROM users WHERE username = 'passenger2') AND status = 'available'")]);

echo PHP_EOL . "== Module A <-> Module B conflict through the API ==" . PHP_EOL;
$pdo->exec("DELETE FROM driver_schedules WHERE driver_id = $d2");
$R1 = (int)val($pdo, 'SELECT id FROM routes WHERE code = "R1"');
$today = (string)val($pdo, 'SELECT CURDATE()');
$pdo->prepare("INSERT INTO route_assignments (route_id, driver_id, unit_id, start_datetime, end_datetime) VALUES (?,?,?,?,?)")
    ->execute([$R1, $d2, (int)val($pdo, 'SELECT default_unit_id FROM drivers WHERE id = ?', [$d2]), "$today 00:01:00", "$today 23:58:00"]);
$assignmentId = (int)$pdo->lastInsertId();
$c4 = $P2->api('booking_create.php', 'POST', ['pickup' => $pickup, 'dest' => $dest]);
$st4 = $D2->api('driver_state.php');
check($st4['available'] === [] && str_contains($st4['blocked_reason'] ?? '', 'official route'), 'a driver on an official route is not shown passenger bookings', json_encode($st4['blocked_reason'] ?? null));
$a = $D2->api('driver_claim.php', 'POST', ['id' => $c4['id']]);
check(($a['ok'] ?? true) === false && str_contains($a['error'] ?? '', 'official route'), 'and cannot accept one', json_encode($a));
$pdo->exec("UPDATE route_assignments SET status = 'cancelled' WHERE id = $assignmentId");
check(count($D2->api('driver_state.php')['available']) === 1, 'once the assignment is cancelled the driver sees the booking');
$a = $D2->api('driver_claim.php', 'POST', ['id' => $c4['id']]);
check(($a['ok'] ?? false) === true, 'and can accept it', json_encode($a));
check($D2->api('driver_action.php', 'POST', ['id' => $c4['id'], 'action' => 'decline'])['ok'] === true && val($pdo, 'SELECT status FROM bookings WHERE id = ?', [$c4['id']]) === 'available', 'a driver can hand a booking back');
$P2->api('booking_cancel.php', 'POST', ['id' => $c4['id']]);

if ($fallback !== null) {
    echo PHP_EOL . "== Routing service unavailable ==" . PHP_EOL;
    $F = new Client($fallback); $F->login('passenger1', 'passenger123');
    $fr = $F->api($route);
    $straight = 6371.0088 * 2 * asin(sqrt(sin(deg2rad($dest['lat'] - $pickup['lat']) / 2) ** 2 + cos(deg2rad($pickup['lat'])) * cos(deg2rad($dest['lat'])) * sin(deg2rad($dest['lng'] - $pickup['lng']) / 2) ** 2));
    check(($fr['ok'] ?? false) === true && $fr['source'] === 'estimate' && abs($fr['distance_km'] - $straight * 1.3) < 0.02, 'when routing is down the distance falls back to a straight-line estimate x 1.3', json_encode($fr));
}

// ---- cleanup
$pdo->prepare('DELETE FROM route_assignment_history WHERE assignment_id = ?')->execute([$assignmentId]);
$pdo->prepare('DELETE FROM route_assignments WHERE id = ?')->execute([$assignmentId]);
$pdo->exec('DELETE FROM driver_locations');
$pdo->exec('DELETE FROM bookings');
$pdo->exec("UPDATE drivers SET status = 'offline'");
$ins = $pdo->prepare('INSERT IGNORE INTO driver_schedules (driver_id, day_of_week, start_time, end_time) VALUES (?,?,?,?)');
for ($day = 1; $day <= 6; $day++) { $ins->execute([$d2, $day, '05:00:00', '21:00:00']); }
$pdo->prepare('DELETE FROM login_attempts WHERE attempted_at >= ?')->execute([$started]);
foreach ($autoBefore as $a) { $pdo->prepare('UPDATE rotation_settings SET auto_assign_enabled = ? WHERE route_id = ?')->execute([$a['auto_assign_enabled'], $a['route_id']]); }

echo PHP_EOL . "Result: $pass passed, $fail failed." . PHP_EOL;
exit($fail === 0 ? 0 : 1);
