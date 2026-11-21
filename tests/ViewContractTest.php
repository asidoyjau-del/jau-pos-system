<?php
declare(strict_types=1);

/**
 * Generic view/controller contract test.
 *
 * The bug this locks down: SuperAdminDashboardController::storeDetail() passed
 * the decrypted 6-digit code under the key 'liveCode' while store_detail.php
 * read $liveCodeText. Because View::include() binds with extract(EXTR_SKIP)
 * and every view guards with `?? ''`, that mismatch failed SILENTLY — the page
 * rendered "cannot be re-shown" / "Code issued" while the ciphertext sat in the
 * database, fully decryptable. The digits were discarded one line above the HTML.
 *
 * A hardcoded assertion on one variable name only catches that one rename. This
 * test instead DERIVES the contract:
 *
 *   1. parse each view's leading `/** @var ... $name *\/` docblock to learn
 *      which variables that view declares it needs;
 *   2. parse every controller render site to learn which keys it passes;
 *   3. assert declared-needs is a subset of keys-passed.
 *
 * So a new view, a renamed variable, or a new team member is covered
 * automatically — adding a cashier cannot silently break the page, and nobody
 * has to remember to update a list of names.
 *
 * Run:  php tests/ViewContractTest.php
 */

$ROOT = dirname(__DIR__);
define('PROCAST_ROOT', $ROOT);
require $ROOT . '/app/Bootstrap.php';

$ok = [];
$fail = [];
$check = static function (string $label, bool $cond) use (&$ok, &$fail): void {
    if ($cond) { $ok[] = $label; } else { $fail[] = $label; }
};

/**
 * Variables a view declares it needs, from its leading @var docblock.
 *
 * @return list<string>
 */
function view_declared_vars(string $file): array
{
    $src = (string)file_get_contents($file);
    if (!preg_match('#/\*\*(.*?)\*/#s', $src, $m)) {
        return [];
    }
    preg_match_all('/@var\s+\S+(?:\|null)?\s+\$(\w+)/', $m[1], $found);
    return array_values(array_unique($found[1]));
}

/**
 * Keys of the $vars array passed for one view, across a controller source.
 *
 * Uses PHP's own tokenizer rather than regex/strpos heuristics: this has to tell
 * a real array literal ('key' => ...) apart from a string SUBSCRIPT such as
 * $store['name'], which also contains [ 'name' ]. The discriminator is the
 * previous significant token — an array literal opens after '(' , ',' or '=',
 * whereas a subscript opens after a variable. Guessing with a regex got this
 * wrong and made the whole check pass vacuously.
 *
 * @return list<string>
 */
function keys_passed_for(string $src, string $view): array
{
    $tokens = token_get_all($src);
    $needle = "'" . $view . "'";
    $keys   = [];

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        if (trim($tokens[$i][1], "'\"") !== $view) {
            continue;
        }

        // Walk forward to the first '[' that begins an ARRAY LITERAL. The scan is
        // bounded by the end of the statement so an unrelated array later in the
        // file can never be mistaken for this call's $vars. A '[' whose previous
        // significant token is a variable is a SUBSCRIPT ($store['name']) and is
        // skipped rather than matched — that distinction is exactly what a plain
        // regex cannot make, and getting it wrong made this check pass vacuously.
        for ($j = $i + 1; $j < $n; $j++) {
            $tj = $tokens[$j];
            if (is_array($tj) && in_array($tj[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($tj === ';' || $tj === '{' || $tj === '}') {
                break;   // statement boundary — nothing further belongs to this call
            }
            if ($tj !== '[') {
                continue;
            }
            $prev = $j - 1;
            while ($prev > 0 && is_array($tokens[$prev])
                && in_array($tokens[$prev][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $prev--;
            }
            $prevTok = $tokens[$prev] ?? null;
            if ($prevTok !== '(' && $prevTok !== ',' && $prevTok !== '=') {
                continue;   // a subscript such as $store['name'] — keep looking
            }

            // Collect quoted keys at depth 1 of this array.
            $depth = 0;
            for ($k = $j; $k < $n; $k++) {
                $t = $tokens[$k];
                if ($t === '[') {
                    $depth++;
                    continue;
                }
                if ($t === ']') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                    continue;
                }
                if ($depth === 1 && is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $next = $k + 1;
                    while ($next < $n && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                        $next++;
                    }
                    /* token_get_all() returns MULTI-character operators such as
                       '=>' as an array [id, text, line], not as a bare constant,
                       so this must compare the token ID. Comparing the whole
                       token to T_DOUBLE_ARROW is always false, which silently
                       collected zero keys and made this check pass vacuously. */
                    if (is_array($tokens[$next] ?? null) && $tokens[$next][0] === T_DOUBLE_ARROW) {
                        $keys[] = trim($t[1], "'\"");
                    }
                }
            }
            break;
        }
    }

    return array_values(array_unique($keys));
}

/** page() injects these itself, so a view may declare them without the call listing them. */
const IMPLICIT = ['title', 'nav', 'admin', 'csrf', 'flash', 'flashSecret', 'content'];

$controllers = [
    $ROOT . '/app/Controllers/PlatformAdmin/SuperAdminDashboardController.php',
    $ROOT . '/app/Controllers/PlatformAdmin/SuperAdminAuthController.php',
    $ROOT . '/app/PlatformAdmin/Kernel.php',
];
$controllerSrc = '';
foreach ($controllers as $c) {
    $controllerSrc .= (string)file_get_contents($c) . "\n";
}

$views = glob($ROOT . '/platform-admin/views/*.php') ?: [];
$check('views were discovered', $views !== []);

$covered = 0;
foreach ($views as $file) {
    $view  = basename($file, '.php');
    $needs = view_declared_vars($file);
    if ($needs === []) {
        continue;
    }
    $passed = keys_passed_for($controllerSrc, $view);
    if ($passed === []) {
        // No reachable render site in these controllers (e.g. a partial):
        // nothing to assert, and nothing silently wrong either.
        continue;
    }
    $covered++;
    $missing = array_values(array_diff($needs, $passed, IMPLICIT));
    $check(
        sprintf('view "%s": controller passes every @var it declares', $view)
        . ($missing === [] ? '' : ' (missing: $' . implode(', $', $missing) . ')'),
        $missing === []
    );
}
$check('at least one view was contract-checked', $covered > 0);

/* ── The specific regression, kept explicit for a readable failure ── */
$dashCtrl = (string)file_get_contents($ROOT . '/app/Controllers/PlatformAdmin/SuperAdminDashboardController.php');
$detailVars = view_declared_vars($ROOT . '/platform-admin/views/store_detail.php');
$check('store_detail still declares $liveCodeText in its @var',
    in_array('liveCodeText', $detailVars, true));
$check('no view declares $liveCode (the old mismatched name)',
    !in_array('liveCode', $detailVars, true));
$check('the mismatched key literal is gone from the controller',
    !preg_match("/'liveCode'\s*=>/", $dashCtrl));

/* ── Prove the checker itself still works ──────────────────────── */
/* Re-introduce the original bug in a string copy of the controller and assert
   the checker flags it. Without this, the checker could silently stop
   detecting anything and the suite would stay green forever. */
$broken       = str_replace("'liveCodeText' =>", "'liveCode' =>", $dashCtrl);
$brokenPassed = keys_passed_for($broken, 'store_detail');
$brokenMiss   = array_values(array_diff($detailVars, $brokenPassed, IMPLICIT));
$check('the checker detects the original liveCode/liveCodeText bug',
    in_array('liveCodeText', $brokenMiss, true));

/* ── report ──────────────────────────────────────────────────── */
foreach ($ok as $t)   { echo "PASS  $t\n"; }
foreach ($fail as $t) { echo "FAIL  $t\n"; }
echo "\n" . count($ok) . " passed, " . count($fail) . " failed\n";
exit($fail === [] ? 0 : 1);