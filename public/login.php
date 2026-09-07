<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/AppLog.php';
AppLog::bootstrap();
require_once __DIR__ . '/../src/Asset.php';
require_once __DIR__ . '/../src/I18n.php';

Auth::bootSession();
I18n::boot();

if (!Auth::isSetupComplete()) {
    header('Location: /setup.php');
    exit;
}
if (Auth::isLoggedIn()) {
    header('Location: ' . (Auth::isAdmin() ? '/admin.php' : '/library.php'));
    exit;
}

$error = null;
$justSetUp = isset($_GET['setup']);
$justWelcomed = isset($_GET['welcome']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::isLockedOut()) {
        $wait = (int) ceil(Auth::secondsUntilUnlock() / 60);
        $error = t('login.error_locked_out', ['minutes' => $wait]);
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $code = (string) ($_POST['totp_code'] ?? '');
        $remember = isset($_POST['remember']);

        $result = Auth::attemptLogin($username, $password, $code, $remember);
        if ($result === 'ok') {
            header('Location: ' . (Auth::isAdmin() ? '/admin.php' : '/library.php'));
            exit;
        }
        if ($result === 'mfa_setup_required') {
            header('Location: /mfa-setup.php');
            exit;
        }
        $error = t('login.error_invalid');
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?= htmlspecialchars(t('login.title')) ?></title>
<link rel="stylesheet" href="<?= asset('css/style.css') ?>" />
<style>
  body { margin: 0; }
  .split { display: grid; grid-template-columns: minmax(360px, 44%) 1fr; min-height: 100vh; }
  @media (max-width: 860px) { .split { grid-template-columns: 1fr; } }

  .pitch {
    background:
      radial-gradient(120% 90% at 15% 100%, color-mix(in srgb, var(--color-accent) 30%, transparent), transparent 60%),
      var(--color-neutral-900);
    color: var(--color-neutral-100);
    padding: 34px 34px 27px; display: flex; flex-direction: column;
  }
  /* Logo pinned at its natural top position; this second (and now only
     other) child grows to fill the rest of the panel and centers its own
     content within that space — same "logo top, pitch centered" feel as
     when a third block (the old spec-row) sat at the bottom keeping the
     middle one roughly centered by itself; removing that block without
     this would leave just two items pinned to opposite ends instead. */
  .pitch > div:last-child { flex: 1; display: flex; flex-direction: column; justify-content: center; }
  .pitch-brand { display: flex; align-items: baseline; gap: 9px; }
  .pitch-brand .name { font-family: var(--font-heading); font-weight: 900; font-size: 22px; letter-spacing: -0.02em; }
  .pitch-brand .sub { font-size: 10px; font-weight: 600; letter-spacing: 0.14em; text-transform: uppercase; opacity: 0.55; }
  .pitch h1 { font-size: clamp(34px, 5vw, 56px); line-height: 1.02; letter-spacing: -0.03em; margin: 0; max-width: 460px; }
  .pitch p { margin: 20px 0 0; font-size: 14.5px; line-height: 1.6; opacity: 0.75; max-width: 340px; }
  .spec-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; font-size: 11px; line-height: 1.5; }
  .spec-row div { border-top: 1px solid rgba(242,242,240,0.16); padding-top: 8px; }
  .spec-row .k { opacity: 0.5; letter-spacing: 0.08em; text-transform: uppercase; font-size: 9.5px; font-weight: 600; }
  .spec-row .v { font-family: var(--font-heading); font-weight: 700; font-size: 15px; margin-top: 3px; }

  .form-side { display: flex; align-items: center; justify-content: center; padding: 40px 36px; }
  .form-wrap { width: 100%; max-width: 352px; }
  .step-label { margin: 0 0 10px; color: var(--color-accent); font-size: 13px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; }
  .form-wrap h2 { margin: 0 0 6px; }
  .form-wrap .lead { font-size: 13.5px; margin-bottom: 20px; }
  .field-stack { display: flex; flex-direction: column; gap: 12px; }
  .remember-row { display: flex; align-items: center; gap: 8px; font-size: 13px; }
  .remember-row input { width: auto; }
  .password-field { position: relative; }
  .password-field .input { padding-right: 42px; }
  .password-toggle {
    position: absolute; right: 4px; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer; font-size: 16px;
    padding: 6px 8px; line-height: 1; opacity: 0.6;
  }
  .password-toggle:hover { opacity: 1; }
  .error-box { background: color-mix(in srgb, #ff3b3b 15%, var(--color-surface)); color: #ff8a8a; padding: 10px 14px; border-radius: var(--radius-md); font-size: 13.5px; font-weight: 600; margin-bottom: 16px; }
  .success-box { background: color-mix(in srgb, #2fbf71 18%, var(--color-surface)); color: #7be3ab; padding: 10px 14px; border-radius: var(--radius-md); font-size: 13.5px; font-weight: 600; margin-bottom: 16px; }
</style>
</head>
<body>

<div class="split">
  <div class="pitch">
    <div class="pitch-brand">
      <span class="name"><?= htmlspecialchars(t('app.name')) ?></span>
      <span class="sub"><?= htmlspecialchars(t('login.pitch_sub')) ?></span>
    </div>
    <div>
      <h1><?= htmlspecialchars(t('login.pitch_h1')) ?></h1>
      <p><?= htmlspecialchars(t('login.pitch_p')) ?></p>
    </div>
  </div>

  <div class="form-side">
    <div class="form-wrap">
      <p class="step-label"><?= htmlspecialchars(t('login.step_label')) ?></p>
      <h2><?= htmlspecialchars(t('login.heading')) ?></h2>
      <p class="text-muted lead"><?= htmlspecialchars(t('login.lead')) ?></p>

      <?php if ($justSetUp && !$error): ?>
        <div class="success-box"><?= htmlspecialchars(t('login.success_setup')) ?></div>
      <?php elseif ($justWelcomed && !$error): ?>
        <div class="success-box"><?= htmlspecialchars(t('login.success_welcome')) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="error-box"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form class="field-stack" method="post">
        <div class="field">
          <label for="username"><?= htmlspecialchars(t('login.username')) ?></label>
          <input class="input" id="username" name="username" required autofocus />
        </div>
        <div class="field">
          <label for="password"><?= htmlspecialchars(t('login.password')) ?></label>
          <div class="password-field">
            <input class="input" id="password" name="password" type="password" required autocomplete="current-password" />
            <button type="button" class="password-toggle" id="passwordToggle" aria-label="<?= htmlspecialchars(t('login.password_show')) ?>" data-show-label="<?= htmlspecialchars(t('login.password_show')) ?>" data-hide-label="<?= htmlspecialchars(t('login.password_hide')) ?>">👁</button>
          </div>
        </div>
        <div class="field">
          <label for="totp_code"><?= htmlspecialchars(t('login.totp_label')) ?></label>
          <input class="input" id="totp_code" name="totp_code" pattern="\d{6}" inputmode="numeric" autocomplete="one-time-code" placeholder="<?= htmlspecialchars(t('login.totp_placeholder')) ?>" />
        </div>
        <label class="remember-row">
          <input type="checkbox" id="remember" name="remember" />
          <?= htmlspecialchars(t('login.remember')) ?>
        </label>
        <button type="submit" class="btn btn-primary btn-block"><?= htmlspecialchars(t('login.continue')) ?></button>
      </form>
      <p style="margin-top:18px;font-size:12.5px;opacity:0.6;">
        <a href="#" data-lang-switch="fr" style="<?= I18n::locale() === 'fr' ? 'font-weight:700;' : '' ?>">Français</a>
        &nbsp;·&nbsp;
        <a href="#" data-lang-switch="en" style="<?= I18n::locale() === 'en' ? 'font-weight:700;' : '' ?>">English</a>
      </p>
    </div>
  </div>
</div>

<script>window.I18N = <?= json_encode(I18n::all(), JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="<?= asset('js/i18n.js') ?>"></script>
<script>
  document.getElementById('passwordToggle').addEventListener('click', function () {
    var field = document.getElementById('password');
    var showing = field.type === 'text';
    field.type = showing ? 'password' : 'text';
    this.textContent = showing ? '👁' : '🙈';
    this.setAttribute('aria-label', showing ? this.dataset.showLabel : this.dataset.hideLabel);
  });
</script>

</body>
</html>
