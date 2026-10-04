<?php
declare(strict_types=1);

namespace ProCast\Support;

/** RFC 6238 TOTP (SHA-1, 6 digits, 30 s) in pure PHP — compatible with Google Authenticator / Authy / 1Password. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    public const PERIOD = 30;

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[\s=-]+/', '', $b32) ?? '');
        $bits = '';
        for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
            $pos = strpos(self::ALPHABET, $b32[$i]);
            if ($pos === false) {
                return '';
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    public static function codeAt(string $secretB32, int $step): string
    {
        $key = self::base32Decode($secretB32);
        $counter = pack('N2', 0, $step); // 64-bit big-endian (step fits 32 bits until 2106)
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);
        return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function step(int $now): int
    {
        return intdiv($now, self::PERIOD);
    }

    /**
     * Verify a user-supplied code within ±$window steps.
     * Returns the matched time-step (so the caller can persist it and refuse
     * replay of the same code) or null when invalid / replayed.
     */
    public static function verify(string $secretB32, string $code, int $now, int $lastUsedStep = 0, int $window = 1): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code) || self::base32Decode($secretB32) === '') {
            return null;
        }
        $current = self::step($now);
        $matched = null;
        for ($i = -$window; $i <= $window; $i++) {
            $s = $current + $i;
            // evaluate every candidate (no early exit) to keep timing flat
            if (hash_equals(self::codeAt($secretB32, $s), $code) && $s > $lastUsedStep) {
                $matched = $s;
            }
        }
        return $matched;
    }

    public static function otpauthUri(string $issuer, string $account, string $secretB32): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secretB32 . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }
}
