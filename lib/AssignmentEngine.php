<?php
declare(strict_types=1);

/**
 * Module B: automatic driver route assignment.
 *
 * Rotation-based assignment with availability and schedule restrictions:
 *  - Each route has a rotation queue (driver_route_eligibility.queue_position).
 *  - A "cycle" is one pass through the queue. Within a cycle every eligible driver is served once, in queue
 *    order. A driver who is skipped (outside their schedule, resting, on a passenger trip...) keeps their
 *    place and is served first once they are available again.
 *  - When nobody left in the cycle is available, the next cycle starts so the route still gets covered.
 *  - The database has the last word: its triggers refuse any assignment that overlaps a passenger trip,
 *    another assignment, the driver's schedule or their eligibility, so no code path can create a conflict.
 *
 * The engine also moves assignments through their life: scheduled -> active -> completed (or missed).
 */
final class AssignmentEngine
{
    private const LOCK = 'troddac_assignment_engine';
    private const IMMINENT_MINUTES = 10;        // an offline driver is not given a route starting this soon
    private const TRIP_LOOKAHEAD_MINUTES = 30;  // a driver on a passenger trip is not given a route starting this soon

    private int $runId = 0;
    private int $lastAssignmentId = 0;
    private array $stats = [];

    public function __construct(private PDO $pdo, private string $source = 'web', private ?int $userId = null)
    {
    }

    /** Runs the engine only if it has not run in the last $minSeconds. Never throws (safe to call from page requests). */
    public static function runIfDue(PDO $pdo, int $minSeconds = 60): ?array
    {
        try {
            $last = $pdo->query("SELECT TIMESTAMPDIFF(SECOND, MAX(started_at), NOW()) FROM engine_runs WHERE run_source IN ('web','cli','manual')")->fetchColumn();
            if ($last !== null && $last !== false && (int)$last < $minSeconds) {
                return null;
            }
            return (new self($pdo, 'web'))->run();
        } catch (Throwable $e) {
            error_log('TRODDAC engine: ' . $e->getMessage());
            return null;
        }
    }

    /** @param string|null $now 'Y-m-d H:i:s' to simulate a time; null uses the database clock. */
    public function run(?string $now = null, ?int $onlyRouteId = null): array
    {
        if ((int)$this->pdo->query("SELECT GET_LOCK('" . self::LOCK . "', 0)")->fetchColumn() !== 1) {
            return ['ok' => true, 'locked' => true, 'message' => 'Another run is in progress.'];
        }
        $this->stats = ['slots' => 0, 'assigned' => 0, 'skipped' => 0, 'unfilled' => 0, 'activated' => 0, 'completed' => 0, 'missed' => 0];
        try {
            $clock = new DateTimeImmutable($now ?? (string)$this->pdo->query('SELECT NOW()')->fetchColumn());
            $this->pdo->prepare('SET @app_user_id = ?')->execute([$this->userId]);
            $this->pdo->prepare('INSERT INTO engine_runs (started_at, now_used, run_source, triggered_by) VALUES (NOW(), ?, ?, ?)')
                ->execute([$clock->format('Y-m-d H:i:s'), $this->source, $this->userId]);
            $this->runId = (int)$this->pdo->lastInsertId();

            $this->completeAndMiss($clock);
            $this->activateDue($clock);
            $this->fillSlots($clock, $onlyRouteId);
            $this->activateDue($clock);          // slots filled late (start already passed) can start right away

            $this->finish(null);
            return ['ok' => true, 'locked' => false, 'run_id' => $this->runId, 'now' => $clock->format('Y-m-d H:i:s')] + $this->stats;
        } catch (Throwable $e) {
            error_log('TRODDAC engine error: ' . $e->getMessage());
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->finish(substr($e->getMessage(), 0, 250));
            return ['ok' => false, 'error' => 'The assignment engine hit an error. See the PHP error log.'] + $this->stats;
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK . "')")->fetchColumn();
        }
    }

    /* ------------------------------------------------------------------ lifecycle */

    private function completeAndMiss(DateTimeImmutable $now): void
    {
        $n = $now->format('Y-m-d H:i:s');

        $due = $this->rows("SELECT id, route_id, driver_id, start_datetime FROM route_assignments WHERE status = 'active' AND end_datetime <= ?", [$n]);
        foreach ($due as $a) {
            $this->pdo->prepare("UPDATE route_assignments SET status = 'completed' WHERE id = ? AND status = 'active'")->execute([$a['id']]);
            $this->stats['completed']++;
            $this->log('completed', (int)$a['route_id'], $a['start_datetime'], (int)$a['driver_id'], 'Route finished', (int)$a['id']);
        }

        $never = $this->rows("SELECT id, route_id, driver_id, start_datetime FROM route_assignments WHERE status = 'scheduled' AND end_datetime <= ?", [$n]);
        foreach ($never as $a) {
            $this->miss($a, 'The slot ended before the route was started');
        }
    }

    private function activateDue(DateTimeImmutable $now): void
    {
        $n = $now->format('Y-m-d H:i:s');
        $due = $this->rows("SELECT a.id, a.route_id, a.driver_id, a.start_datetime, d.status AS driver_status
                              FROM route_assignments a JOIN drivers d ON d.id = a.driver_id
                             WHERE a.status = 'scheduled' AND a.start_datetime <= ? AND a.end_datetime > ?", [$n, $n]);
        foreach ($due as $a) {
            if ($a['driver_status'] === 'on_trip') {
                $this->miss($a, 'The driver was on a passenger trip when the route started');
                continue;
            }
            try {
                $this->pdo->prepare("UPDATE route_assignments SET status = 'active' WHERE id = ? AND status = 'scheduled'")->execute([$a['id']]);
                $this->stats['activated']++;
                $this->log('activated', (int)$a['route_id'], $a['start_datetime'], (int)$a['driver_id'], 'Route started', (int)$a['id']);
            } catch (PDOException $e) {
                $this->miss($a, $this->reason($e));
            }
        }
    }

    private function miss(array $a, string $reason): void
    {
        $this->pdo->prepare("UPDATE route_assignments SET status = 'missed', notes = ? WHERE id = ? AND status = 'scheduled'")
            ->execute([substr($reason, 0, 250), $a['id']]);
        $this->stats['missed']++;
        $this->log('missed', (int)$a['route_id'], $a['start_datetime'], (int)$a['driver_id'], $reason, (int)$a['id']);
    }

    /* ------------------------------------------------------------------ slot filling */

    private function fillSlots(DateTimeImmutable $now, ?int $onlyRouteId): void
    {
        $sql = 'SELECT r.id, r.code,
                       COALESCE(s.auto_assign_enabled, 1)            AS auto_on,
                       COALESCE(s.max_assignments_per_driver_day, 2) AS max_day,
                       COALESCE(s.min_rest_minutes, 30)              AS rest_min,
                       COALESCE(s.assignment_lead_minutes, 60)       AS lead_min
                  FROM routes r LEFT JOIN rotation_settings s ON s.route_id = r.id
                 WHERE r.is_active = 1' . ($onlyRouteId ? ' AND r.id = ?' : '') . ' ORDER BY r.id';
        $routeParams = $onlyRouteId ? [$onlyRouteId] : [];

        foreach ($this->rows($sql, $routeParams) as $route) {
            if ((int)$route['auto_on'] !== 1) {
                continue;
            }
            $horizon = $now->modify('+' . (int)$route['lead_min'] . ' minutes');
            for ($day = $now->setTime(0, 0); $day <= $horizon; $day = $day->modify('+1 day')) {
                $schedules = $this->rows('SELECT id, start_time, end_time, drivers_required FROM route_schedules
                                           WHERE route_id = ? AND day_of_week = ? AND is_active = 1 ORDER BY start_time',
                                         [$route['id'], (int)$day->format('N')]);
                foreach ($schedules as $sch) {
                    $start = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $sch['start_time']);
                    $end   = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $sch['end_time']);
                    if ($end <= $now || $start > $horizon) {
                        continue;
                    }
                    $this->stats['slots']++;
                    $have = (int)$this->value("SELECT COUNT(*) FROM route_assignments WHERE route_id = ? AND start_datetime = ?
                                                AND status IN ('scheduled','active','completed')", [$route['id'], $start->format('Y-m-d H:i:s')]);
                    for ($need = (int)$sch['drivers_required'] - $have; $need > 0; $need--) {
                        if (!$this->assignOne($route, $sch, $start, $end, $now)) {
                            break;
                        }
                    }
                }
            }
        }
    }

    /** Gives one more driver to the slot. Returns false when nobody can take it. */
    private function assignOne(array $route, array $sch, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $now): bool
    {
        $routeId = (int)$route['id'];
        $slot    = $start->format('Y-m-d H:i:s');

        $queue = $this->rows('SELECT e.id AS elig_id, e.driver_id, e.queue_position, e.last_served_cycle,
                                     d.status AS driver_status, d.is_active AS driver_active, d.default_unit_id, u.is_active AS user_active
                                FROM driver_route_eligibility e
                                JOIN drivers d ON d.id = e.driver_id
                                JOIN users u ON u.id = d.user_id
                               WHERE e.route_id = ? AND e.is_eligible = 1
                               ORDER BY e.queue_position', [$routeId]);
        if (!$queue) {
            $this->unfilled($routeId, $slot, 'No eligible drivers are set up for this route');
            return false;
        }

        $this->pdo->prepare('INSERT IGNORE INTO rotation_pointers (route_id) VALUES (?)')->execute([$routeId]);
        $cycle = (int)$this->value('SELECT cycle_no FROM rotation_pointers WHERE route_id = ?', [$routeId]);

        $waiting = array_values(array_filter($queue, static fn($q) => $q['last_served_cycle'] === null || (int)$q['last_served_cycle'] < $cycle));
        $waitingIds = array_column($waiting, 'driver_id');
        $already = array_values(array_filter($queue, static fn($q) => !in_array($q['driver_id'], $waitingIds, true)));

        // Drivers still waiting for their turn this cycle go first; if none of them can work, start the next cycle.
        $passes = $waiting ? [[$cycle, $waiting], [$cycle + 1, $already]] : [[$cycle + 1, $queue]];

        $lastReason = 'Nobody could take it';
        foreach ($passes as [$useCycle, $list]) {
            foreach ($list as $cand) {
                $exists = (int)$this->value('SELECT COUNT(*) FROM route_assignments WHERE route_id = ? AND driver_id = ? AND start_datetime = ?',
                                            [$routeId, $cand['driver_id'], $slot]);
                if ($exists > 0) {
                    continue;                                   // this driver already had (and lost) this exact slot
                }
                $reason = $this->tryAssign($cand, $route, $sch, $start, $end, $now, $useCycle);
                if ($reason === null) {
                    $this->stats['assigned']++;
                    $this->log('assigned', $routeId, $slot, (int)$cand['driver_id'],
                               'Queue position ' . $cand['queue_position'] . ', cycle ' . $useCycle, $this->lastAssignmentId);
                    return true;
                }
                $lastReason = $reason;
                $this->stats['skipped']++;
                $this->pdo->prepare('UPDATE driver_route_eligibility SET skipped_count = skipped_count + 1 WHERE id = ?')->execute([$cand['elig_id']]);
                $this->log('skipped', $routeId, $slot, (int)$cand['driver_id'], $reason);
            }
        }
        $this->unfilled($routeId, $slot, 'No driver available (last reason: ' . $lastReason . ')');
        return false;
    }

    /** Returns null on success, otherwise the reason this driver cannot take the slot. */
    private function tryAssign(array $cand, array $route, array $sch, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $now, int $useCycle): ?string
    {
        $driverId = (int)$cand['driver_id'];
        $s = $start->format('Y-m-d H:i:s');
        $e = $end->format('Y-m-d H:i:s');

        if ((int)$cand['driver_active'] !== 1 || (int)$cand['user_active'] !== 1) {
            return 'The driver account is inactive';
        }
        if ($cand['driver_status'] === 'offline' && $start <= $now->modify('+' . self::IMMINENT_MINUTES . ' minutes')) {
            return 'The driver is offline and the route starts very soon';
        }
        if ($cand['driver_status'] === 'on_trip' && $start <= $now->modify('+' . self::TRIP_LOOKAHEAD_MINUTES . ' minutes')) {
            return 'The driver is on a passenger trip';
        }
        $today = (int)$this->value("SELECT COUNT(*) FROM route_assignments WHERE driver_id = ? AND assignment_date = ?
                                     AND status IN ('scheduled','active','completed')", [$driverId, $start->format('Y-m-d')]);
        if ($today >= (int)$route['max_day']) {
            return 'The daily assignment limit (' . $route['max_day'] . ') is reached';
        }
        $rest = (int)$route['rest_min'];
        $tooClose = (int)$this->value("SELECT COUNT(*) FROM route_assignments WHERE driver_id = ? AND status IN ('scheduled','active','completed')
                                        AND start_datetime < DATE_ADD(?, INTERVAL ? MINUTE) AND end_datetime > DATE_SUB(?, INTERVAL ? MINUTE)",
                                      [$driverId, $e, $rest, $s, $rest]);
        if ($tooClose > 0) {
            return 'The driver needs a ' . $rest . '-minute rest between assignments';
        }

        // Try the driver's own unit first, then any other active unit.
        $units = [];
        if ($cand['default_unit_id'] !== null) {
            $units[] = (int)$cand['default_unit_id'];
        }
        foreach ($this->rows("SELECT id FROM tricycle_units WHERE status = 'active' ORDER BY id") as $u) {
            if (!in_array((int)$u['id'], $units, true)) {
                $units[] = (int)$u['id'];
            }
        }

        $reason = 'No tricycle unit is available';
        foreach ($units as $unitId) {
            try {
                $this->pdo->beginTransaction();
                $this->pdo->prepare("INSERT INTO route_assignments (route_id, schedule_id, driver_id, unit_id, start_datetime, end_datetime,
                                                                     status, source, assigned_by, rotation_cycle, queue_position, notes)
                                     VALUES (?,?,?,?,?,?, 'scheduled', 'system', ?, ?, ?, 'Automatic rotation')")
                    ->execute([$route['id'], $sch['id'], $driverId, $unitId, $s, $e, $this->userId, $useCycle, $cand['queue_position']]);
                $this->lastAssignmentId = (int)$this->pdo->lastInsertId();
                $this->pdo->prepare('UPDATE driver_route_eligibility SET last_assigned_at = ?, total_assignments = total_assignments + 1, last_served_cycle = ? WHERE id = ?')
                    ->execute([$now->format('Y-m-d H:i:s'), $useCycle, $cand['elig_id']]);
                $this->pdo->prepare('UPDATE rotation_pointers SET last_assigned_driver_id = ?, last_queue_position = ?, cycle_no = ? WHERE route_id = ?')
                    ->execute([$driverId, $cand['queue_position'], $useCycle, $route['id']]);
                $this->pdo->commit();
                return null;
            } catch (PDOException $ex) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $code = $this->code($ex);
                if (in_array($code, [105, 406, 408, 410], true)) {
                    $reason = $this->reason($ex);        // a unit problem: try the next unit
                    continue;
                }
                return $this->reason($ex);               // a driver problem: this driver cannot take the slot
            }
        }
        return $reason;
    }

    /* ------------------------------------------------------------------ helpers */

    private function unfilled(int $routeId, string $slot, string $reason): void
    {
        $this->stats['unfilled']++;
        $this->log('unfilled', $routeId, $slot, null, $reason);
    }

    private function log(string $decision, ?int $routeId, ?string $slot, ?int $driverId, ?string $reason, ?int $assignmentId = null): void
    {
        $this->pdo->prepare('INSERT INTO engine_decisions (run_id, route_id, slot_start, driver_id, decision, reason, assignment_id) VALUES (?,?,?,?,?,?,?)')
            ->execute([$this->runId, $routeId, $slot, $driverId, $decision, $reason !== null ? substr($reason, 0, 250) : null, $assignmentId]);
    }

    private function finish(?string $error): void
    {
        if ($this->runId === 0) {
            return;
        }
        $this->pdo->prepare('UPDATE engine_runs SET finished_at = NOW(), slots_checked = ?, assigned_count = ?, skipped_count = ?, unfilled_count = ?,
                                    activated_count = ?, completed_count = ?, missed_count = ?, error_message = ? WHERE id = ?')
            ->execute([$this->stats['slots'], $this->stats['assigned'], $this->stats['skipped'], $this->stats['unfilled'],
                       $this->stats['activated'], $this->stats['completed'], $this->stats['missed'], $error, $this->runId]);
    }

    private function code(PDOException $e): int
    {
        return preg_match('/TRD-E(\d+)/', $e->getMessage(), $m) ? (int)$m[1] : 0;
    }

    private function reason(PDOException $e): string
    {
        if (preg_match('/TRD-E\d+:\s*([^"\']+?)(?:\s*\(\d+\))?$/m', $e->getMessage(), $m)) {
            return trim($m[1]);
        }
        error_log('TRODDAC engine database error: ' . $e->getMessage());
        return 'Database error';
    }

    private function rows(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    private function value(string $sql, array $params = []): mixed
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }
}
