<?php
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/layout.php';
require __DIR__ . '/lib/Accounts.php';
$me  = requireRole(['officer']);
$pdo = db();

$missing = schemaMissing(['users' => ['first_name', 'last_name', 'middle_initial'], 'drivers' => ['license_number']]);
if ($missing && !isPost()) {
    dashHeader('Users', 'officer_users.php');
    echo '<div class="panel glass"><h3>Database update needed</h3><p class="note">' . e(SCHEMA_HELP) . '</p></div>';
    dashFooter();
    exit;
}

function tempPassword(): string
{
    return 'Tr' . bin2hex(random_bytes(4)) . '9';
}

/** Officers create staff and drivers here. Passengers sign up themselves at register.php. */
const STAFF_ROLES = ['driver', 'operator', 'officer'];

if (isPost()) {
    if (!verifyCsrf()) {
        flash('error', 'Your session expired. Please try again.');
        redirect('officer_users.php');
    }
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'create') {
            $role = (string)($_POST['role'] ?? '');
            if (!in_array($role, STAFF_ROLES, true)) {
                $_SESSION['officer_form'] = ['old' => $_POST, 'errors' => ['role' => $role === 'passenger'
                    ? 'Passengers create their own accounts at the sign-up page. Choose driver, operator or officer.' : 'Choose a valid role.'], 'general' => ''];
            } else {
                $temp   = tempPassword();
                $result = Accounts::create($pdo, $_POST, $role, $temp, true);
                if ($result['ok']) {
                    $_SESSION['secret'] = ['title' => 'Account created', 'lead' => 'Give this temporary password to ' . $result['full_name'] . ' (username: ' . $result['username'] . '). It is shown only once.', 'password' => $temp];
                    flash('success', 'Account for ' . $result['full_name'] . ' created.');
                } else {
                    $_SESSION['officer_form'] = ['old' => $_POST, 'errors' => $result['errors'], 'general' => (string)$result['message']];
                }
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['user_id'] ?? 0);
            if ($id === (int)$me['id']) {
                flash('error', 'You cannot disable your own account.');
            } else {
                $st = $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?');
                $st->execute([$id]);
                flash($st->rowCount() ? 'success' : 'error', $st->rowCount() ? 'Account status updated.' : 'User not found.');
            }
        } elseif ($action === 'reset') {
            $id   = (int)($_POST['user_id'] ?? 0);
            $temp = tempPassword();
            $who  = $pdo->prepare('SELECT username FROM users WHERE id = ?');
            $who->execute([$id]);
            $username = $who->fetchColumn();
            if ($username === false) {
                flash('error', 'User not found.');
            } else {
                $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($temp, PASSWORD_DEFAULT), $id]);
                $_SESSION['secret'] = ['title' => 'Password reset', 'lead' => "New temporary password for '$username'. It is shown only once.", 'password' => $temp];
            }
        }
    } catch (PDOException $e) {
        $msg = isSchemaError($e) ? SCHEMA_HELP : (str_contains($e->getMessage(), 'TRD-E305') ? 'The last active Association Officer cannot be disabled.' : null);
        if ($msg === null) {
            error_log('TRODDAC officer_users: ' . $e->getMessage());
        }
        flash('error', $msg ?? 'The change could not be saved. Please try again.');
    }
    redirect('officer_users.php');
}

/* ---------------------------------------------------------------- list */
$q    = Accounts::clean($_GET['q'] ?? '');
$role = (string)($_GET['role'] ?? '');
$where = []; $params = [];
if ($q !== '') {
    $where[] = '(u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR d.license_number LIKE ? OR tu.body_number LIKE ?)';
    array_push($params, ...array_fill(0, 5, '%' . $q . '%'));
}
if (isset(ROLE_LABEL[$role])) {
    $where[] = 'u.role = ?';
    $params[] = $role;
}
$st = $pdo->prepare('SELECT u.id, u.username, u.full_name, u.email, u.phone, u.role, u.is_active, u.last_login_at,
                            d.license_number, COALESCE(tu.body_number, tu.plate_number) AS unit_no
                       FROM users u
                       LEFT JOIN drivers d ON d.user_id = u.id
                       LEFT JOIN tricycle_units tu ON tu.id = d.default_unit_id'
                    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                    . ' ORDER BY FIELD(u.role, "officer","operator","driver","passenger"), u.full_name LIMIT 300');
$st->execute($params);
$users = $st->fetchAll();

$form   = $_SESSION['officer_form'] ?? null;  unset($_SESSION['officer_form']);
$secret = $_SESSION['secret'] ?? null;        unset($_SESSION['secret']);
$old    = $form['old'] ?? [];
$errors = $form['errors'] ?? [];
$val    = static fn(string $k, string $default = '') => e((string)($old[$k] ?? $default));
$err    = static fn(string $k) => isset($errors[$k]) ? '<small class="field-error" role="alert">' . e($errors[$k]) . '</small>' : '';

dashHeader('Users', 'officer_users.php');
?>
        <div class="greet"><h2>Users and roles</h2><p>Add drivers, operators and officers, disable access, or reset a password.</p></div>

        <div class="panel glass">
            <form method="get" action="officer_users.php" class="filter-bar">
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, username, email, license or unit no." aria-label="Search users">
                <select name="role" aria-label="Filter by role">
                    <option value="">All roles</option>
                    <?php foreach (ROLE_LABEL as $r => $label): ?><option value="<?= e($r) ?>" <?= $role === $r ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                </select>
                <button class="btn ghost" type="submit">Search</button>
                <button class="btn" type="button" data-open="m-create">Add account</button>
            </form>
        </div>

        <div class="user-grid">
            <?php foreach ($users as $u): $active = (int)$u['is_active'] === 1; ?>
                <article class="item user-card">
                    <div class="user-head">
                        <div class="avatar sm"><?= e(strtoupper(firstChar($u['full_name']))) ?></div>
                        <div class="who"><b><?= e($u['full_name']) ?></b><span class="muted">@<?= e($u['username']) ?></span></div>
                        <span class="pill <?= e($u['role']) ?>"><?= e(ROLE_LABEL[$u['role']]) ?></span>
                    </div>
                    <dl class="kv compact">
                        <dt>Email</dt><dd><?= e($u['email']) ?></dd>
                        <?php if ($u['phone']): ?><dt>Phone</dt><dd><?= e($u['phone']) ?></dd><?php endif; ?>
                        <?php if ($u['license_number']): ?><dt>License</dt><dd class="mono"><?= e($u['license_number']) ?></dd><?php endif; ?>
                        <?php if ($u['unit_no']): ?><dt>Unit no.</dt><dd class="mono"><?= e($u['unit_no']) ?></dd><?php endif; ?>
                        <dt>Last login</dt><dd><?= e($u['last_login_at'] ?? 'Never') ?></dd>
                        <dt>Status</dt><dd><span class="pill <?= $active ? e($u['role']) : 'off' ?>"><?= $active ? 'Active' : 'Disabled' ?></span></dd>
                    </dl>
                    <div class="actions">
                        <button type="button" class="small-btn ghost" data-confirm data-action="reset" data-user="<?= (int)$u['id'] ?>"
                                data-title="Reset password?" data-text="A new temporary password will be generated for <?= e($u['full_name']) ?>. Their old password stops working." data-ok="Reset password">Reset password</button>
                        <?php if ((int)$u['id'] !== (int)$me['id']): ?>
                            <button type="button" class="small-btn <?= $active ? 'danger' : '' ?>" data-confirm data-action="toggle" data-user="<?= (int)$u['id'] ?>"
                                    data-title="<?= $active ? 'Disable this account?' : 'Enable this account?' ?>"
                                    data-text="<?= $active ? e($u['full_name']) . ' will be logged out and will not be able to log in.' : e($u['full_name']) . ' will be able to log in again.' ?>"
                                    data-ok="<?= $active ? 'Disable' : 'Enable' ?>"><?= $active ? 'Disable' : 'Enable' ?></button>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if (!$users): ?><div class="panel glass empty">No accounts match your search.</div><?php endif; ?>

        <!-- Add account -->
        <dialog class="modal" id="m-create" <?= $form ? 'data-autoopen="1"' : '' ?> aria-labelledby="m-create-title">
            <form method="post" action="officer_users.php" class="modal-body" novalidate>
                <div class="modal-head"><h3 id="m-create-title">Add an account</h3><button type="button" class="x" data-close aria-label="Close">&times;</button></div>
                <?= csrfField() ?><input type="hidden" name="action" value="create">
                <?php if (!empty($form['general'])): ?><div class="alert" role="alert"><?= e($form['general']) ?></div><?php endif; ?>

                <label for="c_role">Role</label>
                <select id="c_role" name="role">
                    <?php foreach (STAFF_ROLES as $r): ?><option value="<?= e($r) ?>" <?= ($old['role'] ?? 'driver') === $r ? 'selected' : '' ?>><?= e(ROLE_LABEL[$r]) ?></option><?php endforeach; ?>
                </select>
                <?= $err('role') ?>

                <div class="name-row">
                    <div class="grow"><label for="c_first">First name</label>
                        <input type="text" id="c_first" name="first_name" value="<?= $val('first_name') ?>" placeholder="e.g. Juan" maxlength="50" autocomplete="off" required>
                        <?= $err('first_name') ?></div>
                    <div class="mi"><label for="c_mi">M.I.</label>
                        <input type="text" id="c_mi" name="middle_initial" value="<?= $val('middle_initial') ?>" placeholder="e.g. D" maxlength="2" autocomplete="off">
                        <?= $err('middle_initial') ?></div>
                </div>
                <label for="c_last">Last name</label>
                <input type="text" id="c_last" name="last_name" value="<?= $val('last_name') ?>" placeholder="e.g. Dela Cruz" maxlength="50" autocomplete="off" required>
                <?= $err('last_name') ?>

                <label for="c_user">Username</label>
                <input type="text" id="c_user" name="username" value="<?= $val('username') ?>" placeholder="e.g. juan.delacruz" maxlength="30" autocomplete="off" required>
                <?= $err('username') ?>

                <label for="c_email">Email</label>
                <input type="email" id="c_email" name="email" value="<?= $val('email') ?>" placeholder="e.g. juan@example.com" autocomplete="off" required>
                <?= $err('email') ?>

                <label for="c_phone">Mobile number (optional)</label>
                <input type="tel" id="c_phone" name="phone" value="<?= $val('phone') ?>" placeholder="e.g. 0917 123 4567" autocomplete="off">
                <?= $err('phone') ?>

                <div id="driverFields">
                    <label for="c_license">Driver's license no.</label>
                    <input type="text" id="c_license" name="license_number" value="<?= $val('license_number') ?>" placeholder="e.g. N01-23-456789" maxlength="30" autocomplete="off">
                    <?= $err('license_number') ?>

                    <label for="c_expiry">License expiry date (optional)</label>
                    <input type="date" id="c_expiry" name="license_expiry" value="<?= $val('license_expiry') ?>" placeholder="YYYY-MM-DD" min="<?= e(date('Y-m-d')) ?>">
                    <?= $err('license_expiry') ?>

                    <label for="c_unit">Tricycle unit no.</label>
                    <input type="text" id="c_unit" name="unit_no" value="<?= $val('unit_no') ?>" placeholder="e.g. B-005" maxlength="20" autocomplete="off">
                    <?= $err('unit_no') ?>
                    <p class="hint">Type the unit number. If it is not in the system yet it is added automatically. A unit can belong to one driver only.</p>
                </div>

                <p class="hint">A temporary password is generated and shown once after the account is created.</p>
                <div class="modal-foot">
                    <button type="button" class="btn ghost" data-close>Cancel</button>
                    <button type="submit" class="btn">Create account</button>
                </div>
            </form>
        </dialog>

        <!-- Confirm (disable / enable / reset) -->
        <dialog class="modal small" id="m-confirm" aria-labelledby="m-confirm-title">
            <form method="post" action="officer_users.php" class="modal-body">
                <div class="modal-head"><h3 id="m-confirm-title">Are you sure?</h3><button type="button" class="x" data-close aria-label="Close">&times;</button></div>
                <?= csrfField() ?><input type="hidden" name="action" id="cf-action" value=""><input type="hidden" name="user_id" id="cf-user" value="">
                <p id="cf-text" class="note"></p>
                <div class="modal-foot">
                    <button type="button" class="btn ghost" data-close>Cancel</button>
                    <button type="submit" class="btn" id="cf-ok">Confirm</button>
                </div>
            </form>
        </dialog>

        <!-- One-time temporary password -->
        <dialog class="modal small" id="m-secret" <?= $secret ? 'data-autoopen="1"' : '' ?> aria-labelledby="m-secret-title">
            <div class="modal-body">
                <div class="modal-head"><h3 id="m-secret-title"><?= e($secret['title'] ?? 'Temporary password') ?></h3><button type="button" class="x" data-close aria-label="Close">&times;</button></div>
                <p class="note"><?= e($secret['lead'] ?? '') ?></p>
                <div class="secret"><strong class="mono" id="secret-pw"><?= e($secret['password'] ?? '') ?></strong></div>
                <div class="modal-foot">
                    <button type="button" class="btn ghost" id="copy-pw">Copy</button>
                    <button type="button" class="btn" data-close>Done</button>
                </div>
            </div>
        </dialog>
<?php dashFooter(['modals.js', 'officer_users.js']);
