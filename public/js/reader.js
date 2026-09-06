(function () {
  const shell = document.getElementById('readerShell');
  const itemId = shell.dataset.itemId;

  const titleEl = document.getElementById('readerTitle');
  const pageIndicator = document.getElementById('readerPageIndicator');
  const imageEl = document.getElementById('readerImage');
  const pdfWrap = document.getElementById('readerPdfWrap');
  const pdfCanvas = document.getElementById('readerCanvas');
  const linkLayer = document.getElementById('readerLinkLayer');
  const statusEl = document.getElementById('readerStatus');
  const prevZone = document.getElementById('prevZone');
  const nextZone = document.getElementById('nextZone');
  const footerPrevBtn = document.getElementById('footerPrevBtn');
  const footerNextBtn = document.getElementById('footerNextBtn');
  const progressFill = document.getElementById('progressFill');
  const progressTrack = document.getElementById('progressTrack');

  let totalPages = 0;
  let currentIndex = 0;
  let saveTimer = null;

  // Set once init() decides how this item is being read — pdfDoc is the
  // PDF.js document handle, only ever non-null when isPdf is true. Kept
  // separate from a straight `item.format === 'pdf'` check because
  // PDF.js itself might fail to load a specific malformed file, in which
  // case init() falls back to the server-rendered flat-image pages
  // (ItemPages.php/PdfRenderer.php) — same as before this feature existed.
  let isPdf = false;
  let pdfDoc = null;

  async function fetchJson(url, options) {
    const res = await fetch(url, options);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || `Erreur ${res.status}`);
    return data;
  }

  function showStatus(message) {
    statusEl.textContent = message;
    statusEl.hidden = false;
    imageEl.style.visibility = 'hidden';
    pdfWrap.style.visibility = 'hidden';
  }

  function clearStatus() {
    statusEl.hidden = true;
    imageEl.style.visibility = 'visible';
    pdfWrap.style.visibility = 'visible';
  }

  function pageUrl(index) {
    return `/api/items/${itemId}/page?index=${index}`;
  }

  function preload(index) {
    if (isPdf || index < 0 || index >= totalPages) return;
    const img = new Image();
    img.src = pageUrl(index);
  }

  function updateChrome() {
    pageIndicator.textContent = `${currentIndex + 1} / ${totalPages}`;
    const pct = totalPages > 1 ? (currentIndex / (totalPages - 1)) * 100 : 100;
    progressFill.style.width = pct + '%';
    prevZone.hidden = currentIndex === 0;
    footerPrevBtn.disabled = currentIndex === 0;
    nextZone.hidden = currentIndex === totalPages - 1;
    footerNextBtn.disabled = currentIndex === totalPages - 1;
  }

  /** How much vertical space the topbar+footer chrome eats — matches reader.css's #readerImage/.reader-pdf-wrap max-height rules exactly, since the PDF canvas has to compute its own fit-to-space size in JS rather than leaning on CSS max-height the way a plain <img> can. */
  function availableHeight() {
    return window.innerHeight - (window.innerWidth <= 700 ? 96 : 110);
  }

  /**
   * Renders one page to the canvas and rebuilds the link overlay on top of
   * it. Internal links (a table of contents entry, a cross-reference) call
   * back into this same reader's own goTo() rather than PDF.js's own
   * multi-page scrolling viewer, which isn't what this reader uses — one
   * page at a time, same as the comic reader.
   */
  async function renderPdfPage(index) {
    const page = await pdfDoc.getPage(index + 1); // PDF.js pages are 1-based
    const outputScale = window.devicePixelRatio || 1;
    const unscaled = page.getViewport({ scale: 1 });
    // window.innerWidth, not the wrap's own clientWidth: .reader-image-wrap
    // is a flex container sized to its *content*, and before the first
    // render the canvas has no size yet — a chicken-and-egg that measured
    // as ~0px. #readerImage sidesteps this entirely with a viewport-relative
    // max-width in CSS instead of JS; matching that unit here (rather than
    // reading a layout size that depends on what we're about to draw into
    // it) is what the fix actually is.
    const maxWidth = window.innerWidth;
    const maxHeight = availableHeight();
    const scale = Math.min(maxWidth / unscaled.width, maxHeight / unscaled.height, 3); // cap at 3x native — a huge page shouldn't demand a huge canvas just because the window is huge
    const viewport = page.getViewport({ scale });

    pdfCanvas.width = Math.floor(viewport.width * outputScale);
    pdfCanvas.height = Math.floor(viewport.height * outputScale);
    pdfCanvas.style.width = Math.floor(viewport.width) + 'px';
    pdfCanvas.style.height = Math.floor(viewport.height) + 'px';

    const ctx = pdfCanvas.getContext('2d');
    const transform = outputScale !== 1 ? [outputScale, 0, 0, outputScale, 0, 0] : undefined;
    await page.render({ canvasContext: ctx, viewport, transform }).promise;

    await buildLinkLayer(page, viewport);
  }

  async function buildLinkLayer(page, viewport) {
    linkLayer.innerHTML = '';
    let annotations;
    try {
      annotations = await page.getAnnotations();
    } catch (_) {
      return; // no links this time — the rendered page itself is still fine
    }
    for (const ann of annotations) {
      if (ann.subtype !== 'Link') continue;
      const rect = viewport.convertToViewportRectangle(ann.rect);
      const x = Math.min(rect[0], rect[2]);
      const y = Math.min(rect[1], rect[3]);
      const w = Math.abs(rect[2] - rect[0]);
      const h = Math.abs(rect[3] - rect[1]);
      const a = document.createElement('a');
      a.style.left = x + 'px';
      a.style.top = y + 'px';
      a.style.width = w + 'px';
      a.style.height = h + 'px';

      if (ann.url) {
        a.href = ann.url;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
      } else if (ann.dest) {
        a.href = '#';
        a.addEventListener('click', (e) => {
          e.preventDefault();
          goToPdfDestination(ann.dest);
        });
      } else if (ann.action) {
        // a named action like "NextPage"/"PrevPage"/"FirstPage"/"LastPage" —
        // uncommon, but cheap to support since it's the same navigation
        // goTo() already does
        a.href = '#';
        a.addEventListener('click', (e) => {
          e.preventDefault();
          if (ann.action === 'NextPage') next();
          else if (ann.action === 'PrevPage') prev();
          else if (ann.action === 'FirstPage') goTo(0);
          else if (ann.action === 'LastPage') goTo(totalPages - 1);
        });
      } else {
        continue; // nothing to navigate to (a form field, a comment, ...) — not this reader's concern
      }
      linkLayer.appendChild(a);
    }
  }

  async function goToPdfDestination(dest) {
    try {
      const explicit = typeof dest === 'string' ? await pdfDoc.getDestination(dest) : dest;
      if (!Array.isArray(explicit) || explicit[0] === undefined || explicit[0] === null) {
        return;
      }
      const target = explicit[0];
      // Per spec this should always be a reference to a page object — but
      // some PDF generators put a plain page index here instead (not
      // compliant, but real files do this; see one of Tonino's PDFs where
      // internal links otherwise silently did nothing). PDF.js's own
      // official viewer treats a bare number here as a direct 0-based page
      // index rather than rejecting it, so matching that is what makes a
      // link behave the same way it would in Chrome or Firefox's built-in
      // viewer — both are also built on PDF.js.
      const pageIndex = typeof target === 'number' ? target : await pdfDoc.getPageIndex(target);
      goTo(pageIndex);
    } catch (_) {
      // a destination PDF.js couldn't resolve (a malformed or unusual PDF) —
      // silently doing nothing beats a JS error breaking the rest of the reader
    }
  }

  function goTo(index, opts) {
    if (index < 0 || index >= totalPages) return;
    currentIndex = index;
    clearStatus();
    if (isPdf) {
      renderPdfPage(index).catch(() => showStatus('Cette page est indisponible.'));
    } else {
      imageEl.src = pageUrl(index);
      preload(index + 1);
      preload(index - 1);
    }
    updateChrome();
    if (!(opts && opts.skipSave)) {
      scheduleSave();
    }
  }

  function scheduleSave() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => {
      fetchJson(`/api/items/${itemId}/progress`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ current_page: currentIndex, total_pages: totalPages }),
      }).catch(() => {}); // a failed save shouldn't interrupt reading — it'll just resume from an earlier page next time
    }, 400);
  }

  function next() { goTo(currentIndex + 1); }
  function prev() { goTo(currentIndex - 1); }

  prevZone.addEventListener('click', prev);
  nextZone.addEventListener('click', next);
  footerPrevBtn.addEventListener('click', prev);
  footerNextBtn.addEventListener('click', next);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowLeft') { prev(); }
    else if (e.key === 'ArrowRight' || e.key === ' ') { e.preventDefault(); next(); }
  });

  // touch swipe
  let touchStartX = null;
  document.getElementById('readerStage').addEventListener('touchstart', (e) => {
    touchStartX = e.touches[0].clientX;
  }, { passive: true });
  document.getElementById('readerStage').addEventListener('touchend', (e) => {
    if (touchStartX === null) return;
    const dx = e.changedTouches[0].clientX - touchStartX;
    if (Math.abs(dx) > 50) { dx < 0 ? next() : prev(); }
    touchStartX = null;
  }, { passive: true });

  // click-to-seek on the progress track
  progressTrack.addEventListener('click', (e) => {
    const rect = progressTrack.getBoundingClientRect();
    const ratio = Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width));
    goTo(Math.round(ratio * (totalPages - 1)));
  });

  imageEl.addEventListener('error', () => {
    if (!isPdf && imageEl.getAttribute('src')) {
      showStatus('Cette page est indisponible.');
    }
  });

  // A PDF page is rendered at a fixed pixel size (unlike the comic <img>,
  // which just leans on CSS max-width/max-height to shrink fluidly) — a
  // window resize needs an explicit re-render to pick up the new size.
  let resizeTimer = null;
  window.addEventListener('resize', () => {
    if (!isPdf) return;
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      renderPdfPage(currentIndex).catch(() => {});
    }, 200);
  });

  async function init() {
    try {
      const [item, progress] = await Promise.all([
        fetchJson(`/api/items/${itemId}`),
        fetchJson(`/api/items/${itemId}/progress`).catch(() => ({ position: null })),
      ]);

      titleEl.textContent = item.title;
      document.title = item.title + ' — Codex';

      if (item.format === 'pdf' && typeof pdfjsLib !== 'undefined') {
        try {
          pdfjsLib.GlobalWorkerOptions.workerSrc = 'vendor/pdfjs/pdf.worker.min.js';
          pdfDoc = await pdfjsLib.getDocument(`/api/items/${itemId}/download`).promise;
          totalPages = pdfDoc.numPages;
          isPdf = true;
          imageEl.hidden = true;
          pdfWrap.hidden = false;
        } catch (_) {
          // PDF.js couldn't make sense of this particular file — fall through
          // to the flat server-rendered pages below, same as before this
          // feature existed. Clickable links just won't be available for it.
          pdfDoc = null;
          isPdf = false;
        }
      }
      if (!isPdf) {
        const pagesInfo = await fetchJson(`/api/items/${itemId}/pages`);
        totalPages = pagesInfo.count;
      }

      if (totalPages === 0) {
        showStatus("Ce fichier n'est pas lisible dans le lecteur intégré.");
        pageIndicator.textContent = '';
        progressTrack.style.display = 'none';
        prevZone.hidden = true;
        nextZone.hidden = true;
        footerPrevBtn.hidden = true;
        footerNextBtn.hidden = true;
        return;
      }

      let startIndex = 0;
      if (progress.position !== null && !progress.completed_at) {
        const saved = Number(progress.position);
        if (Number.isInteger(saved) && saved >= 0 && saved < totalPages) {
          startIndex = saved;
        }
      }
      goTo(startIndex, { skipSave: true });
    } catch (err) {
      titleEl.textContent = 'Erreur';
      showStatus(err.message || "Impossible de charger cet élément.");
      pageIndicator.textContent = '';
    }
  }

  init();
})();
