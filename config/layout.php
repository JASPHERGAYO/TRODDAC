<?php
declare(strict_types=1);

function icon(string $name): string
{
    static $paths = [
        'home'     => '<path d="M3 10l9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
        'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M21 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
        'user'     => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'chart'    => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
        'car'      => '<path d="M3 17h18v-4l-2-6H5l-2 6z"/><circle cx="7.5" cy="17" r="1.6"/><circle cx="16.5" cy="17" r="1.6"/>',
        'map'      => '<path d="M9 4L3 6v14l6-2 6 2 6-2V4l-6 2z"/><path d="M9 4v14M15 6v14"/>',
        'ticket'   => '<path d="M3 9a2 2 0 0 1 0 6v3h18v-3a2 2 0 0 1 0-6V6H3z"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'pin'      => '<path d="M12 21s7-6.2 7-11a7 7 0 0 0-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'coin'     => '<circle cx="12" cy="12" r="9"/><path d="M14.5 9.2A2.6 2.6 0 0 0 12 7.8c-1.4 0-2.5.8-2.5 2s1.1 1.8 2.5 2.1 2.5.8 2.5 2-1.1 2-2.5 2a2.6 2.6 0 0 1-2.5-1.4M12 6v1.8M12 16.2V18"/>',
        'flag'     => '<path d="M5 21V4M5 4h11l-2 4 2 4H5"/>',
        'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    ];
    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/** Navigation links per role: [label, icon, href]. Only pages that exist are listed. */
function roleNav(string $role): array
{
    $items = match ($role) {
        'passenger' => [['Home', 'home', 'passenger_dashboard.php'], ['Book', 'pin', 'passenger_book.php'], ['My trips', 'clock', 'passenger_history.php'], ['Report', 'flag', 'passenger_report.php']],
        'driver'    => [['Home', 'home', 'driver_dashboard.php']],
        'operator'  => [['Home', 'home', 'operator_dashboard.php'], ['Trips', 'map', 'operator_trips.php'], ['Routes', 'clock', 'operator_assignments.php']],
        'officer'   => [['Home', 'home', 'officer_dashboard.php'], ['Users', 'users', 'officer_users.php'], ['Routes', 'clock', 'operator_assignments.php'], ['Fares', 'coin', 'officer_fares.php'], ['Reports', 'flag', 'officer_reports.php']],
        default     => [],
    };
    $items[] = ['Account', 'user', 'account.php'];
    return $items;
}

/** Human-friendly date/time: "Today · 8:07 PM", "Yesterday · 9:15 AM", "Nov 25 · 8:07 PM", "Nov 25, 2025 · 8:07 PM". */
function friendlyTime(?string $dt): string
{
    if (!$dt) {
        return '';
    }
    try {
        $t = new DateTimeImmutable($dt);
    } catch (Exception) {
        return $dt;
    }
    $today = new DateTimeImmutable('today');
    $day   = $t->setTime(0, 0);
    $clock = $t->format('g:i A');
    if ($day == $today) {
        return 'Today · ' . $clock;
    }
    if ($day == $today->modify('-1 day')) {
        return 'Yesterday · ' . $clock;
    }
    return $t->format($t->format('Y') === $today->format('Y') ? 'M j' : 'M j, Y') . ' · ' . $clock;
}

/** <time> element with a friendly label and the exact timestamp on hover. */
function timeTag(?string $dt): string
{
    return $dt ? '<time datetime="' . e(str_replace(' ', 'T', $dt)) . '" title="' . e($dt) . '">' . e(friendlyTime($dt)) . '</time>' : '';
}

/** Pickup → drop-off as a two-stop line. */
function routeStops(string $from, string $to): string
{
    return '<span class="stops"><span class="stop from">' . e($from) . '</span><span class="stop to">' . e($to) . '</span></span>';
}

/** $opts: 'maps' => true loads Leaflet's stylesheet for pages with a map. */
function dashHeader(string $title, string $activeHref, array $opts = []): void
{
    $user        = currentUser();
    $role        = $user['role'];
    $name        = e($user['full_name']);
    $initial     = e(strtoupper(firstChar($user['full_name'])));
    $roleName    = e(ROLE_LABEL[$role] ?? $role);
    $portalTitle = e((ROLE_LABEL[$role] ?? ucfirst($role)) . ' Portal');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0b6b23">
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <title><?= e($title) ?> - TRODDAC</title>
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link rel="preload" href="assets/fonts/public-sans-latin-wght.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="assets/css/style.css">
    <?php if (!empty($opts['maps'])): ?><link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css"><?php endif; ?>
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body class="dash role-<?= e($role) ?>">
<div class="app">
    <nav class="sidebar" aria-label="Main">
        <div class="side-brand">
            <img src="logo/logo.png" alt="TRODDAC Seal" class="logo-seal lg">
            <div class="side-brand-text">
                <strong>TRODDAC</strong>
                <span class="portal-name"><?= $portalTitle ?></span>
            </div>
        </div>
        <p class="side-label">Menu</p>
        <div class="side-nav">
            <?php foreach (roleNav($role) as [$label, $ico, $href]): ?>
                <a href="<?= e($href) ?>" class="<?= $href === $activeHref ? 'active' : '' ?>"<?= $href === $activeHref ? ' aria-current="page"' : '' ?>><?= icon($ico) ?><span><?= e($label) ?></span></a>
            <?php endforeach; ?>
        </div>
        <div class="side-user">
            <div class="avatar sm" aria-hidden="true"><?= $initial ?></div>
            <div class="side-user-text"><b><?= $name ?></b><small><?= $roleName ?></small></div>
            <form method="post" action="logout.php" class="logout-form side-logout">
                <?= csrfField() ?>
                <button type="submit" class="btn-logout" aria-label="Log out" title="Log out"><?= icon('logout') ?></button>
            </form>
        </div>
    </nav>

    <div class="workspace">
        <header class="topbar">
            <div class="logo">
                <img src="logo/logo.png" alt="TRODDAC Seal" class="logo-seal">
                <div class="logo-text">
                    <strong>TRODDAC</strong>
                    <small>Dasmari&ntilde;as City Tricycle Association</small>
                </div>
            </div>
            <div class="page-id">
                <span class="crumb"><?= $portalTitle ?></span>
                <h1 class="page-title"><?= e($title) ?></h1>
            </div>
            <div class="who">
                <span class="top-date"><?= e(date('D, M j')) ?></span>
                <span class="role-tag"><?= $roleName ?></span>
                <span class="avatar sm" title="<?= $name ?>" aria-hidden="true"><?= $initial ?></span>
                <form method="post" action="logout.php" class="logout-form mobile-logout"><?= csrfField() ?><button type="submit" aria-label="Log out" title="Log out"><?= icon('logout') ?></button></form>
            </div>
        </header>

        <main class="main">
            <?php
            // Warn when the code is newer than the database (a migration has not been run).
            if (empty($_SESSION['schema_check']) || time() - (int)$_SESSION['schema_check']['at'] > 60) {
                $_SESSION['schema_check'] = ['at' => time(), 'pending' => pendingMigrations()];
            }
            $pendingDb = $_SESSION['schema_check']['pending'];
            if ($pendingDb): ?>
                <div class="flash error glass" role="alert"><?= $role === 'officer' || $role === 'operator'
                    ? 'Database update needed (' . count($pendingDb) . ' pending). Run: php database\\migrate.php migrate'
                    : 'The system is being updated. Some features may not work yet.' ?></div>
            <?php endif; ?>
            <?php foreach (takeFlashes() as $f): ?>
                <div class="flash <?= e($f['type']) ?> glass" role="status"><?= e($f['message']) ?></div>
            <?php endforeach; ?>
<?php
}

/**
 * $scripts: files under assets/js to load (Leaflet is loaded first when 'maps' is requested).
 * $boot: page data handed to the scripts as JSON (read by assets/js/boot.js).
 */
function dashFooter(array $scripts = [], array $boot = [], bool $maps = false): void
{
    $cfg  = appConfig();
    $boot += [
        'csrf'    => csrfToken(),
        'poll'    => (int)$cfg['poll_seconds'],
        'map'     => [
            'tileUrl'     => $cfg['map']['tile_url'],
            'attribution' => $cfg['map']['attribution'],
            'zoom'        => $cfg['map']['default_zoom'],
            'center'      => [$cfg['service_area']['center_lat'], $cfg['service_area']['center_lng']],
            'radiusKm'    => $cfg['service_area']['radius_km'],
        ],
    ];
    echo "        </main>\n    </div>\n</div>\n";
    echo '<script type="application/json" id="boot">' . json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . "</script>\n";
    echo '<script src="assets/js/boot.js"></script>' . "\n";
    if ($maps) {
        echo '<script src="assets/vendor/leaflet/leaflet.js"></script>' . "\n";
        echo '<script src="assets/js/maps.js"></script>' . "\n";
    }
    foreach ($scripts as $file) {
        echo '<script src="assets/js/' . e($file) . '"></script>' . "\n";
    }
    echo "</body>\n</html>\n";
}

/** Stops a page politely when its tables/columns are missing (a migration was not run). Call after requireRole(). */
function requireSchemaOrExplain(array $needs, string $title, string $activeHref): void
{
    if (!schemaMissing($needs)) {
        return;
    }
    dashHeader($title, $activeHref);
    echo '<div class="panel glass"><h3>Database update needed</h3><p class="note">' . e(SCHEMA_HELP) . '</p></div>';
    dashFooter();
    exit;
}
