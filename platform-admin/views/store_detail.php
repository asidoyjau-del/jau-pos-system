<?php
/** @var array $store @var string $csrf */
use ProCast\Support\View;
$e = [View::class, 'e'];
$s = $store;
$badgeMap = ['pending_approval' => 'badge-pending', 'active' => 'badge-active', 'suspended' => 'badge-suspended', 'rejected' => 'badge-rejected'];
?>
<div style="margin-bottom:18px">
  <a href="/platform-admin/stores" class="btn btn-ghost btn-sm">← Back to stores</a>
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
      <p class="form-label">Owner</p><p class="text-sm"><?= $e($s['owner_name'] ?? '—') ?></p>
      <p class="form-label" style="margin-top:14px">Email</p><p class="text-sm"><?= $e($s['owner_email'] ?? '—') ?></p>
      <p class="form-label" style="margin-top:14px">Phone</p><p class="text-sm"><?= $e($s['contact_phone'] ?? '—') ?></p>
      <p class="form-label" style="margin-top:14px">Address</p><p class="text-sm"><?= $e($s['address'] ?? '—') ?></p>
    </div>
    <div>
      <p class="form-label">Subscription Tier</p><p class="text-sm"><?= $e($s['subscription_tier'] ?? '—') ?></p>
      <p class="form-label" style="margin-top:14px">Registered</p><p class="text-sm"><?= $e($s['registered_at'] ?? $s['created_at'] ?? '—') ?></p>
      <p class="form-label" style="margin-top:14px">Approved At</p><p class="text-sm"><?= $e($s['approved_at'] ?? '—') ?></p>
      <?php if (!empty($s['rejection_reason'])): ?>
      <p class="form-label" style="margin-top:14px">Rejection / Suspension Reason</p>
      <p class="text-sm" style="color:var(--rose)"><?= $e($s['rejection_reason']) ?></p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (!empty($s['verification_doc'])): ?>
<div class="pa-card mb-6">
  <div class="pa-card-header"><span class="pa-card-title">📄 Verification Document</span></div>
  <div class="pa-card-body">
    <a href="/platform-admin/stores/<?= $e($s['id']) ?>/document" class="btn btn-ghost" target="_blank" rel="noopener">
      Download / View Document
    </a>
  </div>
</div>
<?php endif; ?>

<div class="pa-card">
  <div class="pa-card-header"><span class="pa-card-title">⚡ Actions</span></div>
  <div class="pa-card-body flex gap-3" style="flex-wrap:wrap">
    <?php if ($s['status'] === 'pending_approval'): ?>
      <!-- Approve: needs tier selection -->
      <form method="POST" action="/platform-admin/stores/<?= $e($s['id']) ?>/approve" style="display:flex;gap:8px;align-items:center">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <select name="subscription_tier" class="form-control" style="width:140px">
          <option value="free">Free</option>
          <option value="standard" selected>Standard</option>
          <option value="pro">Pro</option>
        </select>
        <button type="submit" class="btn btn-emerald"
          onclick="return confirm('Approve this store with the selected tier?')">✅ Approve</button>
      </form>
      <button class="btn btn-rose" data-confirm='<?= json_encode([
        "title" => "Reject store?",
        "desc"  => "Reject this store. The applicant will be notified with your reason.",
        "requireReason" => true,
        "action"  => "/platform-admin/stores/" . $s['id'] . "/reject",
        "btnClass"=> "btn-rose", "btnLabel"=> "Reject",
        "redirect"=> "/platform-admin/stores",
      ], JSON_UNESCAPED_UNICODE) ?>'>✗ Reject</button>

    <?php elseif ($s['status'] === 'active'): ?>
      <button class="btn btn-amber" data-confirm='<?= json_encode([
        "title" => "Suspend store?",
        "desc"  => "Suspending \"" . addslashes($s['name']) . "\" will revoke their API key immediately.",
        "confirmText" => "SUSPEND",
        "requireReason" => true,
        "action"  => "/platform-admin/stores/" . $s['id'] . "/suspend",
        "btnClass"=> "btn-amber", "btnLabel"=> "Suspend",
        "redirect"=> "/platform-admin/stores",
      ], JSON_UNESCAPED_UNICODE) ?>'>⏸ Suspend Store</button>

    <?php elseif ($s['status'] === 'suspended'): ?>
      <button class="btn btn-emerald" data-confirm='<?= json_encode([
        "title" => "Reactivate store?",
        "desc"  => "This will issue a new API key for \"" . addslashes($s['name']) . "\".",
        "action"  => "/platform-admin/stores/" . $s['id'] . "/reactivate",
        "btnClass"=> "btn-emerald", "btnLabel"=> "Reactivate",
        "redirect"=> "/platform-admin/stores",
      ], JSON_UNESCAPED_UNICODE) ?>'>▶ Reactivate Store</button>
    <?php endif; ?>
  </div>
</div>
