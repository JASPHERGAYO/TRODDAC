<?php
/**
 * 011: first/middle/last names, nullable plate number, duplicate-prevention indexes.
 * Every step first checks what already exists, so running it twice (or on a partly updated
 * database) is safe. Unique indexes are only added when the existing data has no duplicates.
 */
declare(strict_types=1);

return static function (PDO $pdo): void {
    $one = static function (string $sql, array $params) use ($pdo): mixed {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    };
    $hasColumn = static fn(string $t, string $c): bool => (int)$one(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]) > 0;
    $hasIndex = static fn(string $t, string $i): bool => (int)$one(
        'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$t, $i]) > 0;

    // ---- users: name parts
    if (!$hasColumn('users', 'first_name')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN first_name VARCHAR(50) NOT NULL DEFAULT '' AFTER full_name");
    }
    if (!$hasColumn('users', 'last_name')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN last_name VARCHAR(50) NOT NULL DEFAULT '' AFTER first_name");
    }
    if (!$hasColumn('users', 'middle_initial')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN middle_initial CHAR(1) NOT NULL DEFAULT '' AFTER last_name");
    }
    // Fill the parts for accounts that only had a full name.
    $pdo->exec("UPDATE users
                   SET first_name = LEFT(SUBSTRING_INDEX(TRIM(full_name), ' ', 1), 50),
                       last_name  = LEFT(TRIM(SUBSTRING(TRIM(full_name), CHAR_LENGTH(SUBSTRING_INDEX(TRIM(full_name), ' ', 1)) + 1)), 50)
                 WHERE first_name = ''");
    $pdo->exec("UPDATE users SET phone = NULL WHERE phone = ''");
    if (!$hasIndex('users', 'idx_users_person')) {
        $pdo->exec('ALTER TABLE users ADD KEY idx_users_person (first_name, last_name, middle_initial)');
    }

    // ---- one phone number per account (only if today's data allows it)
    if (!$hasIndex('users', 'uq_users_phone')
        && (int)$one('SELECT COUNT(*) FROM (SELECT phone FROM users WHERE phone IS NOT NULL GROUP BY phone HAVING COUNT(*) > 1) d', []) === 0) {
        $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_phone (phone)');
    }

    // ---- tricycle units can be created from a unit number alone
    $nullable = (string)$one("SELECT is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tricycle_units' AND column_name = 'plate_number'", []);
    if ($nullable === 'NO') {
        $pdo->exec('ALTER TABLE tricycle_units MODIFY plate_number VARCHAR(20) NULL');
    }

    // ---- a unit is the default unit of one driver only
    if (!$hasIndex('drivers', 'uq_drivers_default_unit')
        && (int)$one('SELECT COUNT(*) FROM (SELECT default_unit_id FROM drivers WHERE default_unit_id IS NOT NULL GROUP BY default_unit_id HAVING COUNT(*) > 1) d', []) === 0) {
        $pdo->exec('ALTER TABLE drivers ADD UNIQUE KEY uq_drivers_default_unit (default_unit_id)');
    }

    // ---- views: show the unit number when a unit has no plate yet
    $pdo->exec("CREATE OR REPLACE VIEW v_driver_board AS
        SELECT d.id AS driver_id, u.full_name AS driver_name, d.status AS availability, d.is_active AS is_active,
               COALESCE(tu.plate_number, tu.body_number) AS default_unit_plate, d.last_latitude, d.last_longitude, d.last_seen_at,
               (SELECT b.booking_code FROM bookings b WHERE b.driver_id = d.id AND b.status IN ('assigned','en_route','in_progress') LIMIT 1) AS active_booking_code,
               (SELECT r.code FROM route_assignments ra JOIN routes r ON r.id = ra.route_id
                 WHERE ra.driver_id = d.id AND ra.status IN ('scheduled','active') AND ra.end_datetime > NOW() ORDER BY ra.start_datetime LIMIT 1) AS next_route_code,
               (SELECT ra.start_datetime FROM route_assignments ra
                 WHERE ra.driver_id = d.id AND ra.status IN ('scheduled','active') AND ra.end_datetime > NOW() ORDER BY ra.start_datetime LIMIT 1) AS next_route_start
          FROM drivers d JOIN users u ON u.id = d.user_id LEFT JOIN tricycle_units tu ON tu.id = d.default_unit_id");
    $pdo->exec("CREATE OR REPLACE VIEW v_assignment_overview AS
        SELECT ra.id AS assignment_id, r.code AS route_code, r.name AS route_name, ot.name AS origin_terminal, dt.name AS destination_terminal,
               ra.driver_id, u.full_name AS driver_name, COALESCE(tu.plate_number, tu.body_number) AS unit_plate,
               ra.start_datetime, ra.end_datetime, ra.status, ra.source, ra.rotation_cycle, ra.queue_position
          FROM route_assignments ra JOIN routes r ON r.id = ra.route_id JOIN terminals ot ON ot.id = r.origin_terminal_id
          JOIN terminals dt ON dt.id = r.destination_terminal_id JOIN drivers d ON d.id = ra.driver_id JOIN users u ON u.id = d.user_id
          LEFT JOIN tricycle_units tu ON tu.id = ra.unit_id");
    $pdo->exec("CREATE OR REPLACE VIEW v_active_trips AS
        SELECT b.id AS booking_id, b.booking_code, b.status, p.full_name AS passenger_name, b.pickup_label, b.dest_label, b.distance_km, b.estimated_fare,
               b.driver_id, du.full_name AS driver_name, COALESCE(tu.plate_number, tu.body_number) AS unit_plate,
               d.last_latitude, d.last_longitude, d.last_seen_at, b.assigned_at, b.started_at
          FROM bookings b JOIN users p ON p.id = b.passenger_id JOIN drivers d ON d.id = b.driver_id JOIN users du ON du.id = d.user_id
          JOIN tricycle_units tu ON tu.id = b.unit_id WHERE b.status IN ('assigned','en_route','in_progress')");
};
