<?php
declare(strict_types=1);

/**
 * PlatformAdminThemeTest.php
 * ==========================
 * Regression tests for the super admin portal's branding and light/dark theme.
 *
 * Background — three separate things used to be broken or missing:
 *
 *  1. NO BRAND LOGO. The sidebar and the sign-in page drew a generic "shield"
 *     glyph from View::ICONS instead of the actual ProCast mark, so the platform
 *     operator's screen did not look like the product.
 *
 *  2. DARK-ONLY STYLESHEET. admin.css hardcoded one navy palette in `:root` and
 *     baked the translucent surfaces (hero scrim, frosted login box, topbar,
 *     ghost buttons, form fields) into individual rules as literal rgba()
 *     literals. Swapping the palette variables alone would have left a dark
 *     scrim stretched over a white page.
 *
 *  3. HYPHENATED ASSETS 404'd. platform-admin/.htaccess funnels EVERY request
 *     under /platform-admin/ into index.php, so the Kernel's ASSETS whitelist is
 *     the only way to serve an admin asset — but assetName() matched the name
 *     against `[a-z0-9.]`, which rejects '-'. pos-background.webp was listed in
 *     the whitelist and still unreachable. The logo has the same problem.
 *
 * The theme bootstrap has to be an EXTERNAL file: the portal CSP is
 * `script-src 'self'` with no 'unsafe-inline' and no nonce, so the usual inline
 * pre-paint <script> would be blocked by the browser outright. That constraint is
 * asserted here too, so nobody "simplifies" it back into a <head> inline script.
 *
 * Run WITHOUT a web server:
 *
 *   php tests/PlatformAdminThemeTest.php
 */
if (!defined('PROCAST_ROOT')) {
    define('PROCAST_ROOT', dirname(__DIR__));
}
require_once PROCAST_ROOT . '/app/Bootstrap.php';

use ProCast\PlatformAdmin\Kernel;
use ProCast\Support\Request;
use ProCast\Support\View;

$pass = 0;
$fail = 0;

function t(string $name, bool $ok, string $detail = ''): void
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

/** Kernel whose DB factory must never be touched for asset routes. */
function themeKernel(): Kernel
{
    return new Kernel(static function (): PDO {
        throw new RuntimeException('DB must not be opened for asset requests');
    });
}

function themeGet(string $path): Request
{
    return new Request('GET', $path, [], [], [], '127.0.0.1', 'TestBrowser/1.0', true);
}

echo "\nPlatform Admin — Brand + Light/Dark Theme Test Suite\n";
echo str_repeat('═', 55) . "\n";

$css    = (string)file_get_contents(PROCAST_ROOT . '/platform-admin/assets/admin.css');
$js     = (string)file_get_contents(PROCAST_ROOT . '/platform-admin/assets/theme.js');
$layout = (string)file_get_contents(PROCAST_ROOT . '/platform-admin/views/layout.php');
$login  = (string)file_get_contents(PROCAST_ROOT . '/platform-admin/views/login.php');
$guard  = (string)file_get_contents(PROCAST_ROOT . '/app/Middleware/SuperAdminGuard.php');

/* ── T1: the logo asset exists and is the real ProCast mark ─────────── */
echo "\n📋 T1: Brand logo asset\n";

$logo = PROCAST_ROOT . '/platform-admin/assets/procast-logo.png';
t('platform-admin/assets/procast-logo.png exists', is_file($logo));

$src = PROCAST_ROOT . '/assets/default-logo.png';
t('source logo (used by the POS) still exists', is_file($src));
t(
    'the portal logo is byte-identical to the POS logo',
    is_file($logo) && is_file($src) && hash_file('sha256', $logo) === hash_file('sha256', $src)
);
t('it is a real PNG (not a placeholder)', (function (): bool {
    $h = file_get_contents(PROCAST_ROOT . '/platform-admin/assets/procast-logo.png', false, null, 0, 8);
    return $h === "\x89PNG\r\n\x1a\n";
})());

/* ── T2: the Kernel serves every whitelisted asset, hyphens included ── */
echo "\n📋 T2: Kernel asset whitelist (hyphen regression)\n";

$session = [];
$kernel = themeKernel();

foreach ([
    'admin.css'           => 'text/css; charset=utf-8',
    'admin.js'            => 'application/javascript; charset=utf-8',
    'qrcode.min.js'       => 'application/javascript; charset=utf-8',
    'theme.js'            => 'application/javascript; charset=utf-8',
    'procast-logo.png'    => 'image/png',
    'pos-background.webp' => 'image/webp',
] as $asset => $type) {
    $req = themeGet('/assets/' . $asset);
    $r = $kernel->handle($req, $session);
    t("GET /assets/{$asset} → 200 {$type}",
        $r->status === 200 && ($r->headers['Content-Type'] ?? '') === $type,
        'status ' . $r->status . ' type ' . ($r->headers['Content-Type'] ?? 'none'));
    t("  {$asset} resolves to a real file on disk", $r->filePath !== null && is_file($r->filePath));
}

/* The original defect: a hyphenated name was whitelisted but the matcher
   rejected it, so the hero background silently 404'd. */
t('hyphenated asset names are matched (pos-background regression)',
    (bool)preg_match('#^/assets/([a-z0-9.-]+)$#', '/assets/pos-background.webp', $m) && $m[1] === 'pos-background.webp');
t('a logo filename with a hyphen is matched too',
    (bool)preg_match('#^/assets/([a-z0-9.-]+)$#', '/assets/procast-logo.png', $m) && $m[1] === 'procast-logo.png');

/* The widened character class must NOT have opened a path traversal hole. */
foreach ([
    '/assets/../views/totp.php',
    '/assets/..%2Fviews%2Ftotp.php',
    '/assets/sub/dir.png',
    '/assets/UPPER.PNG',
    '/assets/secret.txt',
] as $bad) {
    $r = $kernel->handle(themeGet($bad), $session);
    t("rejected: {$bad}", $r->filePath === null && $r->status !== 200, 'status ' . $r->status);
}
/* ── T3: the theme is applied before paint, without tripping the CSP ── */
echo "\n📋 T3: CSP-safe pre-paint theme bootstrap\n";

t('theme.js exists and is non-empty', $js !== '');
t('CSP really is script-src self with no unsafe-inline (the constraint that forces an external file)',
    str_contains($guard, "script-src 'self'")
    && !str_contains($guard, "'unsafe-inline'")
    && !str_contains($guard, 'nonce-'));
t('layout loads theme.js from same-origin before admin.css',
    strpos($layout, 'src="/platform-admin/assets/theme.js"') !== false
    && strpos($layout, 'src="/platform-admin/assets/theme.js"') < strpos($layout, 'href="/platform-admin/assets/admin.css"'));
t('login loads theme.js before admin.css too',
    strpos($login, 'src="/platform-admin/assets/theme.js"') !== false
    && strpos($login, 'src="/platform-admin/assets/theme.js"') < strpos($login, 'href="/platform-admin/assets/admin.css"'));
t('NO inline <script> block was introduced (CSP would block it)',
    !preg_match('#<script(?![^>]*\bsrc=)[^>]*>#i', $layout)
    && !preg_match('#<script(?![^>]*\bsrc=)[^>]*>#i', $login));
t('theme.js toggles the same class the POS uses (theme-light)',
    str_contains($js, "classList.toggle('theme-light'") && str_contains($css, 'html.theme-light'));
t('theme.js persists the choice in localStorage',
    str_contains($js, 'localStorage.setItem') && str_contains($js, 'pa_theme'));
t('theme.js exposes a toggle for the button to call',
    str_contains($js, 'window.paToggleTheme') && str_contains($js, 'window.paApplyTheme'));
t('theme.js binds every [data-theme-toggle] control',
    str_contains($js, '[data-theme-toggle]') && str_contains($js, "addEventListener('click'"));
t('theme.js has no eval / new Function (CSP script-src self)',
    !str_contains($js, 'eval(') && !str_contains($js, 'new Function'));
t('dark stays the default when nothing is stored',
    str_contains($js, "stored() === 'light'"));

/* ── T4: a working light theme ─────────────────────────────────────── */
echo "\n📋 T4: Light palette\n";

t('admin.css defines html.theme-light', str_contains($css, 'html.theme-light'));
t('light mode declares colour-scheme: light', (bool)preg_match('/html\.theme-light\s*\{[^}]*color-scheme:\s*light/', $css));

preg_match('/html\.theme-light\s*\{([^}]*)\}/', $css, $lightBlock);
foreach ([
    '--bg-base', '--bg-card', '--bg-sidebar', '--border',
    '--text-1', '--text-2', '--accent', '--accent-h',
    '--amber', '--emerald', '--rose',
] as $token) {
    t("light mode redefines {$token}", str_contains($lightBlock[1] ?? '', $token . ':'));
}

/* Every translucent surface must be a variable, not a literal, or light mode
   would keep dark-mode scrims and frosted panels. */
foreach ([
    'hero scrim'   => 'linear-gradient(var(--veil-a),var(--veil-b))',
    'login panel'  => 'background:var(--panel)',
    'topbar'       => 'background:var(--topbar-bg)',
    'ghost button' => 'background:var(--ghost-bg)',
    'form field'   => 'background:var(--input-bg)',
] as $what => $expected) {
    t("{$what} is driven by a theme variable", str_contains($css, $expected));
}
t('no dark literal left in the hero scrim',
    !str_contains($css, 'linear-gradient(rgba(10,22,40'));
t('no dark literal left on the login box',
    !str_contains($css, 'background:rgba(17,24,39'));

/* Dark mode must look exactly as it did before the refactor. */
t('dark defaults are preserved (ghost button)',
    str_contains($css, '--ghost-bg:   rgba(255,255,255,.06)'));
t('dark defaults are preserved (form field)',
    str_contains($css, '--input-bg:   rgba(255,255,255,.05)'));

/* Contrast: the dark-mode status hues are too pale to be text on white, so
   light mode has to re-tune them rather than inherit them. */
t('light mode darkens --accent-h below the dark value (link contrast)',
    (bool)preg_match('/html\.theme-light\s*\{[^}]*--accent-h:\s*#([0-9a-f]{6})/i', $css, $m)
    && hexdec($m[1]) < hexdec('4da3ff'));
/* ── T5: the toggle button ─────────────────────────────────────────── */
echo "\n📋 T5: Toggle button wiring\n";

t('View::icon knows the sun icon', str_contains(View::icon('sun'), '<svg '));
t('View::icon knows the moon icon', str_contains(View::icon('moon'), '<svg '));

foreach (['layout' => $layout, 'login' => $login] as $name => $srcView) {
    t("{$name} renders a toggle marked data-theme-toggle", str_contains($srcView, 'data-theme-toggle'));
    t("{$name} ships BOTH icons so CSS alone can switch them",
        str_contains($srcView, 'pa-theme-icon is-light') && str_contains($srcView, 'pa-theme-icon is-dark'));
    t("{$name} toggle has an accessible name + aria-pressed",
        str_contains($srcView, 'aria-label="Switch to light mode"') && str_contains($srcView, 'aria-pressed='));
    // Must NOT sit inside a <form>. A plain `#<form.*data-theme-toggle#s` test is
    // wrong here: the layout legitimately contains the sign-out form BEFORE the
    // topbar, so a greedy match would flag a toggle that is in fact nowhere near
    // it. Match each complete <form>…</form> span and assert none contains one.
    preg_match_all('#<form\b[\s\S]*?</form>#', $srcView, $forms);
    $inside = false;
    foreach ($forms[0] as $form) {
        if (str_contains($form, 'data-theme-toggle')) {
            $inside = true;
        }
    }
    t("{$name} toggle is not nested inside a form", $inside === false);
}

t('CSS shows the sun in dark mode and the moon in light mode',
    str_contains($css, 'html:not(.theme-light) .pa-theme-toggle .pa-theme-icon.is-light')
    && str_contains($css, 'html.theme-light     .pa-theme-toggle .pa-theme-icon.is-dark'));
t('both theme icons start hidden so only one is ever visible',
    str_contains($css, '.pa-theme-icon{display:none;'));

/* ── T6: the brand logo is actually rendered ───────────────────────── */
echo "\n📋 T6: Logo rendered in the views\n";

t('sidebar renders the ProCast logo', str_contains($layout, 'class="pa-logo-img"'));
t('sidebar no longer uses the generic shield as its brand mark',
    !str_contains($layout, "ico('shield'"));
t('login renders the ProCast logo', str_contains($login, 'class="pa-login-logo-img"'));
t('login no longer uses the generic shield as its brand mark',
    !str_contains($login, "ico('shield'"));
t('both views use the Kernel-served logo path',
    !str_contains($layout, 'assets/default-logo.png')
    && !str_contains($login, 'assets/default-logo.png'));
t('login logo has descriptive alt text',
    (bool)preg_match('/class="pa-login-logo-img"[\s\S]*?alt="ProCast POS System"/', $login));
t('sidebar logo is decorative (alt="" — the wordmark sits beside it)',
    (bool)preg_match('/class="pa-logo-img"[\s\S]*?alt=""/', $layout));
t('both views set the ProCast favicon',
    str_contains($layout, 'rel="icon" href="/platform-admin/assets/procast-logo.png"')
    && str_contains($login, 'rel="icon" href="/platform-admin/assets/procast-logo.png"'));
t('logo CSS makes it circular', str_contains($css, '.pa-logo-img{') && str_contains($css, 'border-radius:50%'));

/* ── T7: rendered pages are still well-formed ──────────────────────── */
echo "\n📋 T7: Rendered output\n";

$page = View::render('users', [
    'rows' => [[
        'id' => 1, 'username' => 'ana', 'full_name' => 'Ana Reyes', 'role' => 'cashier',
        'email' => 'ana@example.com', 'store_id' => 3, 'last_login' => '2026-01-01 00:00:00',
        'store_name' => 'Main', 'store_status' => 'active', 'client_type' => 'local',
        'display_store_name' => 'Main Branch', 'live_tokens' => 0,
    ]],
    'q' => '', 'now' => time(), 'csrf' => 'tok',
], 'layout');

t('rendered layout shows the logo', str_contains($page, 'procast-logo.png'));
t('rendered layout shows the toggle', str_contains($page, 'data-theme-toggle'));
t('rendered layout has no escaped SVG', !str_contains($page, '&lt;svg'));

$loginHtml = View::render('login', [
    'title' => 'Sign in', 'csrf' => 'tok', 'error' => null, 'bare' => true,
], null);

t('rendered login shows the logo', str_contains($loginHtml, 'procast-logo.png'));
t('rendered login shows the toggle', str_contains($loginHtml, 'data-theme-toggle'));
t('rendered login has no escaped SVG', !str_contains($loginHtml, '&lt;svg'));
t('rendered login still contains the credentials form', str_contains($loginHtml, 'id="login-form"'));

/* ── T8: the real /login route emits ONE document, not two ───────────── */
echo "\n📋 T8: Real /login route (single document)\n";

/* T7 above calls View::render('login', …, null). That mirrors the INTENT but
   not what the application actually did: in production the sign-in page goes
   through Kernel::showLogin(), which called View::render() with no third
   argument, so $layout fell back to its 'layout' default. views/login.php is
   already a complete <!DOCTYPE>…</html> document, so the layout wrapped it in a
   SECOND one and every <head> asset was emitted twice. Two copies of theme.js
   each bound their own click listener, so one click of the toggle flipped the
   theme light and immediately back to dark — the button looked completely dead.
   Renders that bypass the routing layer therefore cannot catch it, so the body
   checked here is the actual response of GET /platform-admin/login. */
$sess = [];
$loginResp = themeKernel()->handle(themeGet('/login'), $sess);
$liveLogin = $loginResp->body;
$count = static fn (string $needle): int => substr_count($liveLogin, $needle);

t('GET /login answers 200', $loginResp->status === 200, "got {$loginResp->status}");
t('login emits exactly ONE doctype (layout did not wrap the page)',
    $count('<!DOCTYPE') === 1, $count('<!DOCTYPE') . ' found');
t('login emits exactly ONE <html> element',
    $count('<html') === 1, $count('<html') . ' found');
t('theme.js is referenced exactly once (a 2nd copy rebinds the toggle)',
    $count('<script src="/platform-admin/assets/theme.js"') === 1,
    $count('<script src="/platform-admin/assets/theme.js"') . ' found');
t('the stylesheet is linked exactly once',
    $count('<link rel="stylesheet"') === 1, $count('<link rel="stylesheet"') . ' found');
t('the favicon is declared exactly once',
    $count('rel="icon"') === 1, $count('rel="icon"') . ' found');
t('theme.js still loads BEFORE the stylesheet (pre-paint, no flash)',
    $liveLogin !== '' && strpos($liveLogin, 'theme.js') < strpos($liveLogin, 'admin.css'));
t('live login page still carries the form and the toggle',
    str_contains($liveLogin, 'id="login-form"') && str_contains($liveLogin, 'data-theme-toggle'));
t('live login page carries no inline <script> (CSP script-src self)',
    !preg_match('/<script(?![^>]*\bsrc=)/', $liveLogin));

/* ── Summary ───────────────────────────────────────────────────────── */
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