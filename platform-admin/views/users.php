<?php
/** @var list<array<string,mixed>> $rows @var string $q @var int $now @var string $csrf */
use ProCast\Support\View;
$e = [View::class, 'e'];
// Icons are trusted markup from View::ICONS — must NOT be run through $e().
$ico = static fn (string $n, string $c = ''): string => View::icon($n, $c);
$storeColors = ['active' => 'badge-active', 'suspended' => 'badge-suspended', 'pending_approval' => 'badge-pending'];
$clientLabels = ['web' => 'Web', 'app' => 'App', 'local' => 'Local POS'];

/**
 * "Copy all" payload: tab-separated with a header row, so pasting into Excel /
 * Google Sheets / Numbers lines the columns up automatically. Rebuilt per row
 * from the SAME data the table renders, so it can never drift from the screen.
 */
$clipAll = "Name\tUsername\tEmail\tRole\tStore\tClient\tLast login";
foreach ($rows as $r) {
    $clipAll .= "\n" . implode("\t", [
        (string)($r['full_name'] ?? ''),
        (string)($r['username'] ?? ''),
        (string)($r['email'] ?? ''),
        (string)($r['role'] ?? ''),
        (string)($r['display_store_name'] ?: ($r['store_name'] ?? '')),
        $clientLabels[(string)($r['client_type'] ?? 'web')] ?? 'Web',
        (string)($r['last_login'] ?? '—'),
    ]);
}
?>
<div class="pa-card">
  <div class="pa-card-header">
    <span class="pa-card-title">User Activity Inspector</span>
    <form method="GET" action="/platform-admin/users" id="user-search-form" style="display:flex;gap:8px;align-items:center">
      <div class="pa-search-wrap">
        <span class="pa-search-icon"><?= $ico('search') ?></span>
        <input type="text" name="q" class="form-control" value="<?= $e($q) ?>"
               placeholder="Search username, name, email, store…"
               autocomplete="off" style="width:280px">
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Search</button>
    </form>
    <div style="display:flex;gap:8px;align-items:center;margin-left:auto">
      <?php if (!empty($rows)): ?>
      <button type="button" class="btn btn-ghost btn-sm copy-btn" data-copy="<?= $e($clipAll) ?>"
              title="Copy every visible user as tab-separated columns — paste straight into Excel">
        <?= $ico('copy') ?> Copy all users
      </button>
      <?php endif; ?>
    </div>
  </div>
  <div class="pa-table-wrap">
    <table class="pa-table">
      <thead>
        <tr>
          <th>User</th><th>Role</th><th>Store</th><th>Client</th><th>Store Status</th><th>Last Login</th><th>Active Tokens</th><th>Copy</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="8" class="text-muted" style="text-align:center;padding:32px">No users found. Use the search box above.</td></tr>
      <?php else: foreach ($rows as $u): ?>
        <?php
          $lastLogin  = $u['last_login'] ?? null;
          $active     = $lastLogin && (strtotime($lastLogin) > ($now - 1800));
          $sessionBadge = $active ? '<span class="badge badge-online">Online</span>' : '';
          $sinceMin = $lastLogin ? round((time() - strtotime($lastLogin)) / 60) : null;
          $sinceStr = $sinceMin === null ? '—' : ($sinceMin < 1440 ? $sinceMin.'m ago' : round($sinceMin/1440).'d ago');
          // Per-row clipboard payload: name / username / email only — never any
          // token or hash. The Inspector query returns no secrets to begin with.
          $clipRow = implode("\t", [
              (string)($u['full_name'] ?? ''),
              (string)($u['username'] ?? ''),
              (string)($u['email'] ?? ''),
          ]);
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
              $ctLabel = $clientLabels[$ct] ?? 'Web';
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
          <td>
            <button type="button" class="btn btn-ghost btn-sm copy-btn" data-copy="<?= $e($clipRow) ?>"
                    title="Copy name, username and email"><?= $ico('copy') ?></button>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

