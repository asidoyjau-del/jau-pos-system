<?php
declare(strict_types=1);

namespace ProCast\Support;

use RuntimeException;

/** AES-256-GCM envelope for secrets at rest (TOTP seeds). Key: PLATFORM_ENC_KEY (base64, 32 bytes). */
final class Crypto
{
    private const PREFIX = 'v1:';

    private static function key(): string
    {
        $raw = base64_decode((string)Env::get('PLATFORM_ENC_KEY', ''), true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException('PLATFORM_ENC_KEY must be a base64-encoded 32-byte key.');
        }
        return $raw;
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
