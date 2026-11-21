<?php
declare(strict_types=1);

namespace ProCast\Support;

use PDO;

/**
 * Brute-force protection: max 5 failures per 15 minutes per IP (then the IP is
 * banned for 15 minutes) and per account identifier. All timestamps are integer
 * epoch seconds so the same SQL runs on PostgreSQL and in the SQLite tests.
 */
final class AuthThrottle
{
    public const MAX_FAILURES = 5;
    public const WINDOW = 900;      // 15 minutes
    public const BAN_SECONDS = 900; // 15 minutes

    public function __construct(private PDO $db)
    {
    }

    public function isBanned(string $ip, int $now): bool
    {
        $st = $this->db->prepare('SELECT 1 FROM platform_ip_bans WHERE ip = ? AND banned_until > ?');
        $st->execute([$ip, $now]);
        return (bool)$st->fetchColumn();
    }

    public function failuresForIdentifier(string $identifier, int $now): int
    {
        $st = $this->db->prepare('SELECT COUNT(*) FROM platform_auth_attempts WHERE identifier = ? AND attempted_at > ?');
        $st->execute([$identifier, $now - self::WINDOW]);
        return (int)$st->fetchColumn();
    }

    public function failuresForIp(string $ip, int $now): int
    {
        $st = $this->db->prepare('SELECT COUNT(*) FROM platform_auth_attempts WHERE ip = ? AND attempted_at > ?');
        $st->execute([$ip, $now - self::WINDOW]);
        return (int)$st->fetchColumn();
    }

    /** True when this request must be refused outright (IP banned, or account locked by repeated failures). */
    public function isBlocked(string $ip, string $identifier, int $now): bool
    {
        if ($this->isBanned($ip, $now)) {
            return true;
        }
        return $identifier !== '' && $this->failuresForIdentifier($identifier, $now) >= self::MAX_FAILURES;
    }

    /** Records a failure; bans the IP when it reaches the limit. Returns true if a ban was (re)issued. */
    public function recordFailure(string $kind, string $ip, string $identifier, int $now): bool
    {
        $st = $this->db->prepare('INSERT INTO platform_auth_attempts (kind, ip, identifier, attempted_at) VALUES (?,?,?,?)');
        $st->execute([$kind, $ip, substr($identifier, 0, 190), $now]);

        if ($this->failuresForIp($ip, $now) >= self::MAX_FAILURES) {
            $ban = $this->db->prepare(
                'INSERT INTO platform_ip_bans (ip, banned_until, created_at) VALUES (?,?,?)
                 ON CONFLICT (ip) DO UPDATE SET banned_until = excluded.banned_until'
            );
            $ban->execute([$ip, $now + self::BAN_SECONDS, $now]);
            return true;
        }
        return false;
    }

    /** After a fully successful login (password + TOTP). */
    public function clear(string $ip, string $identifier): void
    {
        $this->db->prepare('DELETE FROM platform_auth_attempts WHERE ip = ? OR identifier = ?')->execute([$ip, $identifier]);
    }

    /** Housekeeping. */
    public function purge(int $now): void
    {
        $this->db->prepare('DELETE FROM platform_auth_attempts WHERE attempted_at < ?')->execute([$now - self::WINDOW * 4]);
        $this->db->prepare('DELETE FROM platform_ip_bans WHERE banned_until < ?')->execute([$now - self::BAN_SECONDS]);
    }
}
