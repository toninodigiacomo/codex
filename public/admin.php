<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/AppLog.php';
AppLog::bootstrap();
require_once __DIR__ . '/../src/Asset.php';
require_once __DIR__ . '/../src/I18n.php';
require_once __DIR__ . '/../src/Theme.php';
Auth::bootSession();
I18n::boot();
Auth::requireAdmin();
$me = Auth::currentUser();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" data-theme="<?= htmlspecialchars(Theme::current()) ?>">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<link rel="icon" type="image/x-icon" href="<?= asset("favicon.ico") ?>" />
<link rel="icon" type="image/png" sizes="32x32" href="<?= asset("assets/icons/favicon-32x32.png") ?>" />
<link rel="icon" type="image/png" sizes="16x16" href="<?= asset("assets/icons/favicon-16x16.png") ?>" />
<link rel="apple-touch-icon" sizes="180x180" href="<?= asset("assets/icons/apple-touch-icon.png") ?>" />
<link rel="manifest" href="<?= asset("site.webmanifest") ?>" />
<title><?= htmlspecialchars(t('admin.title')) ?></title>
<meta name="current-user-id" content="<?= (int) $me['id'] ?>" />
<link rel="stylesheet" href="<?= asset('css/style.css') ?>" />
<link rel="stylesheet" href="<?= asset('css/admin.css') ?>" />
</head>
<body>

<div class="app-shell">
  <nav class="nav topbar">
    <a href="library.php" class="nav-brand" style="text-decoration:none;">Codex</a>
    <a href="library.php" class="btn btn-ghost"><?= htmlspecialchars(t('nav.library')) ?></a>
    <span style="margin-left:auto;"></span>
    <div class="user-menu" id="userMenu">
      <button type="button" class="user-menu-trigger" id="userMenuTrigger" data-account-trigger>
        <?= htmlspecialchars($me['username']) ?>
      </button>
    </div>
  </nav>

  <main class="admin-main">
    <div class="admin-tabs" role="tablist">
      <button class="admin-tab active" data-tab="users"><?= htmlspecialchars(t('admin.tab_users')) ?></button>
      <button class="admin-tab" data-tab="libraries"><?= htmlspecialchars(t('admin.tab_libraries')) ?></button>
      <button class="admin-tab" data-tab="settings"><?= htmlspecialchars(t('admin.tab_settings')) ?></button>
      <button class="admin-tab" data-tab="maintenance"><?= htmlspecialchars(t('admin.tab_maintenance')) ?></button>
      <button class="admin-tab" data-tab="system"><?= htmlspecialchars(t('admin.tab_system')) ?></button>
    </div>

    <section id="panel-users" class="admin-panel"></section>
    <section id="panel-libraries" class="admin-panel" hidden></section>
    <section id="panel-settings" class="admin-panel" hidden></section>
    <section id="panel-maintenance" class="admin-panel" hidden></section>
    <section id="panel-system" class="admin-panel" hidden></section>
  </main>
</div>

<div class="toast" id="toast" hidden></div>

<script>window.I18N = <?= json_encode(I18n::all(), JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="<?= asset('js/i18n.js') ?>"></script>
<script src="<?= asset('js/account.js') ?>"></script>
<script src="<?= asset('js/admin.js') ?>"></script>

<?= Theme::footerHtml() ?>
</body>
</html>
