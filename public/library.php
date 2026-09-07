<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/AppLog.php';
AppLog::bootstrap();
require_once __DIR__ . '/../src/Asset.php';
require_once __DIR__ . '/../src/I18n.php';
Auth::bootSession();
I18n::boot();
Auth::requireReaderPage();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?= htmlspecialchars(t('library.title')) ?></title>
<link rel="stylesheet" href="<?= asset('css/style.css') ?>" />
<link rel="stylesheet" href="<?= asset('css/library.css') ?>" />
</head>
<body>

<div class="app-shell">
  <nav class="nav topbar">
    <span class="nav-brand">Codex</span>
    <a href="#" class="nav-home-btn" id="homeBtn" title="<?= htmlspecialchars(t('nav.home')) ?>" aria-label="<?= htmlspecialchars(t('nav.home')) ?>">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v9a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1v-9"/></svg>
    </a>
    <div class="seg" id="typeTabs"></div>
    <div class="search-box">
      <input class="input" type="search" id="searchInput" placeholder="<?= htmlspecialchars(t('nav.search_placeholder')) ?>" />
    </div>
    <?php // admins never reach this page — see Auth::requireReaderPage() ?>
    <div class="user-menu" id="userMenu">
      <button type="button" class="user-menu-trigger" id="userMenuTrigger" data-account-trigger data-show-email="1">
        <?= htmlspecialchars($_SESSION['username'] ?? '') ?>
      </button>
    </div>
  </nav>

  <div class="app-body">
    <aside class="sidebar">
      <div class="side-section">
        <h6><?= htmlspecialchars(t('library.sidebar_libraries')) ?></h6>
        <ul class="side-list" id="libraryList"></ul>
      </div>
      <div class="side-section">
        <h6><?= htmlspecialchars(t('library.sidebar_tags')) ?></h6>
        <ul class="side-list tag-list" id="tagList"></ul>
      </div>
    </aside>

    <main class="main-area">
      <div id="homeView">
        <section class="shelf" id="shelf-comic">
          <h2 class="shelf-title"><?= htmlspecialchars(t('library.shelf_comic')) ?></h2>
          <div class="shelf-row-wrap">
            <button type="button" class="shelf-arrow shelf-arrow-prev" data-shelf-prev="comic" aria-label="<?= htmlspecialchars(t('library.prev')) ?>">‹</button>
            <div class="item-grid shelf-row" id="shelfGrid-comic"></div>
            <button type="button" class="shelf-arrow shelf-arrow-next" data-shelf-next="comic" aria-label="<?= htmlspecialchars(t('library.next')) ?>">›</button>
          </div>
        </section>
        <section class="shelf" id="shelf-ebook">
          <h2 class="shelf-title"><?= htmlspecialchars(t('library.shelf_ebook')) ?></h2>
          <div class="shelf-row-wrap">
            <button type="button" class="shelf-arrow shelf-arrow-prev" data-shelf-prev="ebook" aria-label="<?= htmlspecialchars(t('library.prev')) ?>">‹</button>
            <div class="item-grid shelf-row" id="shelfGrid-ebook"></div>
            <button type="button" class="shelf-arrow shelf-arrow-next" data-shelf-next="ebook" aria-label="<?= htmlspecialchars(t('library.next')) ?>">›</button>
          </div>
        </section>
        <section class="shelf" id="shelf-magazine">
          <h2 class="shelf-title"><?= htmlspecialchars(t('library.shelf_magazine')) ?></h2>
          <div class="shelf-row-wrap">
            <button type="button" class="shelf-arrow shelf-arrow-prev" data-shelf-prev="magazine" aria-label="<?= htmlspecialchars(t('library.prev')) ?>">‹</button>
            <div class="item-grid shelf-row" id="shelfGrid-magazine"></div>
            <button type="button" class="shelf-arrow shelf-arrow-next" data-shelf-next="magazine" aria-label="<?= htmlspecialchars(t('library.next')) ?>">›</button>
          </div>
        </section>
        <section class="shelf" id="shelf-other">
          <h2 class="shelf-title"><?= htmlspecialchars(t('library.shelf_other')) ?></h2>
          <div class="shelf-row-wrap">
            <button type="button" class="shelf-arrow shelf-arrow-prev" data-shelf-prev="other" aria-label="<?= htmlspecialchars(t('library.prev')) ?>">‹</button>
            <div class="item-grid shelf-row" id="shelfGrid-other"></div>
            <button type="button" class="shelf-arrow shelf-arrow-next" data-shelf-next="other" aria-label="<?= htmlspecialchars(t('library.next')) ?>">›</button>
          </div>
        </section>
        <p class="text-muted" id="homeEmptyState" hidden><?= htmlspecialchars(t('library.empty_home')) ?></p>
      </div>

      <div id="browseView" hidden>
        <div class="main-toolbar">
          <button type="button" class="btn btn-ghost" id="browseBackBtn" hidden><?= htmlspecialchars(t('library.back')) ?></button>
          <div class="active-filters" id="activeFilters"></div>
          <select class="input" id="sortSelect" style="width:auto;">
            <option value="added_at:DESC"><?= htmlspecialchars(t('library.sort_added_desc')) ?></option>
            <option value="added_at:ASC"><?= htmlspecialchars(t('library.sort_added_asc')) ?></option>
            <option value="title:ASC"><?= htmlspecialchars(t('library.sort_title_asc')) ?></option>
            <option value="title:DESC"><?= htmlspecialchars(t('library.sort_title_desc')) ?></option>
          </select>
        </div>

        <p class="text-muted" id="resultCount"></p>

        <div class="item-grid" id="itemGrid"></div>
        <p class="text-muted" id="emptyState" hidden><?= htmlspecialchars(t('library.empty_browse')) ?></p>
        <div class="pagination" id="pagination" hidden></div>
      </div>

      <div id="groupView" hidden>
        <div class="main-toolbar">
          <button type="button" class="btn btn-ghost" id="groupBackBtn"></button>
          <h2 class="group-view-title" id="groupViewTitle"></h2>
        </div>
        <div class="item-grid" id="groupGrid"></div>
        <p class="text-muted" id="groupEmptyState" hidden><?= htmlspecialchars(t('library.empty_group')) ?></p>
        <div class="pagination" id="groupPagination" hidden></div>
      </div>
    </main>
  </div>
</div>

<script>window.I18N = <?= json_encode(I18n::all(), JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="<?= asset('js/i18n.js') ?>"></script>
<script src="<?= asset('js/account.js') ?>"></script>
<script src="<?= asset('js/library.js') ?>"></script>

</body>
</html>
