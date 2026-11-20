-- 006: triggers that keep Module A (bookings) and Module B (route assignments) conflict-free.
-- Set  SET @app_user_id = <users.id>;  on the connection so history rows record who acted.
--   TRD-E201 booking owner must be a passenger        TRD-E202 new bookings must start pending
--   TRD-E203 invalid status change                    TRD-E204 assignment needs driver, unit, operator
--   TRD-E205 assigner must be operator/officer        TRD-E206 driver/unit change only while 'assigned'
--   TRD-E301 driver profile needs a driver user       TRD-E302 cannot leave on_trip during a live trip
--   TRD-E303 on_trip is set only by a live booking    TRD-E304 user still has a driver profile
--   TRD-E409 finished assignments cannot change       TRD-E411 assigner must be operator/officer

DELIMITER $$

-- ---------------------------------------------------------------- users
DROP TRIGGER IF EXISTS trg_users_bu$$
CREATE TRIGGER trg_users_bu BEFORE UPDATE ON users FOR EACH ROW
BEGIN
    IF OLD.role = 'driver' AND NEW.role <> 'driver'
       AND EXISTS (SELECT 1 FROM drivers WHERE user_id = OLD.id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E304: Remove the driver profile before changing this user''s role';
    END IF;
END$$

-- ---------------------------------------------------------------- drivers
DROP TRIGGER IF EXISTS trg_drivers_bi$$
CREATE TRIGGER trg_drivers_bi BEFORE INSERT ON drivers FOR EACH ROW
BEGIN
    DECLARE v_role VARCHAR(12) DEFAULT NULL;
    SELECT role INTO v_role FROM users WHERE id = NEW.user_id;
    IF v_role IS NULL OR v_role <> 'driver' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E301: A driver profile must belong to a user with the driver role';
    END IF;
    IF NEW.status = 'on_trip' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E303: on_trip can only be set by a live passenger booking';
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_drivers_bu$$
CREATE TRIGGER trg_drivers_bu BEFORE UPDATE ON drivers FOR EACH ROW
BEGIN
    DECLARE v_role VARCHAR(12) DEFAULT NULL;

    IF NEW.user_id <> OLD.user_id THEN
        SELECT role INTO v_role FROM users WHERE id = NEW.user_id;
        IF v_role IS NULL OR v_role <> 'driver' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E301: A driver profile must belong to a user with the driver role';
        END IF;
    END IF;

    IF NEW.status <> OLD.status THEN
        IF OLD.status = 'on_trip' AND EXISTS (SELECT 1 FROM bookings
                                               WHERE driver_id = OLD.id
                                                 AND status IN ('assigned','en_route','in_progress')) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E302: Driver cannot change availability during a live passenger trip';
        END IF;
        IF NEW.status = 'on_trip' AND NOT EXISTS (SELECT 1 FROM bookings
                                                   WHERE driver_id = OLD.id
                                                     AND status IN ('assigned','en_route','in_progress')) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E303: on_trip can only be set by a live passenger booking';
        END IF;
    END IF;
END$$

-- ---------------------------------------------------------------- driver_locations
DROP TRIGGER IF EXISTS trg_driver_locations_ai$$
CREATE TRIGGER trg_driver_locations_ai AFTER INSERT ON driver_locations FOR EACH ROW
BEGIN
    UPDATE drivers
       SET last_latitude  = NEW.latitude,
           last_longitude = NEW.longitude,
           last_seen_at   = NEW.recorded_at
     WHERE id = NEW.driver_id
       AND (last_seen_at IS NULL OR last_seen_at <= NEW.recorded_at);
END$$

-- ---------------------------------------------------------------- bookings
DROP TRIGGER IF EXISTS trg_bookings_bi$$
CREATE TRIGGER trg_bookings_bi BEFORE INSERT ON bookings FOR EACH ROW
BEGIN
    DECLARE v_role   VARCHAR(12) DEFAULT NULL;
    DECLARE v_matrix INT UNSIGNED DEFAULT NULL;

    SELECT role INTO v_role FROM users WHERE id = NEW.passenger_id;
    IF v_role IS NULL OR v_role <> 'passenger' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E201: Bookings can only be made by a passenger account';
    END IF;

    IF NEW.status <> 'pending' OR NEW.driver_id IS NOT NULL OR NEW.unit_id IS NOT NULL OR NEW.assigned_by IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E202: A new booking must be pending with no driver or unit';
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

    -- The server is the authority on the estimated fare.
    SET NEW.estimated_fare = fn_calculate_fare(NEW.distance_km, NEW.fare_matrix_id);

    IF NEW.booking_code IS NULL OR NEW.booking_code = '' THEN
        SET NEW.booking_code = CONCAT('TRD', DATE_FORMAT(NOW(), '%y%m%d'), '-', UPPER(SUBSTRING(REPLACE(UUID(), '-', ''), 1, 6)));
    END IF;

    SET NEW.assigned_at = NULL, NEW.started_at = NULL, NEW.completed_at = NULL, NEW.cancelled_at = NULL, NEW.final_fare = NULL;
END$$

DROP TRIGGER IF EXISTS trg_bookings_bu$$
CREATE TRIGGER trg_bookings_bu BEFORE UPDATE ON bookings FOR EACH ROW
BEGIN
    DECLARE v_role   VARCHAR(12) DEFAULT NULL;
    DECLARE v_ok     TINYINT(1)  DEFAULT 1;
    DECLARE v_live   TINYINT(1)  DEFAULT 0;

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
            WHEN 'pending'     THEN NEW.status IN ('assigned','cancelled','rejected')
            WHEN 'assigned'    THEN NEW.status IN ('pending','en_route','in_progress','cancelled')
            WHEN 'en_route'    THEN NEW.status IN ('in_progress','cancelled')
            WHEN 'in_progress' THEN NEW.status = 'completed'
            ELSE 0 END;
        IF v_ok = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E203: That booking status change is not allowed';
        END IF;

        -- pending -> assigned: the operator picks a driver and a unit.
        IF OLD.status = 'pending' AND NEW.status = 'assigned' THEN
            IF NEW.driver_id IS NULL OR NEW.unit_id IS NULL OR NEW.assigned_by IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E204: Assigning needs a driver, a unit and the assigning operator';
            END IF;
            SELECT role INTO v_role FROM users WHERE id = NEW.assigned_by;
            IF v_role IS NULL OR v_role NOT IN ('operator','officer') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E205: Only an operator or officer can assign a booking';
            END IF;
            CALL sp_check_driver_for_booking(NEW.driver_id, OLD.id, NEW.est_duration_min);
            CALL sp_check_unit_for_booking(NEW.unit_id, OLD.id, NEW.est_duration_min);
            SET NEW.assigned_at = NOW();
        END IF;

        -- assigned -> pending: operator unassigns, booking goes back to the queue.
        IF NEW.status = 'pending' THEN
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
        -- Same status: only a re-assignment while 'assigned' may change driver/unit.
        IF OLD.status = 'pending' AND (NEW.driver_id IS NOT NULL OR NEW.unit_id IS NOT NULL) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E202: A pending booking cannot hold a driver or unit';
        END IF;
        IF v_live = 1 AND (NOT (NEW.driver_id <=> OLD.driver_id) OR NOT (NEW.unit_id <=> OLD.unit_id)) THEN
            IF OLD.status <> 'assigned' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E206: Driver or unit can only be changed while the booking is assigned';
            END IF;
            IF NEW.driver_id IS NULL OR NEW.unit_id IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E204: Re-assigning needs a driver and a unit';
            END IF;
            IF NOT (NEW.driver_id <=> OLD.driver_id) THEN
                CALL sp_check_driver_for_booking(NEW.driver_id, OLD.id, NEW.est_duration_min);
            END IF;
            IF NOT (NEW.unit_id <=> OLD.unit_id) THEN
                CALL sp_check_unit_for_booking(NEW.unit_id, OLD.id, NEW.est_duration_min);
            END IF;
            SET NEW.assigned_at = NOW();
        END IF;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_bookings_ai$$
CREATE TRIGGER trg_bookings_ai AFTER INSERT ON bookings FOR EACH ROW
BEGIN
    INSERT INTO booking_status_history (booking_id, old_status, new_status, old_driver_id, new_driver_id, changed_by)
    VALUES (NEW.id, NULL, NEW.status, NULL, NEW.driver_id, @app_user_id);
END$$

DROP TRIGGER IF EXISTS trg_bookings_au$$
CREATE TRIGGER trg_bookings_au AFTER UPDATE ON bookings FOR EACH ROW
BEGIN
    IF NOT (OLD.status <=> NEW.status) OR NOT (OLD.driver_id <=> NEW.driver_id) THEN
        INSERT INTO booking_status_history (booking_id, old_status, new_status, old_driver_id, new_driver_id, changed_by)
        VALUES (NEW.id, OLD.status, NEW.status, OLD.driver_id, NEW.driver_id, @app_user_id);
    END IF;

    -- Driver becomes on_trip while the booking is live ...
    IF NEW.status IN ('assigned','en_route','in_progress') AND NEW.driver_id IS NOT NULL THEN
        UPDATE drivers SET status = 'on_trip' WHERE id = NEW.driver_id AND status <> 'on_trip';
    END IF;

    -- ... and goes back to online when the trip ends or the driver is swapped out.
    IF OLD.status IN ('assigned','en_route','in_progress') AND OLD.driver_id IS NOT NULL
       AND (NEW.status NOT IN ('assigned','en_route','in_progress') OR NOT (OLD.driver_id <=> NEW.driver_id)) THEN
        UPDATE drivers SET status = 'online' WHERE id = OLD.driver_id AND status = 'on_trip';
    END IF;
END$$

-- ---------------------------------------------------------------- route_assignments
DROP TRIGGER IF EXISTS trg_route_assignments_bi$$
CREATE TRIGGER trg_route_assignments_bi BEFORE INSERT ON route_assignments FOR EACH ROW
BEGIN
    DECLARE v_role VARCHAR(12) DEFAULT NULL;

    IF NEW.source = 'officer' THEN
        SELECT role INTO v_role FROM users WHERE id = NEW.assigned_by;
        IF v_role IS NULL OR v_role NOT IN ('operator','officer') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E411: Manual assignments must name an operator or officer';
        END IF;
    END IF;

    IF NEW.status IN ('scheduled','active') THEN
        CALL sp_check_route_assignment(0, NEW.route_id, NEW.driver_id, NEW.unit_id, NEW.start_datetime, NEW.end_datetime);
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_route_assignments_bu$$
CREATE TRIGGER trg_route_assignments_bu BEFORE UPDATE ON route_assignments FOR EACH ROW
BEGIN
    IF OLD.status IN ('completed','cancelled','missed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E409: A finished assignment cannot be changed';
    END IF;

    IF NEW.status IN ('scheduled','active')
       AND (NOT (NEW.driver_id <=> OLD.driver_id) OR NOT (NEW.unit_id <=> OLD.unit_id)
            OR NEW.route_id <> OLD.route_id OR NEW.start_datetime <> OLD.start_datetime
            OR NEW.end_datetime <> OLD.end_datetime) THEN
        CALL sp_check_route_assignment(OLD.id, NEW.route_id, NEW.driver_id, NEW.unit_id, NEW.start_datetime, NEW.end_datetime);
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_route_assignments_ai$$
CREATE TRIGGER trg_route_assignments_ai AFTER INSERT ON route_assignments FOR EACH ROW
BEGIN
    INSERT INTO route_assignment_history (assignment_id, action, old_status, new_status, old_driver_id, new_driver_id, changed_by)
    VALUES (NEW.id, 'created', NULL, NEW.status, NULL, NEW.driver_id, COALESCE(@app_user_id, NEW.assigned_by));
END$$

DROP TRIGGER IF EXISTS trg_route_assignments_au$$
CREATE TRIGGER trg_route_assignments_au AFTER UPDATE ON route_assignments FOR EACH ROW
BEGIN
    IF NOT (OLD.driver_id <=> NEW.driver_id) THEN
        INSERT INTO route_assignment_history (assignment_id, action, old_status, new_status, old_driver_id, new_driver_id, changed_by)
        VALUES (NEW.id, 'reassigned', OLD.status, NEW.status, OLD.driver_id, NEW.driver_id, @app_user_id);
    ELSEIF OLD.status <> NEW.status THEN
        INSERT INTO route_assignment_history (assignment_id, action, old_status, new_status, old_driver_id, new_driver_id, changed_by)
        VALUES (NEW.id, 'status_changed', OLD.status, NEW.status, OLD.driver_id, NEW.driver_id, @app_user_id);
    ELSEIF NOT (OLD.unit_id <=> NEW.unit_id) OR OLD.start_datetime <> NEW.start_datetime OR OLD.end_datetime <> NEW.end_datetime THEN
        INSERT INTO route_assignment_history (assignment_id, action, old_status, new_status, old_driver_id, new_driver_id, changed_by)
        VALUES (NEW.id, 'updated', OLD.status, NEW.status, OLD.driver_id, NEW.driver_id, @app_user_id);
    END IF;
END$$

DELIMITER ;
