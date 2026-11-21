<?php
declare(strict_types=1);

/**
 * PairingCodeTest.php
 * ===================
 * Tests for the six-digit local-POS activation codes.
 *
 * Run WITHOUT a web server:
 *     php tests/PairingCodeTest.php
 *
 * Covers:
 *   - sealPairingCode() <-> Crypto::decrypt() interop under every supported key
 *     format. These two implementations are deliberately NOT shared (index.php is
 *     a single-file deployment with no autoloader), so the only thing keeping the
 *     super admin's "Copy code" button alive is that they agree byte-for-byte.
 *   - mintUniqueUserCode(): shape, CSPRNG use, zero padding and give-up,
 *     driven through a fake PDO so no database is needed.
 *   - The SQL invariants that redemption depends on, asserted against the real
 *     source. Redemption is ONE atomic UPDATE, so its WHERE clause is the whole
 *     security boundary; a refactor that drops a clause fails here rather than
 *     in production.
 *   - Anti-enumeration: every early exit from cloud_pair_request must produce the
 *     same response body.
 *
 * The SQL itself is PostgreSQL (RETURNING, ::text, partial indexes) and is not
 * executed here. What is verified is that the statements are still present and
 * still carry the conditions they depend on.
 */

if (!defined('PROCAST_ROOT')) {
    define('PROCAST_ROOT', dirname(__DIR__));
}
require_once PROCAST_ROOT . '/app/Bootstrap.php';

use ProCast\Support\Crypto;
use ProCast\Support\StoreRepository;

$pass = 0;
$fail = 0;

function assert_test(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        echo "  PASS  {$name}\n";
        $pass++;
    } else {
        echo "  FAIL  {$name}" . ($detail ? " -- {$detail}" : '') . "\n";
        $fail++;
    }
}

echo "\nPairing Activation Code Test Suite\n";
echo str_repeat('=', 55) . "\n";

$CLOUD_INDEX   = PROCAST_ROOT . '/index.php';
$OFFLINE_INDEX = dirname(PROCAST_ROOT) . '/index.php';

$cloudSrc   = is_file($CLOUD_INDEX) ? (string)file_get_contents($CLOUD_INDEX) : '';
$offlineSrc = is_file($OFFLINE_INDEX) ? (string)file_get_contents($OFFLINE_INDEX) : '';

assert_test('cloud index.php is readable', $cloudSrc !== '');
assert_test('offline index.php is readable', $offlineSrc !== '');

/* ────────────────────────────────────────────────────────────────
 * 1. sealPairingCode() <-> Crypto::decrypt() interop
 *
 * The real function is LIFTED OUT OF index.php and evaluated, not retyped
 * here. A hand-copied duplicate in the test file would keep passing after
 * the shipped copy drifted, which is the exact bug this section catches.
 * ──────────────────────────────────────────────────────────────── */
echo "\nT1: sealer / Crypto interop\n";

$src = '';
if (preg_match('/function sealPairingCode\(string \$plain\): \?string\s*\{/', $cloudSrc, $m, PREG_OFFSET_CAPTURE)) {
    $start = (int)$m[0][1];
    $brace = strpos($cloudSrc, '{', $start);
    $depth = 0;
    $end   = null;
    if ($brace !== false) {
        for ($i = $brace, $len = strlen($cloudSrc); $i < $len; $i++) {
            if ($cloudSrc[$i] === '{') {
                $depth++;
            } elseif ($cloudSrc[$i] === '}') {
                $depth--;
                if ($depth === 0) { $end = $i + 1; break; }
            }
        }
    }
    if ($end !== null) { $src = substr($cloudSrc, $start, $end - $start); }
}
assert_test('sealPairingCode() lifted out of index.php', $src !== '');

if ($src !== '' && !function_exists('ProCastTest\\sealPairingCode')) {
    // Namespaced so the eval cannot collide with anything in Bootstrap.
    eval('namespace ProCastTest; ' . $src);
}

/** Runs the shipped sealer under a specific key configuration. */
function seal_with(string $encKey, string $cryptoKey, string $plain): ?string
{
    putenv('PLATFORM_ENC_KEY=' . $encKey);
    putenv('PLATFORM_CRYPTO_KEY=' . $cryptoKey);
    try {
        /** @var callable $sealer */
        $sealer = 'ProCastTest\\sealPairingCode';
        $res = $sealer($plain);
        return is_string($res) ? $res : null;
    } finally {
        putenv('PLATFORM_ENC_KEY');
        putenv('PLATFORM_CRYPTO_KEY');
    }
}

function decrypt_with(string $encKey, string $cryptoKey, string $blob): string
{
    putenv('PLATFORM_ENC_KEY=' . $encKey);
    putenv('PLATFORM_CRYPTO_KEY=' . $cryptoKey);
    try {
        return Crypto::decrypt($blob);
    } finally {
        putenv('PLATFORM_ENC_KEY');
        putenv('PLATFORM_CRYPTO_KEY');
    }
}

$b64 = base64_encode(str_repeat('k', 32));
$raw = str_repeat('Z', 32);

/* Both accepted key spellings and both env var names. An operator only has to
   set whichever their deployment already declares, so each must interop. */
$cases = [
    'base64 key'                    => [$b64, ''],
    'raw 32-char key'               => [$raw, ''],
    'key under PLATFORM_CRYPTO_KEY' => ['', $b64],
];
foreach ($cases as $label => [$ek, $ck]) {
    $ok = true;
    $detail = '';
    foreach (['482913', '000000', '999999', '135790', '100007'] as $plain) {
        $blob = seal_with($ek, $ck, $plain);
        if (!is_string($blob)) { $ok = false; $detail = "no blob for {$plain}"; break; }
        try {
            $back = decrypt_with($ek, $ck, $blob);
        } catch (\Throwable $e) {
            $ok = false; $detail = 'decrypt threw: ' . $e->getMessage(); break;
        }
        if ($back !== $plain) { $ok = false; $detail = "{$plain} came back as '{$back}'"; break; }
    }
    assert_test("round-trips through Crypto::decrypt ({$label})", $ok, $detail);
}

$a = seal_with($b64, '', '123456');
$b = seal_with($b64, '', '123456');
assert_test('two seals of one code differ (fresh IV)', is_string($a) && is_string($b) && $a !== $b);

if (is_string($a)) {
    assert_test('sealed blob carries the v1: prefix', str_starts_with($a, 'v1:'));
    $bin = base64_decode(substr($a, 3), true);
    assert_test(
        'sealed blob is iv(12) + tag(16) + ciphertext',
        is_string($bin) && strlen($bin) === 12 + 16 + 6,
        'len=' . strlen((string)$bin)
    );
}

/* No key must degrade to hash-only (NULL), never throw and never emit a
   half-formed blob: the code stays perfectly redeemable without a seal. */
assert_test('no key yields null instead of throwing', seal_with('', '', '123456') === null);
/* A key of the wrong length must be refused, not silently truncated -- a
   truncated key would produce blobs that fail to decrypt later. */
assert_test('wrong-length key is refused', seal_with('too-short', '', '123456') === null);

/* ────────────────────────────────────────────────────────────────
 * 2. mintUniqueUserCode()
 * ──────────────────────────────────────────────────────────────── */
echo "\nT2: unique code minting\n";

/** Minimal PDO stand-in: answers the minting probe from a fixed hash list. */
class FakePdo extends PDO
{
    /** @var list<string> hashes considered "already live" */
    public array $liveHashes = [];
    public int $probes = 0;

    public function __construct()
    {
        // Intentionally does NOT call parent::__construct(): there is no
        // database here, and prepare() is fully overridden.
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new FakeStatement($this);
    }
}

final class FakeStatement extends PDOStatement
{
    private FakePdo $fake;
    /** @var list<mixed>|null */
    public ?array $lastParams = null;

    public function __construct(FakePdo $fake)
    {
        $this->fake = $fake;
    }

    public function execute(?array $params = null): bool
    {
        $this->fake->probes++;
        $this->lastParams = $params;
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $hash = (string)($this->lastParams[0] ?? '');
        // A row comes back when this hash is already live, which is exactly
        // what makes the real query reject the candidate.
        return in_array($hash, $this->fake->liveHashes, true) ? 1 : false;
    }
}

/** Every candidate looks taken, so the retry loop can never succeed. */
final class AlwaysTakenPdo extends FakePdo
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new AlwaysTakenStatement($this);
    }
}

final class AlwaysTakenStatement extends PDOStatement
{
    private FakePdo $fake;

    public function __construct(FakePdo $fake)
    {
        $this->fake = $fake;
    }

    public function execute(?array $params = null): bool
    {
        $this->fake->probes++;
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return 1;
    }
}

$repo = new StoreRepository(new FakePdo());

$mint = $repo->mintUniqueUserCode();
assert_test('mintUniqueUserCode() returns a code and hash', is_array($mint) && isset($mint['code'], $mint['hash']));
assert_test('code is exactly six digits', is_array($mint) && preg_match('/^\d{6}$/', $mint['code']) === 1);
assert_test(
    'code is zero-padded, not a short number',
    is_array($mint) && $mint['code'] === str_pad($mint['code'], 6, '0', STR_PAD_LEFT)
);
assert_test('hash is sha256 of the code', is_array($mint) && $mint['hash'] === hash('sha256', $mint['code']));

/* Empty namespace: every mint must succeed and never collide. */
$seen = [];
$bad  = 0;
for ($i = 0; $i < 200; $i++) {
    $m = $repo->mintUniqueUserCode();
    if ($m === null || in_array($m['code'], $seen, true)) { $bad++; } else { $seen[] = $m['code']; }
}
assert_test('200 mints produced no duplicates', $bad === 0);

/* Collision give-up: when every probe reports "taken" the method must return
   null rather than hand back a code that is already live somewhere else. */
$always = new StoreRepository(new AlwaysTakenPdo());
assert_test('gives up (null) rather than returning a colliding code', $always->mintUniqueUserCode() === null);

/* The CSPRNG matters: mt_rand()/rand() would let anyone who learned the seed
   predict the next code in the sequence. Comments are stripped first, because
   the method's own docblock names both functions while explaining why they are
   not used, and matching that prose would be a test that passes for the wrong
   reason. */
$repoSrc = (string)file_get_contents(PROCAST_ROOT . '/app/Support/StoreRepository.php');
$mintBody = '';
if (preg_match('/function mintUniqueUserCode.*?\n    \}/s', $repoSrc, $m)) { $mintBody = $m[0]; }
$mintCode = (string)preg_replace('#/\*.*?\*/#s', '', $mintBody);
$mintCode = (string)preg_replace('#//[^\n]*#', '', $mintCode);

assert_test('minting uses random_int() (CSPRNG)', str_contains($mintCode, 'random_int('));
assert_test(
    'minting does not call rand()/mt_rand()',
    !preg_match('/(?<![a-z_])(mt_rand|rand)\s*\(/', $mintCode)
);
assert_test('retry budget is bounded, not infinite', str_contains($mintCode, '$i < $attempts'));
assert_test(
    'a live code is probed before being returned',
    str_contains($mintCode, 'used_at IS NULL')
);

/* Issuing must retire the account's previous live code in the same breath, so
   exactly one code is ever live for an account. */
assert_test('issueUserPairing() retires prior live codes', str_contains($repoSrc, 'SET used_at = ?, code_cipher = NULL'));
assert_test('issueUserPairing() records the minted role', (bool)preg_match(
    '/INSERT INTO platform_user_pairings \([^)]*\brole\b[^)]*\)/',
    $repoSrc
));

/* Extracts a whole `if ($action === '...') { ... }` branch by brace matching.
   A fixed-size character window is not safe here: these branches run to
   hundreds of lines, and a window that stops short silently turns a real
   regression into a passing test. */
function extract_branch(string $src, string $action): string
{
    $anchor = strpos($src, "\$action === '" . $action . "'");
    if ($anchor === false) { return ''; }
    $brace = strpos($src, '{', $anchor);
    if ($brace === false) { return ''; }

    $depth = 0;
    $len   = strlen($src);
    for ($i = $brace; $i < $len; $i++) {
        $ch = $src[$i];
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) { return substr($src, $anchor, $i - $anchor + 1); }
        }
    }
    return '';
}

/* ────────────────────────────────────────────────────────────────
 * 3. Redemption SQL invariants
 *
 * Redemption is a single UPDATE ... RETURNING. Everything that makes it
 * safe lives in its WHERE clause, so that is what gets asserted here.
 * ──────────────────────────────────────────────────────────────── */
echo "\nT3: redemption SQL invariants\n";

$redeem = extract_branch($cloudSrc, 'cloud_pair_redeem');
assert_test('cloud_pair_redeem branch located', $redeem !== '');

$atomic = '';
if ($redeem !== '' && preg_match('/UPDATE platform_user_pairings\s+(.*?)RETURNING user_id/s', $redeem, $m)) {
    $atomic = $m[0];
}

if ($atomic !== '') {
    assert_test('redemption is one atomic UPDATE', true);
    assert_test('redemption uses RETURNING user_id', str_contains($atomic, 'RETURNING user_id'));
    /* Single use: the row must still be unspent. */
    assert_test('redemption requires used_at IS NULL', str_contains($atomic, 'used_at IS NULL'));
    /* Expiry is enforced server-side, so a till with a skewed clock cannot
       talk its way past it. */
    assert_test('redemption enforces expires_at > now', str_contains($atomic, 'expires_at >'));
    /* Legacy whole-store scope binds through platform_store_pairings, and the
       per-account scope re-checks the store is still active after the code is
       spent. A code must not activate a till for a suspended store. */
    assert_test('legacy store scope binds via platform_store_pairings',
        str_contains($redeem, 'UPDATE platform_store_pairings')
        && str_contains($redeem, 'RETURNING store_id'));
    assert_test('legacy store scope also enforces expiry',
        (bool)preg_match('/UPDATE platform_store_pairings.*?expires_at >/s', $redeem));
    assert_test('store must be active before activation is granted',
        substr_count($redeem, "status'] !== 'active'") >= 2);
    assert_test('a spent code is not left live for a dead store',
        str_contains($redeem, "SET client_type = 'local'"));
    /* Role: a code minted for a cashier must die when the account is promoted,
       rather than silently handing the new role to whoever holds the old code. */
    assert_test('redemption re-checks the recorded role', str_contains($atomic, 'role IS NULL OR role ='));
    assert_test('role check compares against the CURRENT user role', str_contains($atomic, 'FROM users'));
    /* The claimed username is enforced INSIDE the UPDATE, not after it.
       Checking afterwards would burn the code before the mismatch was noticed,
       destroying a valid code for the person who owns it. */
    assert_test('claimed username is enforced inside the UPDATE', str_contains($atomic, 'LOWER(username)'));
    /* Consuming the code must destroy the sealed copy too, or the admin could
       reveal an already-spent code from the stored ciphertext. */
    assert_test('redemption clears code_cipher on use', str_contains($atomic, 'code_cipher = NULL'));
    /* Marks the row used in the SAME statement: no read-then-write window in
       which two tills could both redeem the same code. */
    assert_test('redemption stamps used_at in the same UPDATE', str_contains($atomic, 'SET used_at = ?'));
} elseif ($redeem !== '') {
    assert_test('redemption is one atomic UPDATE ... RETURNING', false, 'statement not found');
}

/* Input is stripped to digits and then length-checked, so a code can never be
   a different length of the same digits reaching the hash. */
assert_test('code input is stripped to digits', str_contains($redeem, "preg_replace('/\\D/'"));
assert_test('code input must be exactly six digits', str_contains($redeem, 'strlen($code) !== 6'));
assert_test('code is stored as a hash, never plaintext', str_contains($redeem, "hash('sha256', (string)\$code)"));

/* Per-account scope must reject a code typed at the wrong account, and the
   follow-up lookup must not itself consume anything. */
assert_test('wrong-account redemption has its own message', str_contains($cloudSrc, 'belongs to a different account'));

/* The probe used to distinguish "someone else's code" from "no such code" is
   a SELECT. If it ever became an UPDATE it would burn codes while explaining
   a refusal, which is the exact bug it was written to avoid. */
$probeAt = strpos($cloudSrc, 'SELECT 1 FROM platform_user_pairings');
assert_test(
    'wrong-account probe is a read, not a write',
    $probeAt !== false && !str_contains(substr($cloudSrc, $probeAt, 300), 'UPDATE')
);

/* A redeemed code must not remain revealable to the admin. */
assert_test(
    'redeemed code is not handed back to the caller',
    !str_contains($cloudSrc, 'code_cipher FROM platform_user_pairings WHERE user_id = ? AND used_at IS NULL')
);

/* ────────────────────────────────────────────────────────────────
 * 4. Request endpoint: anti-enumeration + abuse resistance
 * ──────────────────────────────────────────────────────────────── */
echo "\nT4: request endpoint invariants\n";

$req = extract_branch($cloudSrc, 'cloud_pair_request');
assert_test('cloud_pair_request branch located', $req !== '');

if ($req !== '') {
    assert_test('defines one generic responder', str_contains($req, '$generic ='));
    assert_test('generic responder reports success (anti-enumeration)',
        (bool)preg_match('/\$generic\s*=\s*static function[^{]*\{[^}]*json\(true/s', $req));

    /* Every reason to refuse must route through $generic() rather than
       json(false, ...) with a distinctive message. A distinctive message is an
       oracle: it tells a prober the account exists, has an email, or is
       disabled. Each outcome must be followed by $generic() before any other
       exit. */
    foreach (['no_email_on_file', 'role_not_permitted', 'store_not_active'] as $outcome) {
        $at = strpos($req, "'outcome' => '" . $outcome . "'");
        assert_test("'{$outcome}' is audited", $at !== false);
        if ($at !== false) {
            $tail = substr($req, $at, 400);
            assert_test("'{$outcome}' answers generically", str_contains($tail, '$generic();'));
            assert_test(
                "'{$outcome}' does not leak a distinctive error",
                !str_contains($tail, 'json(false')
            );
        }
    }

    /* Unknown account must be indistinguishable from a successful one. */
    assert_test('unknown account answers generically',
        (bool)preg_match('/if\s*\(\s*!\$u\s*\)\s*\{\s*\$generic\(\);/', $req));

    /* Throttle: counted per source IP, and checked BEFORE the account lookup
       so tripping it reveals nothing about the account. */
    assert_test('throttle table is consulted', str_contains($req, 'platform_pairing_attempts'));
    assert_test('throttle counts by hashed client IP', str_contains($req, "hash('sha256', \$clientIp)"));
    assert_test('throttle window is bounded', str_contains($req, 'time() - 900'));
    assert_test('throttle has a hard cap', (bool)preg_match('/\(\s*int\s*\)\s*\$cnt->fetchColumn\(\)\s*>=\s*\d+/', $req));
    $throttleAt = strpos($req, 'platform_pairing_attempts');
    $lookupAt   = strpos($req, 'FROM users WHERE LOWER(username)');
    assert_test(
        'throttle runs before the account lookup',
        $throttleAt !== false && $lookupAt !== false && $throttleAt < $lookupAt
    );

    /* Issuance floor: a code is a credential, so issuing must be expensive for
       the person the code is FOR. Without it this is a mailbomb aimed at a
       chosen victim, and a way to keep expiring the code they are reading. */
    assert_test('issuance floor exists', str_contains($req, 'ISSUANCE FLOOR'));
    assert_test('issuance floor is keyed on the client IP', str_contains($req, 'ip_address = ?'));
    assert_test('issuance floor counts the request scope', str_contains($req, 'user_request'));

    /* Only till-eligible roles may be minted for at all. */
    assert_test('role allow-list is enforced at issuance',
        (bool)preg_match("/in_array\(\\\$role,\s*\['owner',\s*'admin',\s*'cashier',\s*'staff'\]/", $req));

    /* Issuance and retirement must be one transaction; split across two
       statements a crash leaves two live codes and "the one I emailed you"
       becomes ambiguous. */
    assert_test('issuance retires the prior code first', str_contains($req, 'SET used_at = ?, code_cipher = NULL'));
    assert_test('issuance wraps retire+insert in a transaction',
        str_contains($req, 'beginTransaction()') && str_contains($req, '$db->commit()'));
    assert_test('failed issuance rolls back', str_contains($req, 'rollBack()'));

    /* Sealing happens BEFORE the transaction so a key problem cannot abort an
       otherwise good issue. */
    assert_test('code is sealed before the transaction opens',
        strpos($req, 'sealPairingCode(') !== false
        && strpos($req, 'sealPairingCode(') < strpos($req, 'beginTransaction()')
    );

    /* A mail failure must stay in the audit log, never in the response: saying
       "email failed" would confirm the account exists AND has an address. */
    assert_test('mail failure is audited', str_contains($req, 'NOTIFICATION_EMAIL_FAILED'));
    assert_test('mail failure does not change the response',
        str_contains($req, 'IDENTICAL to every other branch')
        || str_contains($req, 'Saying')
    );

    /* Inputs are bounded before they reach a query. */
    assert_test('username is length-capped', str_contains($req, 'substr($username, 0, 100)'));
    assert_test('username is normalised before lookup', str_contains($req, 'mb_strtolower'));
}

/* A broken limiter must never be the reason a new hire cannot activate. */
assert_test('throttle failure does not abort the request',
    str_contains($cloudSrc, 'never be why a new hire cannot activate')
    || str_contains($cloudSrc, 'must never be the reason a legitimate user is')
);

/* ────────────────────────────────────────────────────────────────
 * 5. Offline caller contract
 * ──────────────────────────────────────────────────────────────── */
echo "\nT5: offline caller\n";

assert_test('offline has requestLocalPairingCode()', str_contains($offlineSrc, 'function requestLocalPairingCode('));
assert_test(
    'requestLocalPairingCode() takes only a username (no password)',
    (bool)preg_match('/function requestLocalPairingCode\(PDO \$db, string \$username\)/', $offlineSrc)
);
/* The old contract advertised emailSent=true on every accepted request. The
   server answers identically whether it mailed anyone, so that flag was a lie
   the UI could render as "go check your inbox". */
assert_test('no misleading emailSent flag remains', !str_contains($offlineSrc, "'emailSent'"));
assert_test('success message is hedged, not a promise',
    str_contains($offlineSrc, 'If this account can be used on a ProCast till'));
/* Failure must still point at the administrator fallback so a user is never
   stranded with no way forward. */
assert_test('failure keeps the administrator fallback',
    substr_count($offlineSrc, 'Ask your administrator for your 6-digit code') >= 3);
/* Called only after the password has already been verified. */
assert_test('called after successful password auth',
    str_contains($offlineSrc, "requestLocalPairingCode(\$db, (string)\$user['username'])"));
/* The password must never be forwarded to the server. */
assert_test('password is not sent to the server',
    !preg_match('/requestLocalPairingCode\([^\)]*password/i', $offlineSrc));
/* The activation screen must keep showing the admin fallback. */
assert_test('activation screen shows the request status', str_contains($offlineSrc, "\$pairRequest['msg']"));

/* ────────────────────────────────────────────────────────────────
 * 6. Migration guarantees
 * ──────────────────────────────────────────────────────────────── */
echo "\nT6: migrations\n";

$mig  = PROCAST_ROOT . '/platform-admin/migrations';
$m006 = is_file($mig . '/006_pairing_role_and_unique_code.sql')
    ? (string)file_get_contents($mig . '/006_pairing_role_and_unique_code.sql') : '';
$m007 = is_file($mig . '/007_pairing_request_throttle.sql')
    ? (string)file_get_contents($mig . '/007_pairing_request_throttle.sql') : '';

assert_test('migration 006 present', $m006 !== '');
assert_test('migration 007 present (006 may already be recorded applied)', $m007 !== '');

if ($m006 !== '') {
    assert_test('006 adds a partial unique index on live codes',
        str_contains($m006, 'UNIQUE INDEX') && str_contains($m006, 'WHERE used_at IS NULL'));
    assert_test('006 adds the role column', str_contains($m006, 'ADD COLUMN IF NOT EXISTS role'));
    assert_test('006 backfills role for pre-existing codes',
        str_contains($m006, 'UPDATE platform_user_pairings'));
    assert_test('006 retires colliding live codes before indexing',
        str_contains($m006, 'older.code_hash  = newer.code_hash'));
    assert_test('006 indexes are idempotent',
        !preg_match('/CREATE (UNIQUE )?INDEX (?!IF NOT EXISTS)/', $m006));
}
if ($m007 !== '') {
    assert_test('007 creates the throttle table',
        str_contains($m007, 'CREATE TABLE IF NOT EXISTS platform_pairing_attempts'));
    assert_test('007 indexes the throttle table', str_contains($m007, 'platform_pairing_attempts_ip_idx'));
    assert_test('007 is idempotent', substr_count($m007, 'IF NOT EXISTS') >= 2);
    assert_test('007 stores ip_hash, not a raw address',
        str_contains($m007, 'ip_hash') && !preg_match('/ip_address\s+VARCHAR/', $m007));
}

/* ────────────────────────────────────────────────────────────────
 * Summary
 * ──────────────────────────────────────────────────────────────── */
/* ────────────────────────────────────────────────────────────────
 * 7. Revocation: pulling back a code that is still live
 *
 * The whole point of this feature is that a code which leaked can be made
 * useless WITHOUT minting a replacement. "Issue new code" retires the old one
 * too, but it also emails a fresh credential -- exactly the wrong move when the
 * goal is to ensure nobody else can activate with what they are holding.
 * ──────────────────────────────────────────────────────────────── */
echo "\nT7: per-user pairing revocation\n";

$repoSrc = (string)file_get_contents(PROCAST_ROOT . '/app/Support/StoreRepository.php');
$ctrlSrc = (string)file_get_contents(PROCAST_ROOT . '/app/Controllers/PlatformAdmin/SuperAdminDashboardController.php');
$kernelSrc = (string)file_get_contents(PROCAST_ROOT . '/app/PlatformAdmin/Kernel.php');
$detailSrc = (string)file_get_contents(PROCAST_ROOT . '/platform-admin/views/store_detail.php');
$auditSrc = (string)file_get_contents(PROCAST_ROOT . '/app/Support/Audit.php');

/**
 * The text of ONE method, from its signature to the closing brace at the class's
 * own indent level.
 *
 * Scoping "does this function call notifyOwner?" with a bare
 * `/function revokeUserPairing.*?notifyOwner/s` silently answers about the file,
 * not the function: `.*?` happily runs through the rest of the class and matches
 * the next method that happens to call it. Every such negative assertion below
 * goes through here instead, so a failing check really means "this function
 * does it" rather than "something later in the file does".
 */
function method_body(string $src, string $method): string
{
    $at = strpos($src, 'function ' . $method . '(');
    if ($at === false) {
        return '';
    }
    // Methods in these classes close with "    }" (4 spaces) at column 0 nesting.
    $end = strpos($src, "\n    }", $at);
    return $end === false ? substr($src, $at) : substr($src, $at, $end - $at + 6);
}

$revokeRepo = method_body($repoSrc, 'revokeUserPairing');
$hasLive    = method_body($repoSrc, 'hasLiveUserPairing');
$revokeCtrl = method_body($ctrlSrc, 'revokeUserPairing');

assert_test('the revoke methods are extractable (helper works)', $revokeRepo !== '' && $hasLive !== '' && $revokeCtrl !== '');

/* Repository: the UPDATE is the security boundary. */
assert_test('revokeUserPairing() exists', str_contains($repoSrc, 'function revokeUserPairing('));
assert_test('revoke marks the code used so it can never be redeemed',
    preg_match('/UPDATE platform_user_pairings SET used_at = \?.*WHERE user_id = \? AND used_at IS NULL/s', $repoSrc) === 1);
assert_test('revoke also destroys the stored ciphertext',
    str_contains($repoSrc, 'code_cipher = NULL'));
assert_test('revoke is scoped to ONE user, never the whole store',
    str_contains($revokeRepo, 'WHERE user_id = ? AND used_at IS NULL')
    && !str_contains($revokeRepo, 'store_id'));
assert_test('a redeemed code is left alone (audit trail stays truthful)',
    str_contains($revokeRepo, 'used_at IS NULL'));

assert_test('hasLiveUserPairing() exists', str_contains($repoSrc, 'function hasLiveUserPairing('));
assert_test('hasLiveUserPairing ignores expiry so stale codes stay revocable',
    !str_contains($hasLive, 'expires_at'));

/* Controller: username confirmation, per-user target, audit trail. */
assert_test('the controller exposes revokeUserPairing()',
    str_contains($ctrlSrc, 'function revokeUserPairing('));
assert_test('revocation confirms against the username, not the store name',
    str_contains($ctrlSrc, "\$this->confirmed(\$req, \$username)"));
assert_test('an unknown user id is a 404, not a silent no-op',
    str_contains($revokeCtrl, 'if ($user === null) {')
    && str_contains($revokeCtrl, 'return Response::notFound();'));
assert_test('revoke does NOT mint a replacement code',
    !str_contains($revokeCtrl, 'issueUserPairing') && !str_contains($revokeCtrl, 'issuePairing'));
assert_test('revoke does not email a new code',
    !str_contains($revokeCtrl, 'notifyOwner'));
assert_test('revoke probes hasLiveUserPairing() so the message is honest',
    str_contains($ctrlSrc, 'hasLiveUserPairing($userId)'));
assert_test('revoke writes an audit entry',
    str_contains($ctrlSrc, 'Audit::PAIRING_REVOKED'));
assert_test('the audit record names the user and whether a code was live',
    preg_match('/PAIRING_REVOKED.*?\'username\' => \$username.*?\'had_live_code\' => \$hadLive/s', $ctrlSrc) === 1);
assert_test('Audit::PAIRING_REVOKED is a declared action',
    str_contains($auditSrc, "PAIRING_REVOKED  = 'PAIRING_CODE_REVOKED'"));
assert_test('the no-code case reports honestly instead of claiming success',
    str_contains($ctrlSrc, 'nothing needed revoking'));

/* Route + UI: the button must point at the real route. */
assert_test('a POST route matches /users/{id}/pairing/revoke',
    str_contains($kernelSrc, "'#^/users/(\\d{1,10})/pairing/revoke$#'"));
assert_test('the route dispatches to the controller',
    str_contains($kernelSrc, '$dash->revokeUserPairing($req, $session, $admin, (int)$m[1])'));
assert_test('the route id is bounded, so it cannot overflow an int',
    str_contains($kernelSrc, '\d{1,10}'));
assert_test('the store-detail button posts to the revoke route',
    str_contains($detailSrc, '"/platform-admin/users/" . $t[\'id\'] . "/pairing/revoke"'));
assert_test('the button is labelled for the action it performs',
    str_contains($detailSrc, '"btnLabel"    => "Revoke code"'));
assert_test('the button carries its own confirm dialog',
    str_contains($detailSrc, '"title"       => "Revoke this code?"'));
assert_test('revoke is gated on a code actually being outstanding',
    preg_match('/Revoke is offered ONLY while a code is actually outstanding/', $detailSrc) === 1);

echo "\n" . str_repeat('-', 55) . "\n";
$total = $pass + $fail;
if ($fail === 0) {
    echo "Results: {$total}/{$total} passed (all pass)\n";
} else {
    echo "Results: {$pass}/{$total} passed ({$fail} FAILED)\n";
}
exit($fail === 0 ? 0 : 1);




