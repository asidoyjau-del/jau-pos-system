<?php
declare(strict_types=1);

namespace ProCast\Support;

use PDO;

/** Data access for platform_super_admins. Never touches store-user tables. */
final class AdminRepository
{
    public function __construct(private PDO $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $st = $this->db->prepare('SELECT * FROM platform_super_admins WHERE email = ? LIMIT 1');
        $st->execute([strtolower(trim($email))]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** @return array<string,mixed>|null  only ACTIVE admins */
    public function findActiveById(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM platform_super_admins WHERE id = ? AND status = 'active' LIMIT 1");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function recordLogin(int $id, string $ip, string $nowUtc): void
    {
        $this->db->prepare('UPDATE platform_super_admins SET last_login_at = ?, last_login_ip = ? WHERE id = ?')
            ->execute([$nowUtc, substr($ip, 0, 64), $id]);
    }

    public function saveTotpSecret(int $id, string $encrypted): void
    {
        $this->db->prepare('UPDATE platform_super_admins SET totp_secret = ?, totp_enrolled = 1, totp_last_step = 0 WHERE id = ?')
            ->execute([$encrypted, $id]);
    }

    public function setLastTotpStep(int $id, int $step): void
    {
        $this->db->prepare('UPDATE platform_super_admins SET totp_last_step = ? WHERE id = ?')->execute([$step, $id]);
    }

    public function create(string $fullName, string $email, string $passwordHash): int
    {
        $st = $this->db->prepare('INSERT INTO platform_super_admins (full_name, email, password_hash) VALUES (?,?,?) RETURNING id');
        $st->execute([$fullName, strtolower(trim($email)), $passwordHash]);
        return (int)$st->fetchColumn();
    }

    public function count(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM platform_super_admins')->fetchColumn();
    }
}
