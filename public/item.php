<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Asset.php';
Auth::bootSession();
Auth::requireLogin(); // item management is administration, not browsing — admins can reach this page
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Fiche — Codex</title>
<link rel="stylesheet" href="<?= asset('css/style.css') ?>" />
<link rel="stylesheet" href="<?= asset('css/library.css') ?>" />
<link rel="stylesheet" href="<?= asset('css/item.css') ?>" />
</head>
<body>

<div class="app-shell">
  <nav class="nav topbar">
    <a href="library.php" class="nav-brand" style="text-decoration:none;">Codex</a>
    <a href="library.php" class="btn btn-ghost">&larr; Bibliothèque</a>
  </nav>

  <main class="item-page" id="itemPage" data-user-role="<?= htmlspecialchars($_SESSION['role'] ?? '') ?>">
    <p class="text-muted">Chargement...</p>
  </main>
</div>

<script src="<?= asset('js/item.js') ?>"></script>

</body>
</html>
