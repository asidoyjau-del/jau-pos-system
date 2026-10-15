<?php
/**
 * @var array $store @var string $csrf @var array $pairings @var array $team
 * @var int $userCount @var string|null $liveCodeText
 */
use ProCast\Support\View;
$e = [View::class, 'e'];
$ico = static fn (string $n, string $c = ''): string => View::icon($n, $c);
// Decrypted by the controller. Empty when the code cannot be recovered — e.g.
// no encryption key is configured, or the code predates migration 005.
$liveCodeText = trim((string)($liveCodeText ?? ''));
$s = $store;
$s['name']       = $s['display_name'] ?: $s['name'];
$s['owner_name'] = $s['display_owner'] ?: ($s['owner_name'] ?? '');
$s['owner_email']= $s['display_email'] ?: ($s['owner_email'] ?? '');
// View::render() binds these with extract(), so static analysers cannot see the
// value arrive from the controller and flag it as undefined. ?? keeps that a
// warning-free fallback rather than a fatal if this view is ever rendered
// without the controller-supplied count; $team is always present.
$s['userCount']  = (int)($userCount ?? count($team));
$badgeMap = ['pending_approval' => 'badge-pending', 'active' => 'badge-active', 'suspended' => 'badge-suspended', 'rejected' => 'badge-rejected'];

$clientTypes = [
    'web'   => ['Web browser', 'monitor', 'Uses the online POS at this site in a normal browser.'],
    'app'   => ['Installed app', 'smartphone', 'Installed the PWA on a phone or tablet (Add to Home Screen).'],
    'local' => ['Local install', 'desktop', 'Runs an offline POS install on their own PC/server.'],
];
$current = (string)($s['client_type'] ?? 'web');
?>
<?php /* Back link. display:flex matters: .btn is inline-flex, so as an inline-level
       box it sat on the text baseline and the wrapper's height picked up
       baseline slack, which left the button visually crowding the card below.
       As a flex container the wrapper's height is exactly the button's, so the
       gap is precisely mb-6 (24px) — the same rhythm the cards use between
       themselves. */ ?>
<div class="mb-6" style="display:flex">
  <a href="/platform-admin/stores" class="btn btn-ghost btn-sm">&larr; Back to stores</a>
</div>

<div class="pa-card mb-6">
  <div class="pa-card-header">
    <div>
      <div class="pa-card-title"><?= $e($s['name']) ?></div>
      <div class="text-muted font-mono">Store #<?= $e($s['id']) ?></div>
    </div>
    <span class="badge <?= $e($badgeMap[$s['status']] ?? '') ?>"><?= $e($s['status']) ?></span>
  </div>
  <div class="pa-card-body grid-2">
    <div>
      <p class="form-label"><?= $ico('users') ?> Owner</p>
      <p class="text-sm"><?= $e($s['owner_name'] ?: 'Not recorded') ?></p>
      <p class="form-label" style="margin-top:14px"><?= $ico('mail') ?> Email</p>
      <p class="text-sm"><?= $e($s['owner_email'] ?: 'Not recorded') ?></p>
      <p class="form-label" style="margin-top:14px"><?= $ico('phone') ?> Phone</p>
      <p class="text-sm"><?= $e($s['contact_phone'] ?: 'Not recorded') ?></p>
      <p class="form-label" style="margin-top:14px"><?= $ico('store') ?> Address</p>
      <p class="text-sm"><?= $e($s['address'] ?: 'Not recorded') ?></p>
    </div>
    <div>
      <p class="form-label"><?= $ico('zap') ?> Subscription Tier</p>
      <p class="text-sm"><?= $e($s['subscription_tier'] ?: 'standard') ?></p>
      <p class="form-label" style="margin-top:14px"><?= $ico('info') ?> Registered</p>
      <p class="text-sm"><?= $e($s['registered_at'] ?: $s['created_at'] ?: '-') ?></p>
      <p class="form-label" style="margin-top:14px"><?= $ico('check') ?> Approved At</p>
      <p class="text-sm"><?= $e($s['approved_at'] ?: '-') ?></p>
      <?php if (!empty($s['rejection_reason'])): ?>
      <p class="form-label" style="margin-top:14px"><?= $ico('alert') ?> Rejection / Suspension Reason</p>
      <p class="text-sm" style="color:var(--rose)"><?= $e($s['rejection_reason']) ?></p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php /* How does this store run ProCast? */ ?>
<div class="pa-card mb-6">
  <div class="pa-card-header"><span class="pa-card-title"><?= $ico('smartphone') ?> How they use ProCast</span></div>
  <div class="pa-card-body">
    <?php /* The picker belongs at the TOP of the card: it is the control that
           DRIVES the three explanation tiles below it, so you choose first and
           then read what your choice means. The Save button stays down at the
           bottom with the rest of the row. One form now spans both positions —
           HTML allows that because <select form="..."> joins a form by id
           instead of by being a DOM descendant of it. Both the select and the
           csrf token are therefore submitted together, exactly as before. */ ?>
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:16px;flex-wrap:wrap">
      <label class="form-label" for="client-type-select" style="margin:0">Client type</label>
      <select name="client_type" id="client-type-select" form="client-type-form"
              class="form-control" style="width:180px">
        <?php foreach ($clientTypes as $key => [$label]): ?>
        <option value="<?= $e($key) ?>" <?= $current === $key ? 'selected' : '' ?>><?= $e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="grid-2">
      <?php foreach ($clientTypes as $key => [$label, $icon, $help]): ?>
      <div style="display:flex;gap:10px;align-items:flex-start;padding:12px;border:1px solid <?= $current === $key ? 'var(--accent)' : 'var(--border)' ?>;border-radius:var(--radius-sm);background:<?= $current === $key ? 'rgba(47,127,245,.08)' : 'transparent' ?>">
        <span style="color:var(--accent-h);flex-shrink:0;margin-top:2px"><?= $ico($icon, 'pa-ico-lg') ?></span>
        <div>
          <strong style="display:block"><?= $e($label) ?></strong>
          <span class="text-muted"><?= $e($help) ?></span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php /* Save stays at the bottom. The id here is what the <select> at the top
           of this card binds to via its form="..." attribute. */ ?>
    <form method="POST" action="/platform-admin/stores/<?= $e($s['id']) ?>/client-type" id="client-type-form"
          style="display:flex;gap:8px;align-items:center;margin-top:16px;flex-wrap:wrap">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <button type="submit" class="btn btn-primary btn-sm">Save</button>
    </form>
  </div>
</div>
<?php /* Per-ACCOUNT activation — every person needs their own code */ ?>
<div class="pa-card mb-6">
  <div class="pa-card-header">
    <span class="pa-card-title"><?= $ico('users') ?> Local POS Access — <?= $e($s['name']) ?></span>
    <?php if (!empty($team)): ?>
      <?php /* Tab-separated so it drops straight into Excel as columns. */ ?>
      <button type="button" class="btn btn-ghost btn-sm copy-btn" data-copy="<?= $e(implode("\n", array_merge(
          ["Name\tUsername\tEmail\tRole\tActivation"],
          array_map(static fn (array $t): string => implode("\t", [
              (string)($t['full_name'] ?: $t['username']),
              (string)$t['username'],
              (string)($t['email'] ?? ''),
              (string)($t['role'] ?? ''),
              (!empty($t['pairing_id']) && (empty($t['pairing_expires_at']) || strtotime((string)$t['pairing_expires_at']) >= time()))
                  ? 'code issued' : 'needs code',
          ]), $team)
      ))) ?>" title="Copy this store's whole team as tab-separated columns"><?= $ico('copy') ?> Copy all</button>
    <?php endif; ?>
  </div>
  <div class="pa-card-body">
    <p class="text-muted" style="margin-bottom:14px">
      Every person who uses the offline POS needs their own code, issued to their
      own username. A code unlocks <strong>only that account</strong>, and
      <strong>only on the computer it was entered on</strong> &mdash; a cashier
      cannot sign in with a colleague's code, and a new laptop needs a fresh code.
    </p>

    <?php if (empty($team)): ?>
      <p class="text-muted" style="font-size:.875rem">No accounts have synced up yet. Once this store's users sync online, they will appear here.</p>
    <?php else: ?>
    <div class="pa-table-wrap">
      <table class="pa-table">
        <thead><tr><th>Person</th><th>Role</th><th>Status</th><th>Code</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($team as $t): ?>
          <?php
            $hasPairing = !empty($t['pairing_id']);
            $used       = $hasPairing && !empty($t['pairing_used_at']);
            $live       = $hasPairing && !$used;
            $expired    = $live && !empty($t['pairing_expires_at']) && strtotime((string)$t['pairing_expires_at']) < time();
            $digits     = (string)($t['pairing_code'] ?? '');
          ?>
          <tr>
            <td>
              <div class="store-name"><?= $e($t['full_name'] ?: $t['username']) ?></div>
              <div class="text-muted font-mono"><?= $e($t['username']) ?></div>
              <?php if (!empty($t['email'])): ?>
                <div class="text-muted" style="font-size:.72rem"><?= $e($t['email']) ?></div>
              <?php endif; ?>
            </td>
            <td class="text-muted"><?= $e($t['role']) ?></td>
            <td>
              <?php if ($used): ?>
                <span class="badge badge-active">Activated</span>
              <?php elseif ($live && !$expired): ?>
                <span class="badge badge-pending">Code sent, not yet used</span>
              <?php elseif ($live && $expired): ?>
                <span class="badge badge-rejected">Code expired</span>
              <?php else: ?>
                <span class="badge badge-rejected">Needs a code</span>
              <?php endif; ?>
            </td>
            <td>
              <?php /* This cell shows THIS person's code state. Digits are rendered directly in the cell. */ ?>
              <?php if ($digits !== ''): ?>
                <div class="store-name" style="letter-spacing:.18em"><?= $e($digits) ?></div>
                <?php if ($used): ?>
                  <div class="text-muted" style="font-size:.72rem">Activated</div>
                <?php elseif ($expired): ?>
                  <div class="text-muted" style="font-size:.72rem">Expired</div>
                <?php elseif (!empty($t['pairing_role_name'])): ?>
                  <div class="text-muted" style="font-size:.72rem">Issued for <strong><?= $e((string)$t['pairing_role_name']) ?></strong></div>
                <?php endif; ?>
              <?php elseif ($used): ?>
                <span class="text-muted" style="font-size:.8rem">Activated</span>
              <?php elseif ($live && !$expired): ?>
                <span class="text-muted" style="font-size:.8rem">Code sent</span>
              <?php elseif ($live && $expired): ?>
                <span class="text-muted" style="font-size:.8rem">Expired</span>
              <?php else: ?>
                <span class="text-muted" style="font-size:.8rem">Not issued</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($live && !$expired): ?>
                <button class="btn btn-primary btn-sm" data-confirm='<?= json_encode([
                  "title"   => "Replace the live code?",
                  "desc"    => "Replace the live code if the first code expired and is not been use. A new activation code will be generated for " . addslashes($t['full_name'] ?: $t['username']) . " and the current one will stop working.",
                  "action"  => "/platform-admin/users/" . $t['id'] . "/pairing",
                  "btnClass"=> "btn-primary",
                  "btnLabel"=> "Replace code",
                  "redirect"=> "/platform-admin/stores/" . $s['id'],
                ], JSON_UNESCAPED_UNICODE) ?>'><?= $ico('mail') ?> Send code</button>
                <?php /* Revoke is offered ONLY while a code is actually outstanding. */ ?>
                <button class="btn btn-rose btn-sm" style="margin-left:6px" data-confirm='<?= json_encode([
                  "title"       => "Revoke this code?",
                  "desc"        => "This activation code stops working immediately and no replacement is issued. " . addslashes($t['full_name'] ?: $t['username']) . " will need a new code before they can activate another till.",
                  "confirmText" => (string)$t['username'],
                  "action"      => "/platform-admin/users/" . $t['id'] . "/pairing/revoke",
                  "btnClass"    => "btn-rose",
                  "btnLabel"    => "Revoke code",
                  "redirect"    => "/platform-admin/stores/" . $s['id'],
                ], JSON_UNESCAPED_UNICODE) ?>'>⊘ Revoke</button>
              <?php else: ?>
                <button class="btn btn-primary btn-sm" data-confirm='<?= json_encode([
                  "title"   => "Replace the live code?",
                  "desc"    => "Replace the live code if the first code expired and is not been use. A new activation code will be generated for " . addslashes($t['full_name'] ?: $t['username']) . " and the current one will stop working.",
                  "action"  => "/platform-admin/users/" . $t['id'] . "/pairing",
                  "btnClass"=> "btn-primary",
                  "btnLabel"=> "Replace code",
                  "redirect"=> "/platform-admin/stores/" . $s['id'],
                ], JSON_UNESCAPED_UNICODE) ?>'><?= $ico('mail') ?> Send code</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($s['verification_doc'])): ?>
<div class="pa-card mb-6">
  <div class="pa-card-header"><span class="pa-card-title"><?= $ico('file') ?> Verification Document</span></div>
  <div class="pa-card-body">
    <a href="/platform-admin/stores/<?= $e($s['id']) ?>/document" class="btn btn-ghost" target="_blank" rel="noopener">
      <?= $ico('download') ?> Download / View Document
    </a>
  </div>
</div>
<?php endif; ?>

<div class="pa-card mb-6">
  <div class="pa-card-header"><span class="pa-card-title"><?= $ico('zap') ?> Lifecycle</span></div>
  <div class="pa-card-body flex gap-3" style="flex-wrap:wrap">
    <?php if ($s['status'] === 'pending_approval'): ?>
      <form method="POST" action="/platform-admin/stores/<?= $e($s['id']) ?>/approve" style="display:flex;gap:8px;align-items:center">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <select name="subscription_tier" class="form-control" style="width:140px">
          <option value="free">Free</option>
          <option value="standard" selected>Standard</option>
          <option value="pro">Pro</option>
        </select>
        <button type="submit" class="btn btn-emerald"><?= $ico('check') ?> Approve</button>
      </form>
      <button class="btn btn-rose" data-confirm='<?= json_encode([
        "title" => "Reject store?",
        "desc"  => "Reject this store. The applicant will be notified with your reason.",
        "requireReason" => true,
        "action"  => "/platform-admin/stores/" . $s['id'] . "/reject",
        "btnClass"=> "btn-rose", "btnLabel"=> "Reject",
        "redirect"=> "/platform-admin/stores",
      ], JSON_UNESCAPED_UNICODE) ?>'><?= $ico('x') ?> Reject</button>

    <?php elseif ($s['status'] === 'active'): ?>
      <button class="btn btn-amber" data-confirm='<?= json_encode([
        "title" => "Suspend store?",
        "desc"  => "Suspending this store signs out every user from the online site, the local POS and the ProCast app, and revokes their API key immediately.",
        // Must match SuperAdminDashboardController::suspend(), which authorises
        // with hash_equals($storeName, confirm_text) -- the literal "SUSPEND"
        // asked for here could never satisfy it, so suspend always failed.
        "confirmText" => addslashes($s['name']),
        "action"  => "/platform-admin/stores/" . $s['id'] . "/suspend",
        "btnClass"=> "btn-amber", "btnLabel"=> "Suspend",
        "redirect"=> "/platform-admin/stores",
      ], JSON_UNESCAPED_UNICODE) ?>'><?= $ico('pause') ?> Suspend Store</button>

    <?php elseif ($s['status'] === 'suspended'): ?>
      <button class="btn btn-emerald" data-confirm='<?= json_encode([
        "title" => "Reactivate store?",
        "desc"  => "This will issue a new API key for this store.",
        "action"  => "/platform-admin/stores/" . $s['id'] . "/reactivate",
        "btnClass"=> "btn-emerald", "btnLabel"=> "Reactivate",
        "redirect"=> "/platform-admin/stores",
      ], JSON_UNESCAPED_UNICODE) ?>'><?= $ico('play') ?> Reactivate Store</button>
    <?php endif; ?>
  </div>
</div>

<?php /* Danger zone: irreversible hard delete */ ?>
<div class="pa-card danger-zone mb-6">
  <div class="pa-card-header"><span class="pa-card-title"><?= $ico('alert') ?> Danger Zone</span></div>
  <div class="pa-card-body">
    <p style="font-size:.875rem;color:var(--text-2);margin-bottom:14px">
      Permanently deletes this store, its users and every saved login token.
      Sales history, products and uploaded images are <strong>not</strong> removed.
      This cannot be undone.
    </p>
    <button class="btn btn-rose" data-confirm='<?= json_encode([
      "title"        => "Permanently delete " . $s['name'] . "?",
      "desc"         => "This erases the store and all of its users for good. Type the store name below to confirm.",
      "confirmText"  => (string)$s['name'],
      "action"       => "/platform-admin/stores/" . $s['id'] . "/delete",
      "btnClass"     => "btn-rose",
      "btnLabel"     => "Delete permanently",
      "redirect"     => "/platform-admin/stores",
    ], JSON_UNESCAPED_UNICODE) ?>'><?= $ico('trash') ?> Delete store permanently</button>
  </div>
</div>