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
Auth::requireLogin();

$itemId = (int) ($_GET['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::locale()) ?>" data-theme="<?= htmlspecialchars(Theme::current()) ?>">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?= htmlspecialchars(t('reader.title')) ?></title>
<link rel="stylesheet" href="<?= asset('css/style.css') ?>" />
<link rel="stylesheet" href="<?= asset('css/reader.css') ?>" />
</head>
<body class="reader-body">

<div class="reader-shell" id="readerShell" data-item-id="<?= (int) $itemId ?>">
  <header class="reader-topbar">
    <a href="item.php?id=<?= (int) $itemId ?>" class="reader-close" title="<?= htmlspecialchars(t('common.close')) ?>" aria-label="<?= htmlspecialchars(t('common.close')) ?>">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
    </a>
    <span class="reader-title" id="readerTitle"><?= htmlspecialchars(t('common.loading')) ?></span>
    <span class="reader-page-indicator" id="readerPageIndicator"></span>
  </header>

  <main class="reader-stage" id="readerStage">
    <button type="button" class="reader-nav-zone reader-nav-prev" id="prevZone" aria-label="<?= htmlspecialchars(t('library.page_prev')) ?>"></button>
    <div class="reader-image-wrap" id="readerImageWrap">
      <img id="readerImage" alt="" draggable="false" />
      <div class="reader-pdf-wrap" id="readerPdfWrap" hidden>
        <canvas id="readerCanvas"></canvas>
        <div class="reader-link-layer" id="readerLinkLayer"></div>
      </div>
      <p class="reader-status" id="readerStatus" hidden></p>
    </div>
    <button type="button" class="reader-nav-zone reader-nav-next" id="nextZone" aria-label="<?= htmlspecialchars(t('library.page_next')) ?>"></button>
  </main>

  <footer class="reader-footer">
    <button type="button" class="reader-footer-btn" id="footerPrevBtn" aria-label="<?= htmlspecialchars(t('library.page_prev')) ?>">‹</button>
    <div class="reader-progress-track" id="progressTrack">
      <div class="reader-progress-fill" id="progressFill"></div>
    </div>
    <button type="button" class="reader-footer-btn" id="footerNextBtn" aria-label="<?= htmlspecialchars(t('library.page_next')) ?>">›</button>
  </footer>
</div>

<script>window.I18N = <?= json_encode(I18n::all(), JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="<?= asset('js/i18n.js') ?>"></script>
<!--
  Loaded unconditionally but only ever actually used for a .pdf item —
  reader.js checks `typeof pdfjsLib` before touching it, so this has no
  effect on the comic/image reading path at all. Vendored locally rather
  than pulled from a CDN (see public/vendor/pdfjs/README.md), same as
  vendor/qrcode.js elsewhere in this app.
-->
<script src="<?= asset('vendor/pdfjs/pdf.min.js') ?>"></script>
<script src="<?= asset('js/reader.js') ?>"></script>

</body>
</html>
