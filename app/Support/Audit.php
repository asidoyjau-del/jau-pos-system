<?php
declare(strict_types=1);

namespace ProCast\Support;

use PDO;

/**
 * Append-only audit trail. This class ONLY ever INSERTs; the database also
 * rejects UPDATE/DELETE on platform_audit_logs (trigger + revoked privileges,
 * see migrations/001_platform_tables.sql).
 */
final class Audit
{
    public const LOGIN_OK         = 'LOGIN_SUCCESS';
    public const LOGIN_FAILED     = 'LOGIN_FAILED';
    public const LOGIN_BLOCKED    = 'LOGIN_BLOCKED';
    public const TOTP_FAILED      = 'TOTP_FAILED';
    public const TOTP_ENROLLED    = 'TOTP_ENROLLED';
    public const LOGOUT           = 'LOGOUT';
    public const SESSION_REJECTED = 'SESSION_REJECTED';
    public const STORE_APPROVED   = 'STORE_APPROVED';
    public const STORE_REJECTED   = 'STORE_REJECTED';
    public const STORE_SUSPENDED  = 'STORE_SUSPENDED';
    public const STORE_REACTIVATED = 'STORE_REACTIVATED';
    public const STORE_DELETED    = 'STORE_DELETED';
    public const STORE_CLIENT_SET = 'STORE_CLIENT_TYPE_SET';
    public const PAIRING_ISSUED   = 'PAIRING_CODE_ISSUED';
    public const PAIRING_USED     = 'PAIRING_CODE_USED';
    public const PAIRING_FAILED   = 'PAIRING_CODE_FAILED';
    public const PAIRING_REVEALED = 'PAIRING_CODE_REVEALED';
    public const EMAIL_SENT       = 'NOTIFICATION_EMAIL_SENT';
    public const EMAIL_FAILED     = 'NOTIFICATION_EMAIL_FAILED';
    public const DOCUMENT_VIEWED  = 'DOCUMENT_VIEWED';
    public const USER_SEARCH      = 'USER_SEARCH';

    /** @param array<string,mixed> $details */
    public static function log(
        PDO $db,
        ?int $adminId,
        string $action,
        ?int $storeId,
        string $ip,
        string $userAgent,
        array $details = []
    ): void {
        $st = $db->prepare(
            'INSERT INTO platform_audit_logs (super_admin_id, action, target_store_id, ip_address, user_agent, details_json)
             VALUES (?,?,?,?,?,?)'
        );
        $st->execute([
            $adminId,
            $action,
            $storeId,
            substr($ip, 0, 64),
            substr($userAgent, 0, 255),
            json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        ]);
    }
}
