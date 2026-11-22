<?php
declare(strict_types=1);

/** Insert sample data. Terminal coordinates are approximate sample values: edit them in the Officer dashboard. */
function runSeed(PDO $pdo): void
{
    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
        echo 'Seed skipped: users already exist.' . PHP_EOL;
        return;
    }

    $pdo->beginTransaction();
    try {
        // ---- users: 1 officer, 1 operator, 4 drivers, 2 passengers
        $users = [
            ['officer1',   'officer@troddac.test',    'officer123',    'Association Officer', '09170000001', 'officer'],
            ['operator1',  'operator@troddac.test',   'operator123',   'Dispatch Operator',   '09170000002', 'operator'],
            ['driver1',    'driver1@troddac.test',    'driver123',     'Driver One',          '09170000011', 'driver'],
            ['driver2',    'driver2@troddac.test',    'driver123',     'Driver Two',          '09170000012', 'driver'],
            ['driver3',    'driver3@troddac.test',    'driver123',     'Driver Three',        '09170000013', 'driver'],
            ['driver4',    'driver4@troddac.test',    'driver123',     'Driver Four',         '09170000014', 'driver'],
            ['passenger1', 'passenger1@troddac.test', 'passenger123',  'Passenger One',       '09170000021', 'passenger'],
            ['passenger2', 'passenger2@troddac.test', 'passenger123',  'Passenger Two',       '09170000022', 'passenger'],
        ];
        $ins = $pdo->prepare('INSERT INTO users (username, email, password, full_name, first_name, last_name, phone, role) VALUES (?,?,?,?,?,?,?,?)');
        $ids = [];
        foreach ($users as [$u, $e, $p, $n, $ph, $r]) {
            [$first, $last] = array_pad(explode(' ', $n, 2), 2, '');
            $ins->execute([$u, $e, password_hash($p, PASSWORD_DEFAULT), $n, $first, $last, $ph, $r]);
            $ids[$u] = (int)$pdo->lastInsertId();
        }

        // ---- terminals
        $t = $pdo->prepare('INSERT INTO terminals (name, address, latitude, longitude) VALUES (?,?,?,?)');
        $terminals = [
            'Poblacion Terminal' => ['Poblacion, Dasmariñas City',  14.3294000, 120.9367000],
            'Salawag Terminal'   => ['Salawag, Dasmariñas City',    14.3400000, 120.9700000],
            'Paliparan Terminal' => ['Paliparan, Dasmariñas City',  14.3150000, 120.9950000],
            'Burol Terminal'     => ['Burol, Dasmariñas City',      14.3010000, 120.9500000],
        ];
        $tid = [];
        foreach ($terminals as $name => [$addr, $lat, $lng]) {
            $t->execute([$name, $addr, $lat, $lng]);
            $tid[$name] = (int)$pdo->lastInsertId();
        }

        // ---- tricycle units
        $u = $pdo->prepare('INSERT INTO tricycle_units (plate_number, body_number, make_model, color, capacity) VALUES (?,?,?,?,3)');
        $unitIds = [];
        foreach ([['TRC-0001','B-001'], ['TRC-0002','B-002'], ['TRC-0003','B-003'], ['TRC-0004','B-004']] as $i => [$plate, $body]) {
            $u->execute([$plate, $body, 'Honda TMX 155 sidecar', ['Blue','Red','Green','Yellow'][$i]]);
            $unitIds[] = (int)$pdo->lastInsertId();
        }

        // ---- drivers (+ weekly availability Mon-Sat 05:00-21:00)
        $d  = $pdo->prepare('INSERT INTO drivers (user_id, license_number, license_expiry, default_unit_id) VALUES (?,?,?,?)');
        $ds = $pdo->prepare('INSERT INTO driver_schedules (driver_id, day_of_week, start_time, end_time) VALUES (?,?,?,?)');
        $driverIds = [];
        foreach (['driver1','driver2','driver3','driver4'] as $i => $uname) {
            $d->execute([$ids[$uname], 'N01-23-00000' . ($i + 1), '2028-12-31', $unitIds[$i]]);
            $driverIds[] = (int)$pdo->lastInsertId();
            for ($day = 1; $day <= 6; $day++) {
                $ds->execute([$driverIds[$i], $day, '05:00:00', '21:00:00']);
            }
        }

        // ---- fare matrix (the single active one)
        $pdo->prepare('INSERT INTO fare_matrices (name, base_fare, base_distance_km, per_km_rate, minimum_fare, effective_from, is_active, created_by)
                       VALUES (?,?,?,?,?,?,1,?)')
            ->execute(['Standard tricycle fare', 25.00, 1.00, 10.00, 25.00, date('Y-01-01'), $ids['officer1']]);

        // ---- routes
        $r = $pdo->prepare('INSERT INTO routes (code, name, origin_terminal_id, destination_terminal_id, distance_km, est_duration_min, created_by)
                            VALUES (?,?,?,?,?,?,?)');
        $routeIds = [];
        foreach ([
            ['R1', 'Poblacion - Salawag',   'Poblacion Terminal', 'Salawag Terminal',   4.50, 15],
            ['R2', 'Poblacion - Paliparan', 'Poblacion Terminal', 'Paliparan Terminal', 6.20, 20],
        ] as [$code, $name, $from, $to, $km, $min]) {
            $r->execute([$code, $name, $tid[$from], $tid[$to], $km, $min, $ids['officer1']]);
            $routeIds[$code] = (int)$pdo->lastInsertId();
        }

        // ---- route schedules, rotation settings and pointers
        $rs = $pdo->prepare('INSERT INTO route_schedules (route_id, day_of_week, start_time, end_time, drivers_required) VALUES (?,?,?,?,1)');
        foreach ($routeIds as $code => $rid) {
            $windows = $code === 'R1' ? [['06:00:00','10:00:00'], ['16:00:00','20:00:00']] : [['07:00:00','11:00:00']];
            for ($day = 1; $day <= 6; $day++) {
                foreach ($windows as [$from, $to]) {
                    $rs->execute([$rid, $day, $from, $to]);
                }
            }
            $pdo->prepare('INSERT INTO rotation_settings (route_id, updated_by) VALUES (?,?)')->execute([$rid, $ids['officer1']]);
            $pdo->prepare('INSERT INTO rotation_pointers (route_id) VALUES (?)')->execute([$rid]);
        }

        // ---- eligibility / rotation queue: all four drivers on R1, first three on R2
        $el = $pdo->prepare('INSERT INTO driver_route_eligibility (driver_id, route_id, queue_position) VALUES (?,?,?)');
        foreach ($driverIds as $pos => $did) {
            $el->execute([$did, $routeIds['R1'], $pos + 1]);
        }
        foreach (array_slice($driverIds, 0, 3) as $pos => $did) {
            $el->execute([$did, $routeIds['R2'], $pos + 1]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    echo 'Seed complete. Sample logins:' . PHP_EOL;
    echo '  officer1 / officer123, operator1 / operator123, driver1..4 / driver123, passenger1..2 / passenger123' . PHP_EOL;
}
