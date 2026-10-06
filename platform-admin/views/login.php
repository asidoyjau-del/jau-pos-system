<?php
/** @var string|null $error @var string $csrf @var bool $twoFactor */
use ProCast\Support\Env;
use ProCast\Support\View;
$e = [View::class, 'e'];
// Icons are trusted markup from View::ICONS — must NOT be run through $e().
$ico = static fn (string $n, string $c = ''): string => View::icon($n, $c);
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
<link rel="icon" href="/platform-admin/assets/procast-logo.png" type="image/png">
<!-- Same pre-paint theme bootstrap as the main layout. The sign-in page has no
     admin.js, so this is deliberately self-contained. -->
<script src="/platform-admin/assets/theme.js"></script>
<link rel="stylesheet" href="/platform-admin/assets/admin.css">
</head>
<body>
  <!-- The login page renders outside the layout, so it needs its own toggle.
     Pinned to the viewport corner rather than placed inside the box, which would
     make it part of the credentials form. -->
  <button type="button" class="btn btn-ghost btn-sm pa-theme-toggle login-theme-toggle"
          data-theme-toggle aria-pressed="false"
          aria-label="Switch to light mode" title="Switch to light mode">
    <span class="pa-theme-icon is-light" aria-hidden="true"><?= $ico('sun') ?></span>
    <span class="pa-theme-icon is-dark" aria-hidden="true"><?= $ico('moon') ?></span>
  </button>
  <div class="pa-login-wrap">
    <div class="pa-login-box">
      <div class="pa-login-logo">
        <img src="/platform-admin/assets/procast-logo.png" class="pa-login-logo-img"
             width="96" height="96" alt="ProCast POS System" decoding="async">
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
