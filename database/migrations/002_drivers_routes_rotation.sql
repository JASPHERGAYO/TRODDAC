-- 002: drivers, driver schedules, routes, route schedules, rotation settings,
--      driver eligibility / rotation queue, rotation pointers

CREATE TABLE drivers (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id          INT UNSIGNED NOT NULL,
    license_number   VARCHAR(30)  NOT NULL,
    license_expiry   DATE         NULL,
    default_unit_id  INT UNSIGNED NULL,
    status           ENUM('offline','online','on_trip') NOT NULL DEFAULT 'offline',
    last_latitude    DECIMAL(10,7) NULL,
    last_longitude   DECIMAL(10,7) NULL,
    last_seen_at     DATETIME     NULL,
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_drivers_user (user_id),
    UNIQUE KEY uq_drivers_license (license_number),
    KEY idx_drivers_status (status, is_active),
    CONSTRAINT fk_drivers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_drivers_unit FOREIGN KEY (default_unit_id) REFERENCES tricycle_units (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_drivers_lat CHECK (last_latitude  IS NULL OR last_latitude  BETWEEN -90  AND 90),
    CONSTRAINT chk_drivers_lng CHECK (last_longitude IS NULL OR last_longitude BETWEEN -180 AND 180)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Weekly windows in which a driver may be given official routes (1 = Monday ... 7 = Sunday).
-- A driver with NO active window is unrestricted; a driver with windows can only be
-- assigned inside them.
CREATE TABLE driver_schedules (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    driver_id    INT UNSIGNED NOT NULL,
    day_of_week  TINYINT UNSIGNED NOT NULL,
    start_time   TIME NOT NULL,
    end_time     TIME NOT NULL,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_driver_schedule (driver_id, day_of_week, start_time),
    CONSTRAINT fk_dsched_driver FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_dsched_day  CHECK (day_of_week BETWEEN 1 AND 7),
    CONSTRAINT chk_dsched_time CHECK (start_time < end_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE routes (
    id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code                     VARCHAR(20)  NOT NULL,
    name                     VARCHAR(120) NOT NULL,
    origin_terminal_id       INT UNSIGNED NOT NULL,
    destination_terminal_id  INT UNSIGNED NOT NULL,
    distance_km              DECIMAL(6,2) NOT NULL,
    est_duration_min         SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    path_json                LONGTEXT     NULL,
    is_active                TINYINT(1)   NOT NULL DEFAULT 1,
    created_by               INT UNSIGNED NULL,
    created_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_routes_code (code),
    KEY idx_routes_active (is_active),
    CONSTRAINT fk_routes_origin FOREIGN KEY (origin_terminal_id) REFERENCES terminals (id),
    CONSTRAINT fk_routes_dest   FOREIGN KEY (destination_terminal_id) REFERENCES terminals (id),
    CONSTRAINT fk_routes_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_routes_terminals CHECK (origin_terminal_id <> destination_terminal_id),
    CONSTRAINT chk_routes_distance  CHECK (distance_km > 0),
    CONSTRAINT chk_routes_path      CHECK (path_json IS NULL OR JSON_VALID(path_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- When an official route runs (1 = Monday ... 7 = Sunday) and how many drivers it needs.
CREATE TABLE route_schedules (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    route_id         INT UNSIGNED NOT NULL,
    day_of_week      TINYINT UNSIGNED NOT NULL,
    start_time       TIME NOT NULL,
    end_time         TIME NOT NULL,
    drivers_required TINYINT UNSIGNED NOT NULL DEFAULT 1,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_route_schedule (route_id, day_of_week, start_time),
    CONSTRAINT fk_rsched_route FOREIGN KEY (route_id) REFERENCES routes (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_rsched_day  CHECK (day_of_week BETWEEN 1 AND 7),
    CONSTRAINT chk_rsched_time CHECK (start_time < end_time),
    CONSTRAINT chk_rsched_need CHECK (drivers_required >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rotation rules, one row per route.
CREATE TABLE rotation_settings (
    route_id                        INT UNSIGNED NOT NULL,
    auto_assign_enabled             TINYINT(1)   NOT NULL DEFAULT 1,
    max_assignments_per_driver_day  TINYINT UNSIGNED NOT NULL DEFAULT 2,
    min_rest_minutes                SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    assignment_lead_minutes         SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    updated_by                      INT UNSIGNED NULL,
    updated_at                      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (route_id),
    CONSTRAINT fk_rset_route FOREIGN KEY (route_id) REFERENCES routes (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_rset_user  FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_rset_max  CHECK (max_assignments_per_driver_day >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which drivers may serve which route, and their place in that route's rotation queue.
CREATE TABLE driver_route_eligibility (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    driver_id         INT UNSIGNED NOT NULL,
    route_id          INT UNSIGNED NOT NULL,
    is_eligible       TINYINT(1)   NOT NULL DEFAULT 1,
    queue_position    INT UNSIGNED NOT NULL,
    last_assigned_at  DATETIME     NULL,
    total_assignments INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_count     INT UNSIGNED NOT NULL DEFAULT 0,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_eligibility (driver_id, route_id),
    UNIQUE KEY uq_eligibility_position (route_id, queue_position),
    CONSTRAINT fk_elig_driver FOREIGN KEY (driver_id) REFERENCES drivers (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_elig_route  FOREIGN KEY (route_id)  REFERENCES routes  (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_elig_position CHECK (queue_position >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rotation pointer: who was served last on each route and which pass through the queue we are on.
CREATE TABLE rotation_pointers (
    route_id                 INT UNSIGNED NOT NULL,
    last_assigned_driver_id  INT UNSIGNED NULL,
    last_queue_position      INT UNSIGNED NOT NULL DEFAULT 0,
    cycle_no                 INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (route_id),
    CONSTRAINT fk_rptr_route  FOREIGN KEY (route_id) REFERENCES routes (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_rptr_driver FOREIGN KEY (last_assigned_driver_id) REFERENCES drivers (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
