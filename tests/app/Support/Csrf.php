<?php
declare(strict_types=1);

namespace ProCast\Support;

/** Per-session CSRF token for the Super Admin console (separate key from the POS token). */
final class Csrf
{
    private const KEY = 'sa_csrf';

    /** @param array<string,mixed> $session */
    public static function token(array &$session): string
    {
        if (empty($session[self::KEY]) || !is_string($session[self::KEY])) {
            $session[self::KEY] = bin2hex(random_bytes(32));
        }
        return $session[self::KEY];
    }

    /** @param array<string,mixed> $session */
    public static function verify(array $session, ?string $given): bool
    {
        $expected = $session[self::KEY] ?? null;
        return is_string($expected) && $expected !== '' && is_string($given) && hash_equals($expected, $given);
    }

    /** @param array<string,mixed> $session */
    public static function rotate(array &$session): void
    {
        $session[self::KEY] = bin2hex(random_bytes(32));
    }
}
