<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Asset.php';

Auth::bootSession();

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
        $error = "Trop de tentatives échouées. Réessaie dans environ {$wait} minute(s).";
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
        $error = "Nom d'utilisateur, mot de passe ou code invalide.";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Connexion — Codex</title>
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
    padding: 34px 34px 27px; display: flex; flex-direction: column; justify-content: space-between;
  }
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
      <span class="name">Codex</span>
      <span class="sub">library server</span>
    </div>
    <div>
      <h1>Toute ma bibliothèque, à portée de clic.</h1>
      <p>BD, ebooks, magazines et scans — indexés depuis mes propres disques, lisibles depuis n'importe quel navigateur.</p>
    </div>
    <div class="spec-row">
      <div><div class="k">Formats</div><div class="v">CBZ · EPUB · PDF</div></div>
      <div><div class="k">Bibliothèque</div><div class="v">Personnelle</div></div>
      <div><div class="k">Hébergement</div><div class="v">Local</div></div>
    </div>
  </div>

  <div class="form-side">
    <div class="form-wrap">
      <p class="step-label">Connexion</p>
      <h2>Se connecter</h2>
      <p class="text-muted lead">Utilise le compte que l'administrateur t'a créé.</p>

      <?php if ($justSetUp && !$error): ?>
        <div class="success-box">Compte créé. Connecte-toi avec ton mot de passe et un code de ton application.</div>
      <?php elseif ($justWelcomed && !$error): ?>
        <div class="success-box">Compte activé — tu peux te connecter.</div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="error-box"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form class="field-stack" method="post">
        <div class="field">
          <label for="username">Nom d'utilisateur</label>
          <input class="input" id="username" name="username" required autofocus />
        </div>
        <div class="field">
          <label for="password">Mot de passe</label>
          <div class="password-field">
            <input class="input" id="password" name="password" type="password" required autocomplete="current-password" />
            <button type="button" class="password-toggle" id="passwordToggle" aria-label="Afficher le mot de passe">👁</button>
          </div>
        </div>
        <div class="field">
          <label for="totp_code">Code à 6 chiffres (si l'authentification à deux facteurs est activée)</label>
          <input class="input" id="totp_code" name="totp_code" pattern="\d{6}" inputmode="numeric" autocomplete="one-time-code" placeholder="Laisser vide si MFA désactivée" />
        </div>
        <label class="remember-row">
          <input type="checkbox" id="remember" name="remember" />
          Se souvenir de moi pendant 3 mois
        </label>
        <button type="submit" class="btn btn-primary btn-block">Continuer</button>
      </form>
    </div>
  </div>
</div>

<script>
  document.getElementById('passwordToggle').addEventListener('click', function () {
    var field = document.getElementById('password');
    var showing = field.type === 'text';
    field.type = showing ? 'password' : 'text';
    this.textContent = showing ? '👁' : '🙈';
    this.setAttribute('aria-label', showing ? 'Afficher le mot de passe' : 'Masquer le mot de passe');
  });
</script>

</body>
</html>
