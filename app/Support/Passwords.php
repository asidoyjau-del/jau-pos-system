<?php
declare(strict_types=1);

namespace ProCast\Support;

/** Argon2id (falls back to bcrypt when the PHP build lacks Argon2). */
final class Passwords
{
    public static function hash(string $plain): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($plain, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1]);
        }
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    /** Verified against when the account does not exist, so response time doesn't reveal valid emails. */
    public static function dummyHash(): string
    {
        static $h = null;
        return $h ??= self::hash(bin2hex(random_bytes(16)));
    }

    /** Minimum policy for super admins: 12+ chars, mixed classes. Returns error text or null. */
    public static function policyError(string $plain): ?string
    {
        // A value that ships in the committed .env.example SATISFIED every rule
        // below (14 chars, all four character classes), which is precisely why
        // it was a usable credential for anyone who had read the repository.
        // Length and complexity are not enough on their own -- the password also
        // has to be one that only this deployment knows. Same fail-closed rule
        // the sync token is held to.
        if (!Env::superAdminPasswordIsAcceptable($plain)) {
            return 'Password is empty, under 12 characters, or a published placeholder. '
                 . 'Generate a unique one, e.g. php -r "echo bin2hex(random_bytes(12)), PHP_EOL;"';
        }
        if (strlen($plain) < 12) {
            return 'Password must be at least 12 characters.';
        }
        if (!preg_match('/[a-z]/', $plain) || !preg_match('/[A-Z]/', $plain) || !preg_match('/\d/', $plain) || !preg_match('/[^A-Za-z0-9]/', $plain)) {
            return 'Password must contain lowercase, uppercase, a digit and a symbol.';
        }
        return null;
    }
}
