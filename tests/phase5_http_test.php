<?php
/**
 * Phase 5 test: names + duplicate-safe sign-up, officer user creation, passenger reports,
 * per-km fare rates, mobile layout markup and graceful handling of an out-of-date database.
 *
 *   C:\xampp\php\php.exe tests\phase5_http_test.php [base-url]
 *
 * Creates accounts/reports/rates named tmp_* / TMP-* and removes them. Needs the seed data and no open bookings.
 */
declare(strict_types=1);

$base = rtrim($argv[1] ?? 'http://localhost/troddac', '/');
require __DIR__ . '/../config/db.php';
$pdo = db();

final class Site
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
        preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $size), $loc);
        return ['status' => $status, 'body' => substr($raw, $size), 'location' => $loc[1] ?? '', 'text' => html_entity_decode(substr($raw, $size), ENT_QUOTES)];
    }
    public function get(string $path): array { return $this->raw('GET', $path); }
    public function token(string $from): string
    {
        $r = $this->get($from);
        if (preg_match('/name="csrf_token" value="([^"]+)"/', $r['body'], $m)) { return $m[1]; }
        return preg_match('/name="csrf-token" content="([^"]+)"/', $r['body'], $m) ? $m[1] : '';
    }
    public function form(string $path, array $data, ?string $tokenFrom = null, bool $withToken = true): array
    {
        if ($withToken) { $data['csrf_token'] = $this->token($tokenFrom ?? $path); }
        return $this->raw('POST', $path, http_build_query($data));
    }
    public function login(string $u, string $p): self
    {
        $this->raw('POST', 'login.php', http_build_query(['csrf_token' => $this->token('login.php'), 'identifier' => $u, 'password' => $p]));
        $a = $this->get('account.php'); preg_match('/name="csrf-token" content="([^"]+)"/', $a['body'], $t); $this->token = $t[1] ?? null;
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
$has = static fn(string $hay, string $needle) => str_contains($hay, $needle);

if ((int)val("SELECT COUNT(*) FROM bookings WHERE status IN ('pending','assigned','en_route','in_progress')") > 0) {
    fwrite(STDERR, "There are open bookings. Finish or delete them before running this test." . PHP_EOL);
    exit(2);
}
$started = (string)val('SELECT NOW()');
run('DELETE FROM login_attempts');
$activeFareBefore = (int)val('SELECT id FROM fare_matrices WHERE is_active = 1');

echo "== Database safety ==" . PHP_EOL;
$out = shell_exec('"' . PHP_BINARY . '" ' . escapeshellarg(__DIR__ . '/../database/migrate.php') . ' migrate 2>&1');
check(str_contains((string)$out, 'Nothing to migrate'), 'running the migrations again changes nothing');
$migration011 = require __DIR__ . '/../database/migrations/011_names_and_units.php';
try { $migration011($pdo); $migration011($pdo); $ok = true; } catch (Throwable $e) { $ok = false; $why = $e->getMessage(); }
check($ok, 'the column-checking migration can be run again safely (idempotent)', $why ?? '');
check(schemaHas('users', 'first_name') && schemaHas('passenger_reports') && !schemaHas('users', 'no_such_column') && !schemaHas('no_such_table'), 'schemaHas() reports what exists');
check(schemaMissing(['users' => ['first_name', 'ghost'], 'ghost_table' => []]) === ['users.ghost', 'ghost_table'], 'schemaMissing() lists exactly what is missing');
check(val('SELECT first_name FROM users WHERE username = "passenger1"') === 'Passenger' && val('SELECT last_name FROM users WHERE username = "passenger1"') === 'One', 'existing accounts got their first/last name filled in');
check(val("SELECT is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tricycle_units' AND column_name = 'plate_number'") === 'YES', 'a tricycle unit can exist with only a unit number');
check((int)val("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name IN ('uq_users_phone','uq_drivers_default_unit')") >= 2, 'unique indexes back up the duplicate checks');

echo PHP_EOL . "== Public passenger sign-up ==" . PHP_EOL;
$anon = new Site($base);
$page = $anon->get('register.php');
preg_match_all('/<input\b[^>]*>/i', $page['body'], $inputs);
$visible = array_filter($inputs[0], static fn($i) => !preg_match('/type="(hidden|submit)"/', $i));
$withHolder = array_filter($visible, static fn($i) => str_contains($i, 'placeholder="'));
check($page['status'] === 200 && count($visible) === 8 && count($withHolder) === 8, 'the sign-up page has First name, M.I., Last name and every input has a placeholder', count($withHolder) . ' of ' . count($visible));
check($has($page['body'], 'name="first_name"') && $has($page['body'], 'name="last_name"') && $has($page['body'], 'name="middle_initial"'), 'the sign-up form asks for first name, last name and middle initial');
check($anon->get('login.php')['status'] === 200 && $has($anon->get('login.php')['body'], 'register.php'), 'the login page links to passenger sign-up');

$u = 'tmp_' . bin2hex(random_bytes(3));
$mk = static fn(array $o = []) => $o + ['first_name' => 'Tmp', 'last_name' => 'Rider', 'middle_initial' => 'a', 'username' => $u, 'email' => "$u@example.test",
                                       'phone' => '0917 555 0101', 'password' => 'Secret123', 'confirm' => 'Secret123', 'role' => 'officer'];
$r = $anon->form('register.php', $mk());
check($r['status'] === 302 && $has($r['location'], 'login.php'), 'a passenger can sign up');
$row = rows('SELECT * FROM users WHERE username = ?', [$u])[0] ?? [];
check(($row['role'] ?? '') === 'passenger' && $row['full_name'] === 'Tmp A. Rider' && $row['middle_initial'] === 'A' && $row['phone'] === '09175550101', 'role is forced to passenger, name parts are stored, middle initial upper-cased, phone normalised', json_encode($row));
$dup = static fn(array $o, string $needle, string $label) => (function () use ($anon, $mk, $o, $needle, $label, $has) {
    $before = (int)val('SELECT COUNT(*) FROM users');
    $r = $anon->form('register.php', $mk($o));
    check($r['status'] === 200 && $has($r['text'], $needle) && (int)val('SELECT COUNT(*) FROM users') === $before, $label, substr(strip_tags($r['text']), 0, 160));
})();
$dup(['email' => "other_$u@example.test", 'first_name' => 'Zed', 'phone' => ''], 'username is already taken', 'a duplicate username is refused with a friendly message');
$dup(['username' => $u . 'b', 'first_name' => 'Zed', 'phone' => ''], 'email address already exists', 'a duplicate email is refused');
$dup(['username' => $u . 'c', 'email' => "c$u@example.test", 'first_name' => 'Zed', 'phone' => '+63 917 555 0101'], 'phone number is already used', 'the same phone written another way (+63...) is refused');
$dup(['username' => $u . 'd', 'email' => "d$u@example.test", 'phone' => ''], 'already exists', 'the same person (same name and initial) is refused');
check(!$has($anon->form('register.php', $mk(['username' => $u . 'd', 'email' => "d$u@example.test", 'phone' => '']))['text'], "username: $u"), 'the public message does not reveal the other account\'s username');
$r = $anon->form('register.php', $mk(['username' => $u . 'e', 'email' => "e$u@example.test", 'phone' => '', 'middle_initial' => 'B']));
check($r['status'] === 302, 'the same name with a different middle initial is accepted');
$dup(['username' => $u . 'f', 'email' => "f$u@example.test", 'first_name' => 'T0m', 'phone' => ''], 'letters only', 'a name with digits is refused');
$dup(['username' => $u . 'g', 'email' => "g$u@example.test", 'first_name' => 'Zed', 'middle_initial' => 'AB', 'phone' => ''], 'single letter', 'a two-letter middle initial is refused');
$dup(['username' => 'x', 'email' => 'not-an-email', 'first_name' => 'Zed', 'phone' => '12'], 'valid email', 'a bad username, email and phone are explained field by field');
$r = $anon->form('register.php', $mk(['username' => $u . 'h']), 'register.php', false);
check($has($r['text'], 'session expired') && (int)val('SELECT COUNT(*) FROM users WHERE username = ?', [$u . 'h']) === 0, 'sign-up without a CSRF token is refused');

echo PHP_EOL . "== Officer: add accounts ==" . PHP_EOL;
$OF = (new Site($base))->login('officer1', 'officer123');
$page = $OF->get('officer_users.php');
preg_match('/<dialog class="modal" id="m-create".*?<\/dialog>/s', $page['body'], $dlg);
$dialog = $dlg[0] ?? '';
preg_match_all('/<input\b[^>]*>/i', $dialog, $ins);
$vis = array_filter($ins[0], static fn($i) => !preg_match('/type="hidden"/', $i));
$holder = array_filter($vis, static fn($i) => str_contains($i, 'placeholder="'));
check($page['status'] === 200 && count($vis) === 9 && count($holder) === 9, 'every input in the Add account form has a placeholder', count($holder) . ' of ' . count($vis));
check($has($dialog, 'name="first_name"') && $has($dialog, 'name="last_name"') && $has($dialog, 'name="middle_initial"') && $has($dialog, 'Tricycle unit no.'), 'the form has First name, Last name, Middle initial and Tricycle unit no.');
check(preg_match('/<input type="text" id="c_unit" name="unit_no"/', $dialog) === 1 && !preg_match('/<select[^>]*name="unit_no"/', $dialog), 'Tricycle unit no. is a text input, not a dropdown');
check(!$has($dialog, 'value="passenger"'), 'passenger accounts are not offered on the officer side');

$d = 'tmp_drv' . bin2hex(random_bytes(2));
$unitNo = 'TMPU-' . random_int(1000, 9999);
$lic = 'TMP-' . random_int(10000, 99999);
$drv = static fn(array $o = []) => $o + ['action' => 'create', 'role' => 'driver', 'first_name' => 'Tmp', 'middle_initial' => 'Q', 'last_name' => 'Driver', 'username' => $d,
    'email' => "$d@example.test", 'phone' => '0917 555 0202', 'license_number' => $lic, 'license_expiry' => '', 'unit_no' => $unitNo];
$r = $OF->form('officer_users.php', $drv());
$page = $OF->get('officer_users.php');
check($r['status'] === 302 && preg_match('/id="secret-pw">(Tr[0-9a-f]{8}9)</', $page['body'], $m) === 1, 'creating a driver shows the one-time temporary password');
$drow = rows('SELECT u.first_name, u.last_name, u.middle_initial, u.full_name, d.license_number, tu.body_number, tu.plate_number FROM users u JOIN drivers d ON d.user_id = u.id
                JOIN tricycle_units tu ON tu.id = d.default_unit_id WHERE u.username = ?', [$d])[0] ?? [];
check(($drow['full_name'] ?? '') === 'Tmp Q. Driver' && ($drow['body_number'] ?? '') === $unitNo && $drow['plate_number'] === null && ($drow['license_number'] ?? '') === $lic, 'the driver, licence and a NEW unit (from the unit number) are saved', json_encode($drow));
check((new Site($base))->login($d, $m[1] ?? 'x')->get('driver_dashboard.php')['status'] === 200, 'the new driver can log in');
$no = static fn(array $o, string $needle, string $label) => (function () use ($OF, $drv, $o, $needle, $label, $has) {
    $usersBefore = (int)val('SELECT COUNT(*) FROM users'); $unitsBefore = (int)val('SELECT COUNT(*) FROM tricycle_units');
    $r = $OF->form('officer_users.php', $drv($o));
    $page = $OF->get('officer_users.php');   // the modal re-opens with the error
    check($r['status'] === 302 && $has($page['text'], $needle) && $has($page['body'], 'data-autoopen="1"') && (int)val('SELECT COUNT(*) FROM users') === $usersBefore
          && (int)val('SELECT COUNT(*) FROM tricycle_units') === $unitsBefore, $label, substr(strip_tags($page['text']), 0, 0));
})();
$no(['email' => "z$d@example.test", 'phone' => '', 'license_number' => 'TMP-99998', 'unit_no' => 'TMPU-0002', 'first_name' => 'Newer'], 'username is already taken', 'a duplicate username is caught with a message, nothing is saved');
$no(['username' => $d . 'y', 'phone' => '', 'license_number' => 'TMP-99997', 'unit_no' => 'TMPU-0003', 'first_name' => 'Newer'], 'email address already exists', 'a duplicate email is caught');
$no(['username' => $d . 'x', 'email' => "x$d@example.test", 'license_number' => 'TMP-99996', 'unit_no' => 'TMPU-0004', 'first_name' => 'Newer'], 'phone number is already used', 'a duplicate phone number is caught');
$no(['username' => $d . 'w', 'email' => "w$d@example.test", 'phone' => '', 'license_number' => 'TMP-99995', 'unit_no' => 'TMPU-0005'], 'already exists (username: ' . $d . ')', 'the same person is caught and the officer is told which username');
$no(['username' => $d . 'v', 'email' => "v$d@example.test", 'phone' => '', 'first_name' => 'Newer', 'unit_no' => 'TMPU-0006'], 'license number is already registered', 'a duplicate driver licence is caught');
$no(['username' => $d . 'u', 'email' => "u$d@example.test", 'phone' => '', 'first_name' => 'Newer', 'license_number' => 'TMP-99994'], 'already assigned to Tmp Q. Driver', 'a unit already assigned to another driver is caught');
$no(['username' => $d . 't', 'email' => "t$d@example.test", 'phone' => '', 'first_name' => 'Newer', 'license_number' => 'TMP-99993', 'unit_no' => 'b-001'], 'already assigned to Driver One', 'a seeded unit number is recognised in any letter case');
run("INSERT INTO tricycle_units (plate_number, body_number, status) VALUES (NULL, 'TMPU-SICK', 'maintenance')");
$no(['username' => $d . 's', 'email' => "s$d@example.test", 'phone' => '', 'first_name' => 'Newer', 'license_number' => 'TMP-99992', 'unit_no' => 'TMPU-SICK'], 'not in service', 'a unit under maintenance cannot be assigned');
$no(['username' => $d . 'r', 'email' => "r$d@example.test", 'phone' => '', 'first_name' => 'Newer', 'license_number' => 'TMP-99991', 'unit_no' => ''], 'unit number', 'a driver needs a unit number');
$no(['role' => 'passenger', 'username' => $d . 'q', 'email' => "q$d@example.test", 'phone' => '', 'first_name' => 'Newer'], 'create their own accounts', 'an officer cannot create passengers (they sign up themselves)');
run("INSERT INTO tricycle_units (plate_number, body_number) VALUES (NULL, 'TMPU-FREE')");
$unitsBefore = (int)val('SELECT COUNT(*) FROM tricycle_units');
$r = $OF->form('officer_users.php', $drv(['username' => $d . 'p', 'email' => "p$d@example.test", 'phone' => '', 'first_name' => 'Free', 'license_number' => 'TMP-99990', 'unit_no' => 'tmpu-free']));
check($r['status'] === 302 && (int)val('SELECT COUNT(*) FROM tricycle_units') === $unitsBefore && (int)val('SELECT COUNT(*) FROM drivers d JOIN tricycle_units t ON t.id = d.default_unit_id WHERE t.body_number = "TMPU-FREE"') === 1, 'an existing free unit is linked instead of creating a duplicate');
$r = $OF->raw('POST', 'officer_users.php', http_build_query($drv(['username' => $d . 'o', 'email' => "o$d@example.test", 'phone' => '', 'first_name' => 'Csrf', 'unit_no' => 'TMPU-7777', 'license_number' => 'TMP-99989'])));
check((int)val('SELECT COUNT(*) FROM users WHERE username = ?', [$d . 'o']) === 0, 'creating an account without a CSRF token changes nothing');

echo PHP_EOL . "== Mobile layout markup ==" . PHP_EOL;
$page = $OF->get('officer_users.php');
check(!$has($page['body'], '<table') && $has($page['body'], 'class="user-grid"') && substr_count($page['body'], '<dialog') === 3, 'users are cards (no wide table) and the modals are native dialogs');
check($has($page['body'], 'viewport-fit=cover') && $has($page['body'], 'name="viewport"'), 'the viewport is set for phones');
$css = (string)file_get_contents(__DIR__ . '/../assets/css/dashboard.css');
check($has($css, 'dialog.modal') && $has($css, 'border-radius: 20px 20px 0 0') && $has($css, '.user-grid { display: grid; grid-template-columns: minmax(0, 1fr)') && $has($css, 'min-width: 0'), 'CSS: modals are bottom sheets on phones, cards are one column, items can shrink');
check($has($css, 'max-height: 92dvh') && $has($css, 'overflow-y: auto') && $has($css, 'env(safe-area-inset-bottom)'), 'CSS: modal content scrolls inside the screen and respects the phone safe area');
check($OF->get('officer_user.php')['location'] === 'officer_users.php', 'officer_user.php forwards to the users screen');

echo PHP_EOL . "== Passenger reports ==" . PHP_EOL;
$PA = (new Site($base))->login('passenger1', 'passenger123');
$PB = (new Site($base))->login('passenger2', 'passenger123');
$OP = (new Site($base))->login('operator1', 'operator123');
$page = $PA->get('passenger_report.php');
check($page['status'] === 200 && $has($page['body'], 'name="subject"') && $has($page['body'], 'placeholder="e.g. Driver asked'), 'the passenger has a report form with placeholders');
check($has($PA->get('passenger_dashboard.php')['body'], 'passenger_report.php'), 'the passenger menu links to Report');
$subject = 'TMP report ' . bin2hex(random_bytes(2));
$rep = ['category' => 'fare_dispute', 'booking_id' => '', 'subject' => $subject, 'message' => 'The driver asked for 100 pesos but the app showed 55. Happened near the market.'];
$r = $PA->form('passenger_report.php', $rep);
check($r['status'] === 302 && (int)val('SELECT COUNT(*) FROM passenger_reports WHERE subject = ?', [$subject]) === 1, 'a passenger can send a report');
check($has($PA->get('passenger_report.php')['text'], $subject) && $has($PA->get('passenger_report.php')['text'], 'Sent'), 'the passenger sees it in My reports');
$r = $PA->form('passenger_report.php', $rep);
check($r['status'] === 200 && $has($r['text'], 'already sent this exact report') && (int)val('SELECT COUNT(*) FROM passenger_reports WHERE subject = ?', [$subject]) === 1, 'the same report cannot be sent twice');
check($has($PA->form('passenger_report.php', ['subject' => 'Hi', 'message' => 'short'] + $rep)['text'], 'short title') && true, 'a too-short title or message is explained');
$r = $PA->form('passenger_report.php', ['subject' => 'Another valid title', 'message' => 'tiny'] + $rep);
check($has($r['text'], 'Describe what happened'), 'a too-short message is explained');
$pid2 = (int)val('SELECT id FROM users WHERE username = "passenger2"');
run("INSERT INTO bookings (passenger_id, pickup_label, pickup_latitude, pickup_longitude, dest_label, dest_latitude, dest_longitude, distance_km, status, cancel_reason) VALUES (?, 'A', 14.33, 120.94, 'B', 14.34, 120.97, 3.5, 'pending', NULL)", [$pid2]);
$foreign = (int)$pdo->lastInsertId();
$r = $PA->form('passenger_report.php', ['booking_id' => (string)$foreign, 'subject' => 'About a trip that is not mine', 'message' => 'This should not be accepted at all.'] + $rep);
check($has($r['text'], 'one of your own trips'), "a passenger cannot attach someone else's trip");
for ($i = 1; $i <= 4; $i++) { $PA->form('passenger_report.php', ['subject' => "TMP bulk $i " . bin2hex(random_bytes(2)), 'message' => "Bulk report number $i with enough words to pass."] + $rep); }
$r = $PA->form('passenger_report.php', ['subject' => 'TMP the sixth report', 'message' => 'This one goes over the daily limit.'] + $rep);
check($has($r['text'], 'up to 5 reports a day') && (int)val('SELECT COUNT(*) FROM passenger_reports WHERE passenger_id = (SELECT id FROM users WHERE username = "passenger1")') === 5, 'a passenger is limited to 5 reports a day');

$dash = $OF->get('officer_dashboard.php');
check($has($dash['text'], 'Passenger reports') && $has($dash['text'], $subject) && $has($dash['text'], 'Open reports'), 'THE REPORT SHOWS INSIDE THE OFFICER DASHBOARD');
$list = $OF->get('officer_reports.php');
check($list['status'] === 200 && $has($list['text'], $subject) && $has($list['text'], 'Passenger One') && $has($list['text'], 'Fare or overcharging'), 'the officer sees the report with the passenger and category');
$rid = (int)val('SELECT id FROM passenger_reports WHERE subject = ?', [$subject]);
$OF->form('officer_reports.php', ['report_id' => $rid, 'status' => 'in_review', 'officer_note' => 'We are checking with the driver.'], 'officer_reports.php');
check(val('SELECT status FROM passenger_reports WHERE id = ?', [$rid]) === 'in_review', 'the officer can mark it in review');
$mine = $PA->get('passenger_report.php')['text'];
check($has($mine, 'Being reviewed') && $has($mine, 'We are checking with the driver.'), 'the passenger sees the new status and the reply');
$OF->form('officer_reports.php', ['report_id' => $rid, 'status' => 'resolved', 'officer_note' => 'Resolved.'], 'officer_reports.php');
check(val('SELECT status FROM passenger_reports WHERE id = ?', [$rid]) === 'resolved' && val('SELECT resolved_at FROM passenger_reports WHERE id = ?', [$rid]) !== null, 'resolving records the time');
check($PA->get('officer_reports.php')['status'] === 403 && $OP->get('officer_reports.php')['status'] === 403 && $OF->get('passenger_report.php')['status'] === 403, 'reports are role-locked (passengers send, officers read)');
$before = (string)val('SELECT status FROM passenger_reports WHERE id = ?', [$rid]);
$OF->raw('POST', 'officer_reports.php', http_build_query(['report_id' => $rid, 'status' => 'dismissed']));
check(val('SELECT status FROM passenger_reports WHERE id = ?', [$rid]) === $before, 'updating a report without a CSRF token changes nothing');

echo PHP_EOL . "== Fare rates (per km) ==" . PHP_EOL;
$page = $OF->get('officer_fares.php');
check($page['status'] === 200 && $has($page['text'], 'per km') && $has($page['text'], 'Rate per km') && $has($page['body'], 'placeholder="e.g. 10.00"'), 'the fare screen is built around the per-km rate and has placeholders');
check($has($OF->get('officer_dashboard.php')['text'], 'per km'), 'the officer dashboard shows the active per-km rate');
check($PA->get('officer_fares.php')['status'] === 403 && $OP->get('officer_fares.php')['status'] === 403, 'only officers can open fare rates');
$fn = 'TMP rate ' . bin2hex(random_bytes(2));
$fare = ['action' => 'save', 'id' => '0', 'name' => $fn, 'per_km_rate' => '12.50', 'base_fare' => '0', 'base_distance_km' => '0', 'minimum_fare' => '20', 'effective_from' => date('Y-m-d'), 'effective_to' => ''];
$bad = static function (array $o, string $needle, string $label) use ($OF, $fare, $has) {
    $before = (int)val('SELECT COUNT(*) FROM fare_matrices');
    $r = $OF->form('officer_fares.php', $o + $fare);
    $p = $OF->get('officer_fares.php');
    check($r['status'] === 302 && $has($p['text'], $needle) && (int)val('SELECT COUNT(*) FROM fare_matrices') === $before, $label);
};
$bad(['per_km_rate' => '0'], 'rate per kilometre', 'a zero per-km rate is refused');
$bad(['name' => $fn . 'b', 'per_km_rate' => 'abc'], 'rate per kilometre', 'a non-number rate is refused');
$bad(['name' => $fn . 'c', 'per_km_rate' => '-5'], 'rate per kilometre', 'a negative rate is refused');
$bad(['name' => $fn . 'd', 'effective_to' => date('Y-m-d', strtotime('-3 days'))], 'on or after the start date', 'an end date before the start date is refused');
$bad(['name' => 'ab'], 'name of 3 to 80', 'a too-short name is refused');
$OF->form('officer_fares.php', $fare);
check((int)val('SELECT COUNT(*) FROM fare_matrices WHERE name = ?', [$fn]) === 1 && (int)val('SELECT is_active FROM fare_matrices WHERE name = ?', [$fn]) === 0, 'a new fare rate is saved as not active');
$bad(['name' => strtolower($fn)], 'already exists', 'a duplicate fare rate name is refused');
$fid = (int)val('SELECT id FROM fare_matrices WHERE name = ?', [$fn]);
$OF->form('officer_fares.php', ['action' => 'activate', 'id' => $fid]);
check((int)val('SELECT COUNT(*) FROM fare_matrices WHERE is_active = 1') === 1 && (int)val('SELECT is_active FROM fare_matrices WHERE id = ?', [$fid]) === 1, 'activating a rate switches the old one off (exactly one active)');
$PAapi = (new Site($base))->login('passenger1', 'passenger123');
$rt = $PAapi->api('route.php?plat=14.3294&plng=120.9367&dlat=14.3400&dlng=120.9700');
$expect = max(20.0, 0 + max($rt['distance_km'] - 0, 0) * 12.5);
check(($rt['ok'] ?? false) && abs($rt['fare'] - $expect) < 0.01 && abs($rt['fare_matrix']['per_km_rate'] - 12.5) < 0.001, 'passengers are now priced at the new per-km rate', json_encode($rt));
$future = $fn . ' future';
$OF->form('officer_fares.php', ['name' => $future, 'effective_from' => date('Y-m-d', strtotime('+10 days'))] + $fare);
$futureId = (int)val('SELECT id FROM fare_matrices WHERE name = ?', [$future]);
$OF->form('officer_fares.php', ['action' => 'activate', 'id' => $futureId]);
check((int)val('SELECT is_active FROM fare_matrices WHERE id = ?', [$futureId]) === 0 && (int)val('SELECT is_active FROM fare_matrices WHERE id = ?', [$fid]) === 1, 'a rate that starts in the future cannot be activated early');
$c = $PAapi->api('booking_create.php', 'POST', ['pickup' => ['lat' => 14.3294, 'lng' => 120.9367], 'dest' => ['lat' => 14.34, 'lng' => 120.97]]);
check(($c['ok'] ?? false) && abs($c['fare'] - $expect) < 0.01, 'a booking is priced with the active per-km rate');
$OF->form('officer_fares.php', ['id' => (string)$fid, 'per_km_rate' => '99'] + $fare);
check((float)val('SELECT per_km_rate FROM fare_matrices WHERE id = ?', [$fid]) === 12.5, 'a rate already used by a booking cannot be edited');
$OF->form('officer_fares.php', ['id' => (string)$futureId, 'per_km_rate' => '15.00', 'name' => $future] + $fare);
check((float)val('SELECT per_km_rate FROM fare_matrices WHERE id = ?', [$futureId]) === 15.0, 'an unused rate can be edited');

echo PHP_EOL . "== An out-of-date database does not crash the pages ==" . PHP_EOL;
$pdo->exec('RENAME TABLE passenger_reports TO passenger_reports_off');
try {
    foreach (['passenger_report.php' => $PA, 'officer_reports.php' => $OF, 'officer_dashboard.php' => $OF] as $pg => $who) {
        $r = $who->get($pg);
        check($r['status'] === 200 && $has($r['text'], 'out of date') && !$has($r['text'], 'SQLSTATE') && !$has($r['text'], 'Fatal'), "$pg explains the missing update instead of crashing");
    }
} finally { $pdo->exec('RENAME TABLE passenger_reports_off TO passenger_reports'); }
$pdo->exec('ALTER TABLE users CHANGE middle_initial middle_initial_off CHAR(1) NOT NULL DEFAULT ""');
try {
    $r = (new Site($base))->form('register.php', $mk(['username' => $u . 'm', 'email' => "m$u@example.test", 'phone' => '', 'first_name' => 'Late']));
    check($r['status'] === 200 && $has($r['text'], 'out of date') && !$has($r['text'], 'SQLSTATE'), 'sign-up on an old database shows "database is out of date", not an error page');
    $r = $OF->get('officer_users.php');
    check($r['status'] === 200 && $has($r['text'], 'out of date'), 'the officer users screen explains the missing update');
} finally { $pdo->exec('ALTER TABLE users CHANGE middle_initial_off middle_initial CHAR(1) NOT NULL DEFAULT ""'); }
check(schemaHas('users', 'middle_initial') && $PA->get('passenger_report.php')['status'] === 200 && $has($PA->get('passenger_report.php')['body'], 'name="subject"'), 'everything works again after the structure is restored');

/* ---- clean up ---- */
run('DELETE FROM bookings');
run('DELETE FROM passenger_reports');
$pdo->exec("DELETE FROM users WHERE username LIKE 'tmp\\_%'");
$pdo->exec("DELETE FROM tricycle_units WHERE body_number LIKE 'TMPU%'");
run("DELETE FROM fare_matrices WHERE name LIKE 'TMP rate%'");
run('UPDATE fare_matrices SET is_active = 0 WHERE is_active = 1');
run('UPDATE fare_matrices SET is_active = 1 WHERE id = ?', [$activeFareBefore]);
run('DELETE FROM login_attempts WHERE attempted_at >= ?', [$started]);

echo PHP_EOL . "Result: $pass passed, $fail failed." . PHP_EOL;
exit($fail === 0 ? 0 : 1);
