<?php
// Helper for phase6_claim_test.php: waits for a start time, then claims (or cancels) a booking, so several
// processes act at the same instant. Usage: php claim_worker.php <claim|cancel> <driverId> <userId> <bookingId> <startAt>
declare(strict_types=1);
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/api.php';
require __DIR__ . '/../lib/Bookings.php';

[, $mode, $driverId, $userId, $bookingId, $startAt] = $argv;
$pdo = db();                                   // connect first, so only the claim itself is timed
while (microtime(true) < (float)$startAt) {
    usleep(200);
}
if ($mode === 'cancel') {
    $st = $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancel_reason = 'race test' WHERE id = ? AND status IN ('available','assigned','en_route')");
    $st->execute([(int)$bookingId]);
    echo json_encode(['ok' => $st->rowCount() === 1, 'code' => 'cancel']);
} else {
    echo json_encode(Bookings::claim($pdo, (int)$driverId, (int)$userId, (int)$bookingId));
}
