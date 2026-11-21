<?php
/**
 * SuperAdminSimpleLoginTest.php
 * ==============================
 * In-process regression tests for the PASSWORD-ONLY super admin login.
 *
 * Context
 * -------
 * Login used to be a mandatory two-step dance: password, then a TOTP code
 * from Google Authenticator. That made the owner account configured in Render
 * (SUPERADMIN_EMAIL / SUPERADMIN_PASSWORD) awkward to use, and repeated
 * mistyped passwords could lock it out for 15 minutes with no way in.
 *
 * These tests lock in the new behaviour:
 *   1. SUPERADMIN_REQUIRE_2FA defaults to FALSE (unset) -> simple login.
 *   2. Correct password establishes the session and lands on /monitoring,
 *      with NO /login/verify step and no pending marker in the session.
 *   3. /login/verify is unreachable while 2FA is off.
 *   4. Setting SUPERADMIN_REQUIRE_2FA=true restores the old two-step flow.
 *   5. The Render owner account is exempt from the brute-force lockout;
 *      every other account still gets throttled.
 *
 * Runs entirely in-process against SQLite (no web server, no PostgreSQL):
 *   php tests/SuperAdminSimpleLoginTest.php
 */
declare(strict_types=1);

// Argon2id allocates 64 MB per hash/verify; the throttling loops below run
// several in a single process, so give the CLI a generous ceiling.
ini_set('memory_limit', '1024M');

$root = dirname(__DIR__);
if (!defined('PROCAST_ROOT')) {
    define('PROCAST_ROOT', $root);
}
require_once $root . '/app/Bootstrap.php';

use ProCast\PlatformAdmin\Kernel;
use ProCast\Support\Csrf;
use ProCast\Support\Env;
use ProCast\Support\Request;
use ProCast\Support\Response;

/* -- harness ------------------------------------------------------------- */
$pass = 0;
$fail = 0;

function assert_test(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        echo "  PASS  {$name}\n";
        $pass++;
    } else {
        echo "  FAIL  {$name}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
        $fail++;
    }
}

/** SQLite schema mirroring migrations/001_platform_tables.sql (PostgreSQL in prod). */
function fresh_db(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE platform_super_admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        totp_secret TEXT NULL,
        totp_enrolled INTEGER NOT NULL DEFAULT 0,
        totp_last_step INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT \'active\',
        last_login_at TEXT NULL,
        last_login_ip TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE platform_audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        super_admin_id INTEGER NULL,
        action TEXT NOT NULL,
        target_store_id INTEGER NULL,
        ip_address TEXT NOT NULL,
        user_agent TEXT NOT NULL DEFAULT \'\',
        details_json TEXT NOT NULL DEFAULT \'{}\',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE platform_auth_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        kind TEXT NOT NULL,
        ip TEXT NOT NULL,
        identifier TEXT NOT NULL DEFAULT \'\',
        attempted_at INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE platform_ip_bans (
        ip TEXT PRIMARY KEY,
        banned_until INTEGER NOT NULL,
        created_at INTEGER NOT NULL)');
    return $pdo;
}

/** @param array<string,mixed> $session */
function post(PDO $db, string $path, array $fields, array &$session, string $ip = '203.0.113.9'): Response
{
    $session['sa_csrf'] = Csrf::token($session);
    $fields['csrf'] = $session['sa_csrf'];
    $req = new Request('POST', $path, [], $fields, [], $ip, 'SimpleLoginTest/1.0', true);
    return (new Kernel(static fn (): PDO => $db))->handle($req, $session);
}

/** @param array<string,mixed> $session */
function get(PDO $db, string $path, array &$session, string $ip = '203.0.113.9'): Response
{
    $req = new Request('GET', $path, [], [], [], $ip, 'SimpleLoginTest/1.0', true);
    return (new Kernel(static fn (): PDO => $db))->handle($req, $session);
}

function reset_env(): void
{
    Env::reset();
    Env::set('APP_ENV', 'production');
    Env::set('ENABLE_SUPER_ADMIN', 'true');
    Env::set('SUPERADMIN_EMAIL', 'owner@render.app');
    Env::set('SUPERADMIN_PASSWORD', 'OwnerPassw0rd!');
    Env::set('SUPERADMIN_REQUIRE_2FA', null); // unset => default
    Env::set('PLATFORM_ENC_KEY', base64_encode(str_repeat('k', 32)));
}

echo "\nProCast Platform Admin - Simple (password-only) Login Test Suite\n";
echo str_repeat('=', 62) . "\n\n";

/* -- T1: flag defaults --------------------------------------------------- */
echo "T1: SUPERADMIN_REQUIRE_2FA flag\n";

reset_env();
assert_test('Unset => 2FA not required (simple login by default)', Env::superAdminTwoFactorRequired() === false);

Env::set('SUPERADMIN_REQUIRE_2FA', 'false');
assert_test('"false" => 2FA not required', Env::superAdminTwoFactorRequired() === false);

Env::set('SUPERADMIN_REQUIRE_2FA', 'true');
assert_test('"true" => 2FA required (old behaviour restorable)', Env::superAdminTwoFactorRequired() === true);

reset_env();
assert_test('Env exposes the Render owner email', Env::superAdminEnvEmail() === 'owner@render.app');

/* -- T2: happy path, 2FA off ------------------------------------------- */
echo "\nT2: Correct password signs in directly (2FA off)\n";

$db = fresh_db();
$s  = [];
$r  = post($db, '/login', ['email' => 'owner@render.app', 'password' => 'OwnerPassw0rd!'], $s);

assert_test('Response is a redirect', $r->status === 302, "got {$r->status}");
assert_test('Redirects to /platform-admin/monitoring', ($r->headers['Location'] ?? '') === '/platform-admin/monitoring', $r->headers['Location'] ?? '(none)');
assert_test('Session is authenticated', ($s['super_admin_authenticated'] ?? false) === true);
assert_test('Session records the admin id', ($s['sa_admin_id'] ?? null) === 1, var_export($s['sa_admin_id'] ?? null, true));
assert_test('No pending 2FA marker left behind', !isset($s['sa_pending_id']));
assert_test('No TOTP enrollment secret generated', !isset($s['sa_enroll_secret']));
assert_test('Session id is regenerated on login', $r->regenerateSession === true);

$row = $db->query("SELECT COUNT(*) FROM platform_audit_logs WHERE action = 'LOGIN_SUCCESS'")->fetchColumn();
assert_test('LOGIN_SUCCESS written to the audit trail', (int)$row === 1, "count={$row}");

$last = $db->query('SELECT last_login_at FROM platform_super_admins WHERE id = 1')->fetchColumn();
assert_test('last_login_at recorded', is_string($last) && $last !== '');

/* -- T3: /login/verify is closed while 2FA is off ------------------------ */
echo "\nT3: The second step is unreachable when 2FA is off\n";

$s2 = [];
$rg = get($db, '/login/verify', $s2);
assert_test('GET /login/verify redirects away', $rg->status === 302 && ($rg->headers['Location'] ?? '') === '/platform-admin/login', "{$rg->status} " . ($rg->headers['Location'] ?? ''));
assert_test('GET /login/verify never shows the code form', !str_contains($rg->body, 'totp-form'));

$s2b = [];
$rp  = post($db, '/login/verify', ['code' => '123456'], $s2b);
assert_test('POST /login/verify does not establish a session', ($s2b['super_admin_authenticated'] ?? false) !== true);

/* -- T4: bad password still fails --------------------------------------- */
echo "\nT4: Wrong password is still rejected\n";

reset_env();
$db2 = fresh_db();
$s3  = [];
$rb  = post($db2, '/login', ['email' => 'owner@render.app', 'password' => 'wrong-password'], $s3);

assert_test('Wrong password => HTTP 401', $rb->status === 401, "got {$rb->status}");
assert_test('Wrong password shows an error', str_contains($rb->body, 'Invalid credentials'));
assert_test('Wrong password does NOT authenticate', ($s3['super_admin_authenticated'] ?? false) !== true);

$fails = (int)$db2->query("SELECT COUNT(*) FROM platform_auth_attempts WHERE kind = 'login'")->fetchColumn();
assert_test('Failure recorded for throttling', $fails === 1, "count={$fails}");

/* -- T5: the lockout no longer strands the owner ------------------------ */
echo "\nT5: Render owner account is exempt from the lockout\n";

reset_env();
$db3 = fresh_db();
$s4  = [];

// Six wrong passwords: well past the 5-failure limit.
for ($i = 0; $i < 6; $i++) {
    post($db3, '/login', ['email' => 'owner@render.app', 'password' => 'nope-' . $i], $s4);
}
$banned = (int)$db3->query('SELECT COUNT(*) FROM platform_ip_bans')->fetchColumn();
assert_test('IP did get banned after the failures', $banned === 1, "bans={$banned}");

// ...but the exact Render credentials still get straight in.
$r5 = post($db3, '/login', ['email' => 'owner@render.app', 'password' => 'OwnerPassw0rd!'], $s4);
assert_test('Owner signs in despite the active lockout', $r5->status === 302 && ($r5->headers['Location'] ?? '') === '/platform-admin/monitoring', "got {$r5->status}");
assert_test('Owner session is authenticated', ($s4['super_admin_authenticated'] ?? false) === true);

$left = (int)$db3->query('SELECT COUNT(*) FROM platform_auth_attempts')->fetchColumn();
assert_test('Lockout counter was cleared on success', $left === 0, "remaining={$left}");

/* -- T6: other accounts are still throttled ----------------------------- */
echo "\nT6: Throttling still protects everyone else\n";

reset_env();
$db4 = fresh_db();
$s5  = [];

$rFirst = post($db4, '/login', ['email' => 'someone@else.com', 'password' => 'whatever'], $s5);
assert_test('Unknown account rejected', $rFirst->status === 401, "got {$rFirst->status}");

for ($i = 0; $i < 4; $i++) {
    post($db4, '/login', ['email' => 'someone@else.com', 'password' => 'whatever'], $s5);
}
$rBlocked = post($db4, '/login', ['email' => 'someone@else.com', 'password' => 'whatever'], $s5);
assert_test('Brute force is locked out after 5 failures', $rBlocked->status === 429, "got {$rBlocked->status}");
assert_test('Lockout message is shown', str_contains($rBlocked->body, 'Too many failed attempts'));

/* -- T7: the flag restores the two-step flow ---------------------------- */
echo "\nT7: SUPERADMIN_REQUIRE_2FA=true restores the old flow\n";

reset_env();
Env::set('SUPERADMIN_REQUIRE_2FA', 'true');
$db5 = fresh_db();
$s6  = [];

$r7 = post($db5, '/login', ['email' => 'owner@render.app', 'password' => 'OwnerPassw0rd!'], $s6);
assert_test('Redirects to the verify step', ($r7->headers['Location'] ?? '') === '/platform-admin/login/verify', $r7->headers['Location'] ?? '(none)');
assert_test('NOT authenticated yet (password alone is not enough)', ($s6['super_admin_authenticated'] ?? false) !== true);
assert_test('Pending marker is parked', ($s6['sa_pending_id'] ?? null) === 1);

$r8 = get($db5, '/login/verify', $s6);
assert_test('Verify page renders the code form', str_contains($r8->body, 'totp-form'));

/* -- T8: login screen copy ---------------------------------------------- */
echo "\nT8: Login screen matches the configured flow\n";

reset_env();
$db6 = fresh_db();
$s7  = [];
$off = get($db6, '/login', $s7);
assert_test('2FA off: no "two-factor" hint on the form', !str_contains($off->body, 'Two-factor authentication required'));
assert_test('2FA off: owner email is prefilled', str_contains($off->body, 'value="owner@render.app"'));

Env::set('SUPERADMIN_REQUIRE_2FA', 'true');
$s8 = [];
$on = get($db6, '/login', $s8);
assert_test('2FA on: the hint comes back', str_contains($on->body, 'Two-factor authentication required'));

/* -- T9: session is usable by the guard --------------------------------- */
echo "\nT9: The dashboard is reachable after the simple login\n";

reset_env();
$db7 = fresh_db();
$s9  = [];
post($db7, '/login', ['email' => 'owner@render.app', 'password' => 'OwnerPassw0rd!'], $s9);
assert_test('Session carries the admin id needed by the guard', ($s9['sa_admin_id'] ?? null) === 1);
assert_test('Session is bound to the browser user-agent', ($s9['sa_ua'] ?? '') === hash('sha256', 'SimpleLoginTest/1.0'));

/* -- summary ------------------------------------------------------------ */
$total = $pass + $fail;
echo "\n" . str_repeat('=', 62) . "\n";
echo "Results: {$pass}/{$total} passed\n";
if ($fail > 0) {
    echo "  ({$fail} FAILED)\n";
    exit(1);
}
echo "  (all pass)\n";
exit(0);