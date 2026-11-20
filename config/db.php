<?php
declare(strict_types=1);

function appConfig(): array
{
    static $config = null;
    return $config ??= require __DIR__ . '/config.php';
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
