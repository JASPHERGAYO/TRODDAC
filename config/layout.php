<?php
function icon(string $name): string {
    static $paths = [
        'home'     => '<path d="M3 10l9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
        'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M21 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
        'chart'    => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
        'car'      => '<path d="M3 17h18v-4l-2-6H5l-2 6z"/><circle cx="7.5" cy="17" r="1.6"/><circle cx="16.5" cy="17" r="1.6"/>',
        'map'      => '<path d="M9 4L3 6v14l6-2 6 2 6-2V4l-6 2z"/><path d="M9 4v14M15 6v14"/>',
        'ticket'   => '<path d="M3 9a2 2 0 0 1 0 6v3h18v-3a2 2 0 0 1 0-6V6H3z"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/>',
        'bell'     => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    ];
    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

// Sidebar links per role: [label, icon, href]
function roleNav(string $role): array {
    $map = [
        'admin'     => [['Dashboard','home','admin_dashboard.php'], ['Users','users','#users'], ['Role breakdown','chart','#roles'], ['Settings','settings','#']],
        'operator'  => [['Dashboard','home','operator_dashboard.php'], ['Drivers','car','#drivers'], ['Dispatch','map','#dispatch'], ['Reports','chart','#']],
        'driver'    => [['Dashboard','home','driver_dashboard.php'], ['My trips','map','#trips'], ['Schedule','clock','#']],
        'passenger' => [['Dashboard','home','passenger_dashboard.php'], ['Book a ride','ticket','#book'], ['My trips','clock','#history']],
    ];
    return $map[$role] ?? [];
}

// Tiny inline sparkline from a list of numbers
function sparkline(array $vals, string $color = 'currentColor'): string {
    $n = count($vals);
    if ($n < 2) return '';
    $max = max($vals); $min = min($vals); $range = max($max - $min, 1);
    $pts = [];
    foreach ($vals as $i => $v) {
        $x = round($i / ($n - 1) * 100, 1);
        $y = round(28 - (($v - $min) / $range) * 24, 1);
        $pts[] = "$x,$y";
    }
    return '<svg class="spark" viewBox="0 0 100 32" preserveAspectRatio="none" aria-hidden="true"><polyline fill="none" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" points="' . implode(' ', $pts) . '"/></svg>';
}

function dashHeader(string $title): void {
    $role     = $_SESSION['role'];
    $name     = htmlspecialchars($_SESSION['username']);
    $initial  = htmlspecialchars(strtoupper(substr($_SESSION['username'], 0, 1)));
    $roleName = htmlspecialchars(ucfirst($role));
    $title    = htmlspecialchars($title);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $title ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body class="dash role-<?= $role ?>">
<div class="app">

    <!-- Menu sidebar -->
    <aside class="sidebar glass">
        <div class="logo"><span class="logo-mark"><?= icon('shield') ?></span><span><?= $roleName ?> panel</span></div>
        <p class="side-label">Menu</p>
        <nav class="side-nav">
            <?php foreach (roleNav($role) as $i => [$label, $ico, $href]): ?>
                <a href="<?= htmlspecialchars($href) ?>" class="<?= $i === 0 ? 'active' : '' ?>"><?= icon($ico) ?><span><?= htmlspecialchars($label) ?></span></a>
            <?php endforeach; ?>
        </nav>
        <div class="side-user">
            <div class="avatar sm"><?= $initial ?></div>
            <div><b><?= $name ?></b><small><?= $roleName ?></small></div>
            <a href="logout.php" aria-label="Log out"><?= icon('logout') ?></a>
        </div>
    </aside>

    <!-- Main -->
    <main class="main">
        
<?php
}
function dashFooter(): void {
    ?>
    </main>
</div>
<script src="assets/js/dash.js"></script>
</body>
</html>
<?php
}
