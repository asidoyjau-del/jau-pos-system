<?php
declare(strict_types=1);

/**
 * Applies platform-admin/migrations/*.sql to the ONLINE PostgreSQL database.
 *
 *   php platform-admin/migrate.php            apply pending migrations
 *   php platform-admin/migrate.php --status   list applied / pending
 *
 * CLI only. Refuses to run unless APP_ENV=production and ENABLE_SUPER_ADMIN=true
 * (so it can never touch a local XAMPP/MySQL database).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('PROCAST_ROOT', dirname(__DIR__));
require PROCAST_ROOT . '/app/Bootstrap.php';

use ProCast\Support\Db;
use ProCast\Support\Env;

if (!Env::superAdminEnabled()) {
    fwrite(STDERR, "Refusing to run: set APP_ENV=production and ENABLE_SUPER_ADMIN=true (platform module is online-only).\n");
    exit(2);
}

try {
    $pdo = Db::pdo();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
        throw new RuntimeException('Platform migrations require PostgreSQL.');
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS platform_migrations (name VARCHAR(120) PRIMARY KEY, applied_at TIMESTAMPTZ NOT NULL DEFAULT now())');

    $applied = $pdo->query('SELECT name FROM platform_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $files = glob(__DIR__ . '/migrations/*.sql') ?: [];
    sort($files);

    if (in_array('--status', $argv, true)) {
        foreach ($files as $f) {
            echo (in_array(basename($f), $applied, true) ? '[applied] ' : '[pending] ') . basename($f) . "\n";
        }
        exit(0);
    }

    $ran = 0;
    foreach ($files as $f) {
        $name = basename($f);
        if (in_array($name, $applied, true)) {
            continue;
        }
        echo "Applying $name ... ";
        $pdo->beginTransaction();
        try {
            $pdo->exec((string)file_get_contents($f));
            $pdo->prepare('INSERT INTO platform_migrations (name) VALUES (?)')->execute([$name]);
            $pdo->commit();
            echo "ok\n";
            $ran++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo "FAILED\n";
            throw $e;
        }
    }
    echo $ran === 0 ? "Nothing to apply — database is up to date.\n" : "Applied $ran migration(s).\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration error: ' . $e->getMessage() . "\n");
    exit(1);
}
