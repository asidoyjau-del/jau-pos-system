<?php
declare(strict_types=1);

/**
 * Regression tests for the store LIST page: default tab, aligned Actions
 * column, and a permanent delete that actually deletes.
 *
 * Background -- the delete button was dead in production.
 * SuperAdminDashboardController::destroy() authorises the irreversible delete
 * with hash_equals($storeName, $req->input('confirm_text')), but admin.js built
 * its POST body as `{ reason }` ONLY. It never put the text the admin had typed
 * into the "type the store name" box on the wire, so $req->input('confirm_text')
 * was always ''. Every attempt ended in "Type the exact store name to confirm
 * the deletion." and the store stayed in the database -- the button looked
 * broken while nothing had actually been removed.
 *
 * These tests pin the whole round trip:
 *   1. the modal POST carries confirm_text when a typed confirmation exists;
 *   2. the server no longer demands a written reason, so the modal that no
 *      longer shows one cannot dead-end;
 *   3. the hard delete removes the store AND its users, and the counters the
 *      tabs display are computed live from the stores table (so the Active
 *      badge drops by one with no extra bookkeeping).
 *
 * Run:  php tests/StoreListDeleteTest.php
 */

$ROOT = dirname(__DIR__);
define('PROCAST_ROOT', $ROOT);
require $ROOT . '/app/Bootstrap.php';

$ok = [];
$fail = [];
$check = static function (string $label, bool $cond) use (&$ok, &$fail): void {
    if ($cond) { $ok[] = $label; } else { $fail[] = $label; }
};

$js   = (string)file_get_contents($ROOT . '/platform-admin/assets/admin.js');
$ctrl = (string)file_get_contents($ROOT . '/app/Controllers/PlatformAdmin/SuperAdminDashboardController.php');
$repo = (string)file_get_contents($ROOT . '/app/Support/StoreRepository.php');
$list = (string)file_get_contents($ROOT . '/platform-admin/views/stores.php');
$det  = (string)file_get_contents($ROOT . '/platform-admin/views/store_detail.php');
$css  = (string)file_get_contents($ROOT . '/platform-admin/assets/admin.css');

/* -- 1. The typed confirmation must reach the server ------------------- */
$check(
    'admin.js posts confirm_text from the modal',
    str_contains($js, 'body.confirm_text = typeInput.value.trim()')
);
$check(
    'confirm_text is sent only when a typed confirmation exists',
    str_contains($js, 'if (needsConfirm) body.confirm_text')
);
$check(
    'admin.js does post a request body to the action URL',
    str_contains($js, 'apiPost(cfg.action, body)')
);
$check(
    'server authorises the delete on confirm_text',
    str_contains($ctrl, "input('confirm_text')")
);

/* -- 2. The delete modal must not dead-end on a reason it no longer shows */
$check(
    'destroy() treats a blank reason as acceptable',
    str_contains($ctrl, "if (\$reason !== '' && (mb_strlen(\$reason) < 10")
);
$check(
    'a missing reason is recorded explicitly, not left blank',
    str_contains($ctrl, "\$reason = 'Not given'")
);
// The stores list delete config must not re-introduce requireReason. Anchor on
// the delete's "confirmText"/btnLabel block rather than its "action" entry,
// because "action" comes LAST in this array and a match starting there would
// run off the end of the config entirely.
preg_match('/"title"\s*=>\s*"Permanently delete.*?\], JSON_UNESCAPED_UNICODE\)/s', $list, $m);
$deleteCfg = $m[0] ?? '';
$check('found the delete config in stores.php', $deleteCfg !== '');
$check('stores.php delete does NOT require a reason', !str_contains($deleteCfg, 'requireReason'));
$check('stores.php delete still requires the typed name', str_contains($deleteCfg, 'confirmText'));
$check(
    'store_detail delete does NOT require a reason',
    !preg_match('/"requireReason"=>\s*true,\s*\n\s*"action"\s*=>\s*"[^"]*\/delete"/', $det)
);
// Reject and Suspend legitimately keep their reason -- only the hard delete lost it.
$check('reject still asks for a reason', str_contains($list, '"requireReason"=> true'));

/* -- 3. Deleting must remove the store, its users and its codes -------- */
foreach ([
    'DELETE FROM auth_tokens WHERE user_id IN (SELECT id FROM users WHERE store_id = ?)',
    'DELETE FROM platform_store_pairings WHERE store_id = ?',
    'DELETE FROM platform_user_pairings WHERE store_id = ?',
    'DELETE FROM stores WHERE id = ?',
] as $needle) {
    $check("hard delete runs: {$needle}", str_contains($repo, $needle));
}
// users/settings/categories share one loop driven by a table list.
$check(
    'hard delete removes users (via the child-table loop)',
    (bool)preg_match('/foreach \(\[\s*\'users\'\s*,\s*\'settings\'\s*,\s*\'categories\'/', $repo)
);
$check(
    'that loop deletes every listed table by store_id',
    str_contains($repo, 'DELETE FROM {$table} WHERE store_id = ?')
);
// Pairing rows reference users(id), so they must be deleted before the users
// loop runs -- otherwise removal depends entirely on the FK cascade existing.
$pairPos = strpos($repo, 'DELETE FROM platform_user_pairings');
$userPos = strpos($repo, "foreach (['users', 'settings', 'categories']");
$check(
    'user pairings are removed before the users they point at',
    $pairPos !== false && $userPos !== false && $pairPos < $userPos
);
// The delete must stay inside one transaction so a partial failure cannot leave
// a tombstone with the store still present (or vice versa).
$check('delete is wrapped in a transaction', str_contains($ctrl, '$this->db->beginTransaction()'));
$check('a failed delete rolls back', str_contains($ctrl, '$this->db->rollBack()'));

/* -- 4. The tab counters must fall by one with no extra bookkeeping ----- */
$check(
    'counts are grouped live from the stores table',
    str_contains($repo, 'FROM stores GROUP BY status')
);
$check(
    'no denormalised active counter is kept that could drift',
    !str_contains($repo, 'active_count')
);

/* -- 5. The list defaults to All ---------------------------------------- */
$check(
    'stores() defaults the status filter to all',
    str_contains($ctrl, "queryStr('status', 'all')")
);
$check(
    'an unknown status also falls back to All (no rows hidden)',
    str_contains($ctrl, "in_array(\$status, StoreRepository::STATUSES, true) ? \$status : null")
);
$check('the All tab is still offered', str_contains($list, "'all' => 'All'"));

/* -- 6. The Actions column lines up ------------------------------------ */
$check('stores.php marks the actions cell', str_contains($list, 'class="pa-actions-cell"'));
$check('stores.php uses the pa-actions grid', str_contains($list, 'class="pa-actions"'));
$check(
    'the old ragged flex-wrap row is gone',
    !str_contains($list, 'display:flex;gap:6px;flex-wrap:wrap')
);
$check('admin.css defines .pa-actions', str_contains($css, '.pa-actions{'));
$check('action buttons share one grid so their widths match', str_contains($css, '.pa-actions{display:grid'));
$check('action buttons stretch to the cell width', str_contains($css, '.pa-actions > *{width:100%'));
$check(
    'the header cell is marked too, so it lines up',
    str_contains($list, '<th class="pa-actions-cell">Actions</th>')
);
// Every lifecycle button keeps a leading icon so the labels sit on one baseline.
foreach (['View', 'Approve', 'Reject', 'Suspend', 'Reactivate', 'Delete'] as $lbl) {
    $check(
        "the {$lbl} button keeps an icon",
        (bool)preg_match('/\$ico\(\'[a-z-]+\'\) \?>\s*' . $lbl . '\b/', $list)
    );
}

/* -- Report ------------------------------------------------------------ */
foreach ($fail as $f) {
    echo "FAIL  {$f}\n";
}
foreach ($ok as $o) {
    echo "PASS  {$o}\n";
}
printf("\n%d passed, %d failed\n", count($ok), count($fail));
exit($fail === [] ? 0 : 1);