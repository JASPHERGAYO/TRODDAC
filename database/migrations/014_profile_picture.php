<?php
/**
 * 014: add nullable profile_picture path column to users.
 * Safe to run more than once.
 */
declare(strict_types=1);

return static function (PDO $pdo): void {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $st->execute(['users', 'profile_picture']);
    if ((int)$st->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) NULL DEFAULT NULL AFTER phone');
    }
};
