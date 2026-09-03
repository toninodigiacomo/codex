<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Asset.php';
Auth::bootSession();
Auth::requireReaderPage();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Bibliothèque — Codex</title>
<link rel="stylesheet" href="<?= asset('css/style.css') ?>" />
<link rel="stylesheet" href="<?= asset('css/library.css') ?>" />
</head>
<body>

<div class="app-shell">
  <nav class="nav topbar">
    <span class="nav-brand">Codex</span>
    <a href="#" class="nav-home-btn" id="homeBtn" title="Accueil" aria-label="Accueil">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v9a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1v-9"/></svg>
    </a>
    <div class="seg" id="typeTabs"></div>
    <div class="search-box">
      <input class="input" type="search" id="searchInput" placeholder="Rechercher un titre, un auteur..." />
    </div>
    <?php // admins never reach this page — see Auth::requireReaderPage() ?>
    <div class="user-menu" id="userMenu">
      <button type="button" class="user-menu-trigger" id="userMenuTrigger">
        <?= htmlspecialchars($_SESSION['username'] ?? '') ?>
        <svg class="chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
      </button>
      <div class="user-menu-dropdown" id="userMenuDropdown" hidden>
        <a href="logout.php">Déconnexion</a>
      </div>
    </div>
  </nav>

  <div class="app-body">
    <aside class="sidebar">
      <div class="side-section">
        <h6>Bibliothèques</h6>
        <ul class="side-list" id="libraryList"></ul>
      </div>
      <div class="side-section">
        <h6>Séries</h6>
        <ul class="side-list" id="seriesList"></ul>
      </div>
      <div class="side-section">
        <h6>Tags</h6>
        <ul class="side-list tag-list" id="tagList"></ul>
      </div>
    </aside>

    <main class="main-area">
      <div id="homeView">
        <section class="shelf" id="shelf-comic">
          <h2 class="shelf-title">Bandes Dessinées récentes</h2>
          <div class="shelf-row-wrap">
            <button type="button" class="shelf-arrow shelf-arrow-prev" data-shelf-prev="comic" aria-label="Précédent">‹</button>
            <div class="item-grid shelf-row" id="shelfGrid-comic"></div>
            <button type="button" class="shelf-arrow shelf-arrow-next" data-shelf-next="comic" aria-label="Suivant">›</button>
          </div>
        </section>
        <section class="shelf" id="shelf-ebook">
          <h2 class="shelf-title">Livres récents</h2>
          <div class="shelf-row-wrap">
            <button type="button" class="shelf-arrow shelf-arrow-prev" data-shelf-prev="ebook" aria-label="Précédent">‹</button>
            <div class="item-grid shelf-row" id="shelfGrid-ebook"></div>
            <button type="button" class="shelf-arrow shelf-arrow-next" data-shelf-next="ebook" aria-label="Suivant">›</button>
          </div>
        </section>
        <section class="shelf" id="shelf-magazine">
          <h2 class="shelf-title">Magazines récents</h2>
          <div class="shelf-row-wrap">
            <button type="button" class="shelf-arrow shelf-arrow-prev" data-shelf-prev="magazine" aria-label="Précédent">‹</button>
            <div class="item-grid shelf-row" id="shelfGrid-magazine"></div>
            <button type="button" class="shelf-arrow shelf-arrow-next" data-shelf-next="magazine" aria-label="Suivant">›</button>
          </div>
        </section>
        <section class="shelf" id="shelf-other">
          <h2 class="shelf-title">Fichiers récents</h2>
          <div class="shelf-row-wrap">
            <button type="button" class="shelf-arrow shelf-arrow-prev" data-shelf-prev="other" aria-label="Précédent">‹</button>
            <div class="item-grid shelf-row" id="shelfGrid-other"></div>
            <button type="button" class="shelf-arrow shelf-arrow-next" data-shelf-next="other" aria-label="Suivant">›</button>
          </div>
        </section>
        <p class="text-muted" id="homeEmptyState" hidden>Aucun résultat. Une bibliothèque a-t-elle déjà été synchronisée ?</p>
      </div>

      <div id="browseView" hidden>
        <div class="main-toolbar">
          <button type="button" class="btn btn-ghost" id="browseBackBtn" hidden>← Retour</button>
          <div class="active-filters" id="activeFilters"></div>
          <select class="input" id="sortSelect" style="width:auto;">
            <option value="added_at:DESC">Ajouts récents</option>
            <option value="added_at:ASC">Ajouts anciens</option>
            <option value="title:ASC">Titre (A→Z)</option>
            <option value="title:DESC">Titre (Z→A)</option>
          </select>
        </div>

        <p class="text-muted" id="resultCount"></p>

        <div class="item-grid" id="itemGrid"></div>
        <p class="text-muted" id="emptyState" hidden>Aucun résultat. La bibliothèque est-elle déjà indexée ?</p>
        <div class="pagination" id="pagination" hidden></div>
      </div>

      <div id="groupView" hidden>
        <div class="main-toolbar">
          <button type="button" class="btn btn-ghost" id="groupBackBtn"></button>
          <h2 class="group-view-title" id="groupViewTitle"></h2>
        </div>
        <div class="item-grid" id="groupGrid"></div>
        <p class="text-muted" id="groupEmptyState" hidden>Aucun résultat.</p>
      </div>
    </main>
  </div>
</div>

<script src="<?= asset('js/library.js') ?>"></script>

</body>
</html>
