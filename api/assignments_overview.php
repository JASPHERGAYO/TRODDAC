<?php
// GET  -  read-only picture of Module B for operators and officers: assignments, engine runs and decisions.
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/api.php';
require __DIR__ . '/../lib/AssignmentEngine.php';
requireApiRole(['operator', 'officer'], false, true);
$pdo = db();
AssignmentEngine::runIfDue($pdo);

$stats = $pdo->query("SELECT
        SUM(status = 'active') AS active_now,
        SUM(status = 'scheduled' AND end_datetime > NOW()) AS upcoming,
        SUM(status = 'completed' AND assignment_date = CURDATE()) AS completed_today,
        SUM(status = 'missed' AND assignment_date = CURDATE()) AS missed_today
    FROM route_assignments")->fetch();

$rows = $pdo->query("SELECT ra.id, ra.status, ra.source, ra.start_datetime, ra.end_datetime, ra.rotation_cycle, ra.queue_position, ra.notes,
                            r.code, r.name AS route_name, ot.name AS origin, dt.name AS destination,
                            u.full_name AS driver_name, tu.plate_number
                       FROM route_assignments ra
                       JOIN routes r ON r.id = ra.route_id
                       JOIN terminals ot ON ot.id = r.origin_terminal_id
                       JOIN terminals dt ON dt.id = r.destination_terminal_id
                       JOIN drivers d ON d.id = ra.driver_id
                       JOIN users u ON u.id = d.user_id
                       LEFT JOIN tricycle_units tu ON tu.id = ra.unit_id
                      WHERE ra.assignment_date >= CURDATE() OR ra.status IN ('scheduled','active')
                      ORDER BY FIELD(ra.status, 'active', 'scheduled', 'completed', 'missed', 'cancelled'), ra.start_datetime
                      LIMIT 120")->fetchAll();

$runs = $pdo->query("SELECT id, started_at, now_used, run_source, assigned_count, skipped_count, unfilled_count, activated_count, completed_count, missed_count, error_message
                       FROM engine_runs ORDER BY id DESC LIMIT 5")->fetchAll();

$decisions = $pdo->query("SELECT x.created_at, x.decision, x.reason, x.slot_start, r.code, u.full_name AS driver_name
                            FROM engine_decisions x
                            LEFT JOIN routes r ON r.id = x.route_id
                            LEFT JOIN drivers d ON d.id = x.driver_id
                            LEFT JOIN users u ON u.id = d.user_id
                           WHERE x.decision IN ('assigned','skipped','unfilled','missed')
                           ORDER BY x.id DESC LIMIT 40")->fetchAll();

jsonOut(['ok' => true, 'stats' => array_map('intval', $stats), 'assignments' => $rows, 'runs' => $runs, 'decisions' => $decisions]);
