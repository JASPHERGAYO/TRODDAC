-- 004: route_assignments + audit history (Module B)

CREATE TABLE route_assignments (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    route_id          INT UNSIGNED NOT NULL,
    schedule_id       INT UNSIGNED NULL,
    driver_id         INT UNSIGNED NOT NULL,
    unit_id           INT UNSIGNED NULL,
    start_datetime    DATETIME NOT NULL,
    end_datetime      DATETIME NOT NULL,
    assignment_date   DATE GENERATED ALWAYS AS (DATE(start_datetime)) STORED,
    status            ENUM('scheduled','active','completed','cancelled','missed') NOT NULL DEFAULT 'scheduled',
    source            ENUM('system','officer') NOT NULL DEFAULT 'system',
    assigned_by       INT UNSIGNED NULL,
    rotation_cycle    INT UNSIGNED NULL,
    queue_position    INT UNSIGNED NULL,
    notes             VARCHAR(255) NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- The same driver cannot be put twice on the same route at the same start time.
    UNIQUE KEY uq_assignment_slot (route_id, driver_id, start_datetime),
    KEY idx_assign_driver_time (driver_id, start_datetime, end_datetime),
    KEY idx_assign_unit_time (unit_id, start_datetime, end_datetime),
    KEY idx_assign_route_date (route_id, assignment_date),
    KEY idx_assign_status (status),
    CONSTRAINT fk_assign_route    FOREIGN KEY (route_id)    REFERENCES routes (id),
    CONSTRAINT fk_assign_schedule FOREIGN KEY (schedule_id) REFERENCES route_schedules (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_assign_driver   FOREIGN KEY (driver_id)   REFERENCES drivers (id),
    CONSTRAINT fk_assign_unit     FOREIGN KEY (unit_id)     REFERENCES tricycle_units (id),
    CONSTRAINT fk_assign_user     FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_assign_time    CHECK (end_datetime > start_datetime),
    CONSTRAINT chk_assign_sameday CHECK (DATE(start_datetime) = DATE(end_datetime))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE route_assignment_history (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assignment_id  BIGINT UNSIGNED NOT NULL,
    action         ENUM('created','status_changed','reassigned','updated') NOT NULL,
    old_status     VARCHAR(20) NULL,
    new_status     VARCHAR(20) NOT NULL,
    old_driver_id  INT UNSIGNED NULL,
    new_driver_id  INT UNSIGNED NOT NULL,
    changed_by     INT UNSIGNED NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rah_assignment (assignment_id, created_at),
    CONSTRAINT fk_rah_assignment FOREIGN KEY (assignment_id) REFERENCES route_assignments (id),
    CONSTRAINT fk_rah_user       FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
