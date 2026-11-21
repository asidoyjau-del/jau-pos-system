<?php
/**
 * TotpQrAssetTest.php
 * =====================
 * Regression tests for the locally-bundled QR code library used by the
 * platform-admin 2FA enrollment page (views/totp.php).
 *
 * Background: the page used to load qrcodejs from cdnjs.cloudflare.com.
 * The platform is protected by a strict Content-Security-Policy
 * (script-src 'self'), which blocks third-party scripts entirely — and
 * offline installs cannot reach the CDN anyway. The library is now served
 * from /platform-admin/assets/qrcode.min.js through the Kernel's asset
 * whitelist. These tests lock that behaviour in.
 *
 * Run WITHOUT a web server:
 *
 *   php tests/TotpQrAssetTest.php
 */
declare(strict_types=1);

if (!defined('PROCAST_ROOT')) {
    define('PROCAST_ROOT', dirname(__DIR__));
}
require_once PROCAST_ROOT . '/app/Bootstrap.php';

use ProCast\PlatformAdmin\Kernel;
use ProCast\Support\Request;

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

/** Kernel with a DB factory that must never be called for asset routes. */
function makeKernel(): Kernel
{
    return new Kernel(static function (): PDO {
        throw new RuntimeException('DB must not be opened for asset requests');
    });
}

function get(string $path): Request
{
    return new Request('GET', $path, [], [], [], '127.0.0.1', 'TestBrowser/1.0', true);
}

echo "\nQR Code Asset (offline 2FA enrollment) Test Suite\n";
echo str_repeat('═', 55) . "\n";

/* ── T1: library file present + integrity ─────────────────────── */
echo "\n📋 T1: Bundled qrcode.min.js file\n";

$lib = PROCAST_ROOT . '/platform-admin/assets/qrcode.min.js';
assert_test('platform-admin/assets/qrcode.min.js exists', is_file($lib));

$bytes = is_file($lib) ? (string)file_get_contents($lib) : '';
assert_test('File is not empty (> 10 KB)', strlen($bytes) > 10240, 'size: ' . strlen($bytes));
assert_test('Exposes global QRCode (starts with "var QRCode")', strncmp(ltrim($bytes), 'var QRCode', 10) === 0);

// Integrity: verify the bundled copy against the OFFICIAL cdnjs SRI hash for
// qrcodejs 1.0.0 (confirmed via https://api.cdnjs.com/libraries/qrcodejs/1.0.0).
// Matching it proves the bundled copy is the exact, unmodified upstream file.
$sri = 'sha512-' . base64_encode(hash('sha512', $bytes, true));
$officialSri = 'sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==';
assert_test('SHA-512 matches official cdnjs SRI hash for qrcodejs 1.0.0', $sri === $officialSri, "got {$sri}");

/* ── T2: Kernel serves the asset (whitelist + same-origin) ────── */
echo "\n📋 T2: Kernel asset route\n";

$kernel = makeKernel();
$req = get('/assets/qrcode.min.js');

assert_test('Asset route needs no session (no Set-Cookie for static files)', $kernel->needsSession($req) === false);

$session = [];
$resp = $kernel->handle($req, $session);
assert_test('GET /assets/qrcode.min.js → 200', $resp->status === 200, "got {$resp->status}");
assert_test('Content-Type is application/javascript',
    ($resp->headers['Content-Type'] ?? '') === 'application/javascript; charset=utf-8',
    'got ' . ($resp->headers['Content-Type'] ?? 'none'));
assert_test('filePath points at the bundled library',
    $resp->filePath !== null && realpath($resp->filePath) === realpath($lib),
    'got ' . ($resp->filePath ?? 'null'));

// Existing assets must keep working
foreach (['admin.css' => 'text/css; charset=utf-8', 'admin.js' => 'application/javascript; charset=utf-8'] as $asset => $type) {
    $r = makeKernel()->handle(get('/assets/' . $asset), $session);
    assert_test("GET /assets/{$asset} still → 200 {$type}",
        $r->status === 200 && ($r->headers['Content-Type'] ?? '') === $type);
}

// Traversal attempts: Request::fromGlobals nulls any path containing "..",
// so the route answers a bare 404 before the guard even runs.
$trav = Request::fromGlobals([
    'REQUEST_URI'    => '/platform-admin/assets/%2e%2e/views/totp.php',
    'REQUEST_METHOD' => 'GET',
    'REMOTE_ADDR'    => '127.0.0.1',
], [], [], []);
$r = makeKernel()->handle($trav, $session);
assert_test('Encoded traversal asset path → bare 404', $r->status === 404, "got {$r->status}");

// Unlisted asset names are never served (the kernel whitelist is the only way).
$r = makeKernel()->handle(get('/assets/secret.txt'), $session);
assert_test('Unlisted asset name is not served (no filePath, not 200)',
    $r->filePath === null && $r->status !== 200, "status {$r->status}");

/* ── T3: totp.php uses the local library, never the CDN ───────── */
echo "\n📋 T3: totp.php script reference\n";

$view = (string)file_get_contents(PROCAST_ROOT . '/platform-admin/views/totp.php');
assert_test('View references /platform-admin/assets/qrcode.min.js',
    strpos($view, 'src="/platform-admin/assets/qrcode.min.js"') !== false);
assert_test('View no longer references cdnjs.cloudflare.com',
    strpos($view, 'cdnjs.cloudflare.com') === false);
assert_test('View does not contain any other third-party script host',
    preg_match('#<script[^>]+src="https?://#', $view) !== 1);

/* ── T4: CSP rationale (platform blocks third-party scripts) ──── */
echo "\n📋 T4: Security headers still enforce CSP\n";

$resp = makeKernel()->handle(get('/assets/qrcode.min.js'), $session);
$csp = $resp->headers['Content-Security-Policy'] ?? '';
assert_test('CSP sent on asset responses', $csp !== '');
assert_test("CSP keeps script-src 'self' (hence: local hosting required)",
    strpos($csp, "script-src 'self'") !== false, "got {$csp}");
assert_test('CSP allows data: URIs for images (QR canvas → <img> path)',
    strpos($csp, 'img-src') !== false && strpos($csp, 'data:') !== false);

/* ── T5: manual-key copy button + QR double-render hardening ──── */
echo "\n📋 T5: Manual key copy + QR double-render guard\n";

assert_test('View exposes the manual key (id="totp-secret")', strpos($view, 'id="totp-secret"') !== false);
assert_test('View has the Copy button (id="btn-copy-secret")', strpos($view, 'id="btn-copy-secret"') !== false);
assert_test('Old collapsed <details> manual block removed from view', strpos($view, '<details') === false);

$adminJs = (string)file_get_contents(PROCAST_ROOT . '/platform-admin/assets/admin.js');
assert_test('admin.js guards against double QR render (dataset.qrInit)', strpos($adminJs, 'dataset.qrInit') !== false);
assert_test('admin.js clears the QR box before drawing', strpos($adminJs, "box.innerHTML = ''") !== false);
assert_test('admin.js binds the copy button (btn-copy-secret)', strpos($adminJs, 'btn-copy-secret') !== false);
assert_test('admin.js copies via clipboard API with legacy fallback',
    strpos($adminJs, 'navigator.clipboard') !== false && strpos($adminJs, 'execCommand') !== false);

$adminCss = (string)file_get_contents(PROCAST_ROOT . '/platform-admin/assets/admin.css');
assert_test('admin.css centres QRs inside the box (.qr-box img rule)', strpos($adminCss, '.qr-box img') !== false);
assert_test('admin.css styles the secret row (.secret-row rule)', strpos($adminCss, '.secret-row') !== false);
assert_test('admin.css styles the copy button (.copy-btn rule)', strpos($adminCss, '.copy-btn') !== false);

/* ── Summary ───────────────────────────────────────────────────── */
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
