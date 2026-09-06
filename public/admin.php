<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/AppLog.php';
AppLog::bootstrap();
require_once __DIR__ . '/../src/Asset.php';
Auth::bootSession();
Auth::requireAdmin();
$me = Auth::currentUser();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Administration — Codex</title>
<meta name="current-user-id" content="<?= (int) $me['id'] ?>" />
<link rel="stylesheet" href="<?= asset('css/style.css') ?>" />
<link rel="stylesheet" href="<?= asset('css/admin.css') ?>" />
</head>
<body>

<div class="app-shell">
  <nav class="nav topbar">
    <a href="library.php" class="nav-brand" style="text-decoration:none;">Codex</a>
    <a href="library.php" class="btn btn-ghost">&larr; Bibliothèque</a>
    <span style="margin-left:auto;"></span>
    <div class="user-menu" id="userMenu">
      <button type="button" class="user-menu-trigger" id="userMenuTrigger" data-account-trigger>
        <?= htmlspecialchars($me['username']) ?>
      </button>
    </div>
  </nav>

  <main class="admin-main">
    <div class="admin-tabs" role="tablist">
      <button class="admin-tab active" data-tab="users">Utilisateurs</button>
      <button class="admin-tab" data-tab="libraries">Bibliothèques</button>
      <button class="admin-tab" data-tab="settings">Réglages</button>
      <button class="admin-tab" data-tab="maintenance">Maintenance</button>
      <button class="admin-tab" data-tab="system">Système</button>
    </div>

    <section id="panel-users" class="admin-panel"></section>
    <section id="panel-libraries" class="admin-panel" hidden></section>
    <section id="panel-settings" class="admin-panel" hidden></section>
    <section id="panel-maintenance" class="admin-panel" hidden></section>
    <section id="panel-system" class="admin-panel" hidden></section>
  </main>
</div>

<div class="toast" id="toast" hidden></div>

<script src="<?= asset('js/account.js') ?>"></script>
<script src="<?= asset('js/admin.js') ?>"></script>

</body>
</html>
