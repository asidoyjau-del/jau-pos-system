<?php
declare(strict_types=1);

namespace ProCast\Support;

use RuntimeException;

/**
 * AES-256-GCM envelope for secrets at rest (TOTP seeds, pairing codes).
 *
 * Key material: base64-encoded 32 bytes.
 *
 * TWO env var names are accepted, in order:
 *   PLATFORM_ENC_KEY     — the name the code, docs and seed script all use.
 *   PLATFORM_CRYPTO_KEY  — the name declared in render.yaml.
 *
 * They used to disagree, and because a missing key is handled best-effort
 * (Crypto::encrypt() throws, the caller logs and stores NULL instead), the
 * mismatch was silent: pairing codes were still issued and redeemed perfectly
 * well, but code_cipher was always NULL, so the admin could never re-copy a code
 * and the "Copy the live code" button never appeared. Accepting both names means
 * an operator only has to set whichever one their deployment already declares.
 */
final class Crypto
{
    private const PREFIX = 'v1:';
    /** @var array<int,string> Accepted key env var names, in precedence order. */
    private const KEY_VARS = ['PLATFORM_ENC_KEY', 'PLATFORM_CRYPTO_KEY'];

    /**
     * Resolves the 32-byte key, or throws with a message naming every variable
     * that was tried so a misconfigured deployment is diagnosable from the log.
     */
    private static function key(): string
    {
        foreach (self::KEY_VARS as $var) {
            $encoded = trim((string)Env::get($var, ''));
            if ($encoded === '') {
                continue;
            }
            $raw = base64_decode($encoded, true);
            if ($raw !== false && strlen($raw) === 32) {
                return $raw;
            }
            // Also accept a raw 32-character string, which is what someone
            // pasting a hex-ish secret into the dashboard often ends up with.
            if (strlen($encoded) === 32) {
                return $encoded;
            }
            error_log(sprintf('Crypto: %s is set but is neither base64 of 32 bytes nor a raw 32-char string; ignoring it.', $var));
        }
        throw new RuntimeException(sprintf(
            'No usable encryption key: set %s (or %s) to a base64-encoded 32-byte value.',
            self::KEY_VARS[0],
            self::KEY_VARS[1]
        ));
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new RuntimeException('Encryption failed.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $blob): string
    {
        if (strncmp($blob, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            throw new RuntimeException('Unknown ciphertext format.');
        }
        $bin = base64_decode(substr($blob, strlen(self::PREFIX)), true);
        if ($bin === false || strlen($bin) < 29) {
            throw new RuntimeException('Corrupt ciphertext.');
        }
        $plain = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
        if ($plain === false) {
            throw new RuntimeException('Decryption failed (wrong key or tampered data).');
        }
        return $plain;
    }
}
