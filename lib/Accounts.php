<?php
declare(strict_types=1);

/**
 * Creating and updating accounts, shared by the public passenger sign-up (register.php),
 * the Association Officer's user screen and the My account page.
 *
 * Every check happens before anything is written, field errors come back as plain messages,
 * and the unique indexes in the database are a second line of defence: if two people submit
 * the same details at the same instant, the loser gets the same friendly message.
 */
final class Accounts
{
    public const NEEDS = ['users' => ['first_name', 'last_name', 'middle_initial', 'phone']];
    private const NAME = '/^\p{L}[\p{L}\p{M} .\'\x{2019}-]{0,49}$/u';

    public static function clean(mixed $value): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)$value) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $clean) ?? '');
    }

    /** 0917 123 4567, +63 917 123 4567 and 63917... are the same number (stored as 09171234567). */
    public static function normalizePhone(string $raw): ?string
    {
        $t = preg_replace('/[\s().-]+/', '', trim($raw)) ?? '';
        if ($t === '') {
            return null;
        }
        if (str_starts_with($t, '+63')) {
            $t = '0' . substr($t, 3);
        } elseif (preg_match('/^63\d{10}$/', $t)) {
            $t = '0' . substr($t, 2);
        }
        return $t;
    }

    public static function displayName(string $first, string $last, string $mi): string
    {
        return trim($first . ($mi !== '' ? ' ' . $mi . '.' : '') . ' ' . $last);
    }

    /** Cleans raw form input into the values stored in the database. */
    public static function normalize(array $in): array
    {
        $mi = rtrim(self::clean($in['middle_initial'] ?? ''), '. ');
        return [
            'first_name'     => self::clean($in['first_name'] ?? ''),
            'last_name'      => self::clean($in['last_name'] ?? ''),
            'middle_initial' => $mi === '' ? '' : textUpper($mi),
            'username'       => self::clean($in['username'] ?? ''),
            'email'          => strtolower(self::clean($in['email'] ?? '')),
            'phone_raw'      => self::clean($in['phone'] ?? ''),
            'phone'          => self::normalizePhone((string)($in['phone'] ?? '')),
            'license_number' => strtoupper(self::clean($in['license_number'] ?? '')),
            'license_expiry' => self::clean($in['license_expiry'] ?? ''),
            'unit_no'        => strtoupper(self::clean($in['unit_no'] ?? '')),
        ];
    }

    /** @return array<string,string> field => message */
    public static function validateName(array $v): array
    {
        $errors = [];
        if (!preg_match(self::NAME, $v['first_name'])) {
            $errors['first_name'] = 'Enter the first name using letters only (for example: Juan).';
        }
        if (!preg_match(self::NAME, $v['last_name'])) {
            $errors['last_name'] = 'Enter the last name using letters only (for example: Dela Cruz).';
        }
        if ($v['middle_initial'] !== '' && !preg_match('/^\p{L}$/u', $v['middle_initial'])) {
            $errors['middle_initial'] = 'The middle initial is a single letter (for example: D).';
        }
        if ($v['phone'] !== null && !preg_match('/^\+?\d{7,15}$/', $v['phone'])) {
            $errors['phone'] = 'Enter a valid phone number, for example 0917 123 4567.';
        }
        return $errors;
    }

    public static function validate(array $v, string $role, ?string $password): array
    {
        $errors = self::validateName($v);
        if (!preg_match('/^[A-Za-z0-9_.]{3,30}$/', $v['username'])) {
            $errors['username'] = 'Username must be 3-30 characters: letters, numbers, dot or underscore.';
        }
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($v['email']) > 100) {
            $errors['email'] = 'Enter a valid email address, for example juan@example.com.';
        }
        if ($password !== null && ($msg = validatePassword($password)) !== null) {
            $errors['password'] = $msg;
        }
        if ($role === 'driver') {
            if (!preg_match('/^[A-Z0-9\-]{5,30}$/', $v['license_number'])) {
                $errors['license_number'] = 'Enter the license number (5-30 letters, numbers or dashes).';
            }
            if ($v['license_expiry'] !== '') {
                $d = DateTime::createFromFormat('Y-m-d', $v['license_expiry']);
                if (!$d || $d->format('Y-m-d') !== $v['license_expiry']) {
                    $errors['license_expiry'] = 'Enter the expiry date as year-month-day.';
                } elseif ($v['license_expiry'] < date('Y-m-d')) {
                    $errors['license_expiry'] = 'This license has already expired.';
                }
            }
            if (!preg_match('/^[A-Z0-9][A-Z0-9\-\/ ]{0,19}$/', $v['unit_no'])) {
                $errors['unit_no'] = 'Enter the tricycle unit number (for example B-005).';
            }
        }
        return $errors;
    }

    /** Checks username, email, phone, identity (same name) and, for drivers, license and unit. */
    public static function duplicates(PDO $pdo, array $v, string $role, bool $reveal, int $exceptUserId = 0): array
    {
        $one = static function (string $sql, array $p) use ($pdo): array|false {
            $st = $pdo->prepare($sql);
            $st->execute($p);
            return $st->fetch();
        };
        $errors = [];
        $with = $reveal ? static fn(string $u) => " (username: $u)" : static fn(string $u) => '';

        if (isset($v['username']) && $v['username'] !== '' && $row = $one('SELECT username FROM users WHERE username = ? AND id <> ? LIMIT 1', [$v['username'], $exceptUserId])) {
            $errors['username'] = 'That username is already taken. Please choose another one.';
        }
        if (isset($v['email']) && $v['email'] !== '' && $row = $one('SELECT username FROM users WHERE email = ? AND id <> ? LIMIT 1', [$v['email'], $exceptUserId])) {
            $errors['email'] = 'An account with this email address already exists.' . ($reveal ? '' : ' Try logging in instead.');
        }
        if ($v['phone'] !== null && $row = $one('SELECT username FROM users WHERE phone = ? AND id <> ? LIMIT 1', [$v['phone'], $exceptUserId])) {
            $errors['phone'] = 'That phone number is already used by another account' . $with($row['username']) . '.';
        }
        if (!isset($errors['first_name']) && $row = $one('SELECT username FROM users WHERE first_name = ? AND last_name = ? AND middle_initial = ? AND id <> ? LIMIT 1',
                [$v['first_name'], $v['last_name'], $v['middle_initial'], $exceptUserId])) {
            $who = self::displayName($v['first_name'], $v['last_name'], $v['middle_initial']);
            $errors['first_name'] = "An account for $who already exists" . $with($row['username']) . '. '
                . 'If you are a different person with the same name, add or change the middle initial.';
        }
        if ($role === 'driver') {
            if ($row = $one('SELECT u.full_name FROM drivers d JOIN users u ON u.id = d.user_id WHERE d.license_number = ? LIMIT 1', [$v['license_number']])) {
                $errors['license_number'] = 'That license number is already registered to ' . ($reveal ? $row['full_name'] : 'another driver') . '.';
            }
            $unit = $one('SELECT id, status, body_number FROM tricycle_units WHERE body_number = ? OR plate_number = ? LIMIT 1', [$v['unit_no'], $v['unit_no']]);
            if ($unit) {
                if ($unit['status'] !== 'active') {
                    $errors['unit_no'] = 'Unit ' . $unit['body_number'] . ' is not in service (' . $unit['status'] . ') and cannot be assigned.';
                } elseif ($owner = $one('SELECT u.full_name FROM drivers d JOIN users u ON u.id = d.user_id WHERE d.default_unit_id = ? LIMIT 1', [$unit['id']])) {
                    $errors['unit_no'] = 'Unit ' . $unit['body_number'] . ' is already assigned to ' . $owner['full_name'] . '. Enter a different unit number.';
                }
            }
        }
        return $errors;
    }

    /**
     * @param array $in raw form values; $role one of passenger|driver|operator|officer
     * @return array{ok:bool, errors:array<string,string>, message:?string, id?:int, username?:string, full_name?:string}
     */
    public static function create(PDO $pdo, array $in, string $role, string $password, bool $reveal): array
    {
        if (schemaMissing(self::NEEDS)) {
            return ['ok' => false, 'errors' => [], 'message' => SCHEMA_HELP];
        }
        if (!isset(ROLE_LABEL[$role])) {
            return ['ok' => false, 'errors' => ['role' => 'Choose a valid role.'], 'message' => null];
        }
        $v = self::normalize($in);
        $errors = self::validate($v, $role, $password);
        if ($errors) {
            return ['ok' => false, 'errors' => $errors, 'message' => null];
        }

        $locked = (int)$pdo->query("SELECT GET_LOCK('troddac_account_create', 5)")->fetchColumn() === 1;
        if (!$locked) {
            return ['ok' => false, 'errors' => [], 'message' => 'The system is busy creating another account. Please try again in a moment.'];
        }
        try {
            $errors = self::duplicates($pdo, $v, $role, $reveal);
            if ($errors) {
                return ['ok' => false, 'errors' => $errors, 'message' => null];
            }
            $fullName = self::displayName($v['first_name'], $v['last_name'], $v['middle_initial']);

            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO users (username, email, password, full_name, first_name, last_name, middle_initial, phone, role) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$v['username'], $v['email'], password_hash($password, PASSWORD_DEFAULT), $fullName,
                           $v['first_name'], $v['last_name'], $v['middle_initial'], $v['phone'], $role]);
            $userId = (int)$pdo->lastInsertId();

            if ($role === 'driver') {
                $st = $pdo->prepare('SELECT id FROM tricycle_units WHERE body_number = ? OR plate_number = ? LIMIT 1');
                $st->execute([$v['unit_no'], $v['unit_no']]);
                $unitId = $st->fetchColumn();
                if ($unitId === false) {
                    $pdo->prepare('INSERT INTO tricycle_units (plate_number, body_number) VALUES (NULL, ?)')->execute([$v['unit_no']]);
                    $unitId = (int)$pdo->lastInsertId();
                }
                $pdo->prepare('INSERT INTO drivers (user_id, license_number, license_expiry, default_unit_id) VALUES (?,?,?,?)')
                    ->execute([$userId, $v['license_number'], $v['license_expiry'] !== '' ? $v['license_expiry'] : null, (int)$unitId]);
            }
            $pdo->commit();
            return ['ok' => true, 'errors' => [], 'message' => null, 'id' => $userId, 'username' => $v['username'], 'full_name' => $fullName];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return self::fromDatabaseError($e);
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('troddac_account_create')")->fetchColumn();
        }
    }

    /** Updates the signed-in user's own name and phone. */
    public static function updateProfile(PDO $pdo, int $userId, array $in): array
    {
        if (schemaMissing(self::NEEDS)) {
            return ['ok' => false, 'errors' => [], 'message' => SCHEMA_HELP];
        }
        $v = self::normalize($in);
        $errors = self::validateName($v);
        if (!$errors) {
            $errors = self::duplicates($pdo, ['username' => '', 'email' => ''] + $v, 'passenger', false, $userId);
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors, 'message' => null];
        }
        try {
            $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, middle_initial = ?, full_name = ?, phone = ? WHERE id = ?')
                ->execute([$v['first_name'], $v['last_name'], $v['middle_initial'], self::displayName($v['first_name'], $v['last_name'], $v['middle_initial']), $v['phone'], $userId]);
        } catch (PDOException $e) {
            return self::fromDatabaseError($e);
        }
        return ['ok' => true, 'errors' => [], 'message' => null];
    }

    private static function fromDatabaseError(PDOException $e): array
    {
        if (isSchemaError($e)) {
            return ['ok' => false, 'errors' => [], 'message' => SCHEMA_HELP];
        }
        if (($e->errorInfo[1] ?? 0) === 1062) {
            $text = $e->getMessage();
            $map = ['uq_users_username' => ['username', 'That username is already taken. Please choose another one.'],
                    'uq_users_email' => ['email', 'An account with this email address already exists.'],
                    'uq_users_phone' => ['phone', 'That phone number is already used by another account.'],
                    'uq_drivers_license' => ['license_number', 'That license number is already registered to another driver.'],
                    'uq_drivers_default_unit' => ['unit_no', 'That tricycle unit is already assigned to another driver.'],
                    'uq_units_body' => ['unit_no', 'That tricycle unit number already exists.'],
                    'uq_units_plate' => ['unit_no', 'That tricycle unit number already exists.']];
            foreach ($map as $key => [$field, $message]) {
                if (str_contains($text, $key)) {
                    return ['ok' => false, 'errors' => [$field => $message], 'message' => null];
                }
            }
            return ['ok' => false, 'errors' => [], 'message' => 'Some of these details already exist in the system. Please check them and try again.'];
        }
        error_log('TRODDAC account error: ' . $e->getMessage());
        return ['ok' => false, 'errors' => [], 'message' => 'The account could not be saved. Please try again.'];
    }
}
