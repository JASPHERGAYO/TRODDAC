-- 010: automatic route assignment engine (Module B)
--   TRD-E412 an active assignment cannot go back to scheduled

ALTER TABLE driver_route_eligibility
    ADD COLUMN last_served_cycle INT UNSIGNED NULL AFTER skipped_count;

-- One row per engine run (who/what started it, which clock it used, what it did).
CREATE TABLE engine_runs (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    started_at       DATETIME NOT NULL,
    finished_at      DATETIME NULL,
    now_used         DATETIME NOT NULL,
    run_source       ENUM('cli','web','manual','test') NOT NULL DEFAULT 'web',
    triggered_by     INT UNSIGNED NULL,
    slots_checked    INT UNSIGNED NOT NULL DEFAULT 0,
    assigned_count   INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_count    INT UNSIGNED NOT NULL DEFAULT 0,
    unfilled_count   INT UNSIGNED NOT NULL DEFAULT 0,
    activated_count  INT UNSIGNED NOT NULL DEFAULT 0,
    completed_count  INT UNSIGNED NOT NULL DEFAULT 0,
    missed_count     INT UNSIGNED NOT NULL DEFAULT 0,
    error_message    VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_runs_started (started_at),
    CONSTRAINT fk_runs_user FOREIGN KEY (triggered_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Why the engine did what it did: every assignment, every skipped driver (with the reason), every unfilled slot.
CREATE TABLE engine_decisions (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id         BIGINT UNSIGNED NOT NULL,
    route_id       INT UNSIGNED NULL,
    slot_start     DATETIME NULL,
    driver_id      INT UNSIGNED NULL,
    decision       ENUM('assigned','skipped','unfilled','activated','completed','missed') NOT NULL,
    reason         VARCHAR(255) NULL,
    assignment_id  BIGINT UNSIGNED NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dec_run (run_id),
    KEY idx_dec_created (created_at),
    CONSTRAINT fk_dec_run    FOREIGN KEY (run_id) REFERENCES engine_runs (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_dec_route  FOREIGN KEY (route_id) REFERENCES routes (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_dec_driver FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_dec_assign FOREIGN KEY (assignment_id) REFERENCES route_assignments (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$

-- Starting a route (scheduled -> active) re-runs the full conflict check, so a driver who has
-- since taken a passenger trip, lost eligibility, etc. can never become active on the route.
DROP TRIGGER IF EXISTS trg_route_assignments_bu$$
CREATE TRIGGER trg_route_assignments_bu BEFORE UPDATE ON route_assignments FOR EACH ROW
BEGIN
    IF OLD.status IN ('completed','cancelled','missed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E409: A finished assignment cannot be changed';
    END IF;

    IF OLD.status = 'active' AND NEW.status = 'scheduled' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TRD-E412: An active assignment cannot go back to scheduled';
    END IF;

    IF NEW.status IN ('scheduled','active')
       AND (NOT (NEW.driver_id <=> OLD.driver_id) OR NOT (NEW.unit_id <=> OLD.unit_id)
            OR NEW.route_id <> OLD.route_id OR NEW.start_datetime <> OLD.start_datetime
            OR NEW.end_datetime <> OLD.end_datetime
            OR (OLD.status = 'scheduled' AND NEW.status = 'active')) THEN
        CALL sp_check_route_assignment(OLD.id, NEW.route_id, NEW.driver_id, NEW.unit_id, NEW.start_datetime, NEW.end_datetime);
    END IF;
END$$

DELIMITER ;
