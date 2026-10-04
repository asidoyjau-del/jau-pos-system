<?php
/** @var string|null $error @var string $csrf @var bool $enrolling @var string $secret @var string $secretGrouped @var string $otpauth */
use ProCast\Support\View;
$e = [View::class, 'e'];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title><?= $e($title ?? 'Two-factor') ?> — ProCast Platform</title>
<link rel="stylesheet" href="/platform-admin/assets/admin.css">
</head>
<body>
<div class="pa-login-wrap">
  <div class="pa-login-box">
    <div class="pa-login-logo">
      <div class="pa-login-icon">🔐</div>
      <div class="pa-login-title"><?= $e($title ?? 'Two-factor verification') ?></div>
      <div class="pa-login-sub">
        <?php if ($enrolling): ?>Scan the QR code below with your authenticator app.<?php
        else: ?>Enter the 6-digit code from your authenticator app.<?php endif; ?>
      </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error" role="alert">⚠️ <?= $e($error) ?></div>
    <?php endif; ?>

    <?php if ($enrolling && $secret !== ''): ?>
    <div class="qr-area">
      <canvas id="qr-canvas" class="qr-canvas" width="160" height="160"></canvas>
      <div class="secret-code"><?= $e($secretGrouped) ?></div>
      <p class="text-muted" style="margin-top:8px;font-size:.72rem">Scan or enter the key above</p>
    </div>
    <input type="hidden" id="totp-otpauth" value="<?= $e($otpauth) ?>">
    <?php endif; ?>

    <form method="POST" action="/platform-admin/login/verify" autocomplete="off" novalidate id="totp-form">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <div class="form-group">
        <label class="form-label" for="totp-code">6-digit code</label>
        <input id="totp-code" type="text" name="code" class="form-control totp-code"
               pattern="[0-9]{6}" maxlength="6" inputmode="numeric" autocomplete="one-time-code"
               placeholder="000000" required autofocus>
      </div>
      <button type="submit" class="btn btn-primary w-full" id="btn-totp">
        <?= $enrolling ? 'Activate 2FA &amp; Sign in' : 'Verify &rarr;' ?>
      </button>
    </form>

    <p class="text-muted" style="text-align:center;margin-top:16px">
      <a href="/platform-admin/login">← Back to sign in</a>
    </p>
  </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SANKe6drU1VRm/OB23g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="/platform-admin/assets/admin.js"></script>
</body>
</html>
