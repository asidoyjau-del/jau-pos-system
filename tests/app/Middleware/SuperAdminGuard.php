<?php
declare(strict_types=1);

namespace ProCast\Middleware;

use ProCast\Support\AdminRepository;
use ProCast\Support\Csrf;
use ProCast\Support\Env;

/**
 * Super Admin guard.
 *
 *  Check 1  environment fence      ENABLE_SUPER_ADMIN=true AND APP_ENV=production, else bare 404
 *  Check 2  isolated session       $_SESSION['super_admin_authenticated'] === true (own cookie PROCAST_SUPER_SESS)
 *  Check 3  session binding        user-agent hash + IP network must match the ones recorded at login
 *  Check 4  rate limiting          see AuthThrottle (5 failures / 15 min => 15 min IP ban), enforced in the auth controller
 *
 * Plus: idle/absolute timeouts, live re-validation that the admin is still
 * 'active' in the DB (so deactivating an admin kills their session at once),
 * CSRF verification and the security-header set.
 */
final class SuperAdminGuard
{
    public const SESSION_NAME = 'PROCAST_SUPER_SESS';
    public const IDLE_TTL     = 1800;   // 30 min
    public const ABSOLUTE_TTL = 28800;  // 8 h
    public const PENDING_TTL  = 300;    // password OK -> must finish TOTP within 5 min

    public function __construct(private ?AdminRepository $admins = null)
    {
    }

    // ── Check 1 ───────────────────────────────────────────────────────────
    /** Entry-point fence. Emits NOTHING (no body, no cookie, no session) when closed. */
    public static function fenceOrAbort(): void
    {
        if (!Env::superAdminEnabled()) {
            self::abort404();
        }
    }

    public static function abort404(): never
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            header_remove('Set-Cookie');
        }
        http_response_code(404);
        exit();
    }

    // ── Check 2 + 3 ───────────────────────────────────────────────────────
    /**
     * @param array<string,mixed> $session
     * @return string|null  null when the session is valid, otherwise a short machine reason
     */
    public function check(array &$session, string $ip, string $userAgent, int $now): ?string
    {
        if (($session['super_admin_authenticated'] ?? false) !== true) {
            return 'no_session';
        }
        $adminId = $session['sa_admin_id'] ?? null;
        if (!is_int($adminId) || $adminId <= 0) {
            return 'no_session';
        }
        if (!isset($session['sa_ua'], $session['sa_ip']) || !hash_equals((string)$session['sa_ua'], self::uaHash($userAgent))) {
            return 'ua_mismatch';
        }
        if (!hash_equals((string)$session['sa_ip'], self::bindIp($ip))) {
            return 'ip_mismatch';
        }
        if ($now - (int)($session['sa_created'] ?? 0) > self::ABSOLUTE_TTL) {
            return 'expired';
        }
        if ($now - (int)($session['sa_last'] ?? 0) > self::IDLE_TTL) {
            return 'idle';
        }
        // Fail closed: if the DB cannot confirm the admin is active, deny.
        try {
            if ($this->admins === null || $this->admins->findActiveById($adminId) === null) {
                return 'inactive';
            }
        } catch (\Throwable) {
            return 'unavailable';
        }
        $session['sa_last'] = $now;
        return null;
    }

    /** @param array<string,mixed> $session */
    public static function establish(array &$session, int $adminId, string $ip, string $userAgent, int $now): void
    {
        // Wipe every pre-auth key; only the sa_* namespace ever exists in this session.
        $session = [
            'super_admin_authenticated' => true,
            'sa_admin_id'               => $adminId,
            'sa_ip'                     => self::bindIp($ip),
            'sa_ua'                     => self::uaHash($userAgent),
            'sa_created'                => $now,
            'sa_last'                   => $now,
        ];
        Csrf::rotate($session);
    }

    /** IPv4 -> /24 (tolerates mobile/NAT hops inside a network); IPv6 -> first 48 bits. */
    public static function bindIp(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return $ip; // unparseable — keep as-is
        }
        if (strlen($bin) === 4) {
            // IPv4: mask to /24
            $p = explode('.', $ip);
            return $p[0] . '.' . $p[1] . '.' . $p[2] . '.0/24';
        }
        // IPv6: keep first 48 bits (6 bytes)
        return bin2hex(substr($bin, 0, 6)) . '::/48';
    }

    public static function uaHash(string $ua): string
    {
        return hash('sha256', $ua);
    }

    /** @param array<string,mixed> $session */
    public static function verifyCsrf(array $session, ?string $token): bool
    {
        return Csrf::verify($session, $token);
    }

    /** @return array<string,string> */
    public static function securityHeaders(): array
    {
        return [
            'X-Robots-Tag'              => 'noindex, nofollow, noarchive',
            'Cache-Control'             => 'no-store, no-cache, must-revalidate, private',
            'Pragma'                    => 'no-cache',
            'X-Frame-Options'           => 'DENY',
            'X-Content-Type-Options'    => 'nosniff',
            'Referrer-Policy'           => 'no-referrer',
            'Permissions-Policy'        => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Content-Security-Policy'   => "default-src 'none'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        ];
    }
}
