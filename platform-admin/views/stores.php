<?php
/** @var list<array<string,mixed>> $rows @var string $status @var string $q @var array $counts @var string $csrf */
use ProCast\Support\View;
$e = [View::class, 'e'];

$tabStatuses = ['pending_approval' => 'Pending', 'active' => 'Active', 'suspended' => 'Suspended', 'rejected' => 'Rejected', 'all' => 'All'];
$badgeMap    = ['pending_approval' => 'badge-pending', 'active' => 'badge-active', 'suspended' => 'badge-suspended', 'rejected' => 'badge-rejected'];
?>
<div class="pa-tabs" role="tablist">
<?php foreach ($tabStatuses as $s => $label): ?>
  <a href="/platform-admin/stores?status=<?= $e($s) ?>"
     class="pa-tab <?= ($status === $s || ($s === 'all' && $status === 'all')) ? 'active' : '' ?>"
     role="tab">
    <?= $e($label) ?>
    <?php if ($s !== 'all' && isset($counts[$s])): ?>
    <span class="badge <?= $e($badgeMap[$s] ?? '') ?>" style="margin-left:2px"><?= $e($counts[$s]) ?></span>
    <?php endif; ?>
  </a>
<?php endforeach; ?>
</div>

<div class="pa-card">
  <div class="pa-card-header">
    <span class="pa-card-title">Store Applications</span>
    <form method="GET" action="/platform-admin/stores" id="store-search-form" style="display:flex;gap:8px;align-items:center">
      <input type="hidden" name="status" value="<?= $e($status) ?>">
      <div class="pa-search-wrap">
        <span class="pa-search-icon">🔍</span>
        <input type="text" name="q" class="form-control" value="<?= $e($q) ?>"
               placeholder="Search stores…" autocomplete="off" style="width:220px">
      </div>
    </form>
  </div>
  <div class="pa-table-wrap">
    <table class="pa-table">
      <thead>
        <tr>
          <th>Store</th><th>Owner</th><th>Contact</th><th>Status</th><th>Tier</th><th>Registered</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="7" class="text-muted" style="text-align:center;padding:32px">No stores found.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td>
            <div class="store-name"><a href="/platform-admin/stores/<?= $e($r['id']) ?>"><?= $e($r['name']) ?></a></div>
            <div class="text-muted font-mono">#<?= $e($r['id']) ?></div>
          </td>
          <td>
            <div><?= $e($r['owner_name'] ?? '—') ?></div>
            <div class="text-muted"><?= $e($r['owner_email'] ?? '') ?></div>
          </td>
          <td class="text-muted"><?= $e($r['contact_phone'] ?? '—') ?></td>
          <td><span class="badge <?= $e($badgeMap[$r['status']] ?? '') ?>"><?= $e($r['status']) ?></span></td>
          <td class="text-muted"><?= $e($r['subscription_tier'] ?? '—') ?></td>
          <td class="text-muted"><?= $e(substr($r['registered_at'] ?? $r['created_at'] ?? '', 0, 10)) ?></td>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <a href="/platform-admin/stores/<?= $e($r['id']) ?>" class="btn btn-ghost btn-sm">View</a>
              <?php if ($r['status'] === 'pending_approval'): ?>
              <button class="btn btn-emerald btn-sm" data-confirm='<?= json_encode([
                "title"   => "Approve store?",
                "desc"    => "Approve \"" . addslashes($r['name']) . "\"? This generates an API key and emails the owner.",
                "action"  => "/platform-admin/stores/" . $r['id'] . "/approve",
                "btnClass"=> "btn-emerald",
                "btnLabel"=> "Approve",
                "redirect"=> "/platform-admin/stores",
              ], JSON_UNESCAPED_UNICODE) ?>'>✅ Approve</button>
              <button class="btn btn-rose btn-sm" data-confirm='<?= json_encode([
                "title"        => "Reject store?",
                "desc"         => "Reject \"" . addslashes($r['name']) . "\". The applicant will be notified.",
                "requireReason"=> true,
                "action"       => "/platform-admin/stores/" . $r['id'] . "/reject",
                "btnClass"     => "btn-rose",
                "btnLabel"     => "Reject",
                "redirect"     => "/platform-admin/stores",
              ], JSON_UNESCAPED_UNICODE) ?>'>✗ Reject</button>
              <?php elseif ($r['status'] === 'active'): ?>
              <button class="btn btn-amber btn-sm" data-confirm='<?= json_encode([
                "title"        => "Suspend store?",
                "desc"         => "Suspend \"" . addslashes($r['name']) . "\"? Their API key will be revoked immediately.",
                "confirmText"  => "SUSPEND",
                "requireReason"=> true,
                "action"       => "/platform-admin/stores/" . $r['id'] . "/suspend",
                "btnClass"     => "btn-amber",
                "btnLabel"     => "Suspend",
                "redirect"     => "/platform-admin/stores",
              ], JSON_UNESCAPED_UNICODE) ?>'>⏸ Suspend</button>
              <?php elseif ($r['status'] === 'suspended'): ?>
              <button class="btn btn-emerald btn-sm" data-confirm='<?= json_encode([
                "title"   => "Reactivate store?",
                "desc"    => "Reactivate \"" . addslashes($r['name']) . "\"? A new API key will be issued.",
                "action"  => "/platform-admin/stores/" . $r['id'] . "/reactivate",
                "btnClass"=> "btn-emerald",
                "btnLabel"=> "Reactivate",
                "redirect"=> "/platform-admin/stores",
              ], JSON_UNESCAPED_UNICODE) ?>'>▶ Reactivate</button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
