<?php
// POST JSON {id, reason?}  -  a passenger cancels their own booking before the trip starts.
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/api.php';
$user = requireApiRole(['passenger']);
apiMustBePost();

$in = apiInput();
$id = (int)($in['id'] ?? 0);
$reason = trim(strip_tags((string)($in['reason'] ?? '')));
$reason = $reason === '' ? 'Cancelled by passenger' : (preg_match('/^.{1,255}/us', $reason, $m) ? $m[0] : substr($reason, 0, 255));

$pdo = db();
$st = $pdo->prepare('SELECT status FROM bookings WHERE id = ? AND passenger_id = ?');
$st->execute([$id, $user['id']]);
$status = $st->fetchColumn();
if ($status === false) {
    apiFail('Booking not found.', 404);
}
if (!in_array($status, ['available', 'assigned', 'en_route'], true)) {
    apiFail($status === 'in_progress' ? 'A trip that has already started cannot be cancelled.' : 'This booking is already finished.', 409);
}
try {
    $up = $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancel_reason = ? WHERE id = ? AND passenger_id = ? AND status IN ('available','assigned','en_route')");
    $up->execute([$reason, $id, $user['id']]);
} catch (PDOException $e) {
    apiFail(dbErrorMessage($e), 409);
}
if ($up->rowCount() === 0) {
    apiFail('The booking just changed. Refresh and try again.', 409);
}
jsonOut(['ok' => true, 'status' => 'cancelled']);
