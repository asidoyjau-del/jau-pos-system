<?php
declare(strict_types=1);

namespace ProCast\Support;

/**
 * Environment access + the single source of truth for the Super Admin fence.
 *
 * Lookup order: in-process override (tests only) -> real process environment
 * -> .env.local / .env file (same precedence the POS index.php loader uses).
 */
final class Env
{
    /** @var array<string,?string> */
    private static array $override = [];
    /** @var array<string,string>|null */
    private static ?array $file = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$override)) {
            return self::$override[$key] ?? $default;
        }
        $v = getenv($key);
        if ($v !== false && $v !== '') {
            return $v;
        }
        $file = self::fileValues();
        return $file[$key] ?? $default;
    }

    /** In-process override (unit tests). Pass null to force "unset". */
    public static function set(string $key, ?string $value): void
    {
        self::$override[$key] = $value;
    }

    public static function reset(): void
    {
        self::$override = [];
        self::$file = null;
    }

    /**
     * THE fence. The module is live only when BOTH are true:
     *   APP_ENV=production  AND  ENABLE_SUPER_ADMIN=true  (exact, lowercase)
     */
    public static function superAdminEnabled(): bool
    {
        return trim((string)self::get('ENABLE_SUPER_ADMIN', '')) === 'true'
            && trim((string)self::get('APP_ENV', '')) === 'production';
    }

    /**
     * Must the super admin clear a TOTP code after the password?
     *
     * Defaults to FALSE so the owner account configured in Render
     * (SUPERADMIN_EMAIL / SUPERADMIN_PASSWORD) signs in with email + password
     * only and lands straight on /monitoring. Set SUPERADMIN_REQUIRE_2FA=true
     * to switch the mandatory TOTP step back on.
     */
    public static function superAdminTwoFactorRequired(): bool
    {
        return trim((string)self::get('SUPERADMIN_REQUIRE_2FA', 'false')) === 'true';
    }

    /** The deployment owner account, i.e. SUPERADMIN_EMAIL from the environment. */
    public static function superAdminEnvEmail(): string
    {
        return strtolower(trim((string)self::get('SUPERADMIN_EMAIL', '')));
    }

    /** @return array<string,string> */
    private static function fileValues(): array
    {
        if (self::$file !== null) {
            return self::$file;
        }
        self::$file = [];
        $root = defined('PROCAST_ROOT') ? PROCAST_ROOT : dirname(__DIR__, 2);
        foreach ([$root . '/.env.local', $root . '/.env'] as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v);
                if (strlen($v) >= 2 && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
                    $v = substr($v, 1, -1);
                }
                if ($k !== '') {
                    self::$file[$k] = $v;
                }
            }
            break; // same as index.php: first existing file wins
        }
        return self::$file;
    }
}
