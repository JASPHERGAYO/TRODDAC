<?php
// POST JSON {action: assign|reassign|unassign|reject, booking_id, driver_id?, unit_id?, reason?}
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../config/api.php';
$user = requireApiRole(['operator', 'officer']);
apiMustBePost();

$in     = apiInput();
$action = (string)($in['action'] ?? '');
$id     = (int)($in['booking_id'] ?? 0);
$pdo    = db();

try {
    switch ($action) {
        case 'assign':
        case 'reassign':
            $driverId = (int)($in['driver_id'] ?? 0);
            $unitId   = (int)($in['unit_id'] ?? 0);
            if ($driverId <= 0) {
                apiFail('Choose a driver.');
            }
            if ($unitId <= 0) {
                $st = $pdo->prepare('SELECT default_unit_id FROM drivers WHERE id = ?');
                $st->execute([$driverId]);
                $unitId = (int)$st->fetchColumn();
            }
            if ($unitId <= 0) {
                apiFail('Choose a tricycle unit. This driver has no default unit.');
            }
            if ($action === 'assign') {
                $up = $pdo->prepare("UPDATE bookings SET status = 'assigned', driver_id = ?, unit_id = ?, assigned_by = ? WHERE id = ? AND status = 'pending'");
                $up->execute([$driverId, $unitId, $user['id'], $id]);
            } else {
                $up = $pdo->prepare("UPDATE bookings SET driver_id = ?, unit_id = ?, assigned_by = ? WHERE id = ? AND status = 'assigned'");
                $up->execute([$driverId, $unitId, $user['id'], $id]);
            }
            break;

        case 'unassign':
            $up = $pdo->prepare("UPDATE bookings SET status = 'pending' WHERE id = ? AND status = 'assigned'");
            $up->execute([$id]);
            break;

        case 'reject':
            $reason = trim(strip_tags((string)($in['reason'] ?? '')));
            $reason = $reason === '' ? 'No tricycle available' : (preg_match('/^.{1,255}/us', $reason, $m) ? $m[0] : substr($reason, 0, 255));
            $up = $pdo->prepare("UPDATE bookings SET status = 'rejected', cancel_reason = ? WHERE id = ? AND status = 'pending'");
            $up->execute([$reason, $id]);
            break;

        default:
            apiFail('Unknown action.');
    }
} catch (PDOException $e) {
    apiFail(dbErrorMessage($e), 409);
}

if ($up->rowCount() === 0) {
    apiFail('That booking was already handled or changed. The list has been refreshed.', 409);
}
jsonOut(['ok' => true]);
