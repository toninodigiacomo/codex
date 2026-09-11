(function () {
  function esc(s) {
    const div = document.createElement('div');
    div.textContent = s == null ? '' : String(s);
    return div.innerHTML;
  }

  const TYPE_LABEL_KEYS = { comic: 'type.comic_short', ebook: 'type.ebook_short', magazine: 'type.magazine_short', other: 'type.other_short' };
  const HOME_SHELF_TYPES = ['comic', 'ebook', 'magazine', 'other'];

  let gridPageSize = 80; // replaced by display-settings once loaded, see applyDisplaySettings
  let homeShelfFetchLimit = 60; // replaced by display-settings once loaded, see applyDisplaySettings

  const state = {
    mode: 'home', // 'home' | 'browse' | 'group'
    type: '',
    library_id: null, // sidebar "Bibliothèques" filter — separate from the éditeur-flow scoping below
    series_id: null,
    tag_id: null,
    favorites: false, // sidebar "Favoris" filter — this user's own starred items (Favorites.php)
    q: '',
    sort: 'filename',
    dir: 'ASC',
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
    home_shelf_fetch_limit: 60,
  };

  let libraries = [];
  // series_id stays in state (below) in case something sets it again later,
  // but nothing in this file populates a series list or links to one anymore
  // — removed from the sidebar, too many entries to be useful as a flat list.
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
  const homeEmptyState = document.getElementById('homeEmptyState');
  const mobileMenuBtn = document.getElementById('mobileMenuBtn');
  const mobileNavDrawer = document.getElementById('mobileNavDrawer');
  const mobileNavBackdrop = document.getElementById('mobileNavBackdrop');
  const appShell = document.querySelector('.app-shell');
  const appBody = document.querySelector('.app-body');
  const grid = document.getElementById('itemGrid');
  const emptyState = document.getElementById('emptyState');
  const resultCount = document.getElementById('resultCount');
  const activeFilters = document.getElementById('activeFilters');
  const libraryList = document.getElementById('libraryList');
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

  /**
   * A compact snapshot of "where the user currently is" — attached to
   * every item link (see itemCardHtml below) so item.php's own "←
   * Bibliothèque" link can send them back to this exact spot instead of
   * always landing on the home page. Deliberately just the state fields
   * that matter for re-entering a view, not the whole `state` object
   * (grid-density/pagination-derived stuff like groupItemsPage still
   * matters, but there's no reason to carry along things loadItems()
   * recomputes on its own, like the fetched item list itself).
   */
  function backContext() {
    return JSON.stringify({
      mode: state.mode,
      type: state.type,
      library_id: state.library_id,
      series_id: state.series_id,
      tag_id: state.tag_id,
      favorites: state.favorites,
      q: state.q,
      sort: state.sort,
      dir: state.dir,
      page: state.page,
      groupLevel: state.groupLevel,
      groupLibraryId: state.groupLibraryId,
      groupLibraryName: state.groupLibraryName,
      groupSkippedLibraryLevel: state.groupSkippedLibraryLevel,
      groupPath: state.groupPath,
      groupItemsPage: state.groupItemsPage,
    });
  }

  function itemCardHtml(item) {
    return `
      <a class="item-card" href="item.php?id=${esc(item.id)}&back=${encodeURIComponent(backContext())}">
        <div class="item-cover">
          <span class="type-chip">${esc(TYPE_LABEL_KEYS[item.type] ? t(TYPE_LABEL_KEYS[item.type]) : item.type)}</span>
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
  /** $maxRows caps how many full rows to show before the rest gets trimmed off — used by the home shelves so a partial row doesn't look broken right where horizontal scrolling would start. $maxRows === null means no cap at all (the main paginated browse grid): every item passed in is kept, never rounded down to a row multiple — the bug this comment replaces did that unconditionally, silently dropping a page's trailing partial row (e.g. 18 results in 10 columns kept only the first 10) even though nothing asked for a cap. */
  function renderGridTrimmed(gridEl, items, maxRows) {
    gridEl.innerHTML = items.map(itemCardHtml).join('');
    if (!items.length || !maxRows) {
      return items.length;
    }
    const cols = getComputedStyle(gridEl).gridTemplateColumns.split(' ').filter(Boolean).length || 1;
    const rowCap = maxRows * cols;
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
        fetchJson(`/api/items?type=${type}&sort=added_at&dir=DESC&limit=${homeShelfFetchLimit}`).catch(() => ({ items: [] }))
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
    if (state.favorites) {
      chips.push({ key: 'favorites', label: t('library.chip_favorites') });
    }
    if (state.library_id) {
      const l = libraries.find((x) => x.id === state.library_id);
      if (l) chips.push({ key: 'library_id', label: t('library.chip_library', { name: l.name }) });
    }
    if (state.tag_id) {
      const tagObj = tags.find((x) => x.id === state.tag_id);
      if (tagObj) chips.push({ key: 'tag_id', label: t('library.chip_tag', { name: tagObj.name }) });
    }
    activeFilters.innerHTML = chips
      .map(
        (c) => `<span class="tag tag-accent" data-clear="${esc(c.key)}">${esc(c.label)} <span class="x">✕</span></span>`
      )
      .join('');
    activeFilters.querySelectorAll('[data-clear]').forEach((el) => {
      el.addEventListener('click', () => {
        state[el.dataset.clear] = el.dataset.clear === 'favorites' ? false : null;
        if (el.dataset.clear === 'favorites') {
          document.getElementById('favoritesTab').checked = false;
        }
        state.page = 1;
        loadItems();
        syncSidebarActiveStates();
        pushHistoryState();
      });
    });
  }

  function syncSidebarActiveStates() {
    renderSideList(libraryList, libraries, 'library_id', state.library_id, t('library.no_library_indexed'));
    renderSideList(tagList, tags, 'tag_id', state.tag_id, t('library.no_tag'));
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
        // Same reasoning as the type-tab handler above, in reverse: a
        // sidebar filter picks a specific library/series/tag directly, so
        // any type tab selected up top no longer means anything sensible
        // combined with it — clear it rather than silently ANDing the two
        // into a filter that can only ever match nothing.
        state.type = '';
        state.favorites = false;
        state.groupPath = null;
        state.groupLibraryId = null;
        typeTabs.querySelectorAll('input[name="type"]').forEach((input) => { input.checked = false; });
        document.getElementById('favoritesTab').checked = false;
        loadItems();
        syncSidebarActiveStates();
        pushHistoryState();
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
    if (state.favorites) params.set('favorites', '1');
    if (state.q) params.set('q', state.q);
    if (state.mode === 'browse' && state.groupPath && state.groupPath.length) {
      // Only meaningful while browsing *inside* a group-flow drill-down
      // (state.mode stays 'browse' there too, for the standalone items
      // sitting alongside subfolder tiles at that level) — a stale
      // groupPath left over from an earlier visit must never leak into an
      // ordinary sidebar/type-tab browse, which has nothing to do with
      // any folder depth at all.
      params.set('path', JSON.stringify(state.groupPath));
    }
    params.set('sort', state.sort);
    params.set('dir', state.dir);
    params.set('limit', String(gridPageSize));
    params.set('offset', String((state.page - 1) * gridPageSize));

    // Cleared immediately, before the fetch even starts — otherwise the
    // previous view's covers stay on screen for the length of the request
    // and visibly swap out once it resolves, which reads as a glitch more
    // than a loading state.
    grid.innerHTML = '';
    resultCount.textContent = t('common.loading');
    emptyState.hidden = true;
    pagination.hidden = true;

    try {
      const data = await fetchJson(`/api/items?${params.toString()}`);
      lastBrowseItems = data.items;
      renderGridTrimmed(grid, data.items, null);
      resultCount.textContent = t(data.total === 1 ? 'library.result_count_one' : 'library.result_count_other', { count: data.total });
      emptyState.hidden = data.items.length !== 0;
      grid.hidden = data.items.length === 0;
      renderPagination(data.total);
      browseBackBtn.hidden = state.groupPath === null;
      browseBackBtn.textContent = state.groupPath && state.groupPath.length ? `← ${state.groupPath[state.groupPath.length - 1]}` : t('library.back');
    } catch (err) {
      grid.innerHTML = '';
      resultCount.textContent = '';
      emptyState.hidden = false;
      emptyState.textContent = t('library.load_error', { message: err.message });
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
      <button type="button" class="btn btn-secondary" data-page-action="first" ${currentPage <= 1 ? 'disabled' : ''} aria-label="${esc(t('library.page_first'))}">«</button>
      <button type="button" class="btn btn-secondary" data-page-action="prev" ${currentPage <= 1 ? 'disabled' : ''} aria-label="${esc(t('library.page_prev'))}">‹</button>
      <span class="page-indicator">${currentPage} / ${totalPages}</span>
      <button type="button" class="btn btn-secondary" data-page-action="next" ${currentPage >= totalPages ? 'disabled' : ''} aria-label="${esc(t('library.page_next'))}">›</button>
      <button type="button" class="btn btn-secondary" data-page-action="last" ${currentPage >= totalPages ? 'disabled' : ''} aria-label="${esc(t('library.page_last'))}">»</button>
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
      pushHistoryState();
    });
  }

  const TYPE_ORDER = ['comic', 'ebook', 'magazine', 'other'];
  const NAV_TYPE_KEYS = { comic: 'type.comic', ebook: 'type.ebook', magazine: 'type.magazine', other: 'type.other' };

  /** Only shows a tab for a type that at least one library actually has — an empty tab isn't useful. Favoris is appended last, styled identically (same .seg-opt) but as a checkbox, not part of the type radio group — it's an orthogonal filter, not a fourth type. */
  function renderTypeTabs() {
    const presentTypes = TYPE_ORDER.filter((ty) => libraries.some((l) => l.type === ty));
    typeTabs.innerHTML = presentTypes
      .map(
        (ty) => `<label class="seg-opt">
          <input type="radio" name="type" value="${ty}" ${state.type === ty ? 'checked' : ''} />
          <span>${esc(t(NAV_TYPE_KEYS[ty]))}</span>
        </label>`
      )
      .join('') + `<label class="seg-opt">
          <input type="checkbox" id="favoritesTab" ${state.favorites ? 'checked' : ''} />
          <span>★ ${esc(t('library.chip_favorites'))}</span>
        </label>`;
    typeTabs.querySelectorAll('input[name="type"]').forEach((input) => {
      input.addEventListener('change', () => {
        const type = input.value;
        // Same one-scope-at-a-time rule applies here too, regardless of
        // which branch handles the actual navigation below — a sidebar
        // library/tag left highlighted after using a top tab was exactly
        // that rule silently not applying to this branch.
        state.library_id = null;
        state.series_id = null;
        state.tag_id = null;
        state.favorites = false;
        document.getElementById('favoritesTab').checked = false;
        syncSidebarActiveStates();
        if (displaySettings.show_publishers) {
          startGroupFlow(type);
        } else {
          switchToBrowseMode();
          state.type = type;
          state.page = 1;
          state.groupLibraryId = null;
          state.groupPath = null;
          loadItems();
          pushHistoryState();
        }
      });
    });

    document.getElementById('favoritesTab').addEventListener('change', (e) => {
      switchToBrowseMode();
      state.favorites = e.target.checked;
      state.page = 1;
      if (state.favorites) {
        // Same one-scope-at-a-time principle as the type tabs vs. sidebar
        // filters — Favoris spans every type/library, so combining it with
        // one of those would just narrow it back down in a confusing way.
        state.type = '';
        state.library_id = null;
        state.tag_id = null;
        state.groupPath = null;
        state.groupLibraryId = null;
        typeTabs.querySelectorAll('input[name="type"]').forEach((input) => { input.checked = false; });
      }
      loadItems();
      syncSidebarActiveStates();
      pushHistoryState();
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
    groupBackBtn.textContent = t('library.back');
    groupGrid.innerHTML = '';
    // Centering (see library.css) only makes sense at the very first
    // tile level (one tile per library of this type) — a short row at
    // any deeper level (éditeurs, collections) stays left-aligned, same
    // as the standalone-items browse grid it can also show alongside
    // subfolder tiles.
    groupGrid.classList.toggle('group-grid-centered', state.groupLevel === 'library');
    groupEmptyState.hidden = true;
    groupPagination.hidden = true;
  }

  /** Arrow click: skips straight to the éditeur grid when the type has only one library, per admin's confirmed behaviour. */
  async function startGroupFlow(type, skipPush) {
    try {
      const libs = await fetchJson(`/api/library-groups?type=${encodeURIComponent(type)}`);
      if (libs.length === 1) {
        openPathLevel(type, libs[0].id, libs[0].name, [], true, 1, skipPush);
      } else {
        openLibraryLevel(type, libs, skipPush);
      }
    } catch (err) {
      openLibraryLevel(type, [], skipPush);
    }
  }

  /** First tile grid: one tile per library of $type (never merged, even when two libraries share a type). */
  async function openLibraryLevel(type, preloaded, skipPush) {
    state.mode = 'group';
    state.type = type;
    state.groupLevel = 'library';
    state.groupLibraryId = null;
    state.groupLibraryName = null;
    state.groupSkippedLibraryLevel = false;
    state.groupPath = null;
    showGroupView(t('library.sidebar_libraries'));
    if (!skipPush) pushHistoryState();

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
      groupEmptyState.textContent = t('library.generic_error', { message: err.message });
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
   *
   * $skipPush: true only when applyContext() itself is calling this to
   * restore a saved view (the initial ?restore= link, or a popstate) —
   * every *user-initiated* call (a tile click, pagination, the arrow from
   * startGroupFlow) leaves it false so pushHistoryState() below records a
   * real history entry, the whole point being that the browser's own
   * Back button can step back through these the same way item.php's own
   * link already could.
   */
  async function openPathLevel(type, libraryId, libraryName, path, skippedLibraryLevel, itemsPage, skipPush) {
    state.mode = 'group';
    state.type = type;
    state.groupLevel = 'path';
    state.groupLibraryId = libraryId;
    state.groupLibraryName = libraryName;
    state.groupSkippedLibraryLevel = skippedLibraryLevel;
    state.groupPath = path;
    state.groupItemsPage = itemsPage || 1;

    // Cleared immediately, before either fetch starts — otherwise the
    // previous éditeur/collection's tiles stay on screen for the length of
    // the request and visibly swap out once it resolves, which reads as a
    // glitch more than a loading state (same fix as loadItems').
    showGroupView(path.length ? t('library.collections_title', { name: path[path.length - 1] }) : t('library.editors_title', { name: libraryName }));
    if (!skipPush) pushHistoryState();

    const pathQuery = encodeURIComponent(JSON.stringify(path));
    const baseParams = `type=${encodeURIComponent(type)}&library_id=${encodeURIComponent(libraryId)}&path=${pathQuery}`;

    try {
      const subfolders = await fetchJson(`/api/subfolders?${baseParams}`);
      if (!subfolders.length && state.groupItemsPage === 1) {
        openFilteredBrowse(type, libraryId, path);
        return;
      }
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
      groupEmptyState.hidden = false;
      groupEmptyState.textContent = t('library.generic_error', { message: err.message });
      groupPagination.hidden = true;
    }
  }

  /** Only ever reached from openPathLevel's own leaf-folder fallback right above — that call already pushed a history entry for this navigation moment, so this never pushes its own on top of it. */
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
    pushHistoryState();
  }

  homeBtn.addEventListener('click', (e) => {
    e.preventDefault();
    switchToHomeMode();
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
      pushHistoryState();
    }, 250);
  });

  sortSelect.addEventListener('change', () => {
    const [sort, dir] = sortSelect.value.split(':');
    state.sort = sort;
    state.dir = dir;
    state.page = 1;
    loadItems();
    pushHistoryState();
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
      updateMobileNavLayout();
    }, 200);
  });

  /**
   * #typeTabs (in .nav) and .sidebar (in .app-body) are two completely
   * separate DOM subtrees on desktop — there's no shared parent to wrap
   * them in for a combined mobile drawer without breaking the desktop
   * grid/flex layout. Rather than duplicate their markup (and every
   * click handler already bound to the real ones) for a mobile-only
   * copy, this moves the *actual* elements into #mobileNavDrawer when
   * the viewport is mobile-sized, and back to their original parents
   * once it isn't — appendChild() relocates a node in the DOM without
   * touching its listeners, so nothing needs rewiring either way.
   */
  const mobileQuery = window.matchMedia('(max-width: 760px)');
  let mobileNavLayoutIsMobile = null; // null so the first real call always runs, even if it happens to start out matching mobileQuery's initial state
  function updateMobileNavLayout() {
    const isMobile = mobileQuery.matches;
    if (isMobile === mobileNavLayoutIsMobile) return;
    mobileNavLayoutIsMobile = isMobile;
    if (isMobile) {
      mobileNavDrawer.appendChild(typeTabs);
      mobileNavDrawer.appendChild(document.querySelector('.sidebar'));
    } else {
      closeMobileNav();
      const nav = document.querySelector('.nav.topbar');
      nav.insertBefore(typeTabs, document.getElementById('searchInput')?.closest('.search-box'));
      appBody.insertBefore(document.querySelector('.sidebar'), appBody.firstChild);
    }
  }

  function openMobileNav() {
    appShell.classList.add('mobile-nav-open');
    mobileNavBackdrop.hidden = false;
    mobileNavDrawer.hidden = false;
    mobileMenuBtn.setAttribute('aria-expanded', 'true');
  }
  function closeMobileNav() {
    appShell.classList.remove('mobile-nav-open');
    mobileNavBackdrop.hidden = true;
    mobileNavDrawer.hidden = true;
    mobileMenuBtn.setAttribute('aria-expanded', 'false');
  }
  mobileMenuBtn.addEventListener('click', () => {
    if (appShell.classList.contains('mobile-nav-open')) closeMobileNav();
    else openMobileNav();
  });
  mobileNavBackdrop.addEventListener('click', closeMobileNav);
  // Picking a type/library/tag from the drawer should close it, same as
  // any normal nav menu — otherwise it stays covering the freshly
  // loaded results the tap was meant to reveal.
  mobileNavDrawer.addEventListener('click', (e) => {
    if (e.target.closest('input[type="radio"], input[type="checkbox"], [data-filter-key], [data-group-tile]')) {
      closeMobileNav();
    }
  });
  mobileQuery.addEventListener('change', updateMobileNavLayout);
  updateMobileNavLayout();

  Promise.all([
    fetchJson('/api/libraries').catch(() => []),
    fetchJson('/api/tags').catch(() => []),
    fetchJson('/api/display-settings').catch(() => displaySettings),
  ]).then(([libs, tg, disp]) => {
    libraries = libs;
    tags = tg;
    displaySettings = disp;
    gridPageSize = disp.grid_page_size || 80;
    homeShelfFetchLimit = disp.home_shelf_fetch_limit || 60;
    applyDisplaySettings(disp);
    renderTypeTabs();
    syncSidebarActiveStates();
    // Deferred until here rather than fired in parallel at the top of the
    // file: the home shelves' tile size and visible width both depend on
    // the CSS variables applyDisplaySettings just set.
    restoreBackContextOrLoadHome();
  });

  /**
   * item.php's own "← Bibliothèque" link forwards whatever backContext()
   * attached to the link that got there (see itemCardHtml above) as
   * library.php's own ?restore= param — landing back on the exact
   * library/éditeur/browse view the item was opened from, rather than
   * always resetting to the home page. Falls back to the normal home
   * view for a plain library.php visit, or if the param is missing/
   * malformed (an old bookmark, a manually-edited URL, ...).
   */
  /**
   * Same snapshot shape as backContext() above, but as a parsed object
   * rather than a JSON string — the one place that actually *enters* a
   * saved state, used by both the initial-page-load restore (a ?restore=
   * URL param, from item.php's back link) and the popstate handler below
   * (the browser's own Back/Forward, once pushHistoryState() has been
   * recording history entries as the user clicks around).
   */
  function applyContext(ctx) {
    if (ctx.mode === 'group' && ctx.type) {
      if (ctx.groupLevel === 'path') {
        openPathLevel(ctx.type, ctx.groupLibraryId, ctx.groupLibraryName, ctx.groupPath || [], !!ctx.groupSkippedLibraryLevel, ctx.groupItemsPage || 1, true);
      } else {
        startGroupFlow(ctx.type, true);
      }
      return;
    }
    if (ctx.mode === 'browse') {
      Object.assign(state, {
        type: ctx.type || '',
        library_id: ctx.library_id ?? null,
        series_id: ctx.series_id ?? null,
        tag_id: ctx.tag_id ?? null,
        favorites: !!ctx.favorites,
        q: ctx.q || '',
        sort: ctx.sort || state.sort,
        dir: ctx.dir || state.dir,
        page: ctx.page || 1,
        groupLibraryId: ctx.groupLibraryId ?? null,
        groupPath: ctx.groupPath ?? null,
      });
      typeTabs.querySelectorAll('input[name="type"]').forEach((input) => { input.checked = input.value === state.type; });
      const favoritesTab = document.getElementById('favoritesTab');
      if (favoritesTab) favoritesTab.checked = state.favorites;
      searchInput.value = state.q;
      switchToBrowseMode();
      syncSidebarActiveStates();
      loadItems();
      return;
    }
    loadHome();
  }

  /**
   * Records the current view as a browser history entry — called after
   * every user-initiated navigation (type tabs, sidebar, group tiles,
   * pagination, sort, search...) so the browser's own Back/Forward
   * buttons step through the same views item.php's back link already
   * knows how to restore, instead of leaving library.php's internal
   * navigation invisible to browser history entirely. `history.state`
   * carries the parsed context directly (popstate below reads it
   * straight off the event, no re-fetch/re-parse needed); the URL's own
   * ?restore= is kept in sync too, purely so a reload or a copied link
   * lands back in the same spot.
   * $skipPush is set by applyContext() itself while restoring — the
   * response to a popstate, or the very first restore from item.php's
   * link, must never immediately push ANOTHER entry on top of the one
   * that got them there.
   */
  function pushHistoryState() {
    const ctx = JSON.parse(backContext());
    history.pushState(ctx, '', 'library.php?restore=' + encodeURIComponent(JSON.stringify(ctx)));
  }

  function restoreBackContextOrLoadHome() {
    const raw = new URLSearchParams(window.location.search).get('restore');
    if (!raw) {
      loadHome();
      return;
    }
    let ctx;
    try {
      ctx = JSON.parse(raw);
    } catch {
      loadHome();
      return;
    }
    applyContext(ctx);
  }

  window.addEventListener('popstate', (e) => {
    if (e.state) {
      applyContext(e.state);
    } else {
      restoreBackContextOrLoadHome();
    }
  });
})();
