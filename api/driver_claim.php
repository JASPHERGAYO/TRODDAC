<?php
// POST JSON {id}  -  an online driver accepts an available booking. Only the first driver succeeds.
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/api.php';
require __DIR__ . '/../lib/Bookings.php';
$user = requireApiRole(['driver']);
apiMustBePost();

$driver = driverRowFor((int)$user['id']);
if ($driver === null) {
    apiFail('Your driver profile has not been set up. Ask the Association Officer.', 404);
}
$result = Bookings::claim(db(), (int)$driver['id'], (int)$user['id'], (int)(apiInput()['id'] ?? 0));
if ($result['ok']) {
    jsonOut(['ok' => true, 'code' => $result['code'], 'message' => $result['message']]);
}
jsonOut(['ok' => false, 'error' => $result['message'], 'code' => $result['code']], $result['status']);
