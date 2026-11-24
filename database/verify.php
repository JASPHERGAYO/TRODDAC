<?php
/**
 * Phase 1 self-test. Runs inside ONE transaction that is rolled back at the end,
 * so your data is never changed. Requires the sample seed data.
 *
 *   CLI:      C:\xampp\php\php.exe database\verify.php
 *   Browser:  http://localhost/TRODDAC/database/verify.php   (localhost only)
 */
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';
$isCli  = PHP_SAPI === 'cli';
if (!$isCli) {
    if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', $config['tools_allowed_ips'], true)) {
        http_response_code(403);
        exit('Forbidden: this tool only runs from localhost.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$db  = $config['db'];
$pdo = new PDO("mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}", $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pass = 0; $fail = 0;
function report(bool $ok, string $label, string $detail = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . ($ok || $detail === '' ? '' : "  -> $detail") . PHP_EOL;
}
function expectOk(string $label, callable $fn): mixed {
    try { $r = $fn(); report(true, $label); return $r; }
    catch (Throwable $e) { report(false, $label, $e->getMessage()); return null; }
}
function expectError(string $label, string $needle, callable $fn): void {
    try { $fn(); report(false, $label, "expected '$needle' but the statement succeeded"); }
    catch (Throwable $e) {
        str_contains($e->getMessage(), $needle)
            ? report(true, $label)
            : report(false, $label, "expected '$needle' but got: " . $e->getMessage());
    }
}
function val(PDO $pdo, string $sql, array $p = []): mixed {
    $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn();
}
function exec_(PDO $pdo, string $sql, array $p = []): void { $pdo->prepare($sql)->execute($p); }

if ((int)val($pdo, 'SELECT COUNT(*) FROM users') === 0) {
    exit("No sample data. Run: php database/migrate.php seed" . PHP_EOL);
}

$uid = fn(string $name) => (int)val($pdo, 'SELECT id FROM users WHERE username = ?', [$name]);
$drv = fn(string $name) => (int)val($pdo, 'SELECT d.id FROM drivers d JOIN users u ON u.id = d.user_id WHERE u.username = ?', [$name]);
$unitOf = fn(int $driverId) => (int)val($pdo, 'SELECT default_unit_id FROM drivers WHERE id = ?', [$driverId]);
$route = fn(string $code) => (int)val($pdo, 'SELECT id FROM routes WHERE code = ?', [$code]);

$operator = $uid('operator1'); $p1 = $uid('passenger1'); $p2 = $uid('passenger2');
$d1 = $drv('driver1'); $d2 = $drv('driver2'); $d3 = $drv('driver3'); $d4 = $drv('driver4');
$u1 = $unitOf($d1); $u2 = $unitOf($d2); $u3 = $unitOf($d3); $u4 = $unitOf($d4);
$R1 = $route('R1'); $R2 = $route('R2');
$today    = (string)val($pdo, 'SELECT CURDATE()');
$tomorrow = (string)val($pdo, 'SELECT DATE_ADD(CURDATE(), INTERVAL 1 DAY)');
$matrix   = (int)val($pdo, 'SELECT id FROM fare_matrices WHERE is_active = 1');

$newBooking = function (int $passenger, float $km = 4.5) use ($pdo): int {
    exec_($pdo, "INSERT INTO bookings (passenger_id, pickup_label, pickup_latitude, pickup_longitude, dest_label, dest_latitude, dest_longitude, distance_km)
                 VALUES (?, 'Home', 14.3294, 120.9367, 'Salawag', 14.34, 120.97, ?)", [$passenger, $km]);
    return (int)$pdo->lastInsertId();
};
$userOfDriver = fn(int $driver) => (int)val($pdo, 'SELECT user_id FROM drivers WHERE id = ?', [$driver]);
// A driver accepts a booking for themselves, so the person making the change is the driver's own user.
$assign = fn(int $booking, int $driver, int $unit) => exec_($pdo,
    "UPDATE bookings SET status='assigned', driver_id=?, unit_id=?, assigned_by=? WHERE id=?", [$driver, $unit, $GLOBALS['userOfDriver']($driver), $booking]);
$setStatus = fn(int $booking, string $status) => exec_($pdo, 'UPDATE bookings SET status=? WHERE id=?', [$status, $booking]);
$driverStatus = fn(int $d) => (string)val($pdo, 'SELECT status FROM drivers WHERE id=?', [$d]);
$newAssignment = fn(int $route, int $driver, ?int $unit, string $from, string $to) => exec_($pdo,
    "INSERT INTO route_assignments (route_id, driver_id, unit_id, start_datetime, end_datetime) VALUES (?,?,?,?,?)", [$route, $driver, $unit, $from, $to]);

$pdo->beginTransaction();
exec_($pdo, 'SET @app_user_id = ?', [$uid('driver1')]);

echo "== Fare matrix ==" . PHP_EOL;
report((float)val($pdo, 'SELECT fn_calculate_fare(4.5, ?)', [$matrix]) === 60.00, 'fare for 4.5 km = 60.00 (25 base + 3.5 km x 10)');
report((float)val($pdo, 'SELECT fn_calculate_fare(0.5, ?)', [$matrix]) === 25.00, 'fare for 0.5 km = minimum 25.00');
report((float)val($pdo, 'SELECT fn_calculate_fare(2.25, ?)', [$matrix]) === 37.50, 'fare for 2.25 km = 37.50');
expectError('a second active fare matrix is rejected', 'Duplicate entry', fn() => exec_($pdo,
    "INSERT INTO fare_matrices (name, base_fare, per_km_rate, minimum_fare, effective_from, is_active) VALUES ('Other', 30, 12, 30, CURDATE(), 1)"));

echo PHP_EOL . "== Module A: bookings ==" . PHP_EOL;
expectError('an operator cannot create a booking', 'TRD-E201', fn() => $newBooking($operator));
$b1 = expectOk('a passenger can create a booking', fn() => $newBooking($p1));
$row = $pdo->query("SELECT * FROM bookings WHERE id = $b1")->fetch();
report($row && $row['status'] === 'available' && (float)$row['estimated_fare'] === 60.00 && str_starts_with($row['booking_code'], 'TRD'),
    'booking gets a code, status available and server-side fare 60.00');
expectError('a booking cannot be inserted already assigned', 'TRD-E202', fn() => exec_($pdo,
    "INSERT INTO bookings (passenger_id, pickup_label, pickup_latitude, pickup_longitude, dest_label, dest_latitude, dest_longitude, distance_km, status, driver_id, unit_id)
     VALUES (?, 'A', 14.3, 120.9, 'B', 14.3, 120.9, 2, 'assigned', ?, ?)", [$p1, $d1, $u1]));
expectError('an offline driver cannot be assigned', 'TRD-E102', fn() => $assign($b1, $d1, $u1));
exec_($pdo, "UPDATE drivers SET status='online' WHERE id IN (?,?,?,?)", [$d1, $d2, $d3, $d4]);
expectError('an operator cannot assign a booking (only the driver can accept it)', 'TRD-E205', fn() => exec_($pdo,
    "UPDATE bookings SET status='assigned', driver_id=?, unit_id=?, assigned_by=? WHERE id=?", [$d1, $u1, $operator, $b1]));
expectError('a driver cannot accept a booking on behalf of another driver', 'TRD-E205', fn() => exec_($pdo,
    "UPDATE bookings SET status='assigned', driver_id=?, unit_id=?, assigned_by=? WHERE id=?", [$d1, $u1, $userOfDriver($d2), $b1]));
expectOk('an online driver can accept an available booking', fn() => $assign($b1, $d1, $u1));
report($driverStatus($d1) === 'on_trip', 'driver becomes on_trip automatically');
report((int)val($pdo, 'SELECT COUNT(*) FROM booking_status_history WHERE booking_id = ? AND changed_by = ?', [$b1, $uid('driver1')]) >= 2,
    'booking history records the driver who acted');

$b2 = $newBooking($p2);
expectError('a driver on a trip cannot take a second trip', 'TRD-E102', fn() => $assign($b2, $d1, $u2));
expectError('a unit in a live trip cannot be reused', 'TRD-E107', fn() => $assign($b2, $d2, $u1));
expectError('a driver on a trip cannot go offline', 'TRD-E302', fn() => exec_($pdo, "UPDATE drivers SET status='offline' WHERE id = ?", [$d1]));
expectError('on_trip cannot be set by hand', 'TRD-E303', fn() => exec_($pdo, "UPDATE drivers SET status='on_trip' WHERE id = ?", [$d3]));

echo PHP_EOL . "== Conflict prevention: Module A <-> Module B ==" . PHP_EOL;
exec_($pdo, 'DELETE FROM driver_schedules WHERE driver_id IN (?,?)', [$d1, $d2]);
expectError('route assignment is blocked while the driver is on a passenger trip', 'TRD-E407',
    fn() => $newAssignment($R1, $d1, null, "$today 00:01:00", "$today 23:58:00"));
$a1 = expectOk('route assignment is allowed for a free driver', function () use ($pdo, $newAssignment, $R1, $d2, $u2, $today) {
    $newAssignment($R1, $d2, $u2, "$today 00:01:00", "$today 23:58:00");
    return (int)$pdo->lastInsertId();
});
expectError('a passenger trip is blocked for a driver with a conflicting route assignment', 'TRD-E104', fn() => $assign($b2, $d2, $u3));
expectError('overlapping route assignments for one driver are blocked', 'TRD-E405', fn() => $newAssignment($R2, $d2, null, "$today 10:00:00", "$today 11:00:00"));
expectError('a driver who is not eligible for the route is blocked', 'TRD-E403', fn() => $newAssignment($R2, $d4, null, "$today 08:00:00", "$today 09:00:00"));
expectError('an assignment outside the driver schedule is blocked', 'TRD-E404', fn() => $newAssignment($R2, $d3, null, "$today 22:00:00", "$today 23:00:00"));
exec_($pdo, 'DELETE FROM driver_schedules WHERE driver_id = ?', [$d3]);
expectError('a unit already on an overlapping assignment is blocked', 'TRD-E406', fn() => $newAssignment($R2, $d3, $u2, "$today 08:00:00", "$today 09:00:00"));
expectOk('a non-overlapping assignment with a free unit is allowed', fn() => $newAssignment($R2, $d3, $u3, "$today 08:00:00", "$today 09:00:00"));
exec_($pdo, 'DELETE FROM driver_schedules WHERE driver_id = ?', [$d4]);   // so the weekday cannot change which rule fires first
expectError('an assignment cannot cross midnight', 'chk_assign_sameday', fn() => $newAssignment($R1, $d4, null, "$today 23:00:00", "$tomorrow 01:00:00"));
report((int)val($pdo, 'SELECT COUNT(*) FROM route_assignment_history WHERE assignment_id = ?', [$a1]) === 1, 'assignment history logs the creation');
exec_($pdo, "UPDATE route_assignments SET status='cancelled' WHERE id = ?", [$a1]);
report((int)val($pdo, 'SELECT COUNT(*) FROM route_assignment_history WHERE assignment_id = ?', [$a1]) === 2, 'assignment history logs the status change');
expectError('a finished assignment cannot be edited', 'TRD-E409', fn() => exec_($pdo, "UPDATE route_assignments SET notes='x' WHERE id = ?", [$a1]));
expectOk('after cancelling, the driver can take the passenger trip', fn() => $assign($b2, $d2, $u2));
$setStatus($b2, 'cancelled');
report($driverStatus($d2) === 'online', 'cancelling a trip frees the driver');

echo PHP_EOL . "== Trip lifecycle ==" . PHP_EOL;
expectError('assigned cannot jump straight to completed', 'TRD-E203', fn() => $setStatus($b1, 'completed'));
expectOk('assigned -> in_progress', fn() => $setStatus($b1, 'in_progress'));
expectOk('in_progress -> completed', fn() => $setStatus($b1, 'completed'));
$done = $pdo->query("SELECT final_fare, completed_at FROM bookings WHERE id = $b1")->fetch();
report((float)$done['final_fare'] === 60.00 && $done['completed_at'] !== null, 'completion sets final fare and time');
report($driverStatus($d1) === 'online', 'completing a trip returns the driver to online');
expectError('a completed booking cannot be reopened', 'TRD-E203', fn() => $setStatus($b1, 'available'));

$b3 = $newBooking($p1);
$assign($b3, $d4, $u4);
expectError('nobody can swap the driver after a driver has accepted', 'TRD-E206', fn() => exec_($pdo, 'UPDATE bookings SET driver_id=?, unit_id=? WHERE id=?', [$d1, $u1, $b3]));
$setStatus($b3, 'available');
report($driverStatus($d4) === 'online' && val($pdo, 'SELECT driver_id FROM bookings WHERE id = ?', [$b3]) === null, 'handing a booking back makes it available again and frees the driver');
expectOk('another driver can then accept the same booking', fn() => $assign($b3, $d1, $u1));
$b4 = $newBooking($p2);
expectError('an available booking cannot hold a driver', 'TRD-E202', fn() => exec_($pdo, "UPDATE bookings SET driver_id=? WHERE id=?", [$d2, $b4]));

echo PHP_EOL . "== Other rules ==" . PHP_EOL;
exec_($pdo, 'INSERT INTO driver_locations (driver_id, booking_id, latitude, longitude, recorded_at) VALUES (?, NULL, 14.3311, 120.9402, NOW())', [$d1]);
report(abs((float)val($pdo, 'SELECT last_latitude FROM drivers WHERE id = ?', [$d1]) - 14.3311) < 0.0001, 'a GPS ping updates the driver\'s last known position');
expectError('queue positions are unique per route', 'Duplicate entry', fn() => exec_($pdo, 'INSERT INTO driver_route_eligibility (driver_id, route_id, queue_position) VALUES (?,?,1)', [$d4, $R2]));
expectError('a driver\'s user cannot lose the driver role', 'TRD-E304', fn() => exec_($pdo, "UPDATE users SET role='passenger' WHERE id = ?", [$uid('driver1')]));
expectError('a driver profile needs a driver user', 'TRD-E301', fn() => exec_($pdo, "INSERT INTO drivers (user_id, license_number) VALUES (?, 'X-1')", [$p2]));
report((int)val($pdo, 'SELECT COUNT(*) FROM v_driver_board') === 4, 'v_driver_board lists all drivers');
report((int)val($pdo, 'SELECT COUNT(*) FROM v_assignment_overview') >= 1, 'v_assignment_overview lists assignments');

$pdo->rollBack();
echo PHP_EOL . "Result: $pass passed, $fail failed. (All test data was rolled back.)" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
