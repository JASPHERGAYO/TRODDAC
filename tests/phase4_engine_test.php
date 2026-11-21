<?php
/**
 * Phase 4 test: the automatic route assignment engine (Module B).
 *
 *   C:\xampp\php\php.exe tests\phase4_engine_test.php [base-url] [--replicate-missing-migration]
 *
 * Most checks run the engine directly with a simulated clock. The base URL (default http://localhost/troddac)
 * is used for the screen/API checks at the end. It needs the seed data, restores everything it changes, and
 * refuses to run while bookings are open. --replicate-missing-migration briefly drops one booking column to
 * prove the "database is out of date" message appears instead of "Something went wrong"; it puts the column back.
 */
declare(strict_types=1);

$args = array_values(array_filter($argv ?? [], static fn($a) => !str_starts_with($a, '--') || $a === '--replicate-missing-migration'));
$replicate = in_array('--replicate-missing-migration', $argv ?? [], true);
$base = rtrim((string)(array_values(array_filter(array_slice($argv ?? [], 1), static fn($a) => !str_starts_with($a, '--')))[0] ?? 'http://localhost/troddac'), '/');

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/api.php';
require __DIR__ . '/../lib/AssignmentEngine.php';
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
function tick(string $when, ?int $route = null): array { global $pdo; return (new AssignmentEngine($pdo, 'test'))->run($when, $route); }
function failsWith(callable $fn, string $needle): bool
{
    try { $fn(); return false; } catch (PDOException $e) { return str_contains($e->getMessage(), $needle); }
}

if ((int)val("SELECT COUNT(*) FROM bookings WHERE status IN ('pending','assigned','en_route','in_progress')") > 0) {
    fwrite(STDERR, "There are open bookings. Finish or delete them before running this test." . PHP_EOL);
    exit(2);
}

/* ---- snapshot everything the test changes, so it can be put back exactly ---- */
$tables = ['driver_schedules', 'route_schedules', 'rotation_settings', 'rotation_pointers', 'driver_route_eligibility'];
$snap = [];
foreach ($tables as $t) { $snap[$t] = rows("SELECT * FROM $t"); }
$snapRoutes  = rows('SELECT id, is_active, path_json FROM routes');
$snapDrivers = rows('SELECT id, status FROM drivers');
$snapMigrations = rows('SELECT version, applied_at FROM schema_migrations');

function wipeEngineData(): void
{
    global $pdo;
    $pdo->exec('DELETE FROM engine_decisions');
    $pdo->exec('DELETE FROM engine_runs');
    $pdo->exec('DELETE FROM route_assignment_history');
    $pdo->exec('DELETE FROM route_assignments');
}
function restore(): void
{
    global $pdo, $tables, $snap, $snapRoutes, $snapDrivers;
    wipeEngineData();
    foreach (array_reverse($tables) as $t) { $pdo->exec("DELETE FROM $t"); }
    foreach (['route_schedules', 'rotation_settings', 'rotation_pointers', 'driver_route_eligibility', 'driver_schedules'] as $t) {
        foreach ($snap[$t] as $row) {
            $cols = array_keys($row);
            $pdo->prepare("INSERT INTO $t (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
        }
    }
    foreach ($snapRoutes as $r) { run('UPDATE routes SET is_active = ?, path_json = ? WHERE id = ?', [$r['is_active'], $r['path_json'], $r['id']]); }
    foreach ($snapDrivers as $d) { run('UPDATE drivers SET status = ? WHERE id = ?', [$d['status'], $d['id']]); }
}

$d = array_column(rows('SELECT d.id FROM drivers d JOIN users u ON u.id = d.user_id ORDER BY u.username'), 'id');
[$d1, $d2, $d3, $d4] = array_map('intval', $d);
$R1 = (int)val('SELECT id FROM routes WHERE code = "R1"');
$R2 = (int)val('SELECT id FROM routes WHERE code = "R2"');
$op = (int)val('SELECT id FROM users WHERE username = "operator1"');
$p1 = (int)val('SELECT id FROM users WHERE username = "passenger1"');
$p2 = (int)val('SELECT id FROM users WHERE username = "passenger2"');
$unitOf = static fn(int $driver) => (int)val('SELECT default_unit_id FROM drivers WHERE id = ?', [$driver]);

$mon = date('Y-m-d', strtotime('next monday'));
$tue = date('Y-m-d', strtotime("$mon +1 day"));
$wed = date('Y-m-d', strtotime("$mon +2 day"));
run('UPDATE rotation_settings SET auto_assign_enabled = 0 WHERE route_id = ?', [$R2]);   // keep R2 out of the R1 scenarios

$slotDrivers = static fn(int $route) => array_map('intval', array_column(rows(
    "SELECT driver_id FROM route_assignments WHERE route_id = ? AND status IN ('scheduled','active','completed') ORDER BY start_datetime, id", [$route]), 'driver_id'));
$day = static function (string $date, array $times) {
    foreach ($times as $t) { tick("$date $t"); }
};

echo "== Rotation order ==" . PHP_EOL;
$r = tick("$mon 05:00:00");
check($r['ok'] && $r['assigned'] === 1 && $slotDrivers($R1) === [$d1], 'the first slot goes to queue position 1', json_encode($r));
$again = tick("$mon 05:00:00");
check($again['assigned'] === 0, 'running again at the same time does not assign twice');
$day($mon, ['06:05:00', '10:05:00', '15:00:00', '16:05:00', '20:05:00']);
$day($tue, ['05:00:00', '06:05:00', '10:05:00', '15:00:00', '16:05:00', '20:05:00']);
check($slotDrivers($R1) === [$d1, $d2, $d3, $d4], 'four slots go to drivers 1, 2, 3, 4 in queue order', json_encode($slotDrivers($R1)));
tick("$wed 05:00:00");
check($slotDrivers($R1) === [$d1, $d2, $d3, $d4, $d1], 'the queue wraps around and driver 1 is served again', json_encode($slotDrivers($R1)));
check((int)val('SELECT cycle_no FROM rotation_pointers WHERE route_id = ?', [$R1]) === 2, 'the rotation pointer moved to cycle 2');
check((int)val('SELECT last_assigned_driver_id FROM rotation_pointers WHERE route_id = ?', [$R1]) === $d1
      && (int)val('SELECT total_assignments FROM driver_route_eligibility WHERE route_id = ? AND driver_id = ?', [$R1, $d1]) === 2, 'the pointer and per-driver counters are kept up to date');
$states = rows("SELECT status, COUNT(*) c FROM route_assignments GROUP BY status");
$byStatus = array_column($states, 'c', 'status');
check(($byStatus['completed'] ?? 0) === 4 && ($byStatus['scheduled'] ?? 0) === 1, 'finished routes are marked completed, the next one stays scheduled', json_encode($byStatus));
tick("$wed 06:05:00");
check((int)val("SELECT COUNT(*) FROM route_assignments WHERE status = 'active'") === 1, 'a route becomes active when it starts');
check((int)val('SELECT COUNT(*) FROM route_assignment_history') >= 10, 'every assignment and status change is in the audit history');
check((int)val('SELECT COUNT(*) FROM engine_decisions WHERE decision = "assigned"') === 5 && (int)val('SELECT COUNT(*) FROM engine_runs') >= 15, 'each decision and run is logged');

echo PHP_EOL . "== Availability and schedule restrictions ==" . PHP_EOL;
restore(); run('UPDATE rotation_settings SET auto_assign_enabled = 0 WHERE route_id = ?', [$R2]);
run('DELETE FROM driver_schedules WHERE driver_id = ? AND day_of_week = 1', [$d2]);        // driver 2 does not work Mondays
$day($mon, ['05:00:00', '06:05:00', '10:05:00', '15:00:00']);
check($slotDrivers($R1) === [$d1, $d3], 'a driver outside their schedule is skipped (driver 2 -> driver 3)', json_encode($slotDrivers($R1)));
$skip = rows("SELECT reason FROM engine_decisions WHERE decision = 'skipped' AND driver_id = ?", [$d2]);
check($skip && str_contains($skip[0]['reason'], 'schedule'), 'the skip is logged with its reason', json_encode($skip));
check((int)val('SELECT skipped_count FROM driver_route_eligibility WHERE route_id = ? AND driver_id = ?', [$R1, $d2]) >= 1
      && val('SELECT last_served_cycle FROM driver_route_eligibility WHERE route_id = ? AND driver_id = ?', [$R1, $d2]) === null, 'the skipped driver has not used their turn');
$day($mon, ['16:05:00', '20:05:00']);
$day($tue, ['05:00:00']);
check($slotDrivers($R1) === [$d1, $d3, $d2], 'the skipped driver is first in line once available (Tuesday -> driver 2)', json_encode($slotDrivers($R1)));
$day($tue, ['06:05:00', '10:05:00', '15:00:00']);
check($slotDrivers($R1) === [$d1, $d3, $d2, $d4], 'and then the rest of the queue continues (driver 4)', json_encode($slotDrivers($R1)));

echo PHP_EOL . "== Offline drivers and imminent starts ==" . PHP_EOL;
restore(); run('UPDATE rotation_settings SET auto_assign_enabled = 0 WHERE route_id = ?', [$R2]);
run("UPDATE drivers SET status = 'offline'");
$r = tick("$mon 05:55:00");
check($r['assigned'] === 0 && $r['unfilled'] >= 1, 'when every driver is offline and the route starts in 5 minutes nobody is assigned', json_encode($r));
run("UPDATE drivers SET status = 'online' WHERE id = ?", [$d3]);
tick("$mon 05:56:00");
check($slotDrivers($R1) === [$d3], 'an online driver further down the queue takes the slot', json_encode($slotDrivers($R1)));

echo PHP_EOL . "== Slot size, daily limit and rest ==" . PHP_EOL;
restore(); run('UPDATE rotation_settings SET auto_assign_enabled = 0 WHERE route_id = ?', [$R2]);
run('UPDATE route_schedules SET drivers_required = 2 WHERE route_id = ? AND day_of_week = 1 AND start_time = "06:00:00"', [$R1]);
tick("$mon 05:00:00");
check($slotDrivers($R1) === [$d1, $d2], 'a slot that needs 2 drivers gets 2 different drivers', json_encode($slotDrivers($R1)));
check(count(array_unique($slotDrivers($R1))) === 2, 'a driver is never put on the same slot twice');

restore(); run('UPDATE rotation_settings SET auto_assign_enabled = 0 WHERE route_id = ?', [$R2]);
run('UPDATE driver_route_eligibility SET is_eligible = 0 WHERE route_id = ? AND driver_id <> ?', [$R1, $d1]);
run('UPDATE rotation_settings SET max_assignments_per_driver_day = 1 WHERE route_id = ?', [$R1]);
$day($mon, ['05:00:00', '06:05:00', '10:05:00', '15:00:00']);
$why = rows("SELECT reason FROM engine_decisions WHERE decision = 'unfilled' ORDER BY id DESC LIMIT 1");
check($slotDrivers($R1) === [$d1] && $why && str_contains($why[0]['reason'], 'daily'), 'the daily limit stops a driver taking a second route', json_encode($why));

restore(); run('UPDATE rotation_settings SET auto_assign_enabled = 0 WHERE route_id = ?', [$R2]);
run('UPDATE driver_route_eligibility SET is_eligible = 0 WHERE route_id = ? AND driver_id <> ?', [$R1, $d1]);
run('UPDATE rotation_settings SET max_assignments_per_driver_day = 3, min_rest_minutes = 400 WHERE route_id = ?', [$R1]);
$day($mon, ['05:00:00', '06:05:00', '10:05:00', '15:00:00']);
$why = rows("SELECT reason FROM engine_decisions WHERE decision = 'unfilled' ORDER BY id DESC LIMIT 1");
check($slotDrivers($R1) === [$d1] && $why && str_contains($why[0]['reason'], 'rest'), 'the minimum rest period between routes is enforced', json_encode($why));
run('UPDATE rotation_settings SET min_rest_minutes = 30 WHERE route_id = ?', [$R1]);
tick("$mon 15:01:00");
check($slotDrivers($R1) === [$d1, $d1], 'and the same driver is accepted once the rest rule is relaxed');

echo PHP_EOL . "== Configuration switches ==" . PHP_EOL;
restore(); run('UPDATE rotation_settings SET auto_assign_enabled = 0');
check(tick("$mon 05:00:00")['assigned'] === 0, 'routes with automatic assignment switched off are left alone');
restore(); run('UPDATE rotation_settings SET auto_assign_enabled = 0 WHERE route_id = ?', [$R2]); run('UPDATE routes SET is_active = 0 WHERE id = ?', [$R1]);
check(tick("$mon 05:00:00")['assigned'] === 0, 'inactive routes are never assigned');
restore(); run('UPDATE rotation_settings SET auto_assign_enabled = 0 WHERE route_id = ?', [$R2]); run('UPDATE driver_route_eligibility SET is_eligible = 0 WHERE route_id = ?', [$R1]);
$r = tick("$mon 05:00:00");
check($r['assigned'] === 0 && $r['unfilled'] >= 1, 'a route with no eligible drivers is reported as unfilled');

echo PHP_EOL . "== Passenger trip versus route start ==" . PHP_EOL;
restore(); run('UPDATE rotation_settings SET auto_assign_enabled = 0 WHERE route_id = ?', [$R2]);
tick("$mon 05:00:00");                                         // driver 1 is scheduled for Monday 06:00
check($slotDrivers($R1) === [$d1], 'driver 1 is scheduled for Monday 06:00');
run("UPDATE drivers SET status = 'online' WHERE id IN (?, ?)", [$d1, $d2]);
run("INSERT INTO bookings (passenger_id, pickup_label, pickup_latitude, pickup_longitude, dest_label, dest_latitude, dest_longitude, distance_km) VALUES (?, 'A', 14.33, 120.94, 'B', 14.34, 120.97, 3.5)", [$p1]);
$bk = (int)$pdo->lastInsertId();
run("UPDATE bookings SET status = 'assigned', driver_id = ?, unit_id = ?, assigned_by = ? WHERE id = ?", [$d1, $unitOf($d1), $op, $bk]);
run("UPDATE bookings SET status = 'in_progress' WHERE id = ?", [$bk]);
$r = tick("$mon 06:05:00");
$rowsAt = rows("SELECT driver_id, status, notes FROM route_assignments ORDER BY id");
check($rowsAt[0]['status'] === 'missed' && str_contains((string)$rowsAt[0]['notes'], 'passenger trip'), 'a driver still on a passenger trip is NOT started on the route (marked missed)', json_encode($rowsAt));
check(count($rowsAt) === 2 && (int)$rowsAt[1]['driver_id'] === $d2 && $rowsAt[1]['status'] === 'active', 'the next driver in the rotation covers the slot and starts at once', json_encode($rowsAt));
run("UPDATE bookings SET status = 'completed' WHERE id = ?", [$bk]);
check(val('SELECT status FROM drivers WHERE id = ?', [$d1]) === 'online', 'the passenger trip finishes normally and frees the driver');

echo PHP_EOL . "== Real clock: conflicts both ways ==" . PHP_EOL;
restore(); run('UPDATE rotation_settings SET auto_assign_enabled = 0 WHERE route_id = ?', [$R1]);
$nowDb = new DateTimeImmutable((string)val('SELECT NOW()'));
$start = $nowDb->modify('+6 minutes')->setTime((int)$nowDb->modify('+6 minutes')->format('H'), (int)$nowDb->modify('+6 minutes')->format('i'), 0);
$end   = $start->modify('+60 minutes');
$httpReady = false;
if ($start->format('Y-m-d') !== $end->format('Y-m-d')) {
    echo '[SKIP] the live-clock scenario needs a time before 22:50; run the test earlier in the day' . PHP_EOL;
} else {
    run('UPDATE route_schedules SET is_active = 0 WHERE route_id = ?', [$R2]);
    run('DELETE FROM driver_schedules');                         // no schedule restriction for this scenario
    run('INSERT INTO route_schedules (route_id, day_of_week, start_time, end_time, drivers_required) VALUES (?,?,?,?,1)',
        [$R2, (int)$start->format('N'), $start->format('H:i:s'), $end->format('H:i:s')]);
    run('UPDATE rotation_settings SET auto_assign_enabled = 1, assignment_lead_minutes = 60 WHERE route_id = ?', [$R2]);
    run("UPDATE drivers SET status = 'online' WHERE id IN (?, ?, ?)", [$d1, $d2, $d3]);
    run("INSERT INTO bookings (passenger_id, pickup_label, pickup_latitude, pickup_longitude, dest_label, dest_latitude, dest_longitude, distance_km) VALUES (?, 'A', 14.33, 120.94, 'B', 14.34, 120.97, 3.5)", [$p1]);
    $trip = (int)$pdo->lastInsertId();
    run("UPDATE bookings SET status = 'assigned', driver_id = ?, unit_id = ?, assigned_by = ? WHERE id = ?", [$d1, $unitOf($d1), $op, $trip]);

    $r = tick((string)val('SELECT NOW()'), $R2);
    $mine = rows('SELECT driver_id FROM route_assignments WHERE route_id = ?', [$R2]);
    check(count($mine) === 1 && (int)$mine[0]['driver_id'] === $d2, 'a driver on a live passenger trip is skipped for the route (driver 1 -> driver 2)', json_encode([$r, $mine]));
    $why = rows("SELECT reason FROM engine_decisions WHERE decision = 'skipped' AND driver_id = ?", [$d1]);
    check($why && str_contains($why[0]['reason'], 'passenger trip'), 'and the reason is logged', json_encode($why));
    check(failsWith(fn() => run('INSERT INTO route_assignments (route_id, driver_id, unit_id, start_datetime, end_datetime) VALUES (?,?,?,?,?)',
        [$R2, $d1, $unitOf($d1), $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]), 'TRD-E407'), 'the database also refuses a route for a driver in a passenger trip (TRD-E407)');

    run("INSERT INTO bookings (passenger_id, pickup_label, pickup_latitude, pickup_longitude, dest_label, dest_latitude, dest_longitude, distance_km) VALUES (?, 'A', 14.33, 120.94, 'B', 14.34, 120.97, 3.5)", [$p2]);
    $trip2 = (int)$pdo->lastInsertId();
    check(failsWith(fn() => run("UPDATE bookings SET status = 'assigned', driver_id = ?, unit_id = ?, assigned_by = ? WHERE id = ?", [$d2, $unitOf($d2), $op, $trip2]), 'route assignment'),
        'a passenger trip cannot be given to a driver whose route is about to start');
    $httpReady = true;
}

echo PHP_EOL . "== Concurrency ==" . PHP_EOL;
$cfg = appConfig()['db'];
$other = new PDO("mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset={$cfg['charset']}", $cfg['user'], $cfg['pass']);
$other->query("SELECT GET_LOCK('troddac_assignment_engine', 0)")->fetchColumn();
$r = tick("$mon 05:00:00");
check(!empty($r['locked']), 'a second engine run is refused while another is running');
$other->query("SELECT RELEASE_LOCK('troddac_assignment_engine')")->fetchColumn();

echo PHP_EOL . "== Screens and API ==" . PHP_EOL;
final class Web
{
    private string $jar; private ?string $token = null;
    public function __construct(private string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'trd'); }
    public function raw(string $m, string $path, ?string $body = null, array $h = []): array
    {
        $ch = curl_init($this->base . '/' . ltrim($path, '/'));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 40, CURLOPT_CUSTOMREQUEST => $m, CURLOPT_HTTPHEADER => $h]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $raw = curl_exec($ch);
        if ($raw === false) { fwrite(STDERR, "Cannot reach {$this->base}: " . curl_error($ch) . PHP_EOL); exit(2); }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
        return ['status' => $status, 'body' => substr($raw, $size)];
    }
    public function login(string $u, string $p): self
    {
        $r = $this->raw('GET', 'login.php'); preg_match('/name="csrf_token" value="([^"]+)"/', $r['body'], $m);
        $this->raw('POST', 'login.php', http_build_query(['csrf_token' => $m[1] ?? '', 'identifier' => $u, 'password' => $p]));
        $a = $this->raw('GET', 'account.php'); preg_match('/name="csrf-token" content="([^"]+)"/', $a['body'], $t); $this->token = $t[1] ?? null;
        return $this;
    }
    public function api(string $path, string $m = 'GET', ?array $body = null): array
    {
        $h = ['Accept: application/json']; $payload = null;
        if ($m !== 'GET') { $h[] = 'Content-Type: application/json'; $h[] = 'X-CSRF-Token: ' . $this->token; $payload = json_encode($body ?? []); }
        $r = $this->raw($m, 'api/' . ltrim($path, '/'), $payload, $h); $j = json_decode($r['body'], true);
        return (is_array($j) ? $j : ['ok' => false, 'error' => 'not json']) + ['http' => $r['status']];
    }
}
if ($httpReady) {
    $web = static fn(string $u, string $p) => (new Web($base))->login($u, $p);
    $DR = $web('driver2', 'driver123'); $OP = $web('operator1', 'operator123'); $OF = $web('officer1', 'officer123'); $PA = $web('passenger1', 'passenger123');
    $ds = $DR->api('driver_state.php');
    $off = $ds['official'][0] ?? null;
    check($off && $off['route']['code'] === 'R2' && $off['origin']['name'] === 'Poblacion Terminal' && $off['dest']['name'] === 'Paliparan Terminal', 'THE DRIVER SEES THE ASSIGNED ROUTE AND TERMINALS ON THEIR DASHBOARD', json_encode($ds));
    check($off && count($off['geometry']) >= 2, 'the driver gets the route line for the map');
    check($DR->raw('GET', 'driver_dashboard.php')['status'] === 200 && str_contains($DR->raw('GET', 'driver_dashboard.php')['body'], 'Official route assignment'), 'the driver dashboard shows the official route panel');
    $ov = $OP->api('assignments_overview.php');
    check(($ov['ok'] ?? false) && count($ov['assignments']) >= 1 && $ov['assignments'][0]['code'] === 'R2', 'the operator can monitor all assignments (read-only)');
    check(in_array('skipped', array_column($ov['decisions'] ?? [], 'decision'), true), 'the operator sees why drivers were skipped');
    check($PA->api('assignments_overview.php')['http'] === 403 && $DR->api('assignments_overview.php')['http'] === 403, 'passengers and drivers cannot open the monitoring data (403)');
    check($OP->api('assignment_tick.php', 'POST')['http'] === 403, 'operators cannot force an engine run (403)');
    $webRunsBefore = (int)val("SELECT COUNT(*) FROM engine_runs WHERE run_source = 'web'");
    $manual = $OF->api('assignment_tick.php', 'POST');
    check(($manual['ok'] ?? false) && isset($manual['assigned'], $manual['run_id']), 'the officer can run the engine now', json_encode($manual));
    $DR->api('driver_state.php'); $DR->api('driver_state.php');
    check((int)val("SELECT COUNT(*) FROM engine_runs WHERE run_source = 'web'") === $webRunsBefore, 'screens do not re-run the engine more than about once a minute');
    check($OP->raw('GET', 'operator_assignments.php')['status'] === 200 && $PA->raw('GET', 'operator_assignments.php')['status'] === 403, 'the assignments screen is role-locked');
}

echo PHP_EOL . "== Out-of-date database (the booking error from the screenshot) ==" . PHP_EOL;
$e = new PDOException('SQLSTATE[42S22]: Column not found: 1054 Unknown column');
$ref = new ReflectionProperty(PDOException::class, 'code'); $ref->setAccessible(true); $ref->setValue($e, '42S22');
check(dbErrorMessage($e) === SCHEMA_HELP && str_contains(SCHEMA_HELP, 'migrate'), 'a missing-column error is explained as "run the migration", not "Something went wrong"');
check(pendingMigrations() === [], 'no migrations are pending on this database');
run('DELETE FROM schema_migrations WHERE version = ?', ['010_assignment_engine.sql']);
check(pendingMigrations() === ['010_assignment_engine.sql'], 'a migration that was not applied is detected');
if ($httpReady) {
    $o = $web('officer1', 'officer123'); $pa = $web('passenger1', 'passenger123');
    check(str_contains($o->raw('GET', 'officer_dashboard.php')['body'], 'Database update needed (1 pending)'), 'staff see a "Database update needed" banner with the command');
    check(str_contains($pa->raw('GET', 'passenger_dashboard.php')['body'], 'being updated') && !str_contains($pa->raw('GET', 'passenger_dashboard.php')['body'], 'migrate.php'), 'passengers see a gentle notice without technical details');
}
$pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)')->execute(['010_assignment_engine.sql', $snapMigrations[count($snapMigrations) - 1]['applied_at']]);
check(pendingMigrations() === [], 'the migration record is restored');

if ($replicate && $httpReady) {
    $pdo->exec('DELETE FROM driver_locations');
    $pdo->exec('DELETE FROM bookings');         // leftovers from the scenarios above would block a new booking
    $PB = $web('passenger2', 'passenger123');
    $pdo->exec('ALTER TABLE bookings DROP CONSTRAINT chk_bookings_pax');
    $pdo->exec('ALTER TABLE bookings DROP COLUMN passenger_count');
    try {
        $res = $PB->api('booking_create.php', 'POST', ['pickup' => ['lat' => 14.3294, 'lng' => 120.9367], 'dest' => ['lat' => 14.34, 'lng' => 120.97], 'passengers' => 1]);
        check(($res['ok'] ?? true) === false && str_contains($res['error'] ?? '', 'out of date'), 'booking on an old database now says the database is out of date', json_encode($res));
    } finally {
        $pdo->exec('ALTER TABLE bookings ADD COLUMN passenger_count TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER dest_longitude');
        $pdo->exec('ALTER TABLE bookings ADD CONSTRAINT chk_bookings_pax CHECK (passenger_count BETWEEN 1 AND 6)');
    }
    check((int)val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'bookings' AND column_name = 'passenger_count'") === 1, 'the booking column was put back');
}

/* ---- put everything back ---- */
$pdo->exec('DELETE FROM driver_locations');
$pdo->exec('DELETE FROM bookings');
restore();
$pdo->exec("UPDATE drivers SET status = 'offline'");
$pdo->exec("DELETE FROM login_attempts");

echo PHP_EOL . "Result: $pass passed, $fail failed." . PHP_EOL;
exit($fail === 0 ? 0 : 1);
