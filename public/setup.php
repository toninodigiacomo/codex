<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/AppLog.php';
AppLog::bootstrap();
require_once __DIR__ . '/../src/Asset.php';
require_once __DIR__ . '/../src/Totp.php';
require_once __DIR__ . '/../src/I18n.php';
require_once __DIR__ . '/../src/Theme.php';

Auth::bootSession();
I18n::boot();
// These auth pages iterate quickly during setup/testing — an explicit
// no-store beats letting a browser (mobile Safari especially) silently
// keep serving a stale copy after a real fix has already shipped.
header('Cache-Control: no-store, must-revalidate');

if (Auth::isSetupComplete()) {
    header('Location: /login.php');
    exit;
}

if (empty($_SESSION['pending_secret'])) {
    $_SESSION['pending_secret'] = Totp::generateSecret();
}
$secret = $_SESSION['pending_secret'];

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['regenerate'])) {
        $_SESSION['pending_secret'] = Totp::generateSecret();
        header('Location: /setup.php');
        exit;
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');
    $code = (string) ($_POST['totp_code'] ?? '');

    if ($username === '' || strlen($username) < 3) {
        $error = t('setup.error_username');
    } elseif (strlen($password) < 12) {
        $error = t('setup.error_password_length');
    } elseif ($password !== $confirm) {
        $error = t('setup.error_password_mismatch');
    } elseif (!Totp::verify($secret, $code)) {
        $error = t('setup.error_totp');
    } else {
        Auth::completeSetup($username, $password, $secret);
        unset($_SESSION['pending_secret']);
        header('Location: /login.php?setup=1');
        exit;
    }
}

$issuer = 'Codex';
$uri = Totp::provisioningUri($secret, 'admin', $issuer);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" data-theme="<?= htmlspecialchars(Theme::current()) ?>">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?= htmlspecialchars(t('setup.title')) ?></title>
<link rel="stylesheet" href="<?= asset('css/style.css') ?>" />
<style>
  body { margin: 0; }
  .setup-wrap { max-width: 480px; margin: 0 auto; padding: 48px 20px 64px; }
  .setup-wrap h1 { font-size: 26px; margin-bottom: 4px; }
  .setup-wrap .lead { margin-bottom: 32px; }
  .setup-card {
    background: var(--color-surface); border-radius: var(--radius-lg);
    padding: 24px 26px; margin-bottom: 20px; box-shadow: var(--shadow-sm);
  }
  .setup-card h2 { font-size: 16px; margin-bottom: 4px; }
  .setup-card .hint { font-size: 13px; margin: -2px 0 16px; }
  .qr-holder { background: #fff; padding: 16px; border-radius: var(--radius-md); display: inline-block; margin-bottom: 6px; }
  .qr-holder svg { display: block; width: 180px; height: 180px; }
  .manual-key { margin-top: 12px; }
  .manual-key summary { cursor: pointer; font-size: 13px; font-weight: 600; color: var(--color-accent); margin-bottom: 8px; }
  .show-pw-toggle { display: flex; align-items: center; gap: 8px; font-size: 13px; margin-top: -4px; }
  .show-pw-toggle input { width: auto; }
  .field-stack { display: flex; flex-direction: column; gap: 14px; }
  .error-box { background: color-mix(in srgb, #ff3b3b 15%, var(--color-surface)); color: #ff8a8a; padding: 10px 14px; border-radius: var(--radius-md); font-size: 13.5px; font-weight: 600; margin-bottom: 16px; }
</style>
</head>
<body>

<div class="setup-wrap">
  <h1><?= htmlspecialchars(t('setup.welcome')) ?></h1>
  <p class="text-muted lead"><?= htmlspecialchars(t('setup.no_account_yet')) ?></p>

  <div class="setup-card">
    <h2><?= htmlspecialchars(t('setup.step1_title')) ?></h2>
    <p class="text-muted hint"><?= htmlspecialchars(t('setup.step1_hint')) ?></p>
    <div class="qr-holder" id="qrHolder"></div>
    <details class="manual-key">
      <summary><?= htmlspecialchars(t('setup.manual_entry')) ?></summary>
      <div class="field">
        <label><?= htmlspecialchars(t('setup.secret_key')) ?></label>
        <input class="input" type="text" readonly value="<?= htmlspecialchars($secret) ?>" onclick="this.select()" style="font-family:monospace;letter-spacing:0.04em;" />
      </div>
      <div class="field" style="margin-top:10px;">
        <label><?= htmlspecialchars(t('setup.account_name')) ?></label>
        <input class="input" type="text" readonly value="<?= htmlspecialchars($issuer) ?>:admin" onclick="this.select()" />
      </div>
    </details>
    <form method="post" style="margin-top:14px;">
      <button type="submit" name="regenerate" value="1" class="btn btn-secondary" onclick="return confirm(<?= htmlspecialchars(json_encode(t('setup.confirm_regenerate')), ENT_QUOTES) ?>)"><?= htmlspecialchars(t('setup.regenerate_key')) ?></button>
    </form>
  </div>

  <div class="setup-card">
    <h2><?= htmlspecialchars(t('setup.step2_title')) ?></h2>
    <?php if ($error): ?>
      <div class="error-box"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post" class="field-stack">
      <div class="field">
        <label for="username"><?= htmlspecialchars(t('login.username')) ?></label>
        <input class="input" type="text" id="username" name="username" required minlength="3" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" />
      </div>
      <div class="field">
        <label for="password"><?= htmlspecialchars(t('setup.password_min')) ?></label>
        <input class="input" type="password" id="password" name="password" required minlength="12" autocomplete="new-password" />
      </div>
      <div class="field">
        <label for="password_confirm"><?= htmlspecialchars(t('setup.confirm_password')) ?></label>
        <input class="input" type="password" id="password_confirm" name="password_confirm" required minlength="12" autocomplete="new-password" />
      </div>
      <label class="show-pw-toggle">
        <input type="checkbox" id="showPasswords" /> <?= htmlspecialchars(t('setup.show_passwords')) ?>
      </label>
      <div class="field">
        <label for="totp_code"><?= htmlspecialchars(t('setup.totp_from_app')) ?></label>
        <input class="input" type="text" id="totp_code" name="totp_code" required pattern="\d{6}" inputmode="numeric" autocomplete="one-time-code" />
      </div>
      <button type="submit" class="btn btn-primary btn-block"><?= htmlspecialchars(t('setup.activate')) ?></button>
    </form>
  </div>
  <p style="margin-top:18px;font-size:12.5px;opacity:0.6;">
    <a href="#" data-lang-switch="fr" style="<?= I18n::locale() === 'fr' ? 'font-weight:700;' : '' ?>">Français</a>
    &nbsp;·&nbsp;
    <a href="#" data-lang-switch="en" style="<?= I18n::locale() === 'en' ? 'font-weight:700;' : '' ?>">English</a>
  </p>
</div>

<script>window.I18N = <?= json_encode(I18n::all(), JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="<?= asset('js/i18n.js') ?>"></script>
<script src="vendor/qrcode.js"></script>
<script>
  var qr = qrcode(0, 'M');
  qr.addData(<?= json_encode($uri) ?>);
  qr.make();
  document.getElementById('qrHolder').innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2 });

  document.getElementById('showPasswords').addEventListener('change', function () {
    var type = this.checked ? 'text' : 'password';
    document.getElementById('password').type = type;
    document.getElementById('password_confirm').type = type;
  });
</script>

</body>
</html>
