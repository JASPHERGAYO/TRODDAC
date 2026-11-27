<?php
declare(strict_types=1);

require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/layout.php';
require __DIR__ . '/lib/Accounts.php';

$user      = requireRole(['operator', 'officer']);
$isOfficer = $user['role'] === 'officer';
$pdo       = db();

const DAY_NAMES = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

function validClock(string $t): ?string
{
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $t, $m)) {
        return null;
    }
    return $m[1] . ':' . $m[2] . ':00';
}

function decField(mixed $v): ?float
{
    $t = str_replace([',', ' '], '', trim((string)$v));
    return $t !== '' && is_numeric($t) ? round((float)$t, 2) : null;
}

if (isPost()) {
    if (!$isOfficer) {
        http_response_code(403);
        flash('error', 'Only an Association Officer can add or edit official routes.');
        redirect('operator_assignments.php');
    }
    if (!verifyCsrf()) {
        flash('error', 'Your session expired. Please try again.');
        redirect('operator_assignments.php');
    }

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'save_terminal') {
            $tName = Accounts::clean($_POST['terminal_name'] ?? '');
            $tAddr = Accounts::clean($_POST['terminal_address'] ?? '');
            $tLat  = trim((string)($_POST['terminal_lat'] ?? ''));
            $tLng  = trim((string)($_POST['terminal_lng'] ?? ''));
            $tErr  = [];

            if (charLength($tName) < 3 || charLength($tName) > 100) {
                $tErr['terminal_name'] = 'Enter a terminal name (3 to 100 characters).';
            } else {
                $st = $pdo->prepare('SELECT id FROM terminals WHERE name = ? LIMIT 1');
                $st->execute([$tName]);
                if ($st->fetchColumn() !== false) {
                    $tErr['terminal_name'] = 'A terminal with this name already exists.';
                }
            }
            $latNum = is_numeric($tLat) ? (float)$tLat : null;
            $lngNum = is_numeric($tLng) ? (float)$tLng : null;
            if ($latNum === null || $latNum < -90 || $latNum > 90) {
                $tErr['terminal_lat'] = 'Enter a valid latitude (-90 to 90).';
            }
            if ($lngNum === null || $lngNum < -180 || $lngNum > 180) {
                $tErr['terminal_lng'] = 'Enter a valid longitude (-180 to 180).';
            }

            if ($tErr) {
                $_SESSION['terminal_form'] = ['old' => $_POST, 'errors' => $tErr];
            } else {
                $pdo->prepare('INSERT INTO terminals (name, address, latitude, longitude, is_active) VALUES (?,?,?,?,1)')
                    ->execute([$tName, $tAddr !== '' ? $tAddr : null, round($latNum, 7), round($lngNum, 7)]);
                flash('success', 'Terminal "' . $tName . '" added. You can now use it in routes.');
            }
            redirect('operator_assignments.php');
        }

        if ($action === 'toggle_route') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare('SELECT code, name, is_active FROM routes WHERE id = ?');
            $st->execute([$id]);
            $r = $st->fetch();
            if (!$r) {
                flash('error', 'That route was not found.');
            } else {
                $next = (int)$r['is_active'] === 1 ? 0 : 1;
                $pdo->prepare('UPDATE routes SET is_active = ? WHERE id = ?')->execute([$next, $id]);
                flash('success', 'Route ' . $r['code'] . ' (' . $r['name'] . ') is now ' . ($next ? 'active' : 'disabled') . '.');
            }
            redirect('operator_assignments.php');
        }

        if ($action === 'save_route') {
            $id     = (int)($_POST['id'] ?? 0);
            $code   = strtoupper(preg_replace('/\s+/', '', Accounts::clean($_POST['code'] ?? '')));
            $name   = Accounts::clean($_POST['name'] ?? '');
            $origId = (int)($_POST['origin_terminal_id'] ?? 0);
            $destId = (int)($_POST['destination_terminal_id'] ?? 0);
            $km     = decField($_POST['distance_km'] ?? '');
            $dur    = filter_var(trim((string)($_POST['est_duration_min'] ?? '')), FILTER_VALIDATE_INT);
            $reqDrv = filter_var(trim((string)($_POST['drivers_required'] ?? '1')), FILTER_VALIDATE_INT);
            $start1 = validClock(trim((string)($_POST['start_time'] ?? '')));
            $end1   = validClock(trim((string)($_POST['end_time'] ?? '')));
            $s2Raw  = trim((string)($_POST['start_time_2'] ?? ''));
            $e2Raw  = trim((string)($_POST['end_time_2'] ?? ''));
            $start2 = $s2Raw !== '' ? validClock($s2Raw) : null;
            $end2   = $e2Raw !== '' ? validClock($e2Raw) : null;

            $daysIn = array_values(array_unique(array_filter(
                array_map('intval', (array)($_POST['days'] ?? [])),
                static fn(int $d): bool => $d >= 1 && $d <= 7
            )));
            sort($daysIn);

            $driverIds = array_values(array_unique(array_filter(
                array_map('intval', (array)($_POST['driver_ids'] ?? [])),
                static fn(int $d): bool => $d > 0
            )));

            $errors = [];
            if ($id > 0) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM routes WHERE id = ?');
                $st->execute([$id]);
                if ((int)$st->fetchColumn() === 0) {
                    $errors['general'] = 'That route no longer exists.';
                }
            }
            if (!preg_match('/^[A-Z0-9][A-Z0-9\-]{0,19}$/', $code)) {
                $errors['code'] = 'Enter a short route code (1 to 20 letters/numbers, e.g. R3).';
            } else {
                $st = $pdo->prepare('SELECT id FROM routes WHERE code = ? AND id <> ? LIMIT 1');
                $st->execute([$code, $id]);
                if ($st->fetchColumn() !== false) {
                    $errors['code'] = 'Route code "' . $code . '" is already in use.';
                }
            }
            if (charLength($name) < 3 || charLength($name) > 120) {
                $errors['name'] = 'Enter a route name (3 to 120 characters, e.g. Salawag - Burol).';
            }

            $validTerm = static function (int $tid) use ($pdo): bool {
                if ($tid <= 0) return false;
                $s = $pdo->prepare('SELECT COUNT(*) FROM terminals WHERE id = ? AND is_active = 1');
                $s->execute([$tid]);
                return (int)$s->fetchColumn() > 0;
            };
            if (!$validTerm($origId)) {
                $errors['origin_terminal_id'] = 'Choose an origin terminal.';
            }
            if (!$validTerm($destId)) {
                $errors['destination_terminal_id'] = 'Choose a destination terminal.';
            } elseif ($origId > 0 && $origId === $destId) {
                $errors['destination_terminal_id'] = 'Destination terminal must be different from the origin terminal.';
            }

            if ($km === null || $km <= 0 || $km > 999.99) {
                $errors['distance_km'] = 'Enter a distance between 0.10 and 999.99 km.';
            }
            if ($dur === false || $dur < 1 || $dur > 600) {
                $errors['est_duration_min'] = 'Enter estimated duration in minutes (1 to 600).';
            }
            if ($reqDrv === false || $reqDrv < 1 || $reqDrv > 20) {
                $errors['drivers_required'] = 'Enter how many drivers each slot needs (1 to 20).';
            }
            if (!$daysIn) {
                $errors['days'] = 'Select at least one operating day.';
            }
            if ($start1 === null || $end1 === null || $start1 >= $end1) {
                $errors['start_time'] = 'Shift 1 end time must be later than start time.';
            }
            if ($s2Raw !== '' || $e2Raw !== '') {
                if ($start2 === null || $end2 === null || $start2 >= $end2) {
                    $errors['start_time_2'] = 'Shift 2 needs both start and end times (end after start), or leave both blank.';
                } elseif ($start1 !== null && $start2 === $start1) {
                    $errors['start_time_2'] = 'Shift 2 must start at a different time than Shift 1.';
                }
            }
            if (!$driverIds) {
                $errors['driver_ids'] = 'Select at least one eligible driver for this route.';
            } else {
                $inPh = implode(',', array_fill(0, count($driverIds), '?'));
                $st = $pdo->prepare("SELECT id FROM drivers WHERE id IN ($inPh) AND is_active = 1");
                $st->execute($driverIds);
                $validFound = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                $driverIds  = array_values(array_intersect($driverIds, $validFound));
                if (!$driverIds) {
                    $errors['driver_ids'] = 'Select at least one active driver.';
                }
            }

            if ($errors) {
                $_SESSION['route_form'] = ['old' => $_POST, 'errors' => $errors];
                redirect('operator_assignments.php');
            }

            $pdo->beginTransaction();
            if ($id > 0) {
                $pdo->prepare('UPDATE routes SET code = ?, name = ?, origin_terminal_id = ?, destination_terminal_id = ?, distance_km = ?, est_duration_min = ? WHERE id = ?')
                    ->execute([$code, $name, $origId, $destId, $km, $dur, $id]);
                $routeId = $id;
            } else {
                $pdo->prepare('INSERT INTO routes (code, name, origin_terminal_id, destination_terminal_id, distance_km, est_duration_min, is_active, created_by)
                               VALUES (?,?,?,?,?,?,1,?)')
                    ->execute([$code, $name, $origId, $destId, $km, $dur, $user['id']]);
                $routeId = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO rotation_settings (route_id, auto_assign_enabled, updated_by) VALUES (?,1,?)')
                    ->execute([$routeId, $user['id']]);
                $pdo->prepare('INSERT INTO rotation_pointers (route_id) VALUES (?)')->execute([$routeId]);
            }

            // Refresh schedules for this route (existing assignments keep their history via ON DELETE SET NULL).
            $pdo->prepare('DELETE FROM route_schedules WHERE route_id = ?')->execute([$routeId]);
            $insSch = $pdo->prepare('INSERT INTO route_schedules (route_id, day_of_week, start_time, end_time, drivers_required, is_active) VALUES (?,?,?,?,?,1)');
            foreach ($daysIn as $d) {
                $insSch->execute([$routeId, $d, $start1, $end1, $reqDrv]);
                if ($start2 !== null && $end2 !== null) {
                    $insSch->execute([$routeId, $d, $start2, $end2, $reqDrv]);
                }
            }

            // Sync driver eligibility while keeping existing queue_position and rotation stats intact.
            $existingSt = $pdo->prepare('SELECT driver_id, queue_position FROM driver_route_eligibility WHERE route_id = ?');
            $existingSt->execute([$routeId]);
            $existingMap = [];
            $maxPos      = 0;
            foreach ($existingSt->fetchAll() as $er) {
                $existingMap[(int)$er['driver_id']] = (int)$er['queue_position'];
                if ((int)$er['queue_position'] > $maxPos) {
                    $maxPos = (int)$er['queue_position'];
                }
            }
            $pdo->prepare('UPDATE driver_route_eligibility SET is_eligible = 0 WHERE route_id = ?')->execute([$routeId]);
            $enEl  = $pdo->prepare('UPDATE driver_route_eligibility SET is_eligible = 1 WHERE route_id = ? AND driver_id = ?');
            $insEl = $pdo->prepare('INSERT INTO driver_route_eligibility (driver_id, route_id, is_eligible, queue_position) VALUES (?,?,1,?)');
            foreach ($driverIds as $did) {
                if (isset($existingMap[$did])) {
                    $enEl->execute([$routeId, $did]);
                } else {
                    $maxPos++;
                    $insEl->execute([$did, $routeId, $maxPos]);
                }
            }

            $pdo->commit();
            flash('success', ($id > 0 ? 'Updated route ' : 'Added route ') . $code . ' (' . $name . ') with ' . count($driverIds) . ' eligible driver(s).');
            redirect('operator_assignments.php');
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('TRODDAC routes: ' . $e->getMessage());
        flash('error', isSchemaError($e) ? SCHEMA_HELP : 'Could not save the route. Please check your inputs and try again.');
        redirect('operator_assignments.php');
    }
}

// ---- Fetch routes, terminals, schedules, and drivers for display & modal
$terminals = $pdo->query('SELECT id, name, address FROM terminals WHERE is_active = 1 ORDER BY name')->fetchAll();

$allDrivers = $pdo->query("SELECT d.id, u.full_name, COALESCE(tu.plate_number, tu.body_number) AS unit_label
                             FROM drivers d
                             JOIN users u ON u.id = d.user_id
                             LEFT JOIN tricycle_units tu ON tu.id = d.default_unit_id
                            WHERE d.is_active = 1 AND u.is_active = 1
                            ORDER BY d.id")->fetchAll();

$routes = $pdo->query("SELECT r.id, r.code, r.name, r.origin_terminal_id, r.destination_terminal_id,
                              r.distance_km, r.est_duration_min, r.is_active,
                              ot.name AS origin_name, dt.name AS dest_name
                         FROM routes r
                         JOIN terminals ot ON ot.id = r.origin_terminal_id
                         JOIN terminals dt ON dt.id = r.destination_terminal_id
                        ORDER BY r.is_active DESC, r.code ASC")->fetchAll();

$schedByRoute = [];
foreach ($pdo->query('SELECT route_id, day_of_week, start_time, end_time, drivers_required FROM route_schedules WHERE is_active = 1 ORDER BY day_of_week, start_time') as $s) {
    $schedByRoute[(int)$s['route_id']][] = $s;
}

$eligByRoute = [];
foreach ($pdo->query("SELECT e.route_id, e.driver_id, e.queue_position, u.full_name
                        FROM driver_route_eligibility e
                        JOIN drivers d ON d.id = e.driver_id
                        JOIN users u ON u.id = d.user_id
                       WHERE e.is_eligible = 1 AND d.is_active = 1 AND u.is_active = 1
                       ORDER BY e.route_id, e.queue_position") as $el) {
    $eligByRoute[(int)$el['route_id']][] = $el;
}

/** Summarizes a route's active schedule rows for display and for the edit modal. */
function summarizeSchedule(array $rows): array
{
    if (!$rows) {
        return ['label' => 'No active schedule', 'days' => [1,2,3,4,5,6], 's1' => '06:00', 'e1' => '10:00', 's2' => '', 'e2' => '', 'req' => 1];
    }
    $days = [];
    $windows = [];
    $req = 1;
    foreach ($rows as $r) {
        $days[(int)$r['day_of_week']] = true;
        $w = substr((string)$r['start_time'], 0, 5) . '-' . substr((string)$r['end_time'], 0, 5);
        $windows[$w] = [substr((string)$r['start_time'], 0, 5), substr((string)$r['end_time'], 0, 5)];
        $req = (int)$r['drivers_required'];
    }
    $dayKeys = array_keys($days);
    sort($dayKeys);
    $dayLabel = $dayKeys === [1,2,3,4,5,6] ? 'Mon-Sat'
              : ($dayKeys === [1,2,3,4,5,6,7] ? 'Daily'
              : ($dayKeys === [1,2,3,4,5] ? 'Mon-Fri'
              : implode(', ', array_map(static fn(int $d) => DAY_NAMES[$d], $dayKeys))));
    $winKeys = array_values($windows);
    return [
        'label' => $dayLabel . ' · ' . implode(', ', array_keys($windows)) . ' (' . $req . ' driver' . ($req === 1 ? '' : 's') . '/slot)',
        'days'  => $dayKeys,
        's1'    => $winKeys[0][0] ?? '06:00',
        'e1'    => $winKeys[0][1] ?? '10:00',
        's2'    => $winKeys[1][0] ?? '',
        'e2'    => $winKeys[1][1] ?? '',
        'req'   => $req,
    ];
}

$rForm   = $_SESSION['route_form'] ?? null; unset($_SESSION['route_form']);
$rOld    = $rForm['old'] ?? [];
$rErrors = $rForm['errors'] ?? [];
$rVal    = static fn(string $k, string $d = '') => e((string)($rOld[$k] ?? $d));
$rErr    = static fn(string $k) => isset($rErrors[$k]) ? '<small class="field-error" role="alert">' . e($rErrors[$k]) . '</small>' : '';

$tForm   = $_SESSION['terminal_form'] ?? null; unset($_SESSION['terminal_form']);
$tOld    = $tForm['old'] ?? [];
$tErrors = $tForm['errors'] ?? [];
$tVal    = static fn(string $k, string $d = '') => e((string)($tOld[$k] ?? $d));
$tErr    = static fn(string $k) => isset($tErrors[$k]) ? '<small class="field-error" role="alert">' . e($tErrors[$k]) . '</small>' : '';

$defaultDays    = isset($rOld['days']) ? array_map('intval', (array)$rOld['days']) : [1, 2, 3, 4, 5, 6];
$defaultDrivers = isset($rOld['driver_ids'])
    ? array_map('intval', (array)$rOld['driver_ids'])
    : array_map(static fn(array $d) => (int)$d['id'], $allDrivers);

$cfgCenter = appConfig()['service_area'];

dashHeader('Official routes', 'operator_assignments.php');
?>
        <div class="greet">
            <div>
                <h2>Official route assignments</h2>
                <p><?= $isOfficer ? 'Add routes, schedules and eligible drivers for the automatic rotation.' : 'Drivers are assigned to routes automatically in a fair rotation. This view is read-only.' ?></p>
            </div>
        </div>

        <?php if ($isOfficer): ?>
            <div class="panel glass cta-panel">
                <div class="cta-lead">
                    <span class="cta-ico"><?= icon('map') ?></span>
                    <div>
                        <b>Add an official route for drivers</b>
                        <p class="hint">Set terminals, daily schedule windows and which drivers rotate on the route.</p>
                    </div>
                </div>
                <div class="cta-actions">
                    <button type="button" class="btn" id="new-route">+ Add route</button>
                    <button type="button" class="btn ghost-light" data-open="m-terminal">+ New terminal</button>
                </div>
            </div>
        <?php endif; ?>

        <div class="row stats">
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('map') ?></span>On route now</div><div class="stat-body"><b id="s-active">0</b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('clock') ?></span>Upcoming</div><div class="stat-body"><b id="s-upcoming">0</b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('car') ?></span>Completed today</div><div class="stat-body"><b id="s-done">0</b></div></div>
            <div class="stat-card glass"><div class="stat-top"><span class="chip"><?= icon('users') ?></span>Missed today</div><div class="stat-body"><b id="s-missed">0</b></div></div>
        </div>

        <div class="panel glass">
            <div class="panel-head">
                <h3>Configured routes (<?= count($routes) ?>)</h3>
                <?php if ($isOfficer): ?>
                    <button type="button" class="small-btn ghost" id="new-route-inline">+ Add route</button>
                <?php endif; ?>
            </div>
            <?php if ($routes): ?>
                <div class="user-grid">
                    <?php foreach ($routes as $rt):
                        $rid    = (int)$rt['id'];
                        $active = (int)$rt['is_active'] === 1;
                        $sched  = summarizeSchedule($schedByRoute[$rid] ?? []);
                        $elig   = $eligByRoute[$rid] ?? [];
                        $dIds   = array_map(static fn(array $x) => (int)$x['driver_id'], $elig);
                        $dNames = array_map(static fn(array $x) => $x['full_name'], $elig);
                        $editJson = e((string)json_encode([
                            'id'                      => $rid,
                            'code'                    => $rt['code'],
                            'name'                    => $rt['name'],
                            'origin_terminal_id'      => (int)$rt['origin_terminal_id'],
                            'destination_terminal_id' => (int)$rt['destination_terminal_id'],
                            'distance_km'             => $rt['distance_km'],
                            'est_duration_min'        => (int)$rt['est_duration_min'],
                            'days'                    => $sched['days'],
                            'start_time'              => $sched['s1'],
                            'end_time'                => $sched['e1'],
                            'start_time_2'            => $sched['s2'],
                            'end_time_2'              => $sched['e2'],
                            'drivers_required'        => $sched['req'],
                            'driver_ids'              => $dIds,
                        ], JSON_HEX_TAG | JSON_HEX_AMP));
                    ?>
                        <article class="item user-card<?= $active ? '' : ' route-disabled' ?>">
                            <div class="head">
                                <b><span class="mono"><?= e($rt['code']) ?></span> &middot; <?= e($rt['name']) ?></b>
                                <span class="pill <?= $active ? 'completed' : 'off' ?>"><?= $active ? 'Active' : 'Disabled' ?></span>
                            </div>
                            <div class="route"><?= routeStops((string)$rt['origin_name'], (string)$rt['dest_name']) ?></div>
                            <dl class="kv compact">
                                <dt>Trip</dt><dd><?= e($rt['distance_km']) ?> km &middot; ~<?= (int)$rt['est_duration_min'] ?> min</dd>
                                <dt>Schedule</dt><dd><?= e($sched['label']) ?></dd>
                                <dt>Drivers (<?= count($elig) ?>)</dt>
                                <dd><?= $dNames ? e(implode(' → ', $dNames)) : '<span class="muted">None assigned</span>' ?></dd>
                            </dl>
                            <?php if ($isOfficer): ?>
                                <div class="actions">
                                    <button type="button" class="small-btn ghost" data-route-edit="<?= $editJson ?>">Edit route &amp; drivers</button>
                                    <form method="post" action="operator_assignments.php" class="inline-form">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="toggle_route">
                                        <input type="hidden" name="id" value="<?= $rid ?>">
                                        <button type="submit" class="small-btn <?= $active ? 'ghost' : 'ok' ?>"><?= $active ? 'Disable' : 'Enable' ?></button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty">No official routes yet.<?= $isOfficer ? ' Press "+ Add route" above to create the first one.' : '' ?></div>
            <?php endif; ?>
        </div>

        <div class="split">
            <div class="panel glass">
                <h3>Assignments</h3>
                <div class="list" id="assignments"></div>
                <div class="empty" id="assignments-empty" hidden>No assignments yet.</div>
            </div>
            <div class="col-main" style="display:flex;flex-direction:column;gap:12px">
                <div class="panel glass">
                    <h3>Assignment engine</h3>
                    <p class="hint" id="last-run" style="margin:0 0 8px">Loading...</p>
                    <?php if ($isOfficer): ?>
                        <button type="button" class="btn" id="run-now">Run assignment now</button>
                    <?php endif; ?>
                </div>
                <div class="panel glass">
                    <h3>Why the engine did what it did</h3>
                    <div class="list" id="decisions"></div>
                    <div class="empty" id="decisions-empty" hidden>No decisions recorded yet.</div>
                </div>
            </div>
        </div>

        <?php if ($isOfficer): ?>
            <dialog class="modal" id="m-route" <?= $rForm ? 'data-autoopen="1"' : '' ?> aria-labelledby="m-route-title">
                <form method="post" action="operator_assignments.php" class="modal-body" id="route-form" novalidate>
                    <div class="modal-head">
                        <h3 id="m-route-title"><?= (int)($rOld['id'] ?? 0) > 0 ? 'Edit official route' : 'Add official route' ?></h3>
                        <button type="button" class="x" data-close aria-label="Close">&times;</button>
                    </div>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_route">
                    <input type="hidden" name="id" id="r-id" value="<?= $rVal('id', '0') ?>">

                    <?php if (!empty($rErrors['general'])): ?><div class="alert" role="alert"><?= e($rErrors['general']) ?></div><?php endif; ?>

                    <div class="form-grid">
                        <div>
                            <label for="r-code">Route code</label>
                            <input type="text" id="r-code" name="code" value="<?= $rVal('code') ?>" placeholder="e.g. R3" maxlength="20" required>
                            <?= $rErr('code') ?>
                        </div>
                        <div>
                            <label for="r-name">Route name</label>
                            <input type="text" id="r-name" name="name" value="<?= $rVal('name') ?>" placeholder="e.g. Salawag - Burol" maxlength="120" required>
                            <?= $rErr('name') ?>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div>
                            <label for="r-origin">Origin terminal</label>
                            <select id="r-origin" name="origin_terminal_id" required>
                                <option value="">Select origin terminal...</option>
                                <?php foreach ($terminals as $t): ?>
                                    <option value="<?= (int)$t['id'] ?>" <?= (int)($rOld['origin_terminal_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= $rErr('origin_terminal_id') ?>
                        </div>
                        <div>
                            <label for="r-dest">Destination terminal</label>
                            <select id="r-dest" name="destination_terminal_id" required>
                                <option value="">Select destination terminal...</option>
                                <?php foreach ($terminals as $t): ?>
                                    <option value="<?= (int)$t['id'] ?>" <?= (int)($rOld['destination_terminal_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= $rErr('destination_terminal_id') ?>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div>
                            <label for="r-km">Distance (km)</label>
                            <input type="text" inputmode="decimal" id="r-km" name="distance_km" value="<?= $rVal('distance_km', '4.50') ?>" placeholder="e.g. 4.50" required>
                            <?= $rErr('distance_km') ?>
                        </div>
                        <div>
                            <label for="r-dur">Est. duration (min)</label>
                            <input type="number" id="r-dur" name="est_duration_min" value="<?= $rVal('est_duration_min', '15') ?>" min="1" max="600" required>
                            <?= $rErr('est_duration_min') ?>
                        </div>
                        <div>
                            <label for="r-req">Drivers per slot</label>
                            <input type="number" id="r-req" name="drivers_required" value="<?= $rVal('drivers_required', '1') ?>" min="1" max="20" required>
                            <?= $rErr('drivers_required') ?>
                        </div>
                    </div>

                    <label>Operating days</label>
                    <div class="day-pills" id="r-days">
                        <?php foreach (DAY_NAMES as $dNum => $dLbl): ?>
                            <label class="day-pill">
                                <input type="checkbox" name="days[]" value="<?= $dNum ?>" <?= in_array($dNum, $defaultDays, true) ? 'checked' : '' ?>>
                                <span><?= $dLbl ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?= $rErr('days') ?>

                    <div class="form-grid">
                        <div>
                            <label for="r-s1">Shift 1 start</label>
                            <input type="time" id="r-s1" name="start_time" value="<?= $rVal('start_time', '06:00') ?>" required>
                            <?= $rErr('start_time') ?>
                        </div>
                        <div>
                            <label for="r-e1">Shift 1 end</label>
                            <input type="time" id="r-e1" name="end_time" value="<?= $rVal('end_time', '10:00') ?>" required>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div>
                            <label for="r-s2">Shift 2 start (optional)</label>
                            <input type="time" id="r-s2" name="start_time_2" value="<?= $rVal('start_time_2') ?>">
                            <?= $rErr('start_time_2') ?>
                        </div>
                        <div>
                            <label for="r-e2">Shift 2 end (optional)</label>
                            <input type="time" id="r-e2" name="end_time_2" value="<?= $rVal('end_time_2') ?>">
                        </div>
                    </div>

                    <div class="driver-pick-head">
                        <label>Eligible drivers in rotation queue</label>
                        <?php if ($allDrivers): ?>
                            <button type="button" class="link-btn" id="r-toggle-drivers">Select all</button>
                        <?php endif; ?>
                    </div>
                    <?php if ($allDrivers): ?>
                        <div class="driver-check-grid" id="r-drivers">
                            <?php foreach ($allDrivers as $drv): $did = (int)$drv['id']; ?>
                                <label class="driver-check">
                                    <input type="checkbox" name="driver_ids[]" value="<?= $did ?>" <?= in_array($did, $defaultDrivers, true) ? 'checked' : '' ?>>
                                    <span><b><?= e($drv['full_name']) ?></b><small><?= e($drv['unit_label'] ?: 'No unit') ?></small></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="hint">No active drivers yet. Add drivers in <a href="officer_users.php">Users</a> first.</p>
                    <?php endif; ?>
                    <?= $rErr('driver_ids') ?>

                    <div class="modal-foot">
                        <button type="button" class="btn ghost" data-close>Cancel</button>
                        <button type="submit" class="btn" id="r-submit"><?= (int)($rOld['id'] ?? 0) > 0 ? 'Save changes' : 'Create route' ?></button>
                    </div>
                </form>
            </dialog>

            <dialog class="modal small" id="m-terminal" <?= $tForm ? 'data-autoopen="1"' : '' ?> aria-labelledby="m-terminal-title">
                <form method="post" action="operator_assignments.php" class="modal-body" novalidate>
                    <div class="modal-head">
                        <h3 id="m-terminal-title">Add terminal</h3>
                        <button type="button" class="x" data-close aria-label="Close">&times;</button>
                    </div>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_terminal">

                    <label for="t-name">Terminal name</label>
                    <input type="text" id="t-name" name="terminal_name" value="<?= $tVal('terminal_name') ?>" placeholder="e.g. Sampaloc Terminal" maxlength="100" required>
                    <?= $tErr('terminal_name') ?>

                    <label for="t-addr">Address / barangay (optional)</label>
                    <input type="text" id="t-addr" name="terminal_address" value="<?= $tVal('terminal_address') ?>" placeholder="e.g. Sampaloc I, Dasmariñas City" maxlength="255">

                    <div class="form-grid">
                        <div>
                            <label for="t-lat">Latitude</label>
                            <input type="text" inputmode="decimal" id="t-lat" name="terminal_lat" value="<?= $tVal('terminal_lat', (string)$cfgCenter['center_lat']) ?>" required>
                            <?= $tErr('terminal_lat') ?>
                        </div>
                        <div>
                            <label for="t-lng">Longitude</label>
                            <input type="text" inputmode="decimal" id="t-lng" name="terminal_lng" value="<?= $tVal('terminal_lng', (string)$cfgCenter['center_lng']) ?>" required>
                            <?= $tErr('terminal_lng') ?>
                        </div>
                    </div>
                    <p class="hint">Defaults to Dasmariñas City center if you don't have exact coordinates handy.</p>

                    <div class="modal-foot">
                        <button type="button" class="btn ghost" data-close>Cancel</button>
                        <button type="submit" class="btn">Save terminal</button>
                    </div>
                </form>
            </dialog>
        <?php endif; ?>
<?php dashFooter(['modals.js', 'operator_assignments.js']);
