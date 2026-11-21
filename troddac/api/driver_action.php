<?php
// POST JSON {id, action}   action: on_the_way | picked_up | complete | decline
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/api.php';
$user = requireApiRole(['driver']);
apiMustBePost();

$in     = apiInput();
$id     = (int)($in['id'] ?? 0);
$action = (string)($in['action'] ?? '');
$driver = driverRowFor((int)$user['id']);
if ($driver === null) {
    apiFail('Driver profile not found.', 404);
}

// action => [allowed current statuses, new status, message when the booking is not in an allowed status]
$rules = [
    'on_the_way' => [['assigned'],              'en_route',    'You can only start heading to the pickup right after being assigned.'],
    'picked_up'  => [['assigned', 'en_route'],  'in_progress', 'The passenger can only be picked up after the booking is assigned.'],
    'complete'   => [['in_progress'],           'completed',   'Pick the passenger up before completing the trip.'],
    'decline'    => [['assigned'],              'pending',     'A trip can only be declined before you head to the pickup.'],
];
if (!isset($rules[$action])) {
    apiFail('Unknown action.');
}
[$from, $to, $message] = $rules[$action];

$pdo = db();
$st = $pdo->prepare('SELECT status FROM bookings WHERE id = ? AND driver_id = ?');
$st->execute([$id, $driver['id']]);
$status = $st->fetchColumn();
if ($status === false) {
    apiFail('This booking is not assigned to you.', 404);
}
if (!in_array($status, $from, true)) {
    apiFail($message, 409);
}
try {
    $marks = implode(',', array_fill(0, count($from), '?'));
    $up = $pdo->prepare("UPDATE bookings SET status = ? WHERE id = ? AND driver_id = ? AND status IN ($marks)");
    $up->execute(array_merge([$to, $id, $driver['id']], $from));
} catch (PDOException $e) {
    apiFail(dbErrorMessage($e), 409);
}
if ($up->rowCount() === 0) {
    apiFail('The booking just changed. Refresh and try again.', 409);
}
jsonOut(['ok' => true, 'status' => $to]);
