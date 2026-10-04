<?php
declare(strict_types=1);

namespace ProCast\Support;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Data access for the store lifecycle + monitoring queries.
 * Every user-influenced value is a bound parameter; LIKE input is escaped.
 */
final class StoreRepository
{
    public const STATUSES = ['pending_approval', 'active', 'suspended', 'rejected'];

    public function __construct(private PDO $db)
    {
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        $out = ['total' => 0, 'pending_approval' => 0, 'active' => 0, 'suspended' => 0, 'rejected' => 0];
        foreach ($this->db->query('SELECT status, COUNT(*) AS c FROM stores GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $s = (string)$r['status'];
            if (isset($out[$s])) {
                $out[$s] = (int)$r['c'];
            }
            $out['total'] += (int)$r['c'];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function list(?string $status, string $q, int $limit = 100): array
    {
        $sql = 'SELECT id, name, owner_name, owner_email, contact_phone, address, status, subscription_tier,
                       verification_doc, rejection_reason, registered_at, created_at, approved_at
                FROM stores WHERE 1=1';
        $args = [];
        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $sql .= ' AND status = ?';
            $args[] = $status;
        }
        $q = trim($q);
        if ($q !== '') {
            $like = self::like($q);
            $sql .= " AND (LOWER(name) LIKE ? ESCAPE '\\' OR LOWER(COALESCE(owner_name,'')) LIKE ? ESCAPE '\\' OR LOWER(COALESCE(owner_email,'')) LIKE ? ESCAPE '\\')";
            array_push($args, $like, $like, $like);
        }
        $sql .= ' ORDER BY COALESCE(registered_at, created_at) DESC, id DESC LIMIT ' . max(1, min(500, $limit));
        $st = $this->db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM stores WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** pending_approval -> active. Returns false if the store was not pending (race / replay safe). */
    public function approve(int $id, int $adminId, string $tier, string $tokenHash, string $nowUtc): bool
    {
        $st = $this->db->prepare(
            "UPDATE stores SET status='active', approved_by=?, approved_at=?, subscription_tier=?, database_sync_token=?, rejection_reason=NULL
             WHERE id=? AND status='pending_approval'"
        );
        $st->execute([$adminId, $nowUtc, $tier, $tokenHash, $id]);
        return $st->rowCount() === 1;
    }

    /** pending_approval -> rejected (reason mandatory, enforced by caller + DB CHECK). */
    public function reject(int $id, string $reason): bool
    {
        $st = $this->db->prepare("UPDATE stores SET status='rejected', rejection_reason=?, database_sync_token=NULL WHERE id=? AND status='pending_approval'");
        $st->execute([$reason, $id]);
        return $st->rowCount() === 1;
    }

    /** active -> suspended (API key voided). */
    public function suspend(int $id, string $reason): bool
    {
        $st = $this->db->prepare("UPDATE stores SET status='suspended', rejection_reason=?, database_sync_token=NULL WHERE id=? AND status='active'");
        $st->execute([$reason, $id]);
        return $st->rowCount() === 1;
    }

    /** suspended -> active with a freshly issued API key. */
    public function reactivate(int $id, string $tokenHash): bool
    {
        $st = $this->db->prepare("UPDATE stores SET status='active', rejection_reason=NULL, database_sync_token=? WHERE id=? AND status='suspended'");
        $st->execute([$tokenHash, $id]);
        return $st->rowCount() === 1;
    }

    /** Kills every remembered-login token for the store's users (cashiers can't silently re-login). */
    public function revokeStoreTokens(int $storeId): int
    {
        $st = $this->db->prepare('DELETE FROM auth_tokens WHERE user_id IN (SELECT id FROM users WHERE store_id = ?)');
        $st->execute([$storeId]);
        return $st->rowCount();
    }

    /** Default POS settings + categories for a freshly approved store (idempotent). */
    public function seedDefaults(int $storeId, string $shopName): void
    {
        $set = $this->db->prepare('INSERT INTO settings (store_id, key, value) VALUES (?,?,?) ON CONFLICT (store_id, key) DO NOTHING');
        foreach ([['shop_name', $shopName], ['currency', '₱'], ['vat_rate', '0'], ['tax_rate', '0']] as [$k, $v]) {
            $set->execute([$storeId, $k, $v]);
        }
        $cat = $this->db->prepare('INSERT INTO categories (store_id, name, sort_order) VALUES (?,?,?) ON CONFLICT (store_id, name) DO NOTHING');
        foreach (['Food', 'Drinks', 'Snacks', 'Desserts', 'Others'] as $i => $name) {
            $cat->execute([$storeId, $name, $i]);
        }
    }

    /** @return array<string,mixed>|null */
    public function ownerOf(int $storeId): ?array
    {
        $st = $this->db->prepare("SELECT id, username, full_name, email FROM users WHERE store_id = ? AND role = 'owner' ORDER BY id LIMIT 1");
        $st->execute([$storeId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Cashier/manager sessions: unexpired remember-token AND a login in the last 30 min, on ACTIVE stores only. */
    public function activeSessions(int $now): int
    {
        $st = $this->db->prepare(
            "SELECT COUNT(DISTINCT t.user_id) FROM auth_tokens t
             JOIN users u ON u.id = t.user_id
             JOIN stores s ON s.id = u.store_id
             WHERE s.status = 'active' AND t.expires_at > ? AND u.last_login > ?"
        );
        $st->execute([gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now - 1800)]);
        return (int)$st->fetchColumn();
    }

    /** @return array{today:array{count:int,total:float},month:array{count:int,total:float}} aggregated over ACTIVE stores only */
    public function volume(int $now): array
    {
        $tz = new DateTimeZone((string)Env::get('PLATFORM_TIMEZONE', 'Asia/Manila'));
        $local = (new DateTimeImmutable('@' . $now))->setTimezone($tz);
        $utc = new DateTimeZone('UTC');
        $todayStart = $local->setTime(0, 0)->setTimezone($utc)->format('Y-m-d H:i:s');
        $monthStart = $local->modify('first day of this month')->setTime(0, 0)->setTimezone($utc)->format('Y-m-d H:i:s');

        $sql = "SELECT COUNT(*) AS c, COALESCE(SUM(t.total - COALESCE(t.voided_total,0)),0) AS v
                FROM transactions t JOIN stores s ON s.id = t.store_id
                WHERE s.status = 'active' AND t.created_at >= ? AND COALESCE(t.status,'completed') <> 'voided'";
        $out = [];
        foreach (['today' => $todayStart, 'month' => $monthStart] as $k => $since) {
            $st = $this->db->prepare($sql);
            $st->execute([$since]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'v' => 0];
            $out[$k] = ['count' => (int)$r['c'], 'total' => (float)$r['v']];
        }
        return $out;
    }

    /**
     * User activity inspector. Returns NO secrets (no password hash / reset token).
     *
     * @return list<array<string,mixed>>
     */
    public function searchUsers(string $q, int $now, int $limit = 50): array
    {
        $sql = "SELECT u.id, u.username, u.full_name, u.role, u.email, u.store_id, u.last_login,
                       s.name AS store_name, s.status AS store_status,
                       (SELECT COUNT(*) FROM auth_tokens t WHERE t.user_id = u.id AND t.expires_at > ?) AS live_tokens
                FROM users u LEFT JOIN stores s ON s.id = u.store_id WHERE 1=1";
        $args = [gmdate('Y-m-d H:i:s', $now)];
        $q = trim($q);
        if ($q !== '') {
            $like = self::like($q);
            $sql .= " AND (LOWER(u.username) LIKE ? ESCAPE '\\' OR LOWER(u.full_name) LIKE ? ESCAPE '\\'
                      OR LOWER(COALESCE(u.email,'')) LIKE ? ESCAPE '\\' OR LOWER(COALESCE(s.name,'')) LIKE ? ESCAPE '\\')";
            array_push($args, $like, $like, $like, $like);
        }
        $sql .= ' ORDER BY u.last_login DESC, u.id DESC LIMIT ' . max(1, min(200, $limit));
        $st = $this->db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Escapes LIKE wildcards in user input and wraps for a contains-match. */
    public static function like(string $q): string
    {
        return '%' . addcslashes(strtolower(mb_substr($q, 0, 100)), '%_\\') . '%';
    }
}
