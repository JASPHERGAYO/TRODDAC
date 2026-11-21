<?php
// POST JSON {status: "online"|"offline"}
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/api.php';
$user = requireApiRole(['driver']);
apiMustBePost();

$status = (string)(apiInput()['status'] ?? '');
if (!in_array($status, ['online', 'offline'], true)) {
    apiFail('Status must be online or offline.');
}
$driver = driverRowFor((int)$user['id']);
if ($driver === null || (int)$driver['is_active'] !== 1) {
    apiFail('Your driver profile is not active.', 403);
}
try {
    db()->prepare('UPDATE drivers SET status = ? WHERE id = ?')->execute([$status, $driver['id']]);
} catch (PDOException $e) {
    apiFail(dbErrorMessage($e), 409);
}
jsonOut(['ok' => true, 'availability' => $status]);
