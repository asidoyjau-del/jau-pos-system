<?php
/**
 * TotpQrTest.php
 * ==================
 * Unit tests for the ProCast TOTP implementation with QR code support
 * for Google Authenticator / Authy enrollment.
 *
 * Run WITHOUT a web server — pure PHP, no framework needed:
 *
 *   php tests/TotpQrTest.php
 *
 * Tests cover:
 *   - Secret generation (Base32, valid alphabet, correct length)
 *   - OTP code generation and verification
 *   - otpauth:// URI format (what gets encoded into the QR code)
 *   - QR code data integrity (the URI can be parsed back)
 *   - Time-window verification (past, current, future codes)
 *   - Replay protection (same code rejected after use)
 *   - Base32 encode/decode round-trip
 *
 * All tests use known test vectors to verify correctness.
 */
declare(strict_types=1);

/* ── Minimal autoloader ────────────────────────────────────────── */
if (!defined('PROCAST_ROOT')) {
    define('PROCAST_ROOT', dirname(__DIR__));
}
require_once PROCAST_ROOT . '/app/Bootstrap.php';

use ProCast\Support\Totp;

/* ── Helpers ───────────────────────────────────────────────────── */
$pass = 0; $fail = 0;

function assert_test(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        echo "\033[32m  ✓ PASS\033[0m  {$name}\n";
        $pass++;
    } else {
        echo "\033[31m  ✗ FAIL\033[0m  {$name}" . ($detail ? " — {$detail}" : '') . "\n";
        $fail++;
    }
}

/* ── Tests ─────────────────────────────────────────────────────── */

echo "\nTOTP + QR Code (Google Authenticator) Test Suite\n";
echo str_repeat('═', 55) . "\n";

/* ── T1: Secret generation ────────────────────────────────────── */
echo "\n📋 T1: Secret generation\n";

$secret = Totp::generateSecret();
assert_test('Secret is a string', is_string($secret));
assert_test('Secret length is 32 chars (20 bytes → 32 Base32 chars)', strlen($secret) === 32, "got " . strlen($secret));
assert_test('Secret uses valid Base32 alphabet (A-Z, 2-7)', preg_match('/^[A-Z2-7]+$/', $secret) === 1);
assert_test('Secret has no padding (=)', strpos($secret, '=') === false);
assert_test('Two consecutive secrets differ', $secret !== Totp::generateSecret());

/* ── T2: Base32 encode/decode round-trip ──────────────────────── */
echo "\n📋 T2: Base32 encode/decode round-trip\n";

// Note: Our Base32 implementation does NOT add RFC 4648 padding (=).
// "Hello" (5 bytes = 40 bits) → 8 groups of 5 bits → "JBSWY3DP" (no padding needed)
$encoded = Totp::base32Encode('Hello');
assert_test('Base32 encode of "Hello" is "JBSWY3DP"', $encoded === 'JBSWY3DP', "got {$encoded}");
assert_test('Base32 decode round-trips correctly', Totp::base32Decode($encoded) === 'Hello');

$binary = random_bytes(20);
assert_test('Random binary round-trips through Base32', Totp::base32Decode(Totp::base32Encode($binary)) === $binary);
assert_test('Decode tolerates lowercase input', Totp::base32Decode(strtolower($secret)) === Totp::base32Decode(strtoupper($secret)));

/* ── T3: OTP code generation ──────────────────────────────────── */
echo "\n📋 T3: OTP code generation\n";

// Our implementation uses 6-digit codes (standard for Google Authenticator).
// RFC 6238 test vectors use 8 digits by default, but the underlying HOTP
// calculation (RFC 4226) is the same. A 6-digit code is exactly the last
// 6 digits of the 8-digit code: value % 10^6 == (value % 10^8) % 10^6.

// Secret "12345678901234567890" (same as RFC 6238)
$testSecretBase32 = Totp::base32Encode('12345678901234567890');

// RFC 6238 SHA-1 test vectors (verified against RFC 6238 Appendix B):
//   t=59         → 94287082
//   t=1111111109 → 07081804
//   t=1234567890 → 89005924
$rfcVectors = [
    59         => '94287082',
    1111111109 => '07081804',
    1234567890 => '89005924',
];

foreach ($rfcVectors as $t => $expected8) {
    $our6 = Totp::codeAt($testSecretBase32, Totp::step($t));
    $expected6 = substr($expected8, -6); // last 6 digits of the 8-digit RFC value
    assert_test("TOTP t={$t}: 6-digit code is last 6 of RFC 6238 value {$expected8} ({$expected6})",
        $our6 === $expected6, "got {$our6}");
}

/* ── T4: OTP code format ──────────────────────────────────────── */
echo "\n📋 T4: OTP code format\n";

$now = time();
$step = Totp::step($now);
$code = Totp::codeAt($secret, $step);
assert_test('Code is always 6 digits', strlen($code) === 6);
assert_test('Code is numeric', ctype_digit($code));

/* ── T5: otpauth:// URI (the QR code payload) ─────────────────── */
echo "\n📋 T5: otpauth:// URI generation (QR code payload)\n";

$issuer = 'ProCast Platform';
$account = 'admin@example.com';
$otpauth = Totp::otpauthUri($issuer, $account, $secret);
assert_test('URI starts with otpauth://totp/', strpos($otpauth, 'otpauth://totp/') === 0);
assert_test('URI contains secret parameter', strpos($otpauth, 'secret=' . $secret) !== false);
// Note: otpauthUri uses rawurlencode() which encodes spaces as %20,
// colons as %3A, and @ as %40
assert_test('URI contains issuer parameter', strpos($otpauth, 'issuer=' . rawurlencode($issuer)) !== false);
assert_test('URI contains algorithm=SHA1', strpos($otpauth, 'algorithm=SHA1') !== false);
assert_test('URI contains digits=6', strpos($otpauth, 'digits=6') !== false);
assert_test('URI contains period=30', strpos($otpauth, 'period=30') !== false);

$pathPart = substr($otpauth, strlen('otpauth://totp/'));
$pathPart = explode('?', $pathPart)[0];
assert_test('URI path contains rawurlencoded issuer:account',
    $pathPart === rawurlencode($issuer . ':' . $account), "got {$pathPart}");

/* ── T6: Verify the URI can be parsed back (QR code round-trip) ── */
echo "\n📋 T6: OTP auth URI round-trip (simulate QR scan)\n";

parse_str(parse_url($otpauth, PHP_URL_QUERY), $params);
assert_test('Parsed secret matches original', ($params['secret'] ?? '') === $secret);
assert_test('Parsed issuer matches', ($params['issuer'] ?? '') === $issuer);
assert_test('Parsed digits is 6', ($params['digits'] ?? '') === '6');
assert_test('Parsed algorithm is SHA1', ($params['algorithm'] ?? '') === 'SHA1');
assert_test('Parsed period is 30', ($params['period'] ?? '') === '30');

$parsedSecret = $params['secret'] ?? '';
$verifyCode = Totp::codeAt($parsedSecret, Totp::step($now));
assert_test('Code from parsed secret matches code from original secret',
    $verifyCode === Totp::codeAt($secret, Totp::step($now)));

/* ── T7: TOTP verification with time window ───────────────────── */
echo "\n📋 T7: TOTP verification with time window\n";

$currentStep = Totp::step($now);
$currentCode = Totp::codeAt($secret, $currentStep);

$matched = Totp::verify($secret, $currentCode, $now, 0, 1);
assert_test('Verify accepts current valid code', $matched !== null);
assert_test('Verify returns the correct step value', $matched === $currentStep);

$pastStep = $currentStep - 1;
$pastCode = Totp::codeAt($secret, $pastStep);
assert_test('Verify accepts code from previous 30s window',
    Totp::verify($secret, $pastCode, $now, 0, 1) === $pastStep);

$futureStep = $currentStep + 1;
$futureCode = Totp::codeAt($secret, $futureStep);
assert_test('Verify accepts code from next 30s window',
    Totp::verify($secret, $futureCode, $now, 0, 1) === $futureStep);

/* ── T8: Replay protection ────────────────────────────────────── */
echo "\n📋 T8: Replay protection\n";

$usedCode = Totp::codeAt($secret, $currentStep);
$firstUse = Totp::verify($secret, $usedCode, $now, 0, 1);
assert_test('First use of code succeeds', $firstUse === $currentStep);
assert_test('Replaying the same code is rejected',
    Totp::verify($secret, $usedCode, $now, $currentStep, 1) === null);

/* ── T9: Invalid code rejection ───────────────────────────────── */
echo "\n📋 T9: Invalid code rejection\n";

assert_test('Random 6 digits is rejected', Totp::verify($secret, '000000', $now, 0, 1) === null);
assert_test('Wrong-length code is rejected', Totp::verify($secret, '12345', $now, 0, 1) === null);
assert_test('Non-numeric code is rejected', Totp::verify($secret, 'abcdef', $now, 0, 1) === null);

$wrongSecret = Totp::generateSecret();
$codeFromRightSecret = Totp::codeAt($secret, $currentStep);
assert_test('Valid-format code from different secret is rejected',
    Totp::verify($wrongSecret, $codeFromRightSecret, $now, 0, 1) === null);

/* ── T10: QR code payload size ───────────────────────────────── */
echo "\n📋 T10: QR code payload characteristics\n";

$qrSize = strlen($otpauth);
assert_test('OTP auth URI fits in QR code (< 250 chars)', $qrSize < 250, "size: {$qrSize}");
assert_test('URI is URL-safe (no spaces)', strpos($otpauth, ' ') === false);

/* ── T11: Multiple issuers/accounts ─────────────────────────── */
echo "\n📋 T11: Multiple issuers and accounts\n";

$issuer1 = 'ProCast Platform';
$issuer2 = 'My Other Store';
$account1 = 'admin@store1.com';
$account2 = 'admin@store2.com';

$uri1 = Totp::otpauthUri($issuer1, $account1, $secret);
$uri2 = Totp::otpauthUri($issuer2, $account2, $secret);
assert_test('Different issuers produce different URIs', $uri1 !== $uri2);
assert_test('Account email is in URI 1', strpos($uri1, urlencode($account1)) !== false);
assert_test('Account email is in URI 2', strpos($uri2, urlencode($account2)) !== false);

/* ── Summary ───────────────────────────────────────────────── */
$total = $pass + $fail;
echo "\n" . str_repeat('═', 55) . "\n";
echo "Results: {$pass}/{$total} passed";
if ($fail > 0) {
    echo "  \033[31m({$fail} FAILED)\033[0m\n";
    exit(1);
} else {
    echo "  \033[32m(all pass)\033[0m\n";
    exit(0);
}
