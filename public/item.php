<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/AppLog.php';
AppLog::bootstrap();
require_once __DIR__ . '/../src/Asset.php';
require_once __DIR__ . '/../src/I18n.php';
Auth::bootSession();
I18n::boot();
Auth::requireLogin(); // item management is administration, not browsing — admins can reach this page
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?= htmlspecialchars(t('item.title')) ?></title>
<link rel="stylesheet" href="<?= asset('css/style.css') ?>" />
<link rel="stylesheet" href="<?= asset('css/library.css') ?>" />
<link rel="stylesheet" href="<?= asset('css/item.css') ?>" />
</head>
<body>

<div class="app-shell">
  <nav class="nav topbar">
    <a href="library.php" class="nav-brand" style="text-decoration:none;">Codex</a>
    <a href="library.php" class="btn btn-ghost"><?= htmlspecialchars(t('nav.library')) ?></a>
  </nav>

  <main class="item-page" id="itemPage" data-user-role="<?= htmlspecialchars($_SESSION['role'] ?? '') ?>">
    <p class="text-muted"><?= htmlspecialchars(t('common.loading')) ?></p>
  </main>
</div>

<script>window.I18N = <?= json_encode(I18n::all(), JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="<?= asset('js/i18n.js') ?>"></script>
<script src="<?= asset('js/item.js') ?>"></script>

</body>
</html>
