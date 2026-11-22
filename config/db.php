<?php
declare(strict_types=1);

function appConfig(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
        // Lets tests point the routing service somewhere else without editing config.php.
        if (($override = getenv('TRODDAC_OSRM_BASE')) !== false && $override !== '') {
            $config['routing']['osrm_base'] = $override;
        }
    }
    return $config;
}

/** One shared PDO connection per request. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = appConfig()['db'];
        try {
            $pdo = new PDO(
                "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset={$c['charset']}",
                $c['user'],
                $c['pass'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            exit('Database connection failed. Start MySQL in XAMPP, then run: php database\\migrate.php migrate');
        }
    }
    return $pdo;
}

/** True when the error means the database structure is older than the code (a migration was not run). */
function isSchemaError(Throwable $e): bool
{
    return $e instanceof PDOException && in_array((string)$e->getCode(), ['42S22', '42S02'], true);
}

const SCHEMA_HELP = 'The database is out of date. Ask the administrator to run: php database\\migrate.php migrate';

/** Migration files that have not been applied to this database yet. */
function pendingMigrations(): array
{
    $names = array_map('basename', array_merge(glob(__DIR__ . '/../database/migrations/*.sql') ?: [], glob(__DIR__ . '/../database/migrations/*.php') ?: []));
    sort($names);
    try {
        $done = db()->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return $names;
    }
    return array_values(array_diff($names, $done));
}

/** Uncaught errors: never show SQL or file paths to visitors; explain an out-of-date database clearly. */
set_exception_handler(static function (Throwable $e): void {
    error_log('TRODDAC uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    $message = isSchemaError($e) ? SCHEMA_HELP : 'Something went wrong on our side. Please try again in a moment.';
    $isApi = str_contains((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/');
    if ($isApi) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => $message]);
    } else {
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<title>TRODDAC</title><link rel="stylesheet" href="assets/css/style.css"></head><body><main class="card"><h1>TRODDAC</h1>'
           . '<div class="alert">' . htmlspecialchars($message) . '</div></main></body></html>';
    }
});

/* ---------------------------------------------------------------- schema checks
 * New features check that the tables/columns they need exist, so an un-migrated database
 * produces a clear message instead of a crash.                                          */

function schemaHas(string $table, ?string $column = null): bool
{
    static $cache = [];
    $key = $table . '.' . ($column ?? '*');
    if (!array_key_exists($key, $cache)) {
        try {
            if ($column === null) {
                $st = db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
                $st->execute([$table]);
            } else {
                $st = db()->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
                $st->execute([$table, $column]);
            }
            $cache[$key] = (int)$st->fetchColumn() > 0;
        } catch (PDOException $e) {
            error_log('TRODDAC schema check failed: ' . $e->getMessage());
            $cache[$key] = false;
        }
    }
    return $cache[$key];
}

/** @param array<string, string[]> $needs table => [columns] (empty list = table only). Returns what is missing. */
function schemaMissing(array $needs): array
{
    $missing = [];
    foreach ($needs as $table => $columns) {
        if (!schemaHas($table)) {
            $missing[] = $table;
            continue;
        }
        foreach ($columns as $column) {
            if (!schemaHas($table, $column)) {
                $missing[] = $table . '.' . $column;
            }
        }
    }
    return $missing;
}
