<?php
/** @var array $counts @var int $sessions @var array $volume @var array $analytics @var string $csrf @var array $admin */
use ProCast\Support\View;
$e  = [View::class, 'e'];
$fn = fn($n) => number_format((float)$n, 2);

/* Each analytics section is optional by design: an unsupported column or a failing
   query yields ['available' => false] for that card alone. Defaulting here keeps
   this view renderable from the tests, which pass only counts/sessions/volume. */
$analytics = $analytics ?? [];
$trend   = $analytics['trend']      ?? [];
$summary = $analytics['summary']    ?? [];
$tops    = $analytics['top_stores'] ?? [];
$splits  = $analytics['splits']     ?? [];
$days    = $trend['days'] ?? [];
/* Peak bar drives the chart scale; guard the empty case so a fresh install with
   no sales renders an empty track rather than dividing by zero. */
$peak = 0.0;
foreach ($days as $d) {
    $peak = max($peak, (float)($d['total'] ?? 0));
}
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

<!-- Revenue trend (last 14 days) -->
<?php if (($trend['available'] ?? false) === true): ?>
<div class="pa-card mb-6">
  <div class="pa-card-header">
    <span class="pa-card-title"> Revenue Trend</span>
    <span class="text-muted" style="font-size:.8rem">last <?= $e(count($days)) ?> days</span>
  </div>
  <div class="pa-card-body">
    <div class="pa-trend" role="img"
         aria-label="Daily net revenue for the last <?= $e(count($days)) ?> days">
      <?php foreach ($days as $d):
        $total = (float)($d['total'] ?? 0);
        /* Height as a percentage of the peak. Inline style is the only thing that
           can express a data-driven bar height, so it stays here deliberately. */
        $h = $peak > 0 ? max(2, (int)round($total / $peak * 100)) : 2;
      ?>
      <div class="pa-trend-col">
        <div class="pa-trend-bar<?= $total > 0 ? '' : ' is-zero' ?>"
             style="height:<?= $h ?>%"
             title="<?= $e($d['date']) ?> — ₱<?= $fn($total) ?> (<?= $e($d['count'] ?? 0) ?> orders)"></div>
        <span class="pa-trend-label"><?= $e(substr((string)$d['date'], 5)) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Period summary + top stores -->
<?php if (($summary['available'] ?? false) === true || ($tops['available'] ?? false) === true): ?>
<div class="grid-2 mb-6">
  <?php if (($summary['available'] ?? false) === true): ?>
  <div class="pa-card">
    <div class="pa-card-header">
      <span class="pa-card-title"> Period Summary</span>
    </div>
    <div class="pa-card-body">
      <dl class="pa-metrics">
        <div><dt>Orders</dt><dd><?= $e($summary['count']) ?></dd></div>
        <div><dt>Net Revenue</dt><dd>₱<?= $fn($summary['total']) ?></dd></div>
        <div><dt>Avg Order Value</dt><dd>₱<?= $fn($summary['aov']) ?></dd></div>
        <div><dt>Void Rate</dt><dd><?= $fn((float)$summary['void_rate'] * 100) ?>%</dd></div>
      </dl>
    </div>
  </div>
  <?php endif; ?>
  <?php if (($tops['available'] ?? false) === true): ?>
  <div class="pa-card">
    <div class="pa-card-header">
      <span class="pa-card-title"> Top Stores</span>
      <span class="text-muted" style="font-size:.8rem">by net revenue</span>
    </div>
    <div class="pa-card-body">
      <?php $topList = $tops['stores'] ?? []; ?>
      <?php if ($topList === []): ?>
        <p class="text-muted">No sales in this window yet.</p>
      <?php else: ?>
        <ul class="pa-rank">
          <?php foreach ($topList as $s): ?>
          <li>
            <a href="/platform-admin/stores/<?= $e($s['id']) ?>" class="pa-rank-name"><?= $e($s['name']) ?></a>
            <span class="text-muted"><?= $e($s['count']) ?> orders</span>
            <span class="pa-rank-val">₱<?= $fn($s['total']) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

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
