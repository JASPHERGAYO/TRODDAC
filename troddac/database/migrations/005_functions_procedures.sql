-- 005: fare function + conflict-prevention procedures.
-- Error codes raised by the database (SQLSTATE 45000, message starts with the code):
--   TRD-E101 driver unknown/inactive        TRD-E102 driver not online
--   TRD-E103 unit not active                TRD-E104 driver has route assignment in trip window
--   TRD-E105 unit has route assignment      TRD-E106 driver already in a passenger trip
--   TRD-E107 unit already in a passenger trip   TRD-E207 no usable fare matrix
--   TRD-E401 route inactive                 TRD-E402 driver inactive
--   TRD-E403 driver not eligible for route  TRD-E404 outside driver schedule
--   TRD-E405 driver overlapping assignment  TRD-E406 unit overlapping assignment
--   TRD-E407 driver in passenger trip       TRD-E408 unit in passenger trip
--   TRD-E410 unit not active

DELIMITER $$

DROP FUNCTION IF EXISTS fn_calculate_fare$$
CREATE FUNCTION fn_calculate_fare(p_distance_km DECIMAL(6,2), p_matrix_id INT UNSIGNED)
RETURNS DECIMAL(8,2)
READS SQL DATA
BEGIN
    DECLARE v_base    DECIMAL(8,2);
    DECLARE v_base_km DECIMAL(5,2);
    DECLARE v_rate    DECIMAL(8,2);
    DECLARE v_min     DECIMAL(8,2);

    SELECT base_fare, base_distance_km, per_km_rate, minimum_fare
      INTO v_base, v_base_km, v_rate, v_min
      FROM fare_matrices
     WHERE id = p_matrix_id;

    IF v_base IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E207: Fare matrix not found';
    END IF;

    RETURN ROUND(GREATEST(v_min, v_base + GREATEST(p_distance_km - v_base_km, 0) * v_rate), 2);
END$$

-- Can this driver take a passenger trip right now?
DROP PROCEDURE IF EXISTS sp_check_driver_for_booking$$
CREATE PROCEDURE sp_check_driver_for_booking(
    IN p_driver_id  INT UNSIGNED,
    IN p_booking_id BIGINT UNSIGNED,
    IN p_est_min    SMALLINT UNSIGNED)
BEGIN
    DECLARE v_active TINYINT(1) DEFAULT NULL;
    DECLARE v_status VARCHAR(10) DEFAULT NULL;
    DECLARE v_from   DATETIME;
    DECLARE v_to     DATETIME;

    SET v_from = NOW();
    SET v_to   = DATE_ADD(NOW(), INTERVAL (p_est_min + 10) MINUTE);

    SELECT is_active, status INTO v_active, v_status FROM drivers WHERE id = p_driver_id;

    IF v_active IS NULL OR v_active = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E101: Driver not found or inactive';
    END IF;
    IF v_status <> 'online' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E102: Driver is not online and available';
    END IF;
    IF EXISTS (SELECT 1 FROM bookings
                WHERE driver_id = p_driver_id AND id <> p_booking_id
                  AND status IN ('assigned','en_route','in_progress')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E106: Driver already has an active passenger trip';
    END IF;
    IF EXISTS (SELECT 1 FROM route_assignments
                WHERE driver_id = p_driver_id AND status IN ('scheduled','active')
                  AND start_datetime < v_to AND end_datetime > v_from) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E104: Driver has an official route assignment that conflicts with this trip';
    END IF;
END$$

-- Can this tricycle unit take a passenger trip right now?
DROP PROCEDURE IF EXISTS sp_check_unit_for_booking$$
CREATE PROCEDURE sp_check_unit_for_booking(
    IN p_unit_id    INT UNSIGNED,
    IN p_booking_id BIGINT UNSIGNED,
    IN p_est_min    SMALLINT UNSIGNED)
BEGIN
    DECLARE v_status VARCHAR(12) DEFAULT NULL;
    DECLARE v_from   DATETIME;
    DECLARE v_to     DATETIME;

    SET v_from = NOW();
    SET v_to   = DATE_ADD(NOW(), INTERVAL (p_est_min + 10) MINUTE);

    SELECT status INTO v_status FROM tricycle_units WHERE id = p_unit_id;

    IF v_status IS NULL OR v_status <> 'active' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E103: Tricycle unit not found or not active';
    END IF;
    IF EXISTS (SELECT 1 FROM bookings
                WHERE unit_id = p_unit_id AND id <> p_booking_id
                  AND status IN ('assigned','en_route','in_progress')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E107: Unit already has an active passenger trip';
    END IF;
    IF EXISTS (SELECT 1 FROM route_assignments
                WHERE unit_id = p_unit_id AND status IN ('scheduled','active')
                  AND start_datetime < v_to AND end_datetime > v_from) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E105: Unit has an official route assignment that conflicts with this trip';
    END IF;
END$$

-- Can this driver (and unit) be given this official route assignment?
-- p_assignment_id = 0 for a new row, otherwise the row being changed (excluded from overlap checks).
DROP PROCEDURE IF EXISTS sp_check_route_assignment$$
CREATE PROCEDURE sp_check_route_assignment(
    IN p_assignment_id BIGINT UNSIGNED,
    IN p_route_id      INT UNSIGNED,
    IN p_driver_id     INT UNSIGNED,
    IN p_unit_id       INT UNSIGNED,
    IN p_start         DATETIME,
    IN p_end           DATETIME)
BEGIN
    DECLARE v_route_active  TINYINT(1) DEFAULT NULL;
    DECLARE v_driver_active TINYINT(1) DEFAULT NULL;
    DECLARE v_unit_status   VARCHAR(12) DEFAULT NULL;
    DECLARE v_windows       INT DEFAULT 0;
    DECLARE v_covering      INT DEFAULT 0;
    DECLARE v_dow           TINYINT UNSIGNED;

    SELECT is_active INTO v_route_active FROM routes WHERE id = p_route_id;
    IF v_route_active IS NULL OR v_route_active = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E401: Route not found or inactive';
    END IF;

    SELECT is_active INTO v_driver_active FROM drivers WHERE id = p_driver_id;
    IF v_driver_active IS NULL OR v_driver_active = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E402: Driver not found or inactive';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM driver_route_eligibility
                    WHERE driver_id = p_driver_id AND route_id = p_route_id AND is_eligible = 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E403: Driver is not eligible for this route';
    END IF;

    -- Schedule restriction: only enforced when the driver has defined windows.
    SELECT COUNT(*) INTO v_windows FROM driver_schedules WHERE driver_id = p_driver_id AND is_active = 1;
    IF v_windows > 0 THEN
        SET v_dow = WEEKDAY(p_start) + 1;
        SELECT COUNT(*) INTO v_covering FROM driver_schedules
         WHERE driver_id = p_driver_id AND is_active = 1 AND day_of_week = v_dow
           AND start_time <= TIME(p_start) AND end_time >= TIME(p_end);
        IF v_covering = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E404: Assignment is outside the driver schedule';
        END IF;
    END IF;

    IF EXISTS (SELECT 1 FROM route_assignments
                WHERE driver_id = p_driver_id AND id <> p_assignment_id
                  AND status IN ('scheduled','active')
                  AND start_datetime < p_end AND end_datetime > p_start) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E405: Driver already has an overlapping route assignment';
    END IF;

    -- A live passenger trip (window = trip start .. estimated end + 10 min, never shorter than now + 10 min).
    IF EXISTS (SELECT 1 FROM bookings b
                WHERE b.driver_id = p_driver_id
                  AND b.status IN ('assigned','en_route','in_progress')
                  AND COALESCE(b.assigned_at, b.requested_at) < p_end
                  AND GREATEST(DATE_ADD(COALESCE(b.assigned_at, b.requested_at), INTERVAL (b.est_duration_min + 10) MINUTE),
                               DATE_ADD(NOW(), INTERVAL 10 MINUTE)) > p_start) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E407: Driver is handling a passenger trip that conflicts with this assignment';
    END IF;

    IF p_unit_id IS NOT NULL THEN
        SELECT status INTO v_unit_status FROM tricycle_units WHERE id = p_unit_id;
        IF v_unit_status IS NULL OR v_unit_status <> 'active' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E410: Tricycle unit not found or not active';
        END IF;
        IF EXISTS (SELECT 1 FROM route_assignments
                    WHERE unit_id = p_unit_id AND id <> p_assignment_id
                      AND status IN ('scheduled','active')
                      AND start_datetime < p_end AND end_datetime > p_start) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E406: Unit already has an overlapping route assignment';
        END IF;
        IF EXISTS (SELECT 1 FROM bookings b
                    WHERE b.unit_id = p_unit_id
                      AND b.status IN ('assigned','en_route','in_progress')
                      AND COALESCE(b.assigned_at, b.requested_at) < p_end
                      AND GREATEST(DATE_ADD(COALESCE(b.assigned_at, b.requested_at), INTERVAL (b.est_duration_min + 10) MINUTE),
                                   DATE_ADD(NOW(), INTERVAL 10 MINUTE)) > p_start) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E408: Unit is in a passenger trip that conflicts with this assignment';
        END IF;
    END IF;
END$$

DELIMITER ;
