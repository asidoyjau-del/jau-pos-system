<?php
declare(strict_types=1);

/**
 * Regression tests for the platform-admin icon + copy-to-clipboard UI.
 *
 * Background: View::icon() returns TRUSTED, pre-built <svg> markup, but the
 * views used to wrap every call in $e() (htmlspecialchars). The browser then
 * received &lt;svg … and printed the raw SVG source as visible text all over
 * the login page, the sidebar, the topbar and the store action buttons.
 * These tests lock in "icons render as SVG, user data stays escaped".
 *
 * Run:  php tests/IconAndCopyTest.php
 */

$ROOT = dirname(__DIR__);
define('PROCAST_ROOT', $ROOT);
require $ROOT . '/app/Bootstrap.php';

use ProCast\Support\Crypto;
use ProCast\Support\Env;
use ProCast\Support\View;

$ok = [];
$fail = [];
$check = static function (string $label, bool $cond) use (&$ok, &$fail): void {
    if ($cond) { $ok[] = $label; } else { $fail[] = $label; }
};

/* ── 1. Icons must be markup, not escaped text ───────────────── */
$svg = View::icon('shield');
$check('icon() returns real <svg> markup', str_starts_with($svg, '<svg ') && str_ends_with($svg, '</svg>'));
$check('icon() has a space before class (svgclass bug)', !str_contains($svg, '<svgclass'));
$check('icon() is NOT pre-escaped', !str_contains($svg, '&lt;svg'));
$check('icon() has no &quot; entities', !str_contains($svg, '&quot;'));
$check('icon() inlines the path data', str_contains($svg, '<path d="M12 22s8-4'));
$check('unknown icon falls back to dot', str_contains(View::icon('nope-xyz'), '<circle cx="12" cy="12" r="4"/>'));
$check('icon class arg is escaped', str_contains(View::icon('x', '"><script>'), '&quot;&gt;&lt;script&gt;'));

/* ── 2. Render the real views and inspect the OUTPUT ──────────── */
$rows = [[
    'id' => 7, 'username' => 'cashier1', 'full_name' => 'Ana Reyes', 'role' => 'cashier',
    'email' => 'ana@example.com', 'store_id' => 3, 'last_login' => gmdate('Y-m-d H:i:s', time() - 120),
    'store_name' => 'Main', 'store_status' => 'active', 'client_type' => 'local',
    'display_store_name' => 'Main Branch', 'live_tokens' => 2,
]];
$usersHtml = View::render('users', [
    'rows' => $rows, 'q' => '', 'now' => time(), 'csrf' => 'tok',
], 'layout');

$check('users view emits raw <svg> icons', str_contains($usersHtml, '<svg class="pa-ico'));
$check('users view has NO escaped svg text', !str_contains($usersHtml, '&lt;svg'));
$check('layout nav icons render', substr_count($usersHtml, '<svg class="pa-ico') >= 5);
$T = "\t"; // htmlspecialchars leaves literal tabs alone (no &#9; entity)
$check('per-row copy button present', str_contains($usersHtml, 'data-copy="Ana Reyes' . $T . 'cashier1' . $T . 'ana@example.com"'));
$check('copy-all button present', str_contains($usersHtml, 'Copy all users'));
$check('copy-all has header row', str_contains($usersHtml, 'Name' . $T . 'Username' . $T . 'Email' . $T . 'Role' . $T . 'Store' . $T . 'Client' . $T . 'Last login'));
$check('copy-all includes the row data', str_contains($usersHtml, 'Ana Reyes' . $T . 'cashier1' . $T . 'ana@example.com' . $T . 'cashier' . $T . 'Main Branch' . $T . 'Local POS'));
$check('copy-all maps local client label', str_contains($usersHtml, 'Main Branch' . $T . 'Local POS'));
$check('user email is still displayed', str_contains($usersHtml, 'ana@example.com'));
$check('copy column header added', str_contains($usersHtml, '<th>Copy</th>'));
$check('empty-state colspan is 8', str_contains(View::render('users', ['rows' => [], 'q' => '', 'now' => time(), 'csrf' => 't'], null), 'colspan="8"'));

/* ── 4. store_detail: team copy + lifecycle icons ─────────────── */
$store = [
    'id' => 3, 'name' => 'Main', 'display_name' => '', 'display_owner' => '', 'display_email' => '',
    'owner_name' => 'Owner', 'owner_email' => 'o@e.com', 'contact_phone' => '09', 'address' => 'St',
    'status' => 'active', 'subscription_tier' => 'standard', 'registered_at' => '2026-01-01',
    'created_at' => '2026-01-01', 'approved_at' => '2026-01-02', 'rejection_reason' => '',
    'client_type' => 'local', 'verification_doc' => null,
];
// Far-future expiry: the view compares strtotime(pairing_expires_at) against
// time(), exactly as the on-screen "code sent" badge does, so use a wide margin
// rather than a 1-hour offset that a UTC/local parse skew could flip.
$team = [[
    'id' => 11, 'username' => 'jo', 'full_name' => 'Jo Cruz', 'email' => 'jo@example.com',
    'role' => 'staff', 'pairing_id' => 5, 'pairing_expires_at' => gmdate('Y-m-d H:i:s', time() + 86400 * 7),
]];
$detailHtml = View::render('store_detail', [
    'store' => $store, 'csrf' => 'tok', 'pairings' => [], 'team' => $team, 'userCount' => 1,
], 'layout');

$check('store_detail renders raw svg', str_contains($detailHtml, '<svg class="pa-ico'));
$check('store_detail has no escaped svg', !str_contains($detailHtml, '&lt;svg'));
$check('store_detail has no svgclass bug', !str_contains($detailHtml, '<svgclass'));
$check('team per-user copy details button is removed', !str_contains($detailHtml, 'Copy details'));
$check('team copy-all button', str_contains($detailHtml, 'Name' . $T . 'Username' . $T . 'Email' . $T . 'Role' . $T . 'Activation'));
$check('team copy-all flags issued code', str_contains($detailHtml, 'Jo Cruz' . $T . 'jo' . $T . 'jo@example.com' . $T . 'staff' . $T . 'code issued'));
$check('team email now shown', str_contains($detailHtml, 'jo@example.com'));
$check('lifecycle buttons keep icons', str_contains($detailHtml, 'Suspend Store</button>'));

/* ── 5. stores + login pages ─────────────────────────────────── */
$storesHtml = View::render('stores', [
    'rows' => [[
        'id' => 3, 'name' => 'Main', 'display_name' => '', 'display_owner' => '', 'display_email' => '',
        'contact_phone' => '', 'client_type' => 'local', 'status' => 'active',
        'subscription_tier' => 'standard', 'registered_at' => '2026-08-25', 'created_at' => '2026-08-25',
    ]],
    'status' => 'active', 'q' => '', 'counts' => ['active' => 1], 'csrf' => 'tok',
], 'layout');
$check('stores view has no escaped svg', !str_contains($storesHtml, '&lt;svg'));
$check('stores view has no svgclass bug', !str_contains($storesHtml, '<svgclass'));
$check('stores View button has eye icon', str_contains($storesHtml, '<svg class="pa-ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M1 12s4-8 11-8'));
$check('stores Delete button has trash icon', str_contains($storesHtml, '<svg class="pa-ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><polyline points="3 6 5 6 21 6"/>'));

$loginHtml = View::render('login', ['title' => 'Sign in', 'csrf' => 'tok', 'error' => null, 'bare' => true], null);
$check('login has no escaped svg', !str_contains($loginHtml, '&lt;svg'));
$check('login has no svgclass bug', !str_contains($loginHtml, '<svgclass'));
/* The login page's big decorative mark is the real ProCast logo (<img>) now
   rather than the shield glyph, so "icons reach the browser as SVG" is checked
   against the light/dark toggle's sun/moon instead of the logo. */
$check('login renders real <svg> icons', str_contains($loginHtml, '<svg class="pa-ico"'));
$check('login shows the ProCast brand logo', str_contains($loginHtml, '/platform-admin/assets/procast-logo.png'));

/* ── 6. XSS: user data must STILL be escaped ─────────────────── */
$evil = [[
    'id' => 1, 'username' => '"><img src=x onerror=alert(1)>', 'full_name' => "<script>alert('x')</script>",
    'role' => 'cashier', 'email' => 'a@b.c', 'store_id' => 1, 'last_login' => null,
    'store_name' => '', 'store_status' => '', 'client_type' => 'web',
    'display_store_name' => '', 'live_tokens' => 0,
]];
$xss = View::render('users', ['rows' => $evil, 'q' => '', 'now' => time(), 'csrf' => 't'], 'layout');
$check('script tag in full_name is escaped', !str_contains($xss, "<script>alert('x')</script>"));
$check('img onerror payload is escaped', !str_contains($xss, '<img src=x onerror='));
$check('escaped script still present as text', str_contains($xss, '&lt;script&gt;'));

/* ── 7. Whole-store code card: copy-first, one code for everyone ─ */
/* The code is rendered ON the card (not only in the top banner), so the admin
   can see and copy it from exactly where they generated it. */
$liveRow = [
    'id' => 9, 'created_at' => gmdate('Y-m-d H:i:s', time() - 60),
    'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    'used_at' => null, 'can_reveal' => true, 'code_cipher' => 'v1:xxx',
];
$detailLive = View::render('store_detail', [
    'store' => $store, 'csrf' => 'tok', 'userCount' => 1, 'team' => $team,
    'pairings' => [$liveRow], 'liveCodeText' => '482913',
], 'layout');
$check('legacy store link code card is removed', !str_contains($detailLive, 'Store Link Code'));
$check('legacy store link description is removed', !str_contains($detailLive, 'This code does not unlock accounts.'));
$check('action button has Send code label', str_contains($detailLive, 'Send code'));
$check('action button opens Replace the live code modal', str_contains($detailLive, 'Replace the live code?'));
$check('modal has Replace code button label', str_contains($detailLive, '"btnLabel":"Replace code"'));
$check('modal description mentions replacing code if expired or unused', str_contains($detailLive, 'Replace the live code if the first code expired and is not been use'));
$check('live user action copy code button is removed', !str_contains($detailLive, 'Copy code'));

/* ── 7b. Code column in the team table ────────────────────────── */
$teamTwo = [$team[0], [
    'id' => 12, 'username' => 'mai', 'full_name' => 'Mai Torres', 'email' => 'mai@example.com',
    'role' => 'cashier', 'pairing_id' => null, 'pairing_expires_at' => null,
]];
$tableHtml = View::render('store_detail', [
    'store' => $store, 'csrf' => 'tok', 'userCount' => 2, 'team' => $teamTwo,
    'pairings' => [], 'liveCodeText' => null,
], 'layout');
$check('table has a Code column header', str_contains($tableHtml, '<th>Code</th>'));
$check('Code sits between Status and Action',
    strpos($tableHtml, '<th>Status</th>') < strpos($tableHtml, '<th>Code</th>')
    && strpos($tableHtml, '<th>Code</th>') < strpos($tableHtml, '<th>Action</th>'));
$check('Code cell shows per-person state instead',
    str_contains($tableHtml, 'Code sent') && str_contains($tableHtml, 'Not issued'));
$check('a needs-a-code user is not given someone else\'s code',
    substr_count($tableHtml, 'Not issued') === 1);
$check('needs-a-code user is still called out',
    str_contains($tableHtml, 'Needs a code'));

/* Per-user code with digits available in team row */
$teamWithDigits = [[
    'id' => 15, 'username' => 'c1', 'full_name' => 'Cashier One', 'email' => 'c1@example.com',
    'role' => 'staff', 'pairing_id' => 8, 'pairing_expires_at' => gmdate('Y-m-d H:i:s', time() + 86400 * 7),
    'pairing_code' => '481992', 'pairing_role_name' => 'staff'
]];
$tableDigitsHtml = View::render('store_detail', [
    'store' => $store, 'csrf' => 'tok', 'userCount' => 1, 'team' => $teamWithDigits,
    'pairings' => [], 'liveCodeText' => null,
], 'layout');
$check('Code column displays exact 6-digit code', str_contains($tableDigitsHtml, '481992'));
$check('Code column does not have redundant per-user copy button', !str_contains($tableDigitsHtml, 'data-copy="481992"'));

/* Redeemed/activated user displays Activated status and code */
$teamActivated = [[
    'id' => 16, 'username' => 'admin', 'full_name' => 'Admin User', 'email' => 'admin@example.com',
    'role' => 'owner', 'pairing_id' => 9, 'pairing_expires_at' => gmdate('Y-m-d H:i:s', time() + 86400 * 7),
    'pairing_used_at' => gmdate('Y-m-d H:i:s', time() - 300),
    'pairing_code' => '716253', 'pairing_role_name' => 'owner'
]];
$tableActivatedHtml = View::render('store_detail', [
    'store' => $store, 'csrf' => 'tok', 'userCount' => 1, 'team' => $teamActivated,
    'pairings' => [], 'liveCodeText' => null,
], 'layout');
$check('redeemed user displays Activated badge', str_contains($tableActivatedHtml, 'badge-active">Activated</span>'));
$check('redeemed user displays code in Code column', str_contains($tableActivatedHtml, '716253'));
$check('redeemed user displays Activated label in Code column', str_contains($tableActivatedHtml, '>Activated</div>'));

/* ── 7c. Client-type picker: moved to the TOP of the card ──────── */
/* The dropdown now sits directly under the "How they use ProCast" header — it is
   the control that DRIVES the three explanation tiles below it, so you choose
   first and read the consequence second. The Save button stays at the bottom.
   Because the two are no longer DOM siblings inside one <form>, the <select>
   joins the form by ID via the HTML form="..." attribute. If that id and the
   form= target ever drift apart, the select silently stops being submitted and
   Save re-saves the OLD value with no error anywhere — so pin the pairing, and
   pin the visual order, both together. */
$detailTop = View::render('store_detail', [
    'store' => $store, 'csrf' => 'tok', 'userCount' => 1, 'team' => $team,
    'pairings' => [], 'liveCodeText' => null,
], 'layout');
$check('client-type select sits above the explanation tiles',
    strpos($detailTop, 'id="client-type-select"') < strpos($detailTop, 'class="grid-2"'));
$check('client-type select is above the Save button',
    strpos($detailTop, 'id="client-type-select"') < strpos($detailTop, '>Save</button>'));
$check('select is above the card title? no — title comes first',
    strpos($detailTop, 'How they use ProCast') < strpos($detailTop, 'id="client-type-select"'));
$check('Save button still exists at the bottom', str_contains($detailTop, '>Save</button>'));
$check('form carries the id the select targets', str_contains($detailTop, 'id="client-type-form"'));
$check('select binds to that form via form= attribute',
    (bool)preg_match('/<select[^>]*\bform="client-type-form"/', $detailTop));
/* The csrf token must live inside the form it authenticates — it cannot travel
   with the select, because the select is no longer a child of the form. */
$check('csrf token is inside the POST form',
    (bool)preg_match('#<form[^>]*id="client-type-form".*?name="csrf".*?</form>#s', $detailTop));
$check('form still posts to the client-type route',
    str_contains($detailTop, '/platform-admin/stores/3/client-type'));
$check('only one client-type form exists',
    substr_count($detailTop, 'id="client-type-form"') === 1);
$check('exactly one select for client_type', substr_count($detailTop, 'name="client_type"') === 1);
$check('all three client options still render',
    str_contains($detailTop, 'value="web"') && str_contains($detailTop, 'value="app"')
    && str_contains($detailTop, 'value="local"'));
$check('current client type is preselected', str_contains($detailTop, 'value="local" selected'));

/* ── 7d. Back link needs a real gap above the first card ──────── */
/* The wrapper was margin-bottom:18px around an inline-flex button. An
   inline-level box sits on the text baseline, so the wrapper's height picked up
   baseline slack and the button visually crowded the card below — the 18px was
   there in the markup but not on screen. display:flex makes the wrapper's height
   exactly the button's, and mb-6 (24px) then produces a real, predictable gap
   matching the rhythm between the cards. Pin both halves. */
$detailBack = View::render('store_detail', [
    'store' => $store, 'csrf' => 'tok', 'userCount' => 1, 'team' => $team,
    'pairings' => [], 'liveCodeText' => null,
], 'layout');
$check('back link still present', str_contains($detailBack, 'Back to stores'));
$check('back link still points at the stores list',
    str_contains($detailBack, 'href="/platform-admin/stores"'));
/* NB: the link text is "&larr; Back to stores", so the arrow ENTITY sits between
   the opening tag and the words. These patterns must allow it — an earlier
   version matched "<a ...>Back to stores" and failed on correct markup. */
$check('back-link wrapper is a flex container (kills baseline slack)',
    (bool)preg_match('/<div[^>]*style="display:flex"[^>]*>\s*<a[^>]*>\s*&larr;\s*Back to stores/s', $detailBack));
$check('back-link wrapper carries mb-6',
    (bool)preg_match('/<div class="mb-6"[^>]*>\s*<a[^>]*>\s*&larr;\s*Back to stores/s', $detailBack));
$check('the old inline margin-bottom:18px is gone',
    !str_contains($detailBack, 'style="margin-bottom:18px"'));
/* mb-6 must exist in the stylesheet, or the gap silently becomes zero. */
$adminCss = (string)file_get_contents($ROOT . '/platform-admin/assets/admin.css');
$check('admin.css defines .mb-6 as 24px', (bool)preg_match('/\.mb-6\{margin-bottom:24px\}/', $adminCss));

/* ── 8. The layout must offer Copy on every 6-digit code ─────── */
/* The flash banner is a layout-level concern, so render the monitoring page
   with flashSecret injected directly to prove isCode=true yields the big
   digits plus the Copy button (and that API keys keep their own warning). */
function renderWithFlash(?array $flashSecret): string
{
    return View::render('monitoring', [
        'counts'   => ['total' => 0, 'active' => 0, 'pending_approval' => 0, 'suspended' => 0, 'rejected' => 0],
        'sessions' => 0,   // @var int $sessions (monitoring.php) - StoreRepository::activeSessions() returns int
        'volume'   => ['today' => ['count' => 0, 'total' => 0], 'month' => ['count' => 0, 'total' => 0]],
        'title'    => 't', 'nav' => 'monitoring', 'admin' => ['full_name' => 'A', 'email' => 'a@b.c'],
        'csrf'     => 'tok', 'flash' => null, 'flashSecret' => $flashSecret,
    ], 'layout');
}
$withCode = renderWithFlash(['label' => 'Activation code for ana', 'value' => '482913', 'isCode' => true]);
$check('code flash shows the digits', str_contains($withCode, '482913'));
$check('code flash has a Copy button', str_contains($withCode, 'Copy code'));
$check('code flash has the copy target ids',
    str_contains($withCode, 'id="btn-copy-code"') && str_contains($withCode, 'id="flash-pairing-code"'));
$check('code flash omits the save-this-key warning', !str_contains($withCode, 'it will never be shown again'));

$withKey = renderWithFlash(['label' => 'Production API key (shown once)', 'value' => 'pk_live_abc123']);
$check('non-code secret keeps the save-this-key warning', str_contains($withKey, 'it will never be shown again'));
$check('non-code secret has no pairing copy button', !str_contains($withKey, 'id="btn-copy-code"'));

/* ── 9. Source-level guards for the bugs fixed here ──────────── */
$ctrl = (string)file_get_contents($ROOT . '/app/Controllers/PlatformAdmin/SuperAdminDashboardController.php');
$repo = (string)file_get_contents($ROOT . '/app/Support/StoreRepository.php');
$kern = (string)file_get_contents($ROOT . '/app/PlatformAdmin/Kernel.php');
$detail = (string)file_get_contents($ROOT . '/platform-admin/views/store_detail.php');

/* Regression: invalidatePairings() must run BEFORE issuePairing(). Running it
   after would burn the code it just created, because the UPDATE matches every
   still-unused row for that store — including the brand-new one. */
$invPos = strpos($ctrl, '$this->stores->invalidatePairings($storeId);');
$issPos = strpos($ctrl, '$this->stores->issuePairing($storeId,');
$check('invalidatePairings() runs before issuePairing()', $invPos !== false && $issPos !== false && $invPos < $issPos);

$check('store-scope reveal method exists', str_contains($ctrl, 'public function revealPairing('));
$check('store reveal reads the live cipher', str_contains($ctrl, 'liveStorePairingCipher($storeId'));
$check('store reveal is audited', (bool)preg_match('#function revealPairing\(.*?PAIRING_REVEALED#s', $ctrl));
$check('repo exposes liveStorePairingCipher', str_contains($repo, 'public function liveStorePairingCipher('));
$check('pairingsFor reports can_reveal', str_contains($repo, 'AS can_reveal'));
$check('reveal route is registered', str_contains($kern, '/pairing/reveal'));
$check('reveal route precedes the catch-all store route',
    strpos($kern, 'pairing/reveal') < strpos($kern, '(approve|reject|suspend'));
/* Every flash that carries a 6-digit code must set isCode, or it renders as a
   "never shown again" key block with no way to copy it. There are four:
   issue store-wide, issue per-user, reveal per-user, reveal store-wide. */
$check('all four code flashes set isCode', substr_count($ctrl, "'isCode' => true") >= 4);
/* The two API-key flashes (approve + reactivate) must keep their "shown once"
   treatment and never pick up the pairing-code rendering. */
$check('both API-key flashes exist', substr_count($ctrl, "'value' => \$apiKey]") === 2);
$check('API-key flashes are NOT marked as codes',
    !preg_match('/sa_flash_secret[^;]{0,200}apiKey[^;]{0,200}isCode/s', $ctrl));
$check('email failure tells the admin to copy it themselves', str_contains($ctrl, 'Email FAILED'));
$check('whole-store flash says it does NOT unlock accounts',
    str_contains($ctrl, 'does not unlock accounts'));
$check('storeDetail decrypts the live code for the card', str_contains($ctrl, "'liveCodeText' => \$liveCodeText"));
/* Regression: the controller passed the code under 'liveCode' while the view
   reads $liveCodeText. extract(EXTR_SKIP) plus the view's `?? ''` guard made
   that mismatch SILENT — the card fell back to "it cannot be re-shown" and the
   Code column fell back to "Code issued — use Copy code" even though the
   ciphertext was present and decryptable. Assert the two ends agree, and that
   the stale key name is gone for good. */
$check('store_detail view reads $liveCodeText',
    str_contains($detail, '@var int $userCount @var string|null $liveCodeText'));
$check('storeDetail no longer passes the mismatched liveCode key',
    !preg_match("/'liveCode'\s*=>/", $ctrl));
/* The controller key and the view's @var must not drift apart again. */
$check('controller key matches the view @var name',
    (bool)preg_match("/'liveCodeText'\s*=>/", $ctrl));
$check('pairingsFor selects code_cipher', str_contains($repo, 'used_at, code_cipher'));

/* ── 10. Crypto must accept BOTH key env var names ────────────── */
/* Regression: render.yaml declared PLATFORM_CRYPTO_KEY while Crypto read
   PLATFORM_ENC_KEY. A missing key is handled best-effort, so codes still issued
   and redeemed but code_cipher was always NULL — the admin could never re-copy
   a code and the Copy button never rendered. */
$crypto = (string)file_get_contents($ROOT . '/app/Support/Crypto.php');
$check('Crypto accepts PLATFORM_ENC_KEY', str_contains($crypto, "'PLATFORM_ENC_KEY'"));
$check('Crypto accepts PLATFORM_CRYPTO_KEY too', str_contains($crypto, "'PLATFORM_CRYPTO_KEY'"));
$render = (string)file_get_contents($ROOT . '/render.yaml');
$check('render.yaml declares PLATFORM_ENC_KEY', str_contains($render, 'PLATFORM_ENC_KEY'));
$check('render.yaml no longer declares only the stale name',
    !preg_match('/^\s*- key: PLATFORM_CRYPTO_KEY\s*$/m', $render));

Env::reset();
foreach ([
    ['only ENC', ['PLATFORM_ENC_KEY' => base64_encode(str_repeat('a', 32)), 'PLATFORM_CRYPTO_KEY' => null], true],
    ['only CRYPTO', ['PLATFORM_ENC_KEY' => null, 'PLATFORM_CRYPTO_KEY' => base64_encode(str_repeat('b', 32))], true],
    ['both set', ['PLATFORM_ENC_KEY' => base64_encode(str_repeat('c', 32)), 'PLATFORM_CRYPTO_KEY' => base64_encode(str_repeat('d', 32))], true],
    ['neither', ['PLATFORM_ENC_KEY' => null, 'PLATFORM_CRYPTO_KEY' => null], false],
] as [$label, $vals, $shouldWork]) {
    Env::set('PLATFORM_ENC_KEY', $vals['PLATFORM_ENC_KEY']);
    Env::set('PLATFORM_CRYPTO_KEY', $vals['PLATFORM_CRYPTO_KEY']);
    $worked = true;
    try {
        Crypto::encrypt('482913');
    } catch (\Throwable) {
        $worked = false;
    }
    $check("Crypto works with $label", $worked === $shouldWork);
}

/* Round-trip under the alternate name, so the value is genuinely recoverable. */
Env::set('PLATFORM_ENC_KEY', null);
Env::set('PLATFORM_CRYPTO_KEY', base64_encode(str_repeat('b', 32)));
try {
    $blob = Crypto::encrypt('135790');
    $check('round-trips under the alternate key name', Crypto::decrypt($blob) === '135790');
} catch (\Throwable) {
    $check('round-trips under the alternate key name', false);
}
Env::reset();

/* ── report ──────────────────────────────────────────────────── */
foreach ($ok as $t)   { echo "PASS  $t\n"; }
foreach ($fail as $t) { echo "FAIL  $t\n"; }
echo "\n" . count($ok) . " passed, " . count($fail) . " failed\n";
exit($fail === [] ? 0 : 1);