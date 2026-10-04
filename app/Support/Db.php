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
        $url = (string)Env::get('DATABASE_URL', '');
        if ($url === '') {
            throw new RuntimeException('DATABASE_URL is not configured.');
        }
        $p = parse_url($url);
        if ($p === false || !in_array($p['scheme'] ?? '', ['postgres', 'postgresql'], true)) {
            throw new RuntimeException('DATABASE_URL must be a PostgreSQL URL.');
        }
        parse_str($p['query'] ?? '', $q);
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $p['host'] ?? 'localhost',
            (int)($p['port'] ?? 5432),
            ltrim($p['path'] ?? '/postgres', '/'),
            $q['sslmode'] ?? 'require'
        );
        self::$pdo = new PDO($dsn, urldecode($p['user'] ?? ''), urldecode($p['pass'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return self::$pdo;
    }

    public static function isInjected(): bool
    {
        return self::$injected;
    }
}
