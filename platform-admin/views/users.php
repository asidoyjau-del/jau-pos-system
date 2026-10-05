<?php
/** @var list<array<string,mixed>> $rows @var string $q @var int $now @var string $csrf */
use ProCast\Support\View;
$e = [View::class, 'e'];
$storeColors = ['active' => 'badge-active', 'suspended' => 'badge-suspended', 'pending_approval' => 'badge-pending'];
?>
<div class="pa-card">
  <div class="pa-card-header">
    <span class="pa-card-title">User Activity Inspector</span>
    <form method="GET" action="/platform-admin/users" id="user-search-form" style="display:flex;gap:8px;align-items:center">
      <div class="pa-search-wrap">
        <span class="pa-search-icon"><?= $e(View::icon('search')) ?></span>
        <input type="text" name="q" class="form-control" value="<?= $e($q) ?>"
               placeholder="Search username, name, email, store…"
               autocomplete="off" style="width:280px">
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Search</button>
    </form>
  </div>
  <div class="pa-table-wrap">
    <table class="pa-table">
      <thead>
        <tr>
          <th>User</th><th>Role</th><th>Store</th><th>Client</th><th>Store Status</th><th>Last Login</th><th>Active Tokens</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="7" class="text-muted" style="text-align:center;padding:32px">No users found. Use the search box above.</td></tr>
      <?php else: foreach ($rows as $u): ?>
        <?php
          $lastLogin  = $u['last_login'] ?? null;
          $active     = $lastLogin && (strtotime($lastLogin) > ($now - 1800));
          $sessionBadge = $active ? '<span class="badge badge-online">Online</span>' : '';
          $sinceMin = $lastLogin ? round((time() - strtotime($lastLogin)) / 60) : null;
          $sinceStr = $sinceMin === null ? '—' : ($sinceMin < 1440 ? $sinceMin.'m ago' : round($sinceMin/1440).'d ago');
        ?>
        <tr>
          <td>
            <div class="store-name"><?= $e($u['full_name'] ?? $u['username']) ?></div>
            <div class="text-muted font-mono"><?= $e($u['username']) ?></div>
            <?php if (!empty($u['email'])): ?>
            <div class="text-muted" style="font-size:.72rem"><?= $e($u['email']) ?></div>
            <?php endif; ?>
          </td>
          <td class="text-muted"><?= $e($u['role'] ?? '') ?></td>
          <td>
            <?php if (!empty($u['store_name'])): ?>
            <a href="/platform-admin/stores/<?= $e($u['store_id']) ?>"><?= $e($u['display_store_name'] ?: $u['store_name']) ?></a>
            <?php else: ?>
            <span class="text-muted">&mdash;</span>
            <?php endif; ?>
          </td>
          <td>
            <?php
              $ct      = (string)($u['client_type'] ?? 'web');
              $ctLabel = ['web' => 'Web', 'app' => 'App', 'local' => 'Local POS'][$ct] ?? 'Web';
              $ctClass = $ct === 'app' ? 'app' : ($ct === 'local' ? 'local' : '');
            ?>
            <span class="badge-client <?= $e($ctClass) ?>"><?= $e($ctLabel) ?></span>
          </td>
          <td>
            <?php if (!empty($u['store_status'])): ?>
            <span class="badge <?= $e($storeColors[$u['store_status']] ?? 'badge-rejected') ?>"><?= $e($u['store_status']) ?></span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td>
            <div><?= $e($sinceStr) ?></div>
            <?= $sessionBadge ?>
          </td>
          <td style="font-weight:700;color:<?= (int)$u['live_tokens'] > 0 ? 'var(--emerald)' : 'var(--text-3)' ?>">
            <?= $e($u['live_tokens']) ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

