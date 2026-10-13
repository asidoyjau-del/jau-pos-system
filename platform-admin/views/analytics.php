<?php
/**
 * @var array $counts @var int $sessions @var array $volume @var array $analytics
 * @var array|null $store @var int|null $storeId @var array $stores @var int $days @var int $now
 * @var string $csrf @var array $admin
 */
use ProCast\Support\View;
$e  = [View::class, 'e'];
$ico = static fn (string $n, string $c = ''): string => View::icon($n, $c);
$fn = fn($n) => number_format((float)$n, 2);

$stores  = $stores ?? [];
$storeId = $storeId ?? null;
$store   = $store  ?? null;
$days    = (int)($days ?? 14);

$analytics = $analytics ?? [];
$trend   = $analytics['trend']      ?? [];
$summary = $analytics['summary']    ?? [];
$tops    = $analytics['top_stores'] ?? [];
$splits  = $analytics['splits']     ?? [];
$dayList = $trend['days'] ?? [];

$peak = 0.0;
$totalTrendRev = 0.0;
$totalTrendOrders = 0;
foreach ($dayList as $d) {
    $val = (float)($d['total'] ?? 0);
    $peak = max($peak, $val);
    $totalTrendRev += $val;
    $totalTrendOrders += (int)($d['count'] ?? 0);
}
$dayCount = count($dayList) ?: $days;
$avgDailyRev = $dayCount > 0 ? $totalTrendRev / $dayCount : 0.0;
?>

<!-- Scope & Period selector -->
<div class="pa-card mb-6">
  <div class="pa-card-header" style="flex-wrap:wrap;gap:12px">
    <div style="display:flex;align-items:center;gap:10px">
      <span class="pa-card-title"><?= $ico('bar-chart') ?> Sales Analytics</span>
      <?php if ($store !== null): ?>
        <span class="badge badge-active" data-scope-store="<?= (int)$storeId ?>">
          <?= $e((string)(($store['display_name'] ?: $store['name']) ?: ('Store #' . (int)$storeId))) ?>
        </span>
      <?php else: ?>
        <span class="badge" data-scope-store="all">Platform-wide</span>
      <?php endif; ?>
    </div>
    <!-- Time range selector -->
    <div class="pa-range-pills">
      <span class="text-muted" style="font-size:.78rem;font-weight:600">Period:</span>
      <?php foreach ([7 => '7D', 14 => '14D', 30 => '30D', 60 => '60D', 90 => '90D'] as $dVal => $dLabel): ?>
        <a href="/platform-admin/analytics?days=<?= $dVal ?><?= $storeId !== null ? '&store=' . (int)$storeId : '' ?>"
           class="pa-range-pill <?= $days === $dVal ? 'active' : '' ?>">
          <?= $dLabel ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="pa-card-body" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
    <form method="GET" action="/platform-admin/analytics" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="days" value="<?= (int)$days ?>">
      <label class="text-muted" for="analytics-store" style="font-weight:500">Filter Store:</label>
      <select id="analytics-store" name="store" onchange="this.form.submit()"
              style="padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:var(--bg-2);color:var(--text-1);min-width:240px">
        <option value="">All stores (Platform roll-up)</option>
        <?php foreach ($stores as $s): ?>
          <?php $sid = (int)$s['id']; ?>
          <option value="<?= $sid ?>" <?= $sid === $storeId ? 'selected' : '' ?>>
            <?= $e((string)(($s['display_name'] ?: $s['name']) ?: ('Store #' . $sid))) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit" class="btn btn-sm">Apply</button></noscript>
    </form>
    <?php if ($store !== null): ?>
      <a href="/platform-admin/analytics?days=<?= (int)$days ?>" class="btn btn-ghost btn-sm">View all stores</a>
    <?php endif; ?>
    <div style="margin-left:auto;display:flex;gap:8px">
      <a href="/platform-admin/monitoring<?= $storeId !== null ? '?store=' . (int)$storeId : '' ?>" class="btn btn-ghost btn-sm">
        <?= $ico('activity') ?> Live Monitoring
      </a>
    </div>
  </div>
</div>

<!-- Headline KPI Tiles -->
<div class="pa-stats">
  <div class="pa-stat emerald">
    <div class="pa-stat-label">Window Revenue</div>
    <div class="pa-stat-value">₱<?= $fn($summary['total'] ?? $totalTrendRev) ?></div>
    <div class="pa-stat-sub">net sales (last <?= (int)$days ?> days)</div>
  </div>
  <div class="pa-stat accent">
    <div class="pa-stat-label">Total Transactions</div>
    <div class="pa-stat-value"><?= $e($summary['count'] ?? $totalTrendOrders) ?></div>
    <div class="pa-stat-sub">completed orders</div>
  </div>
  <div class="pa-stat amber">
    <div class="pa-stat-label">Avg Order Value</div>
    <div class="pa-stat-value">₱<?= $fn($summary['aov'] ?? 0) ?></div>
    <div class="pa-stat-sub">per customer receipt</div>
  </div>
  <div class="pa-stat <?= ((float)($summary['void_rate'] ?? 0) > 0.05) ? 'rose' : 'accent' ?>">
    <div class="pa-stat-label">Void Rate</div>
    <div class="pa-stat-value"><?= $fn(((float)($summary['void_rate'] ?? 0)) * 100) ?>%</div>
    <div class="pa-stat-sub"><?= $e($summary['voided'] ?? 0) ?> voided / cancelled</div>
  </div>
  <div class="pa-stat accent">
    <div class="pa-stat-label">Daily Average</div>
    <div class="pa-stat-value">₱<?= $fn($avgDailyRev) ?></div>
    <div class="pa-stat-sub">peak: ₱<?= $fn($peak) ?></div>
  </div>
</div>

<!-- Revenue & Orders Trend Chart -->
<?php if (($trend['available'] ?? false) === true): ?>
<div class="pa-card mb-6">
  <div class="pa-card-header">
    <div>
      <span class="pa-card-title"><?= $ico('chart') ?> Daily Net Revenue Trend</span>
      <span class="text-muted" style="margin-left:8px;font-size:.8rem">last <?= $e(count($dayList)) ?> days</span>
    </div>
    <div class="text-muted" style="font-size:.8rem">Peak: <strong style="color:var(--emerald)">₱<?= $fn($peak) ?></strong></div>
  </div>
  <div class="pa-card-body">
    <div class="pa-trend" role="img" aria-label="Daily net revenue chart">
      <?php foreach ($dayList as $d):
        $tot = (float)($d['total'] ?? 0);
        $h = $peak > 0 ? max(2, (int)round($tot / $peak * 100)) : 2;
      ?>
      <div class="pa-trend-col">
        <div class="pa-trend-bar<?= $tot > 0 ? '' : ' is-zero' ?>"
             style="height:<?= $h ?>%"
             title="<?= $e($d['date']) ?>: ₱<?= $fn($tot) ?> (<?= $e($d['count'] ?? 0) ?> orders)"></div>
        <span class="pa-trend-label"><?= $e(substr((string)$d['date'], 5)) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Payment Methods & Order Types -->
<div class="grid-2 mb-6">
  <!-- Payment methods split -->
  <div class="pa-card">
    <div class="pa-card-header">
      <span class="pa-card-title"><?= $ico('bar-chart') ?> Payment Methods</span>
      <span class="text-muted" style="font-size:.8rem">last <?= (int)$days ?> days</span>
    </div>
    <div class="pa-card-body">
      <?php
      $pm = $splits['payment_methods'] ?? null;
      if ($pm === null):
      ?>
        <p class="text-muted">Payment method breakdown is not enabled on this schema.</p>
      <?php elseif ($pm === []): ?>
        <p class="text-muted">No transaction records in this time period.</p>
      <?php else:
        $pmSum = array_sum(array_column($pm, 'total')) ?: 1.0;
      ?>
        <ul class="pa-split-list">
          <?php foreach ($pm as $item):
            $pct = round(($item['total'] / $pmSum) * 100, 1);
          ?>
          <li class="pa-split-item">
            <div class="pa-split-header">
              <span class="pa-split-label"><?= $e($item['label']) ?></span>
              <span class="pa-split-val">₱<?= $fn($item['total']) ?></span>
            </div>
            <div class="pa-split-sub">
              <span><?= $e($item['count']) ?> orders</span>
              <span><?= $pct ?>% share</span>
            </div>
            <div class="pa-progress-wrap">
              <div class="pa-progress-bar" style="width:<?= $pct ?>%"></div>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>

  <!-- Order types split -->
  <div class="pa-card">
    <div class="pa-card-header">
      <span class="pa-card-title"><?= $ico('pie-chart') ?> Order Types</span>
      <span class="text-muted" style="font-size:.8rem">last <?= (int)$days ?> days</span>
    </div>
    <div class="pa-card-body">
      <?php
      $ot = $splits['order_types'] ?? null;
      if ($ot === null):
      ?>
        <p class="text-muted">Order type breakdown is not enabled on this schema.</p>
      <?php elseif ($ot === []): ?>
        <p class="text-muted">No transaction records in this time period.</p>
      <?php else:
        $otSum = array_sum(array_column($ot, 'total')) ?: 1.0;
      ?>
        <ul class="pa-split-list">
          <?php foreach ($ot as $item):
            $pct = round(($item['total'] / $otSum) * 100, 1);
          ?>
          <li class="pa-split-item">
            <div class="pa-split-header">
              <span class="pa-split-label"><?= $e($item['label']) ?></span>
              <span class="pa-split-val">₱<?= $fn($item['total']) ?></span>
            </div>
            <div class="pa-split-sub">
              <span><?= $e($item['count']) ?> orders</span>
              <span><?= $pct ?>% share</span>
            </div>
            <div class="pa-progress-wrap">
              <div class="pa-progress-bar emerald" style="width:<?= $pct ?>%"></div>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Leaderboard & Period Insights -->
<div class="grid-2 mb-6">
  <!-- Top Performing Stores or Store Profile -->
  <div class="pa-card">
    <div class="pa-card-header">
      <span class="pa-card-title"><?= $ico('store') ?> <?= $store === null ? 'Top Performing Stores' : 'Store Performance' ?></span>
      <span class="text-muted" style="font-size:.8rem">by net revenue</span>
    </div>
    <div class="pa-card-body">
      <?php if ($store === null): ?>
        <?php $topList = $tops['stores'] ?? []; ?>
        <?php if ($topList === []): ?>
          <p class="text-muted">No sales in this window yet.</p>
        <?php else: ?>
          <ul class="pa-rank">
            <?php foreach ($topList as $idx => $s): ?>
            <li>
              <span class="badge <?= $idx === 0 ? 'badge-active' : '' ?>" style="margin-right:6px;font-size:.7rem">#<?= $idx + 1 ?></span>
              <a href="/platform-admin/analytics?store=<?= $e($s['id']) ?>&days=<?= (int)$days ?>" class="pa-rank-name">
                <?= $e($s['name']) ?>
              </a>
              <span class="text-muted"><?= $e($s['count']) ?> orders</span>
              <span class="pa-rank-val">₱<?= $fn($s['total']) ?></span>
            </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      <?php else: ?>
        <dl class="pa-metrics">
          <div><dt>Store Name</dt><dd><?= $e((string)($store['display_name'] ?: $store['name'])) ?></dd></div>
          <div><dt>Account Tier</dt><dd><span class="badge badge-active"><?= $e(strtoupper((string)($store['subscription_tier'] ?? 'FREE'))) ?></span></dd></div>
          <div><dt>Today's Volume</dt><dd>₱<?= $fn($volume['today']['total'] ?? 0) ?></dd></div>
          <div><dt>Month-to-Date</dt><dd>₱<?= $fn($volume['month']['total'] ?? 0) ?></dd></div>
        </dl>
        <div style="margin-top:16px">
          <a href="/platform-admin/stores/<?= (int)$store['id'] ?>" class="btn btn-ghost btn-sm">
            <?= $ico('eye') ?> View Store Settings & Tills
          </a>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Performance Summary -->
  <div class="pa-card">
    <div class="pa-card-header">
      <span class="pa-card-title"><?= $ico('activity') ?> Period Summary Breakdown</span>
    </div>
    <div class="pa-card-body">
      <?php if (($summary['available'] ?? false) === true): ?>
        <dl class="pa-metrics">
          <div><dt>Completed Orders</dt><dd><?= $e($summary['count']) ?></dd></div>
          <div><dt>Net Revenue</dt><dd>₱<?= $fn($summary['total']) ?></dd></div>
          <div><dt>Average Order Value</dt><dd>₱<?= $fn($summary['aov']) ?></dd></div>
          <div><dt>Void Orders</dt><dd><?= $e($summary['voided'] ?? 0) ?></dd></div>
        </dl>
        <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
          <span class="text-muted">Void Rate:</span>
          <span class="badge <?= ((float)$summary['void_rate'] > 0.05) ? 'badge-pending' : '' ?>">
            <?= $fn((float)$summary['void_rate'] * 100) ?>%
          </span>
        </div>
      <?php else: ?>
        <p class="text-muted">Summary metrics are unavailable for the current period.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Navigation & Quick actions -->
<div class="pa-card">
  <div class="pa-card-header">
    <span class="pa-card-title"><?= $ico('zap') ?> Quick Actions</span>
  </div>
  <div class="pa-card-body flex gap-3" style="flex-wrap:wrap">
    <a href="/platform-admin/monitoring<?= $storeId !== null ? '?store=' . (int)$storeId : '' ?>" class="btn btn-ghost">
      <?= $ico('activity') ?> Monitoring Dashboard
    </a>
    <a href="/platform-admin/stores" class="btn btn-ghost">
      <?= $ico('store') ?> Manage Stores
    </a>
    <a href="/platform-admin/users" class="btn btn-ghost">
      <?= $ico('users') ?> User Inspector
    </a>
  </div>
</div>
