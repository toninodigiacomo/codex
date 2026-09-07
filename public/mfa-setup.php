<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/AppLog.php';
AppLog::bootstrap();
require_once __DIR__ . '/../src/Asset.php';
require_once __DIR__ . '/../src/Totp.php';
require_once __DIR__ . '/../src/I18n.php';

Auth::bootSession();
I18n::boot();

$userId = Auth::pendingMfaSetupUserId();
if ($userId === null) {
    header('Location: /login.php');
    exit;
}

if (empty($_SESSION['forced_mfa_secret_' . $userId])) {
    $_SESSION['forced_mfa_secret_' . $userId] = Totp::generateSecret();
}
$secret = $_SESSION['forced_mfa_secret_' . $userId];

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['regenerate'])) {
        $_SESSION['forced_mfa_secret_' . $userId] = Totp::generateSecret();
        header('Location: /mfa-setup.php');
        exit;
    }
    $code = (string) ($_POST['totp_code'] ?? '');
    if (Auth::completeForcedMfaEnrollment($secret, $code)) {
        unset($_SESSION['forced_mfa_secret_' . $userId]);
        header('Location: ' . (Auth::isAdmin() ? '/admin.php' : '/library.php'));
        exit;
    }
    $error = t('mfa_setup.error_invalid_code');
}

$issuer = 'Codex';
$uri = Totp::provisioningUri($secret, $_SESSION['username'] ?? 'compte', $issuer);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?= htmlspecialchars(t('mfa_setup.title')) ?></title>
<link rel="stylesheet" href="<?= asset('css/style.css') ?>" />
<style>
  body { margin: 0; }
  .setup-wrap { max-width: 460px; margin: 0 auto; padding: 48px 20px 64px; }
  .setup-wrap h1 { font-size: 24px; margin-bottom: 4px; }
  .setup-wrap .lead { margin-bottom: 28px; }
  .setup-card { background: var(--color-surface); border-radius: var(--radius-lg); padding: 24px 26px; box-shadow: var(--shadow-sm); }
  .qr-holder { background: #fff; padding: 16px; border-radius: var(--radius-md); display: inline-block; margin-bottom: 6px; }
  .qr-holder svg { display: block; width: 180px; height: 180px; }
  .manual-key summary { cursor: pointer; font-size: 13px; font-weight: 600; color: var(--color-accent); margin: 8px 0; }
  .field-stack { display: flex; flex-direction: column; gap: 14px; margin-top: 14px; }
  .error-box { background: color-mix(in srgb, #ff3b3b 15%, var(--color-surface)); color: #ff8a8a; padding: 10px 14px; border-radius: var(--radius-md); font-size: 13.5px; font-weight: 600; margin-bottom: 16px; }
</style>
</head>
<body>

<div class="setup-wrap">
  <h1><?= htmlspecialchars(t('mfa_setup.heading')) ?></h1>
  <p class="text-muted lead"><?= htmlspecialchars(t('mfa_setup.lead')) ?></p>

  <div class="setup-card">
    <?php if ($error): ?>
      <div class="error-box"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <p class="text-muted" style="font-size:13px;margin:0 0 10px;"><?= htmlspecialchars(t('invite.mfa_scan_hint')) ?></p>
    <div class="qr-holder" id="qrHolder"></div>
    <details class="manual-key">
      <summary><?= htmlspecialchars(t('invite.manual_entry')) ?></summary>
      <input class="input" type="text" readonly value="<?= htmlspecialchars($secret) ?>" onclick="this.select()" style="font-family:monospace;" />
    </details>

    <form method="post" class="field-stack">
      <div class="field">
        <label for="totp_code"><?= htmlspecialchars(t('invite.six_digit_code')) ?></label>
        <input class="input" type="text" id="totp_code" name="totp_code" required pattern="\d{6}" inputmode="numeric" autocomplete="one-time-code" autofocus />
      </div>
      <button type="submit" class="btn btn-primary btn-block"><?= htmlspecialchars(t('mfa_setup.activate_continue')) ?></button>
    </form>
    <form method="post" style="margin-top:10px;">
      <button type="submit" name="regenerate" value="1" class="btn btn-ghost btn-sm"><?= htmlspecialchars(t('setup.regenerate_key')) ?></button>
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
</script>

</body>
</html>
