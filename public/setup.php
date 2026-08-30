<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Totp.php';

Auth::bootSession();

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
        $error = "Le nom d'utilisateur doit contenir au moins 3 caractères.";
    } elseif (strlen($password) < 12) {
        $error = 'Le mot de passe doit contenir au moins 12 caractères.';
    } elseif ($password !== $confirm) {
        $error = 'Les deux mots de passe ne correspondent pas.';
    } elseif (!Totp::verify($secret, $code)) {
        $error = "Code de l'application d'authentification invalide. Vérifie l'heure de ton téléphone et réessaie.";
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
<html lang="fr">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Configuration — Codex</title>
<link rel="stylesheet" href="css/style.css" />
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
  <h1>Bienvenue sur Codex</h1>
  <p class="text-muted lead">Aucun compte n'existe encore — configurons le compte administrateur.</p>

  <div class="setup-card">
    <h2>1. Scanne ce QR code</h2>
    <p class="text-muted hint">Avec Google Authenticator, Authy, 1Password, Bitwarden...</p>
    <div class="qr-holder" id="qrHolder"></div>
    <details class="manual-key">
      <summary>Le QR code ne scanne pas ? Saisie manuelle</summary>
      <div class="field">
        <label>Clé secrète (Base32)</label>
        <input class="input" type="text" readonly value="<?= htmlspecialchars($secret) ?>" onclick="this.select()" style="font-family:monospace;letter-spacing:0.04em;" />
      </div>
      <div class="field" style="margin-top:10px;">
        <label>Nom du compte</label>
        <input class="input" type="text" readonly value="<?= htmlspecialchars($issuer) ?>:admin" onclick="this.select()" />
      </div>
    </details>
    <form method="post" style="margin-top:14px;">
      <button type="submit" name="regenerate" value="1" class="btn btn-secondary" onclick="return confirm('Générer une nouvelle clé ? Il faudra rescanner le QR code.')">Générer une nouvelle clé</button>
    </form>
  </div>

  <div class="setup-card">
    <h2>2. Crée ton compte administrateur</h2>
    <?php if ($error): ?>
      <div class="error-box"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post" class="field-stack">
      <div class="field">
        <label for="username">Nom d'utilisateur</label>
        <input class="input" type="text" id="username" name="username" required minlength="3" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" />
      </div>
      <div class="field">
        <label for="password">Mot de passe (12 caractères minimum)</label>
        <input class="input" type="password" id="password" name="password" required minlength="12" autocomplete="new-password" />
      </div>
      <div class="field">
        <label for="password_confirm">Confirmer le mot de passe</label>
        <input class="input" type="password" id="password_confirm" name="password_confirm" required minlength="12" autocomplete="new-password" />
      </div>
      <label class="show-pw-toggle">
        <input type="checkbox" id="showPasswords" /> Afficher les mots de passe
      </label>
      <div class="field">
        <label for="totp_code">Code à 6 chiffres de l'application</label>
        <input class="input" type="text" id="totp_code" name="totp_code" required pattern="\d{6}" inputmode="numeric" autocomplete="one-time-code" />
      </div>
      <button type="submit" class="btn btn-primary btn-block">Activer l'administration</button>
    </form>
  </div>
</div>

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
