<?php
/**
 * Runs the automatic route assignment engine once. Schedule it every minute for hands-free operation.
 *
 *   D:\xampp\php\php.exe engine\run.php
 *   D:\xampp\php\php.exe engine\run.php --route=1
 *   D:\xampp\php\php.exe engine\run.php --now="2026-11-23 05:00:00"     (pretend it is that time)
 *
 * Windows Task Scheduler: create a basic task that repeats every 1 minute and runs the first command above.
 * (The dispatch and driver screens also run the engine about once a minute while they are open.)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../lib/AssignmentEngine.php';

$opts = getopt('', ['now::', 'route::']);
$engine = new AssignmentEngine(db(), 'cli');
$result = $engine->run($opts['now'] ?? null, isset($opts['route']) ? (int)$opts['route'] : null);

if (!empty($result['locked'])) {
    echo "Another run is already in progress.\n";
    exit(0);
}
if (empty($result['ok'])) {
    echo 'Error: ' . ($result['error'] ?? 'unknown') . "\n";
    exit(1);
}
printf("Run #%d at %s: %d slot(s) checked, %d assigned, %d skipped, %d unfilled, %d started, %d finished, %d missed.\n",
    $result['run_id'], $result['now'], $result['slots'], $result['assigned'], $result['skipped'], $result['unfilled'],
    $result['activated'], $result['completed'], $result['missed']);
