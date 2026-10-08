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

    /**
     * Column metadata for the two tables the monitoring queries read, keyed
     * table => column => data_type. Null until the first probe; the result is
     * cached for the life of the request.
     *
     * @var array<string,array<string,string>>|null
     */
    private ?array $columns = null;

    /** The configured business timezone (drives every "day" boundary below). */
    private function tz(): DateTimeZone
    {
        return new DateTimeZone((string)Env::get('PLATFORM_TIMEZONE', 'Asia/Manila'));
    }

    /**
     * True when the schema could actually be inspected.
     *
     * The distinction matters: when the probe SUCCEEDS and a column is absent we
     * must degrade (that is the whole point). When the probe FAILS we do NOT know,
     * and pretending every optional column is missing would silently overstate
     * revenue by ignoring refunds. So an unknown schema falls back to assuming the
     * columns are there -- i.e. the behaviour we had before this probe existed.
     */
    private function schemaKnown(): bool
    {
        return isset($this->columnMeta()['transactions']);
    }

    /** @return array<string,array<string,string>> */
    private function columnMeta(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }
        $out = [];
        try {
            $st = $this->db->prepare(
                'SELECT table_name, column_name, data_type
                   FROM information_schema.columns
                  WHERE table_schema = current_schema()
                    AND table_name IN (?, ?)'
            );
            $st->execute(['transactions', 'stores']);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[strtolower((string)$r['table_name'])][strtolower((string)$r['column_name'])]
                    = strtolower((string)$r['data_type']);
            }
        } catch (\Throwable $e) {
            // Read-only metadata lookup: if it fails the monitoring page must
            // still render. Leaving $out empty makes schemaKnown() false and
            // every hasColumn() call fall back to the historical behaviour.
            error_log('[platform-admin] schema probe failed: ' . $e->getMessage());
            $out = [];
        }
        $this->columns = $out;
        return $out;
    }

    /**
     * Whether `transactions`.`$column` may be referenced.
     *
     * payment_method and order_type are the motivating cases: they appear in
     * POS payloads but no CREATE or ALTER ever adds them, so a monitoring query
     * naming them fails outright rather than returning zero.
     */
    public function hasColumn(string $table, string $column): bool
    {
        if (!$this->schemaKnown()) {
            return true;
        }
        return isset($this->columnMeta()[strtolower($table)][strtolower($column)]);
    }

    /** Lower-cased data_type for a column, or null when unknown. */
    private function columnType(string $table, string $column): ?string
    {
        return $this->columnMeta()[strtolower($table)][strtolower($column)] ?? null;
    }

    /**
     * Revenue expression net of refunds.
     *
     * Only subtracts voided_total when that column really exists -- otherwise a
     * half-voided order would be billed to the store at full value.
     */
    private function netSql(string $alias): string
    {
        $a = $alias;
        return $this->hasColumn('transactions', 'voided_total')
            ? "COALESCE($a.total,0) - COALESCE($a.voided_total,0)"
            : "COALESCE($a.total,0)";
    }

    /** `AND ...` fragment excluding fully voided orders; empty when status is absent. */
    private function voidFilter(string $alias): string
    {
        return $this->hasColumn('transactions', 'status')
            ? "AND COALESCE($alias.status,'completed') <> 'voided'"
            : '';
    }

    /**
     * Boolean expression (no AND) for "counts as real revenue".
     *
     * Needed where the voided and non-voided populations must be counted side by
     * side: a WHERE clause can only ever drop rows, so a void rate computed
     * alongside a voidFilter() always degenerates to zero.
     */
    private function notVoidSql(string $alias): string
    {
        return $this->hasColumn('transactions', 'status')
            ? "COALESCE($alias.status,'completed') <> 'voided'"
            : 'TRUE';
    }

    /** Boolean expression (no AND) for "fully voided". FALSE when status is absent. */
    private function voidedSql(string $alias): string
    {
        return $this->hasColumn('transactions', 'status')
            ? "COALESCE($alias.status,'completed') = 'voided'"
            : 'FALSE';
    }

    /**
     * SQL that buckets a timestamp column into YYYY-MM-DD in the BUSINESS timezone.
     *
     * The POS writes created_at as a UTC wall clock, but "today's revenue" has to
     * mean today where the store is. The two column types need opposite rewrites:
     * a with-time-zone value is already an instant, a without-time-zone one is a
     * UTC wall clock that must be labelled UTC first or it gets read as local.
     */
    private function localDayExpr(string $col): string
    {
        $expr = $this->columnType('transactions', 'created_at') === 'timestamp with time zone'
            ? "$col AT TIME ZONE ?"
            : "($col AT TIME ZONE 'UTC') AT TIME ZONE ?";
        return "to_char($expr, 'YYYY-MM-DD')";
    }

    /** UTC 'Y-m-d H:i:s' for local midnight N days before $now's local day. */
    private function localMidnightUtc(int $now, int $daysBack): string
    {
        $local = (new DateTimeImmutable('@' . $now))->setTimezone($this->tz());
        $day = $local->modify('-' . $daysBack . ' days')->setTime(0, 0);
        return $day->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
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

    /**
 * Store list. Falls back to the users table for owner_name / owner_email and
 * to settings.shop_name for the store name.
 *
 * Why: stores created before migration 002 have NULL owner_name/owner_email
 * even though the real owner exists as users.role='owner', and migration 001
 * gave every store the literal name 'ProCast'. Reading them live means the
 * list is correct immediately, whether or not the backfill migration has run.
 *
 * @return list<array<string,mixed>>
 */
    public function list(?string $status, string $q, int $limit = 100): array
    {
        $sql = "SELECT s.id, s.name, s.owner_name, s.owner_email, s.contact_phone, s.address,
                       s.status, s.subscription_tier, s.client_type, s.verification_doc,
                       s.rejection_reason, s.registered_at, s.created_at, s.approved_at,
                       COALESCE(NULLIF(s.owner_name, ''), u.full_name)      AS display_owner,
                       COALESCE(NULLIF(s.owner_email, ''), u.email)         AS display_email,
                       COALESCE(NULLIF(s.name, ''), sn.shop_name)           AS display_name
                FROM stores s
                LEFT JOIN LATERAL (
                    SELECT full_name, email FROM users
                     WHERE store_id = s.id AND role = 'owner'
                     ORDER BY id LIMIT 1
                ) u ON TRUE
                LEFT JOIN LATERAL (
                    SELECT value AS shop_name FROM settings
                     WHERE store_id = s.id AND key = 'shop_name' LIMIT 1
                ) sn ON TRUE
                WHERE 1=1";
        $args = [];
        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $sql .= ' AND s.status = ?';
            $args[] = $status;
        }
        $q = trim($q);
        if ($q !== '') {
            $like = self::like($q);
            $sql .= " AND (LOWER(s.name) LIKE ? ESCAPE '\\' OR LOWER(COALESCE(s.owner_name,'')) LIKE ? ESCAPE '\\' OR LOWER(COALESCE(s.owner_email,'')) LIKE ? ESCAPE '\\' OR LOWER(COALESCE(u.full_name,'')) LIKE ? ESCAPE '\\')";
            array_push($args, $like, $like, $like, $like);
        }
        $sql .= ' ORDER BY COALESCE(s.registered_at, s.created_at) DESC, s.id DESC LIMIT ' . max(1, min(500, $limit));
        $st = $this->db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        // Same owner/name fallback as list() so the detail page shows the real
        // values for stores created before migration 002.
        $st = $this->db->prepare(
            "SELECT s.*,
                    COALESCE(NULLIF(s.owner_name, ''), u.full_name) AS display_owner,
                    COALESCE(NULLIF(s.owner_email, ''), u.email)    AS display_email,
                    COALESCE(NULLIF(s.name, ''), sn.shop_name)      AS display_name
               FROM stores s
               LEFT JOIN LATERAL (
                   SELECT full_name, email FROM users
                    WHERE store_id = s.id AND role = 'owner'
                    ORDER BY id LIMIT 1
               ) u ON TRUE
               LEFT JOIN LATERAL (
                   SELECT value AS shop_name FROM settings
                    WHERE store_id = s.id AND key = 'shop_name' LIMIT 1
               ) sn ON TRUE
              WHERE s.id = ?"
        );
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findUser(int $id): ?array
    {
        $st = $this->db->prepare('SELECT id, username, full_name, email, role, store_id, last_login FROM users WHERE id = ?');
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

    // ── Client type (how the store runs ProCast) ─────────────────────────

    public const CLIENT_TYPES = ['web', 'app', 'local'];

    public function setClientType(int $id, string $clientType): bool
    {
        if (!in_array($clientType, self::CLIENT_TYPES, true)) {
            return false;
        }
        $st = $this->db->prepare('UPDATE stores SET client_type = ? WHERE id = ?');
        $st->execute([$clientType, $id]);
        return $st->rowCount() === 1;
    }

    // ── Hard delete ──────────────────────────────────────────────────────

    /**
     * Permanently removes a store and everything that belongs to it.
     *
     * Caller MUST already hold an open transaction and MUST have written the
     * platform_deleted_stores tombstone row first (see
     * SuperAdminDashboardController::destroy). Children are removed explicitly
     * rather than relying on ON DELETE CASCADE because the POS schema predates
     * the platform migrations and cannot be relied on to have those clauses.
     *
     * @return array{deleted:array<string,int>,ok:bool}  counts per child table
     */
    public function destroy(int $storeId): array
    {
        $out = ['auth_tokens' => 0, 'users' => 0, 'settings' => 0, 'categories' => 0, 'pairings' => 0, 'user_pairings' => 0, 'stores' => 0];

        $st = $this->db->prepare('DELETE FROM auth_tokens WHERE user_id IN (SELECT id FROM users WHERE store_id = ?)');
        $st->execute([$storeId]);
        $out['auth_tokens'] = $st->rowCount();

        // Per-user pairing codes (migration 004) are removed explicitly too. They
        // carry ON DELETE CASCADE from both users(id) and stores(id), so this is
        // belt-and-braces -- but the row count is reported back to the admin, and
        // relying on the cascade made that number impossible to state honestly.
        $st = $this->db->prepare('DELETE FROM platform_user_pairings WHERE store_id = ? OR user_id IN (SELECT id FROM users WHERE store_id = ?)');
        $st->execute([$storeId, $storeId]);
        $out['user_pairings'] = $st->rowCount();

        foreach (['users', 'settings', 'categories'] as $table) {
            // `key` is a reserved word in MySQL but not PostgreSQL; the platform
            // module is PostgreSQL-only (see Db.php), so the bare name is safe.
            $st = $this->db->prepare("DELETE FROM {$table} WHERE store_id = ?");
            $st->execute([$storeId]);
            $out[$table] = $st->rowCount();
        }

        $st = $this->db->prepare('DELETE FROM platform_store_pairings WHERE store_id = ?');
        $st->execute([$storeId]);
        $out['pairings'] = $st->rowCount();

        $st = $this->db->prepare('DELETE FROM stores WHERE id = ?');
        $st->execute([$storeId]);
        $out['stores'] = $st->rowCount();

        return ['deleted' => $out, 'ok' => $out['stores'] === 1];
    }

    public function userCount(int $storeId): int
    {
        $st = $this->db->prepare('SELECT COUNT(*) FROM users WHERE store_id = ?');
        $st->execute([$storeId]);
        return (int)$st->fetchColumn();
    }

    /** Append-only tombstone so an irreversible delete is still auditable. */
    public function recordDeletion(int $storeId, array $store, int $userCount, ?int $adminId, string $reason): void
    {
        $st = $this->db->prepare(
            'INSERT INTO platform_deleted_stores (store_id, store_name, owner_name, owner_email, user_count, deleted_by, reason)
             VALUES (?,?,?,?,?,?,?)'
        );
        $st->execute([
            $storeId,
            (string)($store['name'] ?? ''),
            $store['owner_name'] ?? null,
            $store['owner_email'] ?? null,
            $userCount,
            $adminId,
            $reason,
        ]);
    }

    // ── Local-POS pairing codes ──────────────────────────────────────────

    /**
     * Issues a 6-digit code for an offline install.
     *
     * $codeCipher is the AES-256-GCM ciphertext of the code (migration 005), kept
     * only so a super admin can re-copy a LIVE code when the email never arrived.
     * It is NULLed by every retire path and is never accepted for redemption —
     * $codeHash is the only thing that authorises a redeem.
     */
    public function issuePairing(int $storeId, ?int $adminId, string $codeHash, string $expiresAtUtc, ?string $codeCipher = null): void
    {
        $this->db->prepare(
            'INSERT INTO platform_store_pairings (store_id, code_hash, code_cipher, created_by, expires_at) VALUES (?,?,?,?,?)'
        )->execute([$storeId, $codeHash, $codeCipher, $adminId, $expiresAtUtc]);
    }

    /**
 * Burns any previously issued but still-unused codes for this store.
 * Call AFTER issuing the new one, so the newest code always wins and an old
 * code sitting in a lost email can't be redeemed later.
 */
    public function invalidatePairings(int $storeId): void
    {
        $this->db->prepare(
            'UPDATE platform_store_pairings SET used_at = ?, code_cipher = NULL
              WHERE store_id = ? AND used_at IS NULL'
        )->execute([gmdate('Y-m-d H:i:s'), $storeId]);
    }

    // ── Per-ACCOUNT activation codes ──────────────────────────────────────

    /**
     * Mints a 6-digit code whose hash is not already live for ANY account.
     *
     * Six digits is a 1,000,000-value space, so a collision between two live
     * codes is a birthday problem: unlikely per pair, but the consequence is
     * not cosmetic. Redemption is a single atomic UPDATE ... WHERE code_hash = ?,
     * so a colliding pair makes that ONE statement match TWO rows and burn both.
     * The user who typed a perfectly valid code is then locked out, and nothing
     * in the response hints at it. Uniqueness is enforced by the database
     * (migration 006); this makes minting retry instead of throwing, so the
     * index is never actually tripped in the normal path.
     *
     * @return array{code:string,hash:string}|null Null only when every attempt collided.
     */
    public function mintUniqueUserCode(int $attempts = 12): ?array
    {
        $seen = $this->db->prepare(
            'SELECT 1 FROM platform_user_pairings WHERE code_hash = ? AND used_at IS NULL LIMIT 1'
        );
        for ($i = 0; $i < $attempts; $i++) {
            // random_int() is a CSPRNG. mt_rand()/rand() would let an attacker
            // who knows the seed predict the next code in the sequence.
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $hash = hash('sha256', $code);
            $seen->execute([$hash]);
            if ($seen->fetchColumn() === false) {
                return ['code' => $code, 'hash' => $hash];
            }
        }
        return null;
    }

    /**
     * Issues a 6-digit code bound to ONE user account. Any earlier unused code
     * for that account is retired first so exactly one code is ever live —
     * belt-and-braces alongside the partial unique index, which is the real
     * guarantee (this keeps the common path from tripping it).
     *
     * $role records the role the code was MINTED for. Redemption compares it
     * against the account's current role, so a code issued to a cashier stops
     * working the moment that account is promoted, instead of silently handing
     * the new, higher role to whoever was holding the old code.
     */
    public function issueUserPairing(int $userId, ?int $storeId, ?int $adminId, string $codeHash, string $expiresAtUtc, ?string $codeCipher = null, ?string $role = null): void
    {
        $this->db->prepare(
            'UPDATE platform_user_pairings SET used_at = ?, code_cipher = NULL
              WHERE user_id = ? AND used_at IS NULL'
        )->execute([gmdate('Y-m-d H:i:s'), $userId]);
        $this->db->prepare(
            'INSERT INTO platform_user_pairings (user_id, store_id, code_hash, code_cipher, role, created_by, expires_at) VALUES (?,?,?,?,?,?,?)'
        )->execute([$userId, $storeId, $codeHash, $codeCipher, $role, $adminId, $expiresAtUtc]);
    }

    /**
     * Every account belonging to a store, with its activation-code state so the
     * admin can see who still needs a code and who already redeemed one.
     *
     * @return list<array<string,mixed>>
     */
    public function usersOfStore(int $storeId): array
    {
        $st = $this->db->prepare(
            "SELECT u.id, u.username, u.full_name, u.email, u.role, u.last_login,
                    p.id          AS pairing_id,
                    p.created_at  AS pairing_created_at,
                    p.expires_at  AS pairing_expires_at,
                    p.used_at     AS pairing_used_at,
                    p.role        AS pairing_role,
                    p.code_cipher AS pairing_code_cipher
               FROM users u
               LEFT JOIN LATERAL (
                   SELECT id, created_at, expires_at, used_at, role, code_cipher
                     FROM platform_user_pairings
                    WHERE user_id = u.id AND used_at IS NULL
                    ORDER BY created_at DESC LIMIT 1
               ) p ON TRUE
              WHERE u.store_id = ?
              ORDER BY (u.role = 'owner') DESC, u.id ASC"
        );
        $st->execute([$storeId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Revokes any live code for one account. Used when the admin wants to force
     * someone to activate again (e.g. after a reinstall of the local POS).
     */
    public function revokeUserPairing(int $userId): void
    {
        $this->db->prepare(
            'UPDATE platform_user_pairings SET used_at = ?, code_cipher = NULL WHERE user_id = ? AND used_at IS NULL'
        )->execute([gmdate('Y-m-d H:i:s'), $userId]);
    }

    /**
     * Redeems a pairing code. Returns the store id on success, or null when the
     * code is unknown, already used, or expired. Single-use by construction:
     * the UPDATE only matches while used_at IS NULL.
     */
    public function redeemPairing(string $codeHash, string $nowUtc): ?int
    {
        $st = $this->db->prepare(
            'UPDATE platform_store_pairings SET used_at = ?
              WHERE code_hash = ? AND used_at IS NULL AND expires_at > ?
           RETURNING store_id'
        );
        $st->execute([$nowUtc, $codeHash, $nowUtc]);
        $id = $st->fetchColumn();
        return $id === false || $id === null ? null : (int)$id;
    }

    /**
     * The ciphertext of one account's LIVE, unexpired activation code, or null
     * when there is none to show (already redeemed, revoked, expired, or issued
     * before migration 005). Callers decrypt with Crypto::decrypt.
     *
     * Deliberately strict about "live": an expired code is useless to the user
     * and re-copying it would only waste the admin's time, so it is reported as
     * absent rather than as a code that cannot work.
     */
    public function liveUserPairingCipher(int $userId, string $nowUtc): ?string
    {
        $st = $this->db->prepare(
            'SELECT code_cipher FROM platform_user_pairings
              WHERE user_id = ? AND used_at IS NULL AND expires_at > ? AND code_cipher IS NOT NULL
              ORDER BY created_at DESC LIMIT 1'
        );
        $st->execute([$userId, $nowUtc]);
        $cipher = $st->fetchColumn();
        return $cipher === false || $cipher === null ? null : (string)$cipher;
    }

    /**
     * The ciphertext of one store's LIVE, unexpired whole-store code, or null
     * when there is none to show.
     *
     * The mirror image of liveUserPairingCipher(). It exists for the same reason:
     * Brevo's free tier is unreliable, so the admin screen has to be a
     * first-class delivery channel rather than a convenience.
     */
    public function liveStorePairingCipher(int $storeId, string $nowUtc): ?string
    {
        $st = $this->db->prepare(
            'SELECT code_cipher FROM platform_store_pairings
              WHERE store_id = ? AND used_at IS NULL AND expires_at > ? AND code_cipher IS NOT NULL
              ORDER BY created_at DESC LIMIT 1'
        );
        $st->execute([$storeId, $nowUtc]);
        $cipher = $st->fetchColumn();
        return $cipher === false || $cipher === null ? null : (string)$cipher;
    }

    /**
     * Live (unused, unexpired) pairings for a store — shown on the detail page.
     *
     * can_reveal tells the view whether the code can still be re-read. It is
     * false for codes minted before migration 005 or when no encryption key is
     * configured, so the UI can hide the Copy button instead of offering a dead
     * end. code_cipher itself is selected so the controller can decrypt the
     * live code straight onto the card.
     */
    public function pairingsFor(int $storeId, string $nowUtc): array
    {
        $st = $this->db->prepare(
            'SELECT id, created_at, expires_at, used_at, code_cipher,
                    (code_cipher IS NOT NULL) AS can_reveal
               FROM platform_store_pairings
              WHERE store_id = ? AND used_at IS NULL AND expires_at > ?
           ORDER BY created_at DESC LIMIT 10'
        );
        $st->execute([$storeId, $nowUtc]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
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

    /**
     * Order count + net revenue for today and this month, over ACTIVE stores.
     *
     * Built from the guarded helpers rather than hard-coded column names: voided_total
     * and status are both added by best-effort ALTERs, so on an older schema the
     * original literal query raised "column does not exist" and took the whole
     * monitoring page down with it.
     *
     * @return array{today:array{count:int,total:float},month:array{count:int,total:float}}
     */
    public function volume(int $now): array
    {
        $local = (new DateTimeImmutable('@' . $now))->setTimezone($this->tz());
        $monthStart = $local->modify('first day of this month')->setTime(0, 0)
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $sql = 'SELECT COUNT(*) AS c, COALESCE(SUM(' . $this->netSql('t') . '),0) AS v
                  FROM transactions t JOIN stores s ON s.id = t.store_id
                 WHERE s.status = \'active\' AND t.created_at >= ? ' . $this->voidFilter('t');
        $out = [];
        foreach (['today' => $this->localMidnightUtc($now, 0), 'month' => $monthStart] as $k => $since) {
            $st = $this->db->prepare($sql);
            $st->execute([$since]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'v' => 0];
            $out[$k] = ['count' => (int)$r['c'], 'total' => (float)$r['v']];
        }
        return $out;
    }

    /**
     * Everything the monitoring screen renders beyond the store counts.
     *
     * Each section is computed independently and wrapped, so one unsupported
     * breakdown costs that card only -- never the page.
     *
     * @return array<string,mixed>
     */
    public function analytics(int $now, int $days = 14): array
    {
        $days = max(2, min(90, $days));
        return [
            'trend'      => $this->safe(fn() => $this->revenueTrend($now, $days)),
            'top_stores' => $this->safe(fn() => $this->topStores($now, $days, 8)),
            'summary'    => $this->safe(fn() => $this->periodSummary($now, $days)),
            'splits'     => $this->safe(fn() => $this->optionalSplits($now, $days)),
        ];
    }

    /**
     * Runs a query and degrades to an empty-but-valid shape on failure.
     *
     * The monitoring page is an ops console: it must keep showing store counts and
     * session counts even if one analytics query is unsupported by the deployed
     * schema. Returns ['available' => false, 'error' => true] in that case.
     */
    private function safe(callable $fn): mixed
    {
        try {
            $rows = $fn();
            return is_array($rows) && $rows !== [] ? $rows : ['available' => false];
        } catch (\Throwable $e) {
            error_log('[platform-admin] analytics query failed: ' . $e->getMessage());
            return ['available' => false, 'error' => true];
        }
    }

    /**
     * Daily net revenue over the last N local days, gap-filled.
     *
     * Days with no sales are emitted as explicit zeroes. Leaving them out would
     * make a chart connect Tuesday straight to Friday and imply the shop traded on
     * days it was shut, which reads as lost revenue rather than no revenue.
     */
    private function revenueTrend(int $now, int $days): array
    {
        $st = $this->db->prepare(
            'SELECT ' . $this->localDayExpr('t.created_at') . ' AS d,
                    COUNT(*) AS c, COALESCE(SUM(' . $this->netSql('t') . '),0) AS v
               FROM transactions t JOIN stores s ON s.id = t.store_id
              WHERE s.status = \'active\' AND t.created_at >= ? ' . $this->voidFilter('t') . '
              GROUP BY d ORDER BY d'
        );
        // Placeholder order follows the SQL text, not reading order: the timezone
        // lands in the SELECT clause first, the window start in the WHERE clause.
        $st->execute([$this->tzName(), $this->localMidnightUtc($now, $days - 1)]);
        $by = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $by[(string)$r['d']] = ['total' => (float)$r['v'], 'count' => (int)$r['c']];
        }

        $local = (new DateTimeImmutable('@' . $now))->setTimezone($this->tz());
        $out   = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            // setTime(12,0) keeps the label on the intended calendar day across
            // any DST shift, which a midnight-anchored format can skip.
            $d = $local->modify('-' . $i . ' days')->setTime(12, 0)->format('Y-m-d');
            $out[] = [
                'date'  => $d,
                'total' => $by[$d]['total'] ?? 0.0,
                'count' => $by[$d]['count'] ?? 0,
            ];
        }
        return ['available' => true, 'days' => $out];
    }

    /**
     * Best stores by net revenue over the window.
     *
     * @return array{available:bool,stores:list<array{id:int,name:string,total:float,count:int}>}
     */
    private function topStores(int $now, int $days, int $limit): array
    {
        $st = $this->db->prepare(
            'SELECT t.store_id AS id,
                    COALESCE(NULLIF(s.name,\'\'), sn.shop_name, \'Store \' || t.store_id) AS name,
                    COALESCE(SUM(' . $this->netSql('t') . '),0) AS v, COUNT(*) AS c
               FROM transactions t
               JOIN stores s ON s.id = t.store_id
               LEFT JOIN LATERAL (
                   SELECT value AS shop_name FROM settings
                    WHERE store_id = t.store_id AND key = \'shop_name\' LIMIT 1
               ) sn ON TRUE
              WHERE s.status = \'active\' AND t.created_at >= ? ' . $this->voidFilter('t') . '
              GROUP BY t.store_id, name
              ORDER BY v DESC, t.store_id ASC
              LIMIT ' . max(1, min(25, $limit))
        );
        $st->execute([$this->localMidnightUtc($now, $days - 1)]);

        $stores = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $stores[] = [
                'id'    => (int)$r['id'],
                'name'  => (string)$r['name'],
                'total' => (float)$r['v'],
                'count' => (int)$r['c'],
            ];
        }
        return ['available' => true, 'stores' => $stores];
    }

    /**
     * Headline figures for the window: orders, net revenue, average order value
     * and the void rate.
     *
     * @return array{available:bool,count:int,total:float,aov:float,voided:int,void_rate:float}
     */
    private function periodSummary(int $now, int $days): array
    {
        $since  = $this->localMidnightUtc($now, $days - 1);
        $valid  = $this->notVoidSql('t');
        $hasStatus = $this->hasColumn('transactions', 'status');

        /* Revenue and the order count are restricted to non-voided orders with an
           aggregate FILTER rather than a WHERE clause. The void tally has to be
           measured against the same unfiltered row set, otherwise voidFilter()
           would have already deleted the very rows the void count is looking for
           and the void rate would silently report 0 forever. */
        $sql = 'SELECT COUNT(*) FILTER (WHERE ' . $valid . ') AS c'
             . ', COALESCE(SUM(' . $this->netSql('t') . ') FILTER (WHERE ' . $valid . '),0) AS v'
             . ($hasStatus ? ', COUNT(*) FILTER (WHERE ' . $this->voidedSql('t') . ') AS vx' : '')
             . " FROM transactions t JOIN stores s ON s.id = t.store_id
                WHERE s.status = 'active' AND t.created_at >= ?";
        $st = $this->db->prepare($sql);
        $st->execute([$since]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        $count = (int)($r['c'] ?? 0);
        $total = (float)($r['v'] ?? 0);
        $voidN = (int)($r['vx'] ?? 0);

        /* The void rate is a share of ALL orders placed in the window, not of the
           surviving ones -- dividing by $count alone would both overstate it and
           read >0 on a window where every single order was voided. */
        $placed = $count + $voidN;

        return [
            'available' => true,
            'count'     => $count,
            'total'     => $total,
            // Division by zero on an empty window would emit INF/NAN into JSON,
            // which admin.js then renders on screen as the literal text "NaN".
            'aov'       => $count > 0 ? round($total / $count, 2) : 0.0,
            'voided'    => $voidN,
            'void_rate' => $placed > 0 ? round($voidN / $placed, 4) : 0.0,
        ];
    }

    /**
     * Payment-method and order-type splits, ONLY where those columns exist.
     *
     * Neither payment_method nor order_type is ever created by any CREATE or ALTER
     * in this codebase -- they exist only as POS payload keys. Querying them anyway
     * raises SQLSTATE 42703 (undefined column) and kills the whole request, so each
     * split is feature-gated and reports itself null/unavailable instead.
     */
    private function optionalSplits(int $now, int $days): array
    {
        $since = $this->localMidnightUtc($now, $days - 1);
        return [
            'available'       => true,
            'payment_methods' => $this->hasColumn('transactions', 'payment_method')
                ? $this->splitBy('t.payment_method', $since, 'Unknown') : null,
            'order_types'     => $this->hasColumn('transactions', 'order_type')
                ? $this->splitBy('t.order_type', $since, 'Unknown') : null,
        ];
    }

    /** @return list<array{label:string,total:float,count:int}> */
    private function splitBy(string $expr, string $since, string $fallbackLabel): array
    {
        $st = $this->db->prepare(
            'SELECT COALESCE(NULLIF(' . $expr . ",\'\'), ?) AS label,
                    COALESCE(SUM(" . $this->netSql('t') . '),0) AS v, COUNT(*) AS c
               FROM transactions t JOIN stores s ON s.id = t.store_id
              WHERE s.status = \'active\' AND t.created_at >= ? ' . $this->voidFilter('t') . '
              GROUP BY label ORDER BY v DESC LIMIT 12'
        );
        $st->execute([$fallbackLabel, $since]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['label' => (string)$r['label'], 'total' => (float)$r['v'], 'count' => (int)$r['c']];
        }
        return $out;
    }

    /** Timezone name string, for use as a bound SQL parameter. */
    private function tzName(): string
    {
        return $this->tz()->getName();
    }

    /**
     * User activity inspector. Returns NO secrets (no password hash / reset token).
     *
     * @return list<array<string,mixed>>
     */
    public function searchUsers(string $q, int $now, int $limit = 50): array
    {
        $sql = "SELECT u.id, u.username, u.full_name, u.role, u.email, u.store_id, u.last_login,
                       s.name AS store_name, s.status AS store_status, s.client_type,
                       COALESCE(NULLIF(s.name, ''), sn.shop_name) AS display_store_name,
                       (SELECT COUNT(*) FROM auth_tokens t WHERE t.user_id = u.id AND t.expires_at > ?) AS live_tokens
                FROM users u
                LEFT JOIN stores s ON s.id = u.store_id
                LEFT JOIN LATERAL (
                    SELECT value AS shop_name FROM settings
                     WHERE store_id = u.store_id AND key = 'shop_name' LIMIT 1
                ) sn ON TRUE
                WHERE 1=1";
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
