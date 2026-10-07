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

    /**
     * Passwords that must never be allowed to authenticate a super admin.
     *
     * .env.example is a COMMITTED file, so any literal shipped in it is public
     * knowledge. A deployment still configured with one of these has a platform
     * admin account that anyone who has read this repository can sign in with --
     * and that account is deliberately exempt from the brute-force lockout, so
     * the guess is also unlimited.
     *
     * Compared case-insensitively so a trivial case variation is not a bypass.
     */
    private const PUBLISHED_SUPER_ADMIN_PASSWORDS = [
        'ChangeMe_1234!',
        'ChangeMe1234!',
        'ChangeMe_1234',
        'changeme1234',
        'SuperAdmin123',
        'superadmin123',
        'Password123',
        'password123',
        'admin123',
        'changeme',
        'password',
    ];

    /**
     * May this password be used for the platform super admin?
     *
     * Fails closed. An empty value, the published example, an unfilled
     * placeholder or anything under the minimum length is refused, exactly as
     * syncTokenIsSecure() refuses the published sync token. Unconfigured must
     * not quietly mean "the value everybody can read".
     */
    public static function superAdminPasswordIsAcceptable(?string $password): bool
    {
        $p = trim((string)$password);
        if ($p === '' || strlen($p) < 12) {
            return false;
        }

        foreach (self::PUBLISHED_SUPER_ADMIN_PASSWORDS as $published) {
            if (strtolower($p) === strtolower($published)) {
                return false;
            }
        }

        // Unfilled placeholder markers, e.g. CHANGE_ME / REPLACE_WITH / YOUR_.
        $upper = strtoupper($p);
        foreach (['CHANGE', 'REPLACE', 'YOUR_', 'PASTE', 'EXAMPLE', 'TODO', 'XXXX'] as $marker) {
            if (str_contains($upper, $marker)) {
                return false;
            }
        }

        return true;
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
