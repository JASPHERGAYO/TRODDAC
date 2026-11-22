<?php
/**
 * Phase 2 test: logs in over real HTTP and checks authentication and role access.
 *
 *   C:\xampp\php\php.exe tests\phase2_http_test.php [base-url]
 *   default base-url: http://localhost/troddac   (the folder name under htdocs)
 *
 * Needs the sample seed data and the curl extension (enabled in XAMPP by default).
 * Creates a few accounts named tmp_* and removes them at the end.
 */
declare(strict_types=1);

$base = rtrim($argv[1] ?? 'http://localhost/troddac', '/');
$cfg  = (require __DIR__ . '/../config/config.php')['db'];
$pdo  = new PDO("mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset={$cfg['charset']}", $cfg['user'], $cfg['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

final class Http
{
    private string $jar;
    public function __construct(private string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'trd'); }
    public function request(string $method, string $path, array $data = []): array
    {
        $ch = curl_init($this->base . '/' . ltrim($path, '/'));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 30]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $raw = curl_exec($ch);
        if ($raw === false) { fwrite(STDERR, "Cannot reach {$this->base}: " . curl_error($ch) . PHP_EOL); exit(2); }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size   = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $headers = substr($raw, 0, $size);
        preg_match('/^Location:\s*(\S+)/mi', $headers, $m);
        return ['status' => $status, 'location' => $m[1] ?? '', 'body' => substr($raw, $size), 'headers' => $headers];
    }
    public function csrf(string $path): string
    {
        $r = $this->request('GET', $path);
        return preg_match('/name="csrf_token" value="([^"]+)"/', $r['body'], $m) ? $m[1] : '';
    }
    public function login(string $id, string $pw): array
    {
        return $this->request('POST', 'login.php', ['csrf_token' => $this->csrf('login.php'), 'identifier' => $id, 'password' => $pw]);
    }
    public function post(string $path, array $data, ?string $tokenFrom = null): array
    {
        $data['csrf_token'] = $this->csrf($tokenFrom ?? $path);
        return $this->request('POST', $path, $data);
    }
}

$pass = 0; $fail = 0;
function check(bool $ok, string $label, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . ($ok || $detail === '' ? '' : "  -> $detail") . PHP_EOL;
}
function dbVal(PDO $pdo, string $sql, array $p = []): mixed { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); }

$started = (string)dbVal($pdo, 'SELECT NOW()');
$pdo->exec("DELETE FROM login_attempts");

echo "== Anonymous access ==" . PHP_EOL;
$anon = new Http($base);
foreach (['dashboard.php', 'passenger_dashboard.php', 'driver_dashboard.php', 'operator_dashboard.php', 'officer_dashboard.php', 'officer_users.php', 'account.php'] as $page) {
    $r = $anon->request('GET', $page);
    check($r['status'] === 302 && str_contains($r['location'], 'login.php'), "$page redirects an anonymous visitor to login");
}
$fresh = new Http($base);
$r = $fresh->request('GET', 'login.php');
check($r['status'] === 200 && str_contains($r['body'], 'csrf_token'), 'login page loads and carries a CSRF token');
check(str_contains($r['headers'], 'HttpOnly') && str_contains($r['headers'], 'SameSite=Lax'), 'session cookie is HttpOnly and SameSite=Lax');

echo PHP_EOL . "== Login rules ==" . PHP_EOL;
$r = $anon->request('POST', 'login.php', ['identifier' => 'officer1', 'password' => 'officer123']);
check($r['status'] === 200 && str_contains($r['body'], 'session expired'), 'login without a CSRF token is refused');
$r = $anon->login('officer1', 'wrong-password');
check($r['status'] === 200 && str_contains($r['body'], 'Wrong username or password'), 'wrong password gives a generic error');
$r = $anon->login('nobody_here', 'whatever123');
check($r['status'] === 200 && str_contains($r['body'], 'Wrong username or password'), 'unknown user gives the same generic error');
$r = $anon->login("' OR '1'='1' -- ", "' OR '1'='1");
check($r['status'] === 200 && str_contains($r['body'], 'Wrong username or password'), 'SQL injection in the login form does not log in');

echo PHP_EOL . "== Role dashboards and access matrix ==" . PHP_EOL;
$accounts = [
    'passenger' => ['passenger1', 'passenger123', 'passenger_dashboard.php'],
    'driver'    => ['driver1', 'driver123', 'driver_dashboard.php'],
    'operator'  => ['operator1', 'operator123', 'operator_dashboard.php'],
    'officer'   => ['officer1', 'officer123', 'officer_dashboard.php'],
];
$pages = [   // page => roles allowed
    'passenger_dashboard.php' => ['passenger'],
    'driver_dashboard.php'    => ['driver'],
    'operator_dashboard.php'  => ['operator'],
    'officer_dashboard.php'   => ['officer'],
    'officer_users.php'       => ['officer'],
    'account.php'             => ['passenger', 'driver', 'operator', 'officer'],
];
$clients = [];
foreach ($accounts as $role => [$user, $pw, $home]) {
    $c = new Http($base);
    $r = $c->login($user, $pw);
    check($r['status'] === 302 && str_contains($r['location'], $home), "$role logs in and lands on $home", "status {$r['status']} to {$r['location']}");
    $clients[$role] = $c;
}
foreach ($clients as $role => $c) {
    foreach ($pages as $page => $allowed) {
        $r = $c->request('GET', $page);
        $expected = in_array($role, $allowed, true) ? 200 : 403;
        check($r['status'] === $expected, "$role opening $page -> $expected", "got {$r['status']}");
    }
}
$r = $clients['passenger']->request('GET', 'login.php');
check($r['status'] === 302 && str_contains($r['location'], 'passenger_dashboard.php'), 'a logged-in user opening login.php is sent to their dashboard');
check(dbVal($pdo, 'SELECT last_login_at FROM users WHERE username = ?', ['officer1']) !== null, 'last_login_at is recorded');

echo PHP_EOL . "== Logout ==" . PHP_EOL;
$c = new Http($base); $c->login('passenger2', 'passenger123');
$r = $c->request('GET', 'logout.php');
check($c->request('GET', 'passenger_dashboard.php')['status'] === 200, 'GET logout.php does not log the user out (CSRF-safe)');
$r = $c->request('POST', 'logout.php', []);
check($c->request('GET', 'passenger_dashboard.php')['status'] === 200, 'POST logout without a token does not log out');
$c->post('logout.php', [], 'passenger_dashboard.php');
check($c->request('GET', 'passenger_dashboard.php')['status'] === 302, 'POST logout with a token ends the session');

echo PHP_EOL . "== Registration ==" . PHP_EOL;
$reg = new Http($base);
$tmp = 'tmp_' . bin2hex(random_bytes(3));
$r = $reg->post('register.php', ['first_name' => 'Tmp', 'last_name' => 'Passenger', 'middle_initial' => '', 'username' => $tmp, 'email' => "$tmp@example.test", 'phone' => '', 'password' => 'Secret123', 'confirm' => 'Secret123', 'role' => 'officer']);
check($r['status'] === 302 && str_contains($r['location'], 'login.php'), 'a passenger can register');
check(dbVal($pdo, 'SELECT role FROM users WHERE username = ?', [$tmp]) === 'passenger', 'registration ignores a forged role=officer field');
$r = $reg->post('register.php', ['first_name' => 'Dup', 'last_name' => 'Licate', 'username' => $tmp, 'email' => "other_$tmp@example.test", 'password' => 'Secret123', 'confirm' => 'Secret123']);
check(str_contains($r['body'], 'already taken'), 'a duplicate username is rejected');
$r = $reg->post('register.php', ['first_name' => 'Weak', 'last_name' => 'Pass', 'username' => $tmp . 'w', 'email' => "w$tmp@example.test", 'password' => 'short', 'confirm' => 'short']);
check(str_contains($r['body'], 'at least 8'), 'a weak password is rejected');
$r = $reg->post('register.php', ['first_name' => 'Mis', 'last_name' => 'Match', 'username' => $tmp . 'm', 'email' => "m$tmp@example.test", 'password' => 'Secret123', 'confirm' => 'Secret124']);
check(str_contains($r['body'], 'do not match'), 'mismatched passwords are rejected');
check((string)dbVal($pdo, 'SELECT password FROM users WHERE username = ?', [$tmp]) !== 'Secret123'
      && str_starts_with((string)dbVal($pdo, 'SELECT password FROM users WHERE username = ?', [$tmp]), '$2y$'), 'passwords are stored as bcrypt hashes');

echo PHP_EOL . "== Lockout after repeated failures ==" . PHP_EOL;
$lock = new Http($base);
for ($i = 0; $i < 5; $i++) { $lock->login($tmp, 'wrong-password1'); }
$r = $lock->login($tmp, 'Secret123');
check($r['status'] === 200 && str_contains($r['body'], 'Too many failed attempts'), 'after 5 failures even the right password is blocked');
$pdo->exec("DELETE FROM login_attempts");
$r = $lock->login($tmp, 'Secret123');
check($r['status'] === 302, 'login works again once the lockout clears');

echo PHP_EOL . "== Officer: user management ==" . PHP_EOL;
$off = $clients['officer'];
$drvName = 'tmp_drv' . bin2hex(random_bytes(2));
$r = $off->post('officer_users.php', ['action' => 'create', 'role' => 'driver', 'first_name' => 'Tmp', 'last_name' => 'Driver', 'middle_initial' => 'Q', 'username' => $drvName, 'email' => "$drvName@example.test", 'phone' => '', 'license_number' => 'TMP-' . random_int(10000, 99999), 'license_expiry' => '', 'unit_no' => 'TMPU-' . random_int(1000, 9999)]);
$page = $off->request('GET', 'officer_users.php');
check(preg_match('/id="secret-pw">(Tr[0-9a-f]{8}9)</', $page['body'], $m) === 1, 'creating a driver account shows a one-time temporary password');
$temp = $m[1] ?? '';
check((int)dbVal($pdo, 'SELECT COUNT(*) FROM drivers d JOIN users u ON u.id = d.user_id WHERE u.username = ? AND u.role = "driver"', [$drvName]) === 1, 'a driver account creates the matching driver profile');
$newDriver = new Http($base);
$r = $newDriver->login($drvName, $temp);
check($r['status'] === 302 && str_contains($r['location'], 'driver_dashboard.php'), 'the new driver can log in with the temporary password');

$r = $off->post('officer_users.php', ['action' => 'create', 'role' => 'driver', 'first_name' => 'No', 'last_name' => 'License', 'username' => $drvName . 'x', 'email' => "x$drvName@example.test", 'license_number' => '', 'unit_no' => 'TMPU2']);
check((int)dbVal($pdo, 'SELECT COUNT(*) FROM users WHERE username = ?', [$drvName . 'x']) === 0, 'a driver cannot be created without a license number');
$off->post('officer_users.php', ['action' => 'create', 'role' => 'wizard', 'first_name' => 'Bad', 'last_name' => 'Role', 'username' => $drvName . 'y', 'email' => "y$drvName@example.test"]);
check((int)dbVal($pdo, 'SELECT COUNT(*) FROM users WHERE username = ?', [$drvName . 'y']) === 0, 'an unknown role is refused');

// Non-officers cannot use the officer endpoint, even with a valid token.
$pass1 = $clients['passenger'];
$tok = $pass1->csrf('account.php');
$r = $pass1->request('POST', 'officer_users.php', ['csrf_token' => $tok, 'action' => 'create', 'role' => 'officer', 'first_name' => 'Evil', 'last_name' => 'Person', 'username' => 'tmp_evil', 'email' => 'tmp_evil@example.test']);
check($r['status'] === 403 && (int)dbVal($pdo, 'SELECT COUNT(*) FROM users WHERE username = "tmp_evil"') === 0, 'a passenger cannot create accounts through officer_users.php');
$r = $off->request('POST', 'officer_users.php', ['action' => 'toggle', 'user_id' => 1]);
check((int)dbVal($pdo, 'SELECT is_active FROM users WHERE username = "officer1"') === 1, 'an officer POST without a CSRF token changes nothing');

// Disabling an account ends that user's session at once.
$tmpClient = new Http($base); $tmpClient->login($tmp, 'Secret123');
check($tmpClient->request('GET', 'passenger_dashboard.php')['status'] === 200, 'registered passenger is logged in');
$tmpId = (int)dbVal($pdo, 'SELECT id FROM users WHERE username = ?', [$tmp]);
$off->post('officer_users.php', ['action' => 'toggle', 'user_id' => $tmpId]);
$r = $tmpClient->request('GET', 'passenger_dashboard.php');
check($r['status'] === 302 && str_contains($r['location'], 'login.php'), 'disabling an account ends its live session immediately');
$r = $tmpClient->login($tmp, 'Secret123');
check(str_contains($r['body'], 'account is disabled'), 'a disabled account cannot log in again');
$off->post('officer_users.php', ['action' => 'toggle', 'user_id' => $tmpId]);
check($tmpClient->login($tmp, 'Secret123')['status'] === 302, 're-enabling restores access');

// Officer cannot disable themselves; the DB protects the last officer.
$officerId = (int)dbVal($pdo, 'SELECT id FROM users WHERE username = "officer1"');
$off->post('officer_users.php', ['action' => 'toggle', 'user_id' => $officerId]);
check((int)dbVal($pdo, 'SELECT is_active FROM users WHERE id = ?', [$officerId]) === 1, 'an officer cannot disable their own account');
$pdo->beginTransaction();
try { $pdo->exec('UPDATE users SET is_active = 0 WHERE id = ' . $officerId); check(false, 'the database blocks disabling the last officer'); }
catch (PDOException $e) { check(str_contains($e->getMessage(), 'TRD-E305'), 'the database blocks disabling the last officer'); }
$pdo->rollBack();

// Password reset
$off->post('officer_users.php', ['action' => 'reset', 'user_id' => $tmpId]);
$page = $off->request('GET', 'officer_users.php');
preg_match('/id="secret-pw">(Tr[0-9a-f]{8}9)</', $page['body'], $m);
check((new Http($base))->login($tmp, 'Secret123')['status'] === 200 && (new Http($base))->login($tmp, $m[1] ?? 'x')['status'] === 302, 'password reset replaces the old password');

echo PHP_EOL . "== Account page ==" . PHP_EOL;
$me = new Http($base); $me->login($tmp, $m[1] ?? '');
$me->post('account.php', ['action' => 'password', 'current_password' => 'not-it', 'new_password' => 'NewSecret99', 'confirm_password' => 'NewSecret99']);
check((new Http($base))->login($tmp, 'NewSecret99')['status'] === 200, 'password change needs the right current password');
$me->post('account.php', ['action' => 'password', 'current_password' => $m[1] ?? '', 'new_password' => 'NewSecret99', 'confirm_password' => 'NewSecret99']);
check((new Http($base))->login($tmp, 'NewSecret99')['status'] === 302, 'a user can change their own password');
$me->post('account.php', ['action' => 'profile', 'first_name' => 'Renamed', 'last_name' => 'Person', 'middle_initial' => '', 'phone' => '0917 123 4567']);
check(dbVal($pdo, 'SELECT full_name FROM users WHERE username = ?', [$tmp]) === 'Renamed Person', 'a user can update their profile');

echo '(requireApiRole() is exercised by the Phase 3 endpoints.)' . PHP_EOL;

// ---- cleanup
$pdo->exec("DELETE FROM users WHERE username LIKE 'tmp\\_%'");
$pdo->exec("DELETE FROM tricycle_units WHERE body_number LIKE 'TMPU%'");
$pdo->prepare('DELETE FROM login_attempts WHERE attempted_at >= ?')->execute([$started]);

echo PHP_EOL . "Result: $pass passed, $fail failed." . PHP_EOL;
exit($fail === 0 ? 0 : 1);
