-- 007: read-only views used by the dashboards

CREATE OR REPLACE VIEW v_driver_board AS
SELECT d.id            AS driver_id,
       u.full_name     AS driver_name,
       d.status        AS availability,
       d.is_active     AS is_active,
       tu.plate_number AS default_unit_plate,
       d.last_latitude,
       d.last_longitude,
       d.last_seen_at,
       (SELECT b.booking_code FROM bookings b
         WHERE b.driver_id = d.id AND b.status IN ('assigned','en_route','in_progress') LIMIT 1) AS active_booking_code,
       (SELECT r.code FROM route_assignments ra JOIN routes r ON r.id = ra.route_id
         WHERE ra.driver_id = d.id AND ra.status IN ('scheduled','active') AND ra.end_datetime > NOW()
         ORDER BY ra.start_datetime LIMIT 1) AS next_route_code,
       (SELECT ra.start_datetime FROM route_assignments ra
         WHERE ra.driver_id = d.id AND ra.status IN ('scheduled','active') AND ra.end_datetime > NOW()
         ORDER BY ra.start_datetime LIMIT 1) AS next_route_start
  FROM drivers d
  JOIN users u ON u.id = d.user_id
  LEFT JOIN tricycle_units tu ON tu.id = d.default_unit_id;

CREATE OR REPLACE VIEW v_assignment_overview AS
SELECT ra.id            AS assignment_id,
       r.code           AS route_code,
       r.name           AS route_name,
       ot.name          AS origin_terminal,
       dt.name          AS destination_terminal,
       ra.driver_id,
       u.full_name      AS driver_name,
       tu.plate_number  AS unit_plate,
       ra.start_datetime,
       ra.end_datetime,
       ra.status,
       ra.source,
       ra.rotation_cycle,
       ra.queue_position
  FROM route_assignments ra
  JOIN routes r      ON r.id = ra.route_id
  JOIN terminals ot  ON ot.id = r.origin_terminal_id
  JOIN terminals dt  ON dt.id = r.destination_terminal_id
  JOIN drivers d     ON d.id = ra.driver_id
  JOIN users u       ON u.id = d.user_id
  LEFT JOIN tricycle_units tu ON tu.id = ra.unit_id;

CREATE OR REPLACE VIEW v_active_trips AS
SELECT b.id AS booking_id, b.booking_code, b.status,
       p.full_name AS passenger_name,
       b.pickup_label, b.dest_label, b.distance_km, b.estimated_fare,
       b.driver_id, du.full_name AS driver_name,
       tu.plate_number AS unit_plate,
       d.last_latitude, d.last_longitude, d.last_seen_at,
       b.assigned_at, b.started_at
  FROM bookings b
  JOIN users p ON p.id = b.passenger_id
  JOIN drivers d ON d.id = b.driver_id
  JOIN users du ON du.id = d.user_id
  JOIN tricycle_units tu ON tu.id = b.unit_id
 WHERE b.status IN ('assigned','en_route','in_progress');
