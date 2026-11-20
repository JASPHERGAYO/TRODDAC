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
        'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    ];
    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/** Sidebar links per role: [label, icon, href]. Only pages that exist are listed. */
function roleNav(string $role): array
{
    $home = [['Dashboard', 'home', roleHome($role)]];
    $extra = $role === 'officer' ? [['Users', 'users', 'officer_users.php']] : [];
    return array_merge($home, $extra, [['My account', 'user', 'account.php']]);
}

function dashHeader(string $title, string $activeHref): void
{
    $user     = currentUser();
    $role     = $user['role'];
    $name     = e($user['full_name']);
    $initial  = e(strtoupper(firstChar($user['full_name'])));
    $roleName = e(ROLE_LABEL[$role] ?? $role);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> - TRODDAC</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body class="dash role-<?= e($role) ?>">
<div class="app">
    <aside class="sidebar glass">
        <div class="logo"><span class="logo-mark"><?= icon('shield') ?></span><span>TRODDAC</span></div>
        <p class="side-label">Menu</p>
        <nav class="side-nav">
            <?php foreach (roleNav($role) as [$label, $ico, $href]): ?>
                <a href="<?= e($href) ?>" class="<?= $href === $activeHref ? 'active' : '' ?>"><?= icon($ico) ?><span><?= e($label) ?></span></a>
            <?php endforeach; ?>
        </nav>
        <div class="side-user">
            <div class="avatar sm"><?= $initial ?></div>
            <div><b><?= $name ?></b><small><?= $roleName ?></small></div>
            <form method="post" action="logout.php" class="logout-form"><?= csrfField() ?><button type="submit" aria-label="Log out" title="Log out"><?= icon('logout') ?></button></form>
        </div>
    </aside>
    <main class="main">
        <?php foreach (takeFlashes() as $f): ?>
            <div class="flash <?= e($f['type']) ?> glass" role="status"><?= e($f['message']) ?></div>
        <?php endforeach; ?>
<?php
}

function dashFooter(): void
{
    echo "    </main>\n</div>\n</body>\n</html>\n";
}
