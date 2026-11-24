<?php
declare(strict_types=1);

/**
 * Open bookings and driver claims.
 *
 * A new booking is 'available'. Every online driver can see it and press Accept. The claim is ONE atomic
 * statement:  UPDATE bookings SET status='assigned', driver_id=? ... WHERE id=? AND status='available' AND driver_id IS NULL
 * InnoDB locks the row, so when two drivers press Accept at the same instant the second UPDATE waits, then finds
 * the booking already taken and changes nothing. That driver gets a clean "just taken" message.
 */
final class Bookings
{
    public const LIVE = ['assigned', 'en_route', 'in_progress'];
    public const OPEN = ['available', 'assigned', 'en_route', 'in_progress'];

    /** Open bookings nobody accepted in time are closed so passengers are not left waiting forever. */
    public static function expireStale(PDO $pdo): int
    {
        try {
            $minutes = max(1, (int)(appConfig()['booking_expiry_minutes'] ?? 15));
            $st = $pdo->prepare("UPDATE bookings SET status = 'rejected', cancel_reason = 'No driver accepted in time'
                                  WHERE status = 'available' AND driver_id IS NULL AND requested_at < DATE_SUB(NOW(), INTERVAL $minutes MINUTE)");
            $st->execute();
            return $st->rowCount();
        } catch (PDOException $e) {
            error_log('TRODDAC expireStale: ' . $e->getMessage());
            return 0;
        }
    }

    /** Why this driver cannot take passenger trips right now (null = they can). */
    public static function blockedReason(PDO $pdo, array $driver): ?string
    {
        if ((int)$driver['is_active'] !== 1) {
            return 'Your driver account is not active.';
        }
        if ($driver['status'] === 'offline') {
            return 'You are offline. Go online to see and accept bookings.';
        }
        if ($driver['status'] === 'on_trip') {
            return 'You are on a passenger trip. Finish it to see new bookings.';
        }
        if ($driver['default_unit_id'] === null) {
            return 'You have no tricycle unit. Ask the Association Officer to assign one.';
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM route_assignments WHERE driver_id = ? AND status IN ('scheduled','active')
                              AND start_datetime < DATE_ADD(NOW(), INTERVAL 40 MINUTE) AND end_datetime > NOW()");
        $st->execute([$driver['id']]);
        if ((int)$st->fetchColumn() > 0) {
            return 'You are on (or about to start) an official route. Passenger bookings resume when it ends.';
        }
        return null;
    }

    /** Bookings an online driver can accept, oldest first. Only first names are shown until a driver accepts. */
    public static function availableFor(PDO $pdo, array $driver, int $limit = 20): array
    {
        $minutes = max(1, (int)(appConfig()['booking_expiry_minutes'] ?? 15));
        $first = schemaHas('users', 'first_name') ? 'p.first_name' : "SUBSTRING_INDEX(p.full_name, ' ', 1)";
        $st = $pdo->prepare("SELECT b.id, b.booking_code, b.pickup_label, b.pickup_latitude, b.pickup_longitude, b.dest_label, b.dest_latitude, b.dest_longitude,
                                    b.distance_km, b.est_duration_min, b.estimated_fare, b.passenger_count, b.notes, b.requested_at,
                                    TIMESTAMPDIFF(MINUTE, b.requested_at, NOW()) AS waiting_min, $first AS passenger_first
                               FROM bookings b JOIN users p ON p.id = b.passenger_id
                              WHERE b.status = 'available' AND b.driver_id IS NULL AND b.requested_at >= DATE_SUB(NOW(), INTERVAL $minutes MINUTE)
                                AND NOT EXISTS (SELECT 1 FROM booking_declines x WHERE x.booking_id = b.id AND x.driver_id = ?)
                              ORDER BY b.requested_at ASC, b.id ASC LIMIT " . (int)$limit);
        $st->execute([$driver['id']]);
        $out = [];
        foreach ($st->fetchAll() as $b) {
            $away = null;
            if ($driver['last_latitude'] !== null) {
                $away = round(haversineKm((float)$driver['last_latitude'], (float)$driver['last_longitude'], (float)$b['pickup_latitude'], (float)$b['pickup_longitude']), 2);
            }
            $out[] = [
                'id'             => (int)$b['id'],
                'code'           => $b['booking_code'],
                'passenger'      => $b['passenger_first'],
                'pickup'         => ['label' => $b['pickup_label'], 'lat' => (float)$b['pickup_latitude'], 'lng' => (float)$b['pickup_longitude']],
                'dest'           => ['label' => $b['dest_label'], 'lat' => (float)$b['dest_latitude'], 'lng' => (float)$b['dest_longitude']],
                'distance_km'    => (float)$b['distance_km'],
                'est_minutes'    => (int)$b['est_duration_min'],
                'fare'           => (float)$b['estimated_fare'],
                'passengers'     => (int)$b['passenger_count'],
                'notes'          => $b['notes'],
                'waiting_min'    => (int)$b['waiting_min'],
                'away_km'        => $away,
            ];
        }
        return $out;
    }

    /**
     * A driver accepts a booking.
     * @return array{ok:bool, code:string, message:string, status:int}
     */
    public static function claim(PDO $pdo, int $driverId, int $driverUserId, int $bookingId): array
    {
        $fail = static fn(string $code, string $message, int $http = 409) => ['ok' => false, 'code' => $code, 'message' => $message, 'status' => $http];

        $st = $pdo->prepare('SELECT id, user_id, status, is_active, default_unit_id, last_latitude, last_longitude FROM drivers WHERE id = ? AND user_id = ?');
        $st->execute([$driverId, $driverUserId]);
        $driver = $st->fetch();
        if (!$driver) {
            return $fail('no_driver', 'Your driver profile was not found.', 404);
        }
        if ($bookingId <= 0) {
            return $fail('gone', 'That booking is no longer available.', 404);
        }
        // Pressing Accept twice (or a retry after a slow connection) must not turn into an error.
        $mine = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE id = ? AND driver_id = ? AND status IN ('assigned','en_route','in_progress')");
        $mine->execute([$bookingId, $driverId]);
        if ((int)$mine->fetchColumn() > 0) {
            return ['ok' => true, 'code' => 'already_yours', 'message' => 'You already accepted this booking.', 'status' => 200];
        }
        if ($reason = self::blockedReason($pdo, $driver)) {
            return $fail($driver['status'] === 'offline' ? 'offline' : 'blocked', $reason);
        }

        $pdo->prepare('SET @app_user_id = ?')->execute([$driverUserId]);
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                // The one atomic step: only the first driver can change a booking that is still available and unowned.
                $up = $pdo->prepare("UPDATE bookings SET status = 'assigned', driver_id = ?, unit_id = ?, assigned_by = ?
                                      WHERE id = ? AND status = 'available' AND driver_id IS NULL");
                $up->execute([$driverId, $driver['default_unit_id'], $driverUserId, $bookingId]);
                if ($up->rowCount() === 1) {
                    return ['ok' => true, 'code' => 'claimed', 'message' => 'Booking accepted.', 'status' => 200];
                }
                break;                                                  // 0 rows: somebody else got there first (or it was cancelled)
            } catch (PDOException $e) {
                $mysqlCode = (int)($e->errorInfo[1] ?? 0);
                if (in_array($mysqlCode, [1213, 1205], true) && $attempt === 1) {
                    usleep(random_int(20000, 80000));                   // deadlock / lock wait: try once more
                    continue;
                }
                if ($mysqlCode === 1062) {
                    return $fail('busy', 'You already have an active passenger trip.');
                }
                if (isSchemaError($e)) {
                    return $fail('schema', SCHEMA_HELP, 500);
                }
                $text = dbErrorMessage($e);
                return $fail('blocked', $text);
            }
        }

        // Not claimed: say why, in plain words.
        $st = $pdo->prepare('SELECT status, driver_id FROM bookings WHERE id = ?');
        $st->execute([$bookingId]);
        $b = $st->fetch();
        if (!$b) {
            return $fail('gone', 'That booking is no longer available.', 404);
        }
        if ((int)$b['driver_id'] === $driverId && in_array($b['status'], self::LIVE, true)) {
            return ['ok' => true, 'code' => 'already_yours', 'message' => 'You already accepted this booking.', 'status' => 200];
        }
        return match (true) {
            in_array($b['status'], self::LIVE, true) => $fail('taken', 'Sorry, another driver just accepted this booking.'),
            $b['status'] === 'cancelled'             => $fail('cancelled', 'The passenger cancelled this booking.'),
            default                                  => $fail('gone', 'That booking is no longer available.'),
        };
    }

    /** A driver hands an accepted booking back (before heading to the pickup). It becomes available for the other drivers. */
    public static function release(PDO $pdo, int $driverId, int $driverUserId, int $bookingId): array
    {
        try {
            $pdo->prepare('SET @app_user_id = ?')->execute([$driverUserId]);
            $pdo->beginTransaction();
            $up = $pdo->prepare("UPDATE bookings SET status = 'available' WHERE id = ? AND driver_id = ? AND status = 'assigned'");
            $up->execute([$bookingId, $driverId]);
            if ($up->rowCount() === 1) {
                $pdo->prepare('INSERT IGNORE INTO booking_declines (booking_id, driver_id) VALUES (?, ?)')->execute([$bookingId, $driverId]);
                $pdo->commit();
                return ['ok' => true, 'message' => 'Booking handed back. Other drivers can accept it now.'];
            }
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'A trip can only be handed back before you head to the pickup.'];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'message' => dbErrorMessage($e)];
        }
    }
}
