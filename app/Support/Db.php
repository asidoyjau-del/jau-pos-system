<?php
declare(strict_types=1);

namespace ProCast\Support;

use PDO;
use RuntimeException;

/**
 * Lazy PDO factory for the platform module. PostgreSQL only (the online
 * Supabase database). Refuses to connect unless the fence is open, so a
 * local XAMPP install can never reach the platform tables through this class.
 */
final class Db
{
    private static ?PDO $pdo = null;
    private static bool $injected = false;

    /** Inject a connection (tests). Bypasses the fence by design. */
    public static function set(?PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$injected = $pdo !== null;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        if (!Env::superAdminEnabled()) {
            throw new RuntimeException('Platform database is not available in this environment.');
        }

        $url = trim((string)Env::get('DATABASE_URL', ''));
        if ($url !== '') {
            $p = parse_url($url);
            $scheme = strtolower((string)($p['scheme'] ?? ''));
            if ($p === false || !in_array($scheme, ['postgres', 'postgresql'], true)) {
                throw new RuntimeException('DATABASE_URL must be a PostgreSQL URL.');
            }
            $query = [];
            if (!empty($p['query'])) {
                parse_str($p['query'], $query);
            }
            $sslMode = strtolower((string)($query['sslmode'] ?? 'require'));
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
                $p['host'] ?? 'localhost',
                (int)($p['port'] ?? 5432),
                ltrim($p['path'] ?? '/postgres', '/'),
                $sslMode
            );
            $user = rawurldecode((string)($p['user'] ?? ''));
            $pass = rawurldecode((string)($p['pass'] ?? ''));
        } else {
            $host = trim((string)Env::get('DB_HOST', ''));
            $name = trim((string)Env::get('DB_NAME', ''));
            $user = trim((string)Env::get('DB_USER', ''));
            $pass = (string)Env::get('DB_PASS', '');
            $port = (int)(Env::get('DB_PORT', '') ?: 5432);

            if ($host === '' || $name === '' || $user === '') {
                throw new RuntimeException('Database credentials not configured. Please set DATABASE_URL (or DB_HOST, DB_NAME, DB_USER, DB_PASS).');
            }

            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
                $host,
                $port,
                $name
            );
        }

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
        } catch (\PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), (int)$e->getCode(), $e);
        }

        // Auto-run pending migrations if needed
        self::ensureMigrated(self::$pdo);

        // Auto-seed primary Super Admin if environment credentials exist and no admin present
        self::ensureSuperAdminSeeded(self::$pdo);

        return self::$pdo;
    }

    public static function isInjected(): bool
    {
        return self::$injected;
    }

    private static function ensureMigrated(PDO $pdo): void
    {
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS platform_migrations (name VARCHAR(120) PRIMARY KEY, applied_at TIMESTAMPTZ NOT NULL DEFAULT now())');
            $applied = $pdo->query('SELECT name FROM platform_migrations')->fetchAll(PDO::FETCH_COLUMN);

            $migrationDir = (defined('PROCAST_ROOT') ? PROCAST_ROOT : dirname(__DIR__, 2)) . '/platform-admin/migrations';
            $files = glob($migrationDir . '/*.sql') ?: [];
            sort($files);

            foreach ($files as $f) {
                $name = basename($f);
                if (in_array($name, $applied, true)) {
                    continue;
                }
                $sql = file_get_contents($f);
                if ($sql === false || trim($sql) === '') {
                    continue;
                }
                $pdo->beginTransaction();
                try {
                    $pdo->exec($sql);
                    $pdo->prepare('INSERT INTO platform_migrations (name) VALUES (?)')->execute([$name]);
                    $pdo->commit();
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log("[platform-admin] migration error on {$name}: " . $e->getMessage());
                    throw $e;
                }
            }
        } catch (\Throwable $e) {
            error_log('[platform-admin] ensureMigrated: ' . $e->getMessage());
        }
    }

    public static function ensureSuperAdminSeeded(PDO $pdo): void
    {
        try {
            $email = strtolower(trim((string)Env::get('SUPERADMIN_EMAIL', '')));
            $password = (string)Env::get('SUPERADMIN_PASSWORD', '');
            $name = trim((string)Env::get('SUPERADMIN_NAME', 'Platform Administrator'));

            if ($email === '' || $password === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            $repo = new AdminRepository($pdo);
            $existing = $repo->findByEmail($email);
            if ($existing === null) {
                $repo->create($name, $email, Passwords::hash($password));
                error_log("[platform-admin] Auto-seeded primary super admin account for {$email}.");
            } else {
                $st = $pdo->prepare("UPDATE platform_super_admins SET password_hash = ?, status = 'active' WHERE email = ?");
                $st->execute([Passwords::hash($password), $email]);
                error_log("[platform-admin] Synchronized super admin password for {$email}.");
            }
        } catch (\Throwable $e) {
            error_log('[platform-admin] ensureSuperAdminSeeded: ' . $e->getMessage());
        }
    }
}
