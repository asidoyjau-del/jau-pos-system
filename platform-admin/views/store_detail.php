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
            $live   = !empty($t['pairing_id']);
            $expired = $live && !empty($t['pairing_expires_at']) && strtotime((string)$t['pairing_expires_at']) < time();
          ?>
          <tr>
            <td>
              <div class="store-name"><?= $e($t['full_name'] ?: $t['username']) ?></div>
              <div class="text-muted font-mono"><?= $e($t['username']) ?></div>
              <?php if (!empty($t['email'])): ?>
                <div class="text-muted" style="font-size:.72rem"><?= $e($t['email']) ?></div>
              <?php endif; ?>
              <?php /* One-click copy of the identity fields, so the admin can paste
                     them into a message, a spreadsheet or a new hire's record. */ ?>
              <button type="button" class="btn btn-ghost btn-sm copy-btn" style="margin-top:6px"
                      data-copy="<?= $e(implode("\t", [
                          (string)($t['full_name'] ?: $t['username']),
                          (string)$t['username'],
                          (string)($t['email'] ?? ''),
                      ])) ?>"
                      title="Copy name, username and email"><?= $ico('copy') ?> Copy details</button>
            </td>
            <td class="text-muted"><?= $e($t['role']) ?></td>
            <td>
              <?php if ($live && !$expired): ?>
                <span class="badge badge-pending">Code sent, not yet used</span>
              <?php else: ?>
                <span class="badge badge-rejected">Needs a code</span>
              <?php endif; ?>
            </td>
            <td>
              <?php /* This cell must show THIS person's code state, never the
                     store-wide one. It used to print $liveCodeText on every row,
                     so a screen full of rows each displayed the same six digits
                     and it read as though each person had their own — which is
                     exactly how one shared code ended up unlocking every account
                     on a till. A whole-store code names nobody and unlocks
                     nobody, so it has no business appearing in a per-person
                     column. Digits are shown on demand via "Copy code". */ ?>
              <?php if ($live && !$expired): ?>
                <?php $digits = (string)($t['pairing_code'] ?? ''); ?>
                <?php if ($digits !== ''): ?>
                  <div class="store-name" style="letter-spacing:.18em"><?= $e($digits) ?></div>
                  <button type="button" class="btn btn-ghost btn-sm copy-btn" style="margin-top:6px"
                          data-copy="<?= $e($digits) ?>"
                          title="Copy this person's code"><?= $ico('copy') ?> Copy code</button>
                  <?php if (!empty($t['pairing_role_name'])): ?>
                    <div class="text-muted" style="font-size:.72rem">Issued for <strong><?= $e((string)$t['pairing_role_name']) ?></strong></div>
                  <?php endif; ?>
                <?php else: ?>
                  <?php /* Encrypted at rest but the key is unavailable or was
                         rotated, so there is nothing to show. Fall back to the
                         reveal button, which is the last-resort path. */ ?>
                  <span class="text-muted" style="font-size:.8rem">Sent &mdash; use &ldquo;Copy code&rdquo;</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-muted" style="font-size:.8rem">Not issued</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($live && !$expired): ?>
                <?php /* The email is only a convenience — the admin is the fallback the
                       user can actually reach, so a live code can be shown again on
                       demand instead of forcing a pointless re-issue. */ ?>
                <form method="POST" action="/platform-admin/users/<?= (int)$t['id'] ?>/pairing/reveal" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                  <button type="submit" class="btn btn-emerald btn-sm" title="Show this code again so you can copy or read it out"><?= $ico('copy') ?> Copy code</button>
                </form>
                <button class="btn btn-ghost btn-sm" style="margin-left:6px" data-confirm='<?= json_encode([
                  "title"   => "Replace this code?",
                  "desc"    => "A new 6-digit code will be emailed to " . addslashes($t['email'] ?: $t['username']) . " and the current one stops working.",
                  "action"  => "/platform-admin/users/" . $t['id'] . "/pairing",
                  "btnClass"=> "btn-primary",
                  "btnLabel"=> "Issue new code",
                  "redirect"=> "/platform-admin/stores/" . $s['id'],
                ], JSON_UNESCAPED_UNICODE) ?>'>↻ New code</button>
              <?php else: ?>
                <button class="btn btn-primary btn-sm" data-confirm='<?= json_encode([
                  "title"   => "Issue an activation code?",
                  "desc"    => "A 6-digit code will be emailed to " . addslashes($t['email'] ?: $t['username']) . " for " . addslashes($t['full_name'] ?: $t['username']) . ". You can copy it afterwards if the email doesn't arrive.",
                  "action"  => "/platform-admin/users/" . $t['id'] . "/pairing",
                  "btnClass"=> "btn-primary",
                  "btnLabel"=> "Send code",
                  "redirect"=> "/platform-admin/stores/" . $s['id'],
                ], JSON_UNESCAPED_UNICODE) ?>'><?= $ico('mail') ?> Send code</button>
              <?php endif; ?>
              <?php /* Revoke is offered ONLY while a code is actually outstanding.
                     "New code" already retires a live one as a side effect, so past
                     that point revoke would have nothing to act on and would just
                     be a second way to reach the same end. It is also deliberately
                     NOT bundled with "New code": revoke has to stand alone for the
                     case where minting a replacement is exactly what to avoid —
                     a code that leaked to the wrong person. */ ?>
              <?php if ($live && !$expired): ?>
                <button class="btn btn-rose btn-sm" style="margin-left:6px" data-confirm='<?= json_encode([
                  "title"       => "Revoke this code?",
                  "desc"        => "This activation code stops working immediately and no replacement is issued. " . addslashes($t['full_name'] ?: $t['username']) . " will need a new code before they can activate another till.",
                  "confirmText" => (string)$t['username'],
                  "action"      => "/platform-admin/users/" . $t['id'] . "/pairing/revoke",
                  "btnClass"    => "btn-rose",
                  "btnLabel"    => "Revoke code",
                  "redirect"    => "/platform-admin/stores/" . $s['id'],
                ], JSON_UNESCAPED_UNICODE) ?>'>⊘ Revoke</button>
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

<?php /* Legacy whole-install code (migration 003). It still records that this store
      runs on local hardware, but it no longer unlocks any account — see the
      redeem endpoint. Kept visible rather than deleted so an admin holding an
      old code is told plainly what it does and does not do. */ ?>
<div class="pa-card mb-6">
  <div class="pa-card-header"><span class="pa-card-title"><?= $ico('key') ?> Store Link Code</span></div>
  <div class="pa-card-body">
    <div class="alert alert-info" style="margin-bottom:14px">
      <strong>This code does not unlock accounts.</strong> It only records that
      <?= $e($s['name']) ?> runs on a local till &mdash; useful for reporting, and for
      installs made before per-account codes existed. To let someone actually sign in,
      issue a code to their own row in the table above.
    </div>

    <?php if (!empty($pairings)): ?>
      <?php $codeRow = $pairings[0]; ?>
      <?php if (!empty($codeRow['can_reveal']) && $liveCodeText !== ''): ?>
        <?php /* The code is printed HERE, not only in a one-shot banner at the top
               of the page — otherwise it scrolls out of view the moment the admin
               is looking at this card, which is exactly where they need it. */ ?>
        <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:16px;border:1px dashed var(--accent);border-radius:var(--radius-sm);background:rgba(47,127,245,.07);margin-bottom:12px">
          <div>
            <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.08em">Your code — give this to the user</div>
            <div class="pairing-code" id="card-pairing-code"><?= $e($liveCodeText) ?></div>
          </div>
          <button type="button" class="btn btn-emerald btn-sm" id="btn-copy-card-code">📋 Copy code</button>
        </div>
        <p class="text-muted" style="margin-bottom:12px;font-size:.8rem">
          Issued <?= $e(substr((string)$codeRow['created_at'], 0, 16)) ?> UTC &middot;
          expires <?= $e(substr((string)$codeRow['expires_at'], 0, 16)) ?> UTC &middot;
          unlocks <strong>no account</strong> on its own.
        </p>
      <?php else: ?>
        <?php /* A code IS live, but we cannot read its digits back. Two possible
               causes — no encryption key on the server, or a key that no longer
               matches the one the code was sealed with (changed/rotated since).
               The old wording asserted only the first, which sent the operator
               to set an env var that was already set. Name both, and make the
               recovery action explicit: replacing the code re-seals it under the
               current key and brings it back on screen. */ ?>
        <div class="alert alert-info" style="margin-bottom:12px">
          A code is live for this store, issued <?= $e(substr((string)$codeRow['created_at'], 0, 16)) ?> UTC,
          expiring <?= $e(substr((string)$codeRow['expires_at'], 0, 16)) ?> UTC.
          <strong>It cannot be re-shown</strong> &mdash; the server either has no encryption key
          (<code>PLATFORM_ENC_KEY</code>) or the key no longer matches the one this code was
          sealed with. It still works; you just saw it once.
          Click <strong>Replace 6-digit code</strong> below to mint one that is shown here.
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <button class="btn btn-primary btn-sm" style="margin-top:<?= !empty($pairings) ? '14px' : '0' ?>" data-confirm='<?= json_encode([
      "title"   => !empty($pairings) ? "Replace the live code?" : "Generate a whole-store code?",
      "desc"    => !empty($pairings)
        ? "A new store link code will be generated for " . addslashes($s['name']) . " and the current one will stop working."
        : "A single store link code will be generated for " . addslashes($s['name']) . ". It records local usage; it does not unlock any account.",
      "action"  => "/platform-admin/stores/" . $s['id'] . "/pairing",
      "btnClass"=> "btn-primary",
      "btnLabel"=> !empty($pairings) ? "Replace code" : "Generate code",
      "redirect"=> "/platform-admin/stores/" . $s['id'],
    ], JSON_UNESCAPED_UNICODE) ?>'><?= $ico('key') ?> <?= !empty($pairings) ? 'Replace 6-digit code' : 'Generate 6-digit code' ?></button>
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