<?php
/**
 * TRODDAC migration runner.
 *
 *   CLI (XAMPP):  C:\xampp\php\php.exe database\migrate.php [migrate|status|seed|fresh] [--yes]
 *   Browser:      http://localhost/TRODDAC/database/migrate.php?action=migrate   (localhost only)
 *
 *   migrate  create the database if needed and apply every pending migration
 *   status   list applied / pending migrations
 *   seed     insert sample data (skipped if users already exist)
 *   fresh    DROP the database, re-create it, migrate and seed  (needs --yes / &confirm=yes)
 */
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';
$isCli  = PHP_SAPI === 'cli';

if (!$isCli) {
    if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', $config['tools_allowed_ips'], true)) {
        http_response_code(403);
        exit('Forbidden: this tool only runs from localhost.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$action    = $isCli ? ($argv[1] ?? 'migrate') : ($_GET['action'] ?? 'migrate');
$confirmed = $isCli ? in_array('--yes', $argv ?? [], true) : (($_GET['confirm'] ?? '') === 'yes');

function out(string $line): void { echo $line . PHP_EOL; }

function connect(array $db, bool $withDatabase): PDO {
    $dsn = "mysql:host={$db['host']};port={$db['port']};charset={$db['charset']}" . ($withDatabase ? ";dbname={$db['name']}" : '');
    return new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

/** Split an SQL script into statements, honouring `DELIMITER xx` lines (needed for triggers). */
function splitSql(string $sql): array {
    $statements = [];
    $delimiter  = ';';
    $buffer     = '';
    foreach (preg_split('/\R/', $sql) as $line) {
        $trim = trim($line);
        if (preg_match('/^DELIMITER\s+(\S+)\s*$/i', $trim, $m)) {
            $delimiter = $m[1];
            continue;
        }
        if ($buffer === '' && ($trim === '' || str_starts_with($trim, '--'))) {
            continue;
        }
        $buffer .= $line . "\n";
        if (str_ends_with(rtrim($buffer), $delimiter)) {
            $stmt = rtrim($buffer);
            $stmt = trim(substr($stmt, 0, -strlen($delimiter)));
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }
    return $statements;
}

function migrationFiles(): array {
    $files = array_merge(glob(__DIR__ . '/migrations/*.sql') ?: [], glob(__DIR__ . '/migrations/*.php') ?: []);
    usort($files, static fn($a, $b) => strcmp(basename($a), basename($b)));
    return $files;
}

function ensureTracking(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        version    VARCHAR(150) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function appliedVersions(PDO $pdo): array {
    return array_column($pdo->query('SELECT version FROM schema_migrations')->fetchAll(), 'version');
}

function runMigrations(array $db): void {
    $root = connect($db, false);
    $root->exec("CREATE DATABASE IF NOT EXISTS `{$db['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = connect($db, true);
    ensureTracking($pdo);
    $done = appliedVersions($pdo);
    $ran  = 0;

    foreach (migrationFiles() as $file) {
        $version = basename($file);
        if (in_array($version, $done, true)) {
            continue;
        }
        out("Applying $version ...");
        if (str_ends_with($file, '.php')) {
            // A PHP migration returns a function(PDO $pdo) that checks what exists before changing anything.
            try {
                $apply = require $file;
                $apply($pdo);
            } catch (Throwable $e) {
                out("FAILED in $version: " . $e->getMessage());
                exit(1);
            }
            $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
            $ran++;
            continue;
        }
        foreach (splitSql(file_get_contents($file)) as $i => $stmt) {
            try {
                $pdo->exec($stmt);
            } catch (PDOException $e) {
                out("FAILED in $version (statement " . ($i + 1) . "): " . $e->getMessage());
                out(substr($stmt, 0, 200));
                exit(1);
            }
        }
        $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
        $ran++;
    }
    out($ran === 0 ? 'Nothing to migrate. Database is up to date.' : "Done. Applied $ran migration(s).");
}

try {
    $db = $config['db'];

    switch ($action) {
        case 'migrate':
            runMigrations($db);
            break;

        case 'status':
            $pdo = connect($db, true);
            ensureTracking($pdo);
            $done = appliedVersions($pdo);
            foreach (migrationFiles() as $file) {
                out((in_array(basename($file), $done, true) ? '[applied] ' : '[pending] ') . basename($file));
            }
            break;

        case 'seed':
            require __DIR__ . '/seed.php';
            runSeed(connect($db, true));
            break;

        case 'fresh':
            if (!$confirmed) {
                out('This DROPS the database "' . $db['name'] . '" and all its data.');
                out($isCli ? 'Run again with --yes to continue.' : 'Add &confirm=yes to the URL to continue.');
                exit(1);
            }
            connect($db, false)->exec("DROP DATABASE IF EXISTS `{$db['name']}`");
            out('Dropped database ' . $db['name'] . '.');
            runMigrations($db);
            require __DIR__ . '/seed.php';
            runSeed(connect($db, true));
            break;

        default:
            out("Unknown action '$action'. Use migrate, status, seed or fresh.");
            exit(1);
    }
} catch (Throwable $e) {
    out('Error: ' . $e->getMessage());
    exit(1);
}
