<?php
// POST  -  the Association Officer runs the assignment engine right now (the engine also runs by itself).
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/api.php';
require __DIR__ . '/../lib/AssignmentEngine.php';
$user = requireApiRole(['officer']);
apiMustBePost();

$result = (new AssignmentEngine(db(), 'manual', (int)$user['id']))->run();
if (!empty($result['locked'])) {
    apiFail('The engine is already running. Try again in a few seconds.', 409);
}
if (empty($result['ok'])) {
    apiFail($result['error'] ?? 'The engine failed.', 500);
}
jsonOut($result);
