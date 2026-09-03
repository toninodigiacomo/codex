(function () {
  function esc(s) {
    const div = document.createElement('div');
    div.textContent = s == null ? '' : String(s);
    return div.innerHTML;
  }

  const TYPE_LABELS = { comic: 'BD', ebook: 'Ebook', magazine: 'Magazine', other: 'Autre' };
  const HOME_SHELF_TYPES = ['comic', 'ebook', 'magazine', 'other'];
  const HOME_SHELF_TYPES_FETCH_LIMIT = 60; // a full scrollable shelf's worth — how many are *visible* without scrolling is separate, see applyDisplaySettings

  let gridPageSize = 80; // replaced by display-settings once loaded, see applyDisplaySettings

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
    groupLevel: null, // 'library' | 'path' — only meaningful when mode === 'group'
    groupLibraryId: null, // the single library the éditeur flow is scoped to, once past the library tile grid
    groupLibraryName: null,
    groupSkippedLibraryLevel: false, // true when there was only one library of the type, so the library grid was never shown
    // The folder path chosen so far within the éditeur nav (éditeur, collection,
    // sous-collection... any depth) — null whenever we're outside that flow
    // entirely (plain type browse, home, search), so goBackFromGroupFlow can
    // tell "inside the éditeur flow" from "not" with a single check.
    groupPath: null,
    groupItemsPage: 1, // 1-indexed — the standalone-tomes portion of a group-level tile grid pages independently of state.page
  };

  let displaySettings = {
    show_publishers: false,
    thumbnail_width: 165,
    thumbnail_height: 238,
    grid_columns: 10,
    grid_page_size: 80,
    home_shelf_columns: 10,
    home_shelf_rows: 1,
  };

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
  const groupPagination = document.getElementById('groupPagination');
  browseBackBtn.addEventListener('click', goBackFromGroupFlow);

  async function fetchJson(url) {
    const res = await fetch(url);
    if (!res.ok) {
      // The API's error responses carry a JSON body ({error, detail}) with the
      // actual PHP exception message — surfacing it here means a 500 in the UI
      // says *why*, instead of just the status code.
      let detail = '';
      try {
        const body = await res.json();
        detail = body.detail || body.error || '';
      } catch (_) {
        // response wasn't JSON (e.g. a raw PHP fatal error page) — fall back to the status alone
      }
      throw new Error(`${url} → HTTP ${res.status}${detail ? ` (${detail})` : ''}`);
    }
    return res.json();
  }

  /**
   * Turns the fetched display settings into CSS custom properties, so
   * library.css can size tiles and cap grid/shelf dimensions without
   * hardcoding pixel values. --grid-max-width and --shelf-max-width are
   * computed here (in px) rather than left as a CSS calc() of several
   * variables, since the actual gap size is itself a design token
   * (--space-3) whose real pixel value is easiest to read back via
   * getComputedStyle rather than duplicated as a guess in more CSS.
   */
  function applyDisplaySettings(settings) {
    const root = document.documentElement;
    const thumbW = settings.thumbnail_width || 165;
    const thumbH = settings.thumbnail_height || Math.round((thumbW * 36) / 25);
    const gridCols = settings.grid_columns || 10;
    const shelfCols = settings.home_shelf_columns || 10;
    const shelfRows = settings.home_shelf_rows || 1;
    const colGap = parseFloat(getComputedStyle(root).getPropertyValue('--space-3')) || 12;

    root.style.setProperty('--thumb-w', `${thumbW}px`);
    root.style.setProperty('--thumb-h', `${thumbH}px`);
    root.style.setProperty('--grid-max-width', `${gridCols * thumbW + (gridCols - 1) * colGap}px`);
    root.style.setProperty('--shelf-max-width', `${shelfCols * thumbW + (shelfCols - 1) * colGap}px`);
    root.style.setProperty('--shelf-rows', String(shelfRows));
  }

  function coverMarkup(item) {
    if (item.cover_path) {
      return `<img src="${esc(item.cover_path)}" alt="" loading="lazy" decoding="async" />`;
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
    if (state.groupPath && state.groupPath.length) params.set('path', JSON.stringify(state.groupPath));
    params.set('sort', state.sort);
    params.set('dir', state.dir);
    params.set('limit', String(gridPageSize));
    params.set('offset', String((state.page - 1) * gridPageSize));

    try {
      const data = await fetchJson(`/api/items?${params.toString()}`);
      lastBrowseItems = data.items;
      renderGridTrimmed(grid, data.items, null);
      resultCount.textContent = `${data.total} résultat${data.total === 1 ? '' : 's'}`;
      emptyState.hidden = data.items.length !== 0;
      grid.hidden = data.items.length === 0;
      renderPagination(data.total);
      browseBackBtn.hidden = state.groupPath === null;
      browseBackBtn.textContent = state.groupPath && state.groupPath.length ? `← ${state.groupPath[state.groupPath.length - 1]}` : '← Retour';
    } catch (err) {
      grid.innerHTML = '';
      resultCount.textContent = '';
      emptyState.hidden = false;
      emptyState.textContent = `Impossible de charger la bibliothèque (${err.message}).`;
      pagination.hidden = true;
    }
  }

  /** 12-13k BD ne tiennent jamais dans une seule page — « première/précédent/n sur N/suivant/dernière ». */
  /** Generic — used for the browse grid's own pagination and for a group-level view's standalone-items pagination alike. */
  function renderPaginationInto(container, currentPage, total, onChange) {
    const totalPages = Math.max(1, Math.ceil(total / gridPageSize));
    if (totalPages <= 1) {
      container.hidden = true;
      container.innerHTML = '';
      return;
    }
    container.hidden = false;
    container.innerHTML = `
      <button type="button" class="btn btn-secondary" data-page-action="first" ${currentPage <= 1 ? 'disabled' : ''} aria-label="Première page">«</button>
      <button type="button" class="btn btn-secondary" data-page-action="prev" ${currentPage <= 1 ? 'disabled' : ''} aria-label="Page précédente">‹</button>
      <span class="page-indicator">${currentPage} / ${totalPages}</span>
      <button type="button" class="btn btn-secondary" data-page-action="next" ${currentPage >= totalPages ? 'disabled' : ''} aria-label="Page suivante">›</button>
      <button type="button" class="btn btn-secondary" data-page-action="last" ${currentPage >= totalPages ? 'disabled' : ''} aria-label="Dernière page">»</button>
    `;
    container.querySelectorAll('[data-page-action]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const action = btn.dataset.pageAction;
        let next = currentPage;
        if (action === 'first') next = 1;
        else if (action === 'prev') next = Math.max(1, currentPage - 1);
        else if (action === 'next') next = Math.min(totalPages, currentPage + 1);
        else if (action === 'last') next = totalPages;
        onChange(next);
      });
    });
  }

  function renderPagination(total) {
    renderPaginationInto(pagination, state.page, total, (next) => {
      state.page = next;
      loadItems();
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
          state.groupPath = null;
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
          ${g.thumbnail ? `<img src="${esc(g.thumbnail)}" alt="" loading="lazy" decoding="async" />` : `<div class="fallback">${esc(g.name)}</div>`}
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
    groupPagination.hidden = true;
  }

  /** Arrow click: skips straight to the éditeur grid when the type has only one library, per admin's confirmed behaviour. */
  async function startGroupFlow(type) {
    try {
      const libs = await fetchJson(`/api/library-groups?type=${encodeURIComponent(type)}`);
      if (libs.length === 1) {
        openPathLevel(type, libs[0].id, libs[0].name, [], true);
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
    state.groupPath = null;
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
          openPathLevel(type, Number(tile.dataset.groupTileId), tile.dataset.groupTile, [], false);
        });
      });
    } catch (err) {
      groupEmptyState.hidden = false;
      groupEmptyState.textContent = `Erreur : ${err.message}`;
    }
  }

  /**
   * The recursive éditeur/collection/sous-collection grid: the folders
   * directly under $path (éditeurs when $path is empty), mixed with any
   * tomes sitting directly in that folder with no further subfolder. A
   * folder with no subfolders at all (a leaf — an éditeur or collection
   * with nothing nested deeper) never gets an empty tile grid: it skips
   * straight to the full paginated browse instead, however deep it is.
   *
   * Subfolders are never paginated — a folder count realistically never
   * gets anywhere near gridPageSize. The standalone tomes shown alongside
   * them can, though (a big éditeur's loose one-shots), so that part
   * pages independently via state.groupItemsPage; the subfolder tiles
   * stay pinned at the top of every page.
   */
  async function openPathLevel(type, libraryId, libraryName, path, skippedLibraryLevel, itemsPage) {
    state.mode = 'group';
    state.type = type;
    state.groupLevel = 'path';
    state.groupLibraryId = libraryId;
    state.groupLibraryName = libraryName;
    state.groupSkippedLibraryLevel = skippedLibraryLevel;
    state.groupPath = path;
    state.groupItemsPage = itemsPage || 1;

    const pathQuery = encodeURIComponent(JSON.stringify(path));
    const baseParams = `type=${encodeURIComponent(type)}&library_id=${encodeURIComponent(libraryId)}&path=${pathQuery}`;

    try {
      const subfolders = await fetchJson(`/api/subfolders?${baseParams}`);
      if (!subfolders.length && state.groupItemsPage === 1) {
        openFilteredBrowse(type, libraryId, path);
        return;
      }
      showGroupView(path.length ? `Collections — ${path[path.length - 1]}` : `Éditeurs — ${libraryName}`);
      const itemsOffset = (state.groupItemsPage - 1) * gridPageSize;
      const standalone = await fetchJson(`/api/items?${baseParams}&exact=1&limit=${gridPageSize}&offset=${itemsOffset}`);
      groupGrid.innerHTML = subfolders.map((g) => groupTileHtml(g)).join('') + standalone.items.map(itemCardHtml).join('');
      groupGrid.querySelectorAll('[data-group-tile]').forEach((tile) => {
        tile.addEventListener('click', () => {
          openPathLevel(type, libraryId, libraryName, [...path, tile.dataset.groupTile], skippedLibraryLevel);
        });
      });
      renderPaginationInto(groupPagination, state.groupItemsPage, standalone.total, (next) => {
        openPathLevel(type, libraryId, libraryName, path, skippedLibraryLevel, next);
      });
    } catch (err) {
      showGroupView(path.length ? `Collections — ${path[path.length - 1]}` : `Éditeurs — ${libraryName}`);
      groupEmptyState.hidden = false;
      groupEmptyState.textContent = `Erreur : ${err.message}`;
      groupPagination.hidden = true;
    }
  }

  function openFilteredBrowse(type, libraryId, path) {
    switchToBrowseMode();
    state.type = type;
    state.page = 1;
    state.groupLibraryId = libraryId;
    state.groupPath = path;
    typeTabs.querySelectorAll('input[name="type"]').forEach((input) => { input.checked = input.value === type; });
    loadItems();
  }

  /**
   * Where the back arrow goes depends on how deep the current screen sits
   * in the éditeur flow — one path segment shorter each time, all the way
   * back to the library grid (or home, if that grid was skipped). Outside
   * the flow entirely (groupPath === null), it's always home.
   */
  function goBackFromGroupFlow() {
    if (state.groupPath !== null && state.groupPath.length > 0) {
      openPathLevel(state.type, state.groupLibraryId, state.groupLibraryName, state.groupPath.slice(0, -1), state.groupSkippedLibraryLevel);
    } else if (state.groupPath !== null) {
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
    state.groupPath = null;
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
    fetchJson('/api/display-settings').catch(() => displaySettings),
  ]).then(([libs, ser, tg, disp]) => {
    libraries = libs;
    series = ser;
    tags = tg;
    displaySettings = disp;
    gridPageSize = disp.grid_page_size || 80;
    applyDisplaySettings(disp);
    renderTypeTabs();
    syncSidebarActiveStates();
    // Deferred until here rather than fired in parallel at the top of the
    // file: the home shelves' tile size and visible width both depend on
    // the CSS variables applyDisplaySettings just set.
    loadHome();
  });
})();
