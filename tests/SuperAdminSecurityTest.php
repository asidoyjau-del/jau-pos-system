<?php
/**
 * SuperAdminSecurityTest.php
 * ?????????????????????????????????????????????????????????????????????????
 * Run this LOCALLY (XAMPP) or via CLI against a running XAMPP instance to
 * verify the Platform Super Admin module's security posture.
 *
 *   php tests/SuperAdminSecurityTest.php [--base-url http://localhost]
 *
 * All tests use pure PHP (no framework) so nothing extra needs to be installed.
 * ?????????????????????????????????????????????????????????????????????????
 */
declare(strict_types=1);

/* ?? CLI args ??????????????????????????????????????????????????? */
$baseUrl = 'http://localhost';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--base-url=')) {
        $baseUrl = rtrim(substr($arg, 11), '/');
    }
}
$adminBase = $baseUrl . '/platform-admin';

/* ?? Helpers ???????????????????????????????????????????????????? */
$pass = 0; $fail = 0;

function req(string $url, string $method = 'GET', array $headers = [], ?string $body = null): array
{
    $ctx = stream_context_create(['http' => [
        'method'          => $method,
        'header'          => implode("\r\n", $headers),
        'content'         => $body,
        'follow_location' => 0,
        'timeout'         => 6,
        'ignore_errors'   => true,
    ]]);
    $content = @file_get_contents($url, false, $ctx);
    $status  = 0;
    $respHeaders = [];
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) { $status = (int)$m[1]; continue; }
        [$k, $v] = explode(':', $h, 2) + ['', ''];
        $respHeaders[strtolower(trim($k))] = trim($v);
    }
    return ['status' => $status, 'body' => (string)$content, 'headers' => $respHeaders];
}

function assert_test(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        echo "\033[32m  ? PASS\033[0m  {$name}\n";
        $pass++;
    } else {
        echo "\033[31m  ? FAIL\033[0m  {$name}" . ($detail ? " ? {$detail}" : '') . "\n";
        $fail++;
    }
}

/* ???????????????????????????????????????????????????????????????? */
echo "\nProCast Platform Admin ? Security Test Suite\n";
echo str_repeat('?', 60) . "\n";
echo "Target: {$adminBase}\n\n";

/* ?? T1: Local environment fence ??????????????????????????????? */
echo "?? T1: Environment fence (local ? HTTP 404) ??\n";

if (!$serverUp) {
    echo "   (web server not running ? HTTP fence tests skipped; run with XAMPP active to test)\n";
} else {
    $r = req($adminBase . '/');
    $status404 = ($r['status'] === 404);
    assert_test('GET / returns 404 (ENABLE_SUPER_ADMIN not set / false)', $status404, "got HTTP {$r['status']}");
    assert_test('No Set-Cookie header on 404', !isset($r['headers']['set-cookie']), "cookie leaked: " . ($r['headers']['set-cookie'] ?? ''));
    assert_test('Response body is empty on 404', trim($r['body']) === '', "body: " . mb_substr($r['body'], 0, 60));

    $r2 = req($adminBase . '/login');
    assert_test('GET /login also returns 404 locally', $r2['status'] === 404, "got {$r2['status']}");

    $r3 = req($adminBase . '/monitoring');
    assert_test('GET /monitoring also returns 404 locally', $r3['status'] === 404, "got {$r3['status']}");
}

/* ?? T2: Unauthenticated access (if ENABLE_SUPER_ADMIN=true) ??? */
echo "\n?? T2: Unauthenticated access redirects ??\n";
echo "   (skipped locally ? only relevant on production)\n";
echo "   To test on production set env ENABLE_SUPER_ADMIN=true then:\n";
echo "   Expect GET /monitoring ? 302 to /login (not 200 or 500)\n\n";

/* ?? T3: CSRF enforcement ??????????????????????????????????????? */
echo "?? T3: CSRF enforcement ??\n";

if (!$serverUp) { echo "   (skipped ? web server not running)\n"; } else { // POST to login without CSRF token ? should be rejected (403 or redirect)
$r4 = req($adminBase . '/login', 'POST',
    ['Content-Type: application/x-www-form-urlencoded'],
    'email=x%40x.com&password=badpassword'
);
assert_test('POST /login without CSRF token is rejected (?200)', $r4['status'] !== 200, "got {$r4['status']}");

// POST with wrong CSRF token
$r5 = req($adminBase . '/login', 'POST',
    ['Content-Type: application/x-www-form-urlencoded'],
    'email=x%40x.com&password=badpassword&csrf=FAKEFAKE'
);
assert_test('POST /login with fake CSRF token is rejected (!=200)', $r5['status'] !== 200, "got {$r5['status']}"); }

/* ?? T4: SQL injection in search endpoints ?????????????????????? */
echo "\n?? T4: SQL injection (reflected XSS surface) ??\n";

// Unauthenticated so these will 404 / redirect, but bodies must never contain raw SQL error messages.
$payloads = [
    "' OR '1'='1",
    "'; DROP TABLE stores;--",
    "UNION SELECT password_hash FROM platform_super_admins--",
];
foreach ($payloads as $p) {
    $url = $adminBase . '/stores?q=' . urlencode($p);
    $r = req($url);
    $lower = strtolower($r['body']);
    $leaked = str_contains($lower, 'syntax error')
           || str_contains($lower, 'sql')
           || str_contains($lower, 'password_hash')
           || str_contains($lower, 'mysql_fetch')
           || str_contains($lower, 'pg_query');
    assert_test("SQL payload '{$p}' does not leak DB errors", !$leaked, "body snippet: " . mb_substr($r['body'], 0, 100));
}

/* ?? T5: XSS vectors in search params ?????????????????????????? */
echo "\n?? T5: XSS reflection ??\n";

$xssPayloads = [
    '<script>alert(1)</script>',
    '"><img src=x onerror=alert(1)>',
    "';alert(1);//",
];
foreach ($xssPayloads as $p) {
    $url = $adminBase . '/users?q=' . urlencode($p);
    $r = req($url);
    $reflected = str_contains($r['body'], '<script>alert')
              || str_contains($r['body'], 'onerror=alert')
              || str_contains($r['body'], '<img src=x');
    assert_test("XSS payload not reflected raw: " . mb_substr($p, 0, 40), !$reflected, "raw payload in response body");
}

/* ?? T6: Session cookie attributes ????????????????????????????? */
echo "\n?? T6: Security headers (when module is active) ??\n";
// Even on a 404 response, X-Powered-By should be stripped.
$r6 = req($adminBase . '/login');
assert_test('X-Powered-By header not present', !isset($r6['headers']['x-powered-by']), "exposed: " . ($r6['headers']['x-powered-by'] ?? ''));

/* ?? T7: Path traversal on document endpoint ??????????????????? */
echo "\n?? T7: Path traversal protection ??\n";

$traversals = ['../../etc/passwd', '../index.php', '%2e%2e%2fetc%2fpasswd'];
foreach ($traversals as $t) {
    $url = $adminBase . '/stores/1/document?file=' . $t;
    $r = req($url);
    $isPasswd  = str_contains($r['body'], 'root:');
    $isPHPLeak = str_contains($r['body'], '<?php');
    assert_test("Path traversal blocked: {$t}", !$isPasswd && !$isPHPLeak, "got: " . mb_substr($r['body'], 0, 80));
}

/* ?? T8: Guard class unit tests (in-process) ??????????????????? */
echo "\n?? T8: SuperAdminGuard unit tests ??\n";

// Load just the classes needed (no web server required for these).
$root = dirname(__DIR__);
if (!defined('PROCAST_ROOT')) define('PROCAST_ROOT', $root);
if (is_file($root . '/app/Bootstrap.php')) {
    require_once $root . '/app/Bootstrap.php';
} else {
    // Minimal autoload without Bootstrap
    spl_autoload_register(static function(string $class) use ($root): void {
        $rel = str_replace(['ProCast\\', '\\'], ['', '/'], $class) . '.php';
        $path = $root . '/app/' . $rel;
        if (is_file($path)) require_once $path;
    });
}

use ProCast\Middleware\SuperAdminGuard;

// Check 2: no session
$session = [];
$g = new SuperAdminGuard(null);
assert_test('Guard denies empty session', $g->check($session, '127.0.0.1', 'UA', time()) === 'no_session');

// Establish a session then check it passes
SuperAdminGuard::establish($session, 42, '192.168.1.100', 'TestBrowser/1.0', time());

// Check 3: UA mismatch
$g2 = new SuperAdminGuard(null);
$result = $g2->check($session, '192.168.1.100', 'DifferentBrowser/2.0', time());
assert_test('Guard detects UA mismatch', $result === 'ua_mismatch', "got: {$result}");

// Establish fresh session for IP mismatch test
$s2 = [];
SuperAdminGuard::establish($s2, 42, '10.0.0.1', 'TestBrowser/1.0', time());
$result2 = $g2->check($s2, '1.2.3.4', 'TestBrowser/1.0', time());
assert_test('Guard detects IP network mismatch', $result2 === 'ip_mismatch', "got: {$result2}");

// Idle timeout
$s3 = [];
$oldTime = time() - SuperAdminGuard::IDLE_TTL - 10;
SuperAdminGuard::establish($s3, 42, '127.0.0.1', 'UA', $oldTime);
$s3['sa_last'] = $oldTime;
$result3 = $g2->check($s3, '127.0.0.1', 'UA', time());
assert_test('Guard enforces idle timeout', $result3 === 'idle', "got: {$result3}");

// Absolute timeout
$s4 = [];
$veryOld = time() - SuperAdminGuard::ABSOLUTE_TTL - 10;
SuperAdminGuard::establish($s4, 42, '127.0.0.1', 'UA', $veryOld);
$s4['sa_created'] = $veryOld;
$s4['sa_last'] = time();
$result4 = $g2->check($s4, '127.0.0.1', 'UA', time());
assert_test('Guard enforces absolute timeout', $result4 === 'expired', "got: {$result4}");

// CSRF verify
$s5 = [];
SuperAdminGuard::establish($s5, 42, '127.0.0.1', 'UA', time());
$token = \ProCast\Support\Csrf::token($s5);
assert_test('CSRF token verifies correctly', SuperAdminGuard::verifyCsrf($s5, $token));
assert_test('CSRF token rejects wrong value', !SuperAdminGuard::verifyCsrf($s5, 'wrongtoken'));
assert_test('CSRF token rejects empty string', !SuperAdminGuard::verifyCsrf($s5, ''));

// IP binding
assert_test('IPv4 /24 binding works', SuperAdminGuard::bindIp('192.168.1.100') === '192.168.1.0/24');
assert_test('Different /24 does not match', SuperAdminGuard::bindIp('192.168.2.100') !== SuperAdminGuard::bindIp('192.168.1.100'));

/* ?? T9: Privilege isolation (cookie name) ?????????????????????? */
echo "\n?? T9: Session namespace isolation ??\n";
assert_test(
    'Super admin cookie name is PROCAST_SUPER_SESS (not PHPSESSID)',
    SuperAdminGuard::SESSION_NAME === 'PROCAST_SUPER_SESS'
);

/* ?? Summary ???????????????????????????????????????????????????? */
$total = $pass + $fail;
echo "\n" . str_repeat('?', 60) . "\n";
echo "Results: {$pass}/{$total} passed";
if ($fail > 0) {
    echo "  \033[31m({$fail} FAILED)\033[0m\n";
    exit(1);
} else {
    echo "  \033[32m(all pass)\033[0m\n";
    exit(0);
}
