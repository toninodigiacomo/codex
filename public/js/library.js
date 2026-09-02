(function () {
  function esc(s) {
    const div = document.createElement('div');
    div.textContent = s == null ? '' : String(s);
    return div.innerHTML;
  }

  const TYPE_LABELS = { comic: 'BD', ebook: 'Ebook', magazine: 'Magazine', other: 'Autre' };
  const HOME_SHELF_TYPES = ['comic', 'ebook', 'magazine', 'other'];
  const HOME_SHELF_TYPES_FETCH_LIMIT = 60; // a full scrollable shelf's worth, per the "let's say 60" request

  const state = {
    mode: 'home', // 'home' | 'browse'
    type: '',
    library_id: null,
    series_id: null,
    tag_id: null,
    q: '',
    sort: 'added_at',
    dir: 'DESC',
  };

  let libraries = [];
  let series = [];
  let tags = [];
  let lastBrowseItems = []; // kept so a window resize can re-trim without re-fetching

  const homeView = document.getElementById('homeView');
  const browseView = document.getElementById('browseView');
  const typeTabs = document.getElementById('typeTabs');
  const homeBtn = document.getElementById('homeBtn');
  const userMenu = document.getElementById('userMenu');
  const userMenuTrigger = document.getElementById('userMenuTrigger');
  const userMenuDropdown = document.getElementById('userMenuDropdown');
  const homeEmptyState = document.getElementById('homeEmptyState');
  const grid = document.getElementById('itemGrid');
  const emptyState = document.getElementById('emptyState');
  const resultCount = document.getElementById('resultCount');
  const activeFilters = document.getElementById('activeFilters');
  const libraryList = document.getElementById('libraryList');
  const seriesList = document.getElementById('seriesList');
  const tagList = document.getElementById('tagList');
  const searchInput = document.getElementById('searchInput');
  const sortSelect = document.getElementById('sortSelect');

  async function fetchJson(url) {
    const res = await fetch(url);
    if (!res.ok) throw new Error(`${url} → HTTP ${res.status}`);
    return res.json();
  }

  function coverMarkup(item) {
    if (item.cover_path) {
      return `<img src="${esc(item.cover_path)}" alt="" loading="lazy" />`;
    }
    return `<div class="fallback">${esc(item.title)}</div>`;
  }

  function metaLine(item) {
    if (item.type === 'comic') {
      const parts = [];
      if (item.series_name) parts.push(item.issue_number ? `${item.series_name} #${item.issue_number}` : item.series_name);
      return esc(parts.join(' · ') || item.publisher || '');
    }
    return esc(item.publisher || '');
  }

  function itemCardHtml(item) {
    return `
      <a class="item-card" href="item.php?id=${esc(item.id)}">
        <div class="item-cover">
          <span class="type-chip">${esc(TYPE_LABELS[item.type] || item.type)}</span>
          ${coverMarkup(item)}
        </div>
        <div class="item-title">${esc(item.title)}</div>
        <div class="item-meta">${metaLine(item)}</div>
      </a>`;
  }

  /**
   * Renders items into a CSS grid, then trims trailing cards so only full
   * rows remain — a grid using auto-fill/minmax doesn't tell CSS itself
   * how many columns it settled on, but the browser's *resolved*
   * grid-template-columns value does, once the grid has actually laid
   * out; reading that back is what makes the trim match the real,
   * responsive column count instead of a guessed one. maxRows caps how
   * many rows are kept at all (used for the homepage shelves); pass null
   * for "keep everything, just drop the partial last row".
   */
  function renderGridTrimmed(gridEl, items, maxRows) {
    gridEl.innerHTML = items.map(itemCardHtml).join('');
    if (!items.length) {
      return 0;
    }
    const cols = getComputedStyle(gridEl).gridTemplateColumns.split(' ').filter(Boolean).length || 1;
    const rowCap = maxRows ? maxRows * cols : items.length;
    const capped = Math.min(items.length, rowCap);
    // Round down to a full row — but only once there IS at least one full
    // row: with fewer items than one row holds, rounding down lands on 0,
    // which would hide every item just because they don't fill a row yet.
    const keep = capped < cols ? capped : Math.floor(capped / cols) * cols;
    const children = gridEl.children;
    for (let i = children.length - 1; i >= keep; i--) {
      children[i].remove();
    }
    return keep;
  }

  // ============================================================
  // Home mode — recent items shelved by type
  // ============================================================
  async function loadHome() {
    const results = await Promise.all(
      HOME_SHELF_TYPES.map((type) =>
        fetchJson(`/api/items?type=${type}&sort=added_at&dir=DESC&limit=${HOME_SHELF_TYPES_FETCH_LIMIT}`).catch(() => ({ items: [] }))
      )
    );

    let anyItems = false;
    HOME_SHELF_TYPES.forEach((type, i) => {
      const items = results[i].items;
      const section = document.getElementById(`shelf-${type}`);
      const shelfGrid = document.getElementById(`shelfGrid-${type}`);
      shelfGrid.innerHTML = items.map(itemCardHtml).join('');
      section.hidden = items.length === 0;
      if (items.length > 0) anyItems = true;
      updateShelfArrows(type);
    });

    homeEmptyState.hidden = anyItems;
  }

  /** Shows/hides each shelf's arrows based on whether there's actually anything to scroll to in that direction. */
  function updateShelfArrows(type) {
    const row = document.getElementById(`shelfGrid-${type}`);
    const prevBtn = document.querySelector(`[data-shelf-prev="${type}"]`);
    const nextBtn = document.querySelector(`[data-shelf-next="${type}"]`);
    if (!row || !prevBtn || !nextBtn) return;
    const update = () => {
      prevBtn.hidden = row.scrollLeft <= 4;
      nextBtn.hidden = row.scrollLeft >= row.scrollWidth - row.clientWidth - 4;
    };
    update();
    row.addEventListener('scroll', update);
  }

  document.querySelectorAll('[data-shelf-prev], [data-shelf-next]').forEach((btn) => {
    const type = btn.dataset.shelfPrev || btn.dataset.shelfNext;
    const dir = btn.dataset.shelfPrev ? -1 : 1;
    btn.addEventListener('click', () => {
      const row = document.getElementById(`shelfGrid-${type}`);
      row.scrollBy({ left: dir * row.clientWidth * 0.9, behavior: 'smooth' });
    });
  });

  // ============================================================
  // Browse mode — search / filters / sort
  // ============================================================
  function switchToBrowseMode() {
    if (state.mode === 'browse') return;
    state.mode = 'browse';
    homeView.hidden = true;
    browseView.hidden = false;
  }

  function renderSideList(el, entries, key, activeId, emptyLabel) {
    if (!entries.length) {
      el.innerHTML = `<li class="side-empty">${esc(emptyLabel)}</li>`;
      return;
    }
    el.innerHTML = entries
      .map(
        (e) => `
      <li>
        <a href="#" data-filter-key="${esc(key)}" data-filter-value="${esc(e.id)}" class="${e.id === activeId ? 'active' : ''}">
          <span>${esc(e.name)}</span>
        </a>
      </li>`
      )
      .join('');
  }

  function renderActiveFilters() {
    const chips = [];
    if (state.library_id) {
      const l = libraries.find((x) => x.id === state.library_id);
      if (l) chips.push({ key: 'library_id', label: `Bibliothèque : ${l.name}` });
    }
    if (state.series_id) {
      const s = series.find((x) => x.id === state.series_id);
      if (s) chips.push({ key: 'series_id', label: `Série : ${s.name}` });
    }
    if (state.tag_id) {
      const t = tags.find((x) => x.id === state.tag_id);
      if (t) chips.push({ key: 'tag_id', label: `Tag : ${t.name}` });
    }
    activeFilters.innerHTML = chips
      .map(
        (c) => `<span class="tag tag-accent" data-clear="${esc(c.key)}">${esc(c.label)} <span class="x">✕</span></span>`
      )
      .join('');
    activeFilters.querySelectorAll('[data-clear]').forEach((el) => {
      el.addEventListener('click', () => {
        state[el.dataset.clear] = null;
        loadItems();
        syncSidebarActiveStates();
      });
    });
  }

  function syncSidebarActiveStates() {
    renderSideList(libraryList, libraries, 'library_id', state.library_id, 'Aucune bibliothèque indexée');
    renderSideList(seriesList, series, 'series_id', state.series_id, 'Aucune série');
    renderSideList(tagList, tags, 'tag_id', state.tag_id, 'Aucun tag');
    bindSidebarClicks();
    renderActiveFilters();
  }

  function bindSidebarClicks() {
    document.querySelectorAll('[data-filter-key]').forEach((el) => {
      el.addEventListener('click', (e) => {
        e.preventDefault();
        switchToBrowseMode();
        const key = el.dataset.filterKey;
        const value = Number(el.dataset.filterValue);
        state[key] = state[key] === value ? null : value;
        loadItems();
        syncSidebarActiveStates();
      });
    });
  }

  async function loadItems() {
    const params = new URLSearchParams();
    if (state.type) params.set('type', state.type);
    if (state.library_id) params.set('library_id', state.library_id);
    if (state.series_id) params.set('series_id', state.series_id);
    if (state.tag_id) params.set('tag_id', state.tag_id);
    if (state.q) params.set('q', state.q);
    params.set('sort', state.sort);
    params.set('dir', state.dir);
    params.set('limit', '120');

    try {
      const data = await fetchJson(`/api/items?${params.toString()}`);
      lastBrowseItems = data.items;
      renderGridTrimmed(grid, data.items, null);
      resultCount.textContent = `${data.total} résultat${data.total === 1 ? '' : 's'}`;
      emptyState.hidden = data.items.length !== 0;
      grid.hidden = data.items.length === 0;
    } catch (err) {
      grid.innerHTML = '';
      resultCount.textContent = '';
      emptyState.hidden = false;
      emptyState.textContent = `Impossible de charger la bibliothèque (${err.message}).`;
    }
  }

  const NAV_TYPE_LABELS = { comic: 'Bande Dessinée', ebook: 'Ebooks', magazine: 'Magazines', other: 'Fichiers' };
  const TYPE_ORDER = ['comic', 'ebook', 'magazine', 'other'];

  /** Only shows a tab for a type that at least one library actually has — an empty tab isn't useful. */
  function renderTypeTabs() {
    const presentTypes = TYPE_ORDER.filter((t) => libraries.some((l) => l.type === t));
    typeTabs.innerHTML = presentTypes
      .map(
        (t) => `<label class="seg-opt">
          <input type="radio" name="type" value="${t}" ${state.type === t ? 'checked' : ''} />
          <span>${esc(NAV_TYPE_LABELS[t])}</span>
        </label>`
      )
      .join('');
    typeTabs.querySelectorAll('input[name="type"]').forEach((input) => {
      input.addEventListener('change', () => {
        switchToBrowseMode();
        state.type = input.value;
        loadItems();
      });
    });
  }

  function switchToHomeMode() {
    state.mode = 'home';
    state.type = '';
    typeTabs.querySelectorAll('input[name="type"]').forEach((input) => { input.checked = false; });
    browseView.hidden = true;
    homeView.hidden = false;
    loadHome();
  }

  homeBtn.addEventListener('click', (e) => {
    e.preventDefault();
    switchToHomeMode();
  });

  userMenuTrigger.addEventListener('click', (e) => {
    e.stopPropagation();
    userMenuDropdown.hidden = !userMenuDropdown.hidden;
    userMenu.toggleAttribute('data-open', !userMenuDropdown.hidden);
  });
  document.addEventListener('click', () => {
    userMenuDropdown.hidden = true;
    userMenu.removeAttribute('data-open');
  });

  let searchTimer = null;
  searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    const q = searchInput.value.trim();
    if (q === '' && state.mode === 'home') return; // clearing an empty box shouldn't force a mode switch
    searchTimer = setTimeout(() => {
      switchToBrowseMode();
      state.q = q;
      loadItems();
    }, 250);
  });

  sortSelect.addEventListener('change', () => {
    const [sort, dir] = sortSelect.value.split(':');
    state.sort = sort;
    state.dir = dir;
    loadItems();
  });

  let resizeTimer = null;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      // Only the browse grid needs re-trimming on resize (its column
      // count changes with width) — the home shelves scroll horizontally
      // now, so their row count never depends on window width.
      if (state.mode === 'browse' && lastBrowseItems.length) {
        renderGridTrimmed(grid, lastBrowseItems, null);
      }
    }, 200);
  });

  Promise.all([
    fetchJson('/api/libraries').catch(() => []),
    fetchJson('/api/series').catch(() => []),
    fetchJson('/api/tags').catch(() => []),
  ]).then(([libs, ser, tg]) => {
    libraries = libs;
    series = ser;
    tags = tg;
    renderTypeTabs();
    syncSidebarActiveStates();
  });

  loadHome();
})();
