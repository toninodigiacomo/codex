<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/AppLog.php';
AppLog::bootstrap();
require_once __DIR__ . '/../src/Asset.php';
require_once __DIR__ . '/../src/Users.php';
require_once __DIR__ . '/../src/Totp.php';
require_once __DIR__ . '/../src/I18n.php';

Auth::bootSession();
I18n::boot();

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$invitedUser = $token !== '' ? Users::findByInviteToken($token) : null;

if (!$invitedUser) {
    http_response_code(410);
    ?>
    <!DOCTYPE html><html lang="<?= htmlspecialchars(I18n::locale()) ?>"><head><meta charset="UTF-8"><title><?= htmlspecialchars(t('invite.invalid_title')) ?></title>
    <link rel="stylesheet" href="<?= asset('css/style.css') ?>"></head><body style="max-width:480px;margin:60px auto;padding:0 20px;">
    <h1><?= htmlspecialchars(t('invite.invalid_heading')) ?></h1>
    <p class="text-muted"><?= htmlspecialchars(t('invite.invalid_body')) ?></p>
    </body></html>
    <?php
    exit;
}

if (empty($_SESSION['invite_pending_secret_' . $invitedUser['id']])) {
    $_SESSION['invite_pending_secret_' . $invitedUser['id']] = Totp::generateSecret();
}
$secret = $_SESSION['invite_pending_secret_' . $invitedUser['id']];

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['regenerate'])) {
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');
    $enableMfa = $invitedUser['mfa_required'] ? true : isset($_POST['enable_mfa']);
    $code = (string) ($_POST['totp_code'] ?? '');

    if (strlen($password) < 12) {
        $error = t('setup.error_password_length');
    } elseif ($password !== $confirm) {
        $error = t('setup.error_password_mismatch');
    } elseif ($enableMfa && !Totp::verify($secret, $code)) {
        $error = t('invite.error_totp');
    } else {
        Users::acceptInvite((int) $invitedUser['id'], $password, $enableMfa ? $secret : null);
        unset($_SESSION['invite_pending_secret_' . $invitedUser['id']]);
        header('Location: /login.php?welcome=1');
        exit;
    }
} elseif (isset($_POST['regenerate'])) {
    $_SESSION['invite_pending_secret_' . $invitedUser['id']] = Totp::generateSecret();
    header('Location: /accept-invite.php?token=' . urlencode($token));
    exit;
}

$issuer = 'Codex';
$uri = Totp::provisioningUri($secret, $invitedUser['username'], $issuer);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?= htmlspecialchars(t('invite.title')) ?></title>
<link rel="stylesheet" href="<?= asset('css/style.css') ?>" />
<style>
  body { margin: 0; }
  .setup-wrap { max-width: 480px; margin: 0 auto; padding: 48px 20px 64px; }
  .setup-wrap h1 { font-size: 26px; margin-bottom: 4px; }
  .setup-wrap .lead { margin-bottom: 28px; }
  .setup-card { background: var(--color-surface); border-radius: var(--radius-lg); padding: 24px 26px; margin-bottom: 20px; box-shadow: var(--shadow-sm); }
  .field-stack { display: flex; flex-direction: column; gap: 14px; }
  .mfa-toggle { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 600; margin-bottom: 4px; }
  .mfa-toggle input { width: auto; }
  #mfaSection { margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--color-divider); display: none; }
  .qr-holder { background: #fff; padding: 16px; border-radius: var(--radius-md); display: inline-block; margin-bottom: 6px; }
  .qr-holder svg { display: block; width: 170px; height: 170px; }
  .manual-key summary { cursor: pointer; font-size: 13px; font-weight: 600; color: var(--color-accent); margin: 6px 0; }
  .show-pw-toggle { display: flex; align-items: center; gap: 8px; font-size: 13px; }
  .show-pw-toggle input { width: auto; }
  .error-box { background: color-mix(in srgb, #ff3b3b 15%, var(--color-surface)); color: #ff8a8a; padding: 10px 14px; border-radius: var(--radius-md); font-size: 13.5px; font-weight: 600; margin-bottom: 16px; }
</style>
</head>
<body>

<div class="setup-wrap">
  <h1><?= htmlspecialchars(t('invite.welcome', ['username' => $invitedUser['username']])) ?></h1>
  <p class="text-muted lead"><?= htmlspecialchars(t('invite.lead')) ?></p>

  <div class="setup-card">
    <?php if ($error): ?>
      <div class="error-box"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post" class="field-stack">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>" />
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

      <?php if ($invitedUser['mfa_required']): ?>
        <p class="text-muted" style="font-size:13px;font-weight:600;"><?= htmlspecialchars(t('invite.mfa_required')) ?></p>
        <input type="hidden" name="enable_mfa" value="1" />
      <?php else: ?>
        <label class="mfa-toggle">
          <input type="checkbox" id="enable_mfa" name="enable_mfa" <?= isset($_POST['enable_mfa']) ? 'checked' : '' ?> />
          <?= htmlspecialchars(t('invite.mfa_enable')) ?>
        </label>
      <?php endif; ?>

      <div id="mfaSection">
        <p class="text-muted" style="font-size:13px;margin:0 0 10px;"><?= htmlspecialchars(t('invite.mfa_scan_hint')) ?></p>
        <div class="qr-holder" id="qrHolder"></div>
        <details class="manual-key">
          <summary><?= htmlspecialchars(t('invite.manual_entry')) ?></summary>
          <input class="input" type="text" readonly value="<?= htmlspecialchars($secret) ?>" onclick="this.select()" style="font-family:monospace;" />
        </details>
        <div class="field" style="margin-top:10px;">
          <label for="totp_code"><?= htmlspecialchars(t('invite.six_digit_code')) ?></label>
          <input class="input" type="text" id="totp_code" name="totp_code" pattern="\d{6}" inputmode="numeric" autocomplete="one-time-code" />
        </div>
      </div>

      <button type="submit" class="btn btn-primary btn-block"><?= htmlspecialchars(t('invite.activate')) ?></button>
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

  var checkbox = document.getElementById('enable_mfa'); // absent when MFA is admin-forced (hidden input instead)
  var section = document.getElementById('mfaSection');
  var codeInput = document.getElementById('totp_code');
  if (checkbox) {
    function syncMfaSection() {
      section.style.display = checkbox.checked ? 'block' : 'none';
      codeInput.required = checkbox.checked;
    }
    checkbox.addEventListener('change', syncMfaSection);
    syncMfaSection();
  } else {
    section.style.display = 'block';
    codeInput.required = true;
  }

  document.getElementById('showPasswords').addEventListener('change', function () {
    var type = this.checked ? 'text' : 'password';
    document.getElementById('password').type = type;
    document.getElementById('password_confirm').type = type;
  });
</script>

</body>
</html>
