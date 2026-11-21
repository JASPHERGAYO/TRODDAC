<?php
require __DIR__ . '/config/auth.php';
require __DIR__ . '/config/layout.php';
$me  = requireRole(['officer']);
$pdo = db();

function tempPassword(): string
{
    return 'Tr' . bin2hex(random_bytes(4)) . '9';
}

if (isPost()) {
    if (!verifyCsrf()) {
        flash('error', 'Your session expired. Please try again.');
        redirect('officer_users.php');
    }
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'create') {
            $role     = (string)($_POST['role'] ?? '');
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            $email    = trim((string)($_POST['email'] ?? ''));
            $phone    = trim((string)($_POST['phone'] ?? ''));
            $license  = trim((string)($_POST['license_number'] ?? ''));
            $expiry   = trim((string)($_POST['license_expiry'] ?? ''));
            $unitId   = (int)($_POST['default_unit_id'] ?? 0);

            $msg = null;
            if (!isset(ROLE_LABEL[$role])) {
                $msg = 'Choose a valid role.';
            } else {
                $msg = validateNewAccount($fullName, $username, $email, $phone);
            }
            if ($msg === null && $role === 'driver') {
                if (!preg_match('/^[A-Za-z0-9\-]{5,30}$/', $license)) {
                    $msg = 'Enter the driver\'s license number (5-30 letters, numbers or dashes).';
                } elseif ($expiry !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
                    $msg = 'License expiry must be a date.';
                } elseif ($unitId > 0 && (int)$pdo->query("SELECT COUNT(*) FROM tricycle_units WHERE status = 'active' AND id = " . $unitId)->fetchColumn() === 0) {
                    $msg = 'Choose an active tricycle unit.';
                }
            }
            if ($msg !== null) {
                flash('error', $msg);
            } else {
                $temp = tempPassword();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('INSERT INTO users (username, email, password, full_name, phone, role) VALUES (?,?,?,?,?,?)')
                        ->execute([$username, $email, password_hash($temp, PASSWORD_DEFAULT), $fullName, $phone !== '' ? $phone : null, $role]);
                    $newId = (int)$pdo->lastInsertId();
                    if ($role === 'driver') {
                        $pdo->prepare('INSERT INTO drivers (user_id, license_number, license_expiry, default_unit_id) VALUES (?,?,?,?)')
                            ->execute([$newId, $license, $expiry !== '' ? $expiry : null, $unitId > 0 ? $unitId : null]);
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                flash('success', "Account '$username' created. Temporary password: $temp (shown once, share it privately).");
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['user_id'] ?? 0);
            if ($id === (int)$me['id']) {
                flash('error', 'You cannot disable your own account.');
            } else {
                $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
                flash('success', 'Account status updated.');
            }
        } elseif ($action === 'reset') {
            $id   = (int)($_POST['user_id'] ?? 0);
            $temp = tempPassword();
            $st   = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $st->execute([password_hash($temp, PASSWORD_DEFAULT), $id]);
            $name = $pdo->prepare('SELECT username FROM users WHERE id = ?');
            $name->execute([$id]);
            $u = $name->fetchColumn();
            flash($u ? 'success' : 'error', $u ? "New temporary password for '$u': $temp (shown once)." : 'User not found.');
        }
    } catch (PDOException $e) {
        $msg = duplicateMessage($e);
        if ($msg === null && str_contains($e->getMessage(), 'TRD-E305')) {
            $msg = 'The last active Association Officer cannot be disabled.';
        }
        flash('error', $msg ?? 'The change could not be saved.');
    }
    redirect('officer_users.php');
}

$q = trim((string)($_GET['q'] ?? ''));
$sql = 'SELECT u.id, u.username, u.full_name, u.email, u.role, u.is_active, u.last_login_at, d.license_number, tu.plate_number
          FROM users u
          LEFT JOIN drivers d ON d.user_id = u.id
          LEFT JOIN tricycle_units tu ON tu.id = d.default_unit_id';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?';
    $params = array_fill(0, 3, '%' . $q . '%');
}
$sql .= ' ORDER BY FIELD(u.role, "officer","operator","driver","passenger"), u.full_name LIMIT 300';
$st = $pdo->prepare($sql);
$st->execute($params);
$users = $st->fetchAll();
$units = $pdo->query("SELECT id, plate_number FROM tricycle_units WHERE status = 'active' ORDER BY plate_number")->fetchAll();

dashHeader('Users', 'officer_users.php');
?>
        <div class="greet"><h2>Users and roles</h2><p>Create driver, operator and officer accounts, disable access, or reset a password.</p></div>

        <div class="panel glass">
            <h3>Add an account</h3>
            <form method="post" action="officer_users.php" id="createForm">
                <?= csrfField() ?><input type="hidden" name="action" value="create">
                <div class="form-grid">
                    <div><label for="c_role">Role</label>
                        <select id="c_role" name="role">
                            <?php foreach (['driver', 'operator', 'officer', 'passenger'] as $r): ?>
                                <option value="<?= $r ?>"><?= e(ROLE_LABEL[$r]) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div><label for="c_name">Full name</label><input type="text" id="c_name" name="full_name" required></div>
                    <div><label for="c_user">Username</label><input type="text" id="c_user" name="username" required></div>
                    <div><label for="c_email">Email</label><input type="email" id="c_email" name="email" required></div>
                    <div><label for="c_phone">Phone (optional)</label><input type="text" id="c_phone" name="phone"></div>
                </div>
                <div class="form-grid" id="driverFields">
                    <div><label for="c_license">Driver license no.</label><input type="text" id="c_license" name="license_number"></div>
                    <div><label for="c_expiry">License expiry (optional)</label><input type="date" id="c_expiry" name="license_expiry"></div>
                    <div><label for="c_unit">Default tricycle unit (optional)</label>
                        <select id="c_unit" name="default_unit_id">
                            <option value="0">None</option>
                            <?php foreach ($units as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['plate_number']) ?></option><?php endforeach; ?>
                        </select></div>
                </div>
                <p class="hint">A temporary password is generated and shown once after the account is created.</p>
                <button class="btn" type="submit">Create account</button>
            </form>
        </div>

        <div class="panel glass">
            <h3>All accounts</h3>
            <form method="get" action="officer_users.php" class="toolbar">
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, username or email">
                <button class="small-btn" type="submit">Search</button>
            </form>
            <table>
                <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Driver license</th><th>Unit</th><th>Last login</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= e($u['full_name']) ?><br><span class="hint"><?= e($u['email']) ?></span></td>
                        <td><?= e($u['username']) ?></td>
                        <td><span class="pill <?= e($u['role']) ?>"><?= e(ROLE_LABEL[$u['role']]) ?></span></td>
                        <td class="mono"><?= e($u['license_number']) ?></td>
                        <td><?= e($u['plate_number']) ?></td>
                        <td><?= e($u['last_login_at'] ?? 'Never') ?></td>
                        <td><span class="pill <?= (int)$u['is_active'] ? e($u['role']) : 'off' ?>"><?= (int)$u['is_active'] ? 'Active' : 'Disabled' ?></span></td>
                        <td>
                            <form method="post" action="officer_users.php" class="inline-form">
                                <?= csrfField() ?><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <button class="small-btn ghost" name="action" value="reset" type="submit">Reset password</button>
                                <?php if ((int)$u['id'] !== (int)$me['id']): ?>
                                    <button class="small-btn <?= (int)$u['is_active'] ? 'danger' : '' ?>" name="action" value="toggle" type="submit"><?= (int)$u['is_active'] ? 'Disable' : 'Enable' ?></button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?><tr><td colspan="8" class="hint">No accounts match.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <script>
            const roleSelect = document.getElementById('c_role');
            const driverFields = document.getElementById('driverFields');
            const licenseInput = document.getElementById('c_license');
            function syncDriverFields() {
                const isDriver = roleSelect.value === 'driver';
                driverFields.style.display = isDriver ? '' : 'none';
                licenseInput.required = isDriver;
            }
            roleSelect.addEventListener('change', syncDriverFields);
            syncDriverFields();
        </script>
<?php dashFooter();
