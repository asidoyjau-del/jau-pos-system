<?php
/** @var string|null $error @var string $csrf @var bool $twoFactor */
use ProCast\Support\Env;
use ProCast\Support\View;
$e = [View::class, 'e'];
// Convenience only: prefill the owner email so signing in is a single step.
$defaultEmail = Env::superAdminEnvEmail();
$twoFactor = $twoFactor ?? false;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Sign In — ProCast Platform</title>
<link rel="stylesheet" href="/platform-admin/assets/admin.css">
</head>
<body>
<div class="pa-login-wrap">
  <div class="pa-login-box">
    <div class="pa-login-logo">
      <div class="pa-login-icon"><?=$e(View::icon('shield', 'pa-ico-xl')) ?></div>
      <div class="pa-login-title">Platform Admin</div>
      <div class="pa-login-sub">ProCast Super Admin Portal</div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error" role="alert"> <?= $e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="/platform-admin/login" autocomplete="off" novalidate id="login-form">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
      <div class="form-group">
        <label class="form-label" for="login-email">Email address</label>
        <input id="login-email" type="email" name="email" class="form-control"
               value="<?= $e($defaultEmail) ?>"
               placeholder="admin@procast.app" autocomplete="username" required autofocus>
      </div>
      <div class="form-group">
        <label class="form-label" for="login-password">Password</label>
        <input id="login-password" type="password" name="password" class="form-control"
               placeholder="••••••••••" autocomplete="current-password" required>
      </div>
      <button type="submit" class="btn btn-primary w-full" id="btn-login">
        Sign in →
      </button>
    </form>

    <?php if ($twoFactor): ?>
    <p class="text-muted" style="text-align:center;margin-top:20px;font-size:.72rem">
      Two-factor authentication required on the next step.
    </p>
    <?php endif; ?>
  </div>
</div>
<script src="/platform-admin/assets/admin.js"></script>
</body>
</html>
