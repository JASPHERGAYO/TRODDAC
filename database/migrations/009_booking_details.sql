-- 009: extra booking details for the passenger booking screen (Phase 3)

ALTER TABLE bookings
    ADD COLUMN passenger_count TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER dest_longitude,
    ADD COLUMN notes           VARCHAR(255) NULL AFTER passenger_count,
    ADD COLUMN route_source    ENUM('road','estimate') NOT NULL DEFAULT 'road' AFTER distance_km,
    ADD COLUMN route_geometry  LONGTEXT NULL AFTER route_source,
    ADD CONSTRAINT chk_bookings_pax  CHECK (passenger_count BETWEEN 1 AND 6),
    ADD CONSTRAINT chk_bookings_geom CHECK (route_geometry IS NULL OR JSON_VALID(route_geometry));
