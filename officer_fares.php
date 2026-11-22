<?php
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/layout.php';
require __DIR__ . '/lib/Accounts.php';
$user = requireRole(['officer']);
requireSchemaOrExplain(['fare_matrices' => ['base_fare', 'base_distance_km', 'per_km_rate', 'minimum_fare', 'effective_from', 'effective_to', 'is_active']], 'Fare rates', 'officer_fares.php');
$pdo = db();

/** Reads a money/number field; returns null when it is not a plain number. */
function numberField(mixed $v): ?float
{
    $t = str_replace([',', ' ', "\u{20B1}"], '', trim((string)$v));
    return $t !== '' && is_numeric($t) ? round((float)$t, 2) : null;
}
function validDate(string $d): bool
{
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}

/** @return array{0: array, 1: array} [clean values, errors] */
function checkFare(array $in, PDO $pdo, int $exceptId): array
{
    $v = [
        'name'             => Accounts::clean($in['name'] ?? ''),
        'per_km_rate'      => numberField($in['per_km_rate'] ?? ''),
        'base_fare'        => numberField($in['base_fare'] ?? '0'),
        'base_distance_km' => numberField($in['base_distance_km'] ?? '0'),
        'minimum_fare'     => numberField($in['minimum_fare'] ?? '0'),
        'effective_from'   => Accounts::clean($in['effective_from'] ?? ''),
        'effective_to'     => Accounts::clean($in['effective_to'] ?? ''),
    ];
    $e = [];
    if (charLength($v['name']) < 3 || charLength($v['name']) > 80) {
        $e['name'] = 'Give the fare rate a name of 3 to 80 characters, for example "2026 standard rate".';
    } else {
        $st = $pdo->prepare('SELECT id FROM fare_matrices WHERE name = ? AND id <> ? LIMIT 1');
        $st->execute([$v['name'], $exceptId]);
        if ($st->fetchColumn() !== false) {
            $e['name'] = 'A fare rate with this name already exists. Use a different name.';
        }
    }
    if ($v['per_km_rate'] === null || $v['per_km_rate'] <= 0 || $v['per_km_rate'] > 999.99) {
        $e['per_km_rate'] = 'Enter the rate per kilometre, between 0.01 and 999.99.';
    }
    if ($v['base_fare'] === null || $v['base_fare'] < 0 || $v['base_fare'] > 9999.99) {
        $e['base_fare'] = 'Enter 0 or a flat starting fare up to 9999.99.';
    }
    if ($v['base_distance_km'] === null || $v['base_distance_km'] < 0 || $v['base_distance_km'] > 50) {
        $e['base_distance_km'] = 'Enter 0 or the kilometres covered by the starting fare (up to 50).';
    }
    if ($v['minimum_fare'] === null || $v['minimum_fare'] < 0 || $v['minimum_fare'] > 9999.99) {
        $e['minimum_fare'] = 'Enter the lowest fare a trip can cost (0 to 9999.99).';
    }
    if (!validDate($v['effective_from'])) {
        $e['effective_from'] = 'Choose the date this rate starts.';
    }
    if ($v['effective_to'] !== '' && (!validDate($v['effective_to']) || (!isset($e['effective_from']) && $v['effective_to'] < $v['effective_from']))) {
        $e['effective_to'] = 'The end date must be on or after the start date (or leave it empty).';
    }
    return [$v, $e];
}

function usedBy(PDO $pdo, int $id): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE fare_matrix_id = ?');
    $st->execute([$id]);
    return (int)$st->fetchColumn();
}

if (isPost()) {
    if (!verifyCsrf()) {
        flash('error', 'Your session expired. Please try again.');
        redirect('officer_fares.php');
    }
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'save') {
            [$v, $errors] = checkFare($_POST, $pdo, $id);
            if ($id > 0) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM fare_matrices WHERE id = ?');
                $st->execute([$id]);
                if ((int)$st->fetchColumn() === 0) {
                    $errors['general'] = 'That fare rate no longer exists.';
                } elseif (usedBy($pdo, $id) > 0) {
                    $errors['general'] = 'This rate has already been used for bookings, so it cannot be changed. Use "Copy" to create a new version.';
                }
            }
            if ($errors) {
                $_SESSION['fare_form'] = ['old' => $_POST, 'errors' => $errors];
            } else {
                $pdo->beginTransaction();
                if ($id > 0) {
                    $pdo->prepare('UPDATE fare_matrices SET name = ?, base_fare = ?, base_distance_km = ?, per_km_rate = ?, minimum_fare = ?, effective_from = ?, effective_to = ? WHERE id = ?')
                        ->execute([$v['name'], $v['base_fare'], $v['base_distance_km'], $v['per_km_rate'], $v['minimum_fare'], $v['effective_from'], $v['effective_to'] !== '' ? $v['effective_to'] : null, $id]);
                } else {
                    $pdo->prepare('INSERT INTO fare_matrices (name, base_fare, base_distance_km, per_km_rate, minimum_fare, effective_from, effective_to, is_active, created_by) VALUES (?,?,?,?,?,?,?,0,?)')
                        ->execute([$v['name'], $v['base_fare'], $v['base_distance_km'], $v['per_km_rate'], $v['minimum_fare'], $v['effective_from'], $v['effective_to'] !== '' ? $v['effective_to'] : null, $user['id']]);
                    $id = (int)$pdo->lastInsertId();
                }
                $pdo->commit();
                flash('success', 'Fare rate saved. Press "Make active" to start using it for new bookings.');
            }
        } elseif ($action === 'activate') {
            $st = $pdo->prepare('SELECT name, effective_from, effective_to FROM fare_matrices WHERE id = ?');
            $st->execute([$id]);
            $m = $st->fetch();
            $today = date('Y-m-d');
            if (!$m) {
                flash('error', 'That fare rate no longer exists.');
            } elseif ($m['effective_from'] > $today || ($m['effective_to'] !== null && $m['effective_to'] < $today)) {
                flash('error', 'This rate is not in effect today (' . $m['effective_from'] . ($m['effective_to'] ? ' to ' . $m['effective_to'] : ' onward') . '). Edit its dates first.');
            } else {
                // One rate at a time: the old one is switched off in the same transaction.
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE fare_matrices SET is_active = 0 WHERE is_active = 1 AND id <> ?')->execute([$id]);
                $pdo->prepare('UPDATE fare_matrices SET is_active = 1 WHERE id = ?')->execute([$id]);
                $pdo->commit();
                flash('success', '"' . $m['name'] . '" is now the active fare rate. New bookings use it.');
            }
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('TRODDAC fares: ' . $e->getMessage());
        flash('error', isSchemaError($e) ? SCHEMA_HELP : 'The fare rate could not be saved. Please check the values and try again.');
    }
    redirect('officer_fares.php');
}

$rows = $pdo->query('SELECT m.id, m.name, m.base_fare, m.base_distance_km, m.per_km_rate, m.minimum_fare, m.effective_from, m.effective_to, m.is_active,
                            (SELECT COUNT(*) FROM bookings b WHERE b.fare_matrix_id = m.id) AS used
                       FROM fare_matrices m ORDER BY m.is_active DESC, m.effective_from DESC, m.id DESC')->fetchAll();
$sample = $pdo->prepare('SELECT fn_calculate_fare(?, ?)');

$form = $_SESSION['fare_form'] ?? null; unset($_SESSION['fare_form']);
$old = $form['old'] ?? [];
$errors = $form['errors'] ?? [];
$val = static fn(string $k, string $d = '') => e((string)($old[$k] ?? $d));
$err = static fn(string $k) => isset($errors[$k]) ? '<small class="field-error" role="alert">' . e($errors[$k]) . '</small>' : '';
$peso = static fn(float|string $n) => '&#8369;' . number_format((float)$n, 2);

dashHeader('Fare rates', 'officer_fares.php');
?>
        <div class="greet"><h2>Fare rates (per kilometre)</h2><p>Fare = starting fare + rate per km for each km beyond the starting distance, never below the minimum fare.</p></div>

        <div class="panel glass" style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap">
            <div><b>Set the price per kilometre</b><div class="hint" style="margin:0">Only one rate is active at a time. Passengers see the active rate when they book.</div></div>
            <button type="button" class="btn" id="new-fare">Add fare rate</button>
        </div>

        <div class="user-grid">
            <?php foreach ($rows as $m): $active = (int)$m['is_active'] === 1; $used = (int)$m['used']; ?>
                <article class="item user-card">
                    <div class="head"><b><?= e($m['name']) ?></b><span class="pill <?= $active ? 'completed' : 'off' ?>"><?= $active ? 'Active' : 'Not active' ?></span></div>
                    <div class="fare-rate"><b><?= $peso($m['per_km_rate']) ?></b> <span>per km</span></div>
                    <dl class="kv compact">
                        <dt>Starting fare</dt><dd><?= $peso($m['base_fare']) ?><?= (float)$m['base_distance_km'] > 0 ? ' covers the first ' . e(rtrim(rtrim((string)$m['base_distance_km'], '0'), '.')) . ' km' : ' (no free distance)' ?></dd>
                        <dt>Minimum fare</dt><dd><?= $peso($m['minimum_fare']) ?></dd>
                        <dt>In effect</dt><dd><?= e($m['effective_from']) ?> <?= $m['effective_to'] ? 'to ' . e($m['effective_to']) : 'onward' ?></dd>
                        <dt>Used by</dt><dd><?= $used ?> booking<?= $used === 1 ? '' : 's' ?></dd>
                        <dt>Examples</dt><dd><?php foreach ([1, 3, 5, 10] as $km) { $sample->execute([$km, $m['id']]); echo $km . ' km ' . $peso($sample->fetchColumn()) . '<br>'; } ?></dd>
                    </dl>
                    <div class="actions">
                        <?php if (!$active): ?>
                            <form method="post" action="officer_fares.php" class="inline-form"><?= csrfField() ?><input type="hidden" name="action" value="activate"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                <button type="submit" class="small-btn">Make active</button></form>
                        <?php endif; ?>
                        <?php $data = e(json_encode(['id' => (int)$m['id'], 'name' => $m['name'], 'per_km_rate' => $m['per_km_rate'], 'base_fare' => $m['base_fare'], 'base_distance_km' => $m['base_distance_km'],
                                                    'minimum_fare' => $m['minimum_fare'], 'effective_from' => $m['effective_from'], 'effective_to' => $m['effective_to']])); ?>
                        <?php if ($used === 0): ?><button type="button" class="small-btn ghost" data-fare-edit="<?= $data ?>">Edit</button><?php endif; ?>
                        <button type="button" class="small-btn ghost" data-fare-copy="<?= $data ?>">Copy as new</button>
                    </div>
                    <?php if ($used > 0): ?><p class="hint">Used by bookings, so it cannot be edited. Copy it to make a new version.</p><?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if (!$rows): ?><div class="panel glass empty">No fare rates yet. Add one to let passengers book.</div><?php endif; ?>

        <dialog class="modal" id="m-fare" <?= $form ? 'data-autoopen="1"' : '' ?> aria-labelledby="m-fare-title">
            <form method="post" action="officer_fares.php" class="modal-body" novalidate>
                <div class="modal-head"><h3 id="m-fare-title"><?= (int)($old['id'] ?? 0) > 0 ? 'Edit fare rate' : 'Add fare rate' ?></h3><button type="button" class="x" data-close aria-label="Close">&times;</button></div>
                <?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="f-id" value="<?= $val('id', '0') ?>">
                <?php if (!empty($errors['general'])): ?><div class="alert" role="alert"><?= e($errors['general']) ?></div><?php endif; ?>

                <label for="f-name">Name</label>
                <input type="text" id="f-name" name="name" value="<?= $val('name') ?>" placeholder="e.g. 2026 standard rate" maxlength="80" required>
                <?= $err('name') ?>

                <label for="f-rate">Rate per km (&#8369;)</label>
                <input type="text" inputmode="decimal" id="f-rate" name="per_km_rate" value="<?= $val('per_km_rate') ?>" placeholder="e.g. 10.00" required>
                <?= $err('per_km_rate') ?>

                <div class="form-grid">
                    <div><label for="f-base">Starting fare (&#8369;)</label>
                        <input type="text" inputmode="decimal" id="f-base" name="base_fare" value="<?= $val('base_fare', '0') ?>" placeholder="e.g. 0 or 25.00">
                        <?= $err('base_fare') ?></div>
                    <div><label for="f-basekm">Covers first (km)</label>
                        <input type="text" inputmode="decimal" id="f-basekm" name="base_distance_km" value="<?= $val('base_distance_km', '0') ?>" placeholder="e.g. 0 or 1">
                        <?= $err('base_distance_km') ?></div>
                    <div><label for="f-min">Minimum fare (&#8369;)</label>
                        <input type="text" inputmode="decimal" id="f-min" name="minimum_fare" value="<?= $val('minimum_fare', '20') ?>" placeholder="e.g. 20.00">
                        <?= $err('minimum_fare') ?></div>
                </div>
                <div class="form-grid">
                    <div><label for="f-from">In effect from</label>
                        <input type="date" id="f-from" name="effective_from" value="<?= $val('effective_from', date('Y-m-d')) ?>" placeholder="YYYY-MM-DD" required>
                        <?= $err('effective_from') ?></div>
                    <div><label for="f-to">In effect until (optional)</label>
                        <input type="date" id="f-to" name="effective_to" value="<?= $val('effective_to') ?>" placeholder="YYYY-MM-DD">
                        <?= $err('effective_to') ?></div>
                </div>
                <p class="hint">Leave the starting fare and distance at 0 for pure per-kilometre pricing.</p>

                <div class="fare-preview" id="preview" aria-live="polite"></div>

                <div class="modal-foot">
                    <button type="button" class="btn ghost" data-close>Cancel</button>
                    <button type="submit" class="btn">Save fare rate</button>
                </div>
            </form>
        </dialog>
<?php dashFooter(['modals.js', 'officer_fares.js']);
