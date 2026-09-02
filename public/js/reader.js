(function () {
  const shell = document.getElementById('readerShell');
  const itemId = shell.dataset.itemId;

  const titleEl = document.getElementById('readerTitle');
  const pageIndicator = document.getElementById('readerPageIndicator');
  const imageEl = document.getElementById('readerImage');
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
  }

  function clearStatus() {
    statusEl.hidden = true;
    imageEl.style.visibility = 'visible';
  }

  function pageUrl(index) {
    return `/api/items/${itemId}/page?index=${index}`;
  }

  function preload(index) {
    if (index < 0 || index >= totalPages) return;
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

  function goTo(index, opts) {
    if (index < 0 || index >= totalPages) return;
    currentIndex = index;
    clearStatus();
    imageEl.src = pageUrl(index);
    updateChrome();
    preload(index + 1);
    preload(index - 1);
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
    if (imageEl.getAttribute('src')) {
      showStatus('Cette page est indisponible.');
    }
  });

  async function init() {
    try {
      const [item, pagesInfo, progress] = await Promise.all([
        fetchJson(`/api/items/${itemId}`),
        fetchJson(`/api/items/${itemId}/pages`),
        fetchJson(`/api/items/${itemId}/progress`).catch(() => ({ position: null })),
      ]);

      titleEl.textContent = item.title;
      document.title = item.title + ' — Codex';
      totalPages = pagesInfo.count;

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
