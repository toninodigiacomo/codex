(function () {
  function esc(s) {
    const div = document.createElement('div');
    div.textContent = s == null ? '' : String(s);
    return div.innerHTML;
  }

  const TYPE_LABELS = { comic: 'BD', ebook: 'Ebook', magazine: 'Magazine', other: 'Autre' };
  const HOME_SHELF_TYPES = ['comic', 'ebook', 'magazine', 'other'];
  const HOME_SHELF_TYPES_FETCH_LIMIT = 60; // a full scrollable shelf's worth, per the "let's say 60" request

  const BROWSE_PAGE_SIZE = 120;

  const state = {
    mode: 'home', // 'home' | 'browse' | 'group'
    type: '',
    library_id: null, // sidebar "Bibliothèques" filter — separate from the éditeur-flow scoping below
    series_id: null,
    tag_id: null,
    q: '',
    sort: 'added_at',
    dir: 'DESC',
    page: 1, // 1-indexed, browse mode only — reset to 1 whenever a filter/search/sort changes
    groupLevel: null, // 'library' | 'publisher' | 'collection' — only meaningful when mode === 'group'
    groupLibraryId: null, // the single library the éditeur flow is scoped to, once past the library tile grid
    groupLibraryName: null,
    groupSkippedLibraryLevel: false, // true when there was only one library of the type, so the library grid was never shown
    groupPublisher: null, // the publisher a collection-level group view, or a filtered browse, is scoped to
    groupCollection: null, // the collection a filtered browse is scoped to
  };

  let displaySettings = { show_publishers: false };

  let libraries = [];
  let series = [];
  let tags = [];
  let lastBrowseItems = []; // kept so a window resize can re-trim without re-fetching

  const homeView = document.getElementById('homeView');
  const browseView = document.getElementById('browseView');
  const groupView = document.getElementById('groupView');
  const groupGrid = document.getElementById('groupGrid');
  const groupEmptyState = document.getElementById('groupEmptyState');
  const groupViewTitle = document.getElementById('groupViewTitle');
  const groupBackBtn = document.getElementById('groupBackBtn');
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
  const browseBackBtn = document.getElementById('browseBackBtn');
  const pagination = document.getElementById('pagination');
  browseBackBtn.addEventListener('click', goBackFromGroupFlow);

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
    state.mode = 'browse';
    homeView.hidden = true;
    groupView.hidden = true;
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
        state.page = 1;
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
        state.page = 1;
        loadItems();
        syncSidebarActiveStates();
      });
    });
  }

  async function loadItems() {
    const params = new URLSearchParams();
    if (state.type) params.set('type', state.type);
    if (state.library_id) params.set('library_id', state.library_id);
    else if (state.groupLibraryId) params.set('library_id', state.groupLibraryId);
    if (state.series_id) params.set('series_id', state.series_id);
    if (state.tag_id) params.set('tag_id', state.tag_id);
    if (state.q) params.set('q', state.q);
    if (state.groupPublisher) params.set('publisher', state.groupPublisher);
    if (state.groupCollection) params.set('collection', state.groupCollection);
    params.set('sort', state.sort);
    params.set('dir', state.dir);
    params.set('limit', String(BROWSE_PAGE_SIZE));
    params.set('offset', String((state.page - 1) * BROWSE_PAGE_SIZE));

    try {
      const data = await fetchJson(`/api/items?${params.toString()}`);
      lastBrowseItems = data.items;
      renderGridTrimmed(grid, data.items, null);
      resultCount.textContent = `${data.total} résultat${data.total === 1 ? '' : 's'}`;
      emptyState.hidden = data.items.length !== 0;
      grid.hidden = data.items.length === 0;
      renderPagination(data.total);
      browseBackBtn.hidden = !(state.groupPublisher || state.groupCollection);
      browseBackBtn.textContent = state.groupCollection ? `← ${state.groupCollection}` : (state.groupPublisher ? `← ${state.groupPublisher}` : '← Retour');
    } catch (err) {
      grid.innerHTML = '';
      resultCount.textContent = '';
      emptyState.hidden = false;
      emptyState.textContent = `Impossible de charger la bibliothèque (${err.message}).`;
      pagination.hidden = true;
    }
  }

  /** 12-13k BD ne tiennent jamais dans une seule page — « première/précédent/n sur N/suivant/dernière ». */
  function renderPagination(total) {
    const totalPages = Math.max(1, Math.ceil(total / BROWSE_PAGE_SIZE));
    if (totalPages <= 1) {
      pagination.hidden = true;
      pagination.innerHTML = '';
      return;
    }
    pagination.hidden = false;
    pagination.innerHTML = `
      <button type="button" class="btn btn-secondary" data-page-action="first" ${state.page <= 1 ? 'disabled' : ''} aria-label="Première page">«</button>
      <button type="button" class="btn btn-secondary" data-page-action="prev" ${state.page <= 1 ? 'disabled' : ''} aria-label="Page précédente">‹</button>
      <span class="page-indicator">${state.page} / ${totalPages}</span>
      <button type="button" class="btn btn-secondary" data-page-action="next" ${state.page >= totalPages ? 'disabled' : ''} aria-label="Page suivante">›</button>
      <button type="button" class="btn btn-secondary" data-page-action="last" ${state.page >= totalPages ? 'disabled' : ''} aria-label="Dernière page">»</button>
    `;
    pagination.querySelectorAll('[data-page-action]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const action = btn.dataset.pageAction;
        if (action === 'first') state.page = 1;
        else if (action === 'prev') state.page = Math.max(1, state.page - 1);
        else if (action === 'next') state.page = Math.min(totalPages, state.page + 1);
        else if (action === 'last') state.page = totalPages;
        loadItems();
      });
    });
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
        const type = input.value;
        if (displaySettings.show_publishers) {
          startGroupFlow(type);
        } else {
          switchToBrowseMode();
          state.type = type;
          state.page = 1;
          state.groupLibraryId = null;
          state.groupPublisher = null;
          state.groupCollection = null;
          loadItems();
        }
      });
    });
  }

  function groupTileHtml(g, id) {
    const idAttr = id !== undefined ? ` data-group-tile-id="${esc(id)}"` : '';
    return `
      <div class="item-card" data-group-tile="${esc(g.name)}"${idAttr} style="cursor:pointer;">
        <div class="item-cover">
          ${g.thumbnail ? `<img src="${esc(g.thumbnail)}" alt="" loading="lazy" />` : `<div class="fallback">${esc(g.name)}</div>`}
          <span class="group-count-badge">${g.count}</span>
        </div>
        <div class="item-title">${esc(g.name)}</div>
      </div>`;
  }

  function showGroupView(title) {
    typeTabs.querySelectorAll('input[name="type"]').forEach((input) => { input.checked = input.value === state.type; });
    homeView.hidden = true;
    browseView.hidden = true;
    groupView.hidden = false;
    groupViewTitle.textContent = title;
    groupBackBtn.textContent = '← Retour';
    groupGrid.innerHTML = '';
    groupEmptyState.hidden = true;
  }

  /** Arrow click: skips straight to the éditeur grid when the type has only one library, per admin's confirmed behaviour. */
  async function startGroupFlow(type) {
    try {
      const libs = await fetchJson(`/api/library-groups?type=${encodeURIComponent(type)}`);
      if (libs.length === 1) {
        openPublisherLevel(type, libs[0].id, libs[0].name, true);
      } else {
        openLibraryLevel(type, libs);
      }
    } catch (err) {
      openLibraryLevel(type, []);
    }
  }

  /** First tile grid: one tile per library of $type (never merged, even when two libraries share a type). */
  async function openLibraryLevel(type, preloaded) {
    state.mode = 'group';
    state.type = type;
    state.groupLevel = 'library';
    state.groupLibraryId = null;
    state.groupLibraryName = null;
    state.groupSkippedLibraryLevel = false;
    state.groupPublisher = null;
    state.groupCollection = null;
    showGroupView('Bibliothèques');

    try {
      const groups = preloaded || (await fetchJson(`/api/library-groups?type=${encodeURIComponent(type)}`));
      if (!groups.length) {
        groupEmptyState.hidden = false;
        return;
      }
      groupGrid.innerHTML = groups.map((g) => groupTileHtml(g, g.id)).join('');
      groupGrid.querySelectorAll('[data-group-tile]').forEach((tile) => {
        tile.addEventListener('click', () => {
          openPublisherLevel(type, Number(tile.dataset.groupTileId), tile.dataset.groupTile, false);
        });
      });
    } catch (err) {
      groupEmptyState.hidden = false;
      groupEmptyState.textContent = `Erreur : ${err.message}`;
    }
  }

  /** Second tile grid: éditeurs within one specific library. */
  async function openPublisherLevel(type, libraryId, libraryName, skippedLibraryLevel) {
    state.mode = 'group';
    state.type = type;
    state.groupLevel = 'publisher';
    state.groupLibraryId = libraryId;
    state.groupLibraryName = libraryName;
    state.groupSkippedLibraryLevel = skippedLibraryLevel;
    state.groupPublisher = null;
    state.groupCollection = null;
    showGroupView(`Éditeurs — ${libraryName}`);

    try {
      const groups = await fetchJson(`/api/publishers?type=${encodeURIComponent(type)}&library_id=${encodeURIComponent(libraryId)}`);
      if (!groups.length) {
        groupEmptyState.hidden = false;
        return;
      }
      groupGrid.innerHTML = groups.map((g) => groupTileHtml(g)).join('');
      groupGrid.querySelectorAll('[data-group-tile]').forEach((tile) => {
        tile.addEventListener('click', () => openCollectionLevel(type, tile.dataset.groupTile));
      });
    } catch (err) {
      groupEmptyState.hidden = false;
      groupEmptyState.textContent = `Erreur : ${err.message}`;
    }
  }

  /** Third grid: an éditeur's collections, plus the standalone tomes sitting directly in its folder. */
  async function openCollectionLevel(type, publisher) {
    state.mode = 'group';
    state.type = type;
    state.groupLevel = 'collection';
    state.groupPublisher = publisher;
    state.groupCollection = null;
    showGroupView(`Collections — ${publisher}`);

    try {
      const params = `type=${encodeURIComponent(type)}&library_id=${encodeURIComponent(state.groupLibraryId)}&publisher=${encodeURIComponent(publisher)}`;
      const [collections, standalone] = await Promise.all([
        fetchJson(`/api/collections?${params}`),
        fetchJson(`/api/items?${params}&no_collection=1&limit=200`),
      ]);
      if (!collections.length && !standalone.items.length) {
        groupEmptyState.hidden = false;
        return;
      }
      groupGrid.innerHTML = collections.map((g) => groupTileHtml(g)).join('') + standalone.items.map(itemCardHtml).join('');
      groupGrid.querySelectorAll('[data-group-tile]').forEach((tile) => {
        tile.addEventListener('click', () => openFilteredBrowse(type, state.groupLibraryId, publisher, tile.dataset.groupTile));
      });
    } catch (err) {
      groupEmptyState.hidden = false;
      groupEmptyState.textContent = `Erreur : ${err.message}`;
    }
  }

  function openFilteredBrowse(type, libraryId, publisher, collection) {
    switchToBrowseMode();
    state.type = type;
    state.page = 1;
    state.groupLibraryId = libraryId;
    state.groupPublisher = publisher;
    state.groupCollection = collection;
    typeTabs.querySelectorAll('input[name="type"]').forEach((input) => { input.checked = input.value === type; });
    loadItems();
  }

  /** Where the back arrow goes depends on how the current screen was reached. */
  function goBackFromGroupFlow() {
    if (state.mode === 'browse' && state.groupCollection) {
      openCollectionLevel(state.type, state.groupPublisher);
    } else if (state.mode === 'group' && state.groupLevel === 'collection') {
      openPublisherLevel(state.type, state.groupLibraryId, state.groupLibraryName, state.groupSkippedLibraryLevel);
    } else if (state.mode === 'group' && state.groupLevel === 'publisher') {
      if (state.groupSkippedLibraryLevel) {
        switchToHomeMode();
      } else {
        openLibraryLevel(state.type);
      }
    } else {
      switchToHomeMode();
    }
  }

  groupBackBtn.addEventListener('click', goBackFromGroupFlow);

  function switchToHomeMode() {
    state.mode = 'home';
    state.type = '';
    state.page = 1;
    state.groupLibraryId = null;
    state.groupLibraryName = null;
    state.groupSkippedLibraryLevel = false;
    state.groupPublisher = null;
    state.groupCollection = null;
    typeTabs.querySelectorAll('input[name="type"]').forEach((input) => { input.checked = false; });
    browseView.hidden = true;
    groupView.hidden = true;
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
      state.page = 1;
      loadItems();
    }, 250);
  });

  sortSelect.addEventListener('change', () => {
    const [sort, dir] = sortSelect.value.split(':');
    state.sort = sort;
    state.dir = dir;
    state.page = 1;
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
    fetchJson('/api/display-settings').catch(() => ({ show_publishers: false })),
  ]).then(([libs, ser, tg, disp]) => {
    libraries = libs;
    series = ser;
    tags = tg;
    displaySettings = disp;
    renderTypeTabs();
    syncSidebarActiveStates();
  });

  loadHome();
})();
