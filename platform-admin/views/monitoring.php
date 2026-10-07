<?php
/** @var array $counts @var int $sessions @var array $volume @var string $csrf @var array $admin */
use ProCast\Support\View;
$e  = [View::class, 'e'];
$fn = fn($n) => number_format($n, 2);
?>
<!-- Stat tiles -->
<div class="pa-stats" id="telemetry-wrap">
  <div class="pa-stat accent">
    <div class="pa-stat-label">Total Stores</div>
    <div class="pa-stat-value" id="stat-total"><?= $e($counts['total']) ?></div>
    <div class="pa-stat-sub">registered</div>
  </div>
  <div class="pa-stat emerald">
    <div class="pa-stat-label">Active</div>
    <div class="pa-stat-value" id="stat-active"><?= $e($counts['active']) ?></div>
    <div class="pa-stat-sub">live stores</div>
  </div>
  <div class="pa-stat amber">
    <div class="pa-stat-label">Pending</div>
    <div class="pa-stat-value" id="stat-pending"><?= $e($counts['pending_approval']) ?></div>
    <div class="pa-stat-sub">
      <?php if ($counts['pending_approval'] > 0): ?>
      <a href="/platform-admin/stores?status=pending_approval" class="badge badge-pending">Review now</a>
      <?php else: ?>awaiting<?php endif; ?>
    </div>
  </div>
  <div class="pa-stat rose">
    <div class="pa-stat-label">Suspended</div>
    <div class="pa-stat-value" id="stat-suspended"><?= $e($counts['suspended']) ?></div>
    <div class="pa-stat-sub">blocked</div>
  </div>
  <div class="pa-stat accent">
    <div class="pa-stat-label">Active Sessions</div>
    <div class="pa-stat-value" id="stat-sessions"><?= $e($sessions) ?></div>
    <div class="pa-stat-sub">cashiers online</div>
  </div>
</div>

<!-- Volume -->
<div class="grid-2 mb-6">
  <div class="pa-card">
    <div class="pa-card-header">
      <span class="pa-card-title"> Today&apos;s Volume</span>
    </div>
    <div class="pa-card-body">
      <div style="font-size:1.9rem;font-weight:800;color:var(--emerald)" id="stat-today-total">₱<?= $fn($volume['today']['total']) ?></div>
      <div class="text-muted"><span id="stat-today-count"><?= $e($volume['today']['count']) ?></span> transactions</div>
    </div>
  </div>
  <div class="pa-card">
    <div class="pa-card-header">
      <span class="pa-card-title"> This Month</span>
    </div>
    <div class="pa-card-body">
      <div style="font-size:1.9rem;font-weight:800;color:var(--accent-h)" id="stat-month-total">₱<?= $fn($volume['month']['total']) ?></div>
      <div class="text-muted"><span id="stat-month-count"><?= $e($volume['month']['count']) ?></span> transactions</div>
    </div>
  </div>
</div>

<!-- Health widget -->
<div class="pa-card mb-6">
  <div class="pa-card-header">
    <span class="pa-card-title"> External Services</span>
  </div>
  <div class="pa-card-body">
    <div class="health-widget">
      <div class="health-dot unknown" id="health-dot"></div>
      <div class="health-info">
        <div class="health-name" id="health-status-text">Forecasting Engine — checking…</div>
        <div class="health-meta">pos-ml-api.onrender.com &bull; latency: <span id="health-latency">—</span></div>
      </div>
    </div>
  </div>
</div>

<!-- Quick links -->
<div class="pa-card">
  <div class="pa-card-header">
    <span class="pa-card-title"> Quick actions</span>
  </div>
  <div class="pa-card-body flex gap-3" style="flex-wrap:wrap">
    <a href="/platform-admin/stores?status=pending_approval" class="btn btn-amber">
       Review Pending (<?= $e($counts['pending_approval']) ?>)
    </a>
    <a href="/platform-admin/stores?status=active" class="btn btn-emerald">
       All Active Stores
    </a>
    <a href="/platform-admin/users" class="btn btn-ghost">
       User Inspector
    </a>
  </div>
</div>
