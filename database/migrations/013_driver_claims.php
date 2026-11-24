<?php
/**
 * 013: drivers claim bookings (no operator dispatch).
 *  - new bookings are 'available' (was 'pending'); existing pending bookings become available
 *  - only the driver themselves can move a booking from available to assigned
 *  - a driver may hand an assigned booking back (it becomes available again for the others)
 *  - once a driver has accepted, driver/unit cannot be swapped by anyone
 * Every step checks what already exists, so running it twice is safe.
 */
declare(strict_types=1);

return static function (PDO $pdo): void {
    $one = static function (string $sql, array $params = []) use ($pdo): mixed {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    };

    // ---- status values
    $type = (string)$one("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'bookings' AND column_name = 'status'");
    if (!str_contains($type, "'available'")) {
        $pdo->exec("ALTER TABLE bookings MODIFY status ENUM('pending','available','assigned','en_route','in_progress','completed','cancelled','rejected') NOT NULL DEFAULT 'available'");
    }
    $pdo->exec("UPDATE bookings SET status = 'available' WHERE status = 'pending'");

    // ---- an open booking has no driver yet; every other live status needs driver and unit
    if ((int)$one("SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'bookings' AND constraint_name = 'chk_bookings_assigned'") > 0) {
        $pdo->exec('ALTER TABLE bookings DROP CONSTRAINT chk_bookings_assigned');
    }
    $pdo->exec("ALTER TABLE bookings ADD CONSTRAINT chk_bookings_assigned
                CHECK (status IN ('pending','available','cancelled','rejected') OR (driver_id IS NOT NULL AND unit_id IS NOT NULL))");

    // ---- drivers a booking was handed back by (so it is not offered to them again)
    $pdo->exec('CREATE TABLE IF NOT EXISTS booking_declines (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        booking_id  BIGINT UNSIGNED NOT NULL,
        driver_id   INT UNSIGNED NOT NULL,
        created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_decline (booking_id, driver_id),
        CONSTRAINT fk_decline_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_decline_driver  FOREIGN KEY (driver_id)  REFERENCES drivers (id)  ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    // ---- triggers
    $pdo->exec('DROP TRIGGER IF EXISTS trg_bookings_bi');
    $pdo->exec("CREATE TRIGGER trg_bookings_bi BEFORE INSERT ON bookings FOR EACH ROW
BEGIN
    DECLARE v_role   VARCHAR(12) DEFAULT NULL;
    DECLARE v_matrix INT UNSIGNED DEFAULT NULL;

    SELECT role INTO v_role FROM users WHERE id = NEW.passenger_id;
    IF v_role IS NULL OR v_role <> 'passenger' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E201: Bookings can only be made by a passenger account';
    END IF;

    IF NEW.status <> 'available' OR NEW.driver_id IS NOT NULL OR NEW.unit_id IS NOT NULL OR NEW.assigned_by IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E202: A new booking must be available with no driver or unit';
    END IF;

    IF NEW.fare_matrix_id IS NULL THEN
        SELECT id INTO v_matrix FROM fare_matrices
         WHERE is_active = 1 AND effective_from <= CURDATE()
           AND (effective_to IS NULL OR effective_to >= CURDATE())
         LIMIT 1;
        SET NEW.fare_matrix_id = v_matrix;
    END IF;
    IF NEW.fare_matrix_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E207: No active fare matrix is configured';
    END IF;

    SET NEW.estimated_fare = fn_calculate_fare(NEW.distance_km, NEW.fare_matrix_id);

    IF NEW.booking_code IS NULL OR NEW.booking_code = '' THEN
        SET NEW.booking_code = CONCAT('TRD', DATE_FORMAT(NOW(), '%y%m%d'), '-', UPPER(SUBSTRING(REPLACE(UUID(), '-', ''), 1, 6)));
    END IF;

    SET NEW.assigned_at = NULL, NEW.started_at = NULL, NEW.completed_at = NULL, NEW.cancelled_at = NULL, NEW.final_fare = NULL;
END");

    $pdo->exec('DROP TRIGGER IF EXISTS trg_bookings_bu');
    $pdo->exec("CREATE TRIGGER trg_bookings_bu BEFORE UPDATE ON bookings FOR EACH ROW
BEGIN
    DECLARE v_ok          TINYINT(1) DEFAULT 1;
    DECLARE v_live        TINYINT(1) DEFAULT 0;
    DECLARE v_driver_user INT UNSIGNED DEFAULT NULL;

    -- These never change after the booking is made.
    SET NEW.passenger_id   = OLD.passenger_id;
    SET NEW.booking_code   = OLD.booking_code;
    SET NEW.distance_km    = OLD.distance_km;
    SET NEW.fare_matrix_id = OLD.fare_matrix_id;
    SET NEW.estimated_fare = OLD.estimated_fare;
    SET NEW.requested_at   = OLD.requested_at;

    SET v_live = (OLD.status IN ('assigned','en_route','in_progress'));

    IF NEW.status <> OLD.status THEN
        IF OLD.status IN ('completed','cancelled','rejected') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E203: A finished booking cannot change status';
        END IF;
        SET v_ok = CASE OLD.status
            WHEN 'available'   THEN NEW.status IN ('assigned','cancelled','rejected')
            WHEN 'assigned'    THEN NEW.status IN ('available','en_route','in_progress','cancelled')
            WHEN 'en_route'    THEN NEW.status IN ('in_progress','cancelled')
            WHEN 'in_progress' THEN NEW.status = 'completed'
            ELSE 0 END;
        IF v_ok = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E203: That booking status change is not allowed';
        END IF;

        -- available -> assigned: a driver accepts it for themselves.
        IF OLD.status = 'available' AND NEW.status = 'assigned' THEN
            IF NEW.driver_id IS NULL OR NEW.unit_id IS NULL OR NEW.assigned_by IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E204: Accepting a booking needs a driver and a tricycle unit';
            END IF;
            SELECT user_id INTO v_driver_user FROM drivers WHERE id = NEW.driver_id;
            IF v_driver_user IS NULL OR v_driver_user <> NEW.assigned_by THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E205: Only the driver themselves can accept a booking';
            END IF;
            CALL sp_check_driver_for_booking(NEW.driver_id, OLD.id, NEW.est_duration_min);
            CALL sp_check_unit_for_booking(NEW.unit_id, OLD.id, NEW.est_duration_min);
            SET NEW.assigned_at = NOW();
        END IF;

        -- assigned -> available: the driver hands it back for the other drivers.
        IF NEW.status = 'available' THEN
            SET NEW.driver_id = NULL, NEW.unit_id = NULL, NEW.assigned_by = NULL, NEW.assigned_at = NULL;
        END IF;

        IF NEW.status = 'in_progress' AND NEW.started_at IS NULL THEN
            SET NEW.started_at = NOW();
        END IF;
        IF NEW.status = 'completed' THEN
            SET NEW.completed_at = COALESCE(NEW.completed_at, NOW());
            SET NEW.final_fare   = COALESCE(NEW.final_fare, NEW.estimated_fare);
        END IF;
        IF NEW.status IN ('cancelled','rejected') THEN
            SET NEW.cancelled_at = COALESCE(NEW.cancelled_at, NOW());
        END IF;
    ELSE
        IF OLD.status = 'available' AND (NEW.driver_id IS NOT NULL OR NEW.unit_id IS NOT NULL) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E202: An available booking cannot hold a driver or unit';
        END IF;
        IF v_live = 1 AND (NOT (NEW.driver_id <=> OLD.driver_id) OR NOT (NEW.unit_id <=> OLD.unit_id)) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E206: The driver or unit cannot be changed once a driver has accepted the booking';
        END IF;
    END IF;
END");
};
