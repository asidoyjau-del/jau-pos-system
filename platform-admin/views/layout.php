<?php
/** @var string $title @var string $content @var string $csrf @var string $nav @var array $admin @var array|null $flash @var array|null $flashSecret */
use ProCast\Support\View;
$e = [View::class, 'e'];
$navActive = $nav ?? '';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= $e($csrf ?? '') ?>">
<meta name="robots" content="noindex,nofollow,noarchive">
<title><?= $e($title ?? 'ProCast') ?> — ProCast Platform</title>
<link rel="stylesheet" href="/platform-admin/assets/admin.css">
</head>
<body>
<div class="pa-wrap">
  <!-- Sidebar -->
  <aside class="pa-sidebar" role="navigation" aria-label="Admin navigation">
    <div class="pa-logo">
      <div class="pa-logo-mark">
        <div class="pa-logo-icon"><?= $e(View::icon('shield', 'pa-ico-lg')) ?></div>
        <div>
          <div class="pa-logo-text">ProCast</div>
          <div class="pa-logo-sub">Platform Admin</div>
        </div>
      </div>
    </div>
    <nav class="pa-nav">
      <div class="pa-nav-label">Main</div>
      <a href="/platform-admin/monitoring" class="pa-nav-link <?= $navActive==='monitoring'?'active':'' ?>">
        <span class="pa-nav-icon"><?=$e(View::icon('activity')) ?></span> Monitoring
      </a>
      <a href="/platform-admin/stores" class="pa-nav-link <?= $navActive==='stores'?'active':'' ?>">
        <span class="pa-nav-icon"><?=$e(View::icon('store')) ?></span> Stores
      </a>
      <a href="/platform-admin/users" class="pa-nav-link <?= $navActive==='users'?'active':'' ?>">
        <span class="pa-nav-icon"><?=$e(View::icon('users')) ?></span> User Inspector
      </a>
    </nav>
    <div class="pa-sidebar-footer">
      <strong><?= $e(($admin['full_name'] ?? '') ?: ($admin['email'] ?? 'Admin')) ?></strong>
      <div class="text-muted" style="margin-bottom:6px;font-size:.7rem"><?= $e($admin['email'] ?? '') ?></div>
      <form method="POST" action="/platform-admin/logout">
        <input type="hidden" name="csrf" value="<?= $e($csrf ?? '') ?>">
        <button type="submit" class="btn btn-ghost btn-sm w-full"><?=$e(View::icon('log-out')) ?> Sign out</button>
      </form>
    </div>
  </aside>

  <!-- Main -->
  <div class="pa-main">
    <header class="pa-topbar">
      <div class="pa-topbar-title"><?= $e($title ?? '') ?></div>
      <div class="pa-topbar-actions">
        <span class="text-muted" id="refresh-ts"></span>
        <button id="btn-refresh" class="btn btn-ghost btn-sm" title="Refresh data">
          <span id="refresh-icon"><?= $e(View::icon('refresh')) ?></span>
        </button>
      </div>
    </header>
    <main class="pa-content">
      <?php if (!empty($flash)): ?>
      <div class="alert <?= $flash['type'] === 'error' ? 'alert-error' : 'alert-success' ?>" role="alert">
        <?= $e($flash['msg']) ?>
      </div>
      <?php endif; ?>
      <?php if (!empty($flashSecret)): ?>
      <div class="alert alert-info" role="alert">
        <strong><?= $e($flashSecret['label']) ?></strong><br>
        <?php if (!empty($flashSecret['isCode'])): ?>
          <?php /* A 6-digit code the admin has to read out or hand over: show it big
                 and one click to copy, rather than as an unbroken monospace string
                 that is easy to mistype. */ ?>
          <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:10px 0 6px">
            <div class="pairing-code" id="flash-pairing-code"><?= $e($flashSecret['value']) ?></div>
            <button type="button" class="btn btn-emerald btn-sm" id="btn-copy-code">📋 Copy code</button>
          </div>
        <?php else: ?>
          <code class="font-mono" style="font-size:.9rem;word-break:break-all"><?= $e($flashSecret['value']) ?></code>
          <div class="form-hint">Save this key now — it will never be shown again.</div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?= $content ?>
    </main>
  </div>
</div>

<!-- Global confirmation modal -->
<div class="modal-backdrop" id="modal-backdrop" role="dialog" aria-modal="true">
  <div class="modal-box">
    <h2 class="modal-title">Confirm action</h2>
    <p class="modal-desc"></p>
    <div id="modal-reason-wrap" class="form-group" style="display:none">
      <label class="form-label">Reason (required)</label>
      <textarea id="modal-reason-input" class="form-control" rows="3" placeholder="Provide a reason…"></textarea>
    </div>
    <div id="modal-confirm-wrap" class="modal-confirm-input-wrap" style="display:none">
      <label class="form-label" id="modal-confirm-label-text"></label>
      <input type="text" id="modal-confirm-input" class="form-control" autocomplete="off">
    </div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" id="modal-cancel">Cancel</button>
      <button type="button" class="btn btn-rose" id="modal-submit">Confirm</button>
    </div>
  </div>
</div>

<script src="/platform-admin/assets/admin.js"></script>
</body>
</html>
