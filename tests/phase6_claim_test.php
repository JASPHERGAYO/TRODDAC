<?php
/**
 * Phase 6 test: drivers claim bookings. Proves that two (or more) drivers pressing Accept at the same
 * instant can never both get a booking, that the loser gets a clean message, and that every status stays consistent.
 *
 *   C:\xampp\php\php.exe tests\phase6_claim_test.php [base-url]
 *
 * Starts several PHP processes at the same moment (needs proc_open, which XAMPP allows). Restores everything.
 */
declare(strict_types=1);

$base = rtrim($argv[1] ?? 'http://localhost/troddac', '/');
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/api.php';
require __DIR__ . '/../lib/Bookings.php';
$pdo = db();

$pass = 0; $fail = 0;
function check(bool $ok, string $label, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . ($ok || $detail === '' ? '' : "  -> $detail") . PHP_EOL;
}
function val(string $sql, array $p = []): mixed { global $pdo; $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); }
function rows(string $sql, array $p = []): array { global $pdo; $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(); }
function run(string $sql, array $p = []): void { global $pdo; $pdo->prepare($sql)->execute($p); }

if ((int)val("SELECT COUNT(*) FROM bookings WHERE status IN ('available','assigned','en_route','in_progress')") > 0) {
    fwrite(STDERR, "There are open bookings. Finish or delete them before running this test." . PHP_EOL);
    exit(2);
}
$autoBefore = rows('SELECT route_id, auto_assign_enabled FROM rotation_settings');
run('UPDATE rotation_settings SET auto_assign_enabled = 0');
run('DELETE FROM login_attempts');

$d = array_map('intval', array_column(rows('SELECT d.id FROM drivers d JOIN users u ON u.id = d.user_id ORDER BY u.username'), 'id'));
[$d1, $d2, $d3, $d4] = $d;
$userOf = static fn(int $driver) => (int)val('SELECT user_id FROM drivers WHERE id = ?', [$driver]);
$p1 = (int)val("SELECT id FROM users WHERE username = 'passenger1'");
$p2 = (int)val("SELECT id FROM users WHERE username = 'passenger2'");
$newBooking = static function (int $passenger) {
    global $pdo;
    run("INSERT INTO bookings (passenger_id, pickup_label, pickup_latitude, pickup_longitude, dest_label, dest_latitude, dest_longitude, distance_km)
         VALUES (?, 'Pickup', 14.33, 120.94, 'Drop-off', 14.34, 120.97, 3.5)", [$passenger]);
    return (int)$pdo->lastInsertId();
};
$allOnline = static fn() => run("UPDATE drivers SET status = 'online' WHERE id IN (?,?,?,?)", [$GLOBALS['d1'], $GLOBALS['d2'], $GLOBALS['d3'], $GLOBALS['d4']]);

/** Starts one PHP process per job; all of them act at the same moment. */
function race(array $jobs): array
{
    $startAt = microtime(true) + 1.2;
    $procs = [];
    foreach ($jobs as $i => [$mode, $driver, $user, $booking]) {
        $cmd = [PHP_BINARY, __DIR__ . '/claim_worker.php', $mode, (string)$driver, (string)$user, (string)$booking, sprintf('%.4f', $startAt)];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[$i] = [$p, $pipes];
    }
    $out = [];
    foreach ($procs as $i => [$p, $pipes]) {
        $text = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($p);
        $json = json_decode($text, true);
        $out[$i] = is_array($json) ? $json : ['ok' => false, 'code' => 'worker_error', 'message' => trim($text . ' ' . $err)];
    }
    return $out;
}
function wins(array $results): int { return count(array_filter($results, static fn($r) => ($r['ok'] ?? false) && in_array($r['code'] ?? '', ['claimed'], true))); }

echo "== Two drivers press Accept at the same instant (6 rounds) ==" . PHP_EOL;
$bad = 0; $clean = 0; $details = [];
for ($round = 1; $round <= 6; $round++) {
    $allOnline();
    $b = $newBooking($p1);
    $res = race([['claim', $d1, $userOf($d1), $b], ['claim', $d2, $userOf($d2), $b]]);
    $winner = (int)val('SELECT driver_id FROM bookings WHERE id = ?', [$b]);
    $loserRes = array_values(array_filter($res, static fn($r) => !($r['ok'] ?? false)));
    $okOne = wins($res) === 1 && count($loserRes) === 1 && in_array($winner, [$d1, $d2], true);
    $cleanMsg = $loserRes && ($loserRes[0]['code'] ?? '') === 'taken' && str_contains($loserRes[0]['message'] ?? '', 'another driver just accepted');
    $consistent = val('SELECT status FROM bookings WHERE id = ?', [$b]) === 'assigned' && val('SELECT status FROM drivers WHERE id = ?', [$winner]) === 'on_trip'
                  && val('SELECT status FROM drivers WHERE id = ?', [$winner === $d1 ? $d2 : $d1]) === 'online';
    if (!$okOne || !$consistent) { $bad++; $details[] = json_encode($res); }
    if ($cleanMsg) { $clean++; }
    run("UPDATE bookings SET status = 'cancelled' WHERE id = ?", [$b]);          // frees the winner for the next round
}
check($bad === 0, 'in every round exactly ONE driver got the booking and the other stayed free', implode(' | ', $details));
check($clean === 6, 'in every round the other driver got the clean "another driver just accepted" message', "$clean of 6");

echo PHP_EOL . "== Four drivers at once ==" . PHP_EOL;
$allOnline();
$b = $newBooking($p1);
$res = race([['claim', $d1, $userOf($d1), $b], ['claim', $d2, $userOf($d2), $b], ['claim', $d3, $userOf($d3), $b], ['claim', $d4, $userOf($d4), $b]]);
$winner = (int)val('SELECT driver_id FROM bookings WHERE id = ?', [$b]);
check(wins($res) === 1 && count(array_filter($res, static fn($r) => ($r['code'] ?? '') === 'taken')) === 3, 'with four drivers racing, one wins and three are told it was taken', json_encode($res));
check((int)val("SELECT COUNT(*) FROM drivers WHERE status = 'on_trip'") === 1 && val('SELECT status FROM drivers WHERE id = ?', [$winner]) === 'on_trip', 'only the winner is on a trip');
check((int)val('SELECT COUNT(*) FROM booking_status_history WHERE booking_id = ? AND new_status = "assigned"', [$b]) === 1, 'the history shows a single acceptance');
run("UPDATE bookings SET status = 'cancelled' WHERE id = ?", [$b]);

echo PHP_EOL . "== One driver double-clicks ==" . PHP_EOL;
$allOnline();
$b = $newBooking($p1);
$res = race([['claim', $d1, $userOf($d1), $b], ['claim', $d1, $userOf($d1), $b]]);
check(wins($res) === 1 && (int)val('SELECT driver_id FROM bookings WHERE id = ?', [$b]) === $d1 && (int)val("SELECT COUNT(*) FROM bookings WHERE driver_id = ? AND status IN ('assigned','en_route','in_progress')", [$d1]) === 1,
    'a double-click gives the driver the booking once, never twice', json_encode($res));
run("UPDATE bookings SET status = 'cancelled' WHERE id = ?", [$b]);

echo PHP_EOL . "== One driver tries to take two bookings at once ==" . PHP_EOL;
$allOnline();
$b1 = $newBooking($p1); $b2 = $newBooking($p2);
$res = race([['claim', $d1, $userOf($d1), $b1], ['claim', $d1, $userOf($d1), $b2]]);
check(wins($res) === 1 && (int)val("SELECT COUNT(*) FROM bookings WHERE driver_id = ? AND status IN ('assigned','en_route','in_progress')", [$d1]) === 1
      && (int)val("SELECT COUNT(*) FROM bookings WHERE status = 'available'") === 1, 'a driver can hold only one live booking; the other stays available for everyone', json_encode($res));
$loser = array_values(array_filter($res, static fn($r) => !($r['ok'] ?? false)))[0] ?? [];
check(in_array($loser['code'] ?? '', ['blocked', 'busy'], true) && ($loser['message'] ?? '') !== '' && !str_contains($loser['message'], 'SQLSTATE'), 'the second attempt gets a plain message, not a database error', json_encode($loser));
run("UPDATE bookings SET status = 'cancelled' WHERE status IN ('available','assigned')");

echo PHP_EOL . "== Passenger cancels while a driver accepts ==" . PHP_EOL;
$bad = 0; $details = [];
for ($round = 1; $round <= 5; $round++) {
    $allOnline();
    $b = $newBooking($p1);
    $res = race([['claim', $d1, $userOf($d1), $b], ['cancel', 0, 0, $b]]);
    $status = (string)val('SELECT status FROM bookings WHERE id = ?', [$b]);
    $dStatus = (string)val('SELECT status FROM drivers WHERE id = ?', [$d1]);
    $ok = in_array($status, ['cancelled', 'assigned'], true)
          && ($status === 'cancelled' ? $dStatus === 'online' : $dStatus === 'on_trip')
          && (int)val("SELECT COUNT(*) FROM drivers dr WHERE dr.status = 'on_trip' AND NOT EXISTS (SELECT 1 FROM bookings x WHERE x.driver_id = dr.id AND x.status IN ('assigned','en_route','in_progress'))") === 0;
    if (!$ok) { $bad++; $details[] = "$status/$dStatus " . json_encode($res); }
    run("UPDATE bookings SET status = 'cancelled' WHERE id = ? AND status <> 'cancelled'", [$b]);
}
check($bad === 0, 'whichever happens first, the booking and the driver end up in a consistent state', implode(' | ', $details));

echo PHP_EOL . "== Rules that stop a claim ==" . PHP_EOL;
run("UPDATE drivers SET status = 'offline'");
$b = $newBooking($p1);
$r = Bookings::claim($pdo, $d1, $userOf($d1), $b);
check(!$r['ok'] && $r['code'] === 'offline' && str_contains($r['message'], 'Go online'), 'an offline driver cannot accept');
$r = Bookings::claim($pdo, $d1, $userOf($d2), $b);
check(!$r['ok'] && $r['code'] === 'no_driver', "a driver cannot accept using someone else's profile");
run("UPDATE drivers SET status = 'online' WHERE id = ?", [$d1]);
run('UPDATE drivers SET default_unit_id = NULL WHERE id = ?', [$d1]);
$r = Bookings::claim($pdo, $d1, $userOf($d1), $b);
check(!$r['ok'] && str_contains($r['message'], 'no tricycle unit'), 'a driver without a unit is told to ask the officer');
run('UPDATE drivers SET default_unit_id = (SELECT id FROM tricycle_units WHERE body_number = "B-001") WHERE id = ?', [$d1]);
run("UPDATE tricycle_units SET status = 'maintenance' WHERE body_number = 'B-001'");
$r = Bookings::claim($pdo, $d1, $userOf($d1), $b);
check(!$r['ok'] && str_contains($r['message'], 'not found or not active'), 'a unit under maintenance blocks the claim with a plain message', json_encode($r));
run("UPDATE tricycle_units SET status = 'active' WHERE body_number = 'B-001'");
run("UPDATE drivers SET is_active = 0 WHERE id = ?", [$d1]);
$r = Bookings::claim($pdo, $d1, $userOf($d1), $b);
check(!$r['ok'] && str_contains($r['message'], 'not active'), 'a deactivated driver cannot accept');
run("UPDATE drivers SET is_active = 1 WHERE id = ?", [$d1]);
check(Bookings::claim($pdo, $d1, $userOf($d1), $b)['ok'], 'and once everything is in order the same driver can accept');
run("UPDATE bookings SET status = 'cancelled' WHERE id = ?", [$b]);

echo PHP_EOL . "== Over HTTP: two drivers, one tap each ==" . PHP_EOL;
final class Site
{
    public string $jar; public ?string $token = null;
    public function __construct(public string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'trd'); }
    public function curl(string $m, string $path, ?string $body = null, array $h = [])
    {
        $ch = curl_init($this->base . '/' . ltrim($path, '/'));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_TIMEOUT => 40, CURLOPT_CUSTOMREQUEST => $m, CURLOPT_HTTPHEADER => $h]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        return $ch;
    }
    public function login(string $u, string $p): self
    {
        $page = curl_exec($c = $this->curl('GET', 'login.php')); curl_close($c);
        preg_match('/name="csrf_token" value="([^"]+)"/', (string)$page, $m);
        curl_exec($c = $this->curl('POST', 'login.php', http_build_query(['csrf_token' => $m[1] ?? '', 'identifier' => $u, 'password' => $p]))); curl_close($c);
        $acc = curl_exec($c = $this->curl('GET', 'account.php')); curl_close($c);
        preg_match('/name="csrf-token" content="([^"]+)"/', (string)$acc, $t); $this->token = $t[1] ?? null;
        return $this;
    }
    public function api(string $path, string $m = 'GET', ?array $body = null): array
    {
        $ch = $this->handle($path, $m, $body); $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return (json_decode((string)$raw, true) ?: []) + ['http' => $code];
    }
    public function handle(string $path, string $m = 'GET', ?array $body = null)
    {
        $h = ['Accept: application/json'];
        if ($m !== 'GET') { $h[] = 'Content-Type: application/json'; $h[] = 'X-CSRF-Token: ' . $this->token; }
        return $this->curl($m, 'api/' . $path, $m === 'GET' ? null : json_encode($body ?? []), $h);
    }
}
$D1 = (new Site($base))->login('driver1', 'driver123'); $D2 = (new Site($base))->login('driver2', 'driver123');
$D3 = (new Site($base))->login('driver3', 'driver123');
$P1 = (new Site($base))->login('passenger1', 'passenger123'); $OP = (new Site($base))->login('operator1', 'operator123');
$allOnline();
$created = $P1->api('booking_create.php', 'POST', ['pickup' => ['lat' => 14.3294, 'lng' => 120.9367, 'label' => 'Poblacion'], 'dest' => ['lat' => 14.34, 'lng' => 120.97, 'label' => 'Salawag']]);
check(($created['ok'] ?? false) === true, 'the passenger submits a booking (status available)', json_encode($created));
$bid = (int)($created['id'] ?? 0);
check(val('SELECT status FROM bookings WHERE id = ?', [$bid]) === 'available', 'it is saved as available straight away');
$seen = [];
foreach (['driver1' => $D1, 'driver2' => $D2, 'driver3' => $D3] as $name => $site) { $seen[$name] = count($site->api('driver_state.php')['available'] ?? []); }
check($seen === ['driver1' => 1, 'driver2' => 1, 'driver3' => 1], 'all three online drivers see it on their next refresh', json_encode($seen));

$multi = curl_multi_init(); $handles = [];
foreach (['d1' => $D1, 'd2' => $D2, 'd3' => $D3] as $k => $site) { $handles[$k] = $site->handle('driver_claim.php', 'POST', ['id' => $bid]); curl_multi_add_handle($multi, $handles[$k]); }
do { $status = curl_multi_exec($multi, $running); if ($running) { curl_multi_select($multi, 0.05); } } while ($running && $status === CURLM_OK);
$codes = []; $bodies = [];
foreach ($handles as $k => $h) { $codes[$k] = (int)curl_getinfo($h, CURLINFO_HTTP_CODE); $bodies[$k] = json_decode((string)curl_multi_getcontent($h), true) ?: []; curl_multi_remove_handle($multi, $h); }
$winners = array_keys(array_filter($codes, static fn($c) => $c === 200));
check(count($winners) === 1 && count(array_filter($codes, static fn($c) => $c === 409)) === 2, 'three simultaneous HTTP taps: one 200, two 409', json_encode($codes));
$loserBody = array_values(array_filter($bodies, static fn($b) => !($b['ok'] ?? false)))[0] ?? [];
check(($loserBody['code'] ?? '') === 'taken' && str_contains($loserBody['error'] ?? '', 'another driver just accepted'), 'the losers see the clean message', json_encode($loserBody));
$live = $OP->api('trips_overview.php');
check(count($live['live'] ?? []) === 1 && count($live['open'] ?? []) === 0, 'operators (read-only) see one live trip and no open bookings');
check($P1->api('booking_status.php?id=' . $bid)['booking']['status'] === 'assigned', 'the passenger sees that a driver accepted');

echo PHP_EOL . "== Consistency of the whole database ==" . PHP_EOL;
check((int)val("SELECT COUNT(*) FROM bookings WHERE status = 'available' AND (driver_id IS NOT NULL OR unit_id IS NOT NULL)") === 0, 'no available booking holds a driver or unit');
check((int)val("SELECT COUNT(*) FROM bookings WHERE status IN ('assigned','en_route','in_progress') AND (driver_id IS NULL OR unit_id IS NULL OR assigned_by IS NULL)") === 0, 'every live booking has its driver, unit and accepting driver');
check((int)val("SELECT COUNT(*) FROM bookings b JOIN drivers d ON d.id = b.driver_id WHERE b.status IN ('assigned','en_route','in_progress') AND b.assigned_by <> d.user_id") === 0, 'every live booking was accepted by its own driver');
check((int)val("SELECT COUNT(*) FROM (SELECT driver_id FROM bookings WHERE status IN ('assigned','en_route','in_progress') GROUP BY driver_id HAVING COUNT(*) > 1) x") === 0, 'no driver has two live bookings');
check((int)val("SELECT COUNT(*) FROM drivers dr WHERE dr.status = 'on_trip' AND NOT EXISTS (SELECT 1 FROM bookings x WHERE x.driver_id = dr.id AND x.status IN ('assigned','en_route','in_progress'))") === 0
      && (int)val("SELECT COUNT(*) FROM bookings x JOIN drivers dr ON dr.id = x.driver_id WHERE x.status IN ('assigned','en_route','in_progress') AND dr.status <> 'on_trip'") === 0, 'a driver is on_trip exactly when they have a live booking');
check((int)val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'bookings' AND column_name = 'status' AND column_type LIKE '%''available''%'") === 1, "the bookings status column has the 'available' value");
$m13 = require __DIR__ . '/../database/migrations/013_driver_claims.php';
try { $m13($pdo); $m13($pdo); $ok = true; } catch (Throwable $e) { $ok = false; $why = $e->getMessage(); }
check($ok, 'the claim migration can be re-run safely', $why ?? '');

/* ---- clean up ---- */
run('DELETE FROM driver_locations');
run('DELETE FROM bookings');
run("UPDATE drivers SET status = 'offline'");
foreach ($autoBefore as $a) { run('UPDATE rotation_settings SET auto_assign_enabled = ? WHERE route_id = ?', [$a['auto_assign_enabled'], $a['route_id']]); }
run('DELETE FROM login_attempts');

echo PHP_EOL . "Result: $pass passed, $fail failed." . PHP_EOL;
exit($fail === 0 ? 0 : 1);
