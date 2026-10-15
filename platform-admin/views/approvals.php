<?php
/** @var list<array<string,mixed>> $pendingStores @var list<array<string,mixed>> $pendingStaff @var string $q @var int $now @var string $csrf */
use ProCast\Support\View;
$e = [View::class, 'e'];
$ico = static fn (string $n, string $c = ''): string => View::icon($n, $c);

$staffCount = count($pendingStaff);
$storeCount = count($pendingStores);
$totalPending = $staffCount + $storeCount;
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
  <div>
    <h2 style="margin:0 0 4px;font-size:1.35rem;font-weight:700;">Approvals & Verification Hub</h2>
    <div class="text-muted" style="font-size:0.85rem;">Review pending staff applications and store onboarding requests.</div>
  </div>
  <form method="GET" action="/platform-admin/approvals" style="display:flex;gap:8px;align-items:center">
    <div class="pa-search-wrap">
      <span class="pa-search-icon"><?= $ico('search') ?></span>
      <input type="text" name="q" class="form-control" value="<?= $e($q) ?>"
             placeholder="Search applicant or store…" autocomplete="off" style="width:260px">
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Search</button>
  </form>
</div>

<!-- SECTION 1: STAFF & CASHIER APPROVALS -->
<div class="pa-card" style="margin-bottom:24px;">
  <div class="pa-card-header">
    <div style="display:flex;align-items:center;gap:10px;">
      <span class="pa-card-title"><?= $ico('users') ?> Staff & Cashier Approvals</span>
      <span class="badge badge-pending"><?= $staffCount ?> Pending</span>
    </div>
  </div>
  <div class="pa-table-wrap">
    <table class="pa-table">
      <thead>
        <tr>
          <th>Applicant</th>
          <th>Role</th>
          <th>Assigned Store</th>
          <th>Owner Contact</th>
          <th>Registered</th>
          <th style="text-align:right">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($pendingStaff)): ?>
        <tr>
          <td colspan="6" class="text-muted" style="text-align:center;padding:32px">
            <?= $ico('check', 'text-emerald') ?> No pending staff applications waiting for review.
          </td>
        </tr>
      <?php else: foreach ($pendingStaff as $s): ?>
        <tr>
          <td>
            <div class="store-name" style="font-weight:600;"><?= $e($s['full_name'] ?: $s['username']) ?></div>
            <div class="text-muted font-mono" style="font-size:0.75rem;">@<?= $e($s['username']) ?></div>
            <?php if (!empty($s['email'])): ?>
            <div class="text-muted" style="font-size:0.75rem;"><?= $e($s['email']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge badge-client app"><?= $e(ucfirst((string)($s['role'] ?? 'staff'))) ?></span>
          </td>
          <td>
            <?php if (!empty($s['store_name'])): ?>
            <a href="/platform-admin/stores/<?= $e($s['store_id']) ?>" style="font-weight:500;">
              <?= $e($s['display_store_name'] ?: $s['store_name']) ?>
            </a>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td>
            <div style="font-size:0.85rem;"><?= $e($s['owner_name'] ?? '—') ?></div>
            <?php if (!empty($s['owner_email'])): ?>
            <div class="text-muted" style="font-size:0.72rem;"><?= $e($s['owner_email']) ?></div>
            <?php endif; ?>
          </td>
          <td class="text-muted" style="font-size:0.8rem;">
            <?= $e(date('M j, Y g:ia', strtotime((string)$s['created_at']))) ?>
          </td>
          <td style="text-align:right">
            <div style="display:inline-flex;gap:6px;align-items:center;">
              <!-- One-Click Approve Form -->
              <form method="POST" action="/platform-admin/users/<?= $e($s['id']) ?>/approve" style="display:inline;">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                <button type="submit" class="btn btn-primary btn-sm" title="Approve account & issue 6-digit access code">
                  <?= $ico('check') ?> Approve
                </button>
              </form>
              <!-- Reject Form Button with inline prompt -->
              <form method="POST" action="/platform-admin/users/<?= $e($s['id']) ?>/reject" style="display:inline;"
                    onsubmit="const r = prompt('Reason for rejecting <?= $e($s['username']) ?>:'); if (!r) return false; this.reason.value = r;">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="reason" value="">
                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--rose,#e11d48);" title="Reject application">
                  <?= $ico('x') ?> Reject
                </button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- SECTION 2: STORE ONBOARDING APPROVALS -->
<div class="pa-card">
  <div class="pa-card-header">
    <div style="display:flex;align-items:center;gap:10px;">
      <span class="pa-card-title"><?= $ico('store') ?> Pending Store Registrations</span>
      <span class="badge badge-pending"><?= $storeCount ?> Pending</span>
    </div>
    <div style="margin-left:auto;">
      <a href="/platform-admin/stores?status=pending_approval" class="btn btn-ghost btn-sm">View full store list &rarr;</a>
    </div>
  </div>
  <div class="pa-table-wrap">
    <table class="pa-table">
      <thead>
        <tr>
          <th>Store</th>
          <th>Owner</th>
          <th>Tier</th>
          <th>Client</th>
          <th>Registered</th>
          <th style="text-align:right">Review</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($pendingStores)): ?>
        <tr>
          <td colspan="6" class="text-muted" style="text-align:center;padding:32px">
            <?= $ico('check', 'text-emerald') ?> No pending store registrations waiting for review.
          </td>
        </tr>
      <?php else: foreach ($pendingStores as $st): ?>
        <tr>
          <td>
            <a href="/platform-admin/stores/<?= $e($st['id']) ?>" class="store-name" style="font-weight:600;">
              <?= $e($st['display_name'] ?: $st['name']) ?>
            </a>
            <?php if (!empty($st['address'])): ?>
            <div class="text-muted" style="font-size:0.75rem;"><?= $e($st['address']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <div><?= $e($st['display_owner'] ?? '—') ?></div>
            <div class="text-muted font-mono" style="font-size:0.72rem;"><?= $e($st['display_email'] ?? '') ?></div>
          </td>
          <td>
            <span class="badge badge-client"><?= $e(strtoupper((string)($st['subscription_tier'] ?? 'standard'))) ?></span>
          </td>
          <td>
            <span class="badge-client"><?= $e(ucfirst((string)($st['client_type'] ?? 'web'))) ?></span>
          </td>
          <td class="text-muted" style="font-size:0.8rem;">
            <?= $e(date('M j, Y g:ia', strtotime((string)($st['registered_at'] ?: $st['created_at'])))) ?>
          </td>
          <td style="text-align:right">
            <a href="/platform-admin/stores/<?= $e($st['id']) ?>" class="btn btn-primary btn-sm">
              Review Application &rarr;
            </a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
